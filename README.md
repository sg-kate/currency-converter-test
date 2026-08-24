# WordPress development stack

A clean WordPress site running in Docker. Nothing project-specific — a base to
build on.

## Requirements

Docker with Compose v2. Nothing else — no PHP, no Composer, no local WordPress.

## Getting started

```bash
cp .env.example .env
make bootstrap
```

`make bootstrap` starts the containers, installs WordPress, verifies the runtime
configuration and prints the URL and credentials. It is idempotent: running it
again changes nothing.

| | |
|---|---|
| Site | http://localhost:8080 |
| Admin | http://localhost:8080/wp-admin |
| User / password | `admin` / `admin` (from `.env`) |

## Everyday commands

```bash
make help              # list all targets
make up                # start containers without reinstalling
make down              # stop containers, keep the data
make reset             # destroy everything and bootstrap from scratch
make ps                # container status
make logs S=cron       # follow one service's logs
make db                # MariaDB shell

bin/wp plugin list     # WP-CLI inside the stack
bin/wp cron event list
```

## How it fits together

| Service | Image | Role |
|---|---|---|
| `db` | `mariadb:10.11` | database, with a healthcheck the other services wait on |
| `wordpress` | `wordpress:php8.3-apache` | the site, serving `./wordpress` |
| `wpcli` | `wordpress:cli-php8.3` | one-shot WP-CLI runner behind `bin/wp` |
| `cron` | `wordpress:cli-php8.3` | runs due cron events every 60s |

The core is not downloaded by hand: the official image ships it and unpacks it into
`./wordpress` on first start, so the version is pinned by the image tag. It lives on
disk rather than in a volume so an editor can index it — useful when writing against
core APIs.

`./wordpress` is gitignored and **disposable**: `make reset` deletes it. Keep source
you care about outside it, or in version control.

**MariaDB rather than MySQL** is deliberate. MySQL 8.0.19+ removed integer display
width, so `bigint(20)` in a schema definition no longer matches the `bigint` that
`DESCRIBE` reports, and WordPress `dbDelta()` then emits an `ALTER` on every plugin
activation. MariaDB still reports `bigint(20)`.

**WP-Cron is disabled** (`DISABLE_WP_CRON`) because `wp-cron.php` only fires on page
loads: on a development site nobody visits, scheduled events would never run at all.
The `cron` service is the real scheduler.

## Configuration

Everything lives in `.env` (gitignored; `.env.example` is the template). Quote any
value containing spaces — the file is read both by Docker Compose and by
`bin/bootstrap.sh`.

Constants such as `WP_HOME` and `DISABLE_WP_CRON` are passed through
`WORDPRESS_CONFIG_EXTRA`. The image's `wp-config.php` reads that variable **at
runtime** and `eval()`s it, which has two consequences:

- Changing `.env` takes effect after `make down && make up` — no reset needed.
- Every container running WordPress code needs that environment block, not just
  Apache. It is shared through a YAML anchor in `docker-compose.yml`; without it
  `bin/wp` would run with those constants undefined. `make bootstrap` asserts this.

`wp config get` cannot see these constants, since it parses `wp-config.php`
statically. Use `bin/wp eval 'var_dump( DISABLE_WP_CRON );'` instead.

## Troubleshooting

**Port 8080 taken** — set `HTTP_PORT` in `.env`, then `make down && make bootstrap`.

**Site unreachable after changing `HTTP_PORT`** — `WP_HOME` in the database still
points at the old port. `make bootstrap` warns about this; `make reset` fixes it.

**Fresh start** — `make reset` drops the database and empties `./wordpress`.
