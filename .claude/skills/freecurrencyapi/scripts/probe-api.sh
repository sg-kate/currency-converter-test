#!/bin/sh
# Check a freecurrencyapi.com key and record what the plan actually serves.
#
# Read-only: touches no database, writes no files, and never prints the key.
# Errors do not spend quota, so this is free to run while a key is still wrong.
#
#   probe-api.sh                 key from .env, probes /v1/status then /v1/latest
#   probe-api.sh --key KEY       use KEY instead of .env
#   probe-api.sh --base EUR      send a non-USD base_currency, to record the plan's refusal
#   probe-api.sh --currencies    probe /v1/currencies instead (the 33-code list)
#   probe-api.sh --raw           print full response bodies, not a trimmed head
#
# Deliberately NOT set -e: a probe whose whole purpose is to observe failures must
# not abort on the first non-zero exit.

set -u

API="https://api.freecurrencyapi.com/v1"
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../../.." && pwd)

key=""
base=""
raw=0
want_currencies=0

while [ $# -gt 0 ]; do
	case "$1" in
		--key)         key="${2:-}"; shift 2 ;;
		--key=*)       key="${1#*=}"; shift ;;
		--base)        base="${2:-}"; shift 2 ;;
		--base=*)      base="${1#*=}"; shift ;;
		--currencies)  want_currencies=1; shift ;;
		--raw)         raw=1; shift ;;
		-h|--help)     sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*)             echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

# .env is the project's single source for secrets and is gitignored. Parse only the
# one line we need rather than sourcing the file, which would import DB credentials
# and eight salts into this shell for no reason.
if [ -z "$key" ] && [ -f "$ROOT/.env" ]; then
	key=$(sed -n 's/^FREECURRENCYAPI_KEY=//p' "$ROOT/.env" | head -1 | tr -d '\047\042 \r')
fi

if [ -z "$key" ]; then
	cat >&2 <<-EOF
		No API key.

		Add it to .env (gitignored, and .env is never committed):

		    FREECURRENCYAPI_KEY=fca_live_...

		then define it in config/application.php alongside the other constants, or
		pass it here directly with --key. Note that the constant will be invisible to
		\`wp config get\` — check it with:

		    bin/wp eval 'echo defined( "FREECURRENCYAPI_KEY" ) ? "set" : "MISSING";'
	EOF
	exit 1
fi

# Only ever show the tail, and only enough of it to tell two keys apart.
suffix=$(printf '%s' "$key" | tail -c 4)
printf 'key: ****%s (%s chars)\n\n' "$suffix" "$(printf '%s' "$key" | wc -c | tr -d ' ')"

hdr=$(mktemp) || exit 1
body=$(mktemp) || exit 1
trap 'rm -f "$hdr" "$body" "$body.err"' EXIT INT TERM

probe() {
	label="$1"
	url="$2"

	code=$(curl -s -S -m 20 \
		-H "apikey: $key" \
		-D "$hdr" \
		-o "$body" \
		-w '%{http_code}' \
		"$url" 2>"$body.err")
	status=$?

	printf '%s\n' "$label"
	printf '  GET %s\n' "$url"

	if [ $status -ne 0 ]; then
		printf '  transport failure (curl exit %s): %s\n' "$status" "$(cat "$body.err")"
		printf '  no status code — this is the WP_Error branch in the client, and the\n'
		printf '  only failure worth retrying. It spends no quota.\n\n'
		rm -f "$body.err"
		return 1
	fi
	rm -f "$body.err"

	printf '  HTTP %s' "$code"
	case "$code" in
		200) printf '  — success (this one spent quota)\n' ;;
		401) printf '  — key missing, malformed or revoked. Permanent; do not retry.\n' ;;
		403) printf '  — plan does not allow this request. Permanent; a design error, not a transient one.\n' ;;
		422) printf '  — validation error: an unknown code or malformed parameter. Permanent.\n' ;;
		429) printf '  — quota or per-minute limit. Read the headers below to tell which.\n' ;;
		*)   printf '  — unexpected; treat as a failure, not as zero rates.\n' ;;
	esac

	# Present on every response, error responses included.
	grep -i '^x-ratelimit' "$hdr" | sed 's/^/  /' | tr -d '\r'

	if [ "$raw" -eq 1 ]; then
		printf '  body:\n'
		sed 's/^/    /' "$body"
		printf '\n'
	else
		printf '  body: %s\n' "$(cut -c1-300 "$body" | tr -d '\n')"
	fi
	printf '\n'
}

probe "status — quota, without spending a rate request" "$API/status"

if [ "$want_currencies" -eq 1 ]; then
	probe "currencies — the supported list, for seeding Currencies::CODES" "$API/currencies"
	printf 'Codes returned (paste into the constant, do not fetch this on every sync):\n'
	grep -o '"code":"[A-Z]\{3\}"' "$body" | cut -d'"' -f4 | sort | tr '\n' ' '
	printf '\n\n'
	printf 'RUB present: '
	grep -q '"RUB"' "$body" && printf 'yes — the brief'"'"'s convert(123, USD, RUB) example can run live\n' \
		|| printf 'NO — collision C5 applies, substitute a supported pair in the live example\n'
	exit 0
fi

if [ -n "$base" ]; then
	probe "latest with base_currency=$base — recording what the plan does with a non-USD base" \
		"$API/latest?base_currency=$base"
	printf 'Expected on the free plan: a 4xx subscription error, which is collision C1 in\n'
	printf 'docs/REQUIREMENTS.md. Paste the status line above into\n'
	printf 'references/response-fixtures.md so the fixture matches the real plan.\n'
	exit 0
fi

probe "latest — no currencies filter, no base_currency: all available currencies, one request" \
	"$API/latest"

printf 'Reminder: the daily sync makes exactly this call, once. If the monthly remainder\n'
printf 'above drops faster than one per day, something is calling it more than once.\n'
