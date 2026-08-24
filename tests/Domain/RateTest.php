<?php
/**
 * The rate value object.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Domain;

use Drozd\Currency\Domain\Rate;
use Tests\TestCase;

/**
 * A rate is a decimal string, at a fixed scale, and never a float.
 *
 * The assertions here are all about precision, because precision is the only thing that
 * separates a currency module from a rounding error with a schedule.
 */
final class RateTest extends TestCase {

	public function test_value_is_padded_to_twelve_decimal_places(): void {
		$rate = new Rate( 'USD', 'RUB', '93.0071' );

		$this->assertSame( '93.007100000000', $rate->value() );
		$this->assertIsString( $rate->value() );
	}

	public function test_value_keeps_every_stored_digit(): void {
		// Twelve significant decimals survive intact. Through a float they would not:
		// the same literal cast to float and back is 93.007123456700003.
		$rate = new Rate( 'USD', 'RUB', '93.007123456789' );

		$this->assertSame( '93.007123456789', $rate->value() );
	}

	public function test_excess_decimals_round_half_up(): void {
		$this->assertSame( '0.000000000001', Rate::normalize_value( '0.0000000000005' ) );
		$this->assertSame( '2.000000000000', Rate::normalize_value( '1.9999999999995' ) );
		$this->assertSame( '1.000000000000', Rate::normalize_value( '1.0000000000004' ) );
	}

	public function test_identity_is_exactly_one_at_full_scale(): void {
		$rate = Rate::identity( 'USD' );

		$this->assertSame( '1.000000000000', $rate->value() );
		$this->assertSame( Rate::IDENTITY, $rate->value() );
		$this->assertTrue( $rate->is_identity() );
		$this->assertSame( 'USD', $rate->base_code() );
		$this->assertSame( 'USD', $rate->target_code() );
	}

	public function test_from_float_formats_without_locale_or_exponent(): void {
		$rate = Rate::from_float( 'usd', 'rub', 93.0071234567 );

		$this->assertSame( '93.007123456700', $rate->value() );
		$this->assertSame( 'USD', $rate->base_code() );
		$this->assertSame( 'RUB', $rate->target_code() );
	}

	public function test_from_float_keeps_full_precision_of_a_string(): void {
		// A numeric string never becomes a float on the way through, so a payload that
		// carried more digits than a float can hold keeps them.
		$rate = Rate::from_float( 'USD', 'JPY', '148.901234567891' );

		$this->assertSame( '148.901234567891', $rate->value() );
	}

	public function test_a_float_is_refused_by_the_constructor(): void {
		// The one float boundary is from_float(), so it stays greppable.
		$this->expectException( \InvalidArgumentException::class );

		new Rate( 'USD', 'RUB', 93.0071 );
	}

	public function test_non_finite_values_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Rate::from_float( 'USD', 'RUB', INF );
	}

	public function test_zero_is_refused(): void {
		// A stored zero divides by zero on the first cross-rate.
		$this->expectException( \InvalidArgumentException::class );

		new Rate( 'USD', 'RUB', '0.0000000000004' );
	}

	public function test_negative_rates_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Rate( 'USD', 'RUB', '-1.5' );
	}

	public function test_exponent_notation_is_refused(): void {
		// MySQL would store the string "1.0E-5" as 1, not as 0.00001.
		$this->expectException( \InvalidArgumentException::class );

		new Rate( 'USD', 'RUB', '1.0E-5' );
	}

	public function test_a_value_too_wide_for_the_column_is_refused(): void {
		// DECIMAL(24,12) leaves twelve digits before the point; MySQL would clamp silently.
		$this->expectException( \InvalidArgumentException::class );

		new Rate( 'USD', 'RUB', '1234567890123.5' );
	}

	public function test_fetched_at_is_normalised_to_utc(): void {
		$rate = new Rate(
			'USD',
			'EUR',
			'0.91',
			new \DateTimeImmutable( '2026-08-24 12:00:00', new \DateTimeZone( 'Europe/Berlin' ) )
		);

		$this->assertSame( '2026-08-24 10:00:00', $rate->fetched_at_string() );
	}

	public function test_from_row_reads_the_column_as_a_string(): void {
		$rate = Rate::from_row(
			array(
				'base_code'   => 'USD',
				'target_code' => 'RUB',
				'rate'        => '93.007123456789',
				'fetched_at'  => '2026-08-24 09:00:00',
			)
		);

		$this->assertSame( '93.007123456789', $rate->value() );
		$this->assertIsString( $rate->value() );
		$this->assertSame( '2026-08-24 09:00:00', $rate->fetched_at_string() );
	}

	public function test_a_zero_date_reads_as_no_fetch_time(): void {
		$rate = Rate::from_row(
			array(
				'base_code'   => 'USD',
				'target_code' => 'RUB',
				'rate'        => '93.0',
				'fetched_at'  => '0000-00-00 00:00:00',
			)
		);

		$this->assertNull( $rate->fetched_at() );
		$this->assertSame( 'never', $rate->fetched_at_string( 'never' ) );
	}
}
