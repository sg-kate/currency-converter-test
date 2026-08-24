<?php
/**
 * A currency and the metadata needed to display an amount in it.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Domain;

use Drozd\Currency\Exception\UnknownCurrencyException;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable currency: its code, and what `/v1/currencies` says about displaying it.
 *
 * Framework-free by construction — no WordPress function is called anywhere in this file,
 * which is invariant 10 of the task contract and what makes the domain unit-testable with
 * no database and no bootstrap.
 *
 * The code is the identity; `name`, `symbol` and `decimal_digits` are description and may
 * be empty on a currency the metadata sync has not covered yet. `decimal_digits` is not
 * decoration: JPY has 0, not 2, and rounding a yen amount to two places invents a fraction
 * of a currency unit that does not exist.
 *
 * This class does not decide which currencies exist — `Currencies::CODES` does, and a row
 * in `cc_currencies` describes a currency rather than admitting it. Constructing one here
 * asserts the code is *shaped* like a currency code, not that the module serves it.
 */
final class Currency {

	/**
	 * What a currency code has to look like: three upper-case ASCII letters.
	 */
	const CODE_PATTERN = '/^[A-Z]{3}$/';

	/**
	 * Minor units assumed when the metadata sync has not run. Two is right for most.
	 */
	const DEFAULT_DECIMAL_DIGITS = 2;

	/**
	 * Ceiling for `decimal_digits`, matching the `tinyint(3)` column and leaving room for
	 * the handful of currencies with three minor digits.
	 */
	const MAX_DECIMAL_DIGITS = 8;

	/**
	 * ISO 4217 code, upper case.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Display name, empty when unknown.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Display symbol, empty when unknown.
	 *
	 * @var string
	 */
	private $symbol;

	/**
	 * Number of minor units, 0 for JPY.
	 *
	 * @var int
	 */
	private $decimal_digits;

	/**
	 * Constructor.
	 *
	 * @param string $code           ISO 4217 code; case is normalised, shape is enforced.
	 * @param string $name           Display name, e.g. "Euro".
	 * @param string $symbol         Display symbol, e.g. "€".
	 * @param int    $decimal_digits Minor units; clamped to 0..MAX_DECIMAL_DIGITS.
	 * @throws UnknownCurrencyException When the code is not three letters.
	 */
	public function __construct( $code, $name = '', $symbol = '', $decimal_digits = self::DEFAULT_DECIMAL_DIGITS ) {
		$this->code           = self::normalize_code( $code );
		$this->name           = (string) $name;
		$this->symbol         = (string) $symbol;
		$this->decimal_digits = max( 0, min( self::MAX_DECIMAL_DIGITS, (int) $decimal_digits ) );
	}

	/**
	 * Upper-case a code and insist it is one.
	 *
	 * The single place the shape of a currency code is decided, so every entry point —
	 * a CLI argument, a `$_GET` parameter, a JSON key — is normalised the same way and
	 * `'usd'` and `'USD'` cannot become two different rows.
	 *
	 * @param mixed $code Candidate code.
	 * @return string The code, upper case.
	 * @throws UnknownCurrencyException When the value is not three ASCII letters.
	 */
	public static function normalize_code( $code ) {
		if ( ! is_string( $code ) ) {
			throw UnknownCurrencyException::malformed( $code );
		}

		$normalized = strtoupper( trim( $code ) );

		if ( 1 !== preg_match( self::CODE_PATTERN, $normalized ) ) {
			throw UnknownCurrencyException::malformed( $code );
		}

		return $normalized;
	}

	/**
	 * Whether a value is shaped like a currency code, without throwing.
	 *
	 * For the places that have to ask rather than assert — filtering an API payload that
	 * may carry a key we do not recognise.
	 *
	 * @param mixed $code Candidate code.
	 * @return bool True when `normalize_code()` would succeed.
	 */
	public static function is_valid_code( $code ) {
		return is_string( $code ) && 1 === preg_match( self::CODE_PATTERN, strtoupper( trim( $code ) ) );
	}

	/**
	 * Build from a database row or a decoded API entry.
	 *
	 * Accepts both shapes because they differ only in key names: the table stores `code`,
	 * the API's `/v1/currencies` payload stores `code` too but adds `name_plural` and
	 * others this module does not keep.
	 *
	 * @param array<string, mixed> $row    Row with at least a `code` key.
	 * @param string               $code   Fallback code, for payloads keyed by code with
	 *                                     no `code` field inside the value.
	 * @return self The currency.
	 * @throws UnknownCurrencyException When neither the row nor the fallback carries a code.
	 */
	public static function from_array( array $row, $code = '' ) {
		$raw_code = isset( $row['code'] ) && '' !== $row['code'] ? $row['code'] : $code;

		return new self(
			$raw_code,
			isset( $row['name'] ) ? $row['name'] : '',
			isset( $row['symbol'] ) ? $row['symbol'] : '',
			isset( $row['decimal_digits'] ) ? $row['decimal_digits'] : self::DEFAULT_DECIMAL_DIGITS
		);
	}

	/**
	 * The ISO 4217 code.
	 *
	 * @return string Three upper-case letters.
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * The display name.
	 *
	 * @return string Name, or an empty string when the metadata sync has not run.
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * The display symbol.
	 *
	 * @return string Symbol, or an empty string when unknown.
	 */
	public function symbol() {
		return $this->symbol;
	}

	/**
	 * How many minor units this currency has.
	 *
	 * @return int Digits after the decimal point; 0 for JPY.
	 */
	public function decimal_digits() {
		return $this->decimal_digits;
	}

	/**
	 * A label safe to print anywhere, whether or not the metadata is known.
	 *
	 * @return string "Euro (EUR)", or just "EUR" when the name is missing.
	 */
	public function label() {
		if ( '' === $this->name ) {
			return $this->code;
		}

		return sprintf( '%s (%s)', $this->name, $this->code );
	}

	/**
	 * Identity comparison. Two currencies are the same currency when their codes match.
	 *
	 * @param Currency $other The currency to compare with.
	 * @return bool True when both carry the same code.
	 */
	public function equals( Currency $other ) {
		return $this->code === $other->code();
	}

	/**
	 * Flat representation, for storage and for tests.
	 *
	 * @return array{code: string, name: string, symbol: string, decimal_digits: int} The fields.
	 */
	public function to_array() {
		return array(
			'code'           => $this->code,
			'name'           => $this->name,
			'symbol'         => $this->symbol,
			'decimal_digits' => $this->decimal_digits,
		);
	}
}
