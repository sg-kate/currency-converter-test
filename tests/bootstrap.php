<?php
/**
 * PHPUnit bootstrap.
 *
 * The autoloader has to be required on the FIRST line that does any work.
 * Brain\Monkey redefines WordPress functions through Patchwork, and Patchwork can
 * only intercept files loaded after itself. Load anything testable before this
 * and the redefinitions silently do nothing — the tests then fail in ways that
 * point everywhere except here.
 *
 * @package Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * Everything below runs AFTER Patchwork is in place, which is the only order that works.
 *
 * The plugin guards every file with `defined( 'ABSPATH' ) || exit;`, so ABSPATH has to
 * exist before its autoloader is required — otherwise the bootstrap exits silently and
 * PHPUnit reports no tests rather than an error. The value is never dereferenced here;
 * nothing under test reads a file from it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/web/wp/' );
}

/*
 * `ARRAY_A` is defined by WordPress in wp-includes/class-wpdb.php, which no unit test
 * loads. The repositories pass it to `get_results()` and `get_row()` as WordPress code is
 * expected to, so it has to exist here or every repository test dies on an undefined
 * constant. The value is WordPress's own.
 */
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/*
 * Same reason as ARRAY_A: `Cron\Scheduler` anchors its recurring events with WordPress's
 * own time constants from wp-includes/default-constants.php, which no unit test loads.
 * The values are WordPress's own.
 */
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * 3600 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * 24 * 3600 );
}

/*
 * `config/application.php` defines this from `.env` and falls back to an empty string, so a
 * checkout with no key still has the constant. Defined the same way here, because that is the
 * state `Api\ApiKey` has to get right: defined-but-empty must not read as "the environment
 * supplies the key". A constant cannot be redefined within a process, so the non-empty case
 * is verified against the running stack instead of here.
 */
if ( ! defined( 'FREECURRENCYAPI_KEY' ) ) {
	define( 'FREECURRENCYAPI_KEY', '' );
}

/*
 * The plugin ships with its own PSR-4 autoloader and has no Composer runtime
 * dependencies, so its classes are not in vendor/. Registering the plugin's real
 * autoloader — rather than adding a second mapping to composer.json — means the tests
 * exercise the same loading path the shipped zip uses.
 *
 * It registers a lazy spl_autoload_register, so no testable class is loaded here. Class
 * files still load on first use, well after Patchwork.
 */
require_once dirname( __DIR__ ) . '/web/app/plugins/currency-converter/autoload.php';
