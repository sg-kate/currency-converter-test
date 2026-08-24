---
name: session-transcript-export
description: "Use when exporting Claude Code session transcripts to shareable Markdown — for a deliverable, a handover or a review — and whenever the output has to be free of credentials, API keys or salts."
compatibility: "Python 3.9+. Reads the JSONL sessions Claude Code writes under ~/.claude/projects/<slug>/."
---

# Session transcript export

Renders Claude Code sessions as Markdown and **redacts secrets, verifiably**. The exported files
are meant to leave the machine, and the sessions they come from contain database passwords,
generated WordPress salts and API keys.

## Usage

```bash
python3 .claude/skills/session-transcript-export/scripts/export_transcript.py --list
python3 .claude/skills/session-transcript-export/scripts/export_transcript.py --self-check
python3 .claude/skills/session-transcript-export/scripts/export_transcript.py \
    --slug=-Users-drozd-development-test-project
python3 .claude/skills/session-transcript-export/scripts/export_transcript.py \
    --slug=-Users-drozd-development-other-project --session=abc123
```

Output lands in `docs/transcripts/YYYY-MM-DD-<slug>-NN.md`.

**Slugs begin with a dash, so they must be passed as `--slug=…`.** Written as
`--slug -Users-…`, argparse reads the value as another option and fails.

**Use `--session` on busy slugs.** A project directory can hold dozens of sessions; without a
filter every one of them is exported.

## How redaction works

Two layers, because either alone misses cases:

1. **Literal values from `.env`** — the actual passwords and salts in use, replaced longest-first
   so that redacting a short value cannot expose the tail of a longer one. Which entries count as
   secret is decided by the **shape of the name** (`*_KEY`, `*_SALT`, anything containing
   `PASSWORD`, `SECRET`, `TOKEN`, `APIKEY`), not by a hand-maintained list — the credential
   someone forgets to add to a list is exactly the one that leaks.
2. **Pattern rules** — `NAME=value` shapes, `fca_live_…` keys and e-mail addresses, which catch
   secrets that never reached `.env` (a key pasted straight into the chat, for instance).

Then the output is **scanned again**. If any literal survived, the file is not written and the
script exits non-zero. A redaction that quietly stops working is worse than one that fails.

## Rules that were learned the hard way

Each of these was a real defect found by running the exporter against a real session.

### A value published in `.env.example` is not a secret

`DB_PASSWORD` defaults to `wordpress`. Treating that as a literal secret replaced **every**
occurrence of the word "wordpress" in the transcript — 383 of them — and the leak check still
passed, because nothing had leaked; the document was simply destroyed. Any value that also
appears in the committed `.env.example` is public by definition and is skipped, with a note
naming which keys were skipped.

### The separator must not match newlines

`\s*[:=]\s*` looks harmless and is not: `\s` includes newlines, so a line ending in
`..._PASSWORD:` was stitched to the first word of the next line and redacted innocent prose.
The rule uses `[ \t]*[:=][ \t]*`.

### The variable name must be upper case

With `(?i)`, `[A-Z_]*KEY` matches the ordinary local variable in `key = key.strip()`. Env-var
secrets are shouted; local variables are not. The name rule is case-sensitive, and the separate
lowercase `apikey` rule covers the one shape that genuinely appears in lower case.

### No closing quote may be required

A secret that reached the transcript through truncated output — `AUTH_KEY=')!%W7hDW` — has no
terminator. A rule that insists on a matching closing quote walks straight past it.

## Verifying

```bash
# the gate itself, with a synthetic canary
python3 …/export_transcript.py --self-check

# and against the real output
grep -F "$(grep '^AUTH_KEY=' .env | cut -d= -f2- | tr -d "'")" docs/transcripts/*.md   # expect nothing
```

`--self-check` proves two things at once: redaction removes a known value, and the leak detector
notices when redaction is skipped. A gate that never fires is not a gate.
