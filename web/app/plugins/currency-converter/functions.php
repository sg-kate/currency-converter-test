<?php
/**
 * The module's public function surface: one accessor, and nothing else.
 *
 * Not autoloaded — a function is not a class — so this file is required by the main plugin
 * file. Nothing here does any work at load time.
 *
 * @package Currency_Converter
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'currency_converter' ) ) {
	/**
	 * The shared converter for this request.
	 *
	 * Exists so the brief's own example works literally at any call site, with no wiring:
	 *
	 *     $converter = currency_converter();
	 *     $converter->convert( 123, 'USD', 'RUB' );
	 *
	 * The service is still the deliverable — a real object with the brief's signature,
	 * constructed with its storage, which is invariant 6 of the task contract. This is an
	 * accessor for it, not a replacement: `new CurrencyConverter( $repository )` remains the
	 * way to get one with different storage, and is what the tests use.
	 *
	 * **Shared, and that is the point.** The converter reads the whole rate map once and
	 * holds it for the rest of the request; a fresh instance per call site would be a fresh
	 * query per call site, which is precisely the cost the memoisation exists to remove. One
	 * static, so a template converting prices in a loop and a widget converting one further
	 * down share a single `SELECT`.
	 *
	 * Built on first use rather than at load: it resolves `$wpdb` through the repository, and
	 * this file is required before WordPress has finished standing up.
	 *
	 * A theme or another plugin that needs different behaviour — a converter over fixture
	 * rates on a staging site, say — replaces the instance through
	 * `currency_converter_instance`. The filter runs once, on construction, so a filter added
	 * after the first call does not take effect until the next request, and a filter that
	 * returns something that is not a converter is ignored rather than allowed to turn every
	 * call site into a fatal error.
	 *
	 * @return \Drozd\Currency\Service\CurrencyConverter The converter for this request.
	 */
	function currency_converter() {
		static $converter = null;

		if ( $converter instanceof \Drozd\Currency\Service\CurrencyConverter ) {
			return $converter;
		}

		$default  = \Drozd\Currency\Service\CurrencyConverter::from_config();
		$filtered = apply_filters( 'currency_converter_instance', $default );

		$converter = $filtered instanceof \Drozd\Currency\Service\CurrencyConverter ? $filtered : $default;

		return $converter;
	}
}
