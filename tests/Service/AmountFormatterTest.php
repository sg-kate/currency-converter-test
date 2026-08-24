<?php
/**
 * Rendering an amount for a reader.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Service;

use Brain\Monkey\Functions;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Service\AmountFormatter;
use Tests\Fakes\InMemoryCurrencyRepository;
use Tests\TestCase;

/**
 * The presentation edge `convert()` deliberately stops short of.
 *
 * Three things matter here beyond "the number has a symbol in front of it":
 *
 * - **`decimal_digits` is honoured per currency.** JPY has none. Two places on a yen amount
 *   invents a fraction of a currency unit that does not exist, and it reaches a customer.
 * - **A currency with no metadata still renders.** The rate is stored and the conversion is
 *   correct; only the name and symbol are missing. It must not throw and must not print a
 *   bare number with nothing to say what it is.
 * - **The query count.** One `SELECT` however many amounts a page formats, which is the same
 *   bargain the converter's rate map makes.
 */
final class AmountFormatterTest extends TestCase {

	/**
	 * A repository carrying the three interesting shapes.
	 */
	private function repository(): InMemoryCurrencyRepository {
		return new InMemoryCurrencyRepository(
			array(
				new Currency( 'USD', 'US Dollar', '$', 2 ),
				new Currency( 'RUB', 'Russian Ruble', '₽', 2 ),
				new Currency( 'JPY', 'Japanese Yen', '¥', 0 ),
				// Stored, described, and with no symbol — the metadata sync covers the
				// name but freecurrencyapi serves no symbol for it.
				new Currency( 'XYZ', 'Example Currency', '', 2 ),
			)
		);
	}

	protected function set_up(): void {
		parent::set_up();

		// The one WordPress function this class calls. Stubbed rather than mocked because
		// its grouping and decimal separators are the site's business, not this test's.
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals, '.', ',' );
			}
		);

		Functions\when( '_x' )->returnArg( 1 );
	}

	public function test_a_symbol_goes_in_front_of_the_amount(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '₽10,182.68', $formatter->format( 10182.6798598215, 'RUB' ) );
		$this->assertSame( '$123.00', $formatter->format( 123, 'USD' ) );
	}

	/**
	 * The assertion that stops a yen price growing a fractional part.
	 */
	public function test_decimal_digits_come_from_the_currency_not_from_a_constant(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '¥19,546', $formatter->format( 19546.4321, 'JPY' ) );
		$this->assertStringNotContainsString( '.', $formatter->format( 19546.4321, 'JPY' ) );
	}

	/**
	 * No symbol is not the same as nothing to say.
	 */
	public function test_a_currency_without_a_symbol_falls_back_to_its_code(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '1,234.50 XYZ', $formatter->format( 1234.5, 'XYZ' ) );
	}

	/**
	 * A code the metadata sync has never covered still has a correct rate behind it.
	 */
	public function test_an_unknown_code_renders_rather_than_throwing(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '10.00 SEK', $formatter->format( 10, 'SEK' ) );
	}

	public function test_case_is_normalised(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '$5.00', $formatter->format( 5, 'usd' ) );
	}

	public function test_a_malformed_code_is_refused(): void {
		$this->expectException( \Drozd\Currency\Exception\UnknownCurrencyException::class );

		( new AmountFormatter( $this->repository() ) )->format( 1, 'US' );
	}

	/**
	 * The phrase is the whole answer to "11,439.88 of what, from what?".
	 */
	public function test_the_phrase_shows_both_sides_in_their_own_currency(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame(
			'$123.00 = ₽10,182.68',
			$formatter->phrase( 123, 10182.6798598215, 'USD', 'RUB' )
		);
	}

	public function test_the_phrase_honours_zero_decimal_currencies_on_either_side(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame(
			'¥1,000 = $6.79',
			$formatter->phrase( 1000, 6.7891, 'JPY', 'USD' )
		);
	}

	/**
	 * A service, not a script: the table is read once however many amounts are rendered.
	 */
	public function test_the_currency_table_is_read_once_per_instance(): void {
		$repository = $this->repository();
		$formatter  = new AmountFormatter( $repository );

		foreach ( array( 'USD', 'RUB', 'JPY', 'XYZ', 'USD', 'RUB' ) as $code ) {
			$formatter->format( 1, $code );
		}

		$this->assertSame(
			1,
			$repository->all_call_count(),
			'the whole table must be warmed once, not once per amount'
		);
	}

	/**
	 * Formatting against metadata already in hand skips the lookup entirely.
	 */
	public function test_format_with_does_not_touch_storage(): void {
		$repository = $this->repository();
		$formatter  = new AmountFormatter( $repository );
		$currency   = new Currency( 'EUR', 'Euro', '€', 2 );

		$this->assertSame( '€105.30', $formatter->format_with( 105.2988, $currency ) );
		$this->assertSame( 0, $repository->all_call_count() );
		$this->assertSame( 0, $repository->find_call_count() );
	}

	/**
	 * Half-up at the display scale, so a price never rounds down in the customer's favour
	 * by accident and never up against them.
	 */
	public function test_display_rounding_is_half_up(): void {
		$formatter = new AmountFormatter( $this->repository() );

		$this->assertSame( '$0.13', $formatter->format( 0.125, 'USD' ) );
		$this->assertSame( '$1.01', $formatter->format( 1.005, 'USD' ) );
	}
}
