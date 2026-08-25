<?php
/**
 * The `currency-converter/rates` block: stored rates, and a converter over them.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Block;

use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Rest\ConvertController;
use Drozd\Currency\Service\AmountFormatter;
use Drozd\Currency\Service\CurrencyConverter;

defined( 'ABSPATH' ) || exit;

/**
 * A dynamic block that renders the exchange rates and, optionally, a converter.
 *
 * **Dynamic, not static.** A block that saved its markup would freeze whatever the rates
 * were the day the post was edited, and go on showing them — with no way for the daily sync
 * to reach them — until somebody re-opened the editor. Rates are the one kind of content
 * that must never be saved into post content, so `save` returns null and every render goes
 * through `render()` here.
 *
 * **No build step.** The editor script is plain ES5 against the `wp.*` globals, not JSX.
 * This plugin ships as a zip to sites that have no `node_modules` and no `vendor/` (see the
 * autoloader), and a block that needed `npm run build` before it could be installed would
 * contradict that. The cost is `wp.element.createElement` written out longhand; the benefit
 * is that the zip is the deliverable, with nothing to compile.
 *
 * The converter half talks to `Rest\ConvertController` rather than doing its own
 * arithmetic — see that class for why the browser is not allowed to multiply.
 */
final class RatesBlock {

	/**
	 * Block name, namespaced as WordPress requires.
	 */
	const NAME = 'currency-converter/rates';

	/**
	 * Handle for the editor script.
	 */
	const EDITOR_HANDLE = 'currency-converter-block-editor';

	/**
	 * Handle for the front-end script.
	 */
	const VIEW_HANDLE = 'currency-converter-block-view';

	/**
	 * Handle for the stylesheet, shared by the editor and the front end.
	 */
	const STYLE_HANDLE = 'currency-converter-block';

	/**
	 * Register the block and its assets.
	 *
	 * On `init`, which is where block registration belongs and is also where the shortcode
	 * registers — both need the textdomain loaded and both must be in place before any
	 * content is rendered.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Do the registration.
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		self::register_assets();

		/*
		 * `render_callback` is passed here rather than declared as `render` in block.json,
		 * because block.json's `render` key landed in WordPress 6.1 and this plugin's header
		 * says 6.0. On 6.0 that key is ignored silently and the block renders as an empty
		 * string — a failure that looks like a styling problem and is not.
		 */
		register_block_type(
			self::dir() . '/block.json',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Register the scripts and styles the block needs.
	 *
	 * @return void
	 */
	private static function register_assets() {
		$url     = self::url();
		$version = defined( 'CURRENCY_CONVERTER_VERSION' ) ? CURRENCY_CONVERTER_VERSION : false;

		wp_register_script(
			self::EDITOR_HANDLE,
			$url . '/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			$version,
			true
		);

		wp_register_script( self::VIEW_HANDLE, $url . '/view.js', array(), $version, true );

		wp_register_style( self::STYLE_HANDLE, $url . '/style.css', array(), $version );

		/*
		 * The base-currency choices, localised from `Currencies::CODES` rather than repeated
		 * in JavaScript — a second copy of the list is a second thing to keep in step, and
		 * the one that drifts is always the one nobody is looking at.
		 */
		$choices = array();

		foreach ( Currencies::all() as $code => $name ) {
			$choices[] = array(
				'label' => sprintf( '%s — %s', $code, $name ),
				'value' => $code,
			);
		}

		wp_localize_script(
			self::EDITOR_HANDLE,
			'currencyConverterBlockEditor',
			array( 'currencies' => $choices )
		);

		/*
		 * The endpoint, and deliberately no nonce.
		 *
		 * A `wp_rest` nonce used to be attached here to preserve logged-in identity. That
		 * was a mistake on a route whose `permission_callback` is `__return_true`:
		 * `rest_cookie_check_errors()` validates any `X-WP-Nonce` it is given, even for a
		 * request that needs no authentication, and answers a stale one with a 403
		 * `rest_cookie_invalid_nonce`. Nonces expire in 12 hours and this markup is
		 * cacheable, so a page held in a full-page cache — or simply left open overnight —
		 * turned every conversion into "Cookie check failed" printed at the visitor, on an
		 * endpoint that never needed a nonce to begin with.
		 *
		 * Sending none makes the request plainly anonymous, which is what the route already
		 * assumes. Nothing is lost: the endpoint returns the same public answer either way.
		 */
		wp_localize_script(
			self::VIEW_HANDLE,
			'currencyConverterBlock',
			array(
				'endpoint' => rest_url( ConvertController::NAMESPACE_ . ConvertController::ROUTE ),
				'strings'  => array(
					'error'      => __( 'That conversion is not available.', 'currency-converter' ),
					'converting' => __( 'Converting…', 'currency-converter' ),
				),
			)
		);
	}

	/**
	 * Absolute path to the block's asset directory.
	 *
	 * @return string Directory path, no trailing slash.
	 */
	private static function dir() {
		return dirname( __DIR__, 2 ) . '/blocks/currency-rates';
	}

	/**
	 * URL of the block's asset directory.
	 *
	 * @return string Directory URL, no trailing slash.
	 */
	private static function url() {
		$base = defined( 'CURRENCY_CONVERTER_URL' )
			? CURRENCY_CONVERTER_URL
			// Two levels, matching `dir()`: this file is in `src/Block/`, and the plugin
			// root is two above it. One level lands in `src/`, where nothing is served.
			: plugin_dir_url( dirname( __DIR__, 2 ) . '/currency-converter.php' );

		return rtrim( $base, '/' ) . '/blocks/currency-rates';
	}

	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes, as declared in block.json.
	 * @return string HTML, fully escaped.
	 */
	public static function render( $attributes = array() ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$show_table     = ! isset( $attributes['showTable'] ) || (bool) $attributes['showTable'];
		$show_converter = ! isset( $attributes['showConverter'] ) || (bool) $attributes['showConverter'];
		$show_meta      = ! isset( $attributes['showUpdated'] ) || (bool) $attributes['showUpdated'];
		$limit          = isset( $attributes['limit'] ) ? max( 0, (int) $attributes['limit'] ) : 0;

		$base = isset( $attributes['base'] ) ? (string) $attributes['base'] : Currencies::BASE;
		$base = Currencies::has( $base ) ? strtoupper( $base ) : Currencies::BASE;

		if ( $show_converter ) {
			wp_enqueue_script( self::VIEW_HANDLE );
		}

		wp_enqueue_style( self::STYLE_HANDLE );

		$repository = new WpdbRateRepository();
		$formatter  = AmountFormatter::shared();

		/*
		 * Rows are always read against the stored base, never against `$base`.
		 *
		 * Every rate in the table is stored with `base_code = 'USD'` — that is the only
		 * base the free plan serves, and the converter pivots through it. Querying
		 * `base_code = 'EUR'` therefore matched nothing, and the block rendered "No
		 * exchange rates are stored yet." for 32 of the 33 choices the editor offers,
		 * which reads as a broken sync rather than as a setting doing nothing.
		 *
		 * The base is applied afterwards, as a cross-rate, by `render_table()`.
		 */
		$rates = $show_table
			? $repository->all(
				array(
					'base_code' => Currencies::BASE,
					'orderby'   => 'target_code',
					'order'     => 'ASC',
					'per_page'  => $limit,
					'page'      => 1,
				)
			)
			: array();

		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'currency-rates-block' ) )
			: 'class="currency-rates-block"';

		$html = '<div ' . $wrapper . '>';

		if ( $show_converter ) {
			$html .= self::render_converter( $base, $formatter );
		}

		if ( $show_table ) {
			$html .= self::render_table( $rates, $formatter, $base );
		}

		if ( $show_meta ) {
			$html .= self::render_meta( $repository );
		}

		/*
		 * The provenance warning is NOT part of the meta line.
		 *
		 * It used to be, which meant unchecking "Show when rates were updated" — a
		 * setting about a timestamp — silently published 33 invented fixture rates with
		 * nothing on the page saying they were invented. No block setting may suppress
		 * this, and it renders even when there is no timestamp to show.
		 */
		$html .= self::render_provenance();

		$html .= '</div>';

		return $html;
	}

	/**
	 * The converter form.
	 *
	 * Works without JavaScript, which is the whole reason the controls are named and the
	 * form submits by GET: `render()` reads those parameters back and prints the answer
	 * server-side. `view.js` then removes the page reload. An earlier version of this form
	 * carried no `name` attributes and no handler, so its docblock promised a fallback that
	 * did not exist — the form was decorative the moment JavaScript was unavailable.
	 *
	 * No nonce. It is a read-only GET that changes nothing, and a nonce here would make the
	 * markup uncacheable and expire on a page left open.
	 *
	 * @param string          $base      Base currency code, already validated.
	 * @param AmountFormatter $formatter Formatter for the pre-rendered answer.
	 * @return string HTML.
	 */
	private static function render_converter( $base, AmountFormatter $formatter ) {
		$request = self::request_conversion( $base );

		$options_from = '';
		$options_to   = '';

		foreach ( Currencies::all() as $code => $name ) {
			$label = sprintf( '%s — %s', $code, $name );

			$options_from .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $code ),
				esc_html( $label ),
				selected( $code, $request['from'], false )
			);

			$options_to .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $code ),
				esc_html( $label ),
				selected( $code, $request['to'], false )
			);
		}

		$answer = self::converted_phrase( $request, $formatter );

		$id = 'cc-' . wp_unique_id();

		return sprintf(
			'<form class="currency-rates-block__converter" method="get">
				<div class="currency-rates-block__field">
					<label for="%1$s-amount">%2$s</label>
					<input type="text" inputmode="decimal" name="cc_amount" id="%1$s-amount" class="currency-rates-block__amount" value="%3$s" />
				</div>
				<div class="currency-rates-block__field">
					<label for="%1$s-from">%4$s</label>
					<select name="cc_from" id="%1$s-from" class="currency-rates-block__from">%5$s</select>
				</div>
				<div class="currency-rates-block__field">
					<label for="%1$s-to">%6$s</label>
					<select name="cc_to" id="%1$s-to" class="currency-rates-block__to">%7$s</select>
				</div>
				<noscript><button type="submit">%8$s</button></noscript>
				<p class="currency-rates-block__result" aria-live="polite">%9$s</p>
			</form>',
			esc_attr( $id ),
			esc_html__( 'Amount', 'currency-converter' ),
			esc_attr( $request['amount'] ),
			esc_html__( 'From', 'currency-converter' ),
			$options_from,
			esc_html__( 'To', 'currency-converter' ),
			$options_to,
			esc_html__( 'Convert', 'currency-converter' ),
			esc_html( $answer )
		);
	}

	/**
	 * The conversion this request is asking for, falling back to sane defaults.
	 *
	 * Reading `$_GET` is what makes the form work without JavaScript. Nothing here is
	 * trusted: the amount has to match the converter's own pattern and both codes have to be
	 * currencies the module serves, or the default is used instead.
	 *
	 * @param string $base Base currency code, already validated.
	 * @return array{amount: string, from: string, to: string} The requested conversion.
	 */
	private static function request_conversion( $base ) {
		$codes   = Currencies::codes();
		$default = in_array( 'EUR', $codes, true ) && 'EUR' !== $base ? 'EUR' : (string) reset( $codes );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only GET that changes nothing; every value is validated below.
		$amount = isset( $_GET['cc_amount'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_amount'] ) ) : '';
		$from   = isset( $_GET['cc_from'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_from'] ) ) : '';
		$to     = isset( $_GET['cc_to'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'amount' => 1 === preg_match( CurrencyConverter::AMOUNT_PATTERN, trim( $amount ) ) ? trim( $amount ) : '1',
			'from'   => Currencies::has( $from ) ? strtoupper( $from ) : $base,
			'to'     => Currencies::has( $to ) ? strtoupper( $to ) : $default,
		);
	}

	/**
	 * The answer, rendered server-side so the form is useful before any script runs.
	 *
	 * Failures are silent here rather than explanatory: this string is printed to whoever
	 * is reading the page, and the reasons a conversion fails — a sync that has not run, a
	 * missing extension — are things only an administrator can act on. `view.js` replaces
	 * this text as soon as it loads.
	 *
	 * @param array{amount: string, from: string, to: string} $request   The conversion asked for.
	 * @param AmountFormatter                                 $formatter Display formatting.
	 * @return string The phrase, or an empty string when it cannot be produced.
	 */
	private static function converted_phrase( array $request, AmountFormatter $formatter ) {
		try {
			$converted = currency_converter()->convert( $request['amount'], $request['from'], $request['to'] );

			return $formatter->phrase( $request['amount'], $converted, $request['from'], $request['to'] );
		} catch ( \Throwable $e ) {
			return '';
		}
	}


	/**
	 * The rates table, expressed against the chosen base.
	 *
	 * Rows arrive stored against USD, because that is the only base the plan serves. When the
	 * block asks for another base each rate is divided through the base's own USD rate — the
	 * same cross-rate `CurrencyConverter` computes, obtained from it rather than reimplemented
	 * here so there is one piece of arithmetic in the module and not two.
	 *
	 * @param array<int, Rate> $rates     Rates as stored, base USD.
	 * @param AmountFormatter  $formatter Formatter, for the currency column.
	 * @param string           $base      Base to express the rates against.
	 * @return string HTML.
	 */
	private static function render_table( array $rates, AmountFormatter $formatter, $base ) {
		if ( array() === $rates ) {
			return '<p class="currency-rates-block__empty">'
				. esc_html__( 'No exchange rates are stored yet.', 'currency-converter' )
				. '</p>';
		}

		$converter = currency_converter();
		$rows      = '';

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof Rate ) {
				continue;
			}

			$target = $rate->target_code();

			if ( Currencies::BASE === $base ) {
				$value = $rate->value();
			} else {
				try {
					$value = $converter->rate( $base, $target );
				} catch ( \Throwable $e ) {
					// A pair the converter cannot express is left out rather than shown as
					// zero. One missing row is recoverable; a fabricated rate is not.
					continue;
				}
			}

			$currency = $formatter->currency( $target );

			$rows .= sprintf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td class="currency-rates-block__value">%3$s</td></tr>',
				esc_html( $target ),
				esc_html( '' === $currency->name() ? '—' : $currency->name() ),
				esc_html( self::trim_rate( $value ) )
			);
		}

		return sprintf(
			'<table class="currency-rates-block__table"><caption class="screen-reader-text">%1$s</caption>
				<thead><tr><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th></tr></thead>
				<tbody>%5$s</tbody></table>',
			esc_html__( 'Stored exchange rates', 'currency-converter' ),
			esc_html__( 'Code', 'currency-converter' ),
			esc_html__( 'Currency', 'currency-converter' ),
			/* translators: %s: base currency code, e.g. "USD". */
			esc_html( sprintf( __( 'Rate per 1 %s', 'currency-converter' ), $base ) ),
			$rows
		);
	}


	/**
	 * When the rates were last refreshed.
	 *
	 * Carries no provenance warning: that lives in `render_provenance()`, because it must not
	 * be suppressible by a setting about timestamps. Read against the stored base, since that
	 * is what the sync actually writes.
	 *
	 * @param WpdbRateRepository $repository Rate storage.
	 * @return string HTML, or an empty string when nothing has been fetched.
	 */
	private static function render_meta( WpdbRateRepository $repository ) {
		$fetched = $repository->last_fetched_at( Currencies::BASE );

		if ( null === $fetched ) {
			return '';
		}

		$line = sprintf(
			/* translators: %s: human-readable interval, e.g. "2 hours". */
			esc_html__( 'Updated %s ago', 'currency-converter' ),
			esc_html( human_time_diff( $fetched->getTimestamp(), time() ) )
		);

		return '<p class="currency-rates-block__meta">' . $line . '</p>';
	}

	/**
	 * Where these numbers came from, when that is not the API.
	 *
	 * Unconditional by design. No block attribute reaches it, and it does not depend on there
	 * being a timestamp to show. Demo rates are invented values written so the module can be
	 * demonstrated without a key; a page that published them with nothing saying so would be
	 * the single worst thing this module could do, and it used to be one unchecked box away.
	 *
	 * @return string HTML, or an empty string when the stored rates are live.
	 */
	private static function render_provenance() {
		if ( null === DemoMode::details() ) {
			return '';
		}

		return '<p class="currency-rates-block__demo">'
			. esc_html( DemoMode::warning() )
			. '</p>';
	}


	/**
	 * Drop trailing zeros from a stored rate without touching its precision.
	 *
	 * The column is `DECIMAL(24,12)`, so every value arrives with twelve decimal places and
	 * `0.856700000000` is noise in a table a person reads. Only zeros are removed, and only
	 * from the end — the string is never rounded, and a rate that genuinely needs twelve
	 * places keeps all twelve.
	 *
	 * @param string $value Rate as stored.
	 * @return string The same number, without trailing zeros.
	 */
	private static function trim_rate( $value ) {
		if ( false === strpos( (string) $value, '.' ) ) {
			return (string) $value;
		}

		$trimmed = rtrim( rtrim( (string) $value, '0' ), '.' );

		return '' === $trimmed ? '0' : $trimmed;
	}
}
