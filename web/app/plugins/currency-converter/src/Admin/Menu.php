<?php
/**
 * Admin menu registration and the per-screen setup that has to happen on `load-{$hook}`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the two admin screens and the callbacks their list table depends on.
 *
 * Almost everything awkward about `WP_List_Table` is a timing problem, and this class is
 * where the timing is arranged. `WP_List_Table` is a private core class — `@access private`
 * in its own docblock — which means it is not loaded for you, not guaranteed to be loaded at
 * all, and its cooperation with the screen is arranged through side effects in its
 * constructor. Three of those are load-bearing:
 *
 * **`class-wp-list-table.php` is required inside `load-{$hook}`, never at file level.** The
 * file lives under `wp-admin/includes/`, which is not loaded on the front end, in a cron run
 * or under WP-CLI. A file-level `require_once` in a plugin therefore fatals every one of
 * those contexts — and this plugin runs in all three, because the sync is a cron job and the
 * CLI is a deliverable. Requiring it inside the load hook means the file is fetched exactly
 * when a human is looking at that screen and never otherwise.
 *
 * **The table is instantiated in the same callback, before `add_screen_option()` can matter.**
 * `WP_List_Table::__construct()` resolves the current screen and registers the column filters
 * against it; `add_screen_option( 'per_page' )` is only honoured while the screen is being
 * set up, which is what `load-{$hook}` *is*. Register the option from the render callback
 * instead and the Screen Options panel is built before it is ever added, so the control does
 * not appear and a per-page choice already saved by the user is silently ignored — the screen
 * still renders 50 rows and nothing anywhere reports a problem.
 *
 * **`set_screen_option_{$option}` has to exist before the POST that saves it is processed.**
 * `set_screen_options()` runs early in `wp-admin/admin.php`, well before `admin_menu`, so
 * this filter is added at plugin load rather than alongside the menu. Without it, WordPress
 * has no idea the option is ours, refuses to save it, and the Screen Options box resets to
 * the default on every submit.
 *
 * Hook registration is unconditional and not wrapped in `is_admin()`. It does not need to be:
 * `admin_menu`, `load-{$hook}` and `set_screen_option_*` only ever fire in an admin request,
 * so the guard would add a branch that can only be wrong.
 */
final class Menu {

	/**
	 * Slug of the rates screen. Fixed by R7: `admin.php?page=currency-rates`.
	 */
	const RATES_SLUG = 'currency-rates';

	/**
	 * Slug of the settings screen.
	 */
	const SETTINGS_SLUG = 'currency-settings';

	/**
	 * Capability both screens require.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * User meta key the per-page choice is stored under.
	 */
	const PER_PAGE_OPTION = 'currency_converter_rates_per_page';

	/**
	 * Rows per page before anyone changes it.
	 *
	 * Above today's 33 stored rates, so the first thing a reviewer sees is every rate on one
	 * screen — the literal reading of "all saved exchange rates" — while the pager is there
	 * and correct for the day a paid plan multiplies the row count by the number of bases.
	 */
	const DEFAULT_PER_PAGE = 50;

	/**
	 * The rates screen's hook suffix, captured when the page is added.
	 *
	 * @var string
	 */
	private static $rates_hook = '';

	/**
	 * Register the admin surface.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_pages' ) );

		// Added at plugin load, not on `admin_menu`: the POST that saves a per-page choice is
		// processed before `admin_menu` runs. Returning the value is what saves it; returning
		// the incoming $status — the default — silently discards it.
		add_filter(
			'set_screen_option_' . self::PER_PAGE_OPTION,
			/**
			 * Accept the submitted per-page value.
			 *
			 * @param mixed  $status Whatever a previous filter decided; ignored.
			 * @param string $option Option being saved.
			 * @param int    $value  Submitted value.
			 * @return int The value to store.
			 */
			static function ( $status, $option, $value ) {
				unset( $status, $option );

				return max( 1, min( 500, (int) $value ) );
			},
			10,
			3
		);

		SettingsPage::register();
		UpdateAction::register();
		ConvertAjax::register();
	}

	/**
	 * Add the menu and both screens.
	 *
	 * @return void
	 */
	public static function add_pages() {
		$hook = add_menu_page(
			__( 'Currency rates', 'currency-converter' ),
			__( 'Currencies', 'currency-converter' ),
			self::CAPABILITY,
			self::RATES_SLUG,
			array( RatesPage::class, 'render' ),
			'dashicons-chart-line',
			80
		);

		// Without this, the first submenu entry repeats the menu title. Same slug and same
		// callback, so it is the same screen — this only names it.
		add_submenu_page(
			self::RATES_SLUG,
			__( 'Currency rates', 'currency-converter' ),
			__( 'Saved rates', 'currency-converter' ),
			self::CAPABILITY,
			self::RATES_SLUG,
			array( RatesPage::class, 'render' )
		);

		add_submenu_page(
			self::RATES_SLUG,
			__( 'Currency converter settings', 'currency-converter' ),
			__( 'Settings', 'currency-converter' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( SettingsPage::class, 'render' )
		);

		// False when the current user cannot see the page. Hooking `load-` on that is
		// harmless but pointless, and the string would be `load-`.
		if ( is_string( $hook ) && '' !== $hook ) {
			self::$rates_hook = $hook;

			add_action( 'load-' . $hook, array( self::class, 'load_rates_screen' ) );
		}
	}

	/**
	 * Set up the rates screen. Everything here is timing-critical; see the class docblock.
	 *
	 * @return void
	 */
	public static function load_rates_screen() {
		// Inside the load callback, never at file level: `wp-admin/includes/` is not loaded
		// on the front end, in cron, or under WP-CLI, and this plugin runs in all three.
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Rates per page', 'currency-converter' ),
				'default' => self::DEFAULT_PER_PAGE,
				'option'  => self::PER_PAGE_OPTION,
			)
		);

		// Constructed here rather than in the render callback. The constructor is what binds
		// the table to the current screen and registers its column filters, and by render
		// time the Screen Options panel has already been built.
		RatesPage::table();
	}

	/**
	 * The rates screen's hook suffix.
	 *
	 * @return string Hook suffix, or an empty string before `admin_menu` has run.
	 */
	public static function rates_hook() {
		return self::$rates_hook;
	}

	/**
	 * URL of the rates screen.
	 *
	 * @return string Admin URL.
	 */
	public static function rates_url() {
		return admin_url( 'admin.php?page=' . self::RATES_SLUG );
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string Admin URL.
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
	}
}
