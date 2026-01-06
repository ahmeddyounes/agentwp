#!/usr/bin/env python3
"""
agentflow.py — Orchestrate Codex / Claude Code / Gemini CLI to implement tasks from a CSV.

Key features:
- Runs tasks sequentially from a CSV.
- After-prompts: run N extra prompts after each task (from file or CLI).
- Engine options: codex (default), claude, gemini with fallback chain.
- Cross-engine resume via a per-task handoff file (.agentflow/handoff/<task_id>.md)
- Pause/resume: create .agentflow/PAUSE to pause; delete it to continue.
- Stop: create .agentflow/STOP to stop gracefully after current step.
- Task id "next" placeholder: supports T2-2, M2-T2, M02-T02, etc.

Requirements:
- Python 3.10+
- Install whichever CLIs you plan to use:
  - codex (OpenAI Codex CLI)
  - claude (Claude Code CLI)
  - gemini (@google/gemini-cli)

Docs:
- Codex CLI: https://developers.openai.com/codex/cli/reference/
- Claude Code CLI: https://code.claude.com/docs/en/cli-reference
- Gemini CLI: https://google-gemini.github.io/gemini-cli/
"""

from __future__ import annotations

import argparse
import csv
import dataclasses
import datetime as _dt
import json
import os
import platform
import re
import shutil
import signal
import subprocess
import sys
import textwrap
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Tuple


APP_DIRNAME = ".agentflow"
DEFAULT_STATE_FILE = "state.json"
PAUSE_FILE = "PAUSE"
STOP_FILE = "STOP"


# -------------------------
# Models / task structures
# -------------------------

@dataclass(frozen=True)
class Task:
    task_id: str
    spec: str
    title: str = ""
    engine: Optional[str] = None  # optional per-row override


@dataclass
class RunResult:
    engine: str
    ok: bool
    exit_code: int
    stdout: str
    assistant_text: str
    session_id: Optional[str] = None
    log_path: Optional[Path] = None
    last_message_path: Optional[Path] = None
    error_summary: Optional[str] = None


@dataclass
class Cursor:
    task_index: int = 0
    phase: str = "main"  # "main" or "after"
    after_index: int = 0


# -------------------------
# Utility helpers
# -------------------------

def now_stamp() -> str:
    return _dt.datetime.now().strftime("%Y%m%d-%H%M%S")


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def which(binary: str) -> Optional[str]:
    return shutil.which(binary)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def write_text(path: Path, text: str) -> None:
    ensure_dir(path.parent)
    path.write_text(text, encoding="utf-8")


def append_text(path: Path, text: str) -> None:
    ensure_dir(path.parent)
    with path.open("a", encoding="utf-8") as f:
        f.write(text)


def clamp(s: str, max_chars: int) -> str:
    if len(s) <= max_chars:
        return s
    return s[: max_chars - 1] + "…"


def normalize_engine(name: str) -> str:
    name = name.strip().lower()
    if name in {"codex", "openai", "oai"}:
        return "codex"
    if name in {"claude", "claude-code", "anthropic"}:
        return "claude"
    if name in {"gemini", "google"}:
        return "gemini"
    raise ValueError(f"Unknown engine '{name}' (expected: codex|claude|gemini)")


def split_csv_list(s: str) -> List[str]:
    items = [x.strip() for x in s.split(",")]
    return [x for x in items if x]


def git(cmd: Sequence[str], cwd: Path) -> Tuple[int, str]:
    try:
        p = subprocess.run(
            ["git", *cmd],
            cwd=str(cwd),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
        return p.returncode, p.stdout
    except FileNotFoundError:
        return 127, "git not found"


# -------------------------
# Pause / stop controls
# -------------------------

class ControlFiles:
    def __init__(self, app_dir: Path):
        self.pause_path = app_dir / PAUSE_FILE
        self.stop_path = app_dir / STOP_FILE

    def should_pause(self) -> bool:
        return self.pause_path.exists()

    def should_stop(self) -> bool:
        return self.stop_path.exists()

    def wait_if_paused(self) -> None:
        while self.should_pause():
            print(f"[agentflow] PAUSED (delete {self.pause_path} to continue)...")
            time.sleep(2.0)


# -------------------------
# Task ID parsing / next-id
# -------------------------

_TASK_RE = re.compile(
    r"^(?:M(?P<m>\d+)-)?T(?P<t>\d+)(?:-(?P<sub>\d+))?$",
    re.IGNORECASE,
)


@dataclass(frozen=True)
class ParsedTaskId:
    module_num: Optional[int]
    module_width: int
    task_num: int
    task_width: int
    sub_num: Optional[int]
    sub_width: int

    def format(self) -> str:
        parts: List[str] = []
        if self.module_num is not None:
            parts.append(f"M{self.module_num:0{self.module_width}d}")
        parts.append(f"T{self.task_num:0{self.task_width}d}")
        if self.sub_num is not None:
            parts[-1] = parts[-1] + f"-{self.sub_num:0{self.sub_width}d}"
        return "-".join(parts) if len(parts) > 1 else parts[0]


def parse_task_id(task_id: str) -> Optional[ParsedTaskId]:
    m = _TASK_RE.match(task_id.strip())
    if not m:
        return None
    m_str = m.group("m")
    t_str = m.group("t")
    sub_str = m.group("sub")

    module_num = int(m_str) if m_str is not None else None
    module_width = len(m_str) if m_str is not None else 0

    task_num = int(t_str)
    task_width = len(t_str)

    sub_num = int(sub_str) if sub_str is not None else None
    sub_width = len(sub_str) if sub_str is not None else 0

    return ParsedTaskId(module_num, module_width, task_num, task_width, sub_num, sub_width)


def next_task_id(task_id: str) -> str:
    parsed = parse_task_id(task_id)
    if not parsed:
        return task_id
    if parsed.sub_num is not None:
        return dataclasses.replace(parsed, sub_num=parsed.sub_num + 1).format()
    return dataclasses.replace(parsed, task_num=parsed.task_num + 1).format()


def apply_placeholders(text: str, task_id: str) -> str:
    return (
        text.replace("{TASK_ID}", task_id)
        .replace("{TASK_ID_NEXT}", next_task_id(task_id))
    )


# -------------------------
# CSV parsing
# -------------------------

def detect_column(fieldnames: Sequence[str], candidates: Sequence[str]) -> Optional[str]:
    lowered = {f.lower(): f for f in fieldnames}
    for c in candidates:
        if c.lower() in lowered:
            return lowered[c.lower()]
    return None


def load_tasks_from_csv(path: Path) -> List[Task]:
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        if reader.fieldnames is None:
            raise ValueError("CSV has no header row.")

        fieldnames = list(reader.fieldnames)

        id_col = detect_column(fieldnames, ["task_id", "id", "task id", "task"])
        title_col = detect_column(fieldnames, ["title", "name", "summary"])
        spec_col = detect_column(fieldnames, ["spec", "prompt", "details", "description", "body"])
        engine_col = detect_column(fieldnames, ["engine", "model", "agent"])

        tasks: List[Task] = []
        for i, row in enumerate(reader):
            raw_id = (row.get(id_col) if id_col else "") or ""
            task_id = raw_id.strip() if raw_id.strip() else f"row{i+1}"

            title = ((row.get(title_col) if title_col else "") or "").strip()

            spec = ((row.get(spec_col) if spec_col else "") or "").strip()
            if not spec:
                spec = json.dumps(row, ensure_ascii=False, indent=2)

            engine = ((row.get(engine_col) if engine_col else "") or "").strip() or None

            tasks.append(Task(task_id=task_id, title=title, spec=spec, engine=engine))

    return tasks


def load_after_prompts(after_file: Optional[Path], after_list: List[str]) -> List[str]:
    prompts: List[str] = []
    if after_file:
        raw = read_text(after_file)
        blocks = [b.strip() for b in re.split(r"\n\s*\n", raw) if b.strip()]
        prompts.extend(blocks)
    prompts.extend([p.strip() for p in after_list if p.strip()])
    return prompts


# -------------------------
# State handling
# -------------------------

def load_state(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {"cursor": dataclasses.asdict(Cursor()), "tasks": {}}
    try:
        return json.loads(read_text(path))
    except Exception:
        backup = path.with_suffix(".corrupt." + now_stamp() + ".json")
        shutil.copy2(path, backup)
        return {"cursor": dataclasses.asdict(Cursor()), "tasks": {}}


def save_state(path: Path, state: Dict[str, Any]) -> None:
    ensure_dir(path.parent)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(state, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(path)


def get_cursor(state: Dict[str, Any]) -> Cursor:
    c = state.get("cursor", {})
    return Cursor(
        task_index=int(c.get("task_index", 0)),
        phase=str(c.get("phase", "main")),
        after_index=int(c.get("after_index", 0)),
    )


def set_cursor(state: Dict[str, Any], cursor: Cursor) -> None:
    state["cursor"] = dataclasses.asdict(cursor)


def ensure_task_state(state: Dict[str, Any], task_id: str) -> Dict[str, Any]:
    tasks = state.setdefault("tasks", {})
    tstate = tasks.setdefault(task_id, {})
    tstate.setdefault("history", [])
    return tstate


# -------------------------
# Process runner
# -------------------------

_MAX_CAPTURE_CHARS = 5_000_000  # keep up to ~5MB of output in memory for JSON parsing


def run_process(
    cmd: Sequence[str],
    cwd: Path,
    log_path: Path,
    *,
    stdin_text: Optional[str] = None,
    env: Optional[Dict[str, str]] = None,
    verbose: bool = False,
) -> Tuple[int, str]:
    ensure_dir(log_path.parent)

    header = f"$ {' '.join(cmd)}\n\n"
    write_text(log_path, header)

    merged_env = os.environ.copy()
    if env:
        merged_env.update(env)

    try:
        p = subprocess.Popen(
            list(cmd),
            cwd=str(cwd),
            stdin=subprocess.PIPE if stdin_text is not None else None,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            env=merged_env,
            bufsize=1,
        )
    except FileNotFoundError:
        append_text(log_path, f"\n[agentflow] ERROR: command not found: {cmd[0]}\n")
        return 127, ""

    if stdin_text is not None and p.stdin:
        try:
            p.stdin.write(stdin_text)
            p.stdin.close()
        except BrokenPipeError:
            pass

    captured: List[str] = []
    captured_chars = 0

    assert p.stdout is not None
    for line in p.stdout:
        append_text(log_path, line)
        if verbose:
            sys.stdout.write(line)
            sys.stdout.flush()
        captured.append(line)
        captured_chars += len(line)
        if captured_chars > _MAX_CAPTURE_CHARS:
            drop = max(1, len(captured) // 10)
            captured = captured[drop:]
            captured_chars = sum(len(x) for x in captured)

    rc = p.wait()
    out = "".join(captured)
    return rc, out


def try_parse_json_object(text: str) -> Optional[Dict[str, Any]]:
    text = text.strip()
    if not text:
        return None
    for i in range(len(text) - 1, -1, -1):
        if text[i] == "{":
            candidate = text[i:]
            try:
                return json.loads(candidate)
            except Exception:
                continue
    try:
        return json.loads(text)
    except Exception:
        return None


# -------------------------
# Engines
# -------------------------

class Engine:
    def __init__(
        self,
        repo: Path,
        app_dir: Path,
        include_dirs: List[Path],
        autonomy: str,
        models: Dict[str, Optional[str]],
        verbose: bool,
        gemini_resume: Optional[str],
        claude_allowed_tools: Optional[str],
    ):
        self.repo = repo
        self.app_dir = app_dir
        self.include_dirs = include_dirs
        self.autonomy = autonomy
        self.models = models
        self.verbose = verbose
        self.gemini_resume = gemini_resume
        self.claude_allowed_tools = claude_allowed_tools

    # ----- Codex -----

    def run_codex(self, prompt: str, *, resume_last: bool, log_path: Path, last_path: Path) -> RunResult:
        if not which("codex"):
            return RunResult("codex", False, 127, "", "", error_summary="codex binary not found on PATH")

        cmd: List[str] = ["codex", "exec"]

        cmd += ["--cd", str(self.repo)]
        for d in self.include_dirs:
            cmd += ["--add-dir", str(d)]

        if self.models.get("codex"):
            cmd += ["--model", self.models["codex"]]

        if self.autonomy == "yolo":
            cmd += ["--yolo"]
        else:
            cmd += ["--sandbox", "workspace-write", "--ask-for-approval", "never"]

        cmd += ["--color", "never", "--output-last-message", str(last_path)]

        if resume_last:
            cmd += ["resume", "--last", "-"]
        else:
            cmd += ["-"]

        rc, out = run_process(cmd, self.repo, log_path, stdin_text=prompt, verbose=self.verbose)

        assistant_text = read_text(last_path).strip() if last_path.exists() else out.strip()

        return RunResult(
            engine="codex",
            ok=(rc == 0),
            exit_code=rc,
            stdout=out,
            assistant_text=assistant_text,
            log_path=log_path,
            last_message_path=last_path,
        )

    # ----- Claude -----

    def run_claude(self, prompt: str, *, resume_session: Optional[str], log_path: Path) -> RunResult:
        if not which("claude"):
            return RunResult("claude", False, 127, "", "", error_summary="claude binary not found on PATH")

        cmd: List[str] = ["claude", "-p", "--output-format", "json"]

        if self.models.get("claude"):
            cmd += ["--model", self.models["claude"]]

        for d in self.include_dirs:
            cmd += ["--add-dir", str(d)]

        if self.autonomy == "yolo" or self.autonomy == "full_auto":
            cmd += ["--dangerously-skip-permissions"]
        else:
            allowed = self.claude_allowed_tools or "Read,Edit,Grep,Glob"
            cmd += ["--allowedTools", allowed, "--permission-mode", "acceptEdits"]

        if resume_session:
            cmd += ["--resume", resume_session]

        cmd += [prompt]

        rc, out = run_process(cmd, self.repo, log_path, verbose=self.verbose)

        obj = try_parse_json_object(out)
        assistant_text = out.strip()
        session_id: Optional[str] = None
        error_summary: Optional[str] = None
        ok = (rc == 0)

        if isinstance(obj, dict):
            assistant_text = str(obj.get("result", "")).strip() or assistant_text
            session_id = obj.get("session_id")
            is_error = obj.get("is_error")
            subtype = obj.get("subtype")
            if is_error is True or subtype == "error":
                ok = False
                error_summary = clamp(assistant_text, 500)

        return RunResult(
            engine="claude",
            ok=ok,
            exit_code=rc,
            stdout=out,
            assistant_text=assistant_text,
            session_id=session_id,
            log_path=log_path,
            error_summary=error_summary,
        )

    # ----- Gemini -----

    def run_gemini(self, prompt: str, *, log_path: Path, resume_token: Optional[str]) -> RunResult:
        if not which("gemini"):
            return RunResult("gemini", False, 127, "", "", error_summary="gemini binary not found on PATH")

        cmd: List[str] = ["gemini", "--output-format", "json"]

        if self.models.get("gemini"):
            cmd += ["--model", self.models["gemini"]]

        if self.autonomy == "auto_edit":
            cmd += ["--approval-mode", "auto_edit"]
        else:
            cmd += ["--approval-mode", "yolo"]

        extra_dirs = [str(d) for d in self.include_dirs[:5]]
        for d in extra_dirs:
            cmd += ["--include-directories", d]

        if resume_token:
            cmd += ["--resume", resume_token]

        cmd += [prompt]  # positional prompt

        rc, out = run_process(cmd, self.repo, log_path, verbose=self.verbose)

        obj = try_parse_json_object(out)
        assistant_text = out.strip()
        ok = (rc == 0)
        error_summary: Optional[str] = None

        if isinstance(obj, dict):
            if obj.get("error"):
                ok = False
                error_summary = clamp(str(obj["error"]), 700)
            assistant_text = str(obj.get("response") or "").strip() or assistant_text

        return RunResult(
            engine="gemini",
            ok=ok,
            exit_code=rc,
            stdout=out,
            assistant_text=assistant_text,
            log_path=log_path,
            error_summary=error_summary,
        )


# -------------------------
# Prompt building
# -------------------------

def build_main_prompt(task: Task, *, handoff_path: Path) -> str:
    title = f" — {task.title}" if task.title else ""
    return textwrap.dedent(
        f"""\
        You are an autonomous coding agent working inside this repository.

        TASK: {task.task_id}{title}

        Instructions:
        - First read the latest handoff file if it exists: {handoff_path}
        - Implement the task described below in the codebase.
        - Run relevant tests / lint / typechecks if available and fix failures.
        - Keep changes minimal, high-quality, and consistent with the existing style.
        - If you can't fully complete, leave clear TODOs and explain what's blocked.

        Task spec:
        {task.spec}
        """
    ).strip() + "\n"


def build_after_prompt(after_prompt: str, *, task_id: str, handoff_path: Path) -> str:
    after_prompt = apply_placeholders(after_prompt, task_id)
    return textwrap.dedent(
        f"""\
        Follow-up for TASK {task_id}

        Context:
        - Read the current handoff: {handoff_path}

        Follow-up instructions:
        {after_prompt}
        """
    ).strip() + "\n"


# -------------------------
# Handoff writing
# -------------------------

def write_handoff(
    handoff_path: Path,
    *,
    task: Task,
    phase: str,
    engine: str,
    ok: bool,
    exit_code: int,
    log_path: Optional[Path],
    assistant_text: str,
    repo: Path,
) -> None:
    _, status = git(["status", "--porcelain=v1"], cwd=repo)
    _, diffstat = git(["diff", "--stat"], cwd=repo)

    body = textwrap.dedent(
        f"""\
        # Handoff — {task.task_id}

        - Title: {task.title or "(none)"}
        - Phase: {phase}
        - Engine: {engine}
        - Status: {"✅ success" if ok else "❌ failed"} (exit={exit_code})
        - Log: {log_path if log_path else "(none)"}
        - Updated: { _dt.datetime.now().isoformat(timespec="seconds") }

        ## Latest agent message (truncated)
        {clamp(assistant_text.strip(), 4000)}

        ## Git status (porcelain)
        {status.strip() or "(clean)"}

        ## Git diff --stat
        {diffstat.strip() or "(no diff)"}
        """
    ).strip() + "\n"
    write_text(handoff_path, body)


# -------------------------
# Caffeinate (macOS keep-awake)
# -------------------------

class KeepAwake:
    def __init__(self, enabled: bool):
        self.enabled = enabled
        self.proc: Optional[subprocess.Popen[str]] = None

    def __enter__(self) -> "KeepAwake":
        if not self.enabled:
            return self
        if platform.system() != "Darwin":
            print("[agentflow] --keep-awake is macOS-only; ignoring.")
            return self
        if not which("caffeinate"):
            print("[agentflow] caffeinate not found; ignoring --keep-awake.")
            return self
        self.proc = subprocess.Popen(["caffeinate", "-dimsu"])
        print("[agentflow] keep-awake enabled via caffeinate.")
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        if self.proc and self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.proc = None


# -------------------------
# Orchestration
# -------------------------

def run_task(
    engine_runner: Engine,
    task: Task,
    *,
    app_dir: Path,
    after_prompts: List[str],
    primary_engine: str,
    fallback_engines: List[str],
    state: Dict[str, Any],
    cursor: Cursor,
    controls: ControlFiles,
) -> Cursor:
    tstate = ensure_task_state(state, task.task_id)

    task_dir = app_dir / "tasks" / task.task_id
    ensure_dir(task_dir)

    handoff_path = app_dir / "handoff" / f"{task.task_id}.md"
    ensure_dir(handoff_path.parent)

    claude_session = tstate.get("claude_session_id")
    codex_can_resume = bool(tstate.get("codex_can_resume", False))
    gemini_resume = tstate.get("gemini_resume")

    engine_order: List[str] = []
    row_engine = normalize_engine(task.engine) if task.engine else None
    if row_engine:
        engine_order.append(row_engine)
    else:
        engine_order.append(primary_engine)
    engine_order.extend([e for e in fallback_engines if e not in engine_order])

    def run_with_engine(engine_name: str, prompt: str, phase: str, step_name: str) -> RunResult:
        ts = now_stamp()
        log_path = task_dir / f"{ts}.{engine_name}.{phase}.{step_name}.log"
        last_path = task_dir / f"{ts}.{engine_name}.{phase}.{step_name}.last.txt"

        print(f"\n[agentflow] {task.task_id} {phase}:{step_name} using {engine_name} ...")

        if engine_name == "codex":
            return engine_runner.run_codex(
                prompt,
                resume_last=(phase == "after" and codex_can_resume),
                log_path=log_path,
                last_path=last_path,
            )
        if engine_name == "claude":
            return engine_runner.run_claude(
                prompt,
                resume_session=(claude_session if phase == "after" else None),
                log_path=log_path,
            )
        if engine_name == "gemini":
            resume_token = gemini_resume or engine_runner.gemini_resume
            return engine_runner.run_gemini(prompt, log_path=log_path, resume_token=resume_token)

        raise ValueError(f"unsupported engine: {engine_name}")

    # ---- MAIN phase ----
    if cursor.phase == "main":
        controls.wait_if_paused()
        if controls.should_stop():
            print("[agentflow] STOP file detected; exiting before starting next step.")
            return cursor

        prompt = build_main_prompt(task, handoff_path=handoff_path)
        result: Optional[RunResult] = None

        for eng in engine_order:
            result = run_with_engine(eng, prompt, "main", "run")
            write_handoff(
                handoff_path,
                task=task,
                phase="main",
                engine=eng,
                ok=result.ok,
                exit_code=result.exit_code,
                log_path=result.log_path,
                assistant_text=result.assistant_text,
                repo=engine_runner.repo,
            )

            tstate["history"].append(
                {
                    "ts": now_stamp(),
                    "phase": "main",
                    "engine": eng,
                    "ok": result.ok,
                    "exit_code": result.exit_code,
                    "log": str(result.log_path) if result.log_path else None,
                }
            )

            if eng == "claude" and result.session_id:
                claude_session = result.session_id
                tstate["claude_session_id"] = claude_session
            if eng == "codex":
                codex_can_resume = True
                tstate["codex_can_resume"] = True

            if eng == "gemini" and (not result.ok) and ("INVALID_ARGUMENT" in (result.stdout or "")):
                tstate["gemini_resume"] = None
                print("[agentflow] gemini resume appears invalid (INVALID_ARGUMENT). Disabled resume for this task.")

            if result.ok:
                tstate["last_ok_engine"] = eng
                break

        if result is None:
            raise RuntimeError("No engine ran.")

        if not result.ok:
            tstate["status"] = "failed"
            print(f"[agentflow] Task {task.task_id} failed with all engines.")
            cursor = Cursor(task_index=cursor.task_index + 1, phase="main", after_index=0)
            set_cursor(state, cursor)
            return cursor

        tstate["status"] = "main_ok"
        cursor = Cursor(task_index=cursor.task_index, phase="after", after_index=0)
        set_cursor(state, cursor)
        save_state(engine_runner.app_dir / DEFAULT_STATE_FILE, state)

    # ---- AFTER phase ----
    while cursor.phase == "after" and cursor.after_index < len(after_prompts):
        controls.wait_if_paused()
        if controls.should_stop():
            print("[agentflow] STOP file detected; exiting before starting next step.")
            return cursor

        idx = cursor.after_index
        ap = after_prompts[idx]
        step_name = f"after{idx+1}"

        prompt = build_after_prompt(ap, task_id=task.task_id, handoff_path=handoff_path)

        last_ok = tstate.get("last_ok_engine") or primary_engine
        after_engine_order = [last_ok, primary_engine] + [e for e in fallback_engines if e not in {last_ok, primary_engine}]

        result = None
        for eng in after_engine_order:
            result = run_with_engine(eng, prompt, "after", step_name)
            write_handoff(
                handoff_path,
                task=task,
                phase=f"after[{idx+1}/{len(after_prompts)}]",
                engine=eng,
                ok=result.ok,
                exit_code=result.exit_code,
                log_path=result.log_path,
                assistant_text=result.assistant_text,
                repo=engine_runner.repo,
            )

            tstate["history"].append(
                {
                    "ts": now_stamp(),
                    "phase": "after",
                    "after_index": idx,
                    "engine": eng,
                    "ok": result.ok,
                    "exit_code": result.exit_code,
                    "log": str(result.log_path) if result.log_path else None,
                }
            )

            if eng == "claude" and result.session_id:
                claude_session = result.session_id
                tstate["claude_session_id"] = claude_session
            if eng == "codex":
                codex_can_resume = True
                tstate["codex_can_resume"] = True

            if eng == "gemini" and (not result.ok) and ("INVALID_ARGUMENT" in (result.stdout or "")):
                tstate["gemini_resume"] = None
                print("[agentflow] gemini resume appears invalid (INVALID_ARGUMENT). Disabled resume for this task.")

            if result.ok:
                tstate["last_ok_engine"] = eng
                break

        if result is None or not result.ok:
            tstate["status"] = "after_failed"
            print(f"[agentflow] After prompt {idx+1} failed for task {task.task_id}. Continuing to next prompt.")
        else:
            tstate["status"] = f"after_ok_{idx+1}"

        cursor.after_index += 1
        set_cursor(state, cursor)
        save_state(engine_runner.app_dir / DEFAULT_STATE_FILE, state)

    tstate["status"] = "done"
    cursor = Cursor(task_index=cursor.task_index + 1, phase="main", after_index=0)
    set_cursor(state, cursor)
    save_state(engine_runner.app_dir / DEFAULT_STATE_FILE, state)
    return cursor


# -------------------------
# main
# -------------------------

def parse_args(argv: Optional[Sequence[str]] = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="agentflow", formatter_class=argparse.RawTextHelpFormatter)

    p.add_argument("--tasks", required=True, help="Path to CSV file containing tasks.")
    p.add_argument("--repo", default=".", help="Repo/workspace root (default: current dir).")
    p.add_argument("--engine", default="codex", help="Primary engine: codex|claude|gemini (default: codex).")
    p.add_argument("--fallback", default="claude,gemini", help="Fallback engines, comma-separated (default: claude,gemini).")
    p.add_argument("--autonomy", default="full_auto", choices=["auto_edit", "full_auto", "yolo"], help="Automation level.")
    p.add_argument("--model-codex", default=None, help="Codex model override (e.g., gpt-5-codex).")
    p.add_argument("--model-claude", default=None, help="Claude model override (e.g., sonnet|opus).")
    p.add_argument("--model-gemini", default=None, help="Gemini model override (e.g., gemini-3-pro-preview).")
    p.add_argument("--include-dirs", default="", help="Extra dirs to include for agents (comma-separated).")
    p.add_argument("--after-file", default=None, help="File containing after-prompts (blank-line separated).")
    p.add_argument("--after", action="append", default=[], help="Add an after-prompt (repeatable).")
    p.add_argument("--resume", action="store_true", help="Resume from .agentflow/state.json cursor if present.")
    p.add_argument("--start-task", default=None, help="Start from a specific task id (overrides --resume cursor).")
    p.add_argument("--verbose", action="store_true", help="Stream agent output to stdout as it runs.")
    p.add_argument("--keep-awake", action="store_true", help="macOS: run caffeinate to reduce sleep while running.")
    p.add_argument("--gemini-resume", default=None, help='Gemini resume token: "latest", session index, or UUID.')
    p.add_argument("--claude-allowed-tools", default=None, help='Claude allowed tools for auto_edit (default: "Read,Edit,Grep,Glob").')

    return p.parse_args(argv)


def main(argv: Optional[Sequence[str]] = None) -> int:
    args = parse_args(argv)

    repo = Path(args.repo).expanduser().resolve()
    tasks_path = Path(args.tasks).expanduser().resolve()
    if not tasks_path.exists():
        print(f"[agentflow] ERROR: tasks CSV not found: {tasks_path}")
        return 2

    app_dir = repo / APP_DIRNAME
    ensure_dir(app_dir / "handoff")
    ensure_dir(app_dir / "tasks")

    controls = ControlFiles(app_dir)

    primary_engine = normalize_engine(args.engine)
    fallback_engines = [normalize_engine(x) for x in split_csv_list(args.fallback)]
    fallback_engines = [e for e in fallback_engines if e != primary_engine]

    include_dirs: List[Path] = []
    if args.include_dirs.strip():
        for d in split_csv_list(args.include_dirs):
            include_dirs.append(Path(d).expanduser().resolve())

    after_prompts = load_after_prompts(Path(args.after_file).expanduser().resolve() if args.after_file else None, args.after)

    models = {"codex": args.model_codex, "claude": args.model_claude, "gemini": args.model_gemini}

    engine_runner = Engine(
        repo=repo,
        app_dir=app_dir,
        include_dirs=include_dirs,
        autonomy=args.autonomy,
        models=models,
        verbose=args.verbose,
        gemini_resume=args.gemini_resume,
        claude_allowed_tools=args.claude_allowed_tools,
    )

    tasks = load_tasks_from_csv(tasks_path)
    if not tasks:
        print("[agentflow] No tasks found in CSV.")
        return 0

    state_path = app_dir / DEFAULT_STATE_FILE
    state = load_state(state_path)
    cursor = get_cursor(state)

    if args.start_task:
        target = args.start_task.strip()
        idx = next((i for i, t in enumerate(tasks) if t.task_id == target), None)
        if idx is None:
            print(f"[agentflow] ERROR: start-task '{target}' not found in CSV.")
            return 2
        cursor = Cursor(task_index=idx, phase="main", after_index=0)
        set_cursor(state, cursor)
        save_state(state_path, state)
    elif not args.resume:
        cursor = Cursor(task_index=0, phase="main", after_index=0)
        set_cursor(state, cursor)
        save_state(state_path, state)

    def _sigint_handler(sig, frame):
        write_text(controls.pause_path, "paused\n")
        print(f"\n[agentflow] SIGINT received => created {controls.pause_path}. Delete it to continue.\n")

    signal.signal(signal.SIGINT, _sigint_handler)

    print(
        "[agentflow] starting\n"
        f"  repo: {repo}\n"
        f"  tasks: {tasks_path}\n"
        f"  primary: {primary_engine}\n"
        f"  fallback: {fallback_engines}\n"
        f"  autonomy: {args.autonomy}\n"
        f"  after_prompts: {len(after_prompts)}\n"
        f"  state: {state_path}\n"
        f"  pause: {controls.pause_path}\n"
        f"  stop: {controls.stop_path}\n"
    )

    with KeepAwake(args.keep_awake):
        while cursor.task_index < len(tasks):
            task = tasks[cursor.task_index]
            cursor = run_task(
                engine_runner,
                task,
                app_dir=app_dir,
                after_prompts=after_prompts,
                primary_engine=primary_engine,
                fallback_engines=fallback_engines,
                state=state,
                cursor=cursor,
                controls=controls,
            )
            save_state(state_path, state)

            if controls.should_stop():
                print("[agentflow] STOP file detected; stopping.")
                return 0

    print("[agentflow] all tasks complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
