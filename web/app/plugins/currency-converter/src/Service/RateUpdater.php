<?php
/**
 * Fetches rates and metadata, and decides when not to.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Service;

use Drozd\Currency\Api\FreeCurrencyApiClient;
use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\DemoMode;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\CurrencyRepositoryInterface;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Domain\RateRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates fetch → reconcile → store, and refuses to do it too often.
 *
 * The quota is 5,000 requests a month and one daily sync needs about 30 of them. That
 * margin disappears the moment something calls this in a loop, and two things in this
 * stack will: the `cron` container runs `wp cron event run --due-now` every
 * `CRON_INTERVAL` seconds, so a due event that never records having run is due again a
 * minute later; and "Update now" on the settings page is a button a person can click
 * twice. Both are ordinary, and both are stopped here rather than by asking people to be
 * careful.
 *
 * **The freshness window** is the real bound and the one that matters: nothing is fetched
 * while the newest stored rate is younger than 23 hours, unless the caller forces it. It is
 * checked against `fetched_at` in the table rather than against an option, so it is the
 * data itself that decides — an option that drifted from the rows it describes would let a
 * sync run against rates it thinks are missing, or skip one against rates that are gone.
 * 23 and not 24, so a job that runs at the same time each day is never refused by a few
 * seconds of jitter and pushed to the next day.
 *
 * **The lock** covers the seconds the freshness window cannot: two requests that both start
 * before either has stored anything. It is a 60-second transient — comfortably longer than
 * a sync, short enough that a fatal error in the middle costs one minute rather than
 * wedging the module until someone finds the option. It is released in a `finally`, so an
 * API failure does not leave it held.
 *
 * The lock is a check followed by a write, not a compare-and-swap, so two requests
 * arriving in the same millisecond can both take it. That is understood and accepted: the
 * cost of losing that race is exactly one extra request, because the second sync finds
 * fresh rates and stops. It is not a mutex and nothing here treats it as one.
 *
 * **Metadata is synced on a different clock.** Names, symbols and minor units change
 * essentially never, so fetching them daily would double the spend to re-store identical
 * rows. `update_currencies()` runs when the table is empty — the first sync, which seeds it
 * — and then at most weekly.
 *
 * A sync that cannot reach the API throws. It does not fall back to stale rates presented
 * as current, and it does not write a partial batch: `latest()` throws before returning an
 * empty payload, and nothing is stored until the whole response has been read.
 */
final class RateUpdater {

	/**
	 * How old stored rates may be before a sync is allowed, in seconds.
	 */
	const FRESHNESS_WINDOW = 23 * 3600;

	/**
	 * How long metadata is considered current, in seconds.
	 */
	const CURRENCIES_WINDOW = 7 * 24 * 3600;

	/**
	 * How long a lock is held before it expires on its own, in seconds.
	 */
	const LOCK_TIMEOUT = 60;

	/**
	 * Transient holding the rate-sync lock.
	 */
	const LOCK_RATES = 'currency_converter_lock_rates';

	/**
	 * Transient holding the metadata-sync lock.
	 *
	 * Separate from the rate lock on purpose: a single run that refreshes both — the first
	 * one after activation does — would otherwise block itself on the second call.
	 */
	const LOCK_CURRENCIES = 'currency_converter_lock_currencies';

	/**
	 * Option holding the last successful rate sync, ISO-8601 UTC.
	 *
	 * Not autoloaded: it is read on one admin screen and by WP-CLI, so it has no business
	 * in every page load's `alloptions` cache. The freshness window does not read it —
	 * `fetched_at` decides that — it exists to be displayed.
	 */
	const LAST_SYNC_OPTION = 'currency_converter_last_sync';

	/**
	 * Option holding the last successful metadata sync, as a Unix timestamp.
	 *
	 * This one *is* load-bearing: unlike rates, metadata has no per-row timestamp worth
	 * comparing, so the weekly window is measured from here.
	 */
	const CURRENCIES_SYNCED_OPTION = 'currency_converter_currencies_synced';

	/**
	 * The API client.
	 *
	 * @var FreeCurrencyApiClient
	 */
	private $client;

	/**
	 * Rate storage.
	 *
	 * @var RateRepositoryInterface
	 */
	private $rates;

	/**
	 * Currency metadata storage.
	 *
	 * @var CurrencyRepositoryInterface
	 */
	private $currencies;

	/**
	 * Constructor.
	 *
	 * Everything is injected, so the whole class is exercised in tests against a fake
	 * transport and a fake `$wpdb` without a network or a database.
	 *
	 * @param FreeCurrencyApiClient       $client     Client for freecurrencyapi.com.
	 * @param RateRepositoryInterface     $rates      Rate storage.
	 * @param CurrencyRepositoryInterface $currencies Currency metadata storage.
	 */
	public function __construct( FreeCurrencyApiClient $client, RateRepositoryInterface $rates, CurrencyRepositoryInterface $currencies ) {
		$this->client     = $client;
		$this->rates      = $rates;
		$this->currencies = $currencies;
	}

	/**
	 * Build an updater wired to the real client and the real tables.
	 *
	 * The one place the concrete `Db\` implementations are named, so everything else —
	 * including this class's own logic — depends on the interfaces.
	 *
	 * @return self Configured updater.
	 */
	public static function from_config() {
		return new self(
			FreeCurrencyApiClient::from_config(),
			new WpdbRateRepository(),
			new WpdbCurrencyRepository()
		);
	}

	/**
	 * Fetch the latest rates and store them.
	 *
	 * @param bool $force Ignore the freshness window. Never ignores the lock.
	 * @return SyncResult What happened.
	 * @throws \Drozd\Currency\Api\ApiException When the API cannot be reached or refuses.
	 * @throws \RuntimeException When the rates cannot be written.
	 */
	public function update_rates( $force = false ) {
		$last = $this->rates->last_fetched_at( Currencies::BASE );

		if ( ! $force && $this->is_within( $last, self::FRESHNESS_WINDOW ) ) {
			return SyncResult::fresh(
				sprintf( 'Skipped: rates are fresh (last sync %s UTC)', $last->format( Rate::DATETIME_FORMAT ) ),
				$last
			);
		}

		if ( ! $this->acquire( self::LOCK_RATES ) ) {
			return SyncResult::locked( 'Skipped: another rate update is already running' );
		}

		try {
			$payload    = $this->client->latest();
			$fetched_at = $this->now();

			$predefined = Currencies::codes();
			$returned   = array_keys( $payload );

			$unknown = array_values( array_diff( $returned, $predefined ) );
			$missing = array_values( array_diff( $predefined, $returned ) );

			$stored = $this->rates->upsert( $this->to_rates( $payload, $predefined, $fetched_at ) );

			// Written after the rows, so a failed write never leaves a timestamp claiming
			// a sync that did not happen.
			update_option( self::LAST_SYNC_OPTION, $fetched_at->format( 'c' ), 'no' );

			// Live data has just overwritten whatever the fixture loader put there, so the
			// flag that says "these are demonstration rates" is no longer true. Cleared here,
			// at the only point anything earns clearing it.
			DemoMode::clear();

			return SyncResult::updated(
				$this->drift_message(
					sprintf( '%d %s updated', $stored, 1 === $stored ? 'rate' : 'rates' ),
					$unknown,
					$missing
				),
				$stored,
				$unknown,
				$missing,
				$fetched_at
			);
		} finally {
			// Released whatever happened above: an API failure must cost one attempt, not
			// sixty seconds of refusing to try again.
			$this->release( self::LOCK_RATES );
		}
	}

	/**
	 * Fetch currency metadata and store it, if it is due.
	 *
	 * Due means: the table is empty, or the last sync was more than a week ago. Metadata is
	 * static — a name or a minor-unit count changes when a country redenominates — so
	 * fetching it daily would spend a second request every day to write identical rows.
	 *
	 * @param bool $force Ignore the weekly window. Never ignores the lock.
	 * @return SyncResult What happened.
	 * @throws \Drozd\Currency\Api\ApiException When the API cannot be reached or refuses.
	 * @throws \RuntimeException When the metadata cannot be written.
	 */
	public function update_currencies( $force = false ) {
		$synced_at = $this->currencies_synced_at();

		if ( ! $force && $this->currencies->count() > 0 && $this->is_within( $synced_at, self::CURRENCIES_WINDOW ) ) {
			return SyncResult::fresh(
				sprintf( 'Skipped: currency metadata is current (last synced %s UTC)', $synced_at->format( Rate::DATETIME_FORMAT ) ),
				$synced_at
			);
		}

		if ( ! $this->acquire( self::LOCK_CURRENCIES ) ) {
			return SyncResult::locked( 'Skipped: another metadata update is already running' );
		}

		try {
			$payload    = $this->client->currencies();
			$synced_at  = $this->now();
			$predefined = Currencies::codes();

			$list     = array();
			$returned = array();

			foreach ( $payload as $code => $meta ) {
				if ( ! Currency::is_valid_code( $code ) ) {
					continue;
				}

				$returned[] = strtoupper( (string) $code );
				// Everything the API describes is stored, not just the predefined codes:
				// this table describes currencies and never decides which exist, and the
				// full set is what `Currencies::CODES` is checked against.
				$list[] = Currency::from_array( is_array( $meta ) ? $meta : array(), $code );
			}

			$unknown = array_values( array_diff( $returned, $predefined ) );
			$missing = array_values( array_diff( $predefined, $returned ) );

			$stored = $this->currencies->save_all( $list );

			update_option( self::CURRENCIES_SYNCED_OPTION, $synced_at->getTimestamp(), 'no' );

			return SyncResult::updated(
				$this->drift_message(
					sprintf( '%d %s updated', $stored, 1 === $stored ? 'currency' : 'currencies' ),
					$unknown,
					$missing
				),
				$stored,
				$unknown,
				$missing,
				$synced_at
			);
		} finally {
			$this->release( self::LOCK_CURRENCIES );
		}
	}

	/**
	 * Turn the decoded payload into rates, keeping only the predefined codes.
	 *
	 * The base's own identity rate is appended whatever the payload contained, so the row
	 * every cross-rate divides by exists even if the response omitted it or the predefined
	 * list was filtered down to something that excludes it. The repository writes it first
	 * and at exactly 1, and drops any duplicate; this is the belt to that pair of braces,
	 * and it costs one object.
	 *
	 * @param array<string, float> $payload    Rates keyed by code, as the client decoded them.
	 * @param array<int, string>   $predefined The codes the module serves.
	 * @param \DateTimeImmutable   $fetched_at When the payload was fetched.
	 * @return array<int, Rate> Rates ready to store.
	 */
	private function to_rates( array $payload, array $predefined, \DateTimeImmutable $fetched_at ) {
		$rates = array();

		foreach ( $payload as $code => $value ) {
			if ( ! in_array( strtoupper( (string) $code ), $predefined, true ) ) {
				continue;
			}

			$rates[] = Rate::from_float( Currencies::BASE, $code, $value, $fetched_at );
		}

		$rates[] = Rate::identity( Currencies::BASE, $fetched_at );

		return $rates;
	}

	/**
	 * Append both directions of drift to a message, if there is any.
	 *
	 * Both directions are reported because they mean opposite things: a code the API
	 * returned that we do not serve is a currency added upstream, and a code we serve that
	 * the API did not return is one withdrawn. Silently storing the intersection would hide
	 * both — the first as a currency nobody knows exists, the second as a rate that quietly
	 * stops being refreshed while the row stays in the table looking current.
	 *
	 * @param string             $message Base message.
	 * @param array<int, string> $unknown Codes returned but not predefined.
	 * @param array<int, string> $missing Codes predefined but not returned.
	 * @return string The message, with drift appended.
	 */
	private function drift_message( $message, array $unknown, array $missing ) {
		if ( array() !== $unknown ) {
			$message .= sprintf(
				'; %d unknown %s from API: %s',
				count( $unknown ),
				1 === count( $unknown ) ? 'code' : 'codes',
				implode( ', ', $unknown )
			);
		}

		if ( array() !== $missing ) {
			$message .= sprintf(
				'; %d predefined %s missing from API: %s',
				count( $missing ),
				1 === count( $missing ) ? 'code' : 'codes',
				implode( ', ', $missing )
			);
		}

		return $message;
	}

	/**
	 * Whether a timestamp is inside a window ending now.
	 *
	 * @param \DateTimeImmutable|null $timestamp The time to test; null is never fresh.
	 * @param int                     $window    Window length in seconds.
	 * @return bool True when the timestamp is recent enough to skip a sync.
	 */
	private function is_within( $timestamp, $window ) {
		if ( ! $timestamp instanceof \DateTimeImmutable ) {
			return false;
		}

		$age = $this->now()->getTimestamp() - $timestamp->getTimestamp();

		// A future timestamp — a clock that was wound back, a row written by a server in
		// another timezone — counts as fresh rather than as infinitely old, so a wrong
		// clock cannot turn into a sync on every single run.
		return $age < $window;
	}

	/**
	 * When the metadata was last synced.
	 *
	 * @return \DateTimeImmutable|null UTC timestamp, or null when it never has been.
	 */
	private function currencies_synced_at() {
		$stored = (int) get_option( self::CURRENCIES_SYNCED_OPTION, 0 );

		if ( $stored <= 0 ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $stored ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Take a lock, if it is free.
	 *
	 * @param string $name Transient name.
	 * @return bool True when this call took the lock.
	 */
	private function acquire( $name ) {
		if ( false !== get_transient( $name ) ) {
			return false;
		}

		set_transient( $name, $this->now()->format( 'c' ), self::LOCK_TIMEOUT );

		return true;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Transient name.
	 * @return void
	 */
	private function release( $name ) {
		delete_transient( $name );
	}

	/**
	 * The current time, in UTC.
	 *
	 * One method, so tests can pin it by overriding nothing more than this.
	 *
	 * @return \DateTimeImmutable Now, UTC.
	 */
	private function now() {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
