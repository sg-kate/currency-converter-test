<?php
/**
 * The port through which stored exchange rates are read and written.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for exchange rates, described in terms the domain uses.
 *
 * Declared here, in the domain, and implemented in `Db\` — the dependency points inwards,
 * so `Service\CurrencyConverter` depends on this interface and on nothing that knows what
 * `$wpdb` is. That is invariant 10 of the task contract, and it is also what lets the
 * converter's arithmetic be tested against an in-memory implementation with no database.
 *
 * Two rules bind every implementation, not just the `$wpdb` one:
 *
 * **Rates are strings.** `map()` returns strings, `Rate::value()` is a string, and no
 * implementation may cast a stored decimal to a float on the way out. See `Rate`.
 *
 * **Reads answer with data, not with exceptions.** `find()` returns null and `map()`
 * returns an empty array when there is nothing stored. Whether "no rate for TRY" is an
 * error depends on what the caller was doing — the admin list renders an empty table, the
 * converter throws `RatesUnavailableException` — so the decision belongs to the caller and
 * the named constructors on that exception are there for it.
 */
interface RateRepositoryInterface {

	/**
	 * Store a batch of rates, replacing any that are already there.
	 *
	 * Implementations must write the batch in as few statements as the storage allows —
	 * for `$wpdb` that is one multi-row `INSERT ... ON DUPLICATE KEY UPDATE`, and
	 * explicitly not `$wpdb->replace()` in a loop, which is a `DELETE` plus an `INSERT` per
	 * row: 33 round trips a day and 33 burned auto-increment values.
	 *
	 * The identity rate — `USD => 1.000000000000` for base USD — is written by the
	 * implementation for every base present in the batch, whatever the batch says about it.
	 * Every cross-rate divides by the base's own rate, so it cannot be left to the payload.
	 *
	 * @param array<int, Rate> $rates Rates to store. An empty array writes nothing.
	 * @return int Number of rows submitted, identity rows included.
	 * @throws \RuntimeException When the storage rejects the write.
	 */
	public function upsert( array $rates );

	/**
	 * Every rate against one base, as `target code => decimal string`.
	 *
	 * The converter's working set, and the reason this method exists next to `all()`: one
	 * query per request, memoised, rather than one query per `convert()` call. A loop over
	 * a cart calling `convert()` a hundred times must not be a hundred queries.
	 *
	 * @param string $base_code Base currency code.
	 * @return array<string, string> Rates keyed by target code. Values are decimal strings.
	 */
	public function map( $base_code );

	/**
	 * One stored rate.
	 *
	 * @param string $base_code   Base currency code.
	 * @param string $target_code Target currency code.
	 * @return Rate|null The rate, or null when the pair is not stored.
	 */
	public function find( $base_code, $target_code );

	/**
	 * Stored rates as objects, ordered and paged.
	 *
	 * For the admin list table. Recognised keys, all optional:
	 *
	 *     base_code  string  Restrict to one base. Empty for every base.
	 *     search     string  Restrict to rates whose base or target code contains this.
	 *                        Empty for no restriction, which is the default the admin page
	 *                        loads with — R7 asks for *all* saved rates, so paging is
	 *                        allowed and a filter applied by default is not.
	 *     orderby    string  Column to sort by. Anything not on the implementation's
	 *                        allowlist falls back to the default — it must never reach SQL,
	 *                        because `prepare()` binds values and not identifiers.
	 *     order      string  ASC or DESC; anything else is ASC.
	 *     per_page   int     Rows per page. 0 or negative means no limit.
	 *     page       int     1-based page number.
	 *
	 * @param array<string, mixed> $args Query arguments, as above.
	 * @return array<int, Rate> Rates, in the requested order.
	 */
	public function all( array $args = array() );

	/**
	 * How many rates are stored.
	 *
	 * The total, ignoring paging — it is what the admin page prints as "N items", and what
	 * makes "all saved rates" checkable against `SELECT COUNT(*)`.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return int Row count.
	 */
	public function count( $base_code = '' );

	/**
	 * When the most recent stored rate was fetched.
	 *
	 * The freshness window is built on this: a sync that finds a fetch time younger than a
	 * day skips rather than spending quota.
	 *
	 * @param string $base_code Restrict to one base, or empty for every base.
	 * @return \DateTimeImmutable|null UTC timestamp, or null when nothing is stored.
	 */
	public function last_fetched_at( $base_code = '' );

	/**
	 * Discard whatever `map()` has memoised.
	 *
	 * Called by `upsert()` already; public for the one case that needs it — a long-running
	 * process, WP-CLI or cron, that writes rates and then converts with the same instance.
	 *
	 * @return void
	 */
	public function flush();
}
