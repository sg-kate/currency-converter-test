# WordPress development environment

A Bedrock-style WordPress project running in Docker: Composer owns core, plugins
and themes, configuration lives in `config/`, and the document root is `web/`.

## Requirements

Docker with Compose v2. Nothing else — PHP, Composer and WP-CLI all run in
containers.

## Getting started

```bash
cp .env.example .env
make bootstrap
```

`make bootstrap` installs dependencies, starts the containers, installs
WordPress, verifies the configuration and prints the URL and credentials. It is
idempotent: running it again changes nothing.

| | |
|---|---|
| Site | http://localhost:8080 |
| Admin | http://localhost:8080/wp/wp-admin |
| User / password | `admin` / `admin` (from `.env`) |

## Layout

```
config/
  application.php              base configuration for every environment
  environments/development.php  overrides for WP_ENV=development
docker/
  config/apache/               vhost: document root, access rules
  config/php/                  php.ini
web/                           document root
  index.php  wp-config.php  .htaccess
  wp/                          WordPress core — Composer, gitignored
  app/                         wp-content, renamed
    themes/test-theme/         our theme
    mu-plugins/  plugins/  uploads/
bin/                           bootstrap, reset, wp, composer wrappers
```

Only our own code is under version control. `web/wp`, `vendor/` and everything
Composer installs into `web/app` are ignored and rebuilt by `make bootstrap`.

## Everyday commands

```bash
make help                     # list all targets
make up                       # start containers without reinstalling
make down                     # stop containers, keep the data
make reset                    # destroy the database and dependencies, rebuild
make build                    # rebuild the application image
make logs S=cron              # follow one service's logs
make db                       # MariaDB shell
make lint                     # coding standards
make lint-fix                 # fix what can be fixed automatically

bin/wp plugin list            # WP-CLI inside the stack
bin/composer require ...      # Composer inside the stack
```

## Services

| Service | Image | Role |
|---|---|---|
| `db` | `mariadb:10.11` | database, with a healthcheck the others wait on |
| `app` | built from `Dockerfile` | PHP 8.3 + Apache serving `web/` |
| `composer` | `composer:2` | on-demand dependency management behind `bin/composer` |
| `wpcli` | `wordpress:cli-php8.3` | on-demand WP-CLI behind `bin/wp` |
| `cron` | `wordpress:cli-php8.3` | runs due cron events every 60s |

`app` is built rather than pulled because core no longer comes from an image: the
official `wordpress` image ships its own copy of WordPress, which would collide
with the one Composer installs into `web/wp`.

## How configuration works

`.env` holds environment values. `config/application.php` reads them and defines
the WordPress constants; `config/environments/{WP_ENV}.php` overrides them per
environment. `web/wp-config.php` only requires those two files.

Because constants are defined at runtime through `Roots\WPConfig\Config`,
`wp config get` cannot see them — it parses the file statically. Use
`bin/wp eval 'var_dump( WP_DEBUG );'` instead. `make bootstrap` asserts the
important ones this way.

Keys and salts are **not** in `.env.example`. `bin/bootstrap.sh` generates eight
unique values into `.env` on first run, so no two checkouts share secrets.

### Notable defaults

- **WP-Cron is disabled** and the `cron` service runs due events every 60s.
  `wp-cron.php` only fires on page loads, so on a site nobody visits scheduled
  events would never run at all.
- **`DISALLOW_FILE_MODS`** is on everywhere except development: Composer owns
  plugins and themes, so an admin-side install would be reverted by the next
  deploy. Locally it is relaxed so `wp plugin install` still works.
- **MariaDB rather than MySQL.** MySQL 8.0.19+ removed integer display width, so
  `bigint(20)` in a schema definition no longer matches the `bigint` that
  `DESCRIBE` reports, and `dbDelta()` then emits an `ALTER` on every plugin
  activation. MariaDB still reports `bigint(20)`.

## Troubleshooting

**Port 8080 taken** — set `HTTP_PORT` in `.env` (`WP_HOME` follows it), then
`make down && make bootstrap`.

**Site unreachable after changing `HTTP_PORT`** — the URLs stored in the database
still point at the old port. `make reset` fixes it, or update them with
`bin/wp option update home ...` and `siteurl`.

**404 on every page but the homepage** — `web/.htaccess` is missing or
`AllowOverride` is off. The file is under version control; restore it.

**Fresh start** — `make reset` drops the database, `web/wp` and `vendor/`.
`web/app` is left alone, so your themes and uploads survive.
