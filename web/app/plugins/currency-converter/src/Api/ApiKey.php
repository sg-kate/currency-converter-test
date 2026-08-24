<?php
/**
 * Where the freecurrencyapi key comes from, and what may be said about it.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the API key from the environment first and the database second.
 *
 * **The environment wins.** `config/application.php` defines `FREECURRENCYAPI_KEY` from
 * `.env`, and on this project that is the real source — collision C8 in
 * `docs/REQUIREMENTS.md` settled it: a key in `wp_options` is a key in every database dump
 * and a per-environment surprise on every restore. The constant is always *defined* here,
 * possibly to an empty string, so "defined" is not the test — "defined and non-empty" is.
 *
 * **The option exists for the site that is not this one.** The plugin ships as a zip onto
 * hosts with no `.env` and no way to define a constant, and a settings page that could only
 * ever say "ask your system administrator" would be useless there. So an administrator may
 * store a key, and the environment overrides it whenever one is present — which means a
 * deployment cannot be quietly repointed at a different key through wp-admin.
 *
 * The option is written with `autoload='no'`, without exception. An autoloaded option is
 * read into `alloptions` on every single request, cached there, and served to every piece of
 * code that calls `wp_load_alloptions()`; a secret has no business in that set. The option is
 * pre-created empty at activation for exactly this reason: `update_option()` on an option
 * that does not exist yet creates it with WordPress's default autoload, and the Settings API
 * calls `update_option()` without an autoload argument.
 *
 * **What may be said about a key is the last four characters and nothing else.** `hint()` is
 * the only method that returns any part of one for display, and it never returns a prefix —
 * the leading `fca_live_` is fixed across every key on the plan, so showing it identifies
 * nothing while making the tail look shorter than it is.
 */
final class ApiKey {

	/**
	 * Option holding an administrator-supplied key. Never autoloaded.
	 */
	const OPTION = 'currency_converter_api_key';

	/**
	 * Constant the environment supplies the key through.
	 */
	const CONSTANT = 'FREECURRENCYAPI_KEY';

	/**
	 * How many trailing characters `hint()` may reveal.
	 */
	const HINT_DIGITS = 4;

	/**
	 * Shortest string accepted as a key.
	 *
	 * Live keys are far longer. This only catches a paste that lost most of itself, so the
	 * failure is "that is not a key" on the settings page rather than a 401 a day later.
	 */
	const MIN_LENGTH = 16;

	/**
	 * The key to authenticate with.
	 *
	 * @return string The key, or an empty string when none is configured.
	 */
	public static function get() {
		$from_env = self::from_environment();

		if ( '' !== $from_env ) {
			return $from_env;
		}

		return self::from_option();
	}

	/**
	 * Whether a key is available at all.
	 *
	 * @return bool True when `get()` would return something.
	 */
	public static function is_configured() {
		return '' !== self::get();
	}

	/**
	 * Whether the key in use came from the environment rather than the database.
	 *
	 * Drives the settings page: an environment key is displayed as read-only, because
	 * storing one in the database would have no effect and saying otherwise would be a lie.
	 *
	 * @return bool True when the constant supplied the key.
	 */
	public static function is_from_environment() {
		return '' !== self::from_environment();
	}

	/**
	 * Whether an administrator-supplied key is stored, whatever the environment says.
	 *
	 * Distinct from `is_from_environment()`: both can be true at once, and when they are, the
	 * stored key is dead weight the settings page offers to delete.
	 *
	 * @return bool True when the option holds a key.
	 */
	public static function is_stored() {
		return '' !== self::from_option();
	}

	/**
	 * The last few characters of the configured key, masked.
	 *
	 * The whole point of the settings page's key field is that the value never reaches the
	 * page source; this is what is shown instead. Returns an empty string when there is no
	 * key, and when the key is too short for a tail to be worth showing.
	 *
	 * @return string Something like `••••••••ab12`, or an empty string.
	 */
	public static function hint() {
		$key = self::get();

		if ( strlen( $key ) < self::MIN_LENGTH ) {
			return '';
		}

		return str_repeat( '•', 8 ) . substr( $key, -self::HINT_DIGITS );
	}

	/**
	 * Store an administrator-supplied key.
	 *
	 * @param string $key The key, already validated by `is_well_formed()`.
	 * @return void
	 */
	public static function store( $key ) {
		self::ensure_option_exists();

		// No autoload argument: the option was created with `autoload='no'` above and
		// `update_option()` leaves an existing option's autoload setting alone.
		update_option( self::OPTION, (string) $key );
	}

	/**
	 * Delete the stored key.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * Create the option empty, with `autoload='no'`, if it does not exist.
	 *
	 * Called at activation and again before every write. The Settings API saves through
	 * `update_option()` with no autoload argument, and for an option that does not exist yet
	 * that means WordPress's default — which is autoloaded. Creating it first is what makes
	 * the guarantee in the class docblock hold regardless of who writes it.
	 *
	 * **The guard is what stops this recursing into a fatal.** `add_option()` runs
	 * `sanitize_option()` *before* it checks whether the row exists, and this option's
	 * sanitiser — `SettingsPage::sanitize_api_key()` — calls straight back into here. With the
	 * row absent, every call re-entered `add_option()` and the request died on exhausted
	 * memory, which is reachable by an administrator who deletes the stored key and then saves
	 * a new one. The re-entrant call has nothing left to do — the outer one is already
	 * creating the row — so it returns and lets `add_option()` finish.
	 *
	 * `register_setting( …, array( 'autoload' => false ) )` would express this without a
	 * guard, but that argument is honoured only from WordPress 6.6 and this plugin supports
	 * 6.0, where it is accepted and ignored — which would reopen the autoload hole silently on
	 * exactly the versions least likely to be noticed.
	 *
	 * @return void
	 */
	public static function ensure_option_exists() {
		static $creating = false;

		if ( $creating ) {
			return;
		}

		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$creating = true;

		try {
			add_option( self::OPTION, '', '', 'no' );
		} finally {
			// Released whatever happened, so a sanitiser that throws cannot wedge the flag on
			// and leave the option uncreatable for the rest of the request.
			$creating = false;
		}
	}

	/**
	 * Whether a submitted string is shaped like a key.
	 *
	 * Deliberately not a check for the `fca_live_` prefix: the module has no business
	 * refusing a key because the vendor changed its key format, and a wrong-but-well-formed
	 * key is caught by the first 401 with a message that says so. What is rejected is the
	 * shape that cannot be a key — whitespace in the middle, control characters, a fragment.
	 *
	 * The `D` modifier is not decoration. Without it `$` also matches immediately before a
	 * final newline, so `"fca_live_abcdefghijklmnop\n"` — a key pasted with the line ending
	 * still attached, which is the single most likely way one arrives — satisfies the pattern
	 * and is stored intact. This value is sent as an HTTP header, and a newline inside a
	 * header value is where header injection starts.
	 *
	 * @param string $key Candidate key.
	 * @return bool True when it is worth storing.
	 */
	public static function is_well_formed( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9_\-]{' . self::MIN_LENGTH . ',256}$/D', $key );
	}

	/**
	 * The key the environment supplies.
	 *
	 * @return string The constant's value, trimmed; empty when it is unset or blank.
	 */
	private static function from_environment() {
		if ( ! defined( self::CONSTANT ) ) {
			return '';
		}

		$value = constant( self::CONSTANT );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The key an administrator stored.
	 *
	 * @return string The option's value, trimmed; empty when unset.
	 */
	private static function from_option() {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? trim( $stored ) : '';
	}
}
