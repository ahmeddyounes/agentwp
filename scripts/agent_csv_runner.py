#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
import re
import shutil
import subprocess
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


DEFAULTS: Dict[str, Any] = {
    "plan_dir": ".plans",
    "log_dir": ".logs",
    "handoff_dir": ".t2/handoff",
    "state_file": ".t2/state.json",
    "after_prompts_dir": ".t2/after_prompts",
    "pause_file": ".t2/PAUSE",
    "stop_file": ".t2/STOP",
    "commands": {"format": "", "lint": "", "test": ""},
    "retries": 2,
    "verify_after_prompts": False,
    "agents": {
        "default": "codex",
        # New: ordered fallback chain. Example: ["gemini","claude"]
        "fallback_order": [],
        "after_agent": "same",  # "same", "codex", "claude", "gemini"
    },
    "codex": {
        "model": "",
        "full_auto": True,
        "sandbox": "",
        "ask_for_approval": "",
        "search": False,
    },
    "claude": {
        "model": "sonnet",
        "permission_mode": "acceptEdits",
        "allowedTools": "",
        "tools": "",
        "dangerously_skip_permissions": False,
        "max_turns": 0,
        "output_format": "json",
    },
    "gemini": {
        "model": "gemini-3-pro-preview",  # or "auto"
        "output_format": "json",  # text|json|stream-json
        "include_directories": "",  # string "a,b" or list ["a","b"]
        "resume_strategy": "per_task",  # per_task|latest|none
        "capture_session_id": True,  # uses stream-json once to get session_id
        "yolo": True,
        "approval_mode": "auto_edit",  # default|auto_edit|yolo
        "sandbox": False,
        "debug": False,
        # Optional arrays (CLI supports these):
        "allowed_tools": [],  # ["run_shell_command", ...] (names depend on CLI/tools)
        "allowed_mcp_server_names": [],
        "extensions": [],
    },
}

TASK_ID_RE = re.compile(r"^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+$")


@dataclass
class RunResult:
    cmd: List[str]
    returncode: int
    stdout: str
    stderr: str


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def log(msg: str, level: str = "INFO") -> None:
    print(f"[{now_iso()}] {level}: {msg}", flush=True)


def run(cmd: List[str], cwd: Path, *, stdin_text: Optional[str] = None) -> RunResult:
    p = subprocess.run(
        cmd,
        cwd=str(cwd),
        input=stdin_text,
        text=True,
        capture_output=True,
    )
    return RunResult(cmd=cmd, returncode=p.returncode, stdout=p.stdout, stderr=p.stderr)


def run_stream_lines(
    cmd: List[str],
    cwd: Path,
    *,
    stdin_text: Optional[str],
    on_line,
) -> RunResult:
    """
    Stream stdout (and merged stderr) line-by-line to on_line(line).
    Returns combined output in stdout field; stderr is empty (merged).
    """
    p = subprocess.Popen(
        cmd,
        cwd=str(cwd),
        stdin=subprocess.PIPE if stdin_text is not None else None,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
        universal_newlines=True,
    )
    assert p.stdout is not None
    if stdin_text is not None:
        assert p.stdin is not None
        p.stdin.write(stdin_text)
        p.stdin.close()

    out_lines: List[str] = []
    for line in p.stdout:
        out_lines.append(line)
        on_line(line.rstrip("\n"))

    rc = p.wait()
    return RunResult(cmd=cmd, returncode=rc, stdout="".join(out_lines), stderr="")


def git_root(start: Path) -> Path:
    rr = run(["git", "rev-parse", "--show-toplevel"], cwd=start)
    if rr.returncode != 0:
        raise SystemExit("Not in a git repo (git rev-parse failed).")
    return Path(rr.stdout.strip())


def load_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def deep_merge(a: Dict[str, Any], b: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(a)
    for k, v in b.items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = deep_merge(out[k], v)
        else:
            out[k] = v
    return out


def sanitize_task_id(s: str) -> str:
    s = (s or "").strip()
    if not s:
        raise ValueError("Empty task id.")
    if not TASK_ID_RE.fullmatch(s):
        raise ValueError(f"Bad task id '{s}'. Expected like T2-2 or M02-T2-3.")
    return s


def pick_col(fieldnames: List[str], candidates: List[str]) -> Optional[str]:
    lower = {f.lower(): f for f in fieldnames}
    for c in candidates:
        if c.lower() in lower:
            return lower[c.lower()]
    return None


def which_or_none(cmd: str) -> Optional[str]:
    return shutil.which(cmd)


def ensure_cli_available(agent: str, repo: Path, *, strict: bool) -> bool:
    if agent == "codex":
        if not which_or_none("codex"):
            if strict:
                raise SystemExit("codex CLI not found in PATH.")
            return False
        rr = run(["codex", "login", "status"], cwd=repo)
        if rr.returncode != 0:
            if strict:
                raise SystemExit("Codex not logged in. Run: codex login")
            return False
        return True

    if agent == "claude":
        if not which_or_none("claude"):
            if strict:
                raise SystemExit("claude CLI not found in PATH.")
            return False
        rr = run(["claude", "--version"], cwd=repo)
        if rr.returncode != 0:
            if strict:
                raise SystemExit("Claude CLI exists but failed to execute.")
            return False
        return True

    if agent == "gemini":
        if not which_or_none("gemini"):
            if strict:
                raise SystemExit("gemini CLI not found in PATH.")
            return False
        rr = run(["gemini", "--version"], cwd=repo)
        if rr.returncode != 0:
            # Some versions exit non-zero for --version; fall back to --help.
            rr = run(["gemini", "--help"], cwd=repo)
            if rr.returncode != 0:
                if strict:
                    raise SystemExit("Gemini CLI exists but failed to execute.")
                return False
        return True

    if strict:
        raise SystemExit(f"Unknown agent '{agent}'. Use codex|claude|gemini.")
    return False


def read_after_prompts(dir_path: Path) -> List[Tuple[str, str]]:
    if not dir_path.exists():
        return []
    files = sorted([p for p in dir_path.iterdir() if p.is_file() and p.suffix.lower() in [".md", ".txt"]])
    out: List[Tuple[str, str]] = []
    for p in files:
        t = p.read_text(encoding="utf-8").strip()
        if t:
            out.append((p.name, t))
    return out


def wait_if_paused(pause_file: Path, stop_file: Path, *, interval_s: float, where: str) -> None:
    while pause_file.exists():
        log(f"PAUSED at {where}. Remove '{pause_file}' to continue.", "PAUSE")
        if stop_file.exists():
            return
        time.sleep(interval_s)


def stop_requested(stop_file: Path) -> bool:
    return stop_file.exists()


def safe_read(path: Path, max_chars: int = 20000) -> str:
    if not path.exists():
        return ""
    data = path.read_text(encoding="utf-8", errors="replace")
    if len(data) <= max_chars:
        return data
    return data[:max_chars] + "\n\n...[truncated]...\n"


def git_snapshot(repo: Path) -> str:
    parts: List[str] = []
    b = run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd=repo)
    parts.append(f"branch: {b.stdout.strip() if b.returncode == 0 else '(unknown)'}")

    st = run(["git", "status", "--porcelain=v1"], cwd=repo)
    parts.append("status:\n" + (st.stdout.strip() or "(clean)"))

    ds = run(["git", "diff", "--stat"], cwd=repo)
    parts.append("diff --stat:\n" + (ds.stdout.strip() or "(no diff)"))

    return "\n\n".join(parts).strip() + "\n"


def append_handoff(handoff_path: Path, *, header: str, body: str) -> None:
    handoff_path.parent.mkdir(parents=True, exist_ok=True)
    if not handoff_path.exists():
        handoff_path.write_text(f"# Handoff\n\nCreated: {now_iso()}\n\n", encoding="utf-8")
    with handoff_path.open("a", encoding="utf-8") as f:
        f.write(f"\n\n## {header}\n\n{body}\n")


@dataclass
class TaskSessions:
    codex_started: bool = False
    claude_session_id: Optional[str] = None
    gemini_session_id: Optional[str] = None


def normalize_gemini_model(name: str) -> str:
    s = (name or "").strip()
    if not s:
        return s
    key = re.sub(r"\s+", "-", s.lower())
    if key in {"gemini-3-pro", "gemini3pro", "gemini-3-pro-preview"}:
        return "gemini-3-pro-preview"
    if key in {"auto", "pro", "flash", "flash-lite"}:
        return key
    return s


def _as_list(v: Any) -> List[str]:
    if v is None:
        return []
    if isinstance(v, list):
        return [str(x).strip() for x in v if str(x).strip()]
    s = str(v).strip()
    if not s:
        return []
    # allow comma-separated
    parts = [p.strip() for p in s.split(",")]
    return [p for p in parts if p]


class AgentRunner:
    def __init__(
        self,
        repo: Path,
        cfg: Dict[str, Any],
        log_dir: Path,
        handoff_path: Path,
        sessions: TaskSessions,
        *,
        verbose: bool,
    ) -> None:
        self.repo = repo
        self.cfg = cfg
        self.log_dir = log_dir
        self.handoff_path = handoff_path
        self.sessions = sessions
        self.verbose = verbose

    # ---- Codex ----
    def _codex_args(self) -> List[str]:
        c = self.cfg.get("codex", {})
        args: List[str] = ["--color", "never"]
        model = (c.get("model") or "").strip()
        if model:
            args += ["--model", model]
        if bool(c.get("search", False)):
            args += ["--search"]
        if bool(c.get("full_auto", True)):
            args += ["--full-auto"]
        sandbox = (c.get("sandbox") or "").strip()
        if sandbox:
            args += ["--sandbox", sandbox]
        approval = (c.get("ask_for_approval") or "").strip()
        if approval:
            args += ["--ask-for-approval", approval]
        return args

    def codex_run(self, prompt: str, *, stage: str, task_id: str) -> RunResult:
        last_path = self.log_dir / f"{task_id}.codex.{stage}.last.md"
        raw_path = self.log_dir / f"{task_id}.codex.{stage}.raw.txt"

        base = ["codex", "exec"] + self._codex_args() + ["--output-last-message", str(last_path)]
        if self.sessions.codex_started:
            cmd = base + ["resume", "--last", "-"]
        else:
            cmd = base + ["-"]

        if self.verbose:
            log(f"CMD: {' '.join(cmd)}", "CMD")

        rr = run(cmd, cwd=self.repo, stdin_text=prompt)
        raw_path.parent.mkdir(parents=True, exist_ok=True)
        raw_path.write_text(rr.stdout + "\n" + rr.stderr, encoding="utf-8")

        if rr.returncode == 0:
            self.sessions.codex_started = True

        append_handoff(
            self.handoff_path,
            header=f"{now_iso()} — codex — {stage}",
            body=(
                f"Command:\n```\n{' '.join(cmd)}\n```\n\n"
                f"Return code: {rr.returncode}\n\n"
                f"Agent final message (file: {last_path.name}):\n\n"
                f"{safe_read(last_path)}\n\n"
                f"Repo snapshot:\n```\n{git_snapshot(self.repo)}\n```"
            ),
        )
        return rr

    # ---- Claude Code ----
    def _claude_args(self) -> List[str]:
        c = self.cfg.get("claude", {})
        args: List[str] = ["-p", "--output-format", str((c.get("output_format") or "json")).strip()]

        model = (c.get("model") or "").strip()
        if model:
            args += ["--model", model]

        max_turns = int(c.get("max_turns") or 0)
        if max_turns > 0:
            args += ["--max-turns", str(max_turns)]

        perm_mode = (c.get("permission_mode") or "").strip()
        if perm_mode:
            args += ["--permission-mode", perm_mode]

        allowed = (c.get("allowedTools") or "").strip()
        if allowed:
            args += ["--allowedTools", allowed]

        tools = (c.get("tools") or "").strip()
        if tools:
            args += ["--tools", tools]

        if bool(c.get("dangerously_skip_permissions", False)):
            args += ["--dangerously-skip-permissions"]

        return args

    def claude_run(self, prompt: str, *, stage: str, task_id: str) -> Tuple[RunResult, Optional[str]]:
        last_path = self.log_dir / f"{task_id}.claude.{stage}.last.md"
        raw_path = self.log_dir / f"{task_id}.claude.{stage}.raw.txt"

        c = self.cfg.get("claude", {})
        outfmt = str((c.get("output_format") or "json")).strip()

        cmd = ["claude"] + self._claude_args()
        if self.sessions.claude_session_id:
            cmd += ["--resume", self.sessions.claude_session_id]
        cmd += [prompt]

        if self.verbose:
            log(f"CMD: {' '.join(cmd)}", "CMD")

        rr = run(cmd, cwd=self.repo)
        raw_path.parent.mkdir(parents=True, exist_ok=True)
        raw_path.write_text(rr.stdout + "\n" + rr.stderr, encoding="utf-8")

        result_text = rr.stdout.strip()
        new_session_id = None
        if rr.returncode == 0 and outfmt == "json":
            try:
                obj = json.loads(rr.stdout)
                new_session_id = obj.get("session_id")
                result_text = (obj.get("result") or "").strip()
            except Exception:
                pass

        if rr.returncode == 0 and new_session_id and not self.sessions.claude_session_id:
            self.sessions.claude_session_id = str(new_session_id)

        last_path.parent.mkdir(parents=True, exist_ok=True)
        last_path.write_text(result_text + "\n", encoding="utf-8")

        append_handoff(
            self.handoff_path,
            header=f"{now_iso()} — claude — {stage}",
            body=(
                f"Command:\n```\n{' '.join(cmd)}\n```\n\n"
                f"Return code: {rr.returncode}\n\n"
                f"Session: {self.sessions.claude_session_id or '(unknown)'}\n\n"
                f"Agent final message (file: {last_path.name}):\n\n"
                f"{safe_read(last_path)}\n\n"
                f"Repo snapshot:\n```\n{git_snapshot(self.repo)}\n```"
            ),
        )
        return rr, self.sessions.claude_session_id

    # ---- Gemini CLI ----
    def _gemini_args(self, *, output_format: str, model: str) -> List[str]:
        g = self.cfg.get("gemini", {})
        args: List[str] = ["--output-format", output_format]

        m = normalize_gemini_model(model)
        if m:
            args += ["--model", m]

        if bool(g.get("debug", False)):
            args += ["--debug"]
        if bool(g.get("sandbox", False)):
            args += ["--sandbox"]

        include_dirs = _as_list(g.get("include_directories"))
        if include_dirs:
            args += ["--include-directories", ",".join(include_dirs)]

        # Automation / approvals
        if bool(g.get("yolo", False)):
            args += ["--yolo"]
        approval_mode = str(g.get("approval_mode") or "").strip()
        if approval_mode:
            args += ["--approval-mode", approval_mode]

        for t in _as_list(g.get("allowed_tools")):
            args += ["--allowed-tools", t]
        for n in _as_list(g.get("allowed_mcp_server_names")):
            args += ["--allowed-mcp-server-names", n]
        exts = _as_list(g.get("extensions"))
        if exts:
            args += ["--extensions"] + exts

        return args

    def gemini_run(self, prompt: str, *, stage: str, task_id: str) -> Tuple[RunResult, Optional[str]]:
        g = self.cfg.get("gemini", {})
        desired_model = str(g.get("model") or "auto").strip()
        configured_outfmt = str(g.get("output_format") or "json").strip()

        resume_strategy = str(g.get("resume_strategy") or "per_task").strip()
        capture_session = bool(g.get("capture_session_id", True))

        # If we want per-task resume and don't have a session yet, run stream-json once to capture session_id.
        need_session = resume_strategy == "per_task"
        have_session = bool(self.sessions.gemini_session_id)

        outfmt = configured_outfmt
        force_stream = (need_session and not have_session and capture_session)
        if force_stream:
            outfmt = "stream-json"

        last_path = self.log_dir / f"{task_id}.gemini.{stage}.last.md"
        raw_path = self.log_dir / f"{task_id}.gemini.{stage}.raw.txt"
        json_path = self.log_dir / f"{task_id}.gemini.{stage}.json"
        jsonl_path = self.log_dir / f"{task_id}.gemini.{stage}.jsonl"

        cmd = ["gemini"] + self._gemini_args(output_format=outfmt, model=desired_model)

        # Resume support
        if resume_strategy == "latest":
            cmd += ["--resume", "latest"]
        elif resume_strategy == "per_task" and self.sessions.gemini_session_id:
            cmd += ["--resume", self.sessions.gemini_session_id]

        if self.verbose:
            log(f"CMD: {' '.join(cmd)} (prompt via stdin)", "CMD")

        session_id: Optional[str] = self.sessions.gemini_session_id
        final_text = ""
        effective_rc = 0

        if outfmt == "stream-json":
            # Stream JSONL events for better progress + capture session_id.
            assistant_chunks: List[str] = []
            result_status: Optional[str] = None
            errors: List[str] = []

            def handle_line(line: str) -> None:
                nonlocal session_id, result_status
                # Write raw line
                with raw_path.open("a", encoding="utf-8") as f:
                    f.write(line + "\n")
                with jsonl_path.open("a", encoding="utf-8") as f:
                    f.write(line + "\n")
                # Parse event
                try:
                    evt = json.loads(line)
                except Exception:
                    return
                et = evt.get("type")
                if et == "init":
                    sid = evt.get("session_id")
                    if sid:
                        session_id = str(sid)
                        self.sessions.gemini_session_id = session_id
                        if self.verbose:
                            log(f"{task_id}: Gemini session_id={session_id} model={evt.get('model')}", "GEMINI")
                elif et == "tool_use" and self.verbose:
                    log(f"{task_id}: tool_use {evt.get('tool_name')}", "GEMINI")
                elif et == "tool_result" and self.verbose:
                    log(f"{task_id}: tool_result {evt.get('status')}", "GEMINI")
                elif et == "error":
                    msg = str(evt.get("message") or evt)
                    errors.append(msg)
                    if self.verbose:
                        log(f"{task_id}: gemini error event: {msg}", "WARN")
                elif et == "message":
                    role = evt.get("role")
                    content = evt.get("content") or ""
                    if role == "assistant":
                        # If delta chunks, append; else treat as full chunk.
                        assistant_chunks.append(str(content))
                elif et == "result":
                    result_status = str(evt.get("status") or "").lower()

            # Ensure raw files start empty
            raw_path.parent.mkdir(parents=True, exist_ok=True)
            raw_path.write_text("", encoding="utf-8")
            jsonl_path.write_text("", encoding="utf-8")

            rr = run_stream_lines(cmd, cwd=self.repo, stdin_text=prompt, on_line=handle_line)

            # Determine success
            if rr.returncode != 0:
                effective_rc = rr.returncode
            else:
                if result_status and result_status != "success":
                    effective_rc = 1
                elif errors:
                    # Non-fatal errors can happen; treat as failure for automation reliability.
                    effective_rc = 1
                else:
                    effective_rc = 0

            final_text = ("".join(assistant_chunks)).strip()

            # Save a json summary too
            json_path.parent.mkdir(parents=True, exist_ok=True)
            json_path.write_text(
                json.dumps(
                    {
                        "session_id": session_id,
                        "result_status": result_status,
                        "errors": errors,
                    },
                    indent=2,
                )
                + "\n",
                encoding="utf-8",
            )

            rr = RunResult(cmd=rr.cmd, returncode=effective_rc, stdout=rr.stdout, stderr=rr.stderr)

        else:
            rr = run(cmd, cwd=self.repo, stdin_text=prompt)
            raw_path.parent.mkdir(parents=True, exist_ok=True)
            raw_path.write_text(rr.stdout + "\n" + rr.stderr, encoding="utf-8")

            response_text = rr.stdout.strip()
            error_summary = ""

            if outfmt == "json":
                try:
                    obj = json.loads(rr.stdout) if rr.stdout.strip().startswith("{") else {}
                    json_path.parent.mkdir(parents=True, exist_ok=True)
                    json_path.write_text(json.dumps(obj, indent=2) + "\n", encoding="utf-8")
                    if isinstance(obj, dict) and obj.get("error"):
                        e = obj.get("error") or {}
                        error_summary = f"{e.get('type','Error')}: {e.get('message','')}".strip()
                    response_text = (obj.get("response") or "").strip()
                except Exception as e:
                    error_summary = f"JSON parse error: {e}"

            effective_rc = rr.returncode
            if error_summary and effective_rc == 0:
                effective_rc = 1

            final_text = (response_text or error_summary or rr.stdout).strip()
            rr = RunResult(cmd=rr.cmd, returncode=effective_rc, stdout=rr.stdout, stderr=rr.stderr)

        last_path.parent.mkdir(parents=True, exist_ok=True)
        last_path.write_text((final_text or "").strip() + "\n", encoding="utf-8")

        append_handoff(
            self.handoff_path,
            header=f"{now_iso()} — gemini — {stage}",
            body=(
                f"Command:\n```\n{' '.join(cmd)}\n```\n\n"
                f"Return code: {rr.returncode}\n\n"
                f"Session: {session_id or '(unknown)'}\n\n"
                f"Agent final message (file: {last_path.name}):\n\n"
                f"{safe_read(last_path)}\n\n"
                f"Repo snapshot:\n```\n{git_snapshot(self.repo)}\n```"
            ),
        )
        return rr, session_id


def build_impl_prompt(task_id: str, spec_rel: str, handoff_rel: str, commands: Dict[str, str]) -> str:
    return (
        f"You are implementing task {task_id} in this git repository.\n\n"
        f"1) Read the spec: {spec_rel}\n"
        f"2) Read handoff/context: {handoff_rel}\n\n"
        "Implement the spec fully.\n\n"
        "Verification commands (run if non-empty):\n"
        f"- format: {commands.get('format','')}\n"
        f"- lint: {commands.get('lint','')}\n"
        f"- test: {commands.get('test','')}\n\n"
        "In your final response, include:\n"
        "- Status: DONE or NEEDS-REVIEW\n"
        "- Summary of changes\n"
        "- How to verify (exact commands)\n"
        "- Risks / follow-ups\n"
    )


def build_fix_prompt(task_id: str, spec_rel: str, handoff_rel: str) -> str:
    return (
        f"We are working on task {task_id}.\n\n"
        f"Read the spec: {spec_rel}\n"
        f"Read the handoff/context (includes failures): {handoff_rel}\n\n"
        "Fix the repository so the verification commands pass.\n"
        "If there are failing tests/lint, address them and re-run.\n"
        "Summarize what you changed.\n"
    )


def build_after_prompt(task_id: str, spec_rel: str, handoff_rel: str, prompt_text: str) -> str:
    return (
        f"We just completed/updated task {task_id}.\n\n"
        f"Spec: {spec_rel}\n"
        f"Handoff/context: {handoff_rel}\n\n"
        "Run this follow-up instruction:\n"
        f"{prompt_text}\n\n"
        "If you modify code, keep changes minimal and consistent with the task.\n"
        "Summarize what you did.\n"
    )


def run_verify(repo: Path, commands: Dict[str, str], *, verbose: bool) -> List[Tuple[str, str]]:
    failures: List[Tuple[str, str]] = []
    for key in ["format", "lint", "test"]:
        cmd = (commands.get(key) or "").strip()
        if not cmd:
            continue
        if verbose:
            log(f"VERIFY {key}: {cmd}", "VERIFY")
        rr = run(["bash", "-lc", cmd], cwd=repo)
        if rr.returncode != 0:
            failures.append((cmd, (rr.stdout + "\n" + rr.stderr).strip()))
            log(f"Verify FAILED ({key})", "FAIL")
        else:
            log(f"Verify OK ({key})", "OK")
    return failures


def _parse_agent_list(s: str) -> List[str]:
    out: List[str] = []
    for p in [x.strip().lower() for x in s.split(",") if x.strip()]:
        if p in {"codex", "claude", "gemini"} and p not in out:
            out.append(p)
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", required=True, help="Path to CSV file with tasks.")
    ap.add_argument("--config", default=".t2/config.json", help="Runner config JSON.")
    ap.add_argument("--series", default="T2", help="Used only when CSV has no id column, e.g. M02-T2.")
    ap.add_argument("--resume", action="store_true", help="Resume from state file; skip completed tasks.")
    ap.add_argument("--commit", action="store_true", help="Commit after each successful task.")
    ap.add_argument("--agent", default="codex", choices=["codex", "claude", "gemini"], help="Primary agent (default codex).")
    ap.add_argument(
        "--fallback-order",
        default="",
        help="Comma-separated fallback chain, e.g. 'gemini,claude' (overrides config fallback_order).",
    )
    ap.add_argument("--verbose", action="store_true", help="Verbose console logging.")
    ap.add_argument("--pause-after-task", action="store_true", help="Pause after each task until pause file removed.")
    ap.add_argument("--pause-check-interval", type=float, default=5.0, help="Seconds between pause checks.")
    args = ap.parse_args()

    repo = git_root(Path.cwd())
    cfg = deep_merge(DEFAULTS, load_json(repo / args.config))

    plan_dir = repo / str(cfg["plan_dir"])
    log_dir = repo / str(cfg["log_dir"])
    handoff_dir = repo / str(cfg["handoff_dir"])
    state_file = repo / str(cfg["state_file"])
    after_dir = repo / str(cfg["after_prompts_dir"])
    pause_file = repo / str(cfg["pause_file"])
    stop_file = repo / str(cfg["stop_file"])

    for d in [plan_dir, log_dir, handoff_dir, after_dir, pause_file.parent, stop_file.parent]:
        d.mkdir(parents=True, exist_ok=True)

    primary_default = (args.agent or cfg.get("agents", {}).get("default", "codex")).strip().lower() or "codex"

    fallback_order: List[str] = []
    if args.fallback_order.strip():
        fallback_order = _parse_agent_list(args.fallback_order)
    else:
        fallback_order = _as_list(cfg.get("agents", {}).get("fallback_order", []))
        fallback_order = [x.lower() for x in fallback_order if str(x).lower() in {"codex", "claude", "gemini"}]

    # Remove primary if present
    fallback_order = [a for a in fallback_order if a != primary_default]

    ensure_cli_available(primary_default, repo, strict=True)
    checked_fallbacks: List[str] = []
    for a in fallback_order:
        if ensure_cli_available(a, repo, strict=False):
            checked_fallbacks.append(a)
        else:
            log(f"Fallback agent '{a}' not available; skipping.", "WARN")
    fallback_order = checked_fallbacks

    after_prompts = read_after_prompts(after_dir)

    state = load_json(state_file) if args.resume else {}
    done_ids = set(state.get("completed_task_ids", []))

    claude_sessions: Dict[str, str] = state.get("claude_sessions", {}) if isinstance(state.get("claude_sessions", {}), dict) else {}
    gemini_sessions: Dict[str, str] = state.get("gemini_sessions", {}) if isinstance(state.get("gemini_sessions", {}), dict) else {}

    commands = cfg.get("commands", {})
    retries = int(cfg.get("retries", 2))
    verify_after_prompts = bool(cfg.get("verify_after_prompts", False))

    csv_path = Path(args.csv)
    if not csv_path.is_absolute():
        csv_path = (Path.cwd() / csv_path).resolve()

    with csv_path.open("r", newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        if not reader.fieldnames:
            raise SystemExit("CSV must have headers.")
        fields = reader.fieldnames
        rows = list(reader)

    id_col = pick_col(fields, ["id", "task_id", "task"])
    title_col = pick_col(fields, ["title", "name"])
    spec_col = pick_col(fields, ["spec", "details", "description", "prompt", "body"])
    agent_col = pick_col(fields, ["agent", "backend", "engine", "runner"])

    log(f"Repo: {repo}")
    log(f"Primary agent: {primary_default}  Fallback chain: {fallback_order or '(none)'}")
    log(f"CSV rows: {len(rows)}  id_col={id_col} spec_col={spec_col} agent_col={agent_col}")
    log(f"After prompts: {len(after_prompts)} from {after_dir}")
    log(f"Pause file: {pause_file}  Stop file: {stop_file}")
    log(f"Resume: {args.resume}  Completed: {len(done_ids)}")

    total = len(rows)
    for idx, row in enumerate(rows, start=1):
        wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"before row {idx}/{total}")
        if stop_requested(stop_file):
            log("STOP requested. Exiting.", "STOP")
            return 0

        if id_col and (row.get(id_col) or "").strip():
            task_id = sanitize_task_id(row[id_col])
        else:
            series = (args.series or "T2").strip().strip("-")
            task_id = sanitize_task_id(f"{series}-{idx}")

        if task_id in done_ids:
            log(f"[{idx}/{total}] skip {task_id} (already done)", "SKIP")
            continue

        row_agent = (row.get(agent_col) or "").strip().lower() if agent_col else ""
        primary = primary_default
        if row_agent in {"codex", "claude", "gemini"}:
            primary = row_agent

        title = (row.get(title_col) or "").strip() if title_col else ""
        spec = (row.get(spec_col) or "").strip() if spec_col else ""
        if not spec:
            parts = []
            for k, v in row.items():
                if k in {id_col, title_col, agent_col}:
                    continue
                vv = (v or "").strip()
                if vv:
                    parts.append(f"{k}: {vv}")
            spec = "\n".join(parts).strip()
        if not spec:
            raise SystemExit(f"{task_id}: no spec text found (row {idx}).")

        spec_path = plan_dir / f"{task_id}.md"
        spec_path.write_text(f"# {task_id}" + (f" — {title}" if title else "") + "\n\n" + spec + "\n", encoding="utf-8")

        handoff_path = handoff_dir / f"{task_id}.md"
        if not handoff_path.exists():
            handoff_path.write_text(
                f"# Handoff: {task_id}\n\nCreated: {now_iso()}\n\nSpec: {spec_path.relative_to(repo)}\n",
                encoding="utf-8",
            )

        sessions = TaskSessions(
            codex_started=False,
            claude_session_id=claude_sessions.get(task_id),
            gemini_session_id=gemini_sessions.get(task_id),
        )
        runner = AgentRunner(repo, cfg, log_dir, handoff_path, sessions, verbose=args.verbose)

        log(f"[{idx}/{total}] START {task_id} (primary={primary}, fallbacks={fallback_order})", "TASK")

        spec_rel = str(spec_path.relative_to(repo))
        handoff_rel = str(handoff_path.relative_to(repo))

        def run_stage(agent: str, *, stage: str, prompt: str) -> RunResult:
            nonlocal sessions
            if agent == "codex":
                return runner.codex_run(prompt, stage=stage, task_id=task_id)
            if agent == "claude":
                rr, sid = runner.claude_run(prompt, stage=stage, task_id=task_id)
                if sid:
                    claude_sessions[task_id] = sid
                return rr
            if agent == "gemini":
                rr, sid = runner.gemini_run(prompt, stage=stage, task_id=task_id)
                if sid:
                    gemini_sessions[task_id] = sid
                return rr
            raise SystemExit(f"Unknown agent '{agent}'")

        def agent_try_order(primary_agent: str) -> List[str]:
            out = [primary_agent]
            for a in fallback_order:
                if a != primary_agent and a not in out:
                    out.append(a)
            return out

        # Persist sessions on any stage that updated them
        def persist_state() -> None:
            state["completed_task_ids"] = sorted(done_ids)
            state["claude_sessions"] = claude_sessions
            state["gemini_sessions"] = gemini_sessions
            state["updated_at"] = now_iso()
            write_json(state_file, state)

        # 1) Implement
        impl_prompt = build_impl_prompt(task_id, spec_rel, handoff_rel, commands)
        impl_agent_used: Optional[str] = None
        for a in agent_try_order(primary):
            wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"{task_id} before impl ({a})")
            if stop_requested(stop_file):
                log("STOP requested. Exiting.", "STOP")
                return 0
            log(f"{task_id}: IMPLEMENT using {a}", "STEP")
            rr = run_stage(a, stage="impl", prompt=impl_prompt)
            persist_state()
            if rr.returncode == 0:
                impl_agent_used = a
                break
            log(f"{task_id}: impl failed with {a} (exit {rr.returncode})", "WARN")
        if not impl_agent_used:
            log(f"{task_id}: impl failed with all agents.", "ERROR")
            return 1

        # 2) Verify + fix loops
        wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"{task_id} before verification")
        if stop_requested(stop_file):
            log("STOP requested. Exiting.", "STOP")
            return 0

        failures = run_verify(repo, commands, verbose=args.verbose)
        if failures:
            append_handoff(
                handoff_path,
                header=f"{now_iso()} — verify — FAIL",
                body="\n\n".join([f"Command:\n```\n{c}\n```\n\nOutput:\n```\n{o}\n```" for c, o in failures]),
            )

        def fix_with(agent: str, max_attempts: int) -> bool:
            nonlocal failures
            for attempt in range(1, max_attempts + 1):
                if not failures:
                    return True
                wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"{task_id} before fix {attempt} ({agent})")
                if stop_requested(stop_file):
                    return False
                log(f"{task_id}: FIX attempt {attempt}/{max_attempts} using {agent}", "STEP")
                fix_prompt = build_fix_prompt(task_id, spec_rel, handoff_rel)
                rr = run_stage(agent, stage=f"fix{attempt}", prompt=fix_prompt)
                persist_state()
                if rr.returncode != 0:
                    log(f"{task_id}: fix attempt failed at CLI level (exit {rr.returncode})", "WARN")
                failures = run_verify(repo, commands, verbose=args.verbose)
                if failures:
                    append_handoff(
                        handoff_path,
                        header=f"{now_iso()} — verify — still FAIL (after {agent} fix{attempt})",
                        body="\n\n".join([f"Command:\n```\n{c}\n```\n\nOutput:\n```\n{o}\n```" for c, o in failures]),
                    )
                else:
                    append_handoff(handoff_path, header=f"{now_iso()} — verify — PASS", body="All verification commands passed.")
                    return True
            return not failures

        if failures:
            ok = fix_with(impl_agent_used, retries)
            if not ok:
                # try other agents in chain
                for a in agent_try_order(impl_agent_used):
                    if a == impl_agent_used:
                        continue
                    log(f"{task_id}: switching agent for fixes: {a}", "SWITCH")
                    ok = fix_with(a, retries)
                    if ok:
                        break
            if not ok:
                log(f"{task_id}: verification still failing after retries.", "ERROR")
                return 1

        # 3) After prompts
        after_agent = str(cfg.get("agents", {}).get("after_agent", "same") or "same").strip()
        if after_agent == "same":
            after_agent = impl_agent_used
        if after_agent not in {"codex", "claude", "gemini"}:
            after_agent = impl_agent_used

        for j, (fname, ptext) in enumerate(after_prompts, start=1):
            wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"{task_id} before after-prompt {j}")
            if stop_requested(stop_file):
                log("STOP requested. Exiting.", "STOP")
                return 0

            prompt = build_after_prompt(task_id, spec_rel, handoff_rel, ptext)
            log(f"{task_id}: AFTER {j}/{len(after_prompts)} ({fname}) using {after_agent}", "AFTER")
            rr = run_stage(after_agent, stage=f"after{j}", prompt=prompt)
            persist_state()
            if rr.returncode != 0:
                # try fallbacks
                for a in agent_try_order(after_agent):
                    if a == after_agent:
                        continue
                    log(f"{task_id}: after-prompt failed with {after_agent}; trying {a}", "SWITCH")
                    rr2 = run_stage(a, stage=f"after{j}.fallback.{a}", prompt=prompt)
                    persist_state()
                    if rr2.returncode == 0:
                        break

        if verify_after_prompts:
            failures = run_verify(repo, commands, verbose=args.verbose)
            if failures:
                log(f"{task_id}: verification failed after after-prompts.", "ERROR")
                append_handoff(
                    handoff_path,
                    header=f"{now_iso()} — verify-after-prompts — FAIL",
                    body="\n\n".join([f"Command:\n```\n{c}\n```\n\nOutput:\n```\n{o}\n```" for c, o in failures]),
                )
                ok = fix_with(after_agent, retries)
                if not ok:
                    for a in agent_try_order(after_agent):
                        if a == after_agent:
                            continue
                        ok = fix_with(a, retries)
                        if ok:
                            break
                if not ok:
                    return 1

        # 4) Commit + state
        if args.commit:
            log(f"{task_id}: committing changes", "GIT")
            run(["git", "add", "-A"], cwd=repo)
            cm = run(["git", "commit", "-m", f"{task_id}: implement"], cwd=repo)
            run(["git", "push", "origin"], cwd=repo)
            (log_dir / f"{task_id}.gitcommit.txt").write_text(cm.stdout + "\n" + cm.stderr, encoding="utf-8")

        done_ids.add(task_id)
        persist_state()
        log(f"[{idx}/{total}] DONE {task_id}", "DONE")

        if args.pause_after_task:
            pause_file.touch(exist_ok=True)
            wait_if_paused(pause_file, stop_file, interval_s=args.pause_check_interval, where=f"after task {task_id}")

    log("All tasks completed.", "DONE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
