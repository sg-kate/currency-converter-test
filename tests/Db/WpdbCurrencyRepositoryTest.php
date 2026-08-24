<?php
/**
 * Currency metadata storage.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Db;

use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Domain\Currency;
use Tests\Fakes\FakeWpdb;
use Tests\TestCase;

/**
 * The display half of storage: one statement per batch, and one query per request.
 */
final class WpdbCurrencyRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;

	private WpdbCurrencyRepository $currencies;

	protected function set_up(): void {
		parent::set_up();

		$this->wpdb       = new FakeWpdb();
		$this->currencies = new WpdbCurrencyRepository( $this->wpdb );
	}

	public function test_a_batch_is_written_as_one_multi_row_insert(): void {
		$this->currencies->save_all(
			array(
				new Currency( 'EUR', 'Euro', '€', 2 ),
				new Currency( 'JPY', 'Japanese Yen', '¥', 0 ),
			)
		);

		$this->assertSame( 1, $this->wpdb->query_count() );

		$sql = $this->wpdb->last_query();

		$this->assertStringContainsString( 'INSERT INTO `wp_cc_currencies`', $sql );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringNotContainsStringIgnoringCase( 'REPLACE', $sql );
		$this->assertSame( 2, substr_count( $this->wpdb->prepared[0]['query'], '(%s, %s, %s, %d, %s)' ) );
	}

	public function test_zero_decimal_digits_are_written_as_zero(): void {
		$this->currencies->save_all( array( new Currency( 'JPY', 'Japanese Yen', '¥', 0 ) ) );

		// Not defaulted to 2 on the way to the column: a yen has no minor unit.
		$this->assertStringContainsString( "'JPY', 'Japanese Yen', '¥', 0,", $this->wpdb->last_query() );
	}

	public function test_repeated_codes_in_one_batch_are_collapsed(): void {
		$this->currencies->save_all(
			array(
				new Currency( 'EUR', 'Euro' ),
				new Currency( 'EUR', 'Euro (renamed)' ),
			)
		);

		$this->assertSame( 1, substr_count( $this->wpdb->prepared[0]['query'], '(%s, %s, %s, %d, %s)' ) );
		$this->assertStringContainsString( 'Euro (renamed)', $this->wpdb->last_query() );
	}

	public function test_an_empty_batch_writes_nothing(): void {
		$this->assertSame( 0, $this->currencies->save_all( array() ) );
		$this->assertSame( 0, $this->wpdb->query_count() );
	}

	public function test_a_failed_write_is_reported(): void {
		$this->wpdb->will_fail( 'Deadlock found when trying to get lock' );

		$this->expectException( \RuntimeException::class );

		$this->currencies->save_all( array( new Currency( 'EUR', 'Euro' ) ) );
	}

	public function test_a_batch_of_something_other_than_currencies_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->currencies->save_all( array( 'EUR' ) );
	}

	public function test_all_is_memoised_and_find_is_served_from_it(): void {
		$this->wpdb->will_return_rows(
			array(
				array(
					'code'           => 'EUR',
					'name'           => 'Euro',
					'symbol'         => '€',
					'decimal_digits' => '2',
				),
				array(
					'code'           => 'JPY',
					'name'           => 'Japanese Yen',
					'symbol'         => '¥',
					'decimal_digits' => '0',
				),
			)
		);

		$all = $this->currencies->all();

		$this->assertSame( array( 'EUR', 'JPY' ), array_keys( $all ) );
		$this->assertSame( 0, $all['JPY']->decimal_digits() );

		$this->currencies->all();
		$found = $this->currencies->find( 'jpy' );
		$this->currencies->count();

		$this->assertInstanceOf( Currency::class, $found );
		$this->assertSame( 'JPY', $found->code() );
		$this->assertSame( 2, $this->currencies->count() );
		$this->assertSame( 1, $this->wpdb->query_count(), 'The currency list is read once per request.' );
	}

	public function test_find_queries_when_nothing_is_memoised(): void {
		$this->wpdb->will_return_rows(
			array(
				array(
					'code'           => 'EUR',
					'name'           => 'Euro',
					'symbol'         => '€',
					'decimal_digits' => '2',
				),
			)
		);

		$found = $this->currencies->find( 'EUR' );

		$this->assertInstanceOf( Currency::class, $found );
		$this->assertStringContainsString( 'WHERE code = %s', $this->wpdb->last_prepared_template() );
	}

	public function test_find_returns_null_for_a_currency_with_no_metadata(): void {
		$this->assertNull( $this->currencies->find( 'TRY' ) );
	}

	public function test_writing_invalidates_the_memo(): void {
		$this->wpdb->will_return_rows( array() );
		$this->currencies->all();

		$this->currencies->save_all( array( new Currency( 'EUR', 'Euro' ) ) );
		$this->currencies->all();

		// read, write, read again: an empty table must not be memoised past a write.
		$this->assertSame( 3, $this->wpdb->query_count() );
	}
}
