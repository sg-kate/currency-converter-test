<?php
/**
 * The settings screen's buttons: "Update now" and "Delete stored key".
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Service\RateUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Handlers for the two things an administrator can *do* rather than configure.
 *
 * Both go through `admin_post_*` rather than being handled on the settings screen itself,
 * because both change state and a state change must not be reachable by loading a URL: a
 * screen that acted on `$_GET` would fire on a browser prefetch, on a bookmark, and on any
 * image tag pointed at it. `admin-post.php` gives the POST target, and the pair of guards
 * gives the rest —
 *
 * **`current_user_can()` proves permission. `check_admin_referer()` proves intent.** They are
 * not interchangeable and neither is optional. Without the capability check, any logged-in
 * user who guesses the action name spends the site's API quota. Without the nonce, a page on
 * another site can make an administrator's own browser POST here — the cookies ride along and
 * WordPress sees a perfectly authorised request. The nonce is the only thing that
 * distinguishes "the administrator asked for this" from "the administrator's browser was
 * told to ask for this".
 *
 * The outcome is handed back through a short-lived per-user transient rather than a query
 * argument. Sync messages carry drift detail — which codes the API added, which it withdrew —
 * and that does not belong in a URL that ends up in a browser history, a bookmark or a
 * referer header.
 */
final class UpdateAction {

	/**
	 * `admin_post` action that runs a sync.
	 */
	const UPDATE_ACTION = 'currency_converter_update';

	/**
	 * `admin_post` action that deletes the stored key.
	 */
	const FORGET_ACTION = 'currency_converter_forget_key';

	/**
	 * Transient prefix the pending notice is stored under; the user id is appended.
	 */
	const NOTICE_TRANSIENT = 'currency_converter_notice_';

	/**
	 * How long a pending notice survives, in seconds. One redirect is all it has to cross.
	 */
	const NOTICE_TIMEOUT = 60;

	/**
	 * Register both handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( self::class, 'handle_update' ) );
		add_action( 'admin_post_' . self::FORGET_ACTION, array( self::class, 'handle_forget' ) );
	}

	/**
	 * Run a sync, then redirect back to the settings screen with the result.
	 *
	 * The sync is *not* forced. The freshness window is what bounds the module to one API
	 * request a day (R5) and this button is exactly the case it was written for — a control a
	 * person can click twice. A second click inside the window is answered with "skipped:
	 * rates are fresh" and the timestamp, which is the truthful answer and costs no quota.
	 *
	 * @return void
	 */
	public static function handle_update() {
		self::guard( self::UPDATE_ACTION );

		if ( ! ApiKey::is_configured() ) {
			self::notice(
				__( 'No API key is configured, so there is nothing to sync with.', 'currency-converter' ),
				'error'
			);
			self::redirect();
		}

		$updater  = RateUpdater::from_config();
		$messages = array();

		try {
			$rates = $updater->update_rates();

			$messages[] = $rates->message();

			// Metadata has its own weekly window and declines on its own when it is current,
			// so calling it here costs a query and no quota. It is called *after* the rates
			// so that a first run on a fresh install fills the table people came to look at
			// even if the second request fails.
			$currencies = $updater->update_currencies();

			$messages[] = $currencies->message();

			self::notice(
				implode( ' ', $messages ),
				$rates->is_updated() || $currencies->is_updated() ? 'success' : 'info'
			);
		} catch ( \Throwable $e ) {
			// Reported, never papered over: a sync that could not reach the API says so, and
			// what is already stored keeps its own timestamps rather than being re-presented
			// as current.
			$messages[] = $e->getMessage();

			self::notice( implode( ' ', $messages ), 'error' );
		}

		self::redirect();
	}

	/**
	 * Delete the stored key, then redirect back.
	 *
	 * @return void
	 */
	public static function handle_forget() {
		self::guard( self::FORGET_ACTION );

		ApiKey::forget();

		self::notice( __( 'The stored API key has been deleted.', 'currency-converter' ), 'success' );
		self::redirect();
	}

	/**
	 * Move any pending notice into the settings-error store for this request.
	 *
	 * Called on the settings screen. The transient is deleted as it is read, so a notice is
	 * shown once and a reload does not repeat it.
	 *
	 * @return void
	 */
	public static function collect_notice() {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$pending = get_transient( $key );

		if ( ! is_array( $pending ) || ! isset( $pending['message'] ) ) {
			return;
		}

		delete_transient( $key );

		add_settings_error(
			'currency_converter',
			'currency_converter_sync',
			(string) $pending['message'],
			isset( $pending['type'] ) ? (string) $pending['type'] : 'info'
		);
	}

	/**
	 * Refuse anything that is not an authorised, intentional POST from this site.
	 *
	 * @param string $action The action being performed, which is also the nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'currency-converter' ),
				'',
				array( 'response' => 403 )
			);
		}

		// Dies on failure. The second argument is the field name the nonce arrives in, which
		// is what `wp_nonce_field()` writes on the settings screen.
		check_admin_referer( $action, '_wpnonce' );
	}

	/**
	 * Queue a notice for after the redirect.
	 *
	 * @param string $message What happened.
	 * @param string $type    `success`, `info` or `error`.
	 * @return void
	 */
	private static function notice( $message, $type ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			self::NOTICE_TIMEOUT
		);
	}

	/**
	 * Send the browser back to the settings screen and stop.
	 *
	 * @return void
	 */
	private static function redirect() {
		wp_safe_redirect( Menu::settings_url() );

		exit;
	}
}
