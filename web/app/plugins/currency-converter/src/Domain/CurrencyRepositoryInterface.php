<?php
/**
 * The port through which currency metadata is read and written.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for the display metadata of currencies: name, symbol, minor units.
 *
 * Describes currencies; it does not decide which ones exist. `Currencies::CODES` is the
 * single source of truth for membership — invariant 2 of the task contract — and a row
 * here that has no matching code in that list is metadata for a currency the module does
 * not serve, not an extra currency. Implementations must not filter the hardcoded list
 * against this table, in either direction.
 *
 * Reads answer with data: `find()` returns null for a code that is not stored, which is
 * an ordinary state for a site whose metadata sync has not run yet — every currency still
 * converts, it just displays as its bare code. Whether that is worth an exception is the
 * caller's judgement, not this layer's.
 */
interface CurrencyRepositoryInterface {

	/**
	 * Store a batch of currencies, replacing any already there.
	 *
	 * One multi-row `INSERT ... ON DUPLICATE KEY UPDATE` for the same reason as
	 * `RateRepositoryInterface::upsert()`: a `replace()` per row is a `DELETE` plus an
	 * `INSERT` and a round trip each time.
	 *
	 * @param array<int, Currency> $currencies Currencies to store.
	 * @return int Number of rows submitted.
	 * @throws \RuntimeException When the storage rejects the write.
	 */
	public function save_all( array $currencies );

	/**
	 * Every stored currency, keyed by code.
	 *
	 * Memoised per request by implementations that can: the admin table asks for this once
	 * per row otherwise.
	 *
	 * @return array<string, Currency> Currencies keyed by upper-case code, ordered by code.
	 */
	public function all();

	/**
	 * One stored currency.
	 *
	 * @param string $code Currency code, any case.
	 * @return Currency|null The currency, or null when no metadata is stored for it.
	 */
	public function find( $code );

	/**
	 * How many currencies have stored metadata.
	 *
	 * Zero is the signal the metadata sync has never run — which is what schedules it,
	 * rather than a weekly refresh of data that does not change.
	 *
	 * @return int Row count.
	 */
	public function count();

	/**
	 * Discard whatever `all()` has memoised.
	 *
	 * @return void
	 */
	public function flush();
}
