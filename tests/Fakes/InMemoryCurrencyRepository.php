<?php
/**
 * Currency metadata storage that is an array.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Fakes;

use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\CurrencyRepositoryInterface;

/**
 * The currency-metadata counterpart to `InMemoryRateRepository`.
 *
 * Substituted for `WpdbCurrencyRepository`, it lets `AmountFormatter` be tested against
 * currencies chosen for the test — one with a symbol, one without, and JPY with its zero
 * decimal digits — with no database and no WordPress bootstrap.
 *
 * Like its rate counterpart it *counts* the reads. `all_call_count()` and
 * `find_call_count()` are what make "one query however many amounts" an assertion rather
 * than a claim: a formatter that looked each currency up on demand would pass every
 * formatting test here and fail that one.
 */
final class InMemoryCurrencyRepository implements CurrencyRepositoryInterface {

	/**
	 * Stored currencies, keyed by code.
	 *
	 * @var array<string, Currency>
	 */
	private array $currencies = array();

	/**
	 * How many times `all()` has been called.
	 */
	private int $all_calls = 0;

	/**
	 * How many times `find()` has been called.
	 */
	private int $find_calls = 0;

	/**
	 * Constructor.
	 *
	 * @param array<int, Currency> $currencies Currencies to serve.
	 */
	public function __construct( array $currencies = array() ) {
		foreach ( $currencies as $currency ) {
			$this->currencies[ $currency->code() ] = $currency;
		}
	}

	/**
	 * Replace everything stored.
	 *
	 * @param array<int, Currency> $currencies Currencies to store.
	 * @return int Number of rows submitted.
	 */
	public function save_all( array $currencies ) {
		foreach ( $currencies as $currency ) {
			$this->currencies[ $currency->code() ] = $currency;
		}

		return count( $currencies );
	}

	/**
	 * Everything stored, keyed by code.
	 *
	 * @return array<string, Currency> Currencies.
	 */
	public function all() {
		++$this->all_calls;

		return $this->currencies;
	}

	/**
	 * One currency, or null.
	 *
	 * @param string $code Currency code, any case.
	 * @return Currency|null The currency, or null when it is not stored.
	 */
	public function find( $code ) {
		++$this->find_calls;

		$normalized = Currency::normalize_code( $code );

		return isset( $this->currencies[ $normalized ] ) ? $this->currencies[ $normalized ] : null;
	}

	/**
	 * How many currencies are stored.
	 *
	 * @return int Row count.
	 */
	public function count() {
		return count( $this->currencies );
	}

	/**
	 * Forget everything.
	 *
	 * @return void
	 */
	public function flush() {
		$this->currencies = array();
	}

	/**
	 * How many times `all()` has been called.
	 */
	public function all_call_count(): int {
		return $this->all_calls;
	}

	/**
	 * How many times `find()` has been called.
	 */
	public function find_call_count(): int {
		return $this->find_calls;
	}
}
