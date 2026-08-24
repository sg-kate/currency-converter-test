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
		 * The endpoint and the nonce the front-end converter needs. `wp_rest` is attached
		 * even though the route is public: without it a logged-in visitor's request is
		 * treated as anonymous, and any `determine_current_user` filtering on the site
		 * would see a different user than the page was rendered for.
		 */
		wp_localize_script(
			self::VIEW_HANDLE,
			'currencyConverterBlock',
			array(
				'endpoint' => rest_url( ConvertController::NAMESPACE_ . ConvertController::ROUTE ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
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
		$base = defined( 'CURRENCY_CONVERTER_URL' ) ? CURRENCY_CONVERTER_URL : plugin_dir_url( dirname( __DIR__ ) . '/currency-converter.php' );

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

		$rates = $show_table
			? $repository->all(
				array(
					'base_code' => $base,
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
			$html .= self::render_converter( $base );
		}

		if ( $show_table ) {
			$html .= self::render_table( $rates, $formatter );
		}

		if ( $show_meta ) {
			$html .= self::render_meta( $repository, $base );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * The converter form.
	 *
	 * Rendered as a real form with real values, so it shows a conversion before any
	 * JavaScript runs and keeps showing one if the script never loads. The script upgrades
	 * it; it is not what makes it work.
	 *
	 * @param string $base Base currency code, already validated.
	 * @return string HTML.
	 */
	private static function render_converter( $base ) {
		$codes   = Currencies::codes();
		$default = in_array( 'EUR', $codes, true ) ? 'EUR' : (string) reset( $codes );

		$options_from = '';
		$options_to   = '';

		foreach ( Currencies::all() as $code => $name ) {
			$label = sprintf( '%s — %s', $code, $name );

			$options_from .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $code ),
				esc_html( $label ),
				selected( $code, $base, false )
			);

			$options_to .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $code ),
				esc_html( $label ),
				selected( $code, $default, false )
			);
		}

		return sprintf(
			'<form class="currency-rates-block__converter" method="get" action="">
				<div class="currency-rates-block__field">
					<label for="%1$s">%2$s</label>
					<input type="text" inputmode="decimal" id="%1$s" class="currency-rates-block__amount" value="1" />
				</div>
				<div class="currency-rates-block__field">
					<label for="%3$s">%4$s</label>
					<select id="%3$s" class="currency-rates-block__from">%5$s</select>
				</div>
				<div class="currency-rates-block__field">
					<label for="%6$s">%7$s</label>
					<select id="%6$s" class="currency-rates-block__to">%8$s</select>
				</div>
				<p class="currency-rates-block__result" aria-live="polite"></p>
			</form>',
			esc_attr( 'cc-amount-' . wp_unique_id() ),
			esc_html__( 'Amount', 'currency-converter' ),
			esc_attr( 'cc-from-' . wp_unique_id() ),
			esc_html__( 'From', 'currency-converter' ),
			$options_from,
			esc_attr( 'cc-to-' . wp_unique_id() ),
			esc_html__( 'To', 'currency-converter' ),
			$options_to
		);
	}

	/**
	 * The rates table.
	 *
	 * @param array<int, Rate> $rates     Rates to render.
	 * @param AmountFormatter  $formatter Formatter, for the currency column.
	 * @return string HTML.
	 */
	private static function render_table( array $rates, AmountFormatter $formatter ) {
		if ( array() === $rates ) {
			return '<p class="currency-rates-block__empty">'
				. esc_html__( 'No exchange rates are stored yet.', 'currency-converter' )
				. '</p>';
		}

		$rows = '';

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof Rate ) {
				continue;
			}

			$currency = $formatter->currency( $rate->target_code() );

			$rows .= sprintf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td class="currency-rates-block__value">%3$s</td></tr>',
				esc_html( $rate->target_code() ),
				esc_html( '' === $currency->name() ? '—' : $currency->name() ),
				esc_html( self::trim_rate( $rate->value() ) )
			);
		}

		return sprintf(
			'<table class="currency-rates-block__table"><caption class="screen-reader-text">%1$s</caption>
				<thead><tr><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th></tr></thead>
				<tbody>%5$s</tbody></table>',
			esc_html__( 'Stored exchange rates', 'currency-converter' ),
			esc_html__( 'Code', 'currency-converter' ),
			esc_html__( 'Currency', 'currency-converter' ),
			esc_html__( 'Rate', 'currency-converter' ),
			$rows
		);
	}

	/**
	 * The provenance line under the table.
	 *
	 * Carries the demo-mode warning when one applies. A page that silently showed fixture
	 * rates as though they were live would be the worst outcome this module can produce, so
	 * the warning travels with the data wherever it is rendered.
	 *
	 * @param WpdbRateRepository $repository Rate storage.
	 * @param string             $base       Base currency code.
	 * @return string HTML.
	 */
	private static function render_meta( WpdbRateRepository $repository, $base ) {
		$fetched = $repository->last_fetched_at( $base );

		if ( null === $fetched ) {
			return '';
		}

		$ago = human_time_diff( $fetched->getTimestamp(), time() );

		$line = sprintf(
			/* translators: %s: human-readable interval, e.g. "2 hours". */
			esc_html__( 'Updated %s ago', 'currency-converter' ),
			esc_html( $ago )
		);

		$demo = DemoMode::details();

		if ( null !== $demo ) {
			$line .= ' · ' . esc_html( DemoMode::warning() );
		}

		return '<p class="currency-rates-block__meta">' . $line . '</p>';
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
