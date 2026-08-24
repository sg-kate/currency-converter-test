<?php
/**
 * The sync orchestrator and its two safeguards.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Service;

use Brain\Monkey\Functions;
use Drozd\Currency\Api\AuthenticationException;
use Drozd\Currency\Api\FreeCurrencyApiClient;
use Drozd\Currency\Currencies;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Service\RateUpdater;
use Drozd\Currency\Service\SyncResult;
use Tests\Fakes\FakeHttpClient;
use Tests\Fakes\FakeWpdb;
use Tests\Fixture;
use Tests\TestCase;

/**
 * What stops a sync, and what a sync does when nothing stops it.
 *
 * The safeguards are the subject: the quota is 5,000 a month, the `cron` container asks
 * for due events every minute, and "Update now" is a button people double-click. Every
 * test that asserts `call_count() === 0` is asserting that no quota was spent.
 */
final class RateUpdaterTest extends TestCase {

	private FakeWpdb $wpdb;

	private FakeHttpClient $http;

	/**
	 * Transients, as the fake `get/set/delete_transient` see them.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Options written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * The `$autoload` argument each option was written with.
	 *
	 * Captured here rather than asserted with `Functions\expect()`, because Brain\Monkey
	 * does not allow `when()` and `expect()` on the same function in one test — the alias
	 * wins and the expectation never sees the call.
	 *
	 * @var array<string, mixed>
	 */
	private array $option_autoload = array();

	/**
	 * Options deleted during the test, in order.
	 *
	 * @var array<int, string>
	 */
	private array $deleted_options = array();

	protected function set_up(): void {
		parent::set_up();

		$this->wpdb            = new FakeWpdb();
		$this->transients      = array();
		$this->options         = array();
		$this->option_autoload = array();
		$this->deleted_options = array();

		// The filter is the module's one extension point and is called on every
		// Currencies::codes(); pass the list straight through unless a test says otherwise.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );

				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default_value = false ) => $this->options[ $key ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload = null ): bool {
				$this->options[ $key ]         = $value;
				$this->option_autoload[ $key ] = $autoload;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );
				$this->deleted_options[] = $key;

				return true;
			}
		);
	}

	/**
	 * An updater whose API answers with the given body.
	 *
	 * @param string $fixture Fixture file name.
	 * @param int    $status  HTTP status to answer with.
	 * @return RateUpdater The updater, wired to fakes throughout.
	 */
	private function updater( string $fixture = 'latest.json', int $status = 200 ): RateUpdater {
		$this->http = FakeHttpClient::responding( $status, Fixture::raw( $fixture ) );

		return new RateUpdater(
			new FreeCurrencyApiClient( $this->http, 'fca_live_test0000000000000000000' ),
			new WpdbRateRepository( $this->wpdb ),
			new WpdbCurrencyRepository( $this->wpdb )
		);
	}

	/**
	 * Pretend the newest stored rate was fetched this many seconds ago.
	 *
	 * @param int $seconds Age of the stored data.
	 * @return void
	 */
	private function stored_rates_aged( int $seconds ): void {
		$this->wpdb->will_return_var( gmdate( 'Y-m-d H:i:s', time() - $seconds ) );
	}

	// -- Safeguard 1: the freshness window --------------------------------------------

	public function test_it_skips_when_the_stored_rates_are_younger_than_23_hours(): void {
		$updater = $this->updater();
		$this->stored_rates_aged( 3600 );

		$result = $updater->update_rates();

		$this->assertSame( SyncResult::STATUS_FRESH, $result->status() );
		$this->assertStringStartsWith( 'Skipped: rates are fresh (last sync ', $result->message() );
		$this->assertStringEndsWith( ' UTC)', $result->message() );
		$this->assertSame( 0, $this->http->call_count(), 'A skipped sync must spend no quota.' );
	}

	public function test_it_runs_when_the_stored_rates_are_older_than_23_hours(): void {
		$updater = $this->updater();
		$this->stored_rates_aged( 23 * 3600 + 60 );

		$result = $updater->update_rates();

		$this->assertTrue( $result->is_updated() );
		$this->assertSame( 1, $this->http->call_count() );
	}

	public function test_the_window_is_23_hours_and_not_24(): void {
		// A job that runs at the same time every day drifts by seconds. At 24 hours it
		// would be refused once and then run a day late, for ever.
		$updater = $this->updater();
		$this->stored_rates_aged( 23 * 3600 + 1 );

		$this->assertTrue( $updater->update_rates()->is_updated() );
	}

	public function test_it_runs_when_nothing_is_stored_yet(): void {
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$this->assertTrue( $updater->update_rates()->is_updated() );
	}

	public function test_force_overrides_the_freshness_window(): void {
		$updater = $this->updater();
		$this->stored_rates_aged( 60 );

		$result = $updater->update_rates( true );

		$this->assertTrue( $result->is_updated() );
		$this->assertSame( 1, $this->http->call_count() );
	}

	public function test_a_future_timestamp_counts_as_fresh(): void {
		// A clock wound back, or a row written by a host in another timezone. Treating it
		// as infinitely old would mean a sync on every single run.
		$updater = $this->updater();
		$this->stored_rates_aged( -7200 );

		$this->assertSame( SyncResult::STATUS_FRESH, $updater->update_rates()->status() );
		$this->assertSame( 0, $this->http->call_count() );
	}

	// -- Safeguard 2: the lock ---------------------------------------------------------

	public function test_it_skips_while_another_run_holds_the_lock(): void {
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );
		$this->transients[ RateUpdater::LOCK_RATES ] = '2026-08-24T09:00:00+00:00';

		$result = $updater->update_rates();

		$this->assertSame( SyncResult::STATUS_LOCKED, $result->status() );
		$this->assertSame( 'Skipped: another rate update is already running', $result->message() );
		$this->assertSame( 0, $this->http->call_count() );
	}

	public function test_force_does_not_override_the_lock(): void {
		// --force means "ignore the freshness window", not "run twice at once".
		$updater = $this->updater();
		$this->transients[ RateUpdater::LOCK_RATES ] = '2026-08-24T09:00:00+00:00';

		$this->assertSame( SyncResult::STATUS_LOCKED, $updater->update_rates( true )->status() );
		$this->assertSame( 0, $this->http->call_count() );
	}

	public function test_the_lock_is_released_after_a_successful_run(): void {
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$updater->update_rates();

		$this->assertArrayNotHasKey( RateUpdater::LOCK_RATES, $this->transients );
	}

	public function test_the_lock_is_released_when_the_api_fails(): void {
		// Otherwise one 401 would refuse every retry for a minute, and a fatal would look
		// like a permanently wedged module.
		$updater = $this->updater( 'error-401.json', 401 );
		$this->wpdb->will_return_var( null );

		try {
			$updater->update_rates();
			$this->fail( 'The API failure should have been rethrown.' );
		} catch ( AuthenticationException $e ) {
			$this->assertSame( 401, $e->status() );
		}

		$this->assertArrayNotHasKey( RateUpdater::LOCK_RATES, $this->transients );
	}

	public function test_the_lock_expires_on_its_own(): void {
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$seconds = null;

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiry ) use ( &$seconds ): bool {
				$seconds                  = $expiry;
				$this->transients[ $key ] = $value;

				return true;
			}
		);

		$updater->update_rates();

		$this->assertSame( 60, $seconds, 'A held lock must expire without anyone clearing it.' );
	}

	public function test_a_failed_api_call_records_no_sync_timestamp(): void {
		$updater = $this->updater( 'error-401.json', 401 );
		$this->wpdb->will_return_var( null );

		try {
			$updater->update_rates();
		} catch ( AuthenticationException $e ) {
			unset( $e );
		}

		$this->assertArrayNotHasKey( RateUpdater::LAST_SYNC_OPTION, $this->options );
	}

	// -- Storing --------------------------------------------------------------------

	public function test_it_stores_the_rates_and_records_the_sync(): void {
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$result = $updater->update_rates();

		// The fixture carries 11 codes, USD among them, and the identity row replaces the
		// payload's own — so 11 rows, in one statement.
		$this->assertSame( 11, $result->count() );
		$this->assertStringStartsWith( '11 rates updated', $result->message() );
		$this->assertStringContainsString( 'INSERT INTO `wp_cc_rates`', $this->wpdb->last_query() );
		$this->assertStringContainsString( "'USD', 'USD', '1.000000000000'", $this->wpdb->last_query() );

		// The fixture is a documented shape carrying 11 of the 33 predefined codes, so the
		// other 22 are reported as missing. That is the drift log doing its job, and it is
		// what a truncated fixture is supposed to look like from in here.
		$this->assertSame( array(), $result->unknown_codes() );
		$this->assertCount( 22, $result->missing_codes() );
		$this->assertStringContainsString( '22 predefined codes missing from API: CZK, DKK', $result->message() );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
			$this->options[ RateUpdater::LAST_SYNC_OPTION ]
		);
	}

	public function test_the_sync_timestamp_is_not_autoloaded(): void {
		// Otherwise it joins the alloptions cache on every page load of every request.
		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$updater->update_rates();

		$this->assertSame( 'no', $this->option_autoload[ RateUpdater::LAST_SYNC_OPTION ] );
	}

	/**
	 * A live sync clears the flag that says the stored rates are demonstration data.
	 *
	 * The flag describes the rows, and this call has just overwritten them. Left set, it
	 * would put a "demo data, not live rates" banner over rates that are neither.
	 */
	public function test_a_successful_sync_clears_demo_mode(): void {
		$this->options[ DemoMode::OPTION ] = array(
			'source'      => 'demo.json',
			'captured_at' => '2026-08-01T12:00:00+00:00',
			'loaded_at'   => '2026-08-24T09:00:00+00:00',
		);

		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$this->assertTrue( DemoMode::is_active(), 'the fixture flag should start set' );

		$updater->update_rates();

		$this->assertContains( DemoMode::OPTION, $this->deleted_options );
		$this->assertFalse( DemoMode::is_active() );
	}

	/**
	 * A sync that fetched nothing leaves the flag alone.
	 *
	 * Skipping is not replacing. If a fresh-skip cleared the flag, loading a fixture and then
	 * running the sync twice would silently relabel the fixture rows as live.
	 */
	public function test_a_skipped_sync_leaves_demo_mode_set(): void {
		$this->options[ DemoMode::OPTION ] = array(
			'source'      => 'demo.json',
			'captured_at' => '2026-08-01T12:00:00+00:00',
			'loaded_at'   => '2026-08-24T09:00:00+00:00',
		);

		$updater = $this->updater();
		$this->stored_rates_aged( 3600 );

		$result = $updater->update_rates();

		$this->assertTrue( $result->is_skipped() );
		$this->assertNotContains( DemoMode::OPTION, $this->deleted_options );
		$this->assertTrue( DemoMode::is_active() );
	}

	public function test_the_metadata_timestamp_is_not_autoloaded(): void {
		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 0 );

		$updater->update_currencies();

		$this->assertSame( 'no', $this->option_autoload[ RateUpdater::CURRENCIES_SYNCED_OPTION ] );
	}

	public function test_it_stores_only_predefined_codes_and_reports_both_drifts(): void {
		// The list narrowed to four, one of which the fixture does not carry.
		Functions\when( 'apply_filters' )->justReturn( array( 'USD', 'EUR', 'JPY', 'TRY' ) );

		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$result = $updater->update_rates();

		$this->assertSame( 3, $result->count(), 'USD, EUR and JPY: the intersection, plus no others.' );
		$this->assertSame( array( 'TRY' ), $result->missing_codes() );
		$this->assertSame(
			array( 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'GBP', 'RUB' ),
			$result->unknown_codes()
		);
		$this->assertStringContainsString( '3 rates updated;', $result->message() );
		$this->assertStringContainsString( '8 unknown codes from API: AUD, BGN', $result->message() );
		$this->assertStringContainsString( '1 predefined code missing from API: TRY', $result->message() );
	}

	public function test_the_base_rate_is_stored_even_when_the_list_excludes_it(): void {
		// Nothing may leave the table without a USD => USD row: every cross-rate divides
		// by it. A filtered list that drops USD must not be able to remove it.
		Functions\when( 'apply_filters' )->justReturn( array( 'EUR' ) );

		$updater = $this->updater();
		$this->wpdb->will_return_var( null );

		$updater->update_rates();

		$this->assertStringContainsString( "'USD', 'USD', '1.000000000000'", $this->wpdb->last_query() );
	}

	// -- Currency metadata, on a weekly clock ------------------------------------------

	public function test_metadata_is_fetched_when_the_table_is_empty(): void {
		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 0 );
		$this->options[ RateUpdater::CURRENCIES_SYNCED_OPTION ] = time();

		$result = $updater->update_currencies();

		// Fresh by the clock, but there is nothing in the table: seeding wins.
		$this->assertTrue( $result->is_updated() );
		$this->assertSame( 3, $result->count() );
		$this->assertStringContainsString( 'INSERT INTO `wp_cc_currencies`', $this->wpdb->last_query() );
	}

	public function test_metadata_is_not_fetched_again_within_a_week(): void {
		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 33 );
		$this->options[ RateUpdater::CURRENCIES_SYNCED_OPTION ] = time() - 6 * 24 * 3600;

		$result = $updater->update_currencies();

		$this->assertSame( SyncResult::STATUS_FRESH, $result->status() );
		$this->assertStringStartsWith( 'Skipped: currency metadata is current', $result->message() );
		$this->assertSame( 0, $this->http->call_count(), 'Metadata is static; a daily fetch doubles the spend.' );
	}

	public function test_metadata_is_fetched_again_after_a_week(): void {
		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 33 );
		$this->options[ RateUpdater::CURRENCIES_SYNCED_OPTION ] = time() - 7 * 24 * 3600 - 60;

		$this->assertTrue( $updater->update_currencies()->is_updated() );
		$this->assertSame( 1, $this->http->call_count() );
	}

	public function test_metadata_uses_its_own_lock(): void {
		// A first run after activation refreshes both. One shared lock would deadlock it.
		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 0 );
		$this->transients[ RateUpdater::LOCK_RATES ] = 'held';

		$this->assertTrue( $updater->update_currencies()->is_updated() );
	}

	public function test_metadata_stores_every_currency_the_api_describes(): void {
		// This table describes currencies, it does not decide which exist — so a code the
		// predefined list has not heard of is still stored, and reported as drift.
		Functions\when( 'apply_filters' )->justReturn( array( 'USD' ) );

		$updater = $this->updater( 'currencies.json' );
		$this->wpdb->will_return_var( 0 );

		$result = $updater->update_currencies();

		$this->assertSame( 3, $result->count() );
		$this->assertSame( array( 'EUR', 'JPY' ), $result->unknown_codes() );
	}

	public function test_the_predefined_list_carries_the_currencies_the_brief_names(): void {
		// C5: the brief's own example is convert(123, 'USD', 'RUB').
		$this->assertContains( 'USD', Currencies::codes() );
		$this->assertContains( 'RUB', Currencies::codes() );
	}
}
