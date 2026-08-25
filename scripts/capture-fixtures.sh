#!/bin/sh
# Capture real freecurrencyapi.com responses into tests/Fixtures/, in one run.
#
# The point is that it is ONE run. Every successful call spends quota against a
# 5000/month allowance, so the fixtures are captured once and the unit tests then run
# offline forever. Do not put this in a loop, a watch, or CI.
#
#   scripts/capture-fixtures.sh              key from .env
#   scripts/capture-fixtures.sh --key KEY    use KEY instead of .env
#
# Quota cost of a full run: exactly 2 requests.
#
#   /v1/latest       200  -> latest.json        1 request
#   /v1/currencies   200  -> currencies.json    1 request
#   /v1/latest       401  -> error-401.json     0 — errors do not count
#   429                   -> error-429.json     not captured; see below
#
# error-429.json cannot be captured honestly: provoking a real 429 means exhausting
# either the per-minute limit or the whole monthly quota. It is therefore written from
# the shape documented in the freecurrencyapi skill and marked as such in PROVENANCE.md.
# If a 429 is ever seen in the wild, paste the real body over it and update that file.
#
# Never prints the key. Deliberately not `set -e`: a probe that observes failures must
# not abort on the first non-zero exit.

set -u

API="https://api.freecurrencyapi.com/v1"
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR="$ROOT/tests/Fixtures"

key=""
while [ $# -gt 0 ]; do
	case "$1" in
		--key)     key="${2:-}"; shift 2 ;;
		--key=*)   key="${1#*=}"; shift ;;
		-h|--help) sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*)         echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

# Parse only the one line we need rather than sourcing .env, which would pull the
# database password and eight salts into this shell for no reason.
if [ -z "$key" ] && [ -f "$ROOT/.env" ]; then
	key=$(sed -n 's/^FREECURRENCYAPI_KEY=//p' "$ROOT/.env" | head -1 | tr -d '\047\042 \r')
fi

mkdir -p "$DIR"
hdr=$(mktemp) || exit 1
trap 'rm -f "$hdr"' EXIT INT TERM

captured_month=""
captured_limit=""
capture_status=""
status_latest="not attempted"
status_currencies="not attempted"

# Fetch one endpoint into a fixture file. Only overwrites on a 200: a fixture replaced
# by an error body is worse than no fixture, because the tests keep passing.
#
# The status comes back in `capture_status` rather than on stdout, and the caller must NOT
# wrap this in `$( )`. A command substitution puts the whole function in a subshell, which
# cost this script both of the things it exists to print: the per-endpoint progress lines
# were captured as output instead of shown, and `captured_month` was assigned in the
# subshell and discarded on return, so the quota summary at the end never had a value to
# report and silently skipped itself.
capture() {
	label="$1"
	url="$2"
	out="$3"

	code=$(curl -s -S -m 20 -H "apikey: $key" -D "$hdr" -o "$DIR/.tmp.json" -w '%{http_code}' "$url" 2>/dev/null)

	if [ "$code" = "200" ]; then
		mv "$DIR/.tmp.json" "$DIR/$out"
		month=$(grep -i '^x-ratelimit-remaining-quota-month:' "$hdr" | tr -d '\r' | awk '{print $2}')
		limit=$(grep -i '^x-ratelimit-limit-quota-month:' "$hdr" | tr -d '\r' | awk '{print $2}')
		[ -n "$month" ] && captured_month="$month"
		[ -n "$limit" ] && captured_limit="$limit"
		printf '  %-24s HTTP 200  -> %s  (quota remaining: %s)\n' "$label" "$out" "${month:-unreported}"
	else
		rm -f "$DIR/.tmp.json"
		printf '  %-24s HTTP %s  -> NOT written, %s left untouched\n' "$label" "$code" "$out"
	fi

	capture_status="$code"
}

echo "Capturing fixtures into tests/Fixtures/"
echo

if [ -n "$key" ]; then
	printf 'key: ****%s\n\n' "$(printf '%s' "$key" | tail -c 4)"
	echo "Authenticated captures — 2 requests of quota:"
	capture "/v1/latest" "$API/latest" "latest.json"
	status_latest="$capture_status"

	capture "/v1/currencies" "$API/currencies" "currencies.json"
	status_currencies="$capture_status"
	echo
else
	echo "No FREECURRENCYAPI_KEY in .env — skipping the two authenticated captures."
	echo "latest.json and currencies.json are left as they are. Add the key and re-run."
	echo
fi

echo "Free capture — an error spends no quota:"
code=$(curl -s -S -m 20 -H "apikey: deliberately-invalid" -o "$DIR/.tmp.json" -w '%{http_code}' "$API/latest" 2>/dev/null)
if [ "$code" = "401" ]; then
	mv "$DIR/.tmp.json" "$DIR/error-401.json"
	printf '  %-24s HTTP 401  -> error-401.json\n' "/v1/latest (no key)"
else
	rm -f "$DIR/.tmp.json"
	printf '  %-24s HTTP %s  -> NOT written\n' "/v1/latest (no key)" "$code"
fi
status_401="$code"

echo
echo "Writing PROVENANCE.md"

# What the file already claims, read BEFORE the redirect below truncates it.
#
# A run without a key deliberately leaves latest.json and currencies.json untouched, but
# the table used to be regenerated from this run's statuses regardless — so a colleague
# with no key in .env, refreshing the 401 fixture, silently relabelled two real captures
# as "UNVERIFIED" and dropped the quota line. The files were fine and the record of where
# they came from was destroyed, which is the one thing this file exists to keep.
#
# A row is rewritten only when this run actually re-captured that file. Otherwise the
# previous row is carried forward verbatim, and only if there is no previous row at all
# does it fall back to UNVERIFIED.
prev_latest=""
prev_currencies=""
prev_401=""
prev_quota=""

if [ -f "$DIR/PROVENANCE.md" ]; then
	prev_latest=$(grep -m1 '^| `latest.json`' "$DIR/PROVENANCE.md" || true)
	prev_currencies=$(grep -m1 '^| `currencies.json`' "$DIR/PROVENANCE.md" || true)
	prev_401=$(grep -m1 '^| `error-401.json`' "$DIR/PROVENANCE.md" || true)
	prev_quota=$(grep -m1 '^Monthly quota after this run:' "$DIR/PROVENANCE.md" || true)
fi

{
	echo "# Fixture provenance"
	echo
	echo "Generated by \`scripts/capture-fixtures.sh\`. Do not edit by hand — re-run the script."
	echo
	echo "Captured: $(date -u '+%Y-%m-%d %H:%M UTC')"
	if [ -n "$captured_month" ]; then
		echo
		echo "Monthly quota after this run: **${captured_month}** remaining of ${captured_limit:-unknown}."
	elif [ -n "$prev_quota" ]; then
		echo
		echo "$prev_quota"
	fi
	echo
	echo "| File | Source | Status |"
	echo "| --- | --- | --- |"
	if [ "$status_latest" = "200" ]; then
		echo "| \`latest.json\` | live \`GET /v1/latest\`, no filters | **real**, captured $(date -u '+%Y-%m-%d') |"
	elif [ -n "$prev_latest" ]; then
		echo "$prev_latest"
	else
		echo "| \`latest.json\` | shape from the \`freecurrencyapi\` skill | **UNVERIFIED** — no live capture yet |"
	fi
	if [ "$status_currencies" = "200" ]; then
		echo "| \`currencies.json\` | live \`GET /v1/currencies\` | **real**, captured $(date -u '+%Y-%m-%d') |"
	elif [ -n "$prev_currencies" ]; then
		echo "$prev_currencies"
	else
		echo "| \`currencies.json\` | shape from the \`freecurrencyapi\` skill | **UNVERIFIED** — no live capture yet |"
	fi
	if [ "$status_401" = "401" ]; then
		echo "| \`error-401.json\` | live \`GET /v1/latest\` with an invalid key | **real**, captured $(date -u '+%Y-%m-%d') |"
	elif [ -n "$prev_401" ]; then
		echo "$prev_401"
	else
		echo "| \`error-401.json\` | shape from the \`freecurrencyapi\` skill | **UNVERIFIED** |"
	fi
	echo "| \`error-429.json\` | shape from the \`freecurrencyapi\` skill | **UNVERIFIED by design** — see below |"
	echo
	echo "## Why error-429.json is not captured live"
	echo
	echo "Provoking a real 429 means exhausting the per-minute limit or the entire monthly"
	echo "quota. Neither is an acceptable price for a fixture, so the body is written from the"
	echo "documented shape. It is a single field — \`{\"message\":\"Too Many Requests\"}\` — and the"
	echo "part that actually matters for the client is the **headers**, not the body: the body"
	echo "does not say whether the minute or the month was exhausted, and the tests supply those"
	echo "headers directly."
	echo
	echo "## Refreshing"
	echo
	echo '```bash'
	echo "scripts/capture-fixtures.sh      # 2 requests of quota, one run"
	echo '```'
	echo
	echo "An UNVERIFIED fixture is not a broken one — the shapes come from the skill's"
	echo "documentation — but it has not been confirmed against the live API. A fixture that"
	echo "quietly diverges from the real shape is worse than no fixture, because the tests keep"
	echo "passing, so the status column is the thing to read before trusting a test that uses one."
} > "$DIR/PROVENANCE.md"

echo
echo "Done. Fixtures in tests/Fixtures/:"
ls -1 "$DIR"
