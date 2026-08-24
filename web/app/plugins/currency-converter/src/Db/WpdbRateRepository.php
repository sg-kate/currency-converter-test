<?php
/**
 * Exchange rate storage on `$wpdb`.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Db;

use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Domain\RateRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `{$wpdb->prefix}cc_rates`.
 *
 * One of the two files in the module that know what a database is. Everything above it
 * works with `Rate` objects and decimal strings.
 *
 * Four decisions are load-bearing here, and each one is a bug avoided rather than a
 * preference:
 *
 * **One statement, not one per row.** The whole batch goes out as a single multi-row
 * `INSERT ... ON DUPLICATE KEY UPDATE`. The obvious alternative, `$wpdb->replace()` in a
 * loop, is wrong twice over: `REPLACE` is a `DELETE` followed by an `INSERT`, so it burns
 * 33 auto-increment values every single day and would exhaust a signed `INT` id in a decade
 * of daily syncs — and it is 33 network round trips where one will do.
 *
 * **`%s` binds the rate, never `%f`.** `%f` formats through the current locale, so under a
 * `de_DE` locale it would bind `93,0071` and MySQL would read that as `93`. It is lossy as
 * well: the float it formats has fewer significant digits than `DECIMAL(24,12)` holds.
 *
 * **A decimal read back is a string and stays one.** No `(float)` appears in this file.
 * Casting on the way out throws away the precision the column exists to keep.
 *
 * **`ORDER BY` comes from an allowlist.** `orderby` and `order` arrive from `$_GET` on the
 * admin screen, and `$wpdb->prepare()` binds *values*, not identifiers — there is no
 * placeholder for a column name. A requested column is matched against a fixed list and the
 * default is used when it does not match, so nothing from the request is ever interpolated.
 */
final class WpdbRateRepository implements RateRepositoryInterface {

	/**
	 * Columns a caller may sort by, and the only strings that can reach `ORDER BY`.
	 */
	const SORTABLE_COLUMNS = array( 'base_code', 'target_code', 'rate', 'fetched_at' );

	/**
	 * Column sorted on when the request asks for one that is not on the allowlist.
	 */
	const DEFAULT_ORDERBY = 'target_code';

	/**
	 * Rows per page when the caller does not say.
	 *
	 * Above today's 33, so the admin page shows every stored rate on one screen while the
	 * single-base design holds, and pages properly if a paid plan ever adds bases.
	 */
	const DEFAULT_PER_PAGE = 50;

	/**
	 * Ceiling on `per_page`, so a crafted `?per_page=100000` cannot ask for everything.
	 */
	const MAX_PER_PAGE = 500;

	/**
	 * Rows per `INSERT` statement.
	 *
	 * Today's batch is 33 and goes out as exactly one statement. The chunking is for the
	 * case the schema was designed for — `base_code` is a real column, so a paid plan turns
	 * 33 rows into 33 × 33 — where one statement would approach `max_allowed_packet`. It is
	 * never one statement per row.
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
	 * Memoised rate maps, keyed by base code.
	 *
	 * The reason `convert()` in a loop is one query rather than N. Per request only — this
	 * is an object property, not a cache: nothing survives the response, so a rate written
	 * by another process is picked up on the next request rather than being stale for the
	 * length of an object cache TTL.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $maps = array();

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb Database handle. Defaults to the global one; injected in tests.
	 */
	public function __construct( $wpdb = null ) {
		$this->wpdb  = null === $wpdb ? $GLOBALS['wpdb'] : $wpdb;
		$this->table = Schema::rates_table( $this->wpdb );
	}

	/**
	 * Store a batch of rates.
	 *
	 * The identity row for every base in the batch is written first and at exactly
	 * `1.000000000000`, whatever the payload said — see `identity_first()`.
	 *
	 * @param array<int, Rate> $rates Rates to store.
	 * @return int Number of rows submitted, identity rows included.
	 * @throws \InvalidArgumentException When the array holds something that is not a Rate.
	 * @throws \RuntimeException When the database rejects the write.
	 */
	public function upsert( array $rates ) {
		$rows = $this->identity_first( $rates );

		if ( array() === $rows ) {
			return 0;
		}

		// One timestamp for the whole batch: rows written by the same sync should not
		// differ by a second, because the freshness window compares against the newest.
		$now       = gmdate( Rate::DATETIME_FORMAT );
		$submitted = 0;

		foreach ( array_chunk( $rows, self::MAX_ROWS_PER_STATEMENT ) as $chunk ) {
			$submitted += $this->insert_chunk( $chunk, $now );
		}

		$this->flush();

		return $submitted;
	}

	/**
	 * Every rate against one base, as `target code => decimal string`.
	 *
	 * @param string $base_code Base currency code.
	 * @return array<string, string> Rates keyed by target code; empty when none are stored.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When the code is malformed.
	 */
	public function map( $base_code ) {
		$base = Currency::normalize_code( $base_code );

		if ( isset( $this->maps[ $base ] ) ) {
			return $this->maps[ $base ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the value is bound.
		$sql = $this->wpdb->prepare( "SELECT target_code, rate FROM `{$this->table}` WHERE base_code = %s", $base );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; memoised per request by design, see $maps.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		$map = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// (string), never (float): the column is DECIMAL(24,12) and a float cannot hold it.
			$map[ (string) $row['target_code'] ] = (string) $row['rate'];
		}

		$this->maps[ $base ] = $map;

		return $map;
	}

	/**
	 * One stored rate.
	 *
	 * @param string $base_code   Base currency code.
	 * @param string $target_code Target currency code.
	 * @return Rate|null The rate, or null when the pair is not stored.
	 * @throws \Drozd\Currency\Exception\UnknownCurrencyException When either code is malformed.
	 */
	public function find( $base_code, $target_code ) {
		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; both values are bound.
			"SELECT base_code, target_code, rate, fetched_at FROM `{$this->table}` WHERE base_code = %s AND target_code = %s",
			Currency::normalize_code( $base_code ),
			Currency::normalize_code( $target_code )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? Rate::from_row( $row ) : null;
	}

	/**
	 * Stored rates as objects, ordered and paged.
	 *
	 * @param array<string, mixed> $args See `RateRepositoryInterface::all()`.
	 * @return array<int, Rate> Rates, in the requested order.
	 */
	public function all( array $args = array() ) {
		$orderby  = self::sanitize_orderby( isset( $args['orderby'] ) ? $args['orderby'] : '' );
		$order    = self::sanitize_order( isset( $args['order'] ) ? $args['order'] : '' );
		$per_page = self::sanitize_per_page( isset( $args['per_page'] ) ? $args['per_page'] : self::DEFAULT_PER_PAGE );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		list( $where, $params ) = $this->where_clause( $args );

		$sql = "SELECT base_code, target_code, rate, fetched_at FROM `{$this->table}`" . $where;

		// $orderby and $order are allowlisted literals from the constants above — never the
		// caller's string — because prepare() has no placeholder for an identifier. `id` is
		// the tiebreaker, so paging cannot show the same row twice when rates are equal.
		$sql .= " ORDER BY {$orderby} {$order}, id ASC";

		if ( $per_page > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Values bound below; identifiers are allowlisted constants.
		$query = array() === $params ? $sql : $this->wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		$rates = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$rates[] = Rate::from_row( $row );
		}

		return $rates;
	}

	/**
	 * How many rates are stored.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return int Row count.
	 */
	public function count( $base_code = '' ) {
		return $this->count_matching( array( 'base_code' => $base_code ) );
	}

	/**
	 * How many rates match a query.
	 *
	 * Not on `RateRepositoryInterface`, and deliberately so: the interface's `count()` is the
	 * total that makes "all saved rates" checkable against `SELECT COUNT(*)`, and widening it
	 * to take a filter would blur exactly the number R7 is verified with. This is the paging
	 * companion to `all()` — the admin list table needs the count *of the same query* to size
	 * its pager, and it must be the only place a filtered count is used.
	 *
	 * @param array<string, mixed> $args Same `base_code` and `search` keys as `all()`.
	 * @return int Matching row count.
	 */
	public function count_matching( array $args = array() ) {
		list( $where, $params ) = $this->where_clause( $args );

		$sql = "SELECT COUNT(*) FROM `{$this->table}`" . $where;

		if ( array() === $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No filter, so the query is the table name from $wpdb->prefix and literals.
			return (int) $this->wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every value is bound; the clause is built from literals.
		$query = $this->wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return (int) $this->wpdb->get_var( $query );
	}

	/**
	 * When the most recent stored rate was fetched.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return \DateTimeImmutable|null UTC timestamp, or null when nothing is stored.
	 */
	public function last_fetched_at( $base_code = '' ) {
		$base = self::base_filter( $base_code );

		if ( '' === $base ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input in the query.
			$value = $this->wpdb->get_var( "SELECT MAX(fetched_at) FROM `{$this->table}`" );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the value is bound.
			$sql = $this->wpdb->prepare( "SELECT MAX(fetched_at) FROM `{$this->table}` WHERE base_code = %s", $base );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$value = $this->wpdb->get_var( $sql );
		}

		// One parser for the column, zero dates and timezone included.
		return Rate::datetime_from_string( $value );
	}

	/**
	 * Discard the memoised rate maps.
	 *
	 * @return void
	 */
	public function flush() {
		$this->maps = array();
	}

	/**
	 * Put the identity rate for every base at the front of the batch and drop the API's own.
	 *
	 * "Unconditionally, ahead of the API data" is the requirement, and both halves matter.
	 * `/v1/latest` does return `USD: 1.0` today, but the module must not depend on it: every
	 * conversion out of the base divides by the base's own rate, so a payload that omits it
	 * — or a plan change that starts omitting it — would turn every conversion into a
	 * division by a missing key. Writing it ourselves means the row is there whatever
	 * arrives, and writing it *first* means a payload that carries something other than
	 * exactly 1 for the base cannot overwrite it: the API's identity row is dropped here,
	 * not merged.
	 *
	 * Duplicate pairs within one batch are collapsed too, last one winning, so a payload
	 * with a repeated key cannot produce two rows competing inside one statement.
	 *
	 * @param array<int, Rate> $rates The batch as given.
	 * @return array<int, Rate> Identity rows first, then the rest, deduplicated.
	 * @throws \InvalidArgumentException When the array holds something that is not a Rate.
	 */
	private function identity_first( array $rates ) {
		$bases  = array();
		$others = array();

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof Rate ) {
				throw new \InvalidArgumentException(
					sprintf( 'Expected %s objects to store, got %s.', Rate::class, gettype( $rate ) )
				);
			}

			// Every base seen gets an identity row, whether or not the payload had one.
			if ( ! isset( $bases[ $rate->base_code() ] ) || null === $bases[ $rate->base_code() ] ) {
				$bases[ $rate->base_code() ] = $rate->fetched_at();
			}

			if ( $rate->is_identity() ) {
				continue;
			}

			$others[ $rate->pair_key() ] = $rate;
		}

		$identity = array();

		foreach ( $bases as $base => $fetched_at ) {
			$identity[] = Rate::identity( $base, $fetched_at );
		}

		return array_merge( $identity, array_values( $others ) );
	}

	/**
	 * Write one chunk as a single statement.
	 *
	 * @param array<int, Rate> $chunk Rates to write.
	 * @param string           $now   Fallback fetch time, UTC `Y-m-d H:i:s`.
	 * @return int Rows submitted.
	 * @throws \RuntimeException When the database rejects the statement.
	 */
	private function insert_chunk( array $chunk, $now ) {
		$placeholders = array();
		$values       = array();

		foreach ( $chunk as $rate ) {
			$placeholders[] = '(%s, %s, %s, %s)';

			$values[] = $rate->base_code();
			$values[] = $rate->target_code();
			// %s. Never %f — see the class docblock.
			$values[] = $rate->value();
			$values[] = $rate->fetched_at_string( $now );
		}

		// `VALUES(col)` in the update clause is MariaDB's supported spelling (this stack runs
		// MariaDB 10.11); MySQL 8.0.20 deprecated it in favour of a row alias, which MariaDB
		// does not accept. If this ever has to run on MySQL 8, the alias form replaces it.
		$sql = "INSERT INTO `{$this->table}` (base_code, target_code, rate, fetched_at) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = VALUES(fetched_at)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated literals; every value is bound.
		$query = $this->wpdb->prepare( $sql, $values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$result = $this->wpdb->query( $query );

		if ( false === $result ) {
			// Reported, not swallowed: a sync that could not write must not look like one
			// that wrote nothing because there was nothing to write.
			throw new \RuntimeException(
				sprintf(
					'Could not write %d exchange rates: %s',
					count( $chunk ),
					isset( $this->wpdb->last_error ) && '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown database error'
				)
			);
		}

		// Deliberately not `$result`: for `ON DUPLICATE KEY UPDATE`, MySQL reports 1 per
		// inserted row, 2 per updated row and 0 per row that was already identical, so the
		// affected-rows figure is not a row count and printing it as one confuses everyone.
		return count( $chunk );
	}

	/**
	 * Build the shared `WHERE` clause for `all()` and `count_matching()`.
	 *
	 * One builder for both, so the list table's pager can never be sized by a different
	 * query from the one that produced its rows — the bug where page 3 of a search is empty
	 * because the count came from an unfiltered query.
	 *
	 * The search term is escaped with `$wpdb->esc_like()` before it is bound. That is not the
	 * same job as `prepare()` and neither substitutes for the other: `prepare()` stops the
	 * value being read as SQL, `esc_like()` stops a literal `%` or `_` inside it being read
	 * as a wildcard by `LIKE`. Without the first it is an injection; without the second a
	 * search for `_` quietly matches everything.
	 *
	 * @param array<string, mixed> $args Query arguments; `base_code` and `search` are read.
	 * @return array{0: string, 1: array<int, string>} The clause (with a leading space, or
	 *                                                  empty) and the values to bind.
	 */
	private function where_clause( array $args ) {
		$base   = self::base_filter( isset( $args['base_code'] ) ? $args['base_code'] : '' );
		$search = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';

		$clauses = array();
		$params  = array();

		if ( '' !== $base ) {
			$clauses[] = 'base_code = %s';
			$params[]  = $base;
		}

		if ( '' !== $search ) {
			$like = '%' . $this->wpdb->esc_like( $search ) . '%';

			// Both codes, so "EUR" finds the pair from either side. The currency *name* is in
			// the other table and is deliberately not joined in: a join here would make the
			// count and the rows depend on whether the metadata sync has run.
			$clauses[] = '(base_code LIKE %s OR target_code LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( array() === $clauses ) {
			return array( '', array() );
		}

		return array( ' WHERE ' . implode( ' AND ', $clauses ), $params );
	}

	/**
	 * Reduce a requested sort column to one this table actually has.
	 *
	 * @param mixed $orderby Whatever arrived, typically straight from `$_GET`.
	 * @return string An allowlisted column name.
	 */
	private static function sanitize_orderby( $orderby ) {
		if ( ! is_string( $orderby ) ) {
			return self::DEFAULT_ORDERBY;
		}

		$requested = strtolower( trim( $orderby ) );

		return in_array( $requested, self::SORTABLE_COLUMNS, true ) ? $requested : self::DEFAULT_ORDERBY;
	}

	/**
	 * Reduce a requested sort direction to `ASC` or `DESC`.
	 *
	 * @param mixed $order Whatever arrived, typically straight from `$_GET`.
	 * @return string `ASC` or `DESC`.
	 */
	private static function sanitize_order( $order ) {
		return is_string( $order ) && 'desc' === strtolower( trim( $order ) ) ? 'DESC' : 'ASC';
	}

	/**
	 * Clamp a requested page size.
	 *
	 * @param mixed $per_page Requested size; 0 or negative means no limit.
	 * @return int Rows per page, at most MAX_PER_PAGE.
	 */
	private static function sanitize_per_page( $per_page ) {
		$requested = (int) $per_page;

		if ( $requested <= 0 ) {
			return 0;
		}

		return min( self::MAX_PER_PAGE, $requested );
	}

	/**
	 * Normalise a base-code filter without throwing on rubbish.
	 *
	 * A malformed code from a hand-edited query string is bound as a value like any other
	 * and simply matches no rows — which is the truthful answer, and better than either an
	 * uncaught exception on an admin screen or silently dropping the filter and showing
	 * every base as though it had been asked for.
	 *
	 * @param mixed $base_code Requested base code.
	 * @return string Upper-case code, or an empty string for "no filter".
	 */
	private static function base_filter( $base_code ) {
		if ( ! is_string( $base_code ) ) {
			return '';
		}

		return strtoupper( trim( $base_code ) );
	}
}
