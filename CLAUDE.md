# Project guide

A Bedrock-style WordPress project in Docker. Composer owns core, plugins and themes;
configuration lives in `config/`; the document root is `web/`.

## Layout

```
config/application.php            base config for every environment
config/environments/*.php         per-environment overrides, picked by WP_ENV
docker/config/{apache,php}/       vhost and php.ini, baked into the image at build time
web/                              document root
  index.php  wp-config.php  .htaccess
  wp/                             WordPress core — Composer, gitignored, DISPOSABLE
  app/                            wp-content, renamed
    themes/test-theme/            our theme
    mu-plugins/  plugins/  uploads/
bin/                              bootstrap, reset, wp, composer wrappers
tests/                            PHPUnit
```

## Commands

```bash
make bootstrap          # from nothing to an installed site; idempotent
make up / down / reset  # reset destroys the database, web/wp and vendor/
make build              # rebuild the app image (needed after Dockerfile changes)
make lint / lint-fix    # phpcs over our own code only
make test               # phpunit inside the app container
make logs S=cron        # follow one service

bin/wp <command>        # WP-CLI in the wpcli container
bin/composer <command>  # Composer as the host user
```

## Traps — all four cost us time, none is in any documentation

**1. `wp config get` cannot see the configuration.** `Roots\WPConfig\Config::apply()` defines
constants at runtime; `wp config get` parses `wp-config.php` statically and finds nothing. Always
check with `bin/wp eval 'var_dump( WP_DEBUG );'`.

**2. PHP extensions differ between containers.** `app` (our `php:8.3-apache` image) and
`wpcli`/`cron` (the official Alpine WP-CLI image) do not carry the same extension set — `bcmath`
is in the CLI image but not in ours. **A check that only ran in the CLI says nothing about the
web request.** Verify anything extension-dependent in both, and after touching the `Dockerfile`
compare `php -m` across them.

**3. Bedrock paths are not the usual ones.** Login is `/wp/wp-login.php`, the admin is
`/wp/wp-admin`, and content is served from `/app`, not `/wp-content`. `home` has no `/wp`
suffix, `siteurl` does. Any tool that assumes stock paths will 404.

**4. `wp plugin uninstall` deletes the plugin's source.** `web/app/plugins/currency-converter/`
is hand-written source in the working tree, not Composer output — nothing can reinstall it, and
it is untracked, so git cannot restore it either. The R4 acceptance check in
`docs/REQUIREMENTS.md` once ran the bare command and destroyed the entire module; it was
rebuilt only because `~/.claude/projects/` still held the session logs. Snapshot before, restore
after — `docs/REQUIREMENTS.md` carries the exact form. The same applies to `wp plugin delete`.

## Rules

- **Never edit `web/wp` or `vendor/`.** Both are Composer output and `make reset` deletes them.
  `web/app` survives a reset — that is where source belongs.
- **Never commit `.env`.** It holds database credentials and eight generated salts. `.env.example`
  ships the shape with empty secrets; `bin/bootstrap.sh` fills the salts on first run.
- **A new PHP extension means a `Dockerfile` change plus `make build`**, not a runtime install.
- **`DISALLOW_FILE_MODS` is on everywhere except development.** Composer owns plugins and themes,
  so admin-side installs would be reverted by the next deploy.
- **Lint covers our code only.** `config/`, `web/index.php` and `web/wp-config.php` are Bedrock
  scaffolding in upstream style; running `phpcbf` over them rewrites them into something Bedrock
  does not look like.

## Task constraints — currency module

Non-negotiable, and each one is here because getting it wrong is expensive. Full brief in
`docs/REQUIREMENTS.md`, invariants and non-goals in `.claude/agents/_TASK_CONTRACT.md`, API
details in the `freecurrencyapi` skill.

- **`everapihq/freecurrencyapi-php`, and any SDK for this API, are forbidden.** The brief bans them
  by name. Gate before delivery:
  `grep -rn everapihq composer.json composer.lock vendor/ web/app/plugins/` → nothing.
- **The plugin has no Composer runtime dependencies and carries its own PSR-4 autoloader.** It
  ships as a zip onto someone else's site, where this project's `vendor/` does not exist. Dev
  tooling in the root `composer.json` is fine; a runtime `require` is not.
- **Bind rates as `%s`, never `%f`.** `%f` is locale-formatted and lossy against `DECIMAL(24,12)`.
- **The API key travels as an HTTP header**, never a query parameter — query strings land in access
  logs and proxy caches.
- **Quota is 5000/month.** Develop against fixtures
  (`.claude/skills/freecurrencyapi/references/response-fixtures.md`); hit the live API only when
  the request itself is what is being tested.
- **Options holding secrets use `autoload='no'`** — otherwise the key is in every page load's
  `alloptions` cache.
- **The rate map is memoised per request.** One query per request, not one per `convert()` call.
- **The upsert is a single multi-row `INSERT ... ON DUPLICATE KEY UPDATE`**, not `$wpdb->replace()`
  in a loop — replace is a delete plus an insert, so it churns primary keys 33 times a day.
