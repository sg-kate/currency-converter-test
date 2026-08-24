<?php
/**
 * The flag that says the stored rates are demonstration data.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Records that the rates in the table came from a fixture, so nothing can pass them off as live.
 *
 * `wp currency rates load-fixture` exists for one situation: showing that the module works on
 * a site that has no API key. That is a convenience, and the risk with any such convenience is
 * that its output becomes indistinguishable from the real thing an hour later — a screenshot
 * in a review, a number quoted in a meeting, a developer who forgets which site they are on.
 *
 * So the fixture loader is not allowed to be quiet. It sets this flag, and while the flag is
 * set every surface that displays a rate says where it came from: the admin table carries a
 * banner, `wp currency rates status` prints it, and `wp currency doctor` reports it as a
 * warning rather than a pass.
 *
 * **What deliberately does not happen** is as important. Loading a fixture does not write
 * `RateUpdater::LAST_SYNC_OPTION`, does not touch the quota reading, and does not stamp the
 * rows with the current time — they carry the fixture's own `captured_at`, weeks in the past.
 * Nothing about the resulting state imitates a completed fetch: the rates read as old because
 * they are old, and `RateUpdater` sees them as stale, so a real sync is never delayed by a
 * demo having been loaded.
 *
 * The flag is cleared by the only thing that earns clearing it — a successful sync against the
 * live API, which overwrites every row it describes.
 */
final class DemoMode {

	/**
	 * Option holding the demo-mode record. Not autoloaded; it is read on our screens only.
	 */
	const OPTION = 'currency_converter_demo';

	/**
	 * Record that fixture data has been loaded.
	 *
	 * @param string             $source      Where the fixture came from, for display.
	 * @param \DateTimeImmutable $captured_at The date the fixture claims for its data.
	 * @return void
	 */
	public static function enable( $source, \DateTimeImmutable $captured_at ) {
		update_option(
			self::OPTION,
			array(
				'source'      => (string) $source,
				'captured_at' => $captured_at->format( 'c' ),
				'loaded_at'   => gmdate( 'c' ),
			),
			'no'
		);
	}

	/**
	 * Clear the flag.
	 *
	 * Called after a successful live sync, which has just overwritten the fixture rows.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Whether the stored rates are demonstration data.
	 *
	 * @return bool True while a fixture is loaded and no live sync has replaced it.
	 */
	public static function is_active() {
		return null !== self::details();
	}

	/**
	 * What was loaded, and when.
	 *
	 * @return array{source: string, captured_at: string, loaded_at: string}|null The record,
	 *                                                                            or null.
	 */
	public static function details() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ! isset( $stored['source'] ) ) {
			return null;
		}

		return array(
			'source'      => (string) $stored['source'],
			'captured_at' => isset( $stored['captured_at'] ) ? (string) $stored['captured_at'] : '',
			'loaded_at'   => isset( $stored['loaded_at'] ) ? (string) $stored['loaded_at'] : '',
		);
	}

	/**
	 * The one sentence every surface repeats.
	 *
	 * Kept here rather than written out at each call site so the admin banner, the CLI and
	 * the doctor cannot drift into saying three different things about the same state.
	 *
	 * @return string The warning.
	 */
	public static function warning() {
		return __( 'Demo data, not live rates.', 'currency-converter' );
	}
}
