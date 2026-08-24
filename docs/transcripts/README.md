# Session transcripts

Every Claude Code session behind this project, planning and implementation alike, exported to
Markdown and redacted. Nine sessions, covering every stage of the work in this repository.

Exported with `.claude/skills/session-transcript-export/`, which replaces the literal values from
`.env` and then re-scans its own output — if any secret survived, the file is not written and the
export fails. See [Redaction](#redaction) below for how to verify that yourself.

## Read this first: the plan does not describe this repository

The two planning sessions are not included here. They ran in a different working directory
and also covered unrelated client work, which cannot be published with this deliverable.
What they decided still matters to a reader, because the repository does not match it: the
plan specified a **flat WordPress install**, and that is not what was built.

| | The plan (Aug 23) | The repository (built Aug 24) |
|---|---|---|
| Location | `~/development/currency-test` | `~/development/test-project` |
| WordPress | downloaded "clean", official `wordpress` image | Composer dependency `roots/wordpress`, custom `php:8.3-apache` image |
| Plugin path | `plugin/currency-converter/`, bind-mounted to `/var/www/html/wp-content/plugins/` | `web/app/plugins/currency-converter/` |
| Configuration | `WORDPRESS_CONFIG_EXTRA` environment | `config/application.php` reading `.env` |
| Document root | `/var/www/html` | `web/` |

**The switch to Bedrock is the single largest deviation from the plan, and it was the right call:**
Bedrock is what gives the project a `.env` for the API key that is neither committed nor stored in
the database, Composer-owned core that `make reset` can throw away, and a document root that keeps
`config/` and `vendor/` outside it. `docs/DECISIONS.md` D14 argues it in full, including the three
traps it costs — `wp config get` going blind, the `/wp/wp-admin` paths, and lint having to skip the
Bedrock scaffolding.

**The transcripts do not contain the moment that decision was made.** By the first session of
Aug 24 the working tree is already Bedrock, and no session between the Aug 23 plan and that point
exists in either exported directory. The plan was superseded rather than revised, so read it as a
record of the starting intent, not as a description of the deliverable.

## Order

**The file numbers are not the order of events.** The exporter numbers files per directory; the
sessions interleave across two of them. This is the real sequence, by session start time (UTC).

| # | File | Started | What happened |
|---|---|---|---|
| 1 | [project-01](2026-08-24-project-01.md) | Aug 24, 12:45 | First run at `docs/REQUIREMENTS.md`; abandoned within a minute and restarted as the next session. Kept because it is part of the record, not because it produced anything. |
| 2 | [project-02](2026-08-24-project-02.md) | Aug 24, 12:46 | **`docs/REQUIREMENTS.md` written** — the brief split into twelve numbered requirements, each with a command that decides it, plus the collisions C1–C8 where the brief cannot be satisfied literally. |
| 3 | [project-03](2026-08-24-project-03.md) | Aug 24, 13:18 | **Plugin skeleton and schema.** Plugin header with an `ABSPATH` guard, hand-written PSR-4 autoloader, `Plugin::boot()`, activation hook, `uninstall.php`; `Db\Schema` with the `dbDelta()` DDL for `cc_rates` and `cc_currencies`, walked rule by rule. |
| 4 | [project-05](2026-08-24-project-05.md) | Aug 24, 16:42 | **API key wiring.** `FREECURRENCYAPI_KEY` added empty to `.env.example` and defined through `config/application.php`; the real value put into `.env` by hand and never echoed. Verified the key is absent from the git index and from these transcripts. |
| 5 | [project-04](2026-08-24-project-04.md) | Aug 24, 17:30 | **Domain and repositories.** `Currency` and `Rate` value objects (the rate is a string, never a float), repository interfaces, separate exception types; `WpdbRateRepository` with one multi-row `INSERT … ON DUPLICATE KEY UPDATE`, `%s` binding, the USD identity row written unconditionally, and an allowlist for `orderby`. |
| 6 | [project-06](2026-08-24-project-06.md) | Aug 24, 18:10 | **The conversion service.** `Service\CurrencyConverter` with the brief's exact signature, the USD rate map memoised once per request, and half-up rounding through `bcmath` rather than `round()` on a float. |
| 7 | [project-08](2026-08-24-project-08.md) | Aug 24, 18:33 | **Scheduling.** `Cron\Scheduler` registering both hooks unconditionally (a cron run is a front-end request, so an admin-only handler never fires), healing a lost event on `init`, and scheduling a one-off run 30 seconds after activation so the table is not empty when a reviewer first looks. |
| 8 | [project-09](2026-08-24-project-09.md) | Aug 24, 19:32 | **Verification sweep**, run and reported before anything was fixed: `make test`, `make lint`, and greps for `everapihq`, disabled TLS verification, `%f` binding and `$wpdb->replace()`. |
| 9 | [project-10](2026-08-24-project-10.md) | Aug 24, 20:24 | **QA, documentation and packaging.** A cold-start QA run against all twelve requirements; `docs/DECISIONS.md` completed to sixteen entries; `README.md` and `README.ru.md` written and the three-command setup verified from a clean clone; these transcripts exported. **This session also destroyed the plugin source** — `wp plugin uninstall`, run as `docs/REQUIREMENTS.md` R4 specifies, deletes plugin files, and the plugin was untracked in git. The QA report in this transcript records it in full. |

## Redaction

Two layers: the literal values read from `.env` (longest first, so redacting a short value cannot
expose the tail of a longer one), and pattern rules for `NAME=value` shapes, `fca_live_…` keys and
e-mail addresses. Which names count as secret is decided by the shape of the name — `*_KEY`,
`*_SALT`, anything containing `PASSWORD`, `SECRET`, `TOKEN`, `APIKEY` — not by a hand-kept list.

Each export here reports **8 literal values redacted plus pattern rules**: the eight WordPress keys
and salts.

`DB_PASSWORD` is deliberately **not** redacted. Its value is `wordpress`, which is the value
published in the committed `.env.example` and therefore public by definition. Redacting it once
replaced all 383 occurrences of the word "wordpress" in a transcript and destroyed the document
while leaking nothing — so any value that also appears in `.env.example` is skipped, and the
exporter names which keys it skipped.

To verify:

```bash
# the gate itself, against a synthetic canary
python3 .claude/skills/session-transcript-export/scripts/export_transcript.py --self-check

# and against the real output — every one of these must print nothing
grep -rF "$(grep '^AUTH_KEY=' .env | cut -d= -f2- | tr -d "'")" docs/transcripts/
grep -rE 'fca_live_[A-Za-z0-9]{10,}' docs/transcripts/
```
