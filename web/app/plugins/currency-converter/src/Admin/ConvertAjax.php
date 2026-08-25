<?php
/**
 * The admin conversion widget's endpoint.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Service\CurrencyConverter;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-ajax.php` in front of `Service\CurrencyConverter`.
 *
 * The widget converts through the same object every other caller uses, over the wire. The
 * arithmetic deliberately does not happen in the browser: rates are `DECIMAL(24,12)` and
 * JavaScript has one number type, an IEEE-754 double, which cannot hold them — a widget that
 * multiplied in JavaScript would disagree with `convert()` in the last decimal places and
 * would be demonstrating something other than the module.
 *
 * **Both guards, because they answer different questions.** `check_ajax_referer()` proves the
 * request came from a page this site rendered — it stops another origin from driving the
 * endpoint with the administrator's cookies. `current_user_can()` proves the person behind it
 * is allowed to be here — it stops a logged-in subscriber who read the nonce out of a page
 * they *can* see. Neither implies the other, and a nonce alone is the more common mistake.
 *
 * Only `wp_ajax_` is registered, never `wp_ajax_nopriv_`. The widget belongs to a
 * `manage_options` screen, so there is no logged-out caller to serve, and registering the
 * public variant would publish a rate endpoint the brief did not ask for — a REST endpoint
 * by another name, and a non-goal of the task contract.
 */
final class ConvertAjax {

	/**
	 * The AJAX action name.
	 */
	const ACTION = 'currency_converter_convert';

	/**
	 * The nonce action.
	 */
	const NONCE = 'currency_converter_convert';

	/**
	 * Longest amount string accepted.
	 *
	 * `bcmath` is happy with arbitrarily long input and would spend real time on a megabyte
	 * of digits. `DECIMAL(24,12)` and any plausible amount fit inside this.
	 */
	const MAX_AMOUNT_LENGTH = 32;

	/**
	 * Register the endpoint.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Convert, and answer in JSON.
	 *
	 * @return void
	 */
	public static function handle() {
		// Dies with -1 on failure, which is what the front end expects from admin-ajax.
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to convert currencies.', 'currency-converter' ) ),
				403
			);
		}

		$amount = self::posted( 'amount' );
		$from   = self::posted( 'from' );
		$to     = self::posted( 'to' );

		if ( strlen( $amount ) > self::MAX_AMOUNT_LENGTH ) {
			wp_send_json_error(
				array( 'message' => __( 'That amount is too long to be a number.', 'currency-converter' ) ),
				400
			);
		}

		try {
			$converter = currency_converter();

			$result = $converter->convert( $amount, $from, $to );
			$rate   = $converter->rate( $from, $to );
		} catch ( \Throwable $e ) {
			/*
			 * `\Throwable`, not the module's own `ExceptionInterface`.
			 *
			 * The module's failures all implement that interface and each carries a message
			 * written to be read by a person: which currency is unknown, which pair has no
			 * stored rate, what to do about it. But `currency_converter()` builds the
			 * converter on first use, and `CurrencyConverter::__construct()` throws a plain
			 * `\RuntimeException` when `bcmath` is absent — the one failure this plugin is
			 * most likely to meet, because it ships as a zip to hosts whose extension set
			 * nobody here chose. Catching only the interface turned that into an uncaught
			 * fatal and a 500, so the widget showed its generic string instead of the
			 * explanation the constructor took care to write. `Shortcode` and
			 * `Rest\ConvertController` catch `\Throwable` for the same reason.
			 *
			 * The message goes through as-is, which is safe *here* and only here: this
			 * endpoint is `wp_ajax_` with no `nopriv`, behind a capability check and a
			 * nonce, so the reader is an administrator. The public REST route deliberately
			 * does the opposite. The browser writes it with `textContent`, so a currency code
			 * echoed back inside one is text on the page and never markup.
			 *
			 * 400 and not 500: these are the caller asking for something impossible.
			 */
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );

			return;
		}

		$to_code = strtoupper( $to );

		wp_send_json_success(
			array(
				'amount'    => $amount,
				'from'      => strtoupper( $from ),
				'to'        => $to_code,
				'result'    => $result,
				'formatted' => self::format( $result, $to_code ),
				// A string, like everything else that touches a stored rate.
				'rate'      => $rate,
			)
		);
	}

	/**
	 * Render a converted amount at the target currency's minor-unit scale.
	 *
	 * `number_format_i18n()`, not `NumberFormatter`: `intl` is present in the WP-CLI image
	 * and absent from the web image, so the obvious choice here is the one that passes every
	 * `bin/wp` check and fatals on the first page load (collision C6).
	 *
	 * The symbol is appended rather than positioned by locale. Getting placement right for
	 * every currency is what `intl` is for, and guessing it wrong looks worse than not
	 * trying; the code is always shown, which is unambiguous.
	 *
	 * @param float  $amount The converted amount.
	 * @param string $code   Target currency code.
	 * @return string Something like `11,070.00 RUB`.
	 */
	private static function format( $amount, $code ) {
		$currency = ( new WpdbCurrencyRepository() )->find( $code );
		$digits   = $currency instanceof Currency ? $currency->decimal_digits() : Currency::DEFAULT_DECIMAL_DIGITS;
		$symbol   = $currency instanceof Currency ? $currency->symbol() : '';

		$formatted = number_format_i18n( $amount, $digits ) . ' ' . $code;

		return '' === $symbol ? $formatted : $formatted . ' (' . $symbol . ')';
	}

	/**
	 * One posted field, sanitised.
	 *
	 * The nonce has already been checked by the caller, which is what makes reading `$_POST`
	 * here legitimate. Values are still sanitised and still validated downstream —
	 * `CurrencyConverter` decides what is a currency and what is a number, and says so by
	 * throwing.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value, or an empty string.
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() runs in handle() before this is reached.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
}
