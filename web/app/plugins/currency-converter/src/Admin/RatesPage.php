<?php
/**
 * The saved-rates screen.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Service\RateUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the rates table, the count that proves it is complete, and the converter widget.
 *
 * The count above the table is the point of the screen as far as R7 is concerned. It is
 * `SELECT COUNT(*)` on the whole table, printed whether or not a search is active, and it is
 * what makes "all saved exchange rates" a checkable claim in the presence of paging: the
 * number on the screen and the number in the database are the same query.
 *
 * The list table is built once per request and held here. `Menu::load_rates_screen()`
 * constructs it during `load-{$hook}`, which is the only moment `add_screen_option()` is
 * honoured; `render()` then asks for the same instance rather than making a second one,
 * because a second one would re-run every query behind the screen.
 */
final class RatesPage {

	/**
	 * The list table for this request.
	 *
	 * @var RatesListTable|null
	 */
	private static $table = null;

	/**
	 * The list table, built on first use.
	 *
	 * Called from `load-{$hook}` for its timing and from `render()` for its result.
	 *
	 * @return RatesListTable The table.
	 */
	public static function table() {
		if ( ! self::$table instanceof RatesListTable ) {
			self::$table = new RatesListTable();
		}

		return self::$table;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		// Belt to `add_menu_page()`'s braces. The capability is declared there and WordPress
		// enforces it, but a render callback that can be reached by any other route — a
		// direct `do_action`, a future refactor that hangs it somewhere else — must not be
		// the thing that leaks the table.
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view exchange rates.', 'currency-converter' ) );
		}

		$table = self::table();
		$table->prepare_items();

		?>
		<div class="wrap currency-converter-rates">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Currency rates', 'currency-converter' ); ?></h1>
			<a href="<?php echo esc_url( Menu::settings_url() ); ?>" class="page-title-action">
				<?php esc_html_e( 'Settings', 'currency-converter' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php settings_errors( 'currency_converter' ); ?>

			<?php self::render_demo_banner(); ?>

			<?php self::render_summary( $table ); ?>

			<?php self::render_widget(); ?>

			<?php
			/*
			 * `search_box()` renders an input and a submit button and nothing else — no form,
			 * no method, no action. Outside a `<form method="get">` it submits the enclosing
			 * form if there is one and does nothing at all if there is not, which is the
			 * usual way a list-table search silently fails. The hidden `page` field is the
			 * other half: the form submits to `admin.php`, and without it the request
			 * arrives with no `page` argument and lands on the dashboard.
			 */
			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::RATES_SLUG ); ?>">
				<?php
				$table->search_box( __( 'Search codes', 'currency-converter' ), 'currency-rates' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * The banner shown while the table holds fixture data.
	 *
	 * `notice-warning` and not `notice-info`: the table below it looks exactly like a table
	 * of live rates, and the only thing standing between a screenshot of it and someone
	 * quoting an invented number next week is this line. It names the file and the date the
	 * fixture claims, so the state is not merely flagged but explained, and it says what
	 * clears it.
	 *
	 * @return void
	 */
	private static function render_demo_banner() {
		$demo = DemoMode::details();

		if ( null === $demo ) {
			return;
		}

		?>
		<div class="notice notice-warning inline cc-demo-banner">
			<p>
				<strong><?php echo esc_html( DemoMode::warning() ); ?></strong>
				<?php
				printf(
					/* translators: 1: fixture file name, 2: the date the fixture states. */
					esc_html__( 'These rates were loaded from %1$s and are dated %2$s. Nothing was fetched from the API.', 'currency-converter' ),
					'<code>' . esc_html( $demo['source'] ) . '</code>',
					esc_html( self::readable_date( $demo['captured_at'] ) )
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: the WP-CLI command that fetches live rates. */
					esc_html__( 'Run %s, or use Update now on the settings screen, to replace them with live data.', 'currency-converter' ),
					'<code>wp currency rates update</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render an ISO-8601 string in the site's date format.
	 *
	 * @param string $iso The stored timestamp.
	 * @return string Something readable, or the input when it cannot be parsed.
	 */
	private static function readable_date( $iso ) {
		/*
		 * `Rate::datetime_from_string()` rather than `new \DateTimeImmutable( $iso )`.
		 *
		 * Two things were wrong with the constructor. It does not throw on an empty string —
		 * it returns *now* — so the `catch` that existed for exactly that input was dead, and
		 * `DemoMode::details()` yields `'captured_at' => ''` whenever the stored record lacks
		 * the key. The banner whose whole purpose is to stop somebody quoting invented
		 * numbers then announced that the fixture was captured today.
		 *
		 * And it parsed any offset without converting it, while this method appends ' UTC'
		 * unconditionally: `2026-08-20T12:00:00+03:00` printed as `2026-08-20 12:00:00 UTC`,
		 * three hours out. The helper parses in UTC against the module's own format and
		 * returns null rather than guessing.
		 */
		$date = Rate::datetime_from_string( $iso );

		if ( null === $date ) {
			$raw = trim( (string) $iso );

			return '' === $raw ? __( 'an unknown date', 'currency-converter' ) : $raw;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Rate::DATETIME_FORMAT ) . ' UTC';
	}

	/**
	 * The line above the table: how many rates are stored, and how old they are.
	 *
	 * @param RatesListTable $table The prepared table.
	 * @return void
	 */
	private static function render_summary( RatesListTable $table ) {
		$repository = new WpdbRateRepository();
		$total      = $table->total_stored();
		$fetched_at = $repository->last_fetched_at();

		?>
		<div class="notice notice-info inline cc-summary">
			<p>
				<strong>
					<?php
					printf(
						esc_html(
							/* translators: %s: number of stored exchange rates. */
							_n( '%s saved rate', '%s saved rates', $total, 'currency-converter' )
						),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</strong>
				<?php if ( $fetched_at instanceof \DateTimeImmutable ) : ?>
					<span class="cc-summary-age">
						<?php
						printf(
							/* translators: 1: human-readable time difference, 2: UTC timestamp. */
							esc_html__( 'Last updated %1$s ago (%2$s UTC).', 'currency-converter' ),
							esc_html( human_time_diff( $fetched_at->getTimestamp() ) ),
							esc_html( $fetched_at->format( Rate::DATETIME_FORMAT ) )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
			<p class="description">
				<?php
				esc_html_e(
					'Every stored rate is listed here — the count above is the whole table, not this page. Rates refresh once a day.',
					'currency-converter'
				);
				?>
			</p>
		</div>
		<?php
		unset( $repository );
	}

	/**
	 * The conversion widget.
	 *
	 * Deliberately a small thing: it exists so an administrator can check that the stored
	 * rates actually convert, on the screen that shows them, without opening a shell. The
	 * arithmetic is `Service\CurrencyConverter` — the same object every other caller uses —
	 * reached over `admin-ajax.php`; nothing is computed in the browser, because the
	 * `bcmath` precision the module is built around does not survive a round trip through
	 * JavaScript's only number type.
	 *
	 * Rendered with no result and no error: the first response comes from the server.
	 *
	 * @return void
	 */
	private static function render_widget() {
		$currencies = Currencies::all();
		$default_to = isset( $currencies['EUR'] ) ? 'EUR' : (string) array_key_first( $currencies );

		?>
		<div class="cc-widget card">
			<h2><?php esc_html_e( 'Try a conversion', 'currency-converter' ); ?></h2>

			<form class="cc-widget-form" method="post" action="#">
				<label for="cc-amount"><?php esc_html_e( 'Amount', 'currency-converter' ); ?></label>
				<input type="text" id="cc-amount" name="amount" value="123" inputmode="decimal" size="12">

				<label for="cc-from"><?php esc_html_e( 'From', 'currency-converter' ); ?></label>
				<select id="cc-from" name="from">
					<?php foreach ( $currencies as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, Currencies::BASE ); ?>>
							<?php echo esc_html( '' === $name ? $code : $code . ' — ' . $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="cc-to"><?php esc_html_e( 'To', 'currency-converter' ); ?></label>
				<select id="cc-to" name="to">
					<?php foreach ( $currencies as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default_to ); ?>>
							<?php echo esc_html( '' === $name ? $code : $code . ' — ' . $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Convert', 'currency-converter' ); ?>
				</button>

				<?php
				/*
				 * `aria-live` and not a bare div: the result replaces itself in place, so a
				 * screen reader that is not told the region is live announces the first
				 * answer and silently ignores every one after it.
				 */
				?>
				<p class="cc-widget-result" role="status" aria-live="polite"></p>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %s: the converter's PHP call, e.g. currency_converter()->convert( 123, 'USD', 'RUB' ). */
					esc_html__( 'The same call in PHP: %s', 'currency-converter' ),
					'<code>' . esc_html( "currency_converter()->convert( 123, 'USD', 'RUB' )" ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
		unset( $default_to );
	}

	/**
	 * Enqueue the screen's assets.
	 *
	 * Hooked to `admin_enqueue_scripts` and gated on the hook suffix, so nothing here loads
	 * on the other 40 screens of wp-admin.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		$rates    = Menu::rates_hook();
		$settings = Menu::settings_hook();

		$is_rates    = '' !== $rates && $rates === $hook_suffix;
		$is_settings = '' !== $settings && $settings === $hook_suffix;

		if ( ! $is_rates && ! $is_settings ) {
			return;
		}

		/*
		 * The stylesheet goes to both screens, because it styles both. Roughly a third of
		 * `assets/admin.css` — `.cc-status`, `.cc-status th`, `.cc-action`,
		 * `.cc-action .description` — describes markup that only `SettingsPage` emits, and
		 * while this was gated on the rates screen alone that third never loaded anywhere:
		 * the status table had no sizing and the two action forms rendered flush together.
		 */
		wp_enqueue_style(
			'currency-converter-admin',
			plugins_url( 'assets/admin.css', CURRENCY_CONVERTER_FILE ),
			array(),
			CURRENCY_CONVERTER_VERSION
		);

		// The script drives the converter widget, which exists only on the rates screen.
		if ( ! $is_rates ) {
			return;
		}

		wp_enqueue_script(
			'currency-converter-admin',
			plugins_url( 'assets/admin.js', CURRENCY_CONVERTER_FILE ),
			array(),
			CURRENCY_CONVERTER_VERSION,
			true
		);

		wp_localize_script(
			'currency-converter-admin',
			'currencyConverterAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => ConvertAjax::ACTION,
				// A fresh nonce per page load. It is checked server-side by
				// `check_ajax_referer()`; it proves the request came from this screen and is
				// not a substitute for the capability check that runs beside it.
				'nonce'   => wp_create_nonce( ConvertAjax::NONCE ),
				'i18n'    => array(
					'working' => __( 'Converting…', 'currency-converter' ),
					'failed'  => __( 'The conversion could not be completed.', 'currency-converter' ),
				),
			)
		);

		unset( $hook_suffix );
	}

	/**
	 * The last sync's stored timestamp, for the settings screen.
	 *
	 * @return string ISO-8601 string, or an empty string when no sync has completed.
	 */
	public static function last_sync() {
		$stored = get_option( RateUpdater::LAST_SYNC_OPTION, '' );

		return is_string( $stored ) ? $stored : '';
	}
}
