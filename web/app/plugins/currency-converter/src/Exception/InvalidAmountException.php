<?php
/**
 * The amount handed to a conversion is not a usable number.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The value to convert is not something arithmetic can be done with.
 *
 * The brief's signature is `convert( 123, 'USD', 'RUB' )` — a float — and PHP will happily
 * pass `NAN`, `INF`, `'12,50'` or `''` into a float parameter, where each one produces a
 * number-shaped answer that is wrong. `(float) '12,50'` is `12.0`, silently losing half the
 * value; `NAN` propagates through every multiplication and reaches the page as `NAN`. Both
 * are caught here instead, at the edge, before they can be formatted as money.
 *
 * Negative amounts are *not* rejected: a refund is a legitimate conversion. Zero is not
 * rejected either — converting nothing is nothing, and that is a correct answer.
 */
final class InvalidAmountException extends \InvalidArgumentException implements ExceptionInterface {

	/**
	 * The rejected value, rendered for a message.
	 *
	 * @var string
	 */
	private $amount;

	/**
	 * Constructor.
	 *
	 * Prefer the named constructors below.
	 *
	 * @param string $message Human-readable description, safe to log.
	 * @param string $amount  The rejected value, already rendered as a string.
	 */
	public function __construct( $message, $amount = '' ) {
		parent::__construct( $message );

		$this->amount = (string) $amount;
	}

	/**
	 * The value cannot be read as a number at all.
	 *
	 * @param mixed $amount Whatever was passed where an amount was expected.
	 * @return self The exception, ready to throw.
	 */
	public static function not_numeric( $amount ) {
		return new self(
			sprintf( 'Amount "%s" is not a number.', self::render( $amount ) ),
			self::render( $amount )
		);
	}

	/**
	 * The value is a float, but not a finite one.
	 *
	 * `NAN` and `INF` are the dangerous cases: they are floats, they pass `is_numeric()`
	 * once cast, and they survive every arithmetic operation without ever throwing.
	 *
	 * @param mixed $amount The non-finite value.
	 * @return self The exception, ready to throw.
	 */
	public static function not_finite( $amount ) {
		return new self(
			sprintf( 'Amount "%s" is not a finite number.', self::render( $amount ) ),
			self::render( $amount )
		);
	}

	/**
	 * The rejected value.
	 *
	 * @return string The value as it was rendered into the message.
	 */
	public function amount() {
		return $this->amount;
	}

	/**
	 * Render any value for inclusion in a message.
	 *
	 * @param mixed $amount Value of any type.
	 * @return string Printable representation.
	 */
	private static function render( $amount ) {
		if ( is_float( $amount ) && ! is_finite( $amount ) ) {
			return is_nan( $amount ) ? 'NAN' : ( $amount > 0 ? 'INF' : '-INF' );
		}

		return is_scalar( $amount ) ? (string) $amount : gettype( $amount );
	}
}
