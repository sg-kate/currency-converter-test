<?php
/**
 * `wp currency currencies`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cli;

use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Service\RateUpdater;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The currency metadata: names, symbols and minor units.
 *
 * This describes the currencies the module serves; it never decides which they are. That is
 * `Currencies::CODES` and the `currency_converter_currencies` filter, and a `sync` that finds
 * the API serving codes the list does not carry reports the difference rather than adopting
 * it.
 */
final class CurrenciesCommand {

	/**
	 * Fetch currency names, symbols and minor units, and store them.
	 *
	 * Bounded to once a week rather than once a day. A currency's name changes when a country
	 * redenominates, so a daily fetch would spend a second request every day to write rows
	 * that are already identical — the metadata sync is the cheaper half of the quota story,
	 * and it stays cheap by not running.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Ignore the weekly window and fetch anyway. Spends one API request.
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency currencies sync
	 *     wp currency currencies sync --force
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) {
		unset( $args );

		if ( ! ApiKey::is_configured() ) {
			WP_CLI::error( 'No API key is configured. Set FREECURRENCYAPI_KEY in .env, or save one on the settings screen.' );
		}

		$force = (bool) Utils\get_flag_value( $assoc_args, 'force', false );

		try {
			$result = RateUpdater::from_config()->update_currencies( $force );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );

			return;
		}

		if ( $result->is_updated() ) {
			WP_CLI::success( $result->message() );

			return;
		}

		WP_CLI::log( $result->message() );
	}

	/**
	 * List the currencies the module serves, and what is known about each.
	 *
	 * The rows come from the predefined list, not from the metadata table: a currency the
	 * module serves but has never fetched metadata for still belongs here, with its name from
	 * `Currencies::CODES` and an empty symbol. Listing only what the table holds would make
	 * "which currencies does this module serve" unanswerable until a sync had run.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency currencies list
	 *     wp currency currencies list --format=count
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$stored = array();

		foreach ( ( new WpdbCurrencyRepository() )->all() as $currency ) {
			$stored[ $currency->code() ] = $currency;
		}

		$rows = array();

		foreach ( Currencies::all() as $code => $fallback_name ) {
			$known = isset( $stored[ $code ] ) ? $stored[ $code ] : null;

			$rows[] = array(
				'code'           => $code,
				'name'           => null !== $known && '' !== $known->name() ? $known->name() : $fallback_name,
				'symbol'         => null !== $known ? $known->symbol() : '',
				'decimal_digits' => null !== $known ? (string) $known->decimal_digits() : '',
				'metadata'       => null !== $known ? 'stored' : 'not fetched',
			);
		}

		Utils\format_items(
			(string) Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			array( 'code', 'name', 'symbol', 'decimal_digits', 'metadata' )
		);
	}
}
