<?php
/**
 * The module was asked about a currency it does not know.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The code is not a currency this module recognises — or is not a currency code at all.
 *
 * Distinct from `RatesUnavailableException`, and the distinction is the whole point of
 * having two types. "ZZZ is not a currency" and "TRY is a currency but today's sync has
 * not stored a rate for it" are different problems with different fixes: the first is a
 * typo in the caller, the second is an operational condition that a sync will clear. A
 * single "conversion failed" exception makes the two indistinguishable in a log, and the
 * person reading that log then goes looking for the wrong thing.
 *
 * Carries the offending code, and — when the caller supplied one — the list of codes that
 * would have worked, so the message can say what to use instead of only what failed.
 */
final class UnknownCurrencyException extends \InvalidArgumentException implements ExceptionInterface {

	/**
	 * How many supported codes to name before truncating the message.
	 *
	 * The full list is 33 codes and this message reaches admin notices and CLI output.
	 * The complete set stays reachable through `supported()` for anything that wants it.
	 */
	const CODES_IN_MESSAGE = 12;

	/**
	 * The code that was rejected, as it was given.
	 *
	 * Not `$code`: `\Exception` already has a protected `$code` — the integer error code —
	 * and redeclaring it private is a fatal error at class-load time, not a warning.
	 *
	 * @var string
	 */
	private $currency_code;

	/**
	 * The codes that would have been accepted, when the thrower knew them.
	 *
	 * @var array<int, string>
	 */
	private $supported;

	/**
	 * Constructor.
	 *
	 * Prefer the named constructors: they write the message, and a consistent message is
	 * what makes these findable in a log.
	 *
	 * @param string             $message   Human-readable description, safe to log.
	 * @param string             $code      The currency code that was rejected.
	 * @param array<int, string> $supported Codes that would have been accepted.
	 */
	public function __construct( $message, $code = '', array $supported = array() ) {
		parent::__construct( $message );

		$this->currency_code = (string) $code;
		$this->supported     = array_values( array_map( 'strval', $supported ) );
	}

	/**
	 * The code is well-formed but not one the module serves.
	 *
	 * @param string             $code      The rejected code.
	 * @param array<int, string> $supported Codes that would have been accepted, if known.
	 * @return self The exception, ready to throw.
	 */
	public static function for_code( $code, array $supported = array() ) {
		$message = sprintf( 'Unknown currency "%s".', (string) $code );

		if ( array() !== $supported ) {
			$shown = array_slice( $supported, 0, self::CODES_IN_MESSAGE );

			$message .= sprintf(
				' Supported: %s%s.',
				implode( ', ', $shown ),
				count( $supported ) > count( $shown ) ? sprintf( ' and %d more', count( $supported ) - count( $shown ) ) : ''
			);
		}

		return new self( $message, $code, $supported );
	}

	/**
	 * The string is not a currency code in the first place.
	 *
	 * Shape, not membership: `us1`, `EURO` and `''` land here rather than in `for_code()`,
	 * because listing the supported codes answers a question nobody asked.
	 *
	 * @param mixed $code Whatever was passed where a code was expected.
	 * @return self The exception, ready to throw.
	 */
	public static function malformed( $code ) {
		return new self(
			sprintf(
				'"%s" is not a three-letter ISO 4217 currency code.',
				is_scalar( $code ) ? (string) $code : gettype( $code )
			),
			is_scalar( $code ) ? (string) $code : ''
		);
	}

	/**
	 * The code that was rejected.
	 *
	 * @return string The offending code, or an empty string when it was not a string.
	 */
	public function code() {
		return $this->currency_code;
	}

	/**
	 * The codes that would have been accepted.
	 *
	 * @return array<int, string> Supported codes, empty when the thrower did not know them.
	 */
	public function supported() {
		return $this->supported;
	}
}
