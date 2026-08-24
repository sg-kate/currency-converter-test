<!--
  Not an agent. No YAML frontmatter, and the leading underscore keeps it out of the
  agent registry — a file in this directory with `name:` and `description:` becomes a
  spawnable subagent, which this must not be.

  This is the contract every agent working on the currency module is pointed at. Read
  it before writing code; cite it when refusing scope.
-->

# Task contract — currency module

The brief, reduced to what must be true, and what must not be built. `docs/REQUIREMENTS.md` is the
numbered form with acceptance commands; this is the short version that settles arguments.

**When these two disagree, the brief wins, and `docs/REQUIREMENTS.md` is corrected.** Neither
document may be edited to match code that drifted.

## Invariants

Each one is falsifiable. The requirement number in `docs/REQUIREMENTS.md` carries the command that
falsifies it.

1. **The module is a WordPress plugin at `web/app/plugins/currency-converter/`.** Not a theme
   file, not an mu-plugin, not anything under `web/wp` or `vendor/` — both are Composer output and
   `make reset` deletes them. (R1)

2. **The list of currencies is predefined and hardcoded**, in `Currencies::CODES`, overridable
   through the `currency_converter_currencies` filter. The brief permits hardcoding explicitly;
   this is the discretion it grants, already exercised. (R2)

3. **Rates come from `https://api.freecurrencyapi.com/v1/latest`, in one request, with no
   `currencies` filter** — that is what "all available currencies" means on this plan. (R3)

4. **Rates are stored in a database table**, `{$wpdb->prefix}currency_rates`, created with
   `dbDelta()`, `DECIMAL(20,10)`, unique on `(base_code, target_code)`. Not a transient, not an
   option, not a JSON file. (R4)

5. **The sync runs once a day and is bounded at once a day.** `wp_schedule_event()` on the `daily`
   recurrence under the hook `currency_converter_update_rates`, plus a freshness window that skips
   a second run inside 24 hours unless forced. (R5)

6. **A converter service exists with the brief's own signature**:
   `$converter->convert( 123, 'USD', 'RUB' )`, returning a float. Not a static helper, not a
   function, not a filter. (R6)

7. **An admin page displays the saved rates**, `manage_options`, reachable at
   `admin.php?page=currency-rates`, with a total count that matches `SELECT COUNT(*)`. (R7)

8. **No client library for this API.** `everapihq/freecurrencyapi-php` is forbidden by name, and so
   is any equivalent. Before delivery,
   `grep -rn everapihq composer.json composer.lock vendor/ web/app/plugins/` returns nothing. The
   unscoped `grep -rn everapihq .` will always hit this file and the two documents that ban the
   package — read those hits, do not treat them as failures. (R8)

9. **HTTP goes through `wp_remote_get()`, in exactly one file** — `src/Api/Client.php` — so the
   rest of the module unit-tests without a network. (R9)

10. **The domain layer stays framework-free.** `Converter` and `Currencies` call no WordPress
    function. WordPress lives at the edges: `Api\`, `Repository\`, `Admin\`, `Plugin`. (R10, C3)

11. **The work is done with AI and the evidence ships with it.** Exported sessions in
    `docs/transcripts/`, admin screenshots in `docs/screenshots/`, both linked from `README.md`.
    The brief asks for these in the same breath as the code — an implementation delivered without
    them is an incomplete delivery, not a complete one missing extras. Export through the
    `session-transcript-export` skill, never by copying scrollback: transcripts of this project
    quote `.env`, which holds the database password and eight salts. (R11, R12)

## Non-goals

Not "later" — **not at all**. Building any of these is scope the brief did not ask for, and it
costs review time on code nobody requested.

- **No REST endpoint.** No `register_rest_route`, no `/wp-json/currency/*`. The brief asks for a
  PHP service and an admin page. An HTTP API is a different deliverable with its own auth,
  permission-callback and versioning questions.
- **No rate-history table.** One row per currency pair, overwritten daily. No `rate_date` column,
  no append-only log, no `/v1/historical`. "Rates should be updated once a day" describes a
  refresh, not an archive.
- **No admin CRUD for the currency list.** Invariant 2 settled this: the hardcoded constant is the
  single source of truth. A second source in the database would drift from it, and
  `DISALLOW_FILE_MODS` reflects the same principle everywhere but development.
- **No shortcode, block, widget, or front-end display.** The brief names one page, in the admin.
- **No multi-currency pricing, cart, or checkout integration.** The module converts numbers. What
  calls it is not this task.
- **No caching layer beyond the table.** The table *is* the cache — that is what "stored in the
  database" means. No object-cache wrapper, no transient in front of a query that returns 33 rows.
- **No settings beyond the API key.** No rounding preferences, no display formats, no per-currency
  toggles.
- **No historical/paid-plan features written speculatively.** `base_code` is a real column so a
  paid plan is not a migration; that is the whole allowance for the future.
- **No dependency added to `composer.json`.** Not Guzzle, not a decimal library. `wp_remote_get()`
  and `bcmath` are already present.

## Two rules that override "make it work"

**Report failures, do not paper over them.** A sync that cannot reach the API logs and stops. It
does not fall back to stale rates presented as current, to a 1:1 rate, or to zero. A converter with
no rate for a pair throws; it never returns the input unchanged.

**A check in one container proves nothing about another.** `intl` is in `wpcli` and not in `app`,
so `NumberFormatter` passes every `bin/wp` test and fatals on the first admin page load. Verify
anything runtime-dependent in both. (`CLAUDE.md` names `bcmath` as the CLI-only extension — that
is stale; `bcmath` is now in both images. The trap is real, its example moved.)

## Before saying it is done

```bash
grep -rn everapihq composer.json composer.lock vendor/ web/app/plugins/   # nothing
make lint                     # green, and the plugin is in phpcs.xml
make test                     # green
bin/wp currency convert 123 USD RUB
ls docs/screenshots/ docs/transcripts/     # non-empty; R11 and R12 are deliverables
```

Then walk `docs/REQUIREMENTS.md` and run the acceptance command for each of the twelve. A
requirement whose command was not run is reported as not run — never as passed.
