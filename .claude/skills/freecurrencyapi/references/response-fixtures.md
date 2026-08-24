# Response fixtures

Response shapes for building test doubles without a network. `tests/Unit/ClientTest.php` fakes
transport through the `pre_http_request` filter and never opens a socket; these are the bodies it
returns.

**Provenance.** Shapes follow the documented endpoints; values are illustrative and truncated.
Where a shape could not be confirmed from the public documentation it is marked
**observed** or **unconfirmed** — do not treat those as authoritative until a live probe has
replaced them:

```bash
.claude/skills/freecurrencyapi/scripts/probe-api.sh --raw
```

Paste real output over the marked blocks when a key is available. A fixture that quietly diverges
from the live shape is worse than no fixture, because the tests keep passing.

## `GET /v1/latest`

Request as the module makes it — no `currencies`, no `base_currency`:

```
GET https://api.freecurrencyapi.com/v1/latest
apikey: fca_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

200:

```json
{
  "data": {
    "AUD": 1.5183456789,
    "BGN": 1.7891234567,
    "BRL": 5.4321098765,
    "CAD": 1.3654321098,
    "CHF": 0.8912345678,
    "CNY": 7.1234567890,
    "EUR": 0.9145678901,
    "GBP": 0.7823456789,
    "JPY": 148.9012345678,
    "RUB": 93.0071234567,
    "USD": 1.0
  }
}
```

Points that matter for the client:

- `data` is a flat object of `CODE => float`, no envelope, no base echoed back. **The base is not
  in the response** — the client knows it is USD because it did not ask for anything else, and
  writes `base_code = 'USD'` from its own configuration, not from the payload.
- `USD: 1.0` is present in its own list. Store it or skip it, but be deliberate: it makes
  `rate(USD → USD)` a real row, which the identity short-circuit in `Converter` makes redundant.
- Values are JSON numbers, decoded as PHP floats. The column is `DECIMAL(20,10)`.
- Key order is not guaranteed. Never index by position.

Truncated to the two-currency form the unit tests use, so the arithmetic in
`tests/Unit/ConverterTest.php` is exact and readable:

```json
{ "data": { "EUR": 0.5, "RUB": 90.0, "USD": 1.0 } }
```

With those, `convert(123, 'USD', 'RUB') === 11070.0` and
`convert(123, 'EUR', 'RUB') === 22140.0`.

## `GET /v1/currencies`

200 — metadata, one entry per supported currency:

```json
{
  "data": {
    "EUR": {
      "symbol": "€",
      "name": "Euro",
      "symbol_native": "€",
      "decimal_digits": 2,
      "rounding": 0,
      "code": "EUR",
      "name_plural": "Euros",
      "type": "fiat"
    },
    "JPY": {
      "symbol": "¥",
      "name": "Japanese Yen",
      "symbol_native": "￥",
      "decimal_digits": 0,
      "rounding": 0,
      "code": "JPY",
      "name_plural": "Japanese yen",
      "type": "fiat"
    }
  }
}
```

`decimal_digits` is the field to use when rounding for display — JPY is 0, not 2. It is also the
reason `Converter::convert()` returns an unrounded float and leaves rounding to the caller: the
correct scale is per-currency data, not a constant.

The exact field set is **unconfirmed** against the current API; the documentation page describes
the endpoint without printing a full response. `symbol_native`, `name_plural`, `rounding` and
`type` are the historically present fields. The module reads only `name` and `decimal_digits`, so
extra or missing optional fields must not break parsing.

## `GET /v1/status`

200:

```json
{
  "account_id": 123456,
  "quotas": {
    "month": { "total": 5000, "used": 37, "remaining": 4963 },
    "grace":  { "total": 0,   "used": 0,  "remaining": 0 }
  }
}
```

Note the shape difference from the other two: `quotas`, not `data`. A client that unwraps `data`
unconditionally will read `null` here.

## Rate-limit headers

On responses from an authenticated request — a 200, and errors raised after auth such as 422 and
429:

```
X-RateLimit-Limit-Quota-Month: 5000
X-RateLimit-Remaining-Quota-Month: 4963
X-RateLimit-Limit-Quota-Minute: 10
X-RateLimit-Remaining-Quota-Minute: 9
```

**Not present on a 401** — verified live, where the response carries only `content-type`. A
`wp_remote_retrieve_header()` call there returns `''`, not `0`, and `(int) ''` is `0`, which reads
as "quota exhausted" if the client does not check for the header's presence first.

`wp_remote_retrieve_header()` lowercases the name: `x-ratelimit-remaining-quota-month`.

## Errors

### 401 — bad or missing key

**Observed live** on 2026-08-24, against `/v1/status` and `/v1/latest` with a deliberately invalid
key, and identically with no key at all:

```json
{
  "message": "Unauthorized",
  "error": {
    "code": "invalid_api_key",
    "message": "Unauthorized"
  },
  "actions": {
    "get_free_api_key": "https://api.freecurrencyapi.com/v1/agent/keys",
    "sign_up": "https://app.freecurrencyapi.com/register?...",
    "docs": "https://freecurrencyapi.com/docs/..."
  }
}
```

Three things the client should take from this:

- `error.code` (`invalid_api_key`) is the stable machine-readable field. Branch on it, not on
  `message`, which is prose.
- The body carries marketing URLs. **Never render it into an admin notice unescaped** — log the
  status code and `error.code`, and show the operator a message of our own.
- A missing key and a wrong key are indistinguishable here, both 401. The module checks
  `defined( 'FREECURRENCYAPI_KEY' )` itself and fails before making the request, so the two cases
  stay distinguishable in our own logs.

### 422 — validation error

```json
{
  "message": "Validation error",
  "errors": {
    "currencies": [ "The selected currencies is invalid." ]
  }
}
```

`errors` is keyed by parameter name, each holding an array of strings. An unknown currency code
lands here, which is why `Currencies::CODES` is seeded from a real `/v1/currencies` response
rather than from guesswork.

### 429 — quota or rate limit

```json
{ "message": "Too Many Requests" }
```

The body does not distinguish the two limits. **The headers do** — read
`x-ratelimit-remaining-quota-minute` against `x-ratelimit-remaining-quota-month` before choosing
how long to wait. See `free-plan-limits.md`.

### 403 — plan restriction

Returned for a `base_currency` the plan does not allow. **Observed, not documented**; the message
resembles `subscription plan does not support this feature`. Replace this block with real output
from `probe-api.sh --base EUR` the first time it is run against the project's key, and cite it
from collision C1 in `docs/REQUIREMENTS.md`.

### Transport failure

No status code at all. `wp_remote_get()` returns a `WP_Error` — DNS failure, connect timeout, read
timeout. The client must branch on `is_wp_error()` **before** touching
`wp_remote_retrieve_response_code()`, which returns an empty string for a `WP_Error` and would be
silently compared against 200.

```php
if ( is_wp_error( $response ) ) {
    throw new ApiException( $response->get_error_message() );
}
```

This is the only failure worth retrying, and once is enough for a job that runs daily.
