<?php
/**
 * Rate storage that is an array.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Fakes;

use DateTimeImmutable;
use DateTimeZone;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Domain\RateRepositoryInterface;

/**
 * The reason `RateRepositoryInterface` is declared in the domain rather than in `Db\`.
 *
 * Substituted for `WpdbRateRepository`, it lets every branch of the converter's arithmetic
 * be exercised against rates chosen for the test — 90.0 and 0.5, so the expected answers can
 * be worked out on paper — with no database, no `$wpdb` and no WordPress bootstrap.
 *
 * It also *counts* the reads. `map_call_count()` is what makes "the rate map loads once per
 * request" an assertion rather than a claim: a converter that queries per `convert()` call
 * passes every arithmetic test and fails that one.
 *
 * Rates are held as strings, exactly as the interface requires of every implementation, and
 * nothing here casts one to a float.
 */
final class InMemoryRateRepository implements RateRepositoryInterface {

	/**
	 * Stored rates, as `base|target => decimal string`.
	 *
	 * @var array<string, string>
	 */
	private array $rates = array();

	/**
	 * When the stored rates were fetched.
	 */
	private ?DateTimeImmutable $fetched_at;

	/**
	 * How many times `map()` has been called.
	 */
	private int $map_calls = 0;

	/**
	 * How many times `flush()` has been called.
	 */
	private int $flush_calls = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, string|int> $rates      Rates against the base, as `code => value`.
	 *                                              The base's own identity rate is added.
	 * @param string                    $base_code  Base currency for those rates.
	 * @param DateTimeImmutable|null    $fetched_at When they were fetched.
	 */
	public function __construct( array $rates = array(), string $base_code = 'USD', ?DateTimeImmutable $fetched_at = null ) {
		$this->fetched_at = $fetched_at ?? new DateTimeImmutable( '2026-08-24 09:00:00', new DateTimeZone( 'UTC' ) );

		if ( array() === $rates ) {
			return;
		}

		$base = Currency::normalize_code( $base_code );

		// Written by the storage layer and never taken from a payload, exactly as
		// `WpdbRateRepository::identity_first()` does it.
		$this->rates[ $base . '|' . $base ] = Rate::IDENTITY;

		foreach ( $rates as $code => $value ) {
			$this->rates[ $base . '|' . Currency::normalize_code( (string) $code ) ] = (string) $value;
		}
	}

	/**
	 * The rates R6's arithmetic is pinned against.
	 *
	 * USD → RUB at 90 and USD → EUR at 0.5, so `123 USD` is `11070 RUB`, `123 RUB` is
	 * `1.36666…  USD`, and the `EUR → RUB` cross rate is exactly 180. Fixed, so no expected
	 * value in the converter's tests moves when the market does.
	 *
	 * @var array<string, string>
	 */
	const FIXED_RATES = array(
		'RUB' => '90.000000000000',
		'EUR' => '0.500000000000',
		'JPY' => '150.000000000000',
	);

	/**
	 * A repository holding the rates the acceptance criteria are written against.
	 *
	 * @return self Configured repository.
	 */
	public static function with_fixed_rates(): self {
		return new self( self::FIXED_RATES );
	}

	/**
	 * The fixed rates with some currencies withheld.
	 *
	 * A populated table that is simply missing a row, which is a different situation from an
	 * empty one and has a different fix — the sync ran, and the API did not serve that code.
	 * Derived from `FIXED_RATES` rather than hand-written so the two cannot drift apart.
	 *
	 * @param string ...$codes Currency codes to leave out.
	 * @return self Configured repository.
	 */
	public static function with_fixed_rates_except( string ...$codes ): self {
		$rates = self::FIXED_RATES;

		foreach ( $codes as $code ) {
			unset( $rates[ Currency::normalize_code( $code ) ] );
		}

		return new self( $rates );
	}

	/**
	 * How many times storage has been read.
	 *
	 * Deliberately *not* memoised inside this fake, unlike `WpdbRateRepository`, which does
	 * memoise. If both layers cached, this counter would read 1 whether or not the converter
	 * holds the map — and "the map loads once per request" is a claim about the converter.
	 * Here every call counts, so a converter that reads per `convert()` is caught.
	 */
	public function map_call_count(): int {
		return $this->map_calls;
	}

	/**
	 * Store a batch of rates.
	 *
	 * @param array<int, Rate> $rates Rates to store.
	 * @return int Rows submitted.
	 */
	public function upsert( array $rates ) {
		foreach ( $rates as $rate ) {
			$this->rates[ $rate->pair_key() ] = $rate->value();
		}

		return count( $rates );
	}

	/**
	 * Every rate against one base.
	 *
	 * @param string $base_code Base currency code.
	 * @return array<string, string> Rates keyed by target code.
	 */
	public function map( $base_code ) {
		$base = Currency::normalize_code( $base_code );

		++$this->map_calls;

		$map = array();

		foreach ( $this->rates as $key => $value ) {
			[ $stored_base, $target ] = explode( '|', $key );

			if ( $stored_base === $base ) {
				$map[ $target ] = $value;
			}
		}

		return $map;
	}

	/**
	 * One stored rate.
	 *
	 * @param string $base_code   Base currency code.
	 * @param string $target_code Target currency code.
	 * @return Rate|null The rate, or null when the pair is not stored.
	 */
	public function find( $base_code, $target_code ) {
		$base   = Currency::normalize_code( $base_code );
		$target = Currency::normalize_code( $target_code );
		$key    = $base . '|' . $target;

		if ( ! isset( $this->rates[ $key ] ) ) {
			return null;
		}

		return new Rate( $base, $target, $this->rates[ $key ], $this->fetched_at );
	}

	/**
	 * Stored rates as objects.
	 *
	 * @param array<string, mixed> $args Query arguments; only `base_code` is honoured here.
	 * @return array<int, Rate> Rates, in insertion order.
	 */
	public function all( array $args = array() ) {
		$base  = isset( $args['base_code'] ) ? (string) $args['base_code'] : '';
		$rates = array();

		foreach ( $this->rates as $key => $value ) {
			[ $stored_base, $target ] = explode( '|', $key );

			if ( '' === $base || $stored_base === strtoupper( $base ) ) {
				$rates[] = new Rate( $stored_base, $target, $value, $this->fetched_at );
			}
		}

		return $rates;
	}

	/**
	 * How many rates are stored.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return int Row count.
	 */
	public function count( $base_code = '' ) {
		return count( $this->all( array( 'base_code' => $base_code ) ) );
	}

	/**
	 * When the stored rates were fetched.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return DateTimeImmutable|null UTC timestamp, or null when nothing is stored.
	 */
	public function last_fetched_at( $base_code = '' ) {
		unset( $base_code );

		return array() === $this->rates ? null : $this->fetched_at;
	}

	/**
	 * Discard whatever `map()` memoised — which here is nothing, by design.
	 *
	 * Present because the interface requires it, and because the converter's own `flush()`
	 * calls it: that call is what the reads counted above make visible.
	 *
	 * @return void
	 */
	public function flush() {
		++$this->flush_calls;
	}

	/**
	 * How many times `flush()` has been called.
	 */
	public function flush_call_count(): int {
		return $this->flush_calls;
	}
}
