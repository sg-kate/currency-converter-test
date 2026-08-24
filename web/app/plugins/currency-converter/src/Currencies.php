<?php
/**
 * The predefined list of currencies the module serves.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency;

use Drozd\Currency\Domain\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Which currencies exist, as far as this module is concerned.
 *
 * The brief allows the list to be "hardcoded in the module or added via the admin panel",
 * at the developer's discretion. The discretion is exercised as hardcoded: a second source
 * of truth in the database would drift from the code that Composer deploys, and
 * `DISALLOW_FILE_MODS` reflects the same principle everywhere but development. A site that
 * prices in three currencies narrows the list with the `currency_converter_currencies`
 * filter instead of a settings screen.
 *
 * This class decides *membership*. The `cc_currencies` table describes the members —
 * symbol, minor units — and never adds to them. The two are checked against each other on
 * every sync rather than assumed to agree: `RateUpdater` stores the intersection and
 * reports both directions of drift, so a currency the API added and a currency it withdrew
 * are both visible in the sync output instead of silently changing what is stored.
 *
 * Framework-free apart from the one filter, which is the extension point the invariant
 * requires. Nothing here touches `$wpdb`, an option, or a hook registration.
 *
 * **Provenance of the list.** These are the 33 currencies the free plan advertises, in the
 * ISO-code order `/v1/currencies` returns them. Until a live `/v1/currencies` response has
 * been stored — `RateUpdater::update_currencies()` does that, and it needs an API key — the
 * set is documented rather than captured, and the first live sync is what confirms it: any
 * disagreement is printed as drift, not swallowed. Re-seed from the stored metadata with
 * `SELECT code, name FROM wp_cc_currencies ORDER BY code` if it ever reports any.
 */
final class Currencies {

	/**
	 * Currency code to English display name.
	 *
	 * Names are here so the list is usable — a CLI table, a `<select>` — before the
	 * metadata sync has ever run. Where `cc_currencies` has a name, that one wins: it came
	 * from the API, this one is a fallback.
	 *
	 * @var array<string, string>
	 */
	const CODES = array(
		'AUD' => 'Australian Dollar',
		'BGN' => 'Bulgarian Lev',
		'BRL' => 'Brazilian Real',
		'CAD' => 'Canadian Dollar',
		'CHF' => 'Swiss Franc',
		'CNY' => 'Chinese Yuan',
		'CZK' => 'Czech Koruna',
		'DKK' => 'Danish Krone',
		'EUR' => 'Euro',
		'GBP' => 'British Pound Sterling',
		'HKD' => 'Hong Kong Dollar',
		'HRK' => 'Croatian Kuna',
		'HUF' => 'Hungarian Forint',
		'IDR' => 'Indonesian Rupiah',
		'ILS' => 'Israeli New Sheqel',
		'INR' => 'Indian Rupee',
		'ISK' => 'Icelandic Krona',
		'JPY' => 'Japanese Yen',
		'KRW' => 'South Korean Won',
		'MXN' => 'Mexican Peso',
		'MYR' => 'Malaysian Ringgit',
		'NOK' => 'Norwegian Krone',
		'NZD' => 'New Zealand Dollar',
		'PHP' => 'Philippine Peso',
		'PLN' => 'Polish Zloty',
		'RON' => 'Romanian Leu',
		'RUB' => 'Russian Ruble',
		'SEK' => 'Swedish Krona',
		'SGD' => 'Singapore Dollar',
		'THB' => 'Thai Baht',
		'TRY' => 'Turkish Lira',
		'USD' => 'US Dollar',
		'ZAR' => 'South African Rand',
	);

	/**
	 * Filter that narrows or replaces the list.
	 *
	 * Passed the list of codes, expected to return one. A returned map is accepted too —
	 * its keys are taken — so `array_slice( Currencies::all(), 0, 3 )` works as a filter
	 * callback without the caller having to know which shape is wanted.
	 */
	const FILTER = 'currency_converter_currencies';

	/**
	 * The base currency every stored rate is quoted against.
	 *
	 * The free plan serves USD as base and refuses any other, so this is a property of the
	 * plan rather than a preference — see collision C1 in `docs/REQUIREMENTS.md`. Every
	 * other pair is derived arithmetically at conversion time.
	 */
	const BASE = 'USD';

	/**
	 * The currency codes the module serves.
	 *
	 * @return array<int, string> Upper-case codes, in order, without duplicates.
	 */
	public static function codes() {
		$filtered = apply_filters( self::FILTER, array_keys( self::CODES ) );

		if ( ! is_array( $filtered ) ) {
			// A filter that returned rubbish must not empty the module. Ignore it.
			return array_keys( self::CODES );
		}

		$codes = array();

		foreach ( $filtered as $key => $value ) {
			// Accept both a list of codes and a code-keyed map.
			$code = is_string( $key ) ? $key : $value;

			if ( Currency::is_valid_code( $code ) ) {
				$codes[ strtoupper( trim( (string) $code ) ) ] = true;
			}
		}

		return array_keys( $codes );
	}

	/**
	 * The currency codes with their display names.
	 *
	 * @return array<string, string> Name keyed by code, filtered like `codes()`.
	 */
	public static function all() {
		$named = array();

		foreach ( self::codes() as $code ) {
			$named[ $code ] = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : '';
		}

		return $named;
	}

	/**
	 * Whether the module serves a currency.
	 *
	 * @param string $code Currency code, any case.
	 * @return bool True when the code is on the (filtered) list.
	 */
	public static function has( $code ) {
		if ( ! Currency::is_valid_code( $code ) ) {
			return false;
		}

		return in_array( strtoupper( trim( (string) $code ) ), self::codes(), true );
	}

	/**
	 * How many currencies the module serves.
	 *
	 * @return int Count of the filtered list.
	 */
	public static function count() {
		return count( self::codes() );
	}
}
