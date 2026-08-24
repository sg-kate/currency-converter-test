<?php
/**
 * The conversion service: its arithmetic, its query count, and what it refuses.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Service;

use Brain\Monkey\Functions;
use Drozd\Currency\Currencies;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Exception\InvalidAmountException;
use Drozd\Currency\Exception\RatesUnavailableException;
use Drozd\Currency\Exception\UnknownCurrencyException;
use Drozd\Currency\Service\CurrencyConverter;
use Tests\Fakes\FakeWpdb;
use Tests\Fakes\InMemoryRateRepository;
use Tests\TestCase;

/**
 * R6, pinned against fixed rates rather than live ones.
 *
 * The rates are USD → RUB at 90 and USD → EUR at 0.5, so every expected answer below can be
 * checked on paper and none of them moves when the market does. What the API happens to
 * serve is a different question, tested elsewhere; this file is about the arithmetic.
 *
 * Three groups of assertions matter beyond "the multiplication is right":
 *
 * - **The query count.** `map_call_count()` is the difference between a service and a
 *   script. A converter that reads storage per call passes every arithmetic test here.
 * - **Half-up, on decimals.** The tie cases are chosen so that truncation, banker's
 *   rounding, and `round()` on a float each give a different answer from the right one.
 * - **The refusals.** A missing rate throws rather than falling back to 1:1 — the rule in
 *   the task contract that overrides "make it work" — and the two failure types stay apart.
 */
final class CurrencyConverterTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		// `Currencies::codes()` runs the module's one filter on every call. Pass the
		// predefined list straight through unless a test says otherwise.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);
	}

	/**
	 * A converter over the rates the acceptance criteria are written against.
	 *
	 * @param InMemoryRateRepository|null $rates Storage, or the fixed rates by default.
	 * @return CurrencyConverter The converter.
	 */
	private function converter( ?InMemoryRateRepository $rates = null ): CurrencyConverter {
		return new CurrencyConverter( $rates ?? InMemoryRateRepository::with_fixed_rates() );
	}

	// -- The three shapes of conversion, and the brief's own example -------------------

	public function test_it_converts_at_the_direct_stored_rate(): void {
		// The example in the brief, verbatim: $converter->convert( 123, 'USD', 'RUB' ).
		// Out of the base, so the stored rate is used as it stands and nothing is divided.
		$this->assertSame( 11070.0, $this->converter()->convert( 123, 'USD', 'RUB' ) );
	}

	public function test_it_converts_at_the_inverse_of_the_stored_rate(): void {
		// The other direction: only USD → RUB is stored, so RUB → USD is 123 / 90. It does
		// not terminate, which is also where the rounding scale first becomes visible.
		$this->assertSame( 1.366666666667, $this->converter()->convert( 123, 'RUB', 'USD' ) );
	}

	public function test_it_converts_between_two_currencies_when_neither_is_the_base(): void {
		// The cross rate C1 rests on. Neither endpoint is USD — but the arithmetic still
		// pivots on it, and by construction always will: the free plan sells one base, so
		// `EUR → RUB` is `rate(RUB) / rate(EUR)` = 90 / 0.5 = 180, derived and never stored.
		// There is no path through this class that reads a map for any base but USD.
		$this->assertSame( 22140.0, $this->converter()->convert( 123, 'EUR', 'RUB' ) );
		$this->assertSame( '180.000000000000', $this->converter()->rate( 'EUR', 'RUB' ) );
	}

	public function test_converting_a_currency_to_itself_needs_no_rate_at_all(): void {
		$rates = new InMemoryRateRepository();

		$this->assertSame( 123.0, $this->converter( $rates )->convert( 123, 'USD', 'USD' ) );
		$this->assertSame( 0, $rates->map_call_count(), 'Identity must not touch storage.' );
	}

	public function test_codes_are_case_insensitive(): void {
		$this->assertSame( 11070.0, $this->converter()->convert( 123, 'usd', ' rub ' ) );
	}

	public function test_a_negative_amount_converts(): void {
		// A refund is a conversion. Rejecting it would be a bug, not a safeguard.
		$this->assertSame( -11070.0, $this->converter()->convert( -123, 'USD', 'RUB' ) );
	}

	public function test_zero_converts_to_zero(): void {
		$this->assertSame( 0.0, $this->converter()->convert( 0, 'USD', 'RUB' ) );
	}

	// -- One query per request ---------------------------------------------------------

	public function test_the_rate_map_is_read_once_however_many_conversions_follow(): void {
		// The whole reason this is a service. A loop over a cart is one SELECT, not N.
		$rates     = InMemoryRateRepository::with_fixed_rates();
		$converter = $this->converter( $rates );

		for ( $i = 0; $i < 50; $i++ ) {
			$converter->convert( $i, 'USD', 'RUB' );
			$converter->convert( $i, 'EUR', 'RUB' );
			$converter->rate( 'RUB', 'EUR' );
		}

		$this->assertSame( 1, $rates->map_call_count() );
	}

	public function test_an_empty_table_is_also_read_only_once(): void {
		// The failing path must not become the expensive one: a site whose sync has never
		// run would otherwise pay a query per call to be told the same thing every time.
		$rates     = new InMemoryRateRepository();
		$converter = $this->converter( $rates );

		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$converter->convert( 1, 'USD', 'RUB' );
			} catch ( RatesUnavailableException $e ) {
				unset( $e );
			}
		}

		$this->assertSame( 1, $rates->map_call_count() );
	}

	public function test_flush_makes_the_next_conversion_read_again(): void {
		// For the one caller that needs it: a CLI or cron process that updates rates and
		// then converts while holding the same instance.
		$rates     = InMemoryRateRepository::with_fixed_rates();
		$converter = $this->converter( $rates );

		$converter->convert( 1, 'USD', 'RUB' );
		$converter->flush();
		$converter->convert( 1, 'USD', 'RUB' );

		$this->assertSame( 2, $rates->map_call_count() );
		$this->assertSame( 1, $rates->flush_call_count(), 'Clearing one layer and not the other refills from a stale map.' );
	}

	// -- bcmath, not floats -------------------------------------------------------------

	public function test_the_arithmetic_does_not_go_through_a_float(): void {
		// 0.1 * 3 is 0.30000000000000004 in PHP, and that is the error DECIMAL(24,12) and
		// this class exist to keep out of the answer.
		$rates = new InMemoryRateRepository( array( 'RUB' => '3.000000000000' ) );

		$this->assertNotSame( 0.3, 0.1 * 3, 'If this ever passes, PHP changed and the test below proves less.' );
		$this->assertSame( 0.3, $this->converter( $rates )->convert( 0.1, 'USD', 'RUB' ) );
	}

	public function test_a_tie_rounds_half_up_and_not_half_even_or_down(): void {
		// 0.5 × 1e-12 is exactly 0.0000000000005: a tie at the thirteenth place, with an
		// even digit before it. Half-up gives 1e-12, banker's rounding gives 0, and plain
		// truncation — which is what bcmath does unaided — gives 0 as well.
		$rates = new InMemoryRateRepository( array( 'RUB' => '0.000000000001' ) );

		$this->assertSame( 1.0e-12, $this->converter( $rates )->convert( 0.5, 'USD', 'RUB' ) );
	}

	public function test_a_negative_tie_rounds_away_from_zero(): void {
		// Matching what PHP's own round() does with PHP_ROUND_HALF_UP, so a refund and the
		// charge it reverses land the same distance from zero.
		$rates = new InMemoryRateRepository( array( 'RUB' => '0.000000000001' ) );

		$this->assertSame( -1.0e-12, $this->converter( $rates )->convert( -0.5, 'USD', 'RUB' ) );
	}

	// -- Twelve decimal places, which is the scale the column keeps ---------------------

	public function test_it_carries_all_twelve_decimal_places_of_a_stored_rate(): void {
		// `DECIMAL(24,12)` and every digit of it. The twelfth place is significant here, so
		// an implementation that worked at eleven — or that rounded to the two places money
		// is usually shown at — gives a different answer.
		$rates = new InMemoryRateRepository( array( 'RUB' => '0.123456789012' ) );

		$this->assertSame( 0.123456789012, $this->converter( $rates )->convert( 1, 'USD', 'RUB' ) );
		$this->assertSame( '0.123456789012', $this->converter( $rates )->rate( 'USD', 'RUB' ) );
	}

	public function test_a_non_terminating_quotient_is_rounded_at_the_twelfth_place(): void {
		// 1 USD buys 3 EUR, so 2 EUR is 2/3 USD — 0.6666… for ever. Twelve places, rounded
		// half-up, which makes the last digit a 7 and not the 6 truncation would leave.
		$rates     = new InMemoryRateRepository( array( 'EUR' => '3.000000000000' ) );
		$converter = $this->converter( $rates );

		$this->assertSame( 0.666666666667, $converter->convert( 2, 'EUR', 'USD' ) );
		$this->assertSame( 0.333333333333, $converter->convert( 1, 'EUR', 'USD' ) );
		$this->assertSame( '0.333333333333', $converter->rate( 'EUR', 'USD' ) );
	}

	public function test_the_twelfth_place_survives_a_cross_rate(): void {
		// Neither endpoint is the base, so this is a multiplication and a division, and the
		// guard digits below the twelfth place are what stop the two roundings compounding.
		$rates = new InMemoryRateRepository(
			array(
				'EUR' => '0.914567890100',
				'RUB' => '93.007123456700',
			)
		);

		$this->assertSame( '101.695155125696', $this->converter( $rates )->rate( 'EUR', 'RUB' ) );
		$this->assertSame( 101.695155125696, $this->converter( $rates )->convert( 1, 'EUR', 'RUB' ) );
	}

	public function test_an_amount_may_arrive_as_a_decimal_string(): void {
		// The reason the parameter is untyped: a `float` declaration would cast an exact
		// value read straight out of a DECIMAL column before the method ever saw it, which
		// is the one loss no amount of bcmath afterwards undoes.
		$this->assertSame( 11070.0, $this->converter()->convert( '123.000000000000', 'USD', 'RUB' ) );
	}

	// -- The rate itself ----------------------------------------------------------------

	public function test_it_reports_a_stored_rate_as_a_string(): void {
		// A string, because DECIMAL(24,12) is wider than a float and the admin table shows
		// what is stored rather than what survived a cast.
		$this->assertSame( '90.000000000000', $this->converter()->rate( 'USD', 'RUB' ) );
	}

	public function test_a_currency_is_worth_exactly_one_of_itself(): void {
		$this->assertSame( Rate::IDENTITY, $this->converter()->rate( 'EUR', 'EUR' ) );
	}

	// -- What it refuses ----------------------------------------------------------------

	public function test_an_unserved_currency_is_rejected_before_any_lookup(): void {
		$rates = InMemoryRateRepository::with_fixed_rates();

		try {
			$this->converter( $rates )->convert( 123, 'USD', 'XXX' );
			$this->fail( 'An unknown currency should have been rejected.' );
		} catch ( UnknownCurrencyException $e ) {
			$this->assertSame( 'XXX', $e->code() );
			$this->assertStringContainsString( 'Supported: AUD, BGN', $e->getMessage() );
		}

		$this->assertSame( 0, $rates->map_call_count() );
	}

	public function test_something_that_is_not_a_code_is_rejected_without_a_list(): void {
		// Shape, not membership: listing 33 codes at someone who typed "EURO" answers a
		// question they did not ask.
		try {
			$this->converter()->convert( 123, 'USD', 'EURO' );
			$this->fail( 'A malformed code should have been rejected.' );
		} catch ( UnknownCurrencyException $e ) {
			$this->assertSame( array(), $e->supported() );
			$this->assertStringContainsString( 'not a three-letter ISO 4217 currency code', $e->getMessage() );
		}
	}

	public function test_a_filtered_out_currency_stops_being_convertible(): void {
		// `currency_converter_currencies` is the module's one extension point, and narrowing
		// the list has to narrow what the converter will do, not just what a page lists.
		Functions\when( 'apply_filters' )->justReturn( array( 'USD', 'EUR' ) );

		$this->expectException( UnknownCurrencyException::class );

		$this->converter()->convert( 123, 'USD', 'RUB' );
	}

	public function test_an_empty_table_throws_rather_than_returning_the_amount(): void {
		try {
			$this->converter( new InMemoryRateRepository() )->convert( 123, 'USD', 'RUB' );
			$this->fail( 'A conversion with no stored rates should have thrown.' );
		} catch ( RatesUnavailableException $e ) {
			$this->assertSame( '', $e->target_code(), 'Nothing stored is not a per-pair failure.' );
			$this->assertStringContainsString( 'No exchange rates are stored for base USD', $e->getMessage() );
		}
	}

	public function test_convert_throws_when_target_currency_has_no_stored_rate(): void {
		// RUB deliberately, and not some currency nobody asked about: it is the brief's own
		// target, and C5 records that the API's supported set is not guaranteed to contain
		// it. This is what that situation has to look like — a named exception saying the
		// currency is real and the rate is not there, never a silent 1:1 or a zero. The
		// table is populated, so this is not the empty-table case tested above.
		$rates     = InMemoryRateRepository::with_fixed_rates_except( 'RUB' );
		$converter = $this->converter( $rates );

		// The rest of the table still converts, which is what makes the failure specific.
		$this->assertSame( 61.5, $converter->convert( 123, 'USD', 'EUR' ) );

		try {
			$converter->convert( 123, 'USD', 'RUB' );
			$this->fail( 'A missing rate should have thrown.' );
		} catch ( RatesUnavailableException $e ) {
			$this->assertSame( 'USD', $e->base_code() );
			$this->assertSame( 'RUB', $e->target_code() );
			$this->assertStringContainsString( 'RUB is a known currency', $e->getMessage() );
		}
	}

	public function test_a_missing_rate_is_not_an_unknown_currency(): void {
		// The two failures stay apart: RUB is on the predefined list whether or not a rate
		// for it is stored, and telling an operator their code is wrong when the real fix is
		// a cron job sends them looking for the wrong thing.
		$converter = $this->converter( InMemoryRateRepository::with_fixed_rates_except( 'RUB' ) );

		$this->assertContains( 'RUB', Currencies::codes() );

		$this->expectException( RatesUnavailableException::class );

		$converter->convert( 123, 'RUB', 'USD' );
	}

	public function test_a_stored_rate_of_zero_is_treated_as_missing_and_not_divided_by(): void {
		// The column is signed DECIMAL and nothing at the database level forbids a zero. A
		// cross rate out of that currency would be a division by zero; one unusable row must
		// cost that pair and not the other thirty-two.
		$rates = new InMemoryRateRepository(
			array(
				'RUB' => '0.000000000000',
				'EUR' => '0.500000000000',
			)
		);

		$converter = $this->converter( $rates );

		$this->assertSame( 61.5, $converter->convert( 123, 'USD', 'EUR' ), 'The good rows must still work.' );

		$this->expectException( RatesUnavailableException::class );

		$converter->convert( 123, 'RUB', 'EUR' );
	}

	/**
	 * Amounts that are not numbers.
	 *
	 * @return array<string, array{0: mixed}> Test cases.
	 */
	public static function unusable_amounts(): array {
		return array(
			'not a number'  => array( 'twelve fifty' ),
			'empty string'  => array( '' ),
			'comma decimal' => array( '12,50' ),
			'array'         => array( array( 123 ) ),
			'null'          => array( null ),
		);
	}

	/**
	 * @dataProvider unusable_amounts
	 *
	 * @param mixed $amount The value to reject.
	 */
	public function test_it_rejects_an_amount_that_is_not_a_number( $amount ): void {
		// `(float) '12,50'` is 12.0 — half the value, lost silently. Caught at the edge
		// instead, before anything can format it as money.
		$this->expectException( InvalidAmountException::class );

		$this->converter()->convert( $amount, 'USD', 'RUB' );
	}

	public function test_it_rejects_nan_and_infinity(): void {
		// Both are floats, both survive every arithmetic operation without complaining, and
		// both render as strings bcmath silently reads as zero.
		foreach ( array( NAN, INF, -INF ) as $amount ) {
			try {
				$this->converter()->convert( $amount, 'USD', 'RUB' );
				$this->fail( 'A non-finite amount should have been rejected.' );
			} catch ( InvalidAmountException $e ) {
				$this->assertStringContainsString( 'not a finite number', $e->getMessage() );
			}
		}
	}

	public function test_an_exponent_string_is_read_as_the_number_it_is(): void {
		// bcmath reads '1e2' as 1 and says nothing. Routed through a float instead, so the
		// answer is the one the caller meant rather than one hundredth of it.
		$this->assertSame( 9000.0, $this->converter()->convert( '1e2', 'USD', 'RUB' ) );
	}

	// -- The global helper ---------------------------------------------------------------

	public function test_the_global_helper_returns_one_shared_converter(): void {
		// The brief's example has to work literally: currency_converter()->convert( … ).
		//
		// One test, not several: the helper holds a static, so the first call in the process
		// decides what every later one returns. Splitting this would make the second test
		// depend on the order PHPUnit happened to run them in.
		$GLOBALS['wpdb'] = new FakeWpdb();

		require_once dirname( __DIR__, 2 ) . '/web/app/plugins/currency-converter/functions.php';

		$this->assertTrue( function_exists( 'currency_converter' ) );

		$converter = currency_converter();

		$this->assertInstanceOf( CurrencyConverter::class, $converter );
		$this->assertSame(
			$converter,
			currency_converter(),
			'A fresh instance per call site is a fresh query per call site.'
		);

		// Nothing else in the suite reads the global handle — every repository test injects
		// its own — and leaving one behind would let a class that silently falls back to the
		// global pass a test that should have caught it.
		unset( $GLOBALS['wpdb'] );
	}
}
