<?php
/**
 * Currency metadata storage on `$wpdb`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Db;

use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\CurrencyRepositoryInterface;
use Drozd\Currency\Domain\Rate;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `{$wpdb->prefix}cc_currencies`.
 *
 * The display half of the module: name, symbol and minor units, as `/v1/currencies`
 * reports them. It answers "how is EUR written", never "does EUR exist" —
 * `Currencies::CODES` owns membership, and a currency with no row here still converts.
 *
 * Same write discipline as `WpdbRateRepository`: one multi-row
 * `INSERT ... ON DUPLICATE KEY UPDATE` for the whole batch rather than `$wpdb->replace()`
 * per row. This table has no auto-increment to burn — `code` is the primary key — but a
 * `REPLACE` per row is still a `DELETE` plus an `INSERT` and a round trip each, and having
 * the two repositories write the same way means there is one pattern to review, not two.
 *
 * `all()` is memoised for the same reason `WpdbRateRepository::map()` is: the admin table
 * asks for a currency's symbol once per row, and that must not be one query per row.
 */
final class WpdbCurrencyRepository implements CurrencyRepositoryInterface {

	/**
	 * Rows per `INSERT` statement. The list is 33 today, so this is one statement.
	 */
	const MAX_ROWS_PER_STATEMENT = 100;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Prefixed table name, resolved once from the handle above.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Memoised result of `all()`, or null when it has not been read this request.
	 *
	 * Null rather than an empty array as the "not loaded" marker: an empty table is a real
	 * and expected state — metadata has simply not been synced — and must not be re-queried
	 * on every call just because it has nothing in it.
	 *
	 * @var array<string, Currency>|null
	 */
	private $memo = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb Database handle. Defaults to the global one; injected in tests.
	 */
	public function __construct( $wpdb = null ) {
		$this->wpdb  = null === $wpdb ? $GLOBALS['wpdb'] : $wpdb;
		$this->table = Schema::currencies_table( $this->wpdb );
	}

	/**
	 * Store a batch of currencies.
	 *
	 * @param array<int, Currency> $currencies Currencies to store.
	 * @return int Number of rows submitted.
	 * @throws \InvalidArgumentException When the array holds something that is not a Currency.
	 * @throws \RuntimeException When the database rejects the write.
	 */
	public function save_all( array $currencies ) {
		$rows = $this->deduplicate( $currencies );

		if ( array() === $rows ) {
			return 0;
		}

		$now       = gmdate( Rate::DATETIME_FORMAT );
		$submitted = 0;

		foreach ( array_chunk( $rows, self::MAX_ROWS_PER_STATEMENT ) as $chunk ) {
			$submitted += $this->insert_chunk( $chunk, $now );
		}

		$this->flush();

		return $submitted;
	}

	/**
	 * Every stored currency, keyed by code.
	 *
	 * @return array<string, Currency> Currencies keyed by upper-case code, ordered by code.
	 */
	public function all() {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input in the query; memoised per request, see $memo.
		$rows = $this->wpdb->get_results( "SELECT code, name, symbol, decimal_digits FROM `{$this->table}` ORDER BY code ASC", ARRAY_A );

		$currencies = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$currency = Currency::from_array( $row );

			$currencies[ $currency->code() ] = $currency;
		}

		$this->memo = $currencies;

		return $currencies;
	}

	/**
	 * One stored currency.
	 *
	 * Served from the memo when `all()` has already run this request, which is the case on
	 * every screen that renders a list — no second query for a row already in memory.
	 *
	 * @param string $code Currency code, any case.
	 * @return Currency|null The currency, or null when no metadata is stored for it.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When the code is malformed.
	 */
	public function find( $code ) {
		$normalized = Currency::normalize_code( $code );

		if ( null !== $this->memo ) {
			return isset( $this->memo[ $normalized ] ) ? $this->memo[ $normalized ] : null;
		}

		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the value is bound.
			"SELECT code, name, symbol, decimal_digits FROM `{$this->table}` WHERE code = %s",
			$normalized
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? Currency::from_array( $row ) : null;
	}

	/**
	 * How many currencies have stored metadata.
	 *
	 * @return int Row count.
	 */
	public function count() {
		if ( null !== $this->memo ) {
			return count( $this->memo );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input in the query.
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" );
	}

	/**
	 * Discard the memoised currency list.
	 *
	 * @return void
	 */
	public function flush() {
		$this->memo = null;
	}

	/**
	 * Check the batch and collapse repeated codes, last one winning.
	 *
	 * Keyed by code while collapsing, so a payload carrying the same currency twice cannot
	 * produce two rows competing inside a single statement.
	 *
	 * @param array<int, Currency> $currencies The batch as given.
	 * @return array<int, Currency> The batch, deduplicated.
	 * @throws \InvalidArgumentException When the array holds something that is not a Currency.
	 */
	private function deduplicate( array $currencies ) {
		$rows = array();

		foreach ( $currencies as $currency ) {
			if ( ! $currency instanceof Currency ) {
				throw new \InvalidArgumentException(
					sprintf( 'Expected %s objects to store, got %s.', Currency::class, gettype( $currency ) )
				);
			}

			$rows[ $currency->code() ] = $currency;
		}

		return array_values( $rows );
	}

	/**
	 * Write one chunk as a single statement.
	 *
	 * @param array<int, Currency> $chunk Currencies to write.
	 * @param string               $now   Update timestamp, UTC `Y-m-d H:i:s`.
	 * @return int Rows submitted.
	 * @throws \RuntimeException When the database rejects the statement.
	 */
	private function insert_chunk( array $chunk, $now ) {
		$placeholders = array();
		$values       = array();

		foreach ( $chunk as $currency ) {
			// %d for the digit count, %s for everything else — see WpdbRateRepository on placeholders.
			$placeholders[] = '(%s, %s, %s, %d, %s)';

			$values[] = $currency->code();
			$values[] = $currency->name();
			$values[] = $currency->symbol();
			$values[] = $currency->decimal_digits();
			$values[] = $now;
		}

		// `VALUES(col)` is MariaDB's spelling; see the note in WpdbRateRepository.
		$sql = "INSERT INTO `{$this->table}` (code, name, symbol, decimal_digits, updated_at) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol),'
			. ' decimal_digits = VALUES(decimal_digits), updated_at = VALUES(updated_at)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated literals; every value is bound.
		$query = $this->wpdb->prepare( $sql, $values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$result = $this->wpdb->query( $query );

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf(
					'Could not write %d currencies: %s',
					count( $chunk ),
					isset( $this->wpdb->last_error ) && '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown database error'
				)
			);
		}

		// Not `$result`: affected-rows counts updates as 2 under ON DUPLICATE KEY UPDATE.
		return count( $chunk );
	}
}
