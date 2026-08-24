<?php
/**
 * Converting an amount from one currency to another.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Service;

use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Domain\RateRepositoryInterface;
use Drozd\Currency\Exception\InvalidAmountException;
use Drozd\Currency\Exception\RatesUnavailableException;
use Drozd\Currency\Exception\UnknownCurrencyException;

defined( 'ABSPATH' ) || exit;

/**
 * The service the brief asks for: `$converter->convert( 123, 'USD', 'RUB' )`.
 *
 * An object with that method, constructed with its storage, and not a static helper, a
 * bare function or a filter — that is invariant 6 of the task contract, and the signature
 * is taken from the brief verbatim, float in and float out.
 *
 * Three decisions carry the class, and each one is the difference between a service and a
 * script that happens to multiply two numbers.
 *
 * **One query per request, not one per call.** `convert()` in a loop over a cart, a price
 * list or a report is the normal way this gets used, and the naive implementation is one
 * `SELECT` per call. The whole rate map for the stored base is read once, on the first
 * conversion that needs it, and held on the instance for the rest of the request — which is
 * why the module hands callers a shared instance through `currency_converter()` rather than
 * letting every call site build its own. An empty table memoises as an empty map, so a site
 * whose sync has never run still costs one query rather than one per call; every conversion
 * then throws, which is the point.
 *
 * Memoisation is per *request*, deliberately: it is an object property and not an object
 * cache or a transient, so nothing outlives the response and a rate written by cron is
 * picked up by the next request instead of going stale for a TTL. A long-running process
 * — the CLI, cron — that writes rates and then converts calls `flush()`.
 *
 * **The arithmetic is `bcmath`, and the rounding is half-up on decimal strings.** Rates are
 * `DECIMAL(24,12)` and are read as strings precisely so the twelve stored decimal places
 * survive; doing the multiplication in floating point would throw that away in the last step
 * of the one operation the module exists to perform. `round()` is the specific trap: it is
 * handed a float that is already off by a fraction of an ulp, so `round( 2.675, 2 )` is
 * `2.67` — the value it rounds is really `2.67499999999999982…`, and no amount of care at
 * the call site fixes that. Every product, quotient and rounding decision here is made on
 * exact decimal strings. The float cast happens once, at the very end, because the brief's
 * signature says the method returns one.
 *
 * **A missing rate throws.** It is not a rate of 1, not yesterday's rate presented as
 * today's, and not zero — the rule in the task contract that overrides "make it work". The
 * two failures are separate types on purpose: `UnknownCurrencyException` means the caller
 * asked for something that is not a currency this module serves (a typo, fix the code),
 * `RatesUnavailableException` means the currency is real and the sync has not stored a rate
 * for it (fix the cron job or the API key). One "conversion failed" type would send whoever
 * reads the log looking for the wrong thing.
 *
 * Framework-free: no WordPress function is called in this file. Storage arrives as
 * `RateRepositoryInterface`, so the arithmetic below is tested against an in-memory
 * implementation with no database and no bootstrap. `bcmath` is the one extension it needs,
 * and it is present in both of this project's images — checked in both, because a check that
 * only ran in the CLI says nothing about a web request.
 */
final class CurrencyConverter {

	/**
	 * Decimal places the arithmetic works at before the single rounding step.
	 *
	 * Twice the stored scale, which is exactly what the product of two `DECIMAL(24,12)`
	 * values needs: `amount × rate` is held with no truncation at all, so the half-up
	 * decision at `Rate::SCALE` is taken on the true value rather than on a value that has
	 * already lost the digits the decision depends on. Division cannot be exact in general —
	 * a third of anything is not a terminating decimal — but twelve guard digits below the
	 * scale that survives put any disagreement far past the significant digits an exchange
	 * rate carries.
	 */
	const WORKING_SCALE = Rate::SCALE * 2;

	/**
	 * What an amount may look like when it arrives as a string.
	 *
	 * A sign, digits, optionally a decimal point and more digits — no exponent, no thousands
	 * separator, no currency symbol. `bcmath` parses exactly this and silently reads anything
	 * else as far as it understands: `bcmul( '1e5', '2', 2 )` is `2.00`, not `200000.00`, and
	 * no warning is raised. So the shape is checked here rather than discovered later.
	 */
	const AMOUNT_PATTERN = '/^[+-]?\d+(?:\.\d+)?$/';

	/**
	 * Where rates are read from.
	 *
	 * @var RateRepositoryInterface
	 */
	private $rates;

	/**
	 * The memoised rate map for the stored base, or null before the first read.
	 *
	 * Null and `array()` mean different things and the distinction is load-bearing: null is
	 * "not read yet", an empty array is "read, and the table has nothing" — which must not
	 * be re-queried on the next call.
	 *
	 * @var array<string, string>|null
	 */
	private $map = null;

	/**
	 * The memoised list of currency codes the module serves.
	 *
	 * `Currencies::codes()` runs the `currency_converter_currencies` filter and rebuilds the
	 * list on every call, which is cheap once and wasteful a thousand times in a loop.
	 * Memoised for the same reason and with the same lifetime as the map: a filter added
	 * after the first conversion of a request is not seen until the next one, and `flush()`
	 * clears both.
	 *
	 * @var array<int, string>|null
	 */
	private $supported = null;

	/**
	 * Constructor.
	 *
	 * @param RateRepositoryInterface $rates Rate storage.
	 * @throws \RuntimeException When `bcmath` is not loaded.
	 */
	public function __construct( RateRepositoryInterface $rates ) {
		if ( ! function_exists( 'bcmul' ) ) {
			// Said once, plainly, rather than left to surface as "Call to undefined function
			// bcmul()" from the middle of a conversion. The extension is in both of this
			// project's images; the plugin also ships as a zip to sites that are not this one.
			throw new \RuntimeException(
				'Currency Converter needs the bcmath PHP extension: exchange rates are DECIMAL(24,12) and floating-point arithmetic cannot hold them.'
			);
		}

		$this->rates = $rates;
	}

	/**
	 * Build a converter wired to the real rates table.
	 *
	 * The one place in this class that names a concrete storage implementation, so
	 * everything else — including all of the arithmetic — depends on the interface.
	 *
	 * @return self Configured converter.
	 */
	public static function from_config() {
		return new self( new WpdbRateRepository() );
	}

	/**
	 * Convert an amount from one currency to another.
	 *
	 * The brief's signature, unchanged: `$converter->convert( 123, 'USD', 'RUB' )`.
	 *
	 * The stored base — USD, because that is the only base the free plan serves, see
	 * collision C1 — is the pivot for every pair. `USD → X` is a lookup, `X → USD` and
	 * `X → Y` are the same lookup divided by the source currency's own rate. The division is
	 * done last, on the product, rather than first on the two rates: multiplying by an
	 * already-rounded cross rate rounds twice, and the second rounding is applied to a number
	 * that has been scaled up by the amount.
	 *
	 * The result is rounded half-up, once, at `Rate::SCALE`, and only then cast to float.
	 * Rounding for display — two places for most currencies, none for JPY — belongs at the
	 * presentation edge and not here; this method's job is to be exact for as long as its
	 * return type allows.
	 *
	 * @param float|int|string $amount    Amount in the source currency. A decimal string is
	 *                                    kept exactly; a float is read at `Rate::SCALE`.
	 *                                    Negative amounts are legitimate — a refund converts.
	 * @param string           $from_code Source currency code, any case.
	 * @param string           $to_code   Target currency code, any case.
	 * @return float The converted amount.
	 * @throws InvalidAmountException When the amount is not a finite number.
	 * @throws UnknownCurrencyException When either code is malformed or not served.
	 * @throws RatesUnavailableException When either currency has no stored rate.
	 */
	public function convert( $amount, $from_code, $to_code ) {
		$from  = $this->supported_code( $from_code );
		$to    = $this->supported_code( $to_code );
		$value = self::amount_to_decimal( $amount );

		// Identity, with no lookup at all: a currency is worth itself whether or not the
		// sync has ever run, and a site converting USD to USD should not need a rate table.
		if ( $from === $to ) {
			return (float) self::round_half_up( $value, Rate::SCALE );
		}

		$map       = $this->map();
		$from_rate = self::rate_from_map( $map, $from );
		$to_rate   = self::rate_from_map( $map, $to );

		$product = bcmul( $value, $to_rate, self::WORKING_SCALE );

		// Out of the base there is nothing to divide by: the base's own rate is exactly 1,
		// written by the repository rather than taken from the payload. Skipping the division
		// keeps the product exact instead of trusting a stored 1 to be one.
		$converted = Currencies::BASE === $from
			? $product
			: bcdiv( $product, $from_rate, self::WORKING_SCALE );

		return (float) self::round_half_up( $converted, Rate::SCALE );
	}

	/**
	 * The exchange rate between two currencies, as a decimal string.
	 *
	 * For the callers that need to *show* a rate rather than apply one — the admin table, the
	 * CLI's "rate 93.0071" — and returned as a string for the same reason the column is
	 * `DECIMAL(24,12)`: a float cannot hold what is stored.
	 *
	 * Note that `convert()` does not multiply by this value. It divides last, on the product,
	 * so the two can differ in the final decimal places of a cross rate. That is the intended
	 * direction of the difference: this is the rate to display, `convert()` is the arithmetic
	 * to trust.
	 *
	 * @param string $from_code Source currency code, any case.
	 * @param string $to_code   Target currency code, any case.
	 * @return string The rate, with exactly `Rate::SCALE` decimal places.
	 * @throws UnknownCurrencyException When either code is malformed or not served.
	 * @throws RatesUnavailableException When either currency has no stored rate.
	 */
	public function rate( $from_code, $to_code ) {
		$from = $this->supported_code( $from_code );
		$to   = $this->supported_code( $to_code );

		if ( $from === $to ) {
			return Rate::IDENTITY;
		}

		$map       = $this->map();
		$from_rate = self::rate_from_map( $map, $from );
		$to_rate   = self::rate_from_map( $map, $to );

		if ( Currencies::BASE === $from ) {
			return $to_rate;
		}

		return self::round_half_up( bcdiv( $to_rate, $from_rate, self::WORKING_SCALE ), Rate::SCALE );
	}

	/**
	 * Discard everything memoised on this instance.
	 *
	 * Needed by exactly one kind of caller: a process that outlives a web request and writes
	 * rates while holding a converter — the CLI running an update and then a conversion, or
	 * cron doing the same. A web request never needs it, because the instance does not
	 * survive the response.
	 *
	 * @return void
	 */
	public function flush() {
		$this->map       = null;
		$this->supported = null;

		// The repository memoises the same query one layer down; clearing only this side
		// would refill from a map that is just as stale.
		$this->rates->flush();
	}

	/**
	 * The rate map for the stored base, read once per instance.
	 *
	 * Values are canonicalised to `Rate::SCALE` on the way in, so the arithmetic below never
	 * has to wonder what shape a row was in. A value the column should never have held —
	 * zero, negative, malformed — is dropped rather than allowed through: zero would be a
	 * division by zero on the first cross-rate out of that currency, and a negative rate is
	 * not a rate. The pair then reads as "no rate stored" and throws
	 * `RatesUnavailableException`, whose message says to re-run the sync, which is the fix in
	 * both cases. One unusable row costs that currency, not all thirty-three.
	 *
	 * @return array<string, string> Rates keyed by target code; empty when none are stored.
	 */
	private function map() {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$map = array();

		foreach ( $this->rates->map( Currencies::BASE ) as $code => $value ) {
			try {
				$map[ (string) $code ] = Rate::normalize_value( $value );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
		}

		$this->map = $map;

		return $this->map;
	}

	/**
	 * The currency codes the module serves, read once per instance.
	 *
	 * @return array<int, string> Upper-case codes.
	 */
	private function supported() {
		if ( null === $this->supported ) {
			$this->supported = Currencies::codes();
		}

		return $this->supported;
	}

	/**
	 * Normalise a code and insist the module serves it.
	 *
	 * Two failures, kept apart: `Currency::normalize_code()` rejects anything that is not
	 * three letters — listing the supported codes back at someone who passed `'EURO'` answers
	 * a question they did not ask — and membership failure names what would have worked.
	 *
	 * @param string $code Currency code, any case.
	 * @return string The code, upper case.
	 * @throws UnknownCurrencyException When the code is malformed or not on the list.
	 */
	private function supported_code( $code ) {
		$normalized = Currency::normalize_code( $code );

		if ( ! in_array( $normalized, $this->supported(), true ) ) {
			throw UnknownCurrencyException::for_code( $normalized, $this->supported() );
		}

		return $normalized;
	}

	/**
	 * Read one currency's rate out of the map, or say why it is not there.
	 *
	 * @param array<string, string> $map  The memoised rate map.
	 * @param string                $code Currency code, already normalised and served.
	 * @return string The rate, with exactly `Rate::SCALE` decimal places.
	 * @throws RatesUnavailableException When the map is empty, or has no rate for the code.
	 */
	private static function rate_from_map( array $map, $code ) {
		if ( array() === $map ) {
			// Nothing at all is stored: the sync has never completed. A different message
			// from the one below, because it has a different fix.
			throw RatesUnavailableException::nothing_stored( Currencies::BASE );
		}

		if ( ! isset( $map[ $code ] ) ) {
			throw RatesUnavailableException::for_pair( Currencies::BASE, $code );
		}

		return $map[ $code ];
	}

	/**
	 * Turn whatever was passed as an amount into a decimal string `bcmath` can read.
	 *
	 * The brief's example passes an int and its signature says float, but the interesting
	 * case is the string: an amount read straight out of a `DECIMAL` column keeps every digit
	 * it had if it is never cast, and casting is exactly what a `float` type declaration on
	 * the parameter would have forced. So the parameter is untyped and the shape is checked
	 * here.
	 *
	 * A float is rendered with `%.12F` and not `%.12f`: the upper-case conversion is
	 * locale-independent, so a site running under a locale with a comma decimal separator
	 * produces `123.456000000000` rather than `123,456000000000`, which `bcmath` would read
	 * as `123`. It is the same trap as binding a rate with `%f`, one layer up.
	 *
	 * `NAN` and `INF` are the values worth naming: both are floats, both survive every
	 * arithmetic operation without complaint, and `sprintf` renders them as the strings `NAN`
	 * and `INF`, which `bcmath` reads as zero. A conversion would then quietly return 0.0.
	 *
	 * @param float|int|string $amount Whatever the caller passed.
	 * @return string Decimal string, no exponent.
	 * @throws InvalidAmountException When the value is not a finite number.
	 */
	private static function amount_to_decimal( $amount ) {
		if ( is_int( $amount ) ) {
			return (string) $amount;
		}

		if ( is_float( $amount ) ) {
			if ( ! is_finite( $amount ) ) {
				throw InvalidAmountException::not_finite( $amount );
			}

			return sprintf( '%.' . Rate::SCALE . 'F', $amount );
		}

		if ( is_string( $amount ) ) {
			$trimmed = trim( $amount );

			// Already the shape bcmath wants: pass it through with every digit intact.
			if ( 1 === preg_match( self::AMOUNT_PATTERN, $trimmed ) ) {
				return $trimmed;
			}

			// Numeric but not in that shape — `1e5`, `0x1A` on older parsers, leading `.5`.
			// A float round trip is lossy, and it is still the honest reading of the value.
			if ( is_numeric( $trimmed ) ) {
				return self::amount_to_decimal( (float) $trimmed );
			}
		}

		throw InvalidAmountException::not_numeric( $amount );
	}

	/**
	 * Round a decimal string half-up, at the given scale.
	 *
	 * `bcmath` truncates rather than rounds — `bcadd( '0.5', '0', 0 )` is `0` — so half-up is
	 * built the way it is defined: add half of the last kept place, then truncate. Half of
	 * `10^-scale` is `0.0…05`, one digit narrower than the value being added to, and `bcadd`
	 * does the truncation itself when told the scale.
	 *
	 * Negative amounts round *away from zero*, matching what PHP's own `round()` does with
	 * `PHP_ROUND_HALF_UP`, so a refund of `-2.5` and a charge of `2.5` land the same distance
	 * from zero rather than the pair drifting a cent apart.
	 *
	 * @param string $value Decimal string, as `bcmath` returned it.
	 * @param int    $scale Decimal places to keep.
	 * @return string The rounded value, with exactly `$scale` decimal places.
	 */
	private static function round_half_up( $value, $scale ) {
		$half = '0.' . str_repeat( '0', $scale ) . '5';

		if ( 0 === strncmp( $value, '-', 1 ) ) {
			return bcsub( $value, $half, $scale );
		}

		return bcadd( $value, $half, $scale );
	}
}
