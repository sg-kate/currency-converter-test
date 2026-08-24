<?php
/**
 * `[currency_convert]`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency;

use Drozd\Currency\Service\AmountFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * `[currency_convert amount="123" from="USD" to="RUB"]` — the service, outside wp-admin.
 *
 * Its purpose is demonstration: the same `convert()` the admin widget and the CLI call,
 * reached from a post. So it is deliberately twenty lines of work and no more — no formatting
 * options, no currency picker, no caching, no template. Anything larger would be a front-end
 * feature, and the brief asks for a service and an admin page.
 *
 * **A failure renders nothing.** An unknown currency or a missing rate throws, and on a public
 * page the exception message is the wrong answer twice over: it puts internal detail
 * ("run `wp currency rates update --force`") in front of a reader who cannot act on it, and it
 * lets a shortcode attribute steer text onto the page. Visitors get an empty string; an
 * administrator looking at the same page gets the message, because they are the person who can
 * fix it.
 *
 * Escaped on output like anything else. `shortcode_atts()` sanitises nothing on its own — it
 * fills in defaults — so every attribute is treated as what it is: text from a post, which on
 * a multi-author site is text from someone who is not an administrator.
 */
final class Shortcode {

	/**
	 * The shortcode tag.
	 */
	const TAG = 'currency_convert';

	/**
	 * Register the shortcode.
	 *
	 * On `init`, which is where shortcodes belong: earlier and the textdomain is not loaded,
	 * later and `the_content` may already have run.
	 *
	 * @return void
	 */
	public static function register() {
		add_action(
			'init',
			static function () {
				add_shortcode( self::TAG, array( self::class, 'render' ) );
			}
		);
	}

	/**
	 * Render one conversion.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string The converted amount, escaped, or an empty string on failure.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'amount' => '1',
				'from'   => Currencies::BASE,
				'to'     => 'EUR',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$amount = sanitize_text_field( $atts['amount'] );
		$from   = sanitize_text_field( $atts['from'] );
		$to     = sanitize_text_field( $atts['to'] );

		try {
			$result = currency_converter()->convert( $amount, $from, $to );
			$phrase = AmountFormatter::shared()->phrase( $amount, $result, $from, $to );
		} catch ( \Throwable $e ) {
			// The reader gets nothing; whoever can fix it gets the reason.
			//
			// `\Throwable` and not `ExceptionInterface`, because the module's own exceptions
			// are not the only thing `convert()` can raise: `CurrencyConverter::__construct()`
			// throws a plain `\RuntimeException` when `bcmath` is missing, which this caught
			// nothing of. A shortcode renders inside somebody else's page, so anything that
			// escapes here takes down the whole post rather than one span — and this plugin
			// ships as a zip to hosts whose extension set nobody here chose. Degrading to an
			// empty span is always better than a white page.
			return current_user_can( 'manage_options' )
				? '<span class="currency-convert-error">' . esc_html( $e->getMessage() ) . '</span>'
				: '';
		}

		return '<span class="currency-convert">' . esc_html( $phrase ) . '</span>';
	}
}
