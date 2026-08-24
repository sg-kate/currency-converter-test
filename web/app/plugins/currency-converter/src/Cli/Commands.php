<?php
/**
 * WP-CLI command registration.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp currency …`, and only when there is a WP-CLI to register it with.
 *
 * The guard is not defensive tidiness. `WP_CLI::add_command()` does not exist in a web
 * request, so calling it unguarded from `Plugin::boot()` — which runs on every request — is a
 * fatal error on every page of the site. The class files themselves are only ever loaded by
 * the autoloader when one of them is named, which happens inside this guard, so a web request
 * never even reads them.
 *
 * The `currency` namespace is created implicitly by registering commands beneath it; there is
 * no parent class to write, and inventing one would only add a `wp currency` that does
 * nothing.
 */
final class Commands {

	/**
	 * Register the commands.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'currency rates', RatesCommand::class );
		WP_CLI::add_command( 'currency currencies', CurrenciesCommand::class );
		WP_CLI::add_command( 'currency convert', ConvertCommand::class );
		WP_CLI::add_command( 'currency doctor', DoctorCommand::class );
	}
}
