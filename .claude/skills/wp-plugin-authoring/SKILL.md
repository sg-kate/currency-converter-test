---
name: wp-plugin-authoring
description: "Use when writing a WordPress plugin: database schema with dbDelta, activation and uninstall hooks, admin pages and list tables, Settings API, security (nonces, capabilities, sanitizing, escaping), WP-CLI commands, scheduled events, and packaging for distribution."
compatibility: "WordPress 6.x–7.x, PHP 8.1+. Assumes this project's Bedrock layout: plugins live in web/app/plugins."
---

# WordPress plugin authoring

## When to use

Writing or reviewing plugin code: schema, hooks, admin UI, CLI, packaging.

## Where plugins live here

`web/app/plugins/<slug>/` — the whole project is bind-mounted, so files appear in the container
immediately, with no separate mount to configure.

`web/app/plugins/*` is gitignored by default. **Add an explicit negation for your own plugin**,
otherwise its source silently never reaches git:

```
!/web/app/plugins/<slug>/
```

### The plugin must stand on its own

Even though the project uses Composer, a plugin that ships as a zip runs on sites that have no
`vendor/`. Write a small PSR-4 `spl_autoload_register` inside the plugin rather than depending on
the project autoloader, and optionally fall back to a bundled `vendor/autoload.php` if present.

## dbDelta

`dbDelta()` is a parser, not a migration tool, and it is unforgiving. Every rule below breaks it
silently — the table is created, and then an `ALTER` is issued on every single activation.

- two spaces after `PRIMARY KEY`: `PRIMARY KEY  (id)`
- one field per line — the parser splits on newlines
- `KEY`, never `INDEX`
- every key named: `KEY enabled (enabled)`, not `KEY (enabled)`
- field and key names lower case
- `datetime NULL DEFAULT NULL`, never `'0000-00-00 00:00:00'` — that dies under `NO_ZERO_DATE`
- no `IF NOT EXISTS`
- always `$wpdb->get_charset_collate()`
- `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` first — it is **not** loaded on the
  front end or in WP-CLI
- index short columns: an indexed `varchar(255)` in utf8mb4 is 1020 bytes, past the 767-byte
  limit of the old InnoDB row format

**The correctness check is that a second run returns an empty array.** Anything else means a rule
above is broken. Log the return value under `WP_DEBUG` and assert it after re-activation.

`dbDelta` cannot drop or rename columns. Schema v2 needs an explicit `ALTER` behind a version
option.

### Writing rows

Prefer one multi-row `INSERT … ON DUPLICATE KEY UPDATE` over `$wpdb->replace()`: replace is
DELETE+INSERT, so it burns auto-increment values and costs one round trip per row.

`$wpdb->prepare()` binds **values, not identifiers**. Table and column names — anything reaching
`ORDER BY` from `$_GET` — need an allowlist:

```php
$orderby = in_array( $requested, self::SORTABLE, true ) ? $requested : 'code';
```

Bind decimals as `%s`, not `%f`: `%f` casts through a PHP float and destroys exactly the
precision a `DECIMAL` column exists to preserve. Reading back, do not cast either — mysqlnd
returns DECIMAL as a string.

## Lifecycle hooks

| Hook | Does | Never does |
|---|---|---|
| `register_activation_hook` | create tables, seed data, schedule events | assume it runs on plugin *update* — it does not |
| `register_deactivation_hook` | unschedule events | **drop tables** — deactivation is not uninstall, and this is real data loss |
| `uninstall.php` | drop tables and options | assume the plugin is loaded — it is not |

Because activation does not fire on update, check a stored schema version on `plugins_loaded` and
re-run the installer when it lags.

## Admin

### `WP_List_Table`

A private core class (`@access private`) with a specific set of traps:

- `require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` **inside the
  `load-{$hook}` callback**, not at file level — at file level it breaks WP-CLI and the front end
- instantiate the table in that same callback, or `add_screen_option( 'per_page' )` is never
  registered and the user's choice is silently ignored
- `prepare_items()` must set `$this->_column_headers`
- `search_box()` only works inside `<form method="get">` with a hidden `page` field

### Settings and actions

- Settings API for options pages; `register_setting` with an explicit `sanitize_callback`
- action buttons go through `admin_post_*`, guarded by both `current_user_can()` **and**
  `check_admin_referer()` — a nonce proves intent, a capability proves permission, and neither
  substitutes for the other
- never echo a stored secret into a page; render an empty password field and treat empty as
  "unchanged"
- store secrets in their own option with `autoload = 'no'` so they stay out of `alloptions`

## Scheduled events

- register `add_action` for the hook **unconditionally**, never inside `is_admin()`: a cron run is
  a front-end request with `DOING_CRON`, so a handler registered only for admin never fires
- guard scheduling with `wp_next_scheduled()`
- self-heal on `init`: events vanish on database restores and can be deleted by hand
- make the job idempotent — a lock plus a freshness check, so a double click or an overlapping
  run does not do the work twice
- schedule a one-off run a few seconds out at activation, or the first result appears only after
  the full interval and the feature looks broken

## WP-CLI

Register inside `if ( defined( 'WP_CLI' ) && WP_CLI )`. Return non-zero with `WP_CLI::error()` on
failure — an exit code is what a script can act on. Support `--format=table|json|csv` through
`WP_CLI\Utils\format_items()` for anything list-shaped.

## Escaping and sanitizing

Sanitize on input, escape on output, every time — not once at the boundary:

| Context | Function |
|---|---|
| HTML text | `esc_html()` |
| Attribute | `esc_attr()` |
| URL | `esc_url()` |
| Textarea | `esc_textarea()` |
| Free text in | `sanitize_text_field()` |
| Key/slug in | `sanitize_key()` |

## Packaging

Ship a directory whose name matches the main file's slug, with a proper plugin header, a
`readme.txt`, `uninstall.php`, and no development files — no `tests/`, no `node_modules/`, no
`composer.json` unless a runtime dependency genuinely ships with it. Verify by installing the zip
on a **different** site through Plugins → Add New → Upload.
