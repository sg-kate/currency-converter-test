<?php
/**
 * Exchange rate storage.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Db;

use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Domain\Rate;
use Tests\Fakes\FakeWpdb;
use Tests\TestCase;

/**
 * What the repository asks the database to do.
 *
 * The SQL is the behaviour: one statement rather than thirty-three, `%s` rather than `%f`,
 * the identity rate written by us rather than trusted from the payload, and an `ORDER BY`
 * that no query string can reach. Each of those is a requirement in
 * `.claude/agents/_TASK_CONTRACT.md`, and each is asserted below rather than reviewed by eye.
 */
final class WpdbRateRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;

	private WpdbRateRepository $rates;

	protected function set_up(): void {
		parent::set_up();

		$this->wpdb  = new FakeWpdb();
		$this->rates = new WpdbRateRepository( $this->wpdb );
	}

	/**
	 * Three rates as the API's payload would produce them.
	 *
	 * @return array<int, Rate> The batch.
	 */
	private function batch(): array {
		$fetched_at = new \DateTimeImmutable( '2026-08-24 09:00:00', new \DateTimeZone( 'UTC' ) );

		return array(
			Rate::from_float( 'USD', 'RUB', 93.0071234567, $fetched_at ),
			Rate::from_float( 'USD', 'EUR', 0.9145678901, $fetched_at ),
			Rate::from_float( 'USD', 'JPY', 148.9012345678, $fetched_at ),
		);
	}

	public function test_a_batch_is_written_as_one_multi_row_insert(): void {
		$this->rates->upsert( $this->batch() );

		$this->assertSame( 1, $this->wpdb->query_count(), 'The batch must go out as a single statement.' );

		$sql = $this->wpdb->last_query();

		$this->assertStringContainsString( 'INSERT INTO `wp_cc_rates`', $sql );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringNotContainsStringIgnoringCase( 'REPLACE', $sql );

		// Four rows in one VALUES list: three from the payload plus the identity row.
		$this->assertSame( 4, substr_count( $this->wpdb->prepared[0]['query'], '(%s, %s, %s, %s)' ) );
	}

	public function test_the_rate_is_bound_as_a_string(): void {
		$this->rates->upsert( $this->batch() );

		$template = $this->wpdb->prepared[0]['query'];

		$this->assertStringNotContainsString( '%f', $template, 'A rate bound with %f is locale-formatted and lossy.' );
		$this->assertStringContainsString( "'93.007123456700'", $this->wpdb->last_query() );
	}

	public function test_the_identity_rate_is_written_first_and_is_exactly_one(): void {
		$this->rates->upsert( $this->batch() );

		$args = $this->wpdb->prepared[0]['args'];

		$this->assertSame( array( 'USD', 'USD', '1.000000000000', '2026-08-24 09:00:00' ), array_slice( $args, 0, 4 ) );
	}

	public function test_the_payload_cannot_override_the_identity_rate(): void {
		// If the API ever answered with something other than 1 for the base currency, that
		// value must not become the divisor every cross-rate goes through.
		$batch   = $this->batch();
		$batch[] = new Rate( 'USD', 'USD', '0.98' );

		$this->rates->upsert( $batch );

		$args = $this->wpdb->prepared[0]['args'];

		$this->assertSame( '1.000000000000', $args[2] );
		$this->assertStringNotContainsString( "'0.980000000000'", $this->wpdb->last_query() );
		// Still four rows: the payload's identity row was dropped, not added.
		$this->assertSame( 4, substr_count( $this->wpdb->prepared[0]['query'], '(%s, %s, %s, %s)' ) );
	}

	public function test_the_identity_rate_is_written_even_when_the_payload_omits_the_base(): void {
		$this->rates->upsert( array( Rate::from_float( 'USD', 'RUB', 93.0 ) ) );

		$this->assertStringContainsString( "'USD', 'USD', '1.000000000000'", $this->wpdb->last_query() );
	}

	public function test_repeated_pairs_in_one_batch_are_collapsed(): void {
		$this->rates->upsert(
			array(
				Rate::from_float( 'USD', 'RUB', 93.0 ),
				Rate::from_float( 'USD', 'RUB', 94.0 ),
			)
		);

		$this->assertSame( 2, substr_count( $this->wpdb->prepared[0]['query'], '(%s, %s, %s, %s)' ) );
		$this->assertStringContainsString( "'94.000000000000'", $this->wpdb->last_query() );
	}

	public function test_an_empty_batch_writes_nothing(): void {
		$this->assertSame( 0, $this->rates->upsert( array() ) );
		$this->assertSame( 0, $this->wpdb->query_count() );
	}

	public function test_a_failed_write_is_reported_and_not_swallowed(): void {
		$this->wpdb->will_fail( 'Table \'wp_cc_rates\' doesn\'t exist' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'doesn\'t exist' );

		$this->rates->upsert( $this->batch() );
	}

	public function test_a_batch_of_something_other_than_rates_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->rates->upsert( array( array( 'USD', 'RUB', '93.0' ) ) );
	}

	public function test_map_returns_strings_and_queries_once(): void {
		$this->wpdb->will_return_rows(
			array(
				array(
					'target_code' => 'RUB',
					'rate'        => '93.007123456789',
				),
				array(
					'target_code' => 'USD',
					'rate'        => '1.000000000000',
				),
			)
		);

		$first  = $this->rates->map( 'usd' );
		$second = $this->rates->map( 'USD' );

		$this->assertSame(
			array(
				'RUB' => '93.007123456789',
				'USD' => '1.000000000000',
			),
			$first
		);
		$this->assertIsString( $first['RUB'] );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->wpdb->query_count(), 'The rate map is memoised per request.' );
	}

	public function test_writing_rates_invalidates_the_memoised_map(): void {
		$this->wpdb->will_return_rows( array( array( 'target_code' => 'RUB', 'rate' => '93.000000000000' ) ) );
		$this->rates->map( 'USD' );

		$this->rates->upsert( $this->batch() );
		$this->rates->map( 'USD' );

		// read, write, read again — the second read must not come from the stale memo.
		$this->assertSame( 3, $this->wpdb->query_count() );
	}

	public function test_find_returns_null_when_the_pair_is_not_stored(): void {
		$this->assertNull( $this->rates->find( 'USD', 'TRY' ) );
	}

	public function test_find_hydrates_without_casting_the_decimal(): void {
		$this->wpdb->will_return_rows(
			array(
				array(
					'base_code'   => 'USD',
					'target_code' => 'RUB',
					'rate'        => '93.007123456789',
					'fetched_at'  => '2026-08-24 09:00:00',
				),
			)
		);

		$rate = $this->rates->find( 'usd', 'rub' );

		$this->assertInstanceOf( Rate::class, $rate );
		$this->assertSame( '93.007123456789', $rate->value() );
	}

	/**
	 * @dataProvider hostile_orderby
	 *
	 * @param mixed  $orderby What arrives in `$_GET['orderby']`.
	 * @param string $order   What arrives in `$_GET['order']`.
	 */
	public function test_orderby_and_order_come_from_an_allowlist( $orderby, $order ): void {
		$this->rates->all(
			array(
				'orderby' => $orderby,
				'order'   => $order,
			)
		);

		$sql = $this->wpdb->last_query();

		$this->assertStringContainsString( 'ORDER BY target_code ASC, id ASC', $sql );
		$this->assertStringNotContainsStringIgnoringCase( 'DROP', $sql );
		$this->assertStringNotContainsString( 'SLEEP', $sql );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}> Sort arguments that must not reach SQL.
	 */
	public static function hostile_orderby(): array {
		return array(
			'injection'    => array( 'rate; DROP TABLE wp_cc_rates', 'ASC' ),
			'subselect'    => array( '(SELECT SLEEP(5))', 'ASC' ),
			'unknown col'  => array( 'id', 'ASC' ),
			'backticks'    => array( '`rate`', 'ASC' ),
			'not a string' => array( array( 'rate' ), 'ASC' ),
			'bad direction' => array( 'rate; --', 'DESC; DROP TABLE wp_cc_rates' ),
		);
	}

	public function test_an_allowlisted_sort_is_honoured(): void {
		$this->rates->all(
			array(
				'orderby' => 'RATE',
				'order'   => 'desc',
			)
		);

		$this->assertStringContainsString( 'ORDER BY rate DESC, id ASC', $this->wpdb->last_query() );
	}

	public function test_paging_binds_limit_and_offset(): void {
		$this->rates->all(
			array(
				'per_page' => 20,
				'page'     => 3,
			)
		);

		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $this->wpdb->last_prepared_template() );
		$this->assertStringContainsString( 'LIMIT 20 OFFSET 40', $this->wpdb->last_query() );
	}

	public function test_per_page_is_clamped(): void {
		$this->rates->all( array( 'per_page' => 100000 ) );

		$this->assertStringContainsString( 'LIMIT 500 OFFSET 0', $this->wpdb->last_query() );
	}

	public function test_no_limit_is_requested_when_per_page_is_zero(): void {
		$this->rates->all( array( 'per_page' => 0 ) );

		$this->assertStringNotContainsString( 'LIMIT', $this->wpdb->last_query() );
	}

	public function test_count_is_the_total_and_not_a_page(): void {
		$this->wpdb->will_return_var( '33' );

		$this->assertSame( 33, $this->rates->count() );
		$this->assertStringContainsString( 'SELECT COUNT(*) FROM `wp_cc_rates`', $this->wpdb->last_query() );
	}

	public function test_a_base_filter_is_bound_as_a_value(): void {
		$this->rates->count( "USD' OR 1=1 --" );

		$this->assertStringContainsString( 'WHERE base_code = %s', $this->wpdb->last_prepared_template() );
		$this->assertStringNotContainsString( 'OR 1=1 --', $this->wpdb->last_prepared_template() );
	}

	public function test_last_fetched_at_reads_as_utc(): void {
		$this->wpdb->will_return_var( '2026-08-24 09:00:00' );

		$fetched_at = $this->rates->last_fetched_at();

		$this->assertInstanceOf( \DateTimeImmutable::class, $fetched_at );
		$this->assertSame( '2026-08-24 09:00:00', $fetched_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', $fetched_at->getTimezone()->getName() );
	}

	public function test_last_fetched_at_is_null_on_an_empty_table(): void {
		$this->wpdb->will_return_var( null );

		$this->assertNull( $this->rates->last_fetched_at() );
	}
}
