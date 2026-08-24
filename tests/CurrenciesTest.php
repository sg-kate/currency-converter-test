<?php
/**
 * The predefined currency list.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests;

use Brain\Monkey\Functions;
use Drozd\Currency\Currencies;

/**
 * Shape of the list, and the filter that narrows it.
 */
final class CurrenciesTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);
	}

	public function test_the_list_is_not_empty(): void {
		$this->assertNotEmpty( Currencies::codes() );
	}

	public function test_every_entry_is_three_upper_case_letters(): void {
		foreach ( Currencies::codes() as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $code );
		}
	}

	public function test_there_are_no_duplicates(): void {
		$codes = Currencies::codes();

		$this->assertSame( $codes, array_values( array_unique( $codes ) ) );
	}

	public function test_the_list_is_the_thirty_three_the_free_plan_serves(): void {
		$this->assertCount( 33, Currencies::codes() );
	}

	public function test_the_base_currency_is_on_the_list(): void {
		// Every stored rate is quoted against it, and every cross-rate divides by it.
		$this->assertContains( Currencies::BASE, Currencies::codes() );
	}

	public function test_names_accompany_the_codes(): void {
		$all = Currencies::all();

		$this->assertSame( 'Australian Dollar', $all['AUD'] );
		$this->assertSame( 'US Dollar', $all['USD'] );
	}

	public function test_the_filter_narrows_the_list(): void {
		Functions\when( 'apply_filters' )->justReturn( array( 'USD', 'EUR' ) );

		$this->assertSame( array( 'USD', 'EUR' ), Currencies::codes() );
		$this->assertSame( 2, Currencies::count() );
		$this->assertFalse( Currencies::has( 'RUB' ) );
	}

	public function test_the_filter_may_return_a_code_keyed_map(): void {
		Functions\when( 'apply_filters' )->justReturn(
			array(
				'USD' => 'US Dollar',
				'EUR' => 'Euro',
			)
		);

		$this->assertSame( array( 'USD', 'EUR' ), Currencies::codes() );
	}

	public function test_the_filter_cannot_smuggle_in_a_malformed_code(): void {
		Functions\when( 'apply_filters' )->justReturn( array( 'USD', 'not a code', 42 ) );

		$this->assertSame( array( 'USD' ), Currencies::codes() );
	}

	public function test_a_filter_returning_rubbish_is_ignored(): void {
		// Emptying the module because a plugin returned null is not an improvement.
		Functions\when( 'apply_filters' )->justReturn( null );

		$this->assertCount( 33, Currencies::codes() );
	}

	public function test_has_is_case_insensitive(): void {
		$this->assertTrue( Currencies::has( 'usd' ) );
		$this->assertFalse( Currencies::has( 'XYZ' ) );
		$this->assertFalse( Currencies::has( 'nonsense' ) );
	}
}
