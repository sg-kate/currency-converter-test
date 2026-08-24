---
name: wp-stack
description: "Use when working with this project's Docker WordPress stack: running WP-CLI or Composer, adding a PHP extension or dependency, debugging why a constant or config change has no effect, resetting the environment, or finding logs."
compatibility: "Bedrock-style WordPress on Docker Compose v2. Services: app (php:8.3-apache), db (mariadb:10.11), wpcli and cron (wordpress:cli-php8.3)."
---

# WP stack runbook

## When to use

- running anything against the site — WP-CLI, Composer, SQL
- a configuration change appears to have no effect
- adding a PHP extension, a Composer package, or a plugin
- the site is broken and you need to know what is disposable and what is not

## The containers

| Service | Image | Role |
|---|---|---|
| `db` | `mariadb:10.11` | database; has a healthcheck the others wait on |
| `app` | built from `Dockerfile` | serves the site from `web/`, PHP 8.3 + Apache |
| `wpcli` | `wordpress:cli-php8.3` | one-shot WP-CLI, started by `bin/wp`, profile `tools` |
| `cron` | `wordpress:cli-php8.3` | runs `wp cron event run --due-now` every 60s |

The whole project is bind-mounted to `/var/www/html` in every service, so all four see the same
files. `wp-cli.yml` points WP-CLI at `web/wp`.

**Three different network stacks.** A request made from the admin UI leaves `app`; a scheduled
job leaves `cron`; `bin/wp` leaves `wpcli`. When testing network failure, break the container
whose path you are actually exercising.

## Commands

```bash
make bootstrap          # nothing → installed site; idempotent, safe to re-run
make up                 # start without reinstalling
make down               # stop, keep data
make reset              # destroy database, web/wp, vendor/ — then bootstrap
make build              # rebuild the app image after a Dockerfile change
make ps / logs S=cron   # status, follow one service
make db                 # MariaDB shell
make lint / test        # phpcs / phpunit

bin/wp <command>        # WP-CLI
bin/composer <command>  # Composer, as the host user so vendor/ is not root-owned
```

## What is disposable

| Path | Survives `make reset`? |
|---|---|
| `web/wp`, `vendor/` | **No** — Composer output, deleted and reinstalled |
| `web/app` | Yes — themes, plugins, uploads live here |
| `config/`, `bin/`, `docker/` | Yes — under version control |
| database | **No** |

Never put source in `web/wp`. Never edit `vendor/`.

## Traps

### `wp config get` cannot see the configuration

`config/application.php` defines constants through `Roots\WPConfig\Config`, applied at runtime by
`Config::apply()`. `wp config get` parses `wp-config.php` statically and finds nothing there.

```bash
bin/wp config get WP_DEBUG                   # wrong — always empty
bin/wp eval 'var_dump( WP_DEBUG );'          # right
```

`bin/bootstrap.sh` asserts `WP_ENV`, `DISABLE_WP_CRON` and `WP_CONTENT_DIR` this way.

### PHP extensions differ between containers

`app` is our own image; `wpcli`/`cron` is the official Alpine one, and their extension sets are
not the same. This already bit once: `bcmath` shipped in the CLI image but not in ours, so exact
decimal maths worked through `bin/wp` and would have silently degraded in the browser. It is now
in both. **`intl` is still CLI-only** — deliberately, because nothing depends on it and it drags
in libicu; if something ever does, add it to the `Dockerfile` rather than working around it.

**A check that only ran through `bin/wp` says nothing about what a browser request can do.**
Verify extension-dependent behaviour in both:

```bash
bin/wp eval 'var_dump( function_exists("bcdiv") );'      # the CLI answer
curl -s localhost:8080/some-page-that-exercises-it        # the web answer
```

Adding an extension is a `Dockerfile` edit plus `make build` — then compare `php -m` again.

### Bedrock paths

| | |
|---|---|
| Site | `http://localhost:8080` |
| Admin | `http://localhost:8080/wp/wp-admin` |
| Login | `http://localhost:8080/wp/wp-login.php` |
| Content URL | `/app`, not `/wp-content` |
| `home` / `siteurl` | without `/wp` / **with** `/wp` |

### Configuration changes that seem ignored

`.env` is read by `config/application.php` at request time, so most changes apply immediately.
Two exceptions: `HTTP_PORT` also lives in the database (`home`, `siteurl`), and anything in
`docker/config/` is baked into the image — that needs `make build`.

## Logs

- PHP and Apache errors: `docker compose logs app` (php.ini sends `error_log` to stderr)
- WordPress: `web/app/debug.log` (`WP_DEBUG_LOG` is on in development)
- Scheduler: `docker compose logs cron`

`web/app/debug.log` is **not** cleared by `make reset` — timestamps matter when reading it.
