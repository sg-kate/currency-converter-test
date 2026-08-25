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

wp()       { ./bin/wp "$@"; }
composer() { ./bin/composer "$@"; }

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

# ------------------------------------------------------------------- salts ---
# Keys and salts ship empty in .env.example so that no two checkouts share
# secrets. Filling them here keeps `cp .env.example .env` the only manual step.
if grep -qE '^AUTH_KEY=.+' .env; then
	ok 'keys and salts already set'
else
	step 'Generating keys and salts'
	tmp=$(mktemp)
	while IFS= read -r line || [ -n "${line}" ]; do
		case "${line}" in
			AUTH_KEY=|SECURE_AUTH_KEY=|LOGGED_IN_KEY=|NONCE_KEY=|\
AUTH_SALT=|SECURE_AUTH_SALT=|LOGGED_IN_SALT=|NONCE_SALT=)
				salt=$(LC_ALL=C tr -dc 'A-Za-z0-9!@#%^&*()_+=-' < /dev/urandom 2>/dev/null | head -c 64 || true)
				[ -n "${salt}" ] || die 'could not read randomness from /dev/urandom'
				printf "%s'%s'\n" "${line}" "${salt}" >> "${tmp}"
				;;
			*)
				printf '%s\n' "${line}" >> "${tmp}"
				;;
		esac
	done < .env
	mv "${tmp}" .env
	ok 'eight unique keys and salts written to .env'
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

# ---------------------------------------------------------------- composer ---
# Core, plugins and themes are Composer dependencies, so nothing can start before
# this: web/wp does not exist yet and wp-config.php requires vendor/autoload.php.
step 'Installing dependencies'
if [ -f vendor/autoload.php ] && [ -f web/wp/wp-settings.php ]; then
	ok 'vendor/ and web/wp/ already present'
else
	composer install --no-interaction --no-progress
	ok 'composer install finished'
fi
[ -f web/wp/wp-settings.php ] || die 'web/wp is missing — check the wordpress-install-dir setting'
ok "WordPress core in web/wp"

# --------------------------------------------------------------- containers ---
step 'Starting database'
docker compose up -d --wait db
ok 'db is healthy'

step 'Starting the site'
docker compose up -d --build app

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

# ------------------------------------------------------------- installation ---
step 'Installing WordPress'

# Ask the database directly instead of booting WordPress. Against an empty
# database `wp core is-installed` still loads mu-plugins, and the autoloader
# writes its cache into an options table that does not exist yet — three
# database errors in a brand new debug.log that look like a broken install.
tables=$(docker compose exec -T db sh -c \
	"exec mariadb -u\"\$MARIADB_USER\" -p\"\$MARIADB_PASSWORD\" -N -B \
		-e \"SHOW TABLES LIKE '${DB_PREFIX:-wp_}options'\" \"\$MARIADB_DATABASE\"" 2>/dev/null || true)

if [ -n "${tables}" ] && wp core is-installed --quiet 2>/dev/null; then
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

if [ "$(wp option get permalink_structure 2>/dev/null | tr -d '\r')" != '/%postname%/' ]; then
	wp rewrite structure '/%postname%/' >/dev/null
	ok 'permalinks set to /%postname%/'
else
	ok 'permalinks already /%postname%/'
fi

if [ "$(wp option get stylesheet 2>/dev/null | tr -d '\r')" = 'test-theme' ]; then
	ok 'test-theme already active'
else
	wp theme activate test-theme >/dev/null
	ok 'test-theme activated'
fi

# The currency module. Activation is what creates its tables and schedules its
# syncs, so a bootstrap that stops short of it leaves a site where the plugin is
# present, the admin pages 404 and nothing has ever run.
#
# Guarded rather than unconditional: `wp plugin activate` on an already-active
# plugin is a warning and a non-zero exit, which would fail the `set -e` above on
# every re-run and make the script anything but idempotent.
if wp plugin is-active currency-converter 2>/dev/null; then
	ok 'currency-converter already active'
elif [ -f web/app/plugins/currency-converter/currency-converter.php ]; then
	wp plugin activate currency-converter >/dev/null
	ok 'currency-converter activated'
else
	warn 'currency-converter not present — skipping activation'
fi

# A front page for the module, so a reviewer has somewhere to look that is not
# wp-admin. Created only when missing and matched by slug rather than title, so
# renaming it in the editor does not make bootstrap create a second one.
if wp plugin is-active currency-converter 2>/dev/null; then
	if [ "$(wp eval 'echo ( $p = get_page_by_path( "currency-rates" ) ) ? $p->ID : 0;' 2>/dev/null | tr -d '\r')" = "0" ]; then
		wp post create \
			--post_type=page \
			--post_title='Currency Rates' \
			--post_name=currency-rates \
			--post_status=publish \
			--post_content='<!-- wp:currency-converter/rates /-->' >/dev/null
		ok 'demo page created at /currency-rates/'
	else
		ok 'demo page already at /currency-rates/'
	fi

	# Give that page something to show. Without an API key nothing can be fetched,
	# and a reviewer following the README would land on an empty table.
	#
	# Only when the table is empty, so this can never overwrite real rates: once a
	# key is configured and a sync has run, the count is non-zero and this is
	# skipped for good. The fixture dates its rows in the past and switches demo
	# mode on, which puts a "Demo data, not live rates" warning on every surface
	# that renders them — the one thing worse than an empty table is a full one
	# that lies about where it came from.
	stored=$(wp eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_rates" );' 2>/dev/null | tr -d '\r')

	if [ "${stored:-0}" = "0" ]; then
		if wp currency rates load-fixture >/dev/null 2>&1; then
			ok 'demo rates loaded (fixture — not live; set an API key for real rates)'
		else
			warn 'no rates stored and the fixture would not load — the page will be empty'
		fi
	else
		ok "rates already stored (${stored} rows)"
	fi
fi

# ------------------------------------------------------------ config checks ---
# Config::apply() only defines constants at runtime, so `wp config get` cannot
# see them. Asserting through `wp eval` proves the config actually loaded.
step 'Verifying configuration'
for check in \
	"WP_ENV:development" \
	"DISABLE_WP_CRON:1" \
	"WP_CONTENT_DIR:/var/www/html/web/app"
do
	const="${check%%:*}"
	expected="${check#*:}"
	actual=$(wp eval "echo defined('${const}') ? ${const} : '';" 2>/dev/null | tr -d '\r')
	[ "${actual}" = "${expected}" ] || die "${const} is '${actual}', expected '${expected}'"
	ok "${const} = ${actual}"
done

# -------------------------------------------------------------------- cron ---
step 'Starting cron'
docker compose up -d cron
ok 'cron loop running (wp cron event run --due-now every 60s)'

# ----------------------------------------------------------------- summary ---
printf '\n%sStack is up.%s\n\n' "${bold}${green}" "${reset}"
printf '  Site   %s\n'          "${SITE_URL}"
printf '  Admin  %s/wp/wp-admin\n' "${SITE_URL}"
printf '  User   %s\n'          "${WP_ADMIN_USER}"
printf '  Pass   %s\n\n'        "${WP_ADMIN_PASSWORD}"
printf '  WP-CLI     bin/wp <command>\n'
printf '  Composer   bin/composer <command>\n'
printf '  Logs       make logs\n'
printf '  Rebuild    make reset   (drops the database and installed dependencies)\n\n'
