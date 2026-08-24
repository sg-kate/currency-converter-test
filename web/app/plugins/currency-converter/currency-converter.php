<?php
/**
 * Plugin Name:       Currency Converter
 * Description:       Stores exchange rates from freecurrencyapi.com and converts prices between currencies.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Katsiaryna Drozd
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       currency-converter
 * Domain Path:       /languages
 *
 * @package Currency_Converter
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CURRENCY_CONVERTER_VERSION' ) ) {
	define( 'CURRENCY_CONVERTER_VERSION', '0.1.0' );
}

if ( ! defined( 'CURRENCY_CONVERTER_FILE' ) ) {
	define( 'CURRENCY_CONVERTER_FILE', __FILE__ );
}

if ( ! defined( 'CURRENCY_CONVERTER_DIR' ) ) {
	define( 'CURRENCY_CONVERTER_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'CURRENCY_CONVERTER_URL' ) ) {
	define( 'CURRENCY_CONVERTER_URL', plugin_dir_url( __FILE__ ) );
}

require_once __DIR__ . '/autoload.php';

// Functions are not autoloadable, and `currency_converter()` is the module's public entry
// point — it has to exist for anything loaded after this file, which is every theme and
// every other plugin. Nothing in it runs until it is called.
require_once __DIR__ . '/functions.php';

register_activation_hook( __FILE__, array( 'Drozd\Currency\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Drozd\Currency\Plugin', 'deactivate' ) );

Drozd\Currency\Plugin::boot();
