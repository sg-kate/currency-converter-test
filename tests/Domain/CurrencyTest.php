<?php
/**
 * The currency value object.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Domain;

use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Exception\UnknownCurrencyException;
use Tests\TestCase;

/**
 * Code normalisation, and the metadata that decides how an amount is displayed.
 */
final class CurrencyTest extends TestCase {

	public function test_code_is_normalised_to_upper_case(): void {
		$this->assertSame( 'USD', ( new Currency( 'usd' ) )->code() );
		$this->assertSame( 'EUR', Currency::normalize_code( ' eur ' ) );
	}

	/**
	 * @dataProvider malformed_codes
	 *
	 * @param mixed $code A value that is not a currency code.
	 */
	public function test_malformed_codes_are_rejected( $code ): void {
		$this->expectException( UnknownCurrencyException::class );

		Currency::normalize_code( $code );
	}

	/**
	 * @return array<string, array{0: mixed}> Values that are not currency codes.
	 */
	public static function malformed_codes(): array {
		return array(
			'empty'        => array( '' ),
			'two letters'  => array( 'US' ),
			'four letters' => array( 'EURO' ),
			'digits'       => array( 'US1' ),
			'not a string' => array( array( 'USD' ) ),
			'null'         => array( null ),
		);
	}

	public function test_is_valid_code_answers_without_throwing(): void {
		$this->assertTrue( Currency::is_valid_code( 'jpy' ) );
		$this->assertFalse( Currency::is_valid_code( 'JPYY' ) );
		$this->assertFalse( Currency::is_valid_code( null ) );
	}

	public function test_zero_decimal_digits_survive(): void {
		// JPY has no minor unit. Defaulting it to 2 invents a fraction of a yen.
		$jpy = new Currency( 'JPY', 'Japanese Yen', '¥', 0 );

		$this->assertSame( 0, $jpy->decimal_digits() );
	}

	public function test_decimal_digits_default_to_two_and_are_clamped(): void {
		$this->assertSame( 2, ( new Currency( 'EUR' ) )->decimal_digits() );
		$this->assertSame( Currency::MAX_DECIMAL_DIGITS, ( new Currency( 'EUR', '', '', 99 ) )->decimal_digits() );
		$this->assertSame( 0, ( new Currency( 'EUR', '', '', -3 ) )->decimal_digits() );
	}

	public function test_from_array_takes_the_code_from_the_key_when_the_row_has_none(): void {
		// `/v1/currencies` is keyed by code, and each value repeats it — but a narrowed
		// payload may not, and the key is still authoritative.
		$currency = Currency::from_array(
			array(
				'name'           => 'Euro',
				'symbol'         => '€',
				'decimal_digits' => 2,
			),
			'EUR'
		);

		$this->assertSame( 'EUR', $currency->code() );
		$this->assertSame( 'Euro', $currency->name() );
	}

	public function test_label_falls_back_to_the_code_without_metadata(): void {
		$this->assertSame( 'Euro (EUR)', ( new Currency( 'EUR', 'Euro' ) )->label() );
		$this->assertSame( 'EUR', ( new Currency( 'EUR' ) )->label() );
	}

	public function test_equality_is_by_code(): void {
		$this->assertTrue( ( new Currency( 'USD', 'US Dollar' ) )->equals( new Currency( 'usd' ) ) );
		$this->assertFalse( ( new Currency( 'USD' ) )->equals( new Currency( 'EUR' ) ) );
	}
}
