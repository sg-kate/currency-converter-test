---
name: freecurrencyapi
description: "Use when calling freecurrencyapi.com from this project — building or debugging the rate fetcher, choosing an endpoint, handling 401/422/429, reading the rate-limit headers, checking an API key, or deciding what the free plan will and will not serve. Also use before adding any dependency that talks to this API."
compatibility: "api.freecurrencyapi.com v1, free plan. HTTP through wp_remote_get(); no vendor SDK. Key in FREECURRENCYAPI_KEY."
---

# freecurrencyapi

The API this project downloads exchange rates from. Three endpoints matter, one plan restriction
shapes the whole design, and one dependency is forbidden.

## The hard gate: no SDK

**`everapihq/freecurrencyapi-php`, and any other client library for this API, must not be used.**
The brief forbids it by name. This is not a style preference — a reviewer will check, and a single
`composer require` of it fails the task outright.

Write the client by hand. Before delivering anything, run both of these:

```bash
# 1. The gate. Scoped to the places a dependency can actually live.
grep -rn everapihq composer.json composer.lock vendor/ web/app/plugins/
# expected: no output, exit status 1

# 2. The sweep. Unscoped, so nothing hides.
grep -rn everapihq .
# expected: hits in .claude/ and docs/ prose ONLY — the documents that forbid it.
# Any hit outside those two directories fails the gate.
```

The gate is the one that decides, and it is scoped deliberately: an unscoped grep can never come
back empty in this repository, because this skill, `.claude/agents/_TASK_CONTRACT.md` and
`docs/REQUIREMENTS.md` all name the package in order to ban it. A gate that always fails gets
ignored within a week, so the sweep exists to be read rather than to exit 1.

If the gate prints anything — `composer.json`, `composer.lock`, an installed `vendor/` tree, a
plugin file — the dependency is present: `bin/composer remove everapihq/freecurrencyapi-php`, then
re-run both.

Nothing else about the integration is constrained: `wp_remote_get()` is the tool this project
uses, and `curl`, Guzzle or `file_get_contents` would all be equally acceptable to the brief.

## Endpoints

Base URL `https://api.freecurrencyapi.com/v1`. Every request needs the key. Send it in the
**header**, not the query string — the query form ends up in access logs and proxy caches.

```php
wp_remote_get(
    'https://api.freecurrencyapi.com/v1/latest',
    array(
        'headers' => array( 'apikey' => FREECURRENCYAPI_KEY ),
        'timeout' => 15,
    )
);
```

### `GET /v1/latest` — the one the module actually calls

| Parameter | Required | Notes |
| --- | --- | --- |
| `apikey` | yes | header or query; use the header |
| `base_currency` | no | defaults to USD. **The free plan serves USD only** — see `references/free-plan-limits.md` |
| `currencies` | no | comma-separated allowlist. **Omit it** to get every available currency, which is what the brief asks for |

Omitting `currencies` is the point: "rates for all available currencies" is one request with no
filter, not one request per currency.

### `GET /v1/currencies` — metadata, for seeding the predefined list

Returns code, name, symbol, decimal digits per currency. Takes the same optional `currencies`
filter, plus `as_html`. Call it **once**, when seeding `Currencies::CODES`, and paste the result
into the constant. Do not call it on every sync: the list changes about never, and every call
spends quota that the daily rate sync needs.

This is also how the RUB question gets settled — the docs say "33 supported currencies" without
listing them, so the only authority on whether the brief's `convert(123, 'USD', 'RUB')` example
can run against live data is a real response from this endpoint.

### `GET /v1/status` — quota, and the only free health check

Returns `account_id` and `quotas.month` (`total`, `used`, `remaining`) plus a `grace` bucket that
is normally zero. Use it to check a key without spending a rate request, and to explain a 429
after the fact.

## Status codes

| Code | Means | What the client does |
| --- | --- | --- |
| 200 | success | decode; treat a missing or empty `data` as a failure, not as zero rates |
| 401 | key missing, malformed, or revoked | fail loudly — never fall back to an unauthenticated retry |
| 422 | validation error: unknown currency code, malformed parameter | fail loudly; a retry with the same input cannot succeed |
| 429 | quota or per-minute rate limit exhausted | back off; do **not** retry inside the same run |
| 403 | plan does not allow the request — a non-USD `base_currency` surfaces here | fail loudly; treat as a design error, not a transient one |

401, 403 and 422 are permanent for the request as written. Retrying them is how a bug becomes a
banned key. Only network-level failures — DNS, connect timeout, read timeout, which arrive from
`wp_remote_get()` as a `WP_Error` rather than a status code — deserve a retry, and one retry is
enough for a job that runs daily.

Distinguish the two 429s before backing off: the per-minute limit clears within a minute, the
monthly quota does not clear until the month does. The headers say which.

## Rate-limit headers

Present once the key authenticates — on a 200, and on errors raised after auth such as 422 and
429. **Absent on a 401**, where the request never reached an account (verified against the live
API: a 401 carries only `content-type`). Code that reads a remaining-quota header without a
fallback will see an empty string, not a zero.

| Header | Meaning |
| --- | --- |
| `X-RateLimit-Limit-Quota-Month` | monthly allowance |
| `X-RateLimit-Remaining-Quota-Month` | requests left this month |
| `X-RateLimit-Limit-Quota-Minute` | per-minute allowance |
| `X-RateLimit-Remaining-Quota-Minute` | requests left this minute |

Read them with `wp_remote_retrieve_header()` and log the monthly remainder after each sync. A
sync that logs `remaining: 4970` on day one and `remaining: 4200` on day three is making far more
requests than one a day, and the header is the only place that shows it before the month ends.

```php
$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining-quota-month' );
```

WordPress lowercases header names on retrieval.

## Checking a key

```bash
.claude/skills/freecurrencyapi/scripts/probe-api.sh              # key from .env
.claude/skills/freecurrencyapi/scripts/probe-api.sh --key fca_live_xxx
.claude/skills/freecurrencyapi/scripts/probe-api.sh --base EUR   # prove the plan restriction
```

Hits `/v1/status`, then `/v1/latest`, prints the status code, the quota headers and a trimmed
body. `--base` sends a non-USD `base_currency` so the plan's actual refusal is recorded rather
than assumed. It never writes to the database and never prints the key.

## References

- `references/free-plan-limits.md` — the `base_currency` restriction, what spends quota and what
  does not, and the arithmetic behind one-request-per-day.
- `references/response-fixtures.md` — real response shapes for all three endpoints and for each
  error, for building test doubles without a network.

## Traps

**Errors do not spend quota.** Only successful calls count, so a probe that 422s is free. This
makes it safe to check a key repeatedly while debugging — and it means a client that silently
swallows errors can loop forever without the quota ever warning you.

**`data` can be present and useless.** A 200 with `{"data":{}}` is a failure for this module's
purposes. Check for the codes you expect, not merely for HTTP 200.

**Rates are floats in JSON.** `json_decode()` gives PHP floats; the column is `DECIMAL(20,10)`.
Let the database do the narrowing and never `(string)` a float on the way in — locale-independent
formatting is `sprintf( '%.10F', $rate )` if a string is genuinely needed.

**Three containers, three network paths.** A request from the admin leaves `app`; the daily sync
leaves `cron`; `bin/wp currency update` leaves `wpcli`. When testing failure handling, break the
one you are actually exercising — see the `wp-stack` skill.
