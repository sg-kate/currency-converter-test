<?php
/**
 * Turning an exact amount into something a reader can understand.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Service;

use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\CurrencyRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Formats an amount in a given currency, using that currency's own metadata.
 *
 * This is the presentation edge that `CurrencyConverter::convert()` deliberately stops
 * short of. The converter's job is to stay exact for as long as its return type allows;
 * deciding that a rouble amount reads as `₽10,182.68` and a yen amount as `¥15,432` — with
 * no decimal part at all — is this class's job, and it is the only place that decision is
 * made. Both the shortcode and the block render through here, so they cannot drift apart.
 *
 * **`decimal_digits` is not decoration.** JPY has 0 minor units. Formatting ¥15,432 to two
 * places invents a fraction of a yen that does not exist, and doing it in a price is the
 * kind of wrong that reaches a customer. The digit count comes from `/v1/currencies` by way
 * of the `cc_currencies` table, per currency, never from a constant 2.
 *
 * **One query, however many amounts.** `CurrencyRepositoryInterface::all()` memoises per
 * instance and `find()` reads that memo once it exists, so the whole table is warmed on the
 * first format and every later lookup is an array read. A page with forty converted prices
 * costs the same one `SELECT` as a page with one — the same bargain
 * `CurrencyConverter`'s rate map makes, for the same reason.
 */
final class AmountFormatter {

	/**
	 * Currency metadata storage.
	 *
	 * @var CurrencyRepositoryInterface
	 */
	private $currencies;

	/**
	 * Whether the whole table has been warmed into the repository's memo.
	 *
	 * @var bool
	 */
	private $warmed = false;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRepositoryInterface $currencies Currency metadata storage.
	 */
	public function __construct( CurrencyRepositoryInterface $currencies ) {
		$this->currencies = $currencies;
	}

	/**
	 * Build a formatter wired to the real currencies table.
	 *
	 * The one place in this class that names a concrete storage implementation, matching
	 * `CurrencyConverter::from_config()`.
	 *
	 * @return self Configured formatter.
	 */
	public static function from_config() {
		return new self( new WpdbCurrencyRepository() );
	}

	/**
	 * The shared formatter for this request.
	 *
	 * Shared for the same reason `currency_converter()` is: the repository memo lives on the
	 * instance, so a page that renders a shortcode and a block from separate instances would
	 * pay for the currencies table twice. One static, one `SELECT`.
	 *
	 * Deliberately a static on this class rather than a second global function —
	 * `functions.php` promises one accessor and nothing else, and this is internal wiring
	 * rather than the module's public surface.
	 *
	 * @return self The formatter for this request.
	 */
	public static function shared() {
		static $shared = null;

		if ( ! $shared instanceof self ) {
			$shared = self::from_config();
		}

		return $shared;
	}

	/**
	 * The metadata for a currency, falling back to a bare code.
	 *
	 * A code the metadata sync has not covered still has to render — the rate is stored and
	 * the conversion is correct, only the name and symbol are missing — so this returns a
	 * `Currency` carrying just the code rather than null. Callers never have to branch.
	 *
	 * @param string $code Currency code, any case.
	 * @return Currency The currency, described if it is known and bare if it is not.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When the code is malformed.
	 */
	public function currency( $code ) {
		$normalized = Currency::normalize_code( $code );

		// Warms the repository's own memo, so this and every later `find()` is an array
		// read rather than a query. Done once per instance, not once per call.
		if ( ! $this->warmed ) {
			$this->currencies->all();

			$this->warmed = true;
		}

		$found = $this->currencies->find( $normalized );

		return $found instanceof Currency ? $found : new Currency( $normalized );
	}

	/**
	 * Format an amount in one currency.
	 *
	 * @param float|int|string $amount Amount to render.
	 * @param string           $code   Currency code, any case.
	 * @return string Formatted amount, e.g. `₽10,182.68`, `¥15,432` or `10,182.68 XYZ`.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When the code is malformed.
	 */
	public function format( $amount, $code ) {
		return $this->format_with( $amount, $this->currency( $code ) );
	}

	/**
	 * Format an amount against currency metadata already in hand.
	 *
	 * Separate from `format()` so a caller rendering a long table — the block does — can
	 * look each currency up once and format many amounts against it.
	 *
	 * @param float|int|string $amount   Amount to render.
	 * @param Currency         $currency The currency to render it in.
	 * @return string Formatted amount.
	 */
	public function format_with( $amount, Currency $currency ) {
		$number = number_format_i18n( (float) $amount, $currency->decimal_digits() );

		// A currency whose metadata has not synced has no symbol, and an unlabelled number
		// is worse than a slightly longer one: the code goes after it instead.
		if ( '' === $currency->symbol() ) {
			return $number . ' ' . $currency->code();
		}

		return $currency->symbol() . $number;
	}

	/**
	 * Render a conversion as the phrase a reader can check.
	 *
	 * `$123.00 = ₽10,182.68`, both sides in their own currency's format. The source amount
	 * is repeated on purpose: a bare converted number tells nobody what was converted, which
	 * is exactly the complaint this exists to answer.
	 *
	 * @param float|int|string $amount    Amount in the source currency.
	 * @param float|int|string $converted Converted amount, as `convert()` returned it.
	 * @param string           $from_code Source currency code, any case.
	 * @param string           $to_code   Target currency code, any case.
	 * @return string The phrase, unescaped — escaping belongs at the point of output.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When a code is malformed.
	 */
	public function phrase( $amount, $converted, $from_code, $to_code ) {
		return sprintf(
			/* translators: 1: source amount with its currency, 2: converted amount with its currency. */
			_x( '%1$s = %2$s', 'currency conversion', 'currency-converter' ),
			$this->format( $amount, $from_code ),
			$this->format( $converted, $to_code )
		);
	}
}
