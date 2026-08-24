<?php
/**
 * `wp currency doctor`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cli;

use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Api\FreeCurrencyApiClient;
use Drozd\Currency\Currencies;
use Drozd\Currency\Db\Schema;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Plugin;
use Drozd\Currency\Service\RateUpdater;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that has to be true for the module to work, checked and reported.
 *
 * The list is the set of things that have actually gone wrong or plausibly can: a missing
 * extension, a table that was never created, a key that is absent, an event that fell out of
 * the cron array, rates that stopped refreshing, and a fixture that somebody forgot they
 * loaded.
 *
 * **It reports what it checked, not what it assumes.** A check that cannot be made from here
 * says so rather than passing. In particular this command runs in the WP-CLI container, whose
 * PHP extension set is *not* the web container's — `CLAUDE.md` trap 2, and the reason the
 * `bcmath` row names the runtime it looked at. A pass here is a pass for this runtime and
 * says nothing about the other one, and the output says that too.
 *
 * Exits non-zero if anything failed, so it is usable in CI and in a deploy script.
 */
final class DoctorCommand {

	/**
	 * A check that passed.
	 */
	const OK = 'OK';

	/**
	 * A check that found something worth knowing but not something broken.
	 */
	const WARN = 'WARN';

	/**
	 * A check that found the module unable to do its job.
	 */
	const FAIL = 'FAIL';

	/**
	 * Check the module's installation and configuration.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency doctor
	 *     wp currency doctor --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments; none.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$checks = array_merge(
			$this->runtime_checks(),
			$this->schema_checks(),
			$this->credential_checks(),
			$this->data_checks(),
			$this->schedule_checks()
		);

		Utils\format_items(
			(string) Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$checks,
			array( 'check', 'status', 'detail' )
		);

		$failed = array_filter( $checks, static fn( array $check ) => self::FAIL === $check['status'] );
		$warned = array_filter( $checks, static fn( array $check ) => self::WARN === $check['status'] );

		if ( array() !== $failed ) {
			WP_CLI::error(
				sprintf( '%d of %d checks failed.', count( $failed ), count( $checks ) )
			);

			return;
		}

		if ( array() !== $warned ) {
			WP_CLI::warning( sprintf( '%d checks need attention.', count( $warned ) ) );
		}

		WP_CLI::success( sprintf( '%d checks passed.', count( $checks ) - count( $warned ) ) );
	}

	/**
	 * PHP extensions, in the runtime this command is running in.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}> Check results.
	 */
	private function runtime_checks() {
		$checks = array();

		$checks[] = self::result(
			'php.bcmath',
			function_exists( 'bcmul' ) ? self::OK : self::FAIL,
			function_exists( 'bcmul' )
				// Named explicitly, because the answer differs between this project's two
				// images and a pass in one says nothing about the other.
				? 'loaded in this runtime (' . PHP_SAPI . '); check the web container separately'
				: 'missing — rates are DECIMAL(24,12) and float arithmetic cannot hold them'
		);

		$checks[] = self::result(
			'php.intl',
			extension_loaded( 'intl' ) ? self::WARN : self::OK,
			extension_loaded( 'intl' )
				// Backwards on purpose: the hazard is *depending* on intl, because it is in
				// the CLI image and not the web one. Its presence here is the trap, not a pass.
				? 'present here but absent from the web image — nothing in the module may use NumberFormatter'
				: 'absent, as expected; formatting uses number_format_i18n()'
		);

		return $checks;
	}

	/**
	 * Tables and schema version.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}> Check results.
	 */
	private function schema_checks() {
		global $wpdb;

		$checks = array();

		$tables = array(
			'rates'      => Schema::rates_table(),
			'currencies' => Schema::currencies_table(),
		);

		foreach ( $tables as $label => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A schema probe; there is nothing to cache and no cache to invalidate.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			$checks[] = self::result(
				'db.table.' . $label,
				$found === $table ? self::OK : self::FAIL,
				$found === $table ? $table : $table . ' does not exist — deactivate and reactivate the plugin'
			);
		}

		$installed = (string) get_option( Schema::VERSION_OPTION, '' );

		$checks[] = self::result(
			'db.schema_version',
			Schema::VERSION === $installed ? self::OK : self::WARN,
			Schema::VERSION === $installed
				? $installed
				: sprintf( 'installed %s, shipped %s — it upgrades on the next page load', '' === $installed ? 'none' : $installed, Schema::VERSION )
		);

		return $checks;
	}

	/**
	 * The API key and what the last authenticated response said about the quota.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}> Check results.
	 */
	private function credential_checks() {
		$checks = array();

		$configured = ApiKey::is_configured();

		$checks[] = self::result(
			'api.key',
			$configured ? self::OK : self::FAIL,
			$configured
				// The hint and never the key: this output gets pasted into issues.
				? sprintf( '%s, from the %s', ApiKey::hint(), ApiKey::is_from_environment() ? 'environment' : 'database' )
				: 'not configured — set FREECURRENCYAPI_KEY in .env or save one on the settings screen'
		);

		if ( $configured && ApiKey::is_from_environment() && ApiKey::is_stored() ) {
			$checks[] = self::result(
				'api.key.duplicate',
				self::WARN,
				'a key is also stored in the database, where it is overridden and doing nothing'
			);
		}

		$quota = FreeCurrencyApiClient::stored_quota();

		$checks[] = self::result(
			'api.quota',
			is_array( $quota ) && isset( $quota['remaining'] ) && (int) $quota['remaining'] < 100 ? self::WARN : self::OK,
			is_array( $quota ) && isset( $quota['remaining'] )
				? sprintf( '%d requests remaining this month', (int) $quota['remaining'] )
				// Not a failure and not zero: no authenticated response has been seen.
				: 'not known — no authenticated response has been seen yet'
		);

		return $checks;
	}

	/**
	 * What is in the tables, and where it came from.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}> Check results.
	 */
	private function data_checks() {
		$repository = new WpdbRateRepository();
		$stored     = $repository->count();
		$fetched_at = $repository->last_fetched_at();
		$expected   = Currencies::count();

		$checks = array();

		$checks[] = self::result(
			'data.rates',
			0 === $stored ? self::FAIL : ( $stored < $expected ? self::WARN : self::OK ),
			0 === $stored
				? 'no rates stored — run `wp currency rates update`'
				: sprintf( '%d stored, %d currencies served', $stored, $expected )
		);

		if ( null === $fetched_at ) {
			$checks[] = self::result( 'data.age', self::FAIL, 'never fetched' );
		} else {
			$age = time() - $fetched_at->getTimestamp();

			$checks[] = self::result(
				'data.age',
				$age > 2 * DAY_IN_SECONDS ? self::WARN : self::OK,
				sprintf( '%s old', human_time_diff( $fetched_at->getTimestamp() ) )
			);
		}

		$demo = DemoMode::details();

		$checks[] = self::result(
			'data.source',
			null === $demo ? self::OK : self::WARN,
			null === $demo
				? 'live API'
				: sprintf( '%s loaded from %s', DemoMode::warning(), $demo['source'] )
		);

		return $checks;
	}

	/**
	 * The scheduled events, and whether anything will actually run them.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}> Check results.
	 */
	private function schedule_checks() {
		$checks = array();

		$hooks = array(
			'cron.rates'      => Plugin::CRON_HOOK_RATES,
			'cron.currencies' => Plugin::CRON_HOOK_CURRENCIES,
		);

		foreach ( $hooks as $label => $hook ) {
			$next = wp_next_scheduled( $hook );

			$checks[] = self::result(
				$label,
				is_int( $next ) && $next > 0 ? self::OK : self::WARN,
				is_int( $next ) && $next > 0
					? sprintf( 'due in %s', human_time_diff( $next ) )
					: 'not scheduled — the init self-heal re-creates it on the next request'
			);
		}

		// The check that catches the configuration this whole stack depends on. With
		// DISABLE_WP_CRON true and nothing else running events, a perfectly scheduled event
		// runs never — and `wp config get DISABLE_WP_CRON` reports nothing here, because
		// Bedrock defines constants at runtime. Read the constant, not the file.
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$checks[] = self::result(
			'cron.runner',
			$disabled ? self::WARN : self::OK,
			$disabled
				? 'DISABLE_WP_CRON is true — something external must run events (this stack: the `cron` container)'
				: 'wp-cron runs on page loads'
		);

		$checks[] = self::result(
			'cron.freshness_window',
			self::OK,
			sprintf( '%d hours — a second sync inside it is refused', (int) ( RateUpdater::FRESHNESS_WINDOW / 3600 ) )
		);

		return $checks;
	}

	/**
	 * Build one result row.
	 *
	 * @param string $check  Check name.
	 * @param string $status One of OK, WARN, FAIL.
	 * @param string $detail What was found.
	 * @return array{check: string, status: string, detail: string} The row.
	 */
	private static function result( $check, $status, $detail ) {
		return array(
			'check'  => $check,
			'status' => $status,
			'detail' => $detail,
		);
	}
}
