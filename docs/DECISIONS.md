# Decisions

Choices that are not obvious from the code, with the reason that made them. Each entry says what
was decided, why, what it rules out, and how to tell if it has been broken.

A decision here outranks convenience. If one of these turns out to be wrong, change the entry and
say why — do not leave the code and the record disagreeing.

---

## D1 — The API key travels in the `apikey` HTTP header, never in the query string

**Decided:** 2026-08-24, before the client was written.
**Status:** Accepted. Enforced by `Api\FreeCurrencyApiClient`.

freecurrencyapi accepts the key either as an `apikey` query parameter or as an `apikey` request
header. The module uses the header, always.

### Why

A query string is not private. It is copied, verbatim and in full, into places nobody audits:

- **Web server access logs.** Apache and nginx log the full request line by default, so
  `?apikey=fca_live_…` lands in `access.log` on every host the request passes through, and then in
  whatever ships those logs onward.
- **Proxy and CDN caches.** The query string is part of the cache key, so intermediaries retain it
  by design.
- **Query Monitor.** The HTTP panel lists outbound requests with their full URLs. Any administrator
  who opens it on a page that triggered a sync reads the live key off the screen.
- **The transcripts this project ships.** `.claude/agents/_TASK_CONTRACT.md` R11 requires exported
  AI sessions in `docs/transcripts/` as a deliverable. Those transcripts quote commands, responses
  and debugging output. A key in a URL is a key in the submitted deliverable — a repository, an
  archive, and a reviewer's inbox.

The last one is the reason this is written down rather than assumed. The other three are ordinary
operational hygiene and would justify the header on their own; the fourth means a mistake here is
not merely logged somewhere unfortunate but actively published, and cannot be walked back by
rotating a key after the fact — the transcript keeps the old one.

A header avoids all four. It is not logged by default, is not part of a cache key, is not rendered
by Query Monitor's URL column, and does not appear in the shell commands a transcript records.

### What this rules out

- No `add_query_arg( 'apikey', … )`, anywhere.
- No debugging convenience that pastes a full URL with the key into a log line, a notice, an
  exception message or a comment.
- `probe-api.sh` sends the key with `curl -H "apikey: …"` for the same reason, and prints only the
  last four characters of it.

### How to tell if it has been broken

```bash
# The key must never appear in a URL or a query-building call.
grep -rn "apikey=" web/app/plugins/currency-converter/ .claude/skills/freecurrencyapi/scripts/
# expected: nothing

# Real key material must not be in any tracked file. Placeholders (fca_live_xxx, fca_live_…)
# are documentation and are expected; a match of 16+ real characters is not.
git grep -nE 'fca_live_[A-Za-z0-9]{16,}' | grep -viE 'x{16,}'
# expected: nothing
```

The bare `git grep fca_live_` is deliberately *not* the check: five tracked skill and documentation
files name the prefix in order to placeholder or forbid it, so a gate written that way can never
come back empty and gets ignored within a week.

### Related

- The key is defined as a constant in `config/application.php` from `.env`, which is gitignored.
  `wp config get` cannot see it — `Roots\WPConfig\Config::apply()` defines constants at runtime —
  so check it with `bin/wp eval`.
- Options holding secrets use `autoload='no'`; the key is not stored in an option at all.

---

## D2 — Transport failures are an exception, not a response with status 0

**Decided:** 2026-08-24, with the HTTP layer.
**Status:** Accepted. Enforced by `Http\WpHttpClient` and `Api\TransportException`.

`wp_remote_get()` reports DNS failures, refused connections, TLS handshake failures and timeouts as
a `WP_Error` rather than a response. The module turns those into a thrown exception, and never into
an `HttpResponse` carrying a synthetic status of `0`.

### Why

A sentinel status is silently comparable to a real one. `$response->status() < 400` is true for
`0`, so a synthetic status turns "the request never happened" into "the request succeeded" at the
first careless comparison. An exception cannot be ignored by accident.

The split also carries real meaning: a transport failure is the **only** retryable failure against
this API. 401, 403 and 422 are permanent for the request as written, and retrying them in a loop is
how a key gets banned; 429 needs a wait rather than a retry. `ApiException::is_retryable()` returns
false for everything except `TransportException`, and `RateLimitException` overrides it to
distinguish the per-minute limit from the monthly one.

### Note on the two `TransportException` classes

There are two, deliberately. `Http\TransportException` belongs to the transport layer, which knows
nothing about this API. `Api\TransportException extends ApiException` is what
`FreeCurrencyApiClient` rethrows it as, so a caller can wrap a sync in a single
`catch ( ApiException $e )` and still be told about a DNS failure. The original is preserved as
`getPrevious()`.

---

## D3 — The observed monthly quota is recorded from response headers, not polled

**Decided:** 2026-08-24, with the client.
**Status:** Accepted. Enforced by `FreeCurrencyApiClient::record_remaining_quota()`.

`X-RateLimit-Remaining-Quota-Month` is captured into the `currency_converter_quota` option from
every response to a request that authenticated — successful syncs and error responses alike — and
the settings page reads that option.

### Why

`/v1/status` reports the same number, but a successful call to it **spends quota**, so polling the
quota endpoint to display the quota consumes the thing it measures. The header rides along on
requests the module was making anyway and costs nothing.

Capturing it on errors as well as successes matters for the case that most needs displaying: a 429
is precisely when an operator opens the settings page, and it carries the headers because it was
raised after authentication.

### Two traps this encodes

- **An absent header is not zero.** A 401 never reached an account and carries no `X-RateLimit-*`
  headers at all. `wp_remote_retrieve_header()` returns `''`, and `(int) ''` is `0` — which reads
  as an exhausted quota. Nothing is written when the header is absent, and `stored_quota()` returns
  `null` rather than a zero when no reading has ever been taken.
- **The reading needs its age.** The option stores `checked_at` alongside the number, because a
  remaining count with no timestamp could equally be from this minute or from last month.

The option is stored with `autoload='no'`: it is read on one admin screen and has no business in
every page load's `alloptions` cache.

> **Gotcha when verifying this.** WordPress 7.1 — the version this project runs — normalises both
> `'no'` and `false` to **`off`** in the `autoload` column. A check written as
> `SELECT autoload … = 'no'` fails against correctly-stored options. Assert the meaning instead:
>
> ```php
> ! array_key_exists( $option_name, wp_load_alloptions() )
> ```
>
> `CLAUDE.md` states the convention as `autoload='no'`, which is still the correct argument to pass
> — only the stored value differs.

---

## D4 — The converter rounds half-up through `bcmath`, never `round()` on a float

**Decided:** 2026-08-24, with `Service\CurrencyConverter`.
**Status:** Accepted. Enforced by `CurrencyConverter::round_half_up()` and
`tests/Service/CurrencyConverterTest.php`.

Every product, quotient and rounding decision in `convert()` is made on decimal strings through
`bcmath`, at twice the stored scale, and the result becomes a float exactly once — in the `return`
statement, because the brief's signature says the method returns one.

### Why

The module goes to some trouble to keep rates out of floats: the column is `DECIMAL(24,12)`,
`Rate::value()` is a string, `%s` binds it and never `%f`, and `WpdbRateRepository` contains no
cast. All of that is undone if the one operation the module exists to perform is a float
multiplication at the end.

`round()` is the specific trap, and it is worse than "slightly imprecise" because it is *wrong in a
way that looks right*. `round( 2.675, 2 )` is `2.67`: the literal `2.675` is really
`2.67499999999999982236431605997495353221893310546875`, so the rounding is correct and the input
was already broken. Nothing at the call site fixes that — by the time `round()` sees the value the
information needed to round it is gone. Doing the same arithmetic on decimal strings means the
half-up decision is taken on the true value.

`bcmath` truncates rather than rounds, so half-up is built the way it is defined: add half of the
last kept place, then truncate at that scale. Negative amounts round away from zero, matching
`PHP_ROUND_HALF_UP`, so a refund and the charge it reverses land the same distance from zero.

The working scale is `Rate::SCALE * 2` — 24 places. That is exactly what the product of two
`DECIMAL(24,12)` values needs, so `amount × rate` is held with no truncation at all and the
rounding decision at 12 places is taken on the exact value. Division cannot terminate in general,
and twelve guard digits put any disagreement far below the four to six significant digits an
exchange rate carries.

### What this rules out

- No `round()`, `number_format()`, `floor()` or `ceil()` anywhere in the conversion path. They may
  appear at the presentation edge, on a value that is already final — that is what C6 in
  `docs/REQUIREMENTS.md` sanctions, and it is a different job.
- No `float` type declaration on `convert()`'s `$amount` parameter. It would cast an exact decimal
  string — an amount read straight out of a `DECIMAL` column — before the method could see it,
  which is the one loss no amount of care afterwards undoes. The shape is checked in the body
  instead, and a string amount is passed to `bcmath` with every digit intact.
- No multiplying by the value `rate()` returns. `convert()` divides last, on the product; going
  through a rounded cross rate rounds twice, and the second rounding is applied to a number the
  amount has already scaled up.

### How to tell if it has been broken

```bash
# Nothing in the conversion path may round a float.
grep -nE '\b(round|floor|ceil|number_format)\s*\(' \
  web/app/plugins/currency-converter/src/Service/CurrencyConverter.php \
  | grep -v '^\s*[0-9]*:\s*\*'
# expected: nothing — the only mentions are prose in the docblocks

make test ARGS="--filter=CurrencyConverterTest"
# expected: OK. Three tests fail the moment the arithmetic goes through a float:
#   a tie rounds half up and not half even or down
#   a negative tie rounds away from zero
#   the arithmetic does not go through a float   (0.1 * 3 !== 0.3, but 0.1 USD at 3.0 is 0.3)
```

### Related

- `Rate::normalize_value()` does the same half-up rounding on the storage side, and deliberately
  *without* `bcmath` — a value object that fatals when an extension is absent is a poor trade for
  twelve lines. The converter takes the opposite trade knowingly: it needs exact division, which
  string arithmetic would not give in twelve lines, and it says so in a clear exception rather than
  fataling if `bcmath` is missing.
- `bcmath` is in both images — checked in both, per trap 2 in `CLAUDE.md`. `intl` is not, and
  `NumberFormatter` stays out of the module entirely (C6).

---

## D5 — `HRK` stays in `Currencies::CODES` until a live `/v1/currencies` says otherwise

**Decided:** 2026-08-24, after a review flagged it.
**Status:** Accepted, provisionally. Revisit the moment an API key exists.

Croatia adopted the euro on 1 January 2023 and the kuna was withdrawn. A review raised that if
freecurrencyapi no longer serves `HRK`, the 33-code list can never be filled: `update_rates()`
would report it every day as `1 predefined code missing from API: HRK`, and
`wp currency doctor` would warn permanently about a row count that can never be reached.

It is left in place, unchanged, because **nobody here has checked what the API actually returns.**

### Why not simply remove it

Removing it is as much of a guess as leaving it. Rate APIs commonly keep withdrawn currencies
served at their final fixed conversion — `HRK` was locked at 7.53450 to the euro — precisely so
historical data keeps resolving. Whether this one does is a question with a factual answer that
costs one request to obtain, and the module's own drift reporting is built to surface it:

- if the API does serve it, removing it would drop a currency the plan supports, and
  `update_rates()` would then report it in the *other* direction, as an unknown code;
- if the API does not, the daily message says so by name, every day, in the words above.

Guessing wrong in either direction produces a permanently noisy sync. The drift report was written
to make exactly this decision from evidence, and using it is cheaper and more honest than
predicting the answer.

### What this rules out

- No silent removal, and no widening of `Currencies::CODES` to whatever the API happens to return.
  The predefined list is the contract; the API is the thing checked against it.
- No suppression of the drift message to quieten the warning. Hiding the signal that answers the
  question is the one response that makes this permanent.

### How to settle it

```bash
# One request. Errors do not spend quota, and this endpoint costs one success.
.claude/skills/freecurrencyapi/scripts/capture-fixtures.sh
grep -o '"HRK"' tests/Fixtures/currencies.json    # present → keep it; absent → remove it
```

Then either delete `HRK` from `Currencies::CODES` and drop the expected count to 32, or record
here that it is served and close this entry. The shipped fixtures are 11-code samples marked
UNVERIFIED in `PROVENANCE.md` and cannot answer it.

### Related

- The same command settles the `RUB` question the brief's `convert( 123, 'USD', 'RUB' )` example
  depends on. Both are one capture away.

---

## D6 — The API response is validated at the source; the admin-notice sink stays unescaped

**Decided:** 2026-08-24, after a security review.
**Status:** Accepted. Enforced by `Api\FreeCurrencyApiClient::latest()`.

WordPress renders settings errors without escaping them. Core escapes the `code` and `type`
arguments of `add_settings_error()` and not the `message`:

```php
$output .= "<div id='$css_id' class='$css_class'> \n";
$output .= "<p><strong>{$details['message']}</strong></p>";
```

`RateUpdater::drift_message()` builds that message partly from currency codes the API returned,
so an unrecognised key in a `/latest` payload reached the admin screen as markup. `latest()` now
drops anything that is not a well-formed code, which is what `update_currencies()` had always
done — the defect was the asymmetry between the two.

The fix is at the source, and the sink is deliberately left alone.

### Why not escape the message instead

The same string is written to three places with three different escaping rules: an HTML notice,
`WP_CLI::log()`, and `error_log()`. `esc_html()` at the point the notice is created would put
`&lt;img …&gt;` in the log file and in the terminal, where entities are noise and the raw value is
what somebody needs to read. Validating once, where the value enters the module, keeps every
consumer correct without any of them having to know about the others.

It is also the narrower change. Escaping at the sink would leave the client still accepting
`{"data":{"<script>":1.0}}` as a currency code, which is wrong independently of where the string
is later printed.

### What this rules out

- No currency code enters the module without passing `Currency::is_valid_code()`. Both API paths
  check it; neither is the exception.
- Codes that are well-formed but unknown — a currency added upstream — are still reported as
  drift. The filter removes malformed keys, not unfamiliar ones, and `XYZ` must keep surviving it.

### How to tell if it has been broken

```bash
# Both response paths must validate the code, not just the value.
grep -n 'is_valid_code' web/app/plugins/currency-converter/src/Api/FreeCurrencyApiClient.php \
                        web/app/plugins/currency-converter/src/Service/RateUpdater.php
# expected: a hit in each
```

### Note on severity

This was never a live hole and is not recorded as one. The API host is a `const` with no filter or
option behind it, and `WpHttpClient` verifies certificates, so the only way to supply a hostile
code was to control freecurrencyapi.com itself. It is written down because the asymmetry between
the two endpoints was the actual defect, and asymmetries come back.

---

## D7 — The API key setting is registered without a `default`

**Decided:** 2026-08-24, while fixing the recursion in the sanitiser.
**Status:** Accepted. Enforced by `Admin\SettingsPage::register_settings()` and pinned by a test.

`register_setting()` is called without a `default` argument. That looks like an oversight and is
not: adding one puts the key into `alloptions` on every page load.

### Why

`register_setting( … 'default' => '' )` registers a `default_option_…` filter, and `update_option()`
branches on it:

```php
if ( apply_filters( "default_option_{$option}", false, $option, false ) === $old_value ) {
    return add_option( $option, $value, '', $autoload );
}
```

The option is pre-created empty so that `autoload='no'` is set before the Settings API ever writes
to it. That makes `$old_value` the empty string — identical to the registered default — so core
took the `add_option()` branch, which recomputed the autoload column from its own default. The row
came back `autoload=auto` and the key was served in `alloptions` to every request: precisely what
the whole of `ApiKey` exists to prevent.

With no registered default the comparison is `false === ''`, which is false, so the save takes the
`UPDATE` branch and leaves the autoload column exactly as the pre-create set it.

Verified against the running stack, saving a key with the option row absent:

| `register_setting` | resulting row | in `alloptions` |
| --- | --- | --- |
| with `'default' => ''` | `autoload=auto` | yes |
| without | `autoload=off` | no |

### What this rules out

- No `default` on this setting, however tidy it looks next to `type` and `show_in_rest`.
- No removal of the pre-create either. Without it the row does not exist when core writes, and
  core creates it autoloaded — the same outcome by the opposite route.

### How to tell if it has been broken

```bash
docker compose exec -T app vendor/bin/phpunit --filter 'without_a_default'
# expected: OK. Fails the moment a default is registered.

# And against the running site, after saving a key through the settings screen:
bin/wp eval 'global $wpdb; echo $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = \"currency_converter_api_key\""), "\n";'
# expected: off   (MariaDB stores WordPress's 'no' as 'off')
```

### Related

- `ApiKey::ensure_option_exists()` carries a re-entrancy guard for the other half of this: core
  runs `sanitize_option()` before its row-exists check, and this option's sanitiser calls back into
  the pre-create. Unguarded, an administrator who deleted the stored key and then saved a new one
  got a 500 from exhausted memory.

---

## D8 — No client library for this API; the client is hand-written

**Decided:** 2026-08-24, before any code. Fixed by the brief.
**Status:** Accepted. Enforced by `Api\FreeCurrencyApiClient` and by the absence of a dependency.

**Decided:** write the client. **Alternative:** `everapihq/freecurrencyapi-php`, the vendor's own
SDK, or any equivalent wrapper.

### Why

The first reason is contractual and settles it on its own: **the brief forbids it by name.** R8
quotes it — "Libraries implementing integration with https://freecurrencyapi.com/ (e.g.
https://github.com/everapihq/freecurrencyapi-php) shouldn't be used" — and invariant 8 repeats it.
A deliverable that ships the SDK fails the task as written, whatever its merits.

The reasons that would hold anyway are worth recording, because they are why the ban is not
arbitrary:

- **It would be a runtime Composer dependency**, which D11 rules out independently. The plugin
  ships as a zip onto a site where this project's `vendor/` does not exist.
- **The surface actually needed is two GET requests and a status-code mapping.** `/v1/latest` and
  `/v1/currencies`, no pagination, no auth handshake, no webhooks. A wrapper around that size of
  API adds an upgrade obligation and a second changelog to track, and removes nothing.
- **It would own three decisions this module makes deliberately.** D1 puts the key in a header
  rather than a query string; D2 turns transport failure into an exception rather than a status of
  `0`; D3 harvests the quota header off responses the module was making anyway. All three would
  become the library's call, and all three are load-bearing here.

### What this rules out

- No `everapihq/*` in `composer.json` or `composer.lock`, and no vendored copy under
  `web/app/plugins/`.
- No "thin wrapper we found" as a substitute. The ban is on libraries implementing the
  integration, not on one package name.

### How to tell if it has been broken

```bash
grep -rn everapihq composer.json composer.lock vendor/ web/app/plugins/
# expected: nothing
```

The scoped paths are the point. The unscoped `grep -rn everapihq .` always hits this entry,
`docs/REQUIREMENTS.md` and `.claude/agents/_TASK_CONTRACT.md` — the three files that ban the
package — so a gate written that way can never come back empty. Same failure mode as the bare
`git grep fca_live_` in D1, and the same fix: scope it.

---

## D9 — One USD base with cross-rates, not a `base_currency` per currency

**Decided:** 2026-08-24, before the schema. Recorded as C1 in `docs/REQUIREMENTS.md`.
**Status:** Accepted. One open item — see *What is still unverified*.

**Decided:** store 33 rows of `USD → X` and derive every other ordered pair at conversion time.
**Alternative:** request `/v1/latest?base_currency=EUR` and friends, storing a 33 × 33 matrix.

### Why

The free plan will not sell the alternative. `/v1/latest` documents `base_currency` as optional and
defaulting to USD, and documents no per-plan restriction on it; in practice the free plan serves
USD and refuses any other base with a subscription error. The literal reading of R3 — rates "for
all available currencies" as an N×N matrix — needs 33 requests a day against a plan that will not
grant 32 of them.

It would also be redundant if it were granted. Every off-diagonal cell is derivable: `A → B` is
`rate(B) / rate(A)`, with `A === B` short-circuited before any lookup. Storing 1,089 rows to hold
33 rows' worth of information means 1,089 rows that can disagree with each other after a partial
update.

The derivation is exact enough to trust because D4 makes it so: `convert()` divides **last**, on
the product, at twice the stored scale, so the cross-rate path takes one rounding decision rather
than two, and takes it on a 24-place intermediate. Going through a pre-rounded cross rate would
round twice, the second time on a number the amount has already scaled up.

`base_code` is a real column rather than an assumed constant, so a paid plan adds rows instead of
forcing a migration. That is the entire allowance made for the future here.

### What this rules out

- No `base_currency` parameter on the request, and no `currencies` filter either — omitting both is
  what "all available currencies" means on this plan (invariant 3).
- No second base written speculatively "so it is ready". The column is the readiness.
- No caching of computed cross rates. They are a division; the table is the cache (invariant: *no
  caching layer beyond the table*).

### How to tell if it has been broken

```bash
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT DISTINCT base_code FROM wp_cc_rates"'
# expected: USD — a single row

make test ARGS="--filter=CurrencyConverterTest"
# expected: OK, including the cross-rate case convert(123,'EUR','RUB') === 22140.0
# with USD→RUB at 90.0 and USD→EUR at 0.5
```

### What is still unverified

**The plan's refusal has never been observed here.** C1 specifies
`Api\Client::probe_base( 'EUR' )` to record the live response and close this, and no such method
exists — the 2026-08-24 QA run confirmed it. So the sentence "the free plan refuses any other
base" is currently inference from the plan's tiering, not a recorded HTTP status. It costs one
request to settle, and settling it belongs with the D5 capture, which is also one request away.

---

## D10 — No rate-history table; rows are overwritten daily

**Decided:** 2026-08-24, before the schema. A non-goal in `.claude/agents/_TASK_CONTRACT.md`.
**Status:** Accepted.

**Decided:** one row per `(base_code, target_code)`, overwritten on every sync.
**Alternative:** an append-only table with a `rate_date` column, and `/v1/historical` to backfill.

### Why

The brief says "Rates should be updated once a day." That describes a **refresh**, not an archive,
and nothing else in the brief reads a historical rate.

The cost of the alternative is not storage — 33 rows a day is 12,000 a year, which is nothing. The
cost is that it changes the shape of every other component:

- **The unique key would have to go**, or grow a date. `UNIQUE KEY base_target` is what makes the
  upsert idempotent and what makes a single multi-row `INSERT … ON DUPLICATE KEY UPDATE` correct.
  Without it, storage stops being idempotent and R4's "run it twice, still 33 rows" check has no
  meaning.
- **Every read becomes "as of when?"** `Converter::convert( 123, 'USD', 'RUB' )` — the brief's own
  signature, with no date parameter — would need a resolution rule for which row it means, and a
  rule for what to do when the latest row predates the query.
- **R7 becomes ambiguous.** "All saved exchange rates" on one admin page is answerable at 33 rows
  and meaningless at 12,000 with no date filter, which C2's total-count check would then have to be
  rewritten around.
- **`/v1/historical` is a separate endpoint** with its own quota draw, against a 5,000/month
  budget that currently sees about 30 requests.

Every one of those is real work in service of a feature nobody asked for.

### What this rules out

- No `rate_date` or `valid_from` column, no `cc_rates_history` table, no append-only log.
- No `/v1/historical` call, and no "we already fetched it, might as well keep it" row.
- No soft-delete or tombstone on a withdrawn currency. Drift is *reported* (D5, C4), not archived.

### How to tell if it has been broken

```bash
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "DESCRIBE wp_cc_rates"'
# expected: exactly five columns — id, base_code, target_code, rate, fetched_at —
# and UNIQUE KEY base_target (base_code, target_code)

# Two syncs, same row count. If this number grows, the table has become a log.
bin/wp currency rates update --force && bin/wp currency rates update --force
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM wp_cc_rates"'
# expected: 33, unchanged
```

---

## D11 — The plugin has no Composer runtime dependencies and carries its own autoloader

**Decided:** 2026-08-24, with the plugin skeleton.
**Status:** Accepted. Enforced by `currency-converter/autoload.php` and by `tests/bootstrap.php`.

**Decided:** a hand-written PSR-4 `spl_autoload_register` inside the plugin, and zero runtime
`require`s of anything outside it. **Alternative:** rely on the project's `vendor/autoload.php`,
and add libraries to `composer.json` as needed.

### Why

**The plugin ships as a zip onto someone else's site.** That site has its own `vendor/`, or none,
and certainly not this project's. A plugin that requires `vendor/autoload.php` works perfectly in
this checkout and fatals on activation everywhere else — the worst failure shape available, because
every check run here passes.

Two properties of this project sharpen it:

- **`vendor/` is Composer output and `make reset` deletes it.** So does a fresh clone before
  `composer install`. A plugin that cannot load without it is a plugin that cannot load on a cold
  checkout.
- **`vendor/` lives at the project root, outside `web/app`.** Reaching it from the plugin means
  `dirname( __DIR__, 4 )` or similar — a path that encodes the Bedrock layout into a plugin that is
  supposed to be portable out of it.

Dev tooling in the root `composer.json` is unaffected and stays there: PHPUnit, Brain\Monkey,
PHPCS and WPCS are the *project's* dependencies, not the plugin's. The line is runtime, not
`composer.json`.

The test bootstrap follows from this rather than working around it. `tests/bootstrap.php` requires
the plugin's real `autoload.php` instead of adding a second PSR-4 mapping to `composer.json`, so
the suite exercises the same loading path the shipped zip uses — quoting its own comment:

> Registering the plugin's real autoloader — rather than adding a second mapping to composer.json —
> means the tests exercise the same loading path the shipped zip uses.

That is also why the autoloader must register lazily: Patchwork can only intercept files loaded
after itself, so the bootstrap may register a loader but must not *load* a testable class.

### What this rules out

- No Guzzle, no decimal library, no PSR-7 implementation. `wp_remote_get()` and `bcmath` are
  already present (D16), which is the whole reason those two were chosen.
- No `require .../vendor/autoload.php` anywhere under `web/app/plugins/currency-converter/`.
- No Composer-generated autoloader for the plugin's own classes, and no `classmap`.

### How to tell if it has been broken

```bash
# The plugin must not reach for the project's autoloader.
grep -rn "vendor/autoload" web/app/plugins/currency-converter/
# expected: nothing

# No runtime dependency was added. (require-dev may change; require may not.)
git diff HEAD -- composer.json composer.lock
# expected: empty, or changes confined to require-dev

make test
# expected: OK — and it proves the plugin's own autoloader resolves every class,
# because tests/bootstrap.php loads no other one
```

---

## D12 — WordPress is the "suitable PHP-framework"

**Decided:** 2026-08-24, at project setup. Argued as C3 in `docs/REQUIREMENTS.md`.
**Status:** Accepted. One unresolved contradiction — see below.

**Decided:** WordPress 7.1, laid out as a Bedrock project, with the module as a plugin.
**Alternative:** Laravel or Symfony, where "framework" is uncontroversial.

### Why

The brief says "**any suitable** PHP-framework", and the deliverable it describes is
WordPress-shaped in one specific place: R7 asks for "a page in the **admin panel**". A framework
has no admin panel. Building that page in Laravel means building an authentication layer, a
capability model, a list-table with sorting and paging, and a settings form — all of which
WordPress supplies as platform, and none of which is the currency module.

The same holds for three more of the twelve requirements: R5's daily scheduler
(`wp_schedule_event`), R3's HTTP client with timeouts and a cURL transport (`wp_remote_get`), and
R4's schema migration (`dbDelta`). Choosing WordPress means four of the twelve requirements are
answered by the platform, and the module is the part the brief actually asked for.

The honest cost, recorded rather than glossed: **WordPress is a CMS and gives none of what a
reviewer means by "framework"** — no DI container, no ORM, no router reachable from a module, no
service layer. Global functions, hooks, `$wpdb`, and a plugin lifecycle.

That mismatch is absorbed rather than ignored:

- the module is PSR-4 under `Drozd\Currency\`, with its own autoloader (D11);
- dependencies are constructor-injected by hand — `new CurrencyConverter( new WpdbRateRepository() )`
  — so the domain classes stay framework-shaped even though the platform is not;
- WordPress is confined to the edges: `wp_remote_get` in `Http\WpHttpClient`, `$wpdb` in `Db\`,
  hooks in `Plugin`, `WP_List_Table` in `Admin\`;
- `Service\CurrencyConverter` touches no WordPress function, which is exactly why it unit-tests
  under Brain\Monkey with no database and no bootstrap.

Bedrock closes most of the remaining distance — env configuration, Composer-owned dependencies, a
real `web/` document root (D14).

### The contradiction that is not yet resolved

Invariant 10 says "`Converter` **and** `Currencies` call no WordPress function." `Currencies` does:
`apply_filters( self::FILTER, array_keys( self::CODES ) )` at `src/Currencies.php:58`. It is there
because R2 and C4 require the `currency_converter_currencies` filter, so the two rules in the
contract are in direct tension and the code cannot satisfy both.

This is recorded, not silently resolved. The likely correction is to narrow invariant 10 to
`Converter` alone — the class whose framework-freedom is load-bearing for the unit suite — and to
state that `Currencies` is permitted exactly one hook and no other WordPress call. **Per the
contract, that is an edit to the contract, made deliberately; it is not licence to leave the two
disagreeing.**

### How to tell if it has been broken

```bash
# The domain layer stays framework-free. Strip comments first: the naive grep
# matches prose in the docblocks and can never come back empty.
grep -nE '\bwp_|WP_|\$wpdb|add_(action|filter)\(' \
  web/app/plugins/currency-converter/src/Service/CurrencyConverter.php
# expected: nothing

make test
# expected: OK — the suite runs with no WordPress bootstrap at all
```

---

## D13 — Integration checks run through `bin/wp eval-file`, not `install-wp-tests.sh`

**Decided:** 2026-08-24, with the test layout.
**Status:** Accepted as the mechanism. **Not yet implemented** — see the gap below.

**Decided:** exercise the real `$wpdb` code path by running a PHP file inside the booted site,
against the running MariaDB. **Alternative:** WordPress's official test suite —
`install-wp-tests.sh`, `WP_UnitTestCase`, a second throwaway database.

### Why the check is needed at all

Brain\Monkey redefines **functions**. `$wpdb` is a global **object**, so `Functions\when()` cannot
touch it. That is not a gap to paper over — it is why the repositories have interfaces at all.

`Tests\Fakes\FakeWpdb` covers one half of it: `tests/Db/WpdbRateRepositoryTest.php` asserts what SQL
the repository *emits* — one statement rather than thirty-three, `%s` rather than `%f`, an
`ORDER BY` no query string can reach. Those are contract requirements and they are worth pinning.

But a recording fake cannot tell you the SQL **parses**, that `dbDelta()` produced columns matching
the DDL, or that `ON DUPLICATE KEY UPDATE` is genuinely idempotent against a real unique index.
Only a database answers those.

### Why not the official suite

- **It wants a second database** that it drops and recreates per run, plus a core test-suite
  checkout. That is a second stateful dependency in a stack whose entire reset story is
  `make reset`.
- **It pins its own PHPUnit.** The unit suite is PHPUnit ^10.5 with `yoast/phpunit-polyfills` ^2.0;
  the core suite has historically lagged that. Two harnesses at two PHPUnit versions in one
  repository is a toolchain problem that produces failures pointing at everything except their
  cause — the exact category the `php-testing` skill exists to prevent.
- **The database it would test against is not the one that serves the site.** `bin/wp eval-file`
  runs against the real MariaDB, with the real prefix, the real `dbDelta()` output and the real
  charset — which is the only configuration whose correctness is being claimed.

### What is given up, knowingly

An `eval-file` check is not PHPUnit. No assertions API, no fixtures, no red/green line in
`make test`; it prints and exits non-zero. That is an acceptable trade at the handful of checks
this needs, and a bad one at fifty. **If the integration checks ever outgrow a single file, revisit
this entry rather than growing a bespoke harness inside it.**

### The gap

`tests/Integration/smoke.php` is named in the plan and in the `php-testing` skill, and **it does not
exist** — confirmed by the 2026-08-24 QA run. So the real `$wpdb` path currently has fake-based
coverage only, and the three properties above are unverified. The decision here is the mechanism;
writing the check is outstanding work, and it should not be reported as covered until it exists.

---

## D14 — Bedrock, not the stock WordPress layout

**Decided:** at project setup, before the module.
**Status:** Accepted.

**Decided:** Roots Bedrock — Composer-owned core, `config/` + `.env`, document root at `web/`.
**Alternative:** stock WordPress with `wp-config.php` at the root and plugins committed to the repo.

### Why

- **The API key needs somewhere to live that is neither the database nor the repository.** Bedrock's
  `.env` plus `Config::define()` gives exactly that, and `.env.example` ships the shape with an
  empty value (C8). Stock WordPress offers `wp-config.php`, which is a tracked file.
- **Core, plugins and themes become disposable.** `web/wp` and `vendor/` are Composer output;
  `make reset` deletes both and `make bootstrap` rebuilds them. That is what makes a cold-start
  verification meaningful.
- **`config/` and `vendor/` are not web-served.** The document root is `web/`, so configuration and
  dependencies are outside it by construction rather than by `.htaccess`.
- **`DISALLOW_FILE_MODS` per environment** follows from the same principle: Composer owns plugins,
  so admin-side installs would be reverted by the next deploy.

### The costs, all three of which have already cost time

These are the project's documented traps, and they are the price of this decision:

1. **`wp config get` is blind.** `Roots\WPConfig\Config::apply()` defines constants at runtime;
   `wp config get` parses `wp-config.php` statically and finds nothing. Every configuration check
   in this repository uses `bin/wp eval` instead. "Missing" and "set but unreadable by that tool"
   look identical otherwise.
2. **The paths are not the stock ones.** Login is `/wp/wp-login.php`, admin is `/wp/wp-admin`,
   content is `/app`. Any tool assuming stock paths 404s — including screenshot automation.
3. **Lint must skip the scaffolding.** `config/`, `web/index.php` and `web/wp-config.php` are
   upstream Bedrock style; running `phpcbf` over them rewrites them into something Bedrock does not
   look like. `phpcs.xml` lists our own code only.

### How to tell if it has been broken

```bash
git check-ignore -q .env && echo ignored        # expected: ignored
bin/wp eval 'var_dump( DISABLE_WP_CRON );'      # expected: bool(true)
# and note that `bin/wp config get DISABLE_WP_CRON` returns nothing — that is trap 1, not a bug
```

---

## D15 — MariaDB 10.11, not MySQL 8

**Decided:** at project setup. The reason is in the `docker-compose.yml` comment.
**Status:** Accepted. Load-bearing for R4.

**Decided:** `mariadb:10.11`. **Alternative:** `mysql:8`.

### Why

**MySQL 8.0.19+ dropped integer display width.** A DDL statement saying `bigint(20)` produces a
column that `DESCRIBE` reports as plain `bigint`. WordPress's `dbDelta()` works by comparing the
DDL string it is given against what `DESCRIBE` returns, so the two no longer match — and `dbDelta()`
emits an `ALTER TABLE` on **every** plugin activation, forever, to fix a difference that is not
real. MariaDB 10.11 still reports `bigint(20)`.

This is not avoidable by writing the DDL without widths: WordPress core's own schema uses display
widths throughout, and `dbDelta()` is built around that convention. Fighting it means either
diverging from every WordPress schema example or accepting a permanent spurious `ALTER`.

R4's acceptance check pins the consequence directly — it expects `bigint(20) unsigned` and
`tinyint(3) unsigned` in the `DESCRIBE` output, which is a MariaDB answer.

Secondary, and real on this hardware: MariaDB publishes clean `arm64` tags.

### What this rules out

- No switch to `mysql:8` without rewriting the DDL in `Db\Schema` and re-pinning R4's expected
  `DESCRIBE` output.
- No `bigint` without a width in the DDL "to be portable". It would be portable and wrong here.

### How to tell if it has been broken

```bash
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "DESCRIBE wp_cc_rates"'
# expected: id bigint(20) unsigned … — with the width present

# The real symptom: activate twice and watch for a pointless ALTER.
bin/wp plugin deactivate currency-converter && bin/wp plugin activate currency-converter
# expected: no schema change reported on the second activation
```

---

## D16 — `bcmath` in both images; `intl` in neither of the ones that matter

**Decided:** 2026-08-24. `bcmath` added to the `Dockerfile`; `intl` deliberately not.
**Status:** Accepted. `bcmath` is relied on by D4.

**Decided:** compile `bcmath` into the `app` image so exact decimal arithmetic exists in the web
request, and keep `intl` out of the module entirely. **Alternative:** rely on the extension set the
base images happen to ship, and use `NumberFormatter` for money rendering.

### Why `bcmath` had to be added rather than assumed

The official WP-CLI image already carried `bcmath`. Our `php:8.3-apache` image did not. So D4's
arithmetic would have passed **every** `bin/wp` check and fatalled on the first admin page load —
the project's trap 2 in its purest form:

> A check that only ran in the CLI says nothing about the web request.

It is now in the `Dockerfile`'s `docker-php-ext-install` list, which makes it true in both. Note
that `CLAUDE.md` still names `bcmath` as the CLI-only example of this trap: **that sentence is
stale and should be corrected.** The trap is entirely real; its example moved.

### Why `intl` stays out

`intl` is in `wpcli` and not in `app`, and it is the live hazard for exactly this module. A currency
module reaches for `NumberFormatter::CURRENCY` — it is the obvious, correct-looking way to render a
rate in the admin table. It would pass every WP-CLI check and fatal on the first admin page load,
in the same asymmetry, in the opposite direction.

Adding it is not free: it drags in libicu, it means a `Dockerfile` change plus `make build` (never
a runtime install), and nothing else in the project wants it. `number_format_i18n()` is WordPress
core, is present wherever WordPress is, and is what the admin uses.

### What this rules out

- No `NumberFormatter`, `IntlDateFormatter`, `collator_*` or `numfmt_*` anywhere in the module.
- No runtime `docker-php-ext-install`. A new extension is a `Dockerfile` change plus `make build`.
- No extension check run in only one container.

### How to tell if it has been broken

```bash
docker compose exec -T app php -m | sort > /tmp/app-mods
docker compose run --rm -T wpcli php -m | sort > /tmp/cli-mods
diff /tmp/app-mods /tmp/cli-mods
# expected today: intl (and imagick) present only in the wpcli column.
# The requirement: the module depends on nothing that appears in only one list.

# Call-shaped, not name-shaped. The naive grep matches the docblocks that explain
# this decision and so can never come back empty — same trap as D1 and D8.
grep -rnE 'new NumberFormatter|NumberFormatter::|numfmt_|collator_' web/app/plugins/currency-converter/
# expected: nothing
```

### Related

- D4 depends on `bcmath` being present in the request that serves the admin page, not just in the
  CLI. `Domain\Rate::normalize_value()` deliberately does *not* use it, so a value object never
  fatals on a missing extension; `CurrencyConverter` does use it and says so in a clear exception
  rather than fatalling.
