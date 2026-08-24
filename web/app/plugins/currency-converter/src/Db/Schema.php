<?php
/**
 * Database schema.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, upgrades and drops the module's two tables.
 *
 * `dbDelta()` is a parser, not a migration tool. Every rule it enforces is enforced
 * silently: break one and the table is still created, and then an `ALTER` is issued on
 * every single activation. The DDL below is annotated rule by rule, and `install()`
 * returns what `dbDelta()` returned so a second run can be asserted to be empty.
 */
final class Schema {

	/**
	 * Schema version. Bump when the DDL changes so `maybe_upgrade()` re-runs the installer.
	 */
	const VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version. Autoloaded: it is read on every request.
	 */
	const VERSION_OPTION = 'currency_converter_schema_version';

	/**
	 * Currency metadata table: one row per supported currency.
	 *
	 * @param \wpdb|null $wpdb Database handle to take the prefix from. Defaults to the global.
	 * @return string Prefixed table name.
	 */
	public static function currencies_table( $wpdb = null ) {
		return self::db( $wpdb )->prefix . 'cc_currencies';
	}

	/**
	 * Exchange rate table: one row per ordered currency pair.
	 *
	 * @param \wpdb|null $wpdb Database handle to take the prefix from. Defaults to the global.
	 * @return string Prefixed table name.
	 */
	public static function rates_table( $wpdb = null ) {
		return self::db( $wpdb )->prefix . 'cc_rates';
	}

	/**
	 * Resolve the database handle.
	 *
	 * The parameter exists so a repository constructed with an injected `$wpdb` names its
	 * tables from that handle rather than from whatever is global — which is what makes the
	 * repositories testable against a fake, and correct on a multisite `switch_to_blog()`
	 * where the global prefix has moved.
	 *
	 * @param \wpdb|null $wpdb Handle, or null for the global one.
	 * @return \wpdb The handle to use.
	 */
	private static function db( $wpdb = null ) {
		return null === $wpdb ? $GLOBALS['wpdb'] : $wpdb;
	}

	/**
	 * Create or update the tables.
	 *
	 * @return array Table and column names dbDelta changed, keyed by name. Empty means
	 *               the database already matched the DDL, which is the correctness check.
	 */
	public static function install() {
		// Not loaded on the front end or under WP-CLI, and activation can happen in both.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result = dbDelta( self::ddl() );

		update_option( self::VERSION_OPTION, self::VERSION );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// A non-empty array on a second run means a dbDelta rule is broken.
			error_log( 'currency-converter: dbDelta returned ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	/**
	 * Re-run the installer when the shipped schema is newer than the installed one.
	 *
	 * Hooked to `plugins_loaded`, because activation does not fire on plugin update.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Drop both tables. Called from `uninstall.php` only.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array( self::rates_table(), self::currencies_table() ) as $table ) {
			// `prepare()` binds values, not identifiers. These names are built from
			// `$wpdb->prefix` and literals in this file and never touch input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * The DDL, written to dbDelta's rules.
	 *
	 * Where each rule is satisfied:
	 *
	 * - one field per line — the parser splits the body on newlines;
	 * - two spaces after `PRIMARY KEY`, in both tables;
	 * - `KEY`, never `INDEX`;
	 * - every key named — `UNIQUE KEY base_target (...)`, and the primary key needs none;
	 * - field and key names lower case throughout;
	 * - `datetime NULL DEFAULT NULL` for `fetched_at` and `updated_at`, never a zero date,
	 *   which dies under `NO_ZERO_DATE`;
	 * - no `IF NOT EXISTS`;
	 * - `$wpdb->get_charset_collate()` on both tables;
	 * - indexed columns are short: `char(3)` is 12 bytes in utf8mb4 and the composite
	 *   unique key is 24, both far inside the 767-byte limit of the old InnoDB row format.
	 *
	 * `bigint(20)` and `tinyint(3)` carry display widths because MariaDB reports them in
	 * `DESCRIBE`, and dbDelta compares the two strings. MySQL 8 drops display widths, which
	 * is why this stack runs MariaDB — see the comment in `docker-compose.yml`.
	 *
	 * @return string One `CREATE TABLE` statement per table.
	 */
	private static function ddl() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$currencies      = self::currencies_table();
		$rates           = self::rates_table();

		return "CREATE TABLE {$currencies} (
	code char(3) NOT NULL,
	name varchar(64) NOT NULL DEFAULT '',
	symbol varchar(8) NOT NULL DEFAULT '',
	decimal_digits tinyint(3) unsigned NOT NULL DEFAULT 2,
	updated_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (code)
) {$charset_collate};
CREATE TABLE {$rates} (
	id bigint(20) unsigned NOT NULL auto_increment,
	base_code char(3) NOT NULL,
	target_code char(3) NOT NULL,
	rate decimal(24,12) NOT NULL,
	fetched_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY base_target (base_code,target_code)
) {$charset_collate};";
	}
}
