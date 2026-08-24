<?php
/**
 * Plugin lifecycle and hook registration.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency;

use Drozd\Currency\Admin\Menu;
use Drozd\Currency\Admin\RatesPage;
use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Block\RatesBlock;
use Drozd\Currency\Cli\Commands;
use Drozd\Currency\Cron\Scheduler;
use Drozd\Currency\Db\Schema;
use Drozd\Currency\Rest\ConvertController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the module to WordPress and owns the activation and deactivation behaviour.
 */
final class Plugin {

	/**
	 * Cron hook that refreshes the exchange rates. Daily; see R5.
	 */
	const CRON_HOOK_RATES = 'currency_converter_update_rates';

	/**
	 * Cron hook that refreshes the currency metadata. Weekly; the data is near-static.
	 */
	const CRON_HOOK_CURRENCIES = 'currency_converter_update_currencies';

	/**
	 * Register everything the plugin does on an ordinary request.
	 *
	 * Called at the bottom of the main plugin file, so hooks that fire before
	 * `plugins_loaded` are still reachable from here.
	 *
	 * @return void
	 */
	public static function boot() {
		// `register_activation_hook` does not fire when a plugin is updated, so the
		// installed schema version is compared on every load and the installer re-run
		// when it lags. This is the only way a shipped schema change reaches an
		// existing site.
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ) );

		// Not deferred to `plugins_loaded` and not wrapped in any condition: the cron
		// handlers have to be attached in a cron request, which is a front-end request, and
		// the `init` self-heal has to be attached before `init` fires.
		Scheduler::register();

		// Menu registration, the settings API, the admin-post handlers and the AJAX endpoint.
		// Registered unconditionally for the same reason the cron handlers are: every hook it
		// adds — `admin_menu`, `admin_init`, `admin_post_*`, `wp_ajax_*` — only fires in the
		// request type it belongs to, so an `is_admin()` guard could only ever be wrong.
		// `admin-ajax.php` is one of those requests; a front-end AJAX call is not.
		Menu::register();

		add_action( 'admin_enqueue_scripts', array( RatesPage::class, 'enqueue' ) );

		// The service, reachable from a post. Registers on `init`.
		Shortcode::register();

		// The same service as a block, with the rates table beside it. Registers on `init`
		// for the same reasons the shortcode does.
		RatesBlock::register();

		// The block's converter asks the server rather than multiplying in the browser, so
		// it needs a route. Registers on `rest_api_init`, which only fires in a REST
		// request — the guard is the hook, as everywhere else here.
		ConvertController::register();

		// Guarded inside: `WP_CLI::add_command()` does not exist in a web request, so an
		// unguarded call here would be a fatal error on every page of the site.
		Commands::register();
	}

	/**
	 * Activation: create the tables, then schedule the syncs that fill them.
	 *
	 * Order matters. `Scheduler::activate()` queues a run thirty seconds out, so the tables
	 * have to exist first — and they do, because `dbDelta()` has returned by the time the
	 * next line runs.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();
		Scheduler::activate();

		// Creates the key option empty and with `autoload='no'` if it does not exist. Doing
		// it here is what makes the guarantee hold: the Settings API saves through
		// `update_option()` with no autoload argument, which would create a *new* option
		// autoloaded — putting the key in `alloptions` on every page load.
		ApiKey::ensure_option_exists();
	}

	/**
	 * Deactivation: unschedule, and nothing else.
	 *
	 * Deactivation is not uninstall. Dropping tables here would destroy real data
	 * every time someone toggles the plugin off to debug something; that belongs in
	 * `uninstall.php`, which only runs on delete.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::deactivate();
	}
}
