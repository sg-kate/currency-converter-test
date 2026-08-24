<?php
/**
 * Loading demonstration rates from a file.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Service;

use Brain\Monkey\Functions;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Service\FixtureLoader;
use Drozd\Currency\Service\RateUpdater;
use Tests\Fakes\FakeWpdb;
use Tests\Fakes\InMemoryRateRepository;
use Tests\TestCase;

/**
 * What keeps a convenience from turning into a fake.
 *
 * The interesting assertions here are not about rows being written — that is the repository's
 * job and it has its own tests. They are about the two things that stop fixture data being
 * mistaken for a fetch: every row is dated in the past whatever the file claims, and the load
 * announces itself through `DemoMode`.
 *
 * The date clamp is the one with teeth. `RateUpdater` decides whether to sync by comparing the
 * newest `fetched_at` against the freshness window, so a fixture dated "now" would report the
 * rates as fresh and suppress the first real sync for a day — a demonstration aid becoming the
 * reason the live data never arrived.
 */
final class FixtureLoaderTest extends TestCase {

	/**
	 * Rate storage.
	 */
	private InMemoryRateRepository $rates;

	/**
	 * Currency metadata storage, on a fake `$wpdb`.
	 */
	private \Drozd\Currency\Db\WpdbCurrencyRepository $currencies;

	/**
	 * Options as the fakes see them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Fixture files written for a test, deleted afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $written = array();

	protected function set_up(): void {
		parent::set_up();

		$this->rates      = new InMemoryRateRepository();
		$this->currencies = new \Drozd\Currency\Db\WpdbCurrencyRepository( new FakeWpdb() );
		$this->options    = array();
		$this->written    = array();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default_value = false ) => $this->options[ $key ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );

				return true;
			}
		);
	}

	protected function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tear_down();
	}

	/**
	 * A fixture claiming to be current is stored as stale anyway.
	 */
	public function test_a_fixture_dated_now_is_still_stored_in_the_past(): void {
		$path = $this->write_fixture(
			array(
				'captured_at' => gmdate( 'Y-m-d H:i:s' ),
				'rates'       => array( 'EUR' => 0.9 ),
			)
		);

		$loaded = $this->loader()->load( $path );

		$age = time() - $loaded['captured_at']->getTimestamp();

		$this->assertGreaterThanOrEqual( FixtureLoader::MINIMUM_AGE, $age );
		$this->assertGreaterThan(
			RateUpdater::FRESHNESS_WINDOW,
			$age,
			'a fixture must never be fresh enough to make RateUpdater skip a real sync'
		);
	}

	/**
	 * A fixture dated genuinely in the past keeps its own date.
	 *
	 * The clamp is a ceiling, not a replacement: the point is that the rows say when the data
	 * is from, and overwriting an honest old date with a computed one would lose that.
	 */
	public function test_an_older_fixture_keeps_the_date_it_states(): void {
		$stated = '2026-08-01 12:00:00';

		$path = $this->write_fixture(
			array(
				'captured_at' => $stated,
				'rates'       => array( 'EUR' => 0.9 ),
			)
		);

		$loaded = $this->loader()->load( $path );

		$this->assertSame( $stated, $loaded['captured_at']->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Every stored row carries the fixture's date, not the current time.
	 *
	 * Asserted against the values bound into the `INSERT`, because that is where the answer
	 * actually is — the in-memory fake keeps rate strings and stamps its own timestamp on the
	 * way out, so it cannot tell this apart.
	 */
	public function test_the_rows_are_stamped_with_the_fixture_date(): void {
		$wpdb  = new FakeWpdb();
		$rates = new \Drozd\Currency\Db\WpdbRateRepository( $wpdb );

		$path = $this->write_fixture(
			array(
				'captured_at' => '2026-08-01 12:00:00',
				'rates'       => array(
					'EUR' => 0.9,
					'RUB' => 90.0,
				),
			)
		);

		( new FixtureLoader( $rates, $this->currencies ) )->load( $path );

		$bound = $wpdb->last_prepared_args();
		$today = gmdate( 'Y-m-d' );

		$this->assertContains( '2026-08-01 12:00:00', $bound );

		foreach ( $bound as $value ) {
			$this->assertStringNotContainsString(
				$today,
				(string) $value,
				'no row may be stamped with the current date — that is what a fetch looks like'
			);
		}
	}

	/**
	 * Loading announces itself, and names what was loaded.
	 */
	public function test_loading_switches_on_demo_mode(): void {
		$path = $this->write_fixture(
			array(
				'captured_at' => '2026-08-01 12:00:00',
				'rates'       => array( 'EUR' => 0.9 ),
			)
		);

		$this->assertFalse( DemoMode::is_active() );

		$this->loader()->load( $path );

		$this->assertTrue( DemoMode::is_active() );

		$details = DemoMode::details();

		$this->assertNotNull( $details );
		$this->assertSame( basename( $path ), $details['source'] );
	}

	/**
	 * Nothing is written that would imitate a completed fetch.
	 *
	 * A "last sync" timestamp is what the settings screen prints as when the API was last
	 * reached. A fixture load that wrote one would be claiming a request that never happened.
	 */
	public function test_loading_does_not_write_a_last_sync_timestamp(): void {
		$path = $this->write_fixture(
			array(
				'captured_at' => '2026-08-01 12:00:00',
				'rates'       => array( 'EUR' => 0.9 ),
			)
		);

		$this->loader()->load( $path );

		$this->assertArrayNotHasKey( RateUpdater::LAST_SYNC_OPTION, $this->options );
		$this->assertArrayNotHasKey( 'currency_converter_quota', $this->options );
	}

	/**
	 * A raw `/v1/latest` capture is accepted too, so the test fixtures can be loaded directly.
	 */
	public function test_it_reads_a_raw_api_capture(): void {
		$path = $this->write_fixture(
			array(
				'data' => array(
					'EUR' => 0.9145678901,
					'RUB' => 93.0071234567,
				),
			)
		);

		$loaded = $this->loader()->load( $path );

		// Two rates plus the identity row the repository always writes.
		$this->assertSame( 3, $loaded['rates'] );
	}

	/**
	 * A file with nothing usable in it is an error, not an empty success.
	 */
	public function test_a_fixture_without_rates_is_rejected(): void {
		$path = $this->write_fixture( array( 'captured_at' => '2026-08-01 12:00:00' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No rates found' );

		$this->loader()->load( $path );
	}

	/**
	 * A missing file is an error too.
	 */
	public function test_a_missing_file_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot read' );

		$this->loader()->load( '/nonexistent/fixture.json' );
	}

	/**
	 * The loader under test.
	 *
	 * @return FixtureLoader Configured loader.
	 */
	private function loader(): FixtureLoader {
		return new FixtureLoader( $this->rates, $this->currencies );
	}

	/**
	 * Write a fixture to a temporary file.
	 *
	 * @param array<string, mixed> $payload What to write.
	 * @return string Absolute path to the file.
	 */
	private function write_fixture( array $payload ): string {
		$path = tempnam( sys_get_temp_dir(), 'cc-fixture-' ) . '.json';

		file_put_contents( $path, (string) json_encode( $payload ) );

		$this->written[] = $path;

		return $path;
	}
}
