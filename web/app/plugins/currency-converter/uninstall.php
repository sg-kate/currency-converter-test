<?php
/**
 * Uninstall: remove the tables, the options and the scheduled events.
 *
 * This file runs with the plugin *not* loaded — WordPress includes it directly, so
 * nothing from the main plugin file has executed and no class is available yet. It
 * loads the autoloader itself.
 *
 * Single site only, deliberately: this project is not a multisite install, and looping
 * over `get_sites()` here would be code nobody has ever run.
 *
 * @package Currency_Converter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/autoload.php';

wp_clear_scheduled_hook( Drozd\Currency\Plugin::CRON_HOOK_RATES );
wp_clear_scheduled_hook( Drozd\Currency\Plugin::CRON_HOOK_CURRENCIES );

Drozd\Currency\Db\Schema::drop();

$currency_converter_options = array(
	Drozd\Currency\Db\Schema::VERSION_OPTION,
	// The key first in intent if not in order: uninstalling must not leave a live API
	// credential behind in a database that is about to be dumped, migrated or handed over.
	Drozd\Currency\Api\ApiKey::OPTION,
	Drozd\Currency\Api\FreeCurrencyApiClient::QUOTA_OPTION,
	Drozd\Currency\Service\RateUpdater::LAST_SYNC_OPTION,
	Drozd\Currency\Service\RateUpdater::CURRENCIES_SYNCED_OPTION,
	Drozd\Currency\DemoMode::OPTION,
);

foreach ( $currency_converter_options as $currency_converter_option ) {
	delete_option( $currency_converter_option );
}

// The per-page choice the admin list table stores against each user. `delete_metadata()`
// with `$delete_all` is the one call that removes it for every user without enumerating
// them, and the fourth argument must be an empty string for that form to be accepted.
delete_metadata( 'user', 0, Drozd\Currency\Admin\Menu::PER_PAGE_OPTION, '', true );

unset( $currency_converter_options, $currency_converter_option );
