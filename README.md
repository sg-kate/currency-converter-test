# Currency converter — WordPress module

A WordPress module that stores a predefined list of currencies, downloads exchange rates from
[freecurrencyapi.com](https://freecurrencyapi.com/) once a day, keeps them in the database, and
provides a conversion service plus an admin page listing every saved rate.

The module is a plugin at `web/app/plugins/currency-converter/`. Around it is a Bedrock-style
WordPress project in Docker: Composer owns core, plugins and themes, configuration lives in
`config/`, and the document root is `web/`.

*Русская версия: [README.ru.md](README.ru.md).*

## Requirements

**Docker with Compose v2, and nothing else.** PHP, Composer, WP-CLI and MariaDB all run in
containers — none of them has to exist on your machine. You need `git` to clone, and `make` is a
convenience: if you do not have it, run `./bin/bootstrap.sh` instead of `make bootstrap`.

## Start it — three commands

```bash
git clone <repository-url> test-project
cd test-project
make bootstrap
```

That is the whole setup. There is no `.env` to copy first: `bin/bootstrap.sh` creates it from
`.env.example` and generates eight unique keys and salts into it on the first run.

`make bootstrap` installs dependencies, starts the containers, installs WordPress, activates the
theme **and the currency module**, publishes the demo page below, verifies the configuration and
prints the URL and credentials. It is idempotent — running it again changes nothing and reinstalls
nothing.

| | |
|---|---|
| Site | http://localhost:8080 |
| **Converter — start here** | **http://localhost:8080/currency-rates/** |
| Admin | http://localhost:8080/wp/wp-admin |
| Rates page (admin) | http://localhost:8080/wp/wp-admin/admin.php?page=currency-rates |
| Settings (admin) | http://localhost:8080/wp/wp-admin/admin.php?page=currency-settings |
| User / password | `admin` / `admin` |

**http://localhost:8080/currency-rates/ is the page to open first.** It carries the block described
below: a working converter over the stored rates, and the full rate table under it. `make bootstrap`
publishes it, so it is there on a clean clone.

With no API key configured, bootstrap seeds the table from the bundled fixture so the page has
something to show. Those rows are **demo data, dated in the past**, and every surface that renders
them says so. Set a key and run `bin/wp currency rates update --force` to replace them with live
rates.

The admin lives under `/wp/`, not at `/wp-admin` — that is Bedrock's layout, and a bookmark from a
stock WordPress site will 404.

First run takes a few minutes, almost all of it Composer downloading WordPress core and the
dependencies. Later runs start in seconds.

## The API key

**The site starts and runs without a key.** Every rate-fetching path refuses loudly rather than
storing nothing or inventing a rate, so a checkout with no key is a working site with an empty
rates table.

To fetch real rates, get a free key from [freecurrencyapi.com](https://freecurrencyapi.com/) and put
it in `.env`:

```bash
FREECURRENCYAPI_KEY=fca_live_...
```

Then `bin/wp currency rates update --force`.

`.env` is gitignored and must stay that way — it holds the database password and the eight salts.
`.env.example` ships the shape with empty secrets. The key travels to the API as an HTTP header,
never as a query parameter, so it cannot leak into an access log or a proxy cache.

The free plan allows **5000 requests a month**. The module makes one request a day and refuses a
second run inside 24 hours unless forced, so a busy site cannot burn the quota.

## Using the module

### The conversion service

The signature is the one the brief asks for:

```php
$converter = new Drozd\Currency\Service\CurrencyConverter(
    new Drozd\Currency\Db\WpdbRateRepository()
);

$converter->convert( 123, 'USD', 'RUB' );   // 11439.876185174
```

Rates are stored against a single USD base and any other pair is derived arithmetically, so
`EUR → RUB` works without a stored `EUR` row. An unknown currency throws `UnknownCurrencyException`;
a pair with no stored rate throws `RatesUnavailableException`. Neither ever returns the input
unchanged, a 1:1 rate, or zero.

### WP-CLI

```bash
bin/wp currency convert 123 USD RUB      # convert an amount
bin/wp currency rates update             # fetch rates (skipped if fresh)
bin/wp currency rates update --force     # fetch regardless of freshness
bin/wp currency rates list               # every stored rate
bin/wp currency currencies list          # the predefined list
bin/wp currency doctor                   # check config, schema, key, quota
```

Add `--format=csv`, `--format=json` or `--format=count` to either `list` command.

### Admin

- **Currency Rates** — every saved rate, sortable, with a total count that matches the database.
- **Settings** — API key status (presence and last four characters only, never the value), last
  sync time, and an "Update now" button.

### Block — `Currency Rates`

The front end of the module, and what http://localhost:8080/currency-rates/ shows. Insert it from
the block inserter under **Widgets → Currency Rates**, or write it directly:

```
<!-- wp:currency-converter/rates /-->
```

It renders a converter — amount, from, to, result — and the stored rate table beneath it. The
sidebar controls the base currency, how many rows to show, and whether the converter, the table and
the freshness line each appear.

Two things about it are deliberate:

- **It is a dynamic block.** Nothing is saved into post content, so the rates a visitor sees are the
  ones in the database today rather than the ones there when somebody last opened the editor.
- **The browser never does the arithmetic.** The converter asks
  `GET /wp-json/currency-converter/v1/convert`, which runs the same `bcmath` path as the CLI.
  JavaScript has one number type and it is a float; the module exists because floats lose money.
  The endpoint is public and read-only, and never reaches freecurrencyapi.com — it cannot spend
  quota.

### Shortcode

```
[currency_convert amount="123" from="USD" to="RUB"]
```

Renders `$123.00 = ₽10,182.68` — both sides in their own currency's symbol and decimal places, so
a yen amount shows none. For a converter a reader can drive, use the block above.

## Everyday commands

```bash
make help                     # list all targets
make up / make down           # start / stop containers, keeping data
make reset                    # destroy the database, web/wp and vendor/, rebuild
make build                    # rebuild the app image (after a Dockerfile change)
make test                     # PHPUnit inside the app container
make lint / make lint-fix     # coding standards over our own code
make logs S=cron              # follow one service
make db                       # MariaDB shell
make screenshots              # capture admin screenshots into docs/screenshots/

bin/wp <command>              # WP-CLI in the wpcli container
bin/composer <command>        # Composer as the host user
```

`make screenshots` is the one command here that needs something outside Docker: Node on the host,
plus `npm install` for Playwright. Nothing else in this file does, and neither does the setup above.

`make reset` deletes `web/wp` and `vendor/` — both are Composer output. `web/app` survives, so the
plugin, themes and uploads are untouched.

## Layout

```
config/application.php          base config for every environment
config/environments/*.php       per-environment overrides, picked by WP_ENV
docker/config/{apache,php}/     vhost and php.ini, baked into the image
web/                            document root
  wp/                           WordPress core — Composer, gitignored, disposable
  app/                          wp-content, renamed
    plugins/currency-converter/ the module
    themes/test-theme/          the theme
bin/                            bootstrap, reset, wp, composer wrappers
tests/                          PHPUnit — unit tests, no WordPress bootstrap
docs/                           requirements, decisions, screenshots, transcripts
```

## Services

| Service | Image | Role |
|---|---|---|
| `db` | `mariadb:10.11` | database, with a healthcheck the others wait on |
| `app` | built from `Dockerfile` | PHP 8.3 + Apache serving `web/` |
| `composer` | `composer:2` | on-demand, behind `bin/composer` |
| `wpcli` | `wordpress:cli-php8.3` | on-demand, behind `bin/wp` |
| `cron` | `wordpress:cli-php8.3` | runs due cron events every 60s |

WP-Cron is disabled — `wp-cron.php` only fires on page loads, so on a site nobody visits the daily
rate update would never run. The `cron` container is the real scheduler.

MariaDB rather than MySQL 8 on purpose: MySQL 8.0.19+ removed integer display width, so `bigint(20)`
in a schema definition no longer matches the `bigint` that `DESCRIBE` reports, and `dbDelta()` then
emits a pointless `ALTER` on every plugin activation.

## Deliverables

- **[docs/screenshots/](docs/screenshots/)** — the rates page, the settings page, the shortcode.
  Reproducible with `make screenshots`.
- **[docs/transcripts/](docs/transcripts/)** — exported AI sessions, redacted of credentials by the
  `session-transcript-export` skill.

## Documentation

| File | What it holds |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Project guide, three traps that cost real time, and the module's constraints |
| [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) | The brief as twelve numbered requirements, each with the command that decides it, plus eight collisions and how they resolve |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Sixteen decisions: what was chosen, the alternative, and why |
| [.claude/agents/_TASK_CONTRACT.md](.claude/agents/_TASK_CONTRACT.md) | Invariants and non-goals — the short version that settles arguments |

## Three traps

All three cost time here, and none is in any upstream documentation.

**1. `wp config get` cannot see the configuration.** `Roots\WPConfig\Config::apply()` defines
constants at runtime; `wp config get` parses `wp-config.php` statically and finds nothing. Check
with `bin/wp eval 'var_dump( WP_DEBUG );'` instead. "Missing" and "set but invisible to that tool"
look identical otherwise.

**2. PHP extensions differ between containers.** `app` and `wpcli`/`cron` do not carry the same
extension set — `intl` is in the CLI image and not in `app`. A check that only ran through
`bin/wp` says nothing about what a browser request can do, which is why the module uses
`number_format_i18n()` and never `NumberFormatter`.

**3. Bedrock paths are not the usual ones.** Login is `/wp/wp-login.php`, the admin is
`/wp/wp-admin`, content is served from `/app`, not `/wp-content`.

## Troubleshooting

**Port 8080 already in use** — set `HTTP_PORT` in `.env` (`WP_HOME` follows it), then
`make down && make bootstrap`.

**Site unreachable after changing `HTTP_PORT`** — the URLs stored in the database still point at the
old port. `make reset` fixes it, or update `home` and `siteurl` with `bin/wp option update`.

**`docker daemon is not running`** — bootstrap checks this first and stops. Start Docker Desktop.

**404 on every page but the homepage** — `web/.htaccess` is missing or `AllowOverride` is off. The
file is under version control; restore it.

**Rates table is empty** — expected with no API key. Check with `bin/wp currency doctor`.

**Start over** — `make reset` drops the database, `web/wp` and `vendor/`, then bootstraps again.
