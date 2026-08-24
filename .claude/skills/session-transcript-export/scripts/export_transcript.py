#!/usr/bin/env python3
"""Export Claude Code session transcripts to redacted Markdown.

Reads the JSONL transcripts Claude Code writes under ~/.claude/projects/<slug>/
and renders them as Markdown suitable for handing to someone else.

Redaction is the point, not a feature: the exported files are meant to be shared,
and the sessions they come from contain database passwords, generated WordPress
salts and — later — an API key. The script therefore scans its own output and
exits non-zero if any known secret survived, so a broken redaction fails loudly
instead of shipping quietly.

Usage:
    export_transcript.py --slug <project-slug> [--out docs/transcripts]
    export_transcript.py --list
    export_transcript.py --self-check
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

PROJECTS_DIR = Path.home() / ".claude" / "projects"

# Which .env entries hold secrets, by the shape of their name rather than a list.
# A list would need editing every time a new credential appears — and the one that
# gets forgotten is the one that leaks.
SECRET_KEY_PATTERN = re.compile(r"(?:PASSWORD|SECRET|TOKEN|APIKEY)|_(?:KEY|SALT)$")

# Shapes that look like secrets even when we have no value to compare against —
# an API key pasted into chat, for instance, that never reached .env.
PATTERN_RULES = (
    # Vendor-prefixed API keys, e.g. sk_live_… / fca_live_… — the shape survives
    # even when the value never reached .env.
    (re.compile(r"\b[a-z]{2,6}_(?:live|test)_[A-Za-z0-9]{16,}\b"), "[REDACTED:api-key]"),
    (
        re.compile(r"(?i)\b(api[_-]?key)\b([ \t]*[:=][ \t]*)['\"]?([A-Za-z0-9_\-]{16,})"),
        r"\1\2[REDACTED:api-key]",
    ),
    # Three deliberate narrowings, each of which cost a false positive to find:
    #
    # - the separator is [ \t], not \s: \s matches newlines, so the rule stitched a
    #   trailing "..._PASSWORD:" together with the first word of the next line and
    #   redacted innocent prose;
    # - the name must be upper case, so ordinary code like `key = key.strip()` is
    #   left alone — env-var secrets are shouted, local variables are not;
    # - no closing quote is required, because a secret that reached the transcript
    #   through truncated output ("AUTH_KEY=')!%W7hDW") has no terminator, and a
    #   rule insisting on one steps straight over it.
    (
        re.compile(r"\b([A-Z][A-Z_]*(?:PASSWORD|SALT|SECRET|TOKEN|KEY))\b([ \t]*[:=][ \t]*)['\"]?([^\s'\"]{6,})"),
        r"\1\2[REDACTED]",
    ),
    (re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.]+\b"), "[REDACTED:email]"),
)

KEY_BODY_PATTERN = re.compile(r"[a-z]{2,6}_(?:live|test)_([A-Za-z0-9]{16,})")

MIN_LITERAL_LEN = 6


def parse_env(path: Path) -> dict[str, str]:
    if not path.is_file():
        return {}

    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, raw = line.partition("=")
        values[key.strip()] = raw.strip().strip("'\"")
    return values


def load_env_secrets(env_path: Path, example_path: Path) -> tuple[list[str], list[str]]:
    """Return (secrets, skipped) literal values from .env, longest first.

    Longest first matters: redacting a short value that is a substring of a longer
    one would leave the tail of the longer one exposed.

    A value that also appears in .env.example is **not** treated as a secret. That
    file is committed, so anything in it is already public — and blind-replacing
    such values wrecks the document: DB_PASSWORD defaults to "wordpress", which
    would redact the word "wordpress" everywhere it occurs in the conversation.
    """
    env = parse_env(env_path)
    published = {value for value in parse_env(example_path).values() if value}

    secrets: list[str] = []
    skipped: list[str] = []

    for key, value in env.items():
        if not SECRET_KEY_PATTERN.search(key):
            continue
        if len(value) < MIN_LITERAL_LEN:
            continue
        if value in published:
            skipped.append(key)
            continue
        secrets.append(value)

        # A prefixed API key is also registered without its prefix.
        #
        # `PATTERN_RULES` catches `fca_live_ABC…` written whole, and the literal above
        # catches the exact value — but neither sees a key split across a concatenation,
        # which is how one reached a transcript: `"fca_live_" + "ABC…"`. Two string
        # fragments, and the entropy-bearing half carries no prefix to match on. Adding
        # the body as its own literal closes that, and the body is long and random enough
        # that redacting it cannot collide with ordinary prose.
        body = KEY_BODY_PATTERN.fullmatch(value)
        if body and body.group(1) not in published:
            secrets.append(body.group(1))

    return sorted(set(secrets), key=len, reverse=True), skipped


def redact(text: str, literals: list[str]) -> str:
    for literal in literals:
        text = text.replace(literal, "[REDACTED]")
    for pattern, replacement in PATTERN_RULES:
        text = pattern.sub(replacement, text)
    return text


def find_leaks(text: str, literals: list[str]) -> list[str]:
    """Return secrets that survived redaction. Empty means the output is clean."""
    return [literal for literal in literals if literal in text]


def block_to_text(block) -> str:
    """Flatten one content block of a transcript message."""
    if isinstance(block, str):
        return block
    if not isinstance(block, dict):
        return ""

    kind = block.get("type")

    if kind == "text":
        return block.get("text", "")

    if kind == "thinking":
        return ""

    if kind == "tool_use":
        name = block.get("name", "tool")
        payload = json.dumps(block.get("input", {}), ensure_ascii=False, indent=2)
        return (
            f"\n<details>\n<summary>Tool: {name}</summary>\n\n"
            f"```json\n{payload}\n```\n\n</details>\n"
        )

    if kind == "tool_result":
        content = block.get("content", "")
        if isinstance(content, list):
            content = "\n".join(block_to_text(part) for part in content)
        content = str(content)
        if len(content) > 4000:
            content = content[:4000] + "\n… (truncated)"
        return (
            "\n<details>\n<summary>Result</summary>\n\n"
            f"```\n{content}\n```\n\n</details>\n"
        )

    return ""


def render(entries: list[dict]) -> str:
    lines: list[str] = []
    last_role = None

    for entry in entries:
        message = entry.get("message")
        if not isinstance(message, dict):
            continue

        role = message.get("role")
        if role not in ("user", "assistant"):
            continue

        content = message.get("content", "")
        if isinstance(content, str):
            parts = [content]
        elif isinstance(content, list):
            parts = [block_to_text(block) for block in content]
        else:
            parts = []

        body = "\n".join(part for part in parts if part).strip()
        if not body:
            continue

        heading = "## User" if role == "user" else "## Assistant"
        if role != last_role:
            lines.append(heading)
            last_role = role
        lines.append(body)
        lines.append("")

    return "\n".join(lines).strip() + "\n"


def read_session(path: Path) -> list[dict]:
    entries = []
    with path.open(encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            try:
                entries.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return entries


def list_slugs() -> int:
    if not PROJECTS_DIR.is_dir():
        print(f"no transcripts directory at {PROJECTS_DIR}", file=sys.stderr)
        return 1
    for slug_dir in sorted(PROJECTS_DIR.iterdir()):
        if not slug_dir.is_dir():
            continue
        sessions = sorted(slug_dir.glob("*.jsonl"))
        if sessions:
            print(f"{slug_dir.name}\t{len(sessions)} session(s)")
    return 0


def self_check(literals: list[str]) -> int:
    """Prove the redaction gate actually fires, using a synthetic secret."""
    canary = "SuperSecretCanaryValue12345"
    probe = literals + [canary]
    sample = f"the password is {canary} and it must not survive"

    redacted = redact(sample, probe)
    leaks = find_leaks(redacted, probe)

    if canary in redacted or leaks:
        print("self-check FAILED: canary survived redaction", file=sys.stderr)
        return 1

    # And the detector must catch a leak when redaction is skipped.
    if not find_leaks(sample, probe):
        print("self-check FAILED: leak detector did not fire", file=sys.stderr)
        return 1

    print("self-check OK: redaction removes secrets and the detector catches leaks")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    # Slugs start with a dash, so they must be passed as --slug=-Users-... ;
    # argparse would otherwise read the value as another option.
    parser.add_argument("--slug", help="project directory under ~/.claude/projects")
    parser.add_argument(
        "--session",
        action="append",
        default=[],
        help="only export sessions whose id starts with this (repeatable); "
        "without it every session in the slug is exported",
    )
    parser.add_argument("--out", default="docs/transcripts", help="output directory")
    parser.add_argument("--env", default=".env", help="file to read literal secrets from")
    parser.add_argument(
        "--example",
        default=".env.example",
        help="committed template; values present here are public defaults, not secrets",
    )
    parser.add_argument("--list", action="store_true", help="list available slugs")
    parser.add_argument("--self-check", action="store_true", help="verify the redaction gate")
    args = parser.parse_args()

    literals, skipped = load_env_secrets(Path(args.env), Path(args.example))

    if args.list:
        return list_slugs()

    if args.self_check:
        return self_check(literals)

    if not args.slug:
        parser.error("--slug is required (use --list to see the options)")

    source_dir = PROJECTS_DIR / args.slug
    if not source_dir.is_dir():
        print(f"no such slug: {source_dir}", file=sys.stderr)
        return 1

    sessions = sorted(source_dir.glob("*.jsonl"), key=lambda p: p.stat().st_mtime)

    if args.session:
        sessions = [
            path
            for path in sessions
            if any(path.stem.startswith(prefix) for prefix in args.session)
        ]
        if not sessions:
            print(f"no session in {source_dir} matches {args.session}", file=sys.stderr)
            return 1

    if not sessions:
        print(f"no sessions in {source_dir}", file=sys.stderr)
        return 1

    if skipped:
        print(
            f"note: {', '.join(skipped)} match published defaults in {args.example} "
            "and are treated as public, not redacted"
        )

    if not literals:
        print(
            f"warning: no secret values loaded from {args.env}; "
            "only pattern-based redaction will apply",
            file=sys.stderr,
        )

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    short_slug = args.slug.strip("-").split("-")[-1] or "session"
    failures = 0

    for index, session in enumerate(sessions, start=1):
        entries = read_session(session)
        if not entries:
            continue

        stamp = datetime.fromtimestamp(session.stat().st_mtime, tz=timezone.utc)
        body = render(entries)
        body = redact(body, literals)

        leaks = find_leaks(body, literals)
        if leaks:
            print(
                f"REDACTION FAILED for {session.name}: "
                f"{len(leaks)} secret value(s) survived",
                file=sys.stderr,
            )
            failures += 1
            continue

        header = (
            f"# Session transcript — {args.slug}\n\n"
            f"- Session: `{session.stem}`\n"
            f"- Exported: {datetime.now(tz=timezone.utc):%Y-%m-%d %H:%M} UTC\n"
            f"- Secrets redacted: {len(literals)} literal value(s) plus pattern rules\n\n---\n\n"
        )

        target = out_dir / f"{stamp:%Y-%m-%d}-{short_slug}-{index:02d}.md"
        target.write_text(header + body, encoding="utf-8")
        print(f"wrote {target} ({len(body.splitlines())} lines)")

    if failures:
        print(f"{failures} transcript(s) not written because redaction failed", file=sys.stderr)
        return 2

    return 0


if __name__ == "__main__":
    sys.exit(main())
