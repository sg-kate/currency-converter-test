#!/usr/bin/env bash
# Brings the whole stack up from nothing to an installed WordPress.
# Every step is idempotent: running this twice is a no-op, never a reinstall.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

bold=$'\033[1m'; green=$'\033[32m'; yellow=$'\033[33m'; red=$'\033[31m'; reset=$'\033[0m'
step() { printf '%s==>%s %s\n' "${bold}" "${reset}" "$1"; }
ok()   { printf '  %s✓%s %s\n' "${green}" "${reset}" "$1"; }
warn() { printf '  %s!%s %s\n' "${yellow}" "${reset}" "$1"; }
die()  { printf '  %s✗%s %s\n' "${red}" "${reset}" "$1" >&2; exit 1; }

# ---------------------------------------------------------------- preflight ---
step 'Preflight'

command -v docker >/dev/null 2>&1 || die 'docker is not installed'
docker compose version >/dev/null 2>&1 || die 'docker compose v2 is not available'
docker info >/dev/null 2>&1 || die 'the docker daemon is not running'
ok "docker $(docker version --format '{{.Server.Version}}')"

if [ ! -f .env ]; then
	cp .env.example .env
	ok 'created .env from .env.example'
else
	ok '.env present'
fi

set -a
# shellcheck disable=SC1091
. ./.env
set +a

: "${HTTP_PORT:=8080}"
: "${WP_ADMIN_USER:=admin}"
: "${WP_ADMIN_PASSWORD:=admin}"
: "${WP_ADMIN_EMAIL:=admin@example.com}"
: "${WP_SITE_TITLE:=WordPress Dev}"
SITE_URL="http://localhost:${HTTP_PORT}"

# The image unpacks core into this directory on first start; it must exist first,
# otherwise Docker creates it as root and the container cannot write into it.
mkdir -p wordpress
ok './wordpress ready for core files'

wp() { ./bin/wp "$@"; }

# --------------------------------------------------------------- containers ---
step 'Starting database'
docker compose up -d --wait db
ok 'db is healthy'

step 'Starting WordPress'
docker compose up -d wordpress

printf '  waiting for %s ' "${SITE_URL}"
for _ in $(seq 1 60); do
	code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "${SITE_URL}/" || true)
	case "${code}" in
		200|301|302) printf '\n'; ok "HTTP ${code}"; break ;;
	esac
	printf '.'
	sleep 2
done
[ -n "${code:-}" ] && [ "${code}" != "000" ] || { printf '\n'; die "no response from ${SITE_URL}"; }

# Safety net: the official image unpacks core into the empty volume on first run,
# so this should never fire. It costs nothing and turns a confusing failure into
# a self-healing step if the volume ever ends up half-populated.
if ! docker compose run --rm -T --entrypoint sh wpcli -c 'test -f /var/www/html/wp-settings.php'; then
	warn 'core files missing, downloading'
	wp core download --force
fi
ok "core files present ($(wp core version | tr -d '\r'))"

# ------------------------------------------------------------- installation ---
step 'Installing WordPress'
if wp core is-installed --quiet 2>/dev/null; then
	ok 'already installed, skipping'
else
	wp core install \
		--url="${SITE_URL}" \
		--title="${WP_SITE_TITLE}" \
		--admin_user="${WP_ADMIN_USER}" \
		--admin_password="${WP_ADMIN_PASSWORD}" \
		--admin_email="${WP_ADMIN_EMAIL}" \
		--skip-email
	ok 'installed'
fi

# No --hard: WordPress writes .htaccess itself, and --hard only emits a warning
# about the Apache configuration it cannot inspect from the CLI container.
if [ "$(wp option get permalink_structure 2>/dev/null | tr -d '\r')" != '/%postname%/' ]; then
	wp rewrite structure '/%postname%/' >/dev/null
	ok 'permalinks set to /%postname%/'
else
	ok 'permalinks already /%postname%/'
fi

# ------------------------------------------------------------ config checks ---
# These constants come from WORDPRESS_CONFIG_EXTRA, which the image eval()s from
# the environment at runtime — so `wp config get` cannot see them (it parses the
# file statically) and, more importantly, they only exist in containers that carry
# the env block. Checking them from the WP-CLI container proves both the value and
# the wiring; a container missing the shared env would fail right here.
step 'Verifying runtime constants'

cron_disabled=$(wp eval 'echo defined("DISABLE_WP_CRON") && DISABLE_WP_CRON ? "true" : "false";' | tr -d '\r')
[ "${cron_disabled}" = 'true' ] || die "DISABLE_WP_CRON is ${cron_disabled} — WP-CLI is missing the shared env block"
ok "DISABLE_WP_CRON = true"

actual_home=$(wp eval 'echo defined("WP_HOME") ? WP_HOME : "";' | tr -d '\r')
[ -n "${actual_home}" ] || die 'WP_HOME is undefined — WP-CLI is missing the shared env block'
if [ "${actual_home}" != "${SITE_URL}" ]; then
	warn "WP_HOME is ${actual_home} but HTTP_PORT says ${SITE_URL}"
	warn 'restart the stack so the new value is picked up: make down && make bootstrap'
else
	ok "WP_HOME = ${actual_home}"
fi

# -------------------------------------------------------------------- cron ---
step 'Starting cron'
docker compose up -d cron
ok 'cron loop running (wp cron event run --due-now every 60s)'

# ----------------------------------------------------------------- summary ---
printf '\n%sStack is up.%s\n\n' "${bold}${green}" "${reset}"
printf '  Site   %s\n'        "${SITE_URL}"
printf '  Admin  %s/wp-admin\n' "${SITE_URL}"
printf '  User   %s\n'        "${WP_ADMIN_USER}"
printf '  Pass   %s\n\n'      "${WP_ADMIN_PASSWORD}"
printf '  WP-CLI     bin/wp <command>\n'
printf '  Logs       make logs\n'
printf '  Rebuild    make reset   (drops the database, empties ./wordpress)\n\n'
