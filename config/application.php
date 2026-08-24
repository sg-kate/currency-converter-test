<?php

/**
 * Base configuration. Environment-specific overrides live in
 * config/environments/{{WP_ENV}}.php and are loaded at the end of this file.
 *
 * Deviate from this file as little as possible: anything defined here applies
 * everywhere, which is what makes environments comparable.
 */

use Roots\WPConfig\Config;

use function Env\env;

/**
 * Directory containing all of the site's files.
 */
$root_dir = dirname(__DIR__);

/**
 * Document root.
 */
$webroot_dir = $root_dir . '/web';

/**
 * Load the .env file. Values already present in the real environment win, so a
 * container can override anything without the file being edited.
 */
if (file_exists($root_dir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable($root_dir, ['.env'], false);
    $dotenv->load();
    $dotenv->required(['WP_HOME', 'WP_SITEURL']);
    $dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD']);
}

/**
 * Environment. Defaults to production so that a missing WP_ENV never
 * accidentally enables debugging.
 */
Config::define('WP_ENV', env('WP_ENV') ?: 'production');
Config::define('WP_ENVIRONMENT_TYPE', Config::get('WP_ENV'));

/**
 * URLs. Core lives in its own directory, so the site URL carries the /wp suffix
 * while the home URL does not.
 */
Config::define('WP_HOME', env('WP_HOME'));
Config::define('WP_SITEURL', env('WP_SITEURL'));

/**
 * Custom content directory: wp-content becomes web/app, outside of core.
 */
Config::define('CONTENT_DIR', '/app');
Config::define('WP_CONTENT_DIR', $webroot_dir . Config::get('CONTENT_DIR'));
Config::define('WP_CONTENT_URL', Config::get('WP_HOME') . Config::get('CONTENT_DIR'));

/**
 * Database.
 */
Config::define('DB_NAME', env('DB_NAME'));
Config::define('DB_USER', env('DB_USER'));
Config::define('DB_PASSWORD', env('DB_PASSWORD'));
Config::define('DB_HOST', env('DB_HOST') ?: 'localhost');
Config::define('DB_CHARSET', 'utf8mb4');
Config::define('DB_COLLATE', '');
$table_prefix = env('DB_PREFIX') ?: 'wp_'; // phpcs:ignore

/**
 * Authentication keys and salts. Generated into .env by bin/bootstrap.sh.
 */
Config::define('AUTH_KEY', env('AUTH_KEY'));
Config::define('SECURE_AUTH_KEY', env('SECURE_AUTH_KEY'));
Config::define('LOGGED_IN_KEY', env('LOGGED_IN_KEY'));
Config::define('NONCE_KEY', env('NONCE_KEY'));
Config::define('AUTH_SALT', env('AUTH_SALT'));
Config::define('SECURE_AUTH_SALT', env('SECURE_AUTH_SALT'));
Config::define('LOGGED_IN_SALT', env('LOGGED_IN_SALT'));
Config::define('NONCE_SALT', env('NONCE_SALT'));

/**
 * Core behaviour.
 *
 * Updates are disabled because Composer owns core, plugins and themes: an
 * in-place update from the admin would be silently reverted by the next deploy.
 */
Config::define('AUTOMATIC_UPDATER_DISABLED', true);
Config::define('DISALLOW_FILE_EDIT', true);
Config::define('DISALLOW_FILE_MODS', true);
Config::define('WP_POST_REVISIONS', env('WP_POST_REVISIONS') ?: true);
Config::define('WP_DEFAULT_THEME', 'test-theme');

/**
 * WP-Cron is driven by the cron container, not by page loads.
 */
Config::define('DISABLE_WP_CRON', env('DISABLE_WP_CRON') ?: true);

/**
 * Third-party services.
 *
 * The freecurrencyapi.com key. Empty rather than null when unset, so the currency
 * module can test it with a plain empty check. Never committed: the value lives in
 * .env only. Note that `wp config get` cannot see this — Config::apply() defines it
 * at runtime — so check it with:
 *
 *   bin/wp eval 'echo defined( "FREECURRENCYAPI_KEY" ) ? "set" : "MISSING";'
 */
Config::define('FREECURRENCYAPI_KEY', env('FREECURRENCYAPI_KEY') ?: '');

/**
 * Debugging is off by default; the development environment turns it on.
 */
Config::define('WP_DEBUG', false);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
ini_set('display_errors', '0');

/**
 * Let WordPress detect HTTPS behind a reverse proxy or load balancer.
 *
 * @see https://developer.wordpress.org/reference/functions/is_ssl/
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

/**
 * Environment-specific overrides.
 */
$env_config = __DIR__ . '/environments/' . Config::get('WP_ENV') . '.php';

if (file_exists($env_config)) {
    require_once $env_config;
}

Config::apply();

/**
 * Bootstrap WordPress.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $webroot_dir . '/wp/');
}
