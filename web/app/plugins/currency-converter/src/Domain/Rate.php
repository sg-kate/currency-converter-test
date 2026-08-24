<?php
/**
 * One stored exchange rate, held as a decimal string.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable rate for one ordered currency pair.
 *
 * **The value is a string and never a float.** Not a stylistic preference: the column is
 * `DECIMAL(24,12)`, and a float has 53 bits of mantissa — about 15-16 significant decimal
 * digits — so the moment a rate becomes a float the twelve stored decimal places are no
 * longer the twelve that were stored. `0.1 + 0.2 !== 0.3` is the toy example; the real one
 * is a rate that reads back as `93.007123456699997` and a total that is a hundredth out
 * after enough rows. Reading the column into a string keeps what the database holds, exactly.
 *
 * The same reasoning runs through the whole module: `%s` binds this value, never `%f`
 * (which is locale-formatted as well as lossy — a `de_DE` request would bind `93,0071` and
 * MySQL would store `93`), and `Db\WpdbRateRepository` never casts a `rate` column to float.
 *
 * A float still has to be accepted at exactly one boundary: `json_decode()` produces floats
 * and PHP has no flag to make it produce anything else, so the API payload arrives as
 * floats. `from_float()` is that boundary, in one named place that can be grepped for, and
 * it is the only route by which a float becomes a rate.
 *
 * Framework-free: no WordPress function is called here.
 */
final class Rate {

	/**
	 * Decimal places kept, matching the scale of the `rate` column.
	 */
	const SCALE = 12;

	/**
	 * Digits available before the decimal point: `DECIMAL(24,12)` is 24 total, 12 after.
	 *
	 * A value wider than this is rejected here rather than being truncated by MySQL — in
	 * non-strict mode an over-wide value is silently clamped to the column maximum, which
	 * turns a bad rate into a plausible-looking one.
	 */
	const MAX_INTEGER_DIGITS = 12;

	/**
	 * The rate of a currency against itself, at full scale.
	 */
	const IDENTITY = '1.000000000000';

	/**
	 * How MySQL wants a `datetime`. Always UTC; WordPress local time never enters storage.
	 */
	const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Base currency code, upper case.
	 *
	 * @var string
	 */
	private $base_code;

	/**
	 * Target currency code, upper case.
	 *
	 * @var string
	 */
	private $target_code;

	/**
	 * The rate, as a canonical decimal string with exactly SCALE decimal places.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * When the rate was fetched, in UTC. Null when it was never recorded.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private $fetched_at;

	/**
	 * Constructor.
	 *
	 * @param string                  $base_code   Base currency code.
	 * @param string                  $target_code Target currency code.
	 * @param string|int              $value       Decimal rate. Strings keep their full
	 *                                             precision; floats must come through
	 *                                             `from_float()`, which says why.
	 * @param \DateTimeImmutable|null $fetched_at  When the rate was retrieved.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When either code is malformed.
	 * @throws \InvalidArgumentException When the value is not a positive decimal that fits the column.
	 */
	public function __construct( $base_code, $target_code, $value, ?\DateTimeImmutable $fetched_at = null ) {
		$this->base_code   = Currency::normalize_code( $base_code );
		$this->target_code = Currency::normalize_code( $target_code );
		$this->value       = self::normalize_value( $value );

		// Normalised on the way in, so every consumer can format without asking about zones.
		$this->fetched_at = $fetched_at instanceof \DateTimeImmutable
			? $fetched_at->setTimezone( new \DateTimeZone( 'UTC' ) )
			: null;
	}

	/**
	 * The rate of a currency against itself: exactly 1, always.
	 *
	 * Written by us rather than taken from the API payload. The base currency's own rate is
	 * the one every cross-rate divides by, so a missing or approximate `USD => USD` row turns
	 * every conversion out of USD into a division by a missing key.
	 *
	 * @param string                  $code       The currency, as both base and target.
	 * @param \DateTimeImmutable|null $fetched_at When the surrounding batch was fetched.
	 * @return self The identity rate.
	 */
	public static function identity( $code, ?\DateTimeImmutable $fetched_at = null ) {
		return new self( $code, $code, self::IDENTITY, $fetched_at );
	}

	/**
	 * Build from the float `json_decode()` produced.
	 *
	 * The one sanctioned float-to-rate conversion in the module, and the reason it is named
	 * rather than implicit: `grep -rn from_float` lists every place precision could have
	 * been lost, and the list is meant to stay one entry long — the API response.
	 *
	 * `%.12F` and not `%.12f`: the upper-case conversion is locale-independent, so a site
	 * running under a locale with a comma decimal separator produces `93.007123456700`
	 * rather than `93,007123456700`, which MySQL would read as `93`.
	 *
	 * @param string                  $base_code   Base currency code.
	 * @param string                  $target_code Target currency code.
	 * @param float|int|string        $value       Rate as decoded from the payload.
	 * @param \DateTimeImmutable|null $fetched_at  When the batch was fetched.
	 * @return self The rate.
	 * @throws \InvalidArgumentException When the value is not a finite, positive number.
	 */
	public static function from_float( $base_code, $target_code, $value, ?\DateTimeImmutable $fetched_at = null ) {
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Rate for %s is not a finite number.', (string) $target_code )
				);
			}

			$value = sprintf( '%.' . self::SCALE . 'F', $value );
		}

		return new self( $base_code, $target_code, $value, $fetched_at );
	}

	/**
	 * Build from a database row, without casting the decimal to anything.
	 *
	 * @param array<string, mixed> $row Row with `base_code`, `target_code`, `rate` and
	 *                                  optionally `fetched_at`, as `$wpdb` returns them —
	 *                                  every column a string.
	 * @return self The rate.
	 * @throws \InvalidArgumentException When the row carries no usable rate.
	 */
	public static function from_row( array $row ) {
		return new self(
			isset( $row['base_code'] ) ? $row['base_code'] : '',
			isset( $row['target_code'] ) ? $row['target_code'] : '',
			isset( $row['rate'] ) ? $row['rate'] : '',
			isset( $row['fetched_at'] ) ? self::datetime_from_string( $row['fetched_at'] ) : null
		);
	}

	/**
	 * Canonicalise a decimal string to exactly SCALE decimal places.
	 *
	 * Rounds half-up on the string itself rather than through `round()`, which would mean a
	 * round trip through the float this class exists to avoid. Implemented with string
	 * arithmetic and not `bcmath`, so the domain layer depends on no PHP extension at all —
	 * `bcmath` is present in both of this project's images today, but a value object that
	 * fatals when it is not is a poor trade for twelve lines.
	 *
	 * @param string|int $value Decimal value, no sign, no exponent.
	 * @return string The value with exactly SCALE decimal places.
	 * @throws \InvalidArgumentException When the value is not a positive decimal within range.
	 */
	public static function normalize_value( $value ) {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'A rate must be given as a decimal string, %s given. Use Rate::from_float() for a decoded API value.',
					gettype( $value )
				)
			);
		}

		$matches = array();

		// No sign and no exponent: a negative exchange rate is meaningless, and `1.0E-5`
		// would be stored by MySQL as the string it is not.
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d*))?$/', trim( $value ), $matches ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Rate "%s" is not a positive decimal number.', $value )
			);
		}

		$integer  = ltrim( $matches[1], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $matches[2] ) ? $matches[2] : '';

		if ( strlen( $fraction ) > self::SCALE ) {
			$digits = $integer . substr( $fraction, 0, self::SCALE );

			if ( $fraction[ self::SCALE ] >= '5' ) {
				$digits = self::increment_digits( $digits );
			}

			$integer  = substr( $digits, 0, strlen( $digits ) - self::SCALE );
			$fraction = substr( $digits, -self::SCALE );
		} else {
			$fraction = str_pad( $fraction, self::SCALE, '0' );
		}

		if ( strlen( $integer ) > self::MAX_INTEGER_DIGITS ) {
			throw new \InvalidArgumentException(
				sprintf( 'Rate "%s" does not fit DECIMAL(24,12).', $value )
			);
		}

		$normalized = $integer . '.' . $fraction;

		if ( '0' === $integer && str_repeat( '0', self::SCALE ) === $fraction ) {
			// Zero would be stored happily and then divide by zero on the first cross-rate.
			throw new \InvalidArgumentException(
				sprintf( 'Rate "%s" is zero at %d decimal places.', $value, self::SCALE )
			);
		}

		return $normalized;
	}

	/**
	 * The base currency code.
	 *
	 * @return string Three upper-case letters.
	 */
	public function base_code() {
		return $this->base_code;
	}

	/**
	 * The target currency code.
	 *
	 * @return string Three upper-case letters.
	 */
	public function target_code() {
		return $this->target_code;
	}

	/**
	 * The rate.
	 *
	 * @return string Decimal string with exactly SCALE decimal places. Never a float, and
	 *                never cast to one by a caller either.
	 */
	public function value() {
		return $this->value;
	}

	/**
	 * When the rate was fetched.
	 *
	 * @return \DateTimeImmutable|null UTC timestamp, or null when it was not recorded.
	 */
	public function fetched_at() {
		return $this->fetched_at;
	}

	/**
	 * The fetch time in the format the `datetime` column wants.
	 *
	 * @param string|null $fallback Value to return when no fetch time is set.
	 * @return string|null UTC `Y-m-d H:i:s`, or the fallback.
	 */
	public function fetched_at_string( $fallback = null ) {
		if ( ! $this->fetched_at instanceof \DateTimeImmutable ) {
			return $fallback;
		}

		return $this->fetched_at->format( self::DATETIME_FORMAT );
	}

	/**
	 * Whether this is a currency's rate against itself.
	 *
	 * @return bool True when base and target are the same currency.
	 */
	public function is_identity() {
		return $this->base_code === $this->target_code;
	}

	/**
	 * The pair, as a key.
	 *
	 * @return string For example `USD|RUB`.
	 */
	public function pair_key() {
		return $this->base_code . '|' . $this->target_code;
	}

	/**
	 * Flat representation, for storage and for tests.
	 *
	 * @return array{base_code: string, target_code: string, rate: string, fetched_at: string|null} The fields.
	 */
	public function to_array() {
		return array(
			'base_code'   => $this->base_code,
			'target_code' => $this->target_code,
			'rate'        => $this->value,
			'fetched_at'  => $this->fetched_at_string(),
		);
	}

	/**
	 * Add one to a string of digits, carrying as far as it needs to.
	 *
	 * @param string $digits Digits only, no separator.
	 * @return string The incremented digits, one longer when the carry runs off the end.
	 */
	private static function increment_digits( $digits ) {
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );

				return $digits;
			}

			$digits[ $i ] = '0';
		}

		return '1' . $digits;
	}

	/**
	 * Read a MySQL `datetime` as UTC.
	 *
	 * Public because the repository has one column to read that belongs to no row —
	 * `MAX(fetched_at)` — and a second parser for the same format is a second place for the
	 * zero-date and timezone handling to be got wrong.
	 *
	 * @param mixed $value Column value; a string, or null on a fresh row.
	 * @return \DateTimeImmutable|null The timestamp, or null when absent or a zero date.
	 */
	public static function datetime_from_string( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strncmp( $value, '0000-00-00', 10 ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat(
			self::DATETIME_FORMAT,
			$value,
			new \DateTimeZone( 'UTC' )
		);

		return false === $parsed ? null : $parsed;
	}
}
