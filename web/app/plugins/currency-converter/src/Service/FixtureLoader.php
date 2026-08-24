<?php
/**
 * Loading demonstration rates from a file.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Service;

use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\CurrencyRepositoryInterface;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Domain\RateRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Fills the tables from a JSON file, so the module can be shown working without an API key.
 *
 * This is a convenience and is built to keep reading as one. It shares no code path with
 * `RateUpdater`: there is no client, no request, no quota, no lock and no freshness window,
 * because none of those describe reading a file. What it does share is the storage, so the
 * rows it writes are ordinary rows and the admin screens, the converter and the CLI need to
 * know nothing about where they came from.
 *
 * **Fixture rows are dated in the past, always.** The file states when its data was captured
 * and that is what goes into `fetched_at` — and whatever the file says, the timestamp is
 * clamped to at least {@see self::MINIMUM_AGE} seconds ago. That clamp is the load-bearing
 * part: `RateUpdater` decides whether to sync by looking at the newest `fetched_at`, so a
 * fixture dated "now" would tell it the rates are fresh and suppress the first real sync for
 * a day. A fixture is by definition not current, and dating it as current would turn a
 * demonstration aid into the reason the live data never arrived.
 *
 * Everything it writes is announced. `DemoMode` is set, and stays set until a live sync
 * overwrites the rows.
 */
final class FixtureLoader {

	/**
	 * The bundled demo file, relative to the plugin directory.
	 */
	const DEFAULT_FILE = 'fixtures/demo.json';

	/**
	 * How far in the past fixture rows are dated, at the very least, in seconds.
	 *
	 * Comfortably past `RateUpdater::FRESHNESS_WINDOW`, so loading a fixture can never be
	 * the reason a real sync was skipped.
	 */
	const MINIMUM_AGE = 25 * 3600;

	/**
	 * Largest fixture accepted, in bytes.
	 *
	 * The bundled file is a few kilobytes. This only stops `--file` being pointed at
	 * something that is not a fixture at all.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Rate storage.
	 *
	 * @var RateRepositoryInterface
	 */
	private $rates;

	/**
	 * Currency metadata storage.
	 *
	 * @var CurrencyRepositoryInterface
	 */
	private $currencies;

	/**
	 * Constructor.
	 *
	 * @param RateRepositoryInterface     $rates      Rate storage.
	 * @param CurrencyRepositoryInterface $currencies Currency metadata storage.
	 */
	public function __construct( RateRepositoryInterface $rates, CurrencyRepositoryInterface $currencies ) {
		$this->rates      = $rates;
		$this->currencies = $currencies;
	}

	/**
	 * Build a loader wired to the real tables.
	 *
	 * @return self Configured loader.
	 */
	public static function from_config() {
		return new self( new WpdbRateRepository(), new WpdbCurrencyRepository() );
	}

	/**
	 * The bundled demo file's absolute path.
	 *
	 * @return string Path to `fixtures/demo.json` inside the plugin.
	 */
	public static function default_path() {
		return CURRENCY_CONVERTER_DIR . self::DEFAULT_FILE;
	}

	/**
	 * Read a fixture and store what it holds.
	 *
	 * @param string $path Absolute path to a JSON fixture.
	 * @return array{rates: int, currencies: int, captured_at: \DateTimeImmutable, source: string,
	 *               unknown: array<int, string>, missing: array<int, string>} What was loaded.
	 * @throws \RuntimeException When the file cannot be read or does not hold rates.
	 */
	public function load( $path ) {
		$payload     = $this->read( $path );
		$captured_at = $this->captured_at( $payload );

		$raw_rates = isset( $payload['rates'] ) && is_array( $payload['rates'] )
			? $payload['rates']
			// A raw `/v1/latest` capture, so this loader can also be pointed at the
			// fixtures the test suite uses without a conversion step.
			: ( isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array() );

		if ( array() === $raw_rates ) {
			throw new \RuntimeException(
				sprintf( 'No rates found in %s: expected a "rates" or "data" object.', $path )
			);
		}

		$predefined = Currencies::codes();
		$returned   = array();
		$rates      = array();

		foreach ( $raw_rates as $code => $value ) {
			if ( ! Currency::is_valid_code( $code ) || ! is_numeric( $value ) ) {
				continue;
			}

			$normalized = strtoupper( trim( (string) $code ) );
			$returned[] = $normalized;

			if ( ! in_array( $normalized, $predefined, true ) ) {
				continue;
			}

			$rates[] = Rate::from_float( Currencies::BASE, $normalized, $value, $captured_at );
		}

		$rates[] = Rate::identity( Currencies::BASE, $captured_at );

		$stored_rates      = $this->rates->upsert( $rates );
		$stored_currencies = $this->store_currencies( $payload );

		DemoMode::enable( basename( $path ), $captured_at );

		return array(
			'rates'       => $stored_rates,
			'currencies'  => $stored_currencies,
			'captured_at' => $captured_at,
			'source'      => $path,
			'unknown'     => array_values( array_diff( $returned, $predefined ) ),
			'missing'     => array_values( array_diff( $predefined, $returned ) ),
		);
	}

	/**
	 * Store the fixture's currency metadata, if it carries any.
	 *
	 * @param array<string, mixed> $payload The decoded fixture.
	 * @return int Rows written.
	 */
	private function store_currencies( array $payload ) {
		$raw = isset( $payload['currencies'] ) && is_array( $payload['currencies'] ) ? $payload['currencies'] : array();

		if ( array() === $raw ) {
			return 0;
		}

		$list = array();

		foreach ( $raw as $code => $meta ) {
			if ( ! Currency::is_valid_code( $code ) ) {
				continue;
			}

			$list[] = Currency::from_array( is_array( $meta ) ? $meta : array(), $code );
		}

		return array() === $list ? 0 : $this->currencies->save_all( $list );
	}

	/**
	 * Read and decode the file.
	 *
	 * @param string $path Absolute path.
	 * @return array<string, mixed> The decoded payload.
	 * @throws \RuntimeException When the file is missing, too big, or not JSON.
	 */
	private function read( $path ) {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Cannot read %s.', $path ) );
		}

		$size = filesize( $path );

		if ( is_int( $size ) && $size > self::MAX_BYTES ) {
			throw new \RuntimeException(
				sprintf( '%s is %d bytes; a fixture should be a few kilobytes.', $path, $size )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file read, not an HTTP request; WP_Filesystem is for writes and for remote transports.
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new \RuntimeException( sprintf( 'Cannot read %s.', $path ) );
		}

		$decoded = json_decode( $contents, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				sprintf( '%s is not valid JSON: %s', $path, json_last_error_msg() )
			);
		}

		return $decoded;
	}

	/**
	 * When the fixture's data is dated, never later than `MINIMUM_AGE` ago.
	 *
	 * @param array<string, mixed> $payload The decoded fixture.
	 * @return \DateTimeImmutable UTC timestamp for every row this load writes.
	 */
	private function captured_at( array $payload ) {
		$latest = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
			->modify( '-' . self::MINIMUM_AGE . ' seconds' );

		if ( ! isset( $payload['captured_at'] ) || ! is_string( $payload['captured_at'] ) ) {
			return $latest;
		}

		try {
			$stated = new \DateTimeImmutable( $payload['captured_at'], new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			unset( $e );

			return $latest;
		}

		// The clamp. A fixture that claims to be current is still stored as stale, because a
		// fixture is not current whatever it says about itself.
		return $stated > $latest ? $latest : $stated;
	}
}
