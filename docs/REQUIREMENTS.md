# Requirements

The task brief broken into numbered requirements. Each one carries the brief's own wording, a
command that decides whether it is met, and the place in the repository that is meant to meet it.

## How to read this

- **Verbatim** is copied from the brief unchanged, including its typos (`file_get_content`,
  `shouldn't` with a curly apostrophe). Where one sentence of the brief states two separable
  obligations it is split across two requirements, each quoting its own fragment.
- **Acceptance** is a command and the output that counts as a pass. "Works" is not a criterion;
  if the expected output is not printed, the requirement is not met.
- **Where** is a path in this repository. **As of commit `6e7ff99` none of the module code exists**
  — `web/app/plugins/` holds a `.gitkeep` and nothing else — so every path under
  `web/app/plugins/currency-converter/` is the agreed target location, and every acceptance
  command below is expected to fail today. R10 is the exception: it is satisfied already.
- Status is one of **Planned** (nothing written yet) or **Satisfied** (holds at the named path now).

Commands assume the stack is up (`make bootstrap`, then `make up`) and are run from the project
root. Three project traps shape the commands used here, all three documented in `CLAUDE.md`:
constants are invisible to `wp config get`, so configuration is read with `bin/wp eval`; the `app`
and `wpcli` images carry different PHP extension sets, so anything extension-dependent is checked
in both; and Bedrock's admin lives at `/wp/wp-admin`, not `/wp-admin`.

### Naming fixed by this document

| Thing | Value |
| --- | --- |
| Plugin directory | `web/app/plugins/currency-converter/` |
| Plugin slug | `currency-converter` |
| PHP namespace | `Drozd\Currency\` |
| Database table | `{$wpdb->prefix}currency_rates` (`wp_currency_rates` with the default `DB_PREFIX`) |
| Cron hook | `currency_converter_update_rates` |
| Admin page slug | `currency-rates` |
| WP-CLI namespace | `wp currency` |
| API key source | `FREECURRENCYAPI_KEY` in `.env`, defined as a constant in `config/application.php` |

---

## 1. A module for storing and converting currencies

> **Verbatim:** "Create a module for storing and converting currencies."

**Status:** Planned

**Acceptance**

```bash
bin/wp plugin list --name=currency-converter --field=status
# expected: active

make lint
# expected: exits 0, no error or warning lines for web/app/plugins/currency-converter
```

The plugin must activate on a clean install and pass the project's own coding standard, which
means adding it to the `<file>` list in `phpcs.xml` — lint covers our code only, and this is our
code.

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/currency-converter.php` | Plugin header, autoloader, activation/deactivation hooks |
| `web/app/plugins/currency-converter/src/Plugin.php` | Wires services to WordPress hooks |
| `web/app/plugins/currency-converter/uninstall.php` | Drops the table and options |
| `phpcs.xml` | `<file>web/app/plugins/currency-converter</file>` |

---

## 2. Predefined list of currencies

> **Verbatim:** "The module must have a predefined list of currencies (at the discretion of the
> developer - hardcoded in the module or added via the admin panel)."

**Status:** Planned

The developer's discretion is exercised as: **hardcoded**, in a class constant, overridable through
a filter. See collision **C4**.

**Acceptance**

```bash
bin/wp currency currencies --format=count
# expected: 33

bin/wp currency currencies --format=csv | head -3
# expected:
# code,name
# AUD,Australian Dollar
# BGN,Bulgarian Lev

bin/wp eval 'echo in_array( "USD", Drozd\Currency\Currencies::codes(), true ) && in_array( "RUB", Drozd\Currency\Currencies::codes(), true ) ? "ok" : "missing";'
# expected: ok
```

A unit test asserts the list is non-empty, has no duplicates, and every entry is three uppercase
letters:

```bash
make test ARGS="--filter=CurrenciesTest"
# expected: OK (3 tests)
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Currencies.php` | `const CODES` plus `codes()` / `all()`, filtered through `currency_converter_currencies` |
| `web/app/plugins/currency-converter/src/Cli/CurrencyCommand.php` | `wp currency currencies` |
| `tests/Unit/CurrenciesTest.php` | Shape of the list |

---

## 3. Rates downloaded from freecurrencyapi.com for all available currencies

> **Verbatim:** "Exchange rates should be downloaded from https://freecurrencyapi.com/ (API
> documentation at https://freecurrencyapi.com/docs) for all available currencies"

**Status:** Planned

**Acceptance**

```bash
bin/wp currency update --dry-run
# expected, exactly these two lines (the count may differ if the API adds currencies):
# GET https://api.freecurrencyapi.com/v1/latest
# 33 rates returned, base USD
```

The request must carry `apikey` in the **header**, and must send neither a `currencies` nor a
`base_currency` query parameter — the endpoint returns every available currency when `currencies`
is omitted, which is what "all available currencies" asks for. Verified without a network call by:

```bash
make test ARGS="--filter=ClientTest"
# expected: OK (5 tests) — asserts the request URL is exactly
# https://api.freecurrencyapi.com/v1/latest with an empty query string,
# and that the apikey travels in the request header, not the URL
```

A missing or rejected key must fail loudly rather than silently storing nothing:

```bash
bin/wp eval 'define( "FREECURRENCYAPI_KEY", "nope" ); ' ; bin/wp currency update --force
# expected: exits non-zero, prints: Error: freecurrencyapi returned HTTP 401
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Api/Client.php` | Builds and sends the request, decodes JSON, maps HTTP status to exceptions |
| `web/app/plugins/currency-converter/src/Api/ApiException.php` | Transport, quota (429) and auth (401/403) failures |
| `web/app/plugins/currency-converter/src/RateUpdater.php` | Orchestrates fetch → filter → store |
| `tests/Unit/ClientTest.php` | Request shape, with the HTTP layer faked |

---

## 4. Rates stored in the database

> **Verbatim:** "and stored in the database"

**Status:** Planned

**Acceptance**

```bash
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "DESCRIBE wp_currency_rates"'
# expected columns, in order:
# id            bigint(20) unsigned  PRI  auto_increment
# base_code     char(3)
# target_code   char(3)
# rate          decimal(20,10)
# fetched_at    datetime
# UNIQUE KEY base_target (base_code, target_code)

bin/wp currency update --force
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM wp_currency_rates"'
# expected: 33
```

Storage is idempotent — a second update replaces rows rather than appending, enforced by the
unique key:

```bash
bin/wp currency update --force && docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM wp_currency_rates"'
# expected: 33 (unchanged)
```

Deactivation must keep the data and uninstall must remove it:

```bash
bin/wp plugin uninstall currency-converter --deactivate
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SHOW TABLES LIKE \"wp_currency_rates\""'
# expected: no output
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Repository/RateRepository.php` | `dbDelta()` schema, `upsert()`, `all()`, `find()` |
| `web/app/plugins/currency-converter/src/Plugin.php` | `register_activation_hook` → schema install |
| `web/app/plugins/currency-converter/uninstall.php` | `DROP TABLE`, delete options |

`dbDelta()` is why the stack runs MariaDB rather than MySQL 8 — see the comment in
`docker-compose.yml`; `bigint(20)` in the DDL has to match what `DESCRIBE` reports or every
activation emits a pointless `ALTER`.

---

## 5. Rates updated once a day

> **Verbatim:** "Rates should be updated once a day."

**Status:** Planned

**Acceptance**

```bash
bin/wp cron event list --fields=hook,schedule,recurrence --format=csv | grep currency
# expected: currency_converter_update_rates,daily,"1 day"
```

Once a day means **at most** once a day as well: a second run inside the window is refused unless
forced, so a busy site cannot burn the monthly quota.

```bash
bin/wp currency update --force   # expected: 33 rates updated
bin/wp currency update           # expected: Skipped: rates are fresh (last sync 2026-08-24 09:00:00 UTC)
```

The event fires without anyone visiting the site, because `DISABLE_WP_CRON` is true and the `cron`
container is the real scheduler:

```bash
bin/wp cron event run currency_converter_update_rates
make logs S=cron        # expected: a [cron] line naming the hook within CRON_INTERVAL seconds

bin/wp eval 'echo get_option( "currency_converter_last_sync" );'
# expected: an ISO-8601 UTC timestamp within the last minute
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Cron/DailySync.php` | Schedules and unschedules `currency_converter_update_rates` |
| `web/app/plugins/currency-converter/src/RateUpdater.php` | Freshness window, `currency_converter_last_sync` option |
| `bin/cron-loop.sh`, `docker-compose.yml` (`cron` service) | Existing scheduler that makes the event fire |

---

## 6. A conversion service

> **Verbatim:** "The module should provide a service for converting prices from one currency to
> another (using something like this $converter->convert(123, 'USD', 'RUB');)."

**Status:** Planned

The signature is taken literally: `convert( float $amount, string $from, string $to ): float`.

**Acceptance**

```bash
bin/wp eval 'printf( "%.2f", ( new Drozd\Currency\Converter( new Drozd\Currency\Repository\RateRepository() ) )->convert( 123, "USD", "RUB" ) );'
# expected: a number greater than 0, e.g. 11439.87

bin/wp currency convert 123 USD RUB
# expected: 123.00 USD = 11439.87 RUB (rate 93.0071, as of 2026-08-24)
```

Exact arithmetic is pinned by unit tests against fixed rates rather than live ones — with
USD→RUB at 90.0 and USD→EUR at 0.5:

```bash
make test ARGS="--filter=ConverterTest"
# expected: OK (7 tests), covering
#   convert(123, 'USD', 'RUB')  === 11070.0    (from base)
#   convert(123, 'RUB', 'USD')  === 1.3666...  (to base, inverse)
#   convert(123, 'EUR', 'RUB')  === 22140.0    (cross rate through USD)
#   convert(123, 'USD', 'USD')  === 123.0      (identity, no lookup)
#   convert(123, 'USD', 'XXX')  throws UnknownCurrencyException
#   convert(123, 'USD', 'RUB')  throws MissingRateException when the table is empty
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Converter.php` | `convert()`, cross-rate through the stored base |
| `web/app/plugins/currency-converter/src/Exception/` | `UnknownCurrencyException`, `MissingRateException` |
| `web/app/plugins/currency-converter/src/Cli/CurrencyCommand.php` | `wp currency convert` |
| `tests/Unit/ConverterTest.php` | The arithmetic above |

---

## 7. An admin page displaying all saved exchange rates

> **Verbatim:** "Also, a page in the admin panel should be created, where all saved exchange rates
> should be displayed."

**Status:** Planned

**Acceptance**

```bash
bin/wp eval 'echo menu_page_url( "currency-rates", false );'
# expected: http://localhost:8080/wp/wp-admin/admin.php?page=currency-rates
```

The page is capability-gated and the count it reports must equal the count in the table — that is
what makes "all saved rates" checkable in the presence of paging (see collision **C2**):

```bash
bin/wp eval 'wp_set_current_user( 0 ); echo current_user_can( "manage_options" ) ? "open" : "gated";'
# expected: gated

make screenshots
# expected: docs/screenshots/currency-rates.png exists and shows
# "33 items" in the list-table header, matching SELECT COUNT(*) from R4
```

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Admin/RatesPage.php` | `add_menu_page`, `manage_options`, renders the table |
| `web/app/plugins/currency-converter/src/Admin/RatesListTable.php` | `WP_List_Table`: columns, sorting, per-page, total count |
| `web/app/plugins/currency-converter/src/Admin/SettingsPage.php` | API key status, last sync, "Update now" (nonce-protected) |
| `.claude/skills/browser-capture/scripts/capture.mjs` | Existing capture script, extended with the new page |

---

## 8. No freecurrencyapi client library

> **Verbatim:** "Libraries implementing integration with https://freecurrencyapi.com/ (e.g.
> https://github.com/everapihq/freecurrencyapi-php) shouldn’t be used."

**Status:** Planned

**Acceptance**

```bash
grep -riE 'everapi|freecurrencyapi-php' composer.json composer.lock
# expected: no output, exit status 1

bin/composer show | grep -iE 'currency|everapi'
# expected: no output, exit status 1
```

`composer.lock` is in the repository, so this check is meaningful for a reviewer who has not run
`composer install`.

**Where**

| Path | Role |
| --- | --- |
| `composer.json`, `composer.lock` | Absence of the dependency is the evidence |
| `web/app/plugins/currency-converter/src/Api/Client.php` | The hand-written client that stands in for it |

---

## 9. Integration written against an HTTP tool

> **Verbatim:** "Integration should be implemented with Guzzle, curl, file_get_content or any other
> tool aimed to make http requests or network requests."

**Status:** Planned

The chosen tool is **`wp_remote_get()`**, WordPress's HTTP API — an "other tool aimed to make http
requests", and one that already carries timeouts, retries and a cURL transport, so nothing new is
added to `composer.json`.

**Acceptance**

```bash
grep -c 'wp_remote_get' web/app/plugins/currency-converter/src/Api/Client.php
# expected: 1

grep -rln 'wp_remote_get\|curl_init\|file_get_contents\|GuzzleHttp' web/app/plugins/currency-converter/
# expected: exactly one path — web/app/plugins/currency-converter/src/Api/Client.php
```

The second command is the real requirement: HTTP happens in one file, so the rest of the module is
unit-testable without a network. `tests/Unit/ClientTest.php` fakes the transport through the
`pre_http_request` filter and never opens a socket.

**Where**

| Path | Role |
| --- | --- |
| `web/app/plugins/currency-converter/src/Api/Client.php` | The only file allowed to make a request |
| `tests/Unit/ClientTest.php` | Faked transport, asserts no real request is attempted |

---

## 10. PHP framework

> **Verbatim:** "Any suitable PHP-framework can be used to implement the currency converter module."

**Status:** Satisfied

The chosen platform is **WordPress 7.x**, laid out as a Bedrock project. See collision **C3** for
why a CMS is a defensible reading of "any suitable PHP-framework".

**Acceptance**

```bash
bin/wp core version
# expected: 7.1.x

bin/wp eval 'echo PHP_VERSION;'      # expected: 8.3.x
docker compose exec -T app php -v    # expected: PHP 8.3.x
```

**Where**

| Path | Role |
| --- | --- |
| `composer.json` | `roots/wordpress: ^7.1`, `php: >=8.1`, platform pinned to 8.3.0 |
| `config/application.php`, `config/environments/` | Bedrock configuration |
| `Dockerfile`, `docker-compose.yml` | `php:8.3-apache`, MariaDB, WP-CLI, cron |

---

## 11. Implemented using AI

> **Verbatim:** "The task should be implemented using AI."

**Status:** Planned — `docs/transcripts/` exists and is empty

**Acceptance**

```bash
ls docs/transcripts/*.md
# expected: at least one file

grep -rniE 'AUTH_KEY|SALT|DB_PASSWORD|apikey|fca_live_' docs/transcripts/
# expected: no output, exit status 1
```

The second command is not optional: transcripts of this project quote `.env`, and `.env` holds the
database password and eight generated salts. Export through the `session-transcript-export` skill,
which redacts them, rather than by copying scrollback.

**Where**

| Path | Role |
| --- | --- |
| `docs/transcripts/` | Exported sessions, one Markdown file per session |
| `.claude/skills/session-transcript-export/` | The redacting exporter |
| `CLAUDE.md`, `.claude/skills/` | The project's own AI tooling, itself evidence |

---

## 12. Screenshots or chat dumps delivered with the task

> **Verbatim:** "Screenshots or dumps of chats from AI-agent or AI-chat should be sent together
> with the implemented task."

**Status:** Planned — `docs/screenshots/` exists and is empty

**Acceptance**

```bash
ls docs/screenshots/*.png
# expected: at least currency-rates.png and currency-settings.png

grep -c 'docs/screenshots' README.md
# expected: 1 or more — the deliverable is linked from the entry point, not just present on disk
```

**Where**

| Path | Role |
| --- | --- |
| `docs/screenshots/` | Admin captures |
| `Makefile` (`make screenshots`) | Reproducible capture |
| `README.md` | Links the screenshots and the transcripts |

---

# Collisions and how they are resolved

Places where the brief, read literally, cannot be satisfied as written — because of the API plan,
the platform, or the brief contradicting itself. Each one names what gives way.

## C1. "for all available currencies" against the free plan rejecting a custom `base_currency`

**Requirement:** R3 — rates "for all available currencies".

**Constraint:** `/v1/latest` documents `base_currency` as optional, defaulting to USD, and
`currencies` as optional, defaulting to every available currency. The documentation states neither
a per-plan restriction on `base_currency` nor the error it returns; in practice the free plan
serves USD as base and refuses any other with an HTTP 4xx subscription error. Taken literally, "all
available currencies" would mean an N×N matrix — 33 bases × 33 targets — which needs 33 requests a
day against a 5,000-request monthly quota and a plan that will not sell 32 of them.

**Resolution:** Store one row per currency against the single base the plan grants — 33 rows of
`USD → X` — and derive every other pair arithmetically at conversion time: `A → B` is
`rate(B) / rate(A)`, with the identity case short-circuited. The stored set is complete in the sense
the plan allows: every available currency is present, exactly once, and any ordered pair is
answerable. `base_code` is a real column rather than an assumption, so a paid plan later adds rows
instead of forcing a migration. One request per day, 30-ish a month, inside the free quota with room
to spare.

**Verification**

```bash
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT DISTINCT base_code FROM wp_currency_rates"'
# expected: USD — one row, confirming the single-base design is what is stored

bin/wp eval 'echo ( new Drozd\Currency\Api\Client() )->probe_base( "EUR" );'
# expected: records the live response for a non-USD base, e.g.
# HTTP 403 {"message":"subscription plan does not support this feature"}
# This command exists to document the plan's actual behaviour, since the docs do not.
```

The cross-rate path is what makes the derivation trustworthy, and it is tested directly:
`convert(123, 'EUR', 'RUB') === 22140.0` in `tests/Unit/ConverterTest.php` (R6).

## C2. "all saved exchange rates" against pagination

**Requirement:** R7 — the admin page displays "all saved exchange rates".

**Constraint:** "All" and "one screen" are the same demand only while the table is small. It is 33
rows today, 33 × 33 = 1,089 the moment a paid plan adds bases, and unbounded if historical rows are
ever kept. An unpaginated `WP_List_Table` loads every row into memory and renders it, which is the
standard way admin screens fall over on real data.

**Resolution:** "All" is read as *reachable and accounted for*, not *simultaneously on screen*.
`WP_List_Table` with a default of 50 per page — above today's 33, so the literal reading also holds
at present scale — a screen-options control for per-page, sortable columns, and a header that
prints the **total** count from `SELECT COUNT(*)`, not the count of the current page. The total is
what proves nothing is hidden: it is checkable against the database independently of paging. A CSV
export (`wp currency list --format=csv`) gives the genuinely complete dump for anyone who needs one
file.

**Verification**

```bash
bin/wp currency list --format=count
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM wp_currency_rates"'
# expected: the two numbers are equal, and both equal the "N items" in docs/screenshots/currency-rates.png
```

## C3. "any suitable PHP-framework" against WordPress being a CMS

**Requirement:** R10 — "Any suitable PHP-framework can be used".

**Constraint:** WordPress is a CMS, not a framework in the Laravel/Symfony sense. It offers no DI
container, no ORM, no router usable from a module, no first-class service layer; it supplies global
functions, hooks, `$wpdb`, and a plugin lifecycle. A reviewer expecting `App\Services\Converter`
wired by a container will not find one. The brief's "suitable" is doing the work here.

**Resolution:** WordPress is chosen deliberately, and the mismatch is absorbed rather than ignored.
The module is a plugin, PSR-4 autoloaded under `Drozd\Currency\`, with constructor injection by hand
— `new Converter( new RateRepository() )` — so the domain classes stay framework-shaped even though
the platform is not. WordPress is confined to the edges: `wp_remote_get` in `Api\Client`, `$wpdb` in
`Repository\RateRepository`, hooks in `Plugin`, `WP_List_Table` in `Admin\`. `Converter` itself
touches no WordPress function, which is why it can be unit-tested with Brain\Monkey and no database.
Bedrock supplies what plain WordPress lacks — env-based configuration, Composer-owned
dependencies, a real `web/` document root — and closes most of the distance to a framework layout.

**Verification**

```bash
grep -rlE 'wp_|WP_|\$wpdb|add_(action|filter)' web/app/plugins/currency-converter/src/Converter.php web/app/plugins/currency-converter/src/Currencies.php
# expected: no output, exit status 1 — the domain layer is framework-free

make test
# expected: OK — the suite runs with no WordPress bootstrap, per tests/bootstrap.php
```

## C4. "a predefined list of currencies" against "all available currencies"

**Requirement:** R2 predefines a list; R3 wants every currency the API offers.

**Constraint:** The two sentences pull in opposite directions — a fixed list is by definition not
whatever the API happens to serve. The brief resolves its own tension only halfway, by permitting
the list to be "hardcoded in the module or added via the admin panel" at the developer's
discretion.

**Resolution:** Take the permission. The list is hardcoded in `Currencies::CODES`, seeded with the
33 codes the API advertises, so the predefined list and the available set start out identical and
the contradiction is empty at install time. Divergence later is handled explicitly rather than
silently: the updater stores the intersection, and logs both directions of drift — a code the API
returned that is not in the list (new currency upstream), and a code in the list the API did not
return (withdrawn upstream). A `currency_converter_currencies` filter lets a site narrow the list to
the handful of currencies it actually prices in without touching the module. No admin CRUD screen
for the list: it would be a second source of truth for something Composer-deployed code already
owns, and `DISALLOW_FILE_MODS` reflects the same principle everywhere but development.

**Verification**

```bash
bin/wp currency update --force
# expected on a normal day: 33 rates updated
# expected on drift: 32 rates updated; 1 unknown code from API: XYZ; 1 predefined code missing from API: ZWL

bin/wp eval 'add_filter( "currency_converter_currencies", fn() => ["USD","EUR"] ); echo count( Drozd\Currency\Currencies::codes() );'
# expected: 2
```

## C5. `convert(123, 'USD', 'RUB')` against RUB's availability

**Requirement:** R6 — the brief's own example converts to RUB.

**Constraint:** The API advertises 33 supported currencies and the documentation page does not
enumerate them, so RUB's presence is not guaranteed by the docs. If RUB is absent, the brief's
literal example cannot return a number from live data.

**Resolution:** The hardcoded list is seeded from a live `/v1/currencies` response rather than from
assumption, so the module can only claim currencies the API actually serves. `wp currency convert`
with an unavailable code fails with a named exception and a message listing the supported codes,
never a silent zero or a 1:1 rate. The unit tests use fixed rates, so R6's arithmetic stays
verifiable regardless of what the upstream list contains, and the live example in R6 is checked
against the stored set at review time.

**Verification**

```bash
bin/wp currency currencies --format=csv | grep -c '^RUB,'
# expected: 1 — if this returns 0, R6's live example is replaced with a supported pair
# and the substitution noted here; the unit tests are unaffected either way

bin/wp currency convert 123 USD XYZ
# expected: exits non-zero — Error: unknown currency XYZ. Supported: AUD, BGN, BRL, ...
```

## C6. Money arithmetic and formatting against the extension sets of two different images

**Requirement:** R6 — money arithmetic; R4's stored precision; R7 — rates rendered in the admin.

**Constraint:** `CLAUDE.md` trap 2: `app` (`php:8.3-apache`, built from our `Dockerfile`) and
`wpcli`/`cron` (the official Alpine WP-CLI image) do not carry the same extension set, and a check
that only ran through `bin/wp` says nothing about what a browser request can do. The current split,
verified rather than assumed:

| Extension | `app` | `wpcli` / `cron` | Consequence |
| --- | --- | --- | --- |
| `bcmath` | yes (`Dockerfile`) | yes | safe in both — exact decimal arithmetic is available |
| `intl` | **no** | yes | `NumberFormatter` fatals in the browser, passes every `bin/wp` check |

`intl` is the live hazard for this module, and a currency module is exactly the code that reaches
for it: `NumberFormatter::CURRENCY` is the obvious way to render a rate in the admin table (R7),
it would work in every WP-CLI test, and it would fatal on the first admin page load. Its absence
from `app` is deliberate — nothing depends on it and it drags in libicu.

Note that `CLAUDE.md` still names `bcmath` as the CLI-only extension. That was true when it was
written and is now stale: `bcmath` was added to the `Dockerfile` and is in both. The trap is real;
its example has moved.

**Resolution:** No new extension, and no `intl`. Rates are stored as `DECIMAL(20,10)` so the
database keeps full precision; `Converter::convert()` returns a float, as the brief's signature
requires, and rounding happens once at the presentation edge via `round()` at the currency's
minor-unit scale — accurate far beyond the four to six significant digits an FX rate carries.
Formatting in the admin uses `number_format_i18n()`, which is WordPress core and present wherever
WordPress is. `bcmath` is available in both images should exact decimal arithmetic ever be needed,
so that door is open without a `Dockerfile` change; `intl` is not, and adding it would mean a
`Dockerfile` edit plus `make build` — never a runtime install — followed by comparing `php -m`
across both containers again.

**Verification**

```bash
docker compose exec -T app php -m | sort > /tmp/app-mods
docker compose run --rm -T wpcli php -m | sort > /tmp/cli-mods
diff /tmp/app-mods /tmp/cli-mods
# expected today: intl present only in the wpcli column
# the requirement: the module depends on nothing that appears in only one list

grep -rn 'NumberFormatter\|IntlDateFormatter\|collator_\|numfmt_' web/app/plugins/currency-converter/
# expected: no output, exit status 1

docker compose exec -T app php -r 'require "vendor/autoload.php";' && bin/wp eval 'echo "cli ok";'
# expected: both succeed — the module loads in both runtimes
```

## C7. "updated once a day" against WP-Cron being disabled

**Requirement:** R5 — a daily update.

**Constraint:** `config/application.php` sets `DISABLE_WP_CRON` to true, so `wp-cron.php` never
fires on a page load. A plain `wp_schedule_event()` would register an event that, on a site nobody
visits, runs never. And per trap 1, `wp config get DISABLE_WP_CRON` reports nothing — `Config::apply()`
defines constants at runtime, so a static parse of `wp-config.php` finds no definition and a
reviewer can conclude the opposite of the truth.

**Resolution:** The `cron` container in `docker-compose.yml` is the scheduler; `bin/cron-loop.sh`
runs `wp cron event run --due-now` every `CRON_INTERVAL` seconds. `wp_schedule_event()` is still the
right registration — it is the container that executes it. The freshness window in `RateUpdater`
makes the daily bound hold independently of scheduling, so a manual run, a second container, or a
misconfigured interval cannot multiply the API calls. Every configuration check in this document
uses `bin/wp eval`, never `wp config get`.

**Verification**

```bash
bin/wp eval 'var_dump( DISABLE_WP_CRON );'
# expected: bool(true) — and note that `bin/wp config get DISABLE_WP_CRON` returns nothing,
# which is the trap, not a contradiction

docker compose ps cron
# expected: running

bin/wp eval 'echo wp_next_scheduled( "currency_converter_update_rates" ) ? "scheduled" : "NOT SCHEDULED";'
# expected: scheduled
```

## C8. The API key against "never commit .env" and against `wp config get`

**Requirement:** R3 — authenticated requests.

**Constraint:** The key is a secret, so it cannot live in `composer.json`, in the plugin, or in the
repository at all. Storing it in `wp_options` instead puts it in every database dump and makes it a
per-environment surprise. And a key defined through `Config::define()` is invisible to
`wp config get`, so "the key is missing" and "the key is set but unreadable by that tool" look
identical.

**Resolution:** `FREECURRENCYAPI_KEY` in `.env`, defined as a constant in `config/application.php`
alongside the rest of the configuration, with the empty shape shipped in `.env.example` exactly as
the salts are. The settings page reads the constant and displays only presence and last four
characters, never the value. The module fails with a clear message when the constant is absent
rather than making an unauthenticated request.

**Verification**

```bash
grep -c '^FREECURRENCYAPI_KEY=' .env.example
# expected: 1, with an empty value

bin/wp eval 'echo defined( "FREECURRENCYAPI_KEY" ) && FREECURRENCYAPI_KEY !== "" ? "set" : "MISSING";'
# expected: set

git check-ignore -q .env && echo ignored
# expected: ignored
```

---

# Summary

| # | Requirement | Status | Primary location |
| --- | --- | --- | --- |
| 1 | Module for storing and converting currencies | Planned | `web/app/plugins/currency-converter/` |
| 2 | Predefined list of currencies | Planned | `src/Currencies.php` |
| 3 | Rates downloaded for all available currencies | Planned | `src/Api/Client.php` |
| 4 | Rates stored in the database | Planned | `src/Repository/RateRepository.php` |
| 5 | Updated once a day | Planned | `src/Cron/DailySync.php` + `cron` container |
| 6 | Conversion service | Planned | `src/Converter.php` |
| 7 | Admin page with all saved rates | Planned | `src/Admin/RatesListTable.php` |
| 8 | No freecurrencyapi client library | Planned | `composer.json` / `composer.lock` |
| 9 | Integration via an HTTP tool | Planned | `src/Api/Client.php` |
| 10 | PHP framework | Satisfied | `composer.json`, `config/` |
| 11 | Implemented using AI | Planned | `docs/transcripts/` |
| 12 | Screenshots or chat dumps delivered | Planned | `docs/screenshots/` |

| # | Collision | Gives way |
| --- | --- | --- |
| C1 | All currencies vs. free-plan base restriction | The N×N matrix; one base plus cross-rates |
| C2 | "All saved rates" vs. pagination | "All on one screen"; total count and CSV export instead |
| C3 | "Any PHP-framework" vs. WordPress being a CMS | Framework idiom; domain layer kept framework-free |
| C4 | Predefined list vs. all available currencies | Nothing — the brief permits hardcoding; drift is logged |
| C5 | The RUB example vs. the supported set | The live example, if RUB is absent; tests are unaffected |
| C6 | Money formatting vs. differing extension sets | `intl`/`NumberFormatter`; `number_format_i18n()` instead |
| C7 | Daily schedule vs. WP-Cron disabled | Page-load cron; the `cron` container executes the event |
| C8 | API key vs. never committing `.env` | Committed configuration; `.env` plus a constant |
