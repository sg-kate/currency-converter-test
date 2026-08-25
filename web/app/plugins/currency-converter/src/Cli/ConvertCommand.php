<?php
/**
 * `wp currency convert`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cli;

use Drozd\Currency\DemoMode;
use Drozd\Currency\Service\CurrencyConverter;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The brief's own call, from a shell.
 *
 * `$converter->convert( 123, 'USD', 'RUB' )` is the signature the brief asks for, and this is
 * that object with its arguments taken from `argv`. Nothing is reimplemented here — no
 * arithmetic, no rounding, no rate lookup — so a disagreement between this command and the
 * service is impossible rather than unlikely.
 */
final class ConvertCommand {

	/**
	 * Convert an amount between two currencies.
	 *
	 * Fails, loudly and non-zero, when it cannot: an unknown currency and a currency with no
	 * stored rate are different problems with different fixes and are reported as such. It
	 * never falls back to a rate of 1 or returns the input unchanged.
	 *
	 * ## OPTIONS
	 *
	 * <amount>
	 * : The amount to convert. Passed through as typed — a decimal string keeps every digit,
	 * where a float would lose the last of them.
	 *
	 * <from>
	 * : Source currency code, any case.
	 *
	 * <to>
	 * : Target currency code, any case.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: plain
	 * options:
	 *   - plain
	 *   - json
	 *   - value
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp currency convert 123 USD RUB
	 *     wp currency convert 123 USD RUB --format=value
	 *     wp currency convert 19.99 EUR JPY --format=json
	 *
	 * @param array<int, string>    $args       Amount, source code, target code.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		list( $amount, $from, $to ) = $args;

		$converter = CurrencyConverter::from_config();

		try {
			$result = $converter->convert( $amount, $from, $to );
			$rate   = $converter->rate( $from, $to );
		} catch ( \Throwable $e ) {
			// `\Throwable`, not the module's own `ExceptionInterface`. Those all carry a
			// message written for a person and, where there is one, the command that fixes
			// it — but `CurrencyConverter::__construct()` throws a plain `\RuntimeException`
			// when `bcmath` is absent, and catching only the interface let that escape as an
			// uncaught fatal instead of the explanation it took care to write. A terminal is
			// the right place for the full message, so it goes through as-is. Non-zero exit.
			WP_CLI::error( $e->getMessage() );

			return;
		}

		$from_code = strtoupper( trim( (string) $from ) );
		$to_code   = strtoupper( trim( (string) $to ) );
		$format    = (string) Utils\get_flag_value( $assoc_args, 'format', 'plain' );

		if ( 'json' === $format ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'amount' => (string) $amount,
						'from'   => $from_code,
						'to'     => $to_code,
						'result' => $result,
						'rate'   => $rate,
						'demo'   => DemoMode::is_active(),
					)
				)
			);
		} elseif ( 'value' === $format ) {
			// Nothing but the number, for `$(wp currency convert …)` in a script.
			WP_CLI::line( (string) $result );
		} else {
			WP_CLI::line( sprintf( '%s %s = %s %s', $amount, $from_code, $result, $to_code ) );
			WP_CLI::line( sprintf( 'rate 1 %s = %s %s', $from_code, $rate, $to_code ) );
		}

		if ( DemoMode::is_active() ) {
			// The answer is arithmetically correct and the input was invented. Said every
			// time, because a number in a terminal outlives the session it came from — and
			// said for `--format=value` too: `WP_CLI::warning()` writes to stderr, so the
			// operator sees it while `$(wp currency convert … --format=value)` still captures
			// nothing but the number.
			WP_CLI::warning( DemoMode::warning() );
		}
	}
}
