<?php
/**
 * The public read-only REST surface the front-end converter talks to.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Rest;

use Drozd\Currency\Currencies;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Exception\ExceptionInterface;
use Drozd\Currency\Service\AmountFormatter;
use Drozd\Currency\Service\CurrencyConverter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /currency-converter/v1/convert` — one conversion, done on the server.
 *
 * **Why an endpoint at all, when the block already renders every rate into the page.**
 * Converting in the browser from that embedded map would be one line of JavaScript and
 * would be wrong: JavaScript has one number type, a float, and the whole module exists
 * because `DECIMAL(24,12)` and `bcmath` are not floats. `Rate` refuses to be one, the
 * repository binds `%s` rather than `%f`, and `convert()` divides last to avoid rounding
 * twice. Handing the final multiplication to a float in the browser would throw all of that
 * away at the last step, and the number the visitor reads is the only one they see.
 *
 * So the arithmetic stays in `CurrencyConverter`, on the server, and the browser asks.
 *
 * **Why it is public.** `Admin\ConvertAjax` registers `wp_ajax_` and never
 * `wp_ajax_nopriv_`, because that widget belongs to an admin screen. This one is the
 * opposite case by design: it backs a block that anonymous visitors read, and it exposes
 * nothing that the block has not already printed into the page. It is read-only, it takes
 * no action, it touches no option, and — importantly — it never reaches freecurrencyapi.com.
 * Every answer comes from the local rates table, so no volume of requests here can spend a
 * single unit of the monthly quota.
 *
 * Every parameter is validated before it reaches the converter: the amount against
 * `CurrencyConverter::AMOUNT_PATTERN`, both codes against `Currency::CODE_PATTERN` and then
 * against the served list. A malformed request is a 400 with a reason, never an exception
 * escaping into a 500.
 */
final class ConvertController {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_ = 'currency-converter/v1';

	/**
	 * Route, relative to the namespace.
	 */
	const ROUTE = '/convert';

	/**
	 * Register the route.
	 *
	 * On `rest_api_init`, which only fires in a REST request.
	 *
	 * @return void
	 */
	public static function register() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					self::NAMESPACE_,
					self::ROUTE,
					array(
						'methods'             => WP_REST_Server::READABLE,

						/*
						 * Public on purpose, and the one thing here that has to be a
						 * deliberate decision rather than a default. See the class docblock:
						 * read-only, local-only, and nothing that is not already on the page.
						 */
						'permission_callback' => '__return_true',
						'callback'            => array( self::class, 'handle' ),
						'args'                => self::args(),
					)
				);
			}
		);
	}

	/**
	 * Argument schema, with validation that runs before the callback.
	 *
	 * @return array<string, array<string, mixed>> Argument definitions.
	 */
	private static function args() {
		return array(
			'amount' => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Amount in the source currency, as a decimal string.', 'currency-converter' ),
				'validate_callback' => array( self::class, 'validate_amount' ),
			),
			'from'   => array(
				'required'          => false,
				'default'           => Currencies::BASE,
				'type'              => 'string',
				'description'       => __( 'Source currency code.', 'currency-converter' ),
				'validate_callback' => array( self::class, 'validate_code' ),
			),
			'to'     => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Target currency code.', 'currency-converter' ),
				'validate_callback' => array( self::class, 'validate_code' ),
			),
		);
	}

	/**
	 * Whether a value is an amount this module will convert.
	 *
	 * Checked against the converter's own pattern rather than `is_numeric()`, so the
	 * endpoint accepts exactly what `convert()` accepts — no exponent notation, no hex, no
	 * leading-plus surprises — and rejects the rest before any work is done.
	 *
	 * @param mixed $value Candidate amount.
	 * @return bool True when it is a plain decimal.
	 */
	public static function validate_amount( $value ) {
		return is_scalar( $value ) && 1 === preg_match( CurrencyConverter::AMOUNT_PATTERN, trim( (string) $value ) );
	}

	/**
	 * Whether a value is a currency code this module serves.
	 *
	 * Two failures kept apart, as everywhere else in the module: the shape first, then
	 * whether the module actually carries it.
	 *
	 * @param mixed $value Candidate code.
	 * @return bool True when the code is well formed and served.
	 */
	public static function validate_code( $value ) {
		return Currency::is_valid_code( $value ) && Currencies::has( $value );
	}

	/**
	 * How much of a failure to tell the caller.
	 *
	 * The module's exception messages are written for whoever can act on them and name
	 * things a stranger has no use for — a WP-CLI command to run, the full list of served
	 * currencies, the state of the daily sync. This route is public and the block prints the
	 * reply straight into the page, so the detail is given only to a reader who could
	 * actually do something with it.
	 *
	 * Separated from `handle()` so the rule can be asserted without standing up a REST
	 * request; it is the one line here whose getting-it-wrong is invisible in testing and
	 * obvious in production.
	 *
	 * @param string $detail The exception's own message.
	 * @return string What to send back.
	 */
	public static function client_message( $detail ) {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return (string) $detail;
		}

		return __( 'That conversion is not available right now.', 'currency-converter' );
	}

	/**
	 * Convert, and answer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The conversion, or an error the client can read.
	 */
	public static function handle( WP_REST_Request $request ) {
		$amount = trim( (string) $request->get_param( 'amount' ) );
		$from   = (string) $request->get_param( 'from' );
		$to     = (string) $request->get_param( 'to' );

		try {
			$converter = currency_converter();
			$formatter = AmountFormatter::shared();

			$converted = $converter->convert( $amount, $from, $to );

			return new WP_REST_Response(
				array(
					'amount'    => $amount,
					'from'      => strtoupper( $from ),
					'to'        => strtoupper( $to ),
					'result'    => $converted,
					'rate'      => $converter->rate( $from, $to ),
					'formatted' => $formatter->format( $converted, $to ),
					'phrase'    => $formatter->phrase( $amount, $converted, $from, $to ),
				),
				200
			);
		} catch ( ExceptionInterface $e ) {
			/*
			 * The module's own failures are the expected ones — no rate stored for a pair,
			 * an amount that got past validation, a code the list dropped between the
			 * validation callback and here. They are a 400 rather than a 500.
			 *
			 * **The message is not passed through to a stranger.** These exceptions are
			 * written for whoever can fix them: `RatesUnavailableException::nothing_stored()`
			 * says "the daily sync has not completed successfully yet; run
			 * `wp currency rates update --force`", and `UnknownCurrencyException` names every
			 * currency the module serves. This route is public and the block prints whatever
			 * comes back straight into the page, so an anonymous visitor typing into a
			 * converter would have been shown a WP-CLI command and the state of the site's
			 * cron.
			 *
			 * `Shortcode::render()` already made this exact call — detail for an
			 * administrator, nothing for a reader — and this is the same rule applied at the
			 * other entry point. The full message still reaches the log below.
			 */
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; the detail has to go somewhere.
				error_log( 'currency-converter: REST convert refused: ' . $e->getMessage() );
			}

			return new WP_Error(
				'currency_converter_cannot_convert',
				self::client_message( $e->getMessage() ),
				array( 'status' => 400 )
			);
		} catch ( \Throwable $e ) {
			/*
			 * Anything else is this site's problem, not the caller's: a missing `bcmath`
			 * throws a plain `RuntimeException` from the converter's constructor, and the
			 * message names the host's configuration. It is logged and answered with a
			 * generic 500, because an endpoint that anonymous visitors can reach must not
			 * narrate the server's internals back to them.
			 */
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; this is the diagnostic path.
				error_log( 'currency-converter: REST convert failed: ' . $e->getMessage() );
			}

			return new WP_Error(
				'currency_converter_unavailable',
				__( 'The converter is unavailable.', 'currency-converter' ),
				array( 'status' => 500 )
			);
		}
	}
}
