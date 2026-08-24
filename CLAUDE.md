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

## Traps — all three cost us time, none is in any documentation

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
