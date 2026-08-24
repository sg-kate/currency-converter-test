---
name: browser-capture
description: "Use when screenshots of the running site or its admin are needed — for a deliverable, a bug report, or a before/after comparison — or when a page has to be checked as a browser actually renders it rather than as curl returns it."
compatibility: "Playwright with headless Chromium, Node 18+. Requires the stack to be running and `npm install && npx playwright install chromium` once."
---

# Browser capture

Screenshots the site through headless Chromium. Deterministic by construction: a capture that
shifts between runs cannot be used to compare before and after.

## Usage

```bash
npm install && npx playwright install chromium     # once
make screenshots                                   # the default set

node .claude/skills/browser-capture/scripts/capture.mjs --list
node .claude/skills/browser-capture/scripts/capture.mjs \
    --shot rates=/wp/wp-admin/admin.php?page=some-page
```

Output: `docs/screenshots/NN-name.png`, numbered in capture order.

Flags: `--url`, `--user`, `--password`, `--out`, `--width`, `--height`. Defaults come from
`WP_HOME`, `WP_ADMIN_USER` and `WP_ADMIN_PASSWORD`, matching `.env`.

## Bedrock paths

The one thing a generic browser tool gets wrong here. Core is served from `/wp`:

| | |
|---|---|
| Login | `/wp/wp-login.php` |
| Admin | `/wp/wp-admin/` |
| Plugin page | `/wp/wp-admin/admin.php?page=…` |
| Front end | `/` |

A tool pointed at `/wp-login.php` gets a 404 and usually reports it as "the site is down".

## What makes the output stable

- fixed viewport (1440×900 by default) at `deviceScaleFactor: 2`
- `reducedMotion: 'reduce'`, plus injected CSS that kills every animation and transition
- the caret is made transparent — a blinking cursor otherwise changes the image between runs
- the admin-bar logo and heartbeat indicator are hidden
- `waitUntil: 'networkidle'` before each shot

Login happens once and the session is reused, so a run of ten shots does ten page loads, not ten
logins.

## Failure behaviour

One failed shot does not abort the run — the rest are still captured, the failure is printed, and
the process exits non-zero. A screenshot run that half-fails silently is how empty images end up
in a deliverable.

Two errors are explicit rather than generic, because they are the common ones:

- no login form at the expected URL → the stack is down, or the path assumption is wrong
- still on `wp-login.php` after submitting → credentials are wrong

## When not to use this

For checking status codes, headers or redirects, `curl` is faster and clearer. Reach for the
browser when rendering, JavaScript or an authenticated view is what actually matters.
