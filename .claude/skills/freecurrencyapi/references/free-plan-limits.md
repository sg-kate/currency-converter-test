# Free plan limits

What the free tier will and will not serve, and how that constrains the module. Read this before
designing anything that assumes a currency pair is directly fetchable.

## The `base_currency` restriction

`/v1/latest` documents `base_currency` as an ordinary optional parameter:

> "The base currency to which all results are behaving relative to. By default all values are
> based on USD"

The documentation does not state anywhere that the parameter is plan-gated, and does not list the
error returned when it is refused. In practice **the free plan serves USD as the base and refuses
any other**, with a 4xx subscription error (observed as 403, message along the lines of
`subscription plan does not support this feature`).

Because the docs are silent, do not take this file's word for it either — record the live
behaviour and cite the recording:

```bash
.claude/skills/freecurrencyapi/scripts/probe-api.sh --base EUR
```

Paste the resulting status line into `references/response-fixtures.md` when it is first observed
on a given key, so the fixture used by the tests matches the plan the project is actually on.

### What it costs the design

Taken literally, "rates for all available currencies" would mean every ordered pair — 33 bases ×
33 targets. On this plan that is 33 requests per day, 990 per month against a 5,000 quota, to buy
information the plan will not sell in 32 of the 33 cases anyway.

The module therefore stores **one base**:

```
USD → AUD    USD → BGN    USD → BRL    ...    33 rows
```

and derives every other pair arithmetically:

```
rate(A → B) = rate(USD → B) / rate(USD → A)
rate(A → USD) = 1 / rate(USD → A)
rate(A → A) = 1                                (short-circuit, no lookup)
```

This is exact to float precision and needs no extra request. Store `base_code` as a real column
rather than assuming USD everywhere, so a paid plan later adds rows instead of forcing a schema
migration.

The cross-rate is the part worth testing directly, because it is the part the plan forced on us:
`convert(123, 'EUR', 'RUB')` must equal `123 × rate(USD→RUB) / rate(USD→EUR)`.

## Quota

Two independent limits, both reported in the `X-RateLimit-*` response headers of any request that
authenticated. A 401 carries no such headers — verified live — so quota can only be read once the
key is valid:

| Limit | Exhausted behaviour | Clears |
| --- | --- | --- |
| Monthly quota | HTTP 429 | end of the month |
| Per-minute rate limit | HTTP 429 | end of the minute |

The free tier's monthly allowance is the number the `/v1/status` endpoint reports, not a number to
hardcode from memory — plans change. Read it:

```bash
.claude/skills/freecurrencyapi/scripts/probe-api.sh | grep -i quota
```

Both limits return the same status code, so **check the headers before deciding how to back off**.
`X-RateLimit-Remaining-Quota-Minute: 0` with month remaining is a wait of under a minute;
`X-RateLimit-Remaining-Quota-Month: 0` is a wait of up to a month, and no amount of retrying will
help.

A `grace` bucket appears in `/v1/status` alongside `month`. It is a temporary allowance during a
pending payment and is 0 on a free plan — do not design around it.

## What spends quota

From the documentation:

> "Only successful calls count against your quota. Any error on our side or any validation error
> (e.g. wrong parameter) will NOT count against your quota or rate limit."

So:

| Call | Spends quota |
| --- | --- |
| `/v1/latest` returning 200 | **yes** |
| `/v1/currencies` returning 200 | **yes** |
| `/v1/status` returning 200 | yes — it is a successful call |
| any 401 / 403 / 422 | no |
| any 429 | no |
| a connect or read timeout | no — it never reached them |

Two consequences worth holding onto:

1. **Debugging a broken key is free.** Probe as often as needed while the key is wrong; the meter
   only starts once it is right.
2. **A silent failure loop is invisible in the quota.** Code that swallows errors and retries can
   run all month without the quota budging, which removes the one signal that would have shown
   the sync was never working. Log failures; do not rely on quota consumption as a health check.

## The budget

One request per day against a 5,000-per-month quota:

| Consumer | Requests/month |
| --- | --- |
| Daily rate sync (`/v1/latest`, once a day) | ~31 |
| `/v1/currencies`, seeding the predefined list once | 1 |
| Occasional `/v1/status` probes and manual `--force` runs | tens |

That leaves the quota effectively untouched, which is the intent: the constraint that actually
bites is `base_currency`, not the request count. The freshness window in `RateUpdater` — a second
sync inside 24 hours is skipped unless `--force` is passed — exists so that a busy site, a second
cron container, or a misconfigured `CRON_INTERVAL` cannot turn one request a day into thousands.

## What this plan does not give you

- **No historical rates.** `/v1/historical` exists but is not part of this module's brief, and no
  rate-history table is being built. See `.claude/agents/_TASK_CONTRACT.md`, non-goals.
- **No non-USD base.** Covered above.
- **No currency-conversion endpoint.** Conversion is arithmetic performed locally, which is what
  the brief asks for anyway — a service object, not a proxied API call.
