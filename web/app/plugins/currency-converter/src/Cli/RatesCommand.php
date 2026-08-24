<?php
/**
 * `wp currency rates`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cli;

use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Plugin;
use Drozd\Currency\Service\FixtureLoader;
use Drozd\Currency\Service\RateUpdater;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch, list and inspect the stored exchange rates.
 *
 * Every subcommand here reports what happened rather than what it hoped for. A sync that was
 * skipped says it was skipped and why; a sync that failed exits non-zero, because an exit
 * code is the only part of this a script can act on.
 */
final class RatesCommand {

	/**
	 * Fetch the latest rates and store them.
	 *
	 * Ordinarily bounded to one fetch a day: the freshness window in `RateUpdater` refuses a
	 * second one and says so, spending no quota. That bound is the whole reason a monthly
	 * allowance of 5,000 requests is never in danger, so `--force` is the deliberate way past
	 * it and not the default.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Ignore the 23-hour freshness window and fetch anyway. Spends one API request. The
	 * lock still applies, so two forced runs at once still make one request.
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency rates update
	 *     wp currency rates update --force
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		unset( $args );

		if ( ! ApiKey::is_configured() ) {
			WP_CLI::error( 'No API key is configured. Set FREECURRENCYAPI_KEY in .env, or save one on the settings screen.' );
		}

		$force = (bool) Utils\get_flag_value( $assoc_args, 'force', false );

		try {
			$result = RateUpdater::from_config()->update_rates( $force );
		} catch ( \Throwable $e ) {
			// Non-zero exit. A sync that could not reach the API is a failure, and a caller
			// that cannot tell it from a skip will happily build on rates that never arrived.
			WP_CLI::error( $e->getMessage() );

			return;
		}

		if ( $result->is_updated() ) {
			WP_CLI::success( $result->message() );

			return;
		}

		// Not a failure and not a success: nothing was fetched, on purpose. `log` rather than
		// `warning`, because being inside the freshness window is the system working.
		WP_CLI::log( $result->message() );
	}

	/**
	 * List the stored rates.
	 *
	 * ## OPTIONS
	 *
	 * [--base=<code>]
	 * : Only rates quoted against this base currency.
	 *
	 * [--search=<term>]
	 * : Only rates whose base or target code contains this.
	 *
	 * [--orderby=<column>]
	 * : Sort column.
	 * ---
	 * default: target_code
	 * options:
	 *   - base_code
	 *   - target_code
	 *   - rate
	 *   - fetched_at
	 * ---
	 *
	 * [--order=<direction>]
	 * : Sort direction.
	 * ---
	 * default: asc
	 * options:
	 *   - asc
	 *   - desc
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Columns to show.
	 * ---
	 * default: base_code,target_code,name,rate,fetched_at
	 * ---
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
	 *     wp currency rates list
	 *     wp currency rates list --format=count
	 *     wp currency rates list --search=EUR --format=csv
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$repository = new WpdbRateRepository();

		$rates = $repository->all(
			array(
				'base_code' => (string) Utils\get_flag_value( $assoc_args, 'base', '' ),
				'search'    => (string) Utils\get_flag_value( $assoc_args, 'search', '' ),
				'orderby'   => (string) Utils\get_flag_value( $assoc_args, 'orderby', 'target_code' ),
				'order'     => (string) Utils\get_flag_value( $assoc_args, 'order', 'asc' ),
				// No limit. This is the complete dump the admin table's paging cannot be —
				// collision C2 names it as what anyone who needs every row in one file uses.
				'per_page'  => 0,
			)
		);

		$names = Currencies::CODES;
		$rows  = array();

		foreach ( $rates as $rate ) {
			$code = $rate->target_code();

			$rows[] = array(
				'base_code'   => $rate->base_code(),
				'target_code' => $code,
				'name'        => isset( $names[ $code ] ) ? $names[ $code ] : '',
				// The stored decimal string, untouched. `--format=csv` piped into a
				// spreadsheet must carry the digits the column holds, not a float's idea
				// of them.
				'rate'        => $rate->value(),
				'fetched_at'  => $rate->fetched_at_string( '' ),
			);
		}

		if ( array() === $rows && 'count' !== Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::log( 'No rates are stored. Run `wp currency rates update`, or `wp currency rates load-fixture` to try it without a key.' );

			return;
		}

		$fields = Utils\get_flag_value( $assoc_args, 'fields', 'base_code,target_code,name,rate,fetched_at' );

		Utils\format_items(
			(string) Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			is_string( $fields ) ? explode( ',', $fields ) : $fields
		);
	}

	/**
	 * Show what is stored and when it was last refreshed.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency rates status
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );

		$repository = new WpdbRateRepository();
		$fetched_at = $repository->last_fetched_at();
		$next_run   = wp_next_scheduled( Plugin::CRON_HOOK_RATES );
		$demo       = DemoMode::details();

		$rows = array(
			array(
				'field' => 'stored_rates',
				'value' => (string) $repository->count(),
			),
			array(
				'field' => 'currencies_served',
				'value' => (string) Currencies::count(),
			),
			array(
				'field' => 'last_fetched_at',
				'value' => $fetched_at instanceof \DateTimeImmutable
					? $fetched_at->format( Rate::DATETIME_FORMAT ) . ' UTC'
					: 'never',
			),
			array(
				'field' => 'next_scheduled',
				'value' => is_int( $next_run ) && $next_run > 0
					? gmdate( Rate::DATETIME_FORMAT, $next_run ) . ' UTC'
					: 'not scheduled',
			),
			array(
				'field' => 'api_key',
				'value' => ApiKey::is_configured()
					? ( ApiKey::is_from_environment() ? 'set (environment)' : 'set (stored)' )
					: 'missing',
			),
			array(
				'field' => 'data_source',
				// The line that stops a demo being mistaken for live data a week later.
				'value' => null === $demo
					? 'live API'
					: sprintf( 'FIXTURE — %s (%s)', DemoMode::warning(), $demo['source'] ),
			),
		);

		Utils\format_items(
			(string) Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			array( 'field', 'value' )
		);

		if ( null !== $demo ) {
			WP_CLI::warning( DemoMode::warning() . ' Run `wp currency rates update` to replace them.' );
		}
	}

	/**
	 * Fill the tables from a JSON fixture, for demonstrating the module without an API key.
	 *
	 * This is a convenience, not a substitute for a sync, and it is built so that the two can
	 * never be confused. No request is made and nothing imitates one: the rows carry the
	 * fixture's own capture date rather than the current time, no "last sync" timestamp is
	 * written, the quota reading is untouched, and demo mode is switched on — which puts a
	 * banner on the rates screen and a warning on `wp currency rates status` until a real sync
	 * replaces the data.
	 *
	 * Because the rows are dated in the past, loading a fixture never delays the first real
	 * sync: `RateUpdater` sees stale rates and fetches.
	 *
	 * Available only while `WP_DEBUG` is on. On a production site there is no situation in
	 * which the right answer is to write invented rates into the table people are reading.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Fixture to load. Accepts the bundled demo shape and a raw `/v1/latest` capture.
	 * Defaults to the plugin's own fixtures/demo.json. Written as prose rather than as a
	 * `default:` block on purpose — WP-CLI injects such a block's text as the literal value,
	 * so a described default becomes a filename nobody can open.
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency rates load-fixture
	 *     wp currency rates load-fixture --file=tests/Fixtures/latest.json
	 *
	 * @subcommand load-fixture
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function load_fixture( $args, $assoc_args ) {
		unset( $args );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			WP_CLI::error(
				'`load-fixture` needs WP_DEBUG. It writes invented rates into the table, which is a development convenience and never a production one.'
			);
		}

		$path = (string) Utils\get_flag_value( $assoc_args, 'file', FixtureLoader::default_path() );

		// A relative --file is resolved against the working directory, which is what someone
		// typing `--file=tests/Fixtures/latest.json` means.
		if ( '' !== $path && '/' !== substr( $path, 0, 1 ) ) {
			$resolved = realpath( $path );
			$path     = false === $resolved ? $path : $resolved;
		}

		try {
			$loaded = FixtureLoader::from_config()->load( $path );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );

			return;
		}

		WP_CLI::log(
			sprintf(
				'Loaded %d rates and %d currencies from %s.',
				$loaded['rates'],
				$loaded['currencies'],
				$loaded['source']
			)
		);

		WP_CLI::log(
			sprintf(
				'Rows are dated %s UTC, which is what the fixture states — they are not, and do not claim to be, current.',
				$loaded['captured_at']->format( Rate::DATETIME_FORMAT )
			)
		);

		if ( array() !== $loaded['missing'] ) {
			WP_CLI::log(
				sprintf(
					'%d predefined codes are not in the fixture: %s',
					count( $loaded['missing'] ),
					implode( ', ', $loaded['missing'] )
				)
			);
		}

		// `warning`, not `success`. Nothing was synced; a file was read.
		WP_CLI::warning( DemoMode::warning() . ' Run `wp currency rates update` for live data.' );
	}
}
