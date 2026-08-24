<?php
/**
 * The currency is real; the rate for it is not in the database.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A known currency with no stored rate, or an empty rate table.
 *
 * This type exists so that "the currency is known but has no rate" gets its own message.
 * It is a *runtime* condition, not a bad argument: the call was correct, and the same call
 * will succeed once the sync has run. Rolling it into `UnknownCurrencyException` would tell
 * an operator to fix their code when what they need to fix is a cron job or an API key.
 *
 * Never resolved by falling back. A missing rate is not a rate of 1, and it is not the
 * previous rate presented as current — a converter with no rate for a pair throws, which is
 * the rule in `.claude/agents/_TASK_CONTRACT.md` that overrides "make it work". The cost of
 * throwing is an error someone reads; the cost of a silent 1:1 is a wrong price nobody sees.
 *
 * Two named constructors, because the two cases have different fixes: an empty table means
 * the sync has never run, while one missing pair on a populated table means the API stopped
 * serving that currency.
 */
final class RatesUnavailableException extends \RuntimeException implements ExceptionInterface {

	/**
	 * The base currency of the rate that was missing.
	 *
	 * @var string
	 */
	private $base_code;

	/**
	 * The target currency of the rate that was missing, empty when the table had nothing.
	 *
	 * @var string
	 */
	private $target_code;

	/**
	 * Constructor.
	 *
	 * Prefer the named constructors below.
	 *
	 * @param string $message     Human-readable description, safe to log.
	 * @param string $base_code   Base currency code of the missing rate.
	 * @param string $target_code Target currency code, empty when nothing at all is stored.
	 */
	public function __construct( $message, $base_code = '', $target_code = '' ) {
		parent::__construct( $message );

		$this->base_code   = (string) $base_code;
		$this->target_code = (string) $target_code;
	}

	/**
	 * A recognised currency with no rate stored against the base.
	 *
	 * @param string $base_code   Base currency code, normally USD.
	 * @param string $target_code The known currency that has no rate.
	 * @return self The exception, ready to throw.
	 */
	public static function for_pair( $base_code, $target_code ) {
		return new self(
			sprintf(
				'%1$s is a known currency, but no %2$s to %1$s rate is stored. Run `wp currency rates update --force` to fetch one.',
				(string) $target_code,
				(string) $base_code
			),
			$base_code,
			$target_code
		);
	}

	/**
	 * Nothing at all is stored for the base currency.
	 *
	 * @param string $base_code Base currency code that has no rows.
	 * @return self The exception, ready to throw.
	 */
	public static function nothing_stored( $base_code ) {
		return new self(
			sprintf(
				'No exchange rates are stored for base %s. The daily sync has not completed successfully yet; run `wp currency rates update --force`.',
				(string) $base_code
			),
			$base_code
		);
	}

	/**
	 * The base currency of the rate that could not be found.
	 *
	 * @return string Base currency code.
	 */
	public function base_code() {
		return $this->base_code;
	}

	/**
	 * The target currency of the rate that could not be found.
	 *
	 * @return string Target currency code, empty when nothing at all was stored.
	 */
	public function target_code() {
		return $this->target_code;
	}
}
