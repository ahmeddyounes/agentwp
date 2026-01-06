#!/usr/bin/env python3
import argparse
import json
import os
import sys


def load_json(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def map_level(severity):
    if not severity:
        return "warning"
    severity = severity.lower()
    if severity in ("critical", "high"):
        return "error"
    if severity in ("moderate", "medium"):
        return "warning"
    if severity in ("low", "info"):
        return "note"
    return "warning"


def sarif_skeleton(tool_name, info_uri):
    return {
        "$schema": "https://json.schemastore.org/sarif-2.1.0.json",
        "version": "2.1.0",
        "runs": [
            {
                "tool": {
                    "driver": {
                        "name": tool_name,
                        "informationUri": info_uri,
                        "rules": [],
                    }
                },
                "results": [],
            }
        ],
    }


def add_rule(rules, rule_map, rule_id, title, help_uri=None):
    if rule_id in rule_map:
        return
    rule = {
        "id": rule_id,
        "name": rule_id,
        "shortDescription": {"text": title or rule_id},
    }
    if help_uri:
        rule["helpUri"] = help_uri
    rule_map[rule_id] = rule
    rules.append(rule)


def add_result(results, rule_id, level, message, location):
    result = {
        "ruleId": rule_id,
        "level": level,
        "message": {"text": message},
    }
    if location:
        result["locations"] = [
            {"physicalLocation": {"artifactLocation": {"uri": location}}}
        ]
    results.append(result)


def composer_to_sarif(data, location):
    sarif = sarif_skeleton("Composer Audit", "https://getcomposer.org/doc/03-cli.md#audit")
    run = sarif["runs"][0]
    rules = run["tool"]["driver"]["rules"]
    results = run["results"]
    rule_map = {}

    advisories = data.get("advisories", {})
    for package, entries in advisories.items():
        for index, advisory in enumerate(entries):
            title = advisory.get("title") or advisory.get("advisory") or "Composer advisory"
            cve = advisory.get("cve")
            rule_id = f"composer:{package}:{cve or index}"
            severity = advisory.get("severity")
            affected = advisory.get("affectedVersions") or advisory.get("affected_versions")
            message = f"{package}: {title}"
            if affected:
                message = f"{message} (affected: {affected})"
            help_uri = advisory.get("link") or advisory.get("url")
            add_rule(rules, rule_map, rule_id, title, help_uri)
            add_result(results, rule_id, map_level(severity), message, location)

    return sarif


def npm_v7_to_sarif(data, location):
    sarif = sarif_skeleton("npm audit", "https://docs.npmjs.com/cli/v9/commands/npm-audit")
    run = sarif["runs"][0]
    rules = run["tool"]["driver"]["rules"]
    results = run["results"]
    rule_map = {}

    vulnerabilities = data.get("vulnerabilities", {})
    for package, info in vulnerabilities.items():
        via = info.get("via", [])
        if isinstance(via, dict):
            via = [via]
        for entry in via:
            if isinstance(entry, str):
                continue
            title = entry.get("title") or entry.get("name") or "npm advisory"
            source = entry.get("source") or entry.get("url") or title
            severity = entry.get("severity") or info.get("severity")
            affected = entry.get("range") or info.get("range")
            rule_id = f"npm:{package}:{source}"
            message = f"{package}: {title}"
            if affected:
                message = f"{message} (affected: {affected})"
            help_uri = entry.get("url")
            add_rule(rules, rule_map, rule_id, title, help_uri)
            add_result(results, rule_id, map_level(severity), message, location)

    return sarif


def npm_v6_to_sarif(data, location):
    sarif = sarif_skeleton("npm audit", "https://docs.npmjs.com/cli/v9/commands/npm-audit")
    run = sarif["runs"][0]
    rules = run["tool"]["driver"]["rules"]
    results = run["results"]
    rule_map = {}

    advisories = data.get("advisories", {})
    for advisory_id, advisory in advisories.items():
        package = advisory.get("module_name") or advisory.get("name") or "package"
        title = advisory.get("title") or "npm advisory"
        severity = advisory.get("severity")
        affected = advisory.get("vulnerable_versions")
        rule_id = f"npm:{package}:{advisory_id}"
        message = f"{package}: {title}"
        if affected:
            message = f"{message} (affected: {affected})"
        help_uri = advisory.get("url")
        add_rule(rules, rule_map, rule_id, title, help_uri)
        add_result(results, rule_id, map_level(severity), message, location)

    return sarif


def write_sarif(output_path, sarif):
    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(sarif, handle, indent=2)
        handle.write("\n")


def resolve_location(preferred):
    if preferred and os.path.exists(preferred):
        return preferred
    return preferred


def main():
    parser = argparse.ArgumentParser(description="Convert audit JSON reports to SARIF.")
    parser.add_argument("--tool", choices=["composer", "npm"], required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--location", default="")
    args = parser.parse_args()

    try:
        data = load_json(args.input)
    except (OSError, json.JSONDecodeError) as exc:
        print(f"Failed to read audit JSON: {exc}", file=sys.stderr)
        return 2

    location = resolve_location(args.location)
    if args.tool == "composer":
        sarif = composer_to_sarif(data, location)
    else:
        if "auditReportVersion" in data or "vulnerabilities" in data:
            sarif = npm_v7_to_sarif(data, location)
        else:
            sarif = npm_v6_to_sarif(data, location)

    write_sarif(args.output, sarif)
    return 0


if __name__ == "__main__":
    sys.exit(main())
