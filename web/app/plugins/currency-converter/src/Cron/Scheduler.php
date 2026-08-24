<?php
/**
 * Scheduling and execution of the two background syncs.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Cron;

use Drozd\Currency\Plugin;
use Drozd\Currency\Service\RateUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Owns everything about when the syncs run, and nothing about what they do.
 *
 * `RateUpdater` decides whether a sync is worth making — the freshness window and the
 * lock live there. This class decides only when to ask it, which is the part WordPress
 * gets to have an opinion about. The split is what makes the daily bound in R5 hold even
 * if the scheduling is wrong: a misconfigured `CRON_INTERVAL`, a second `cron` container
 * or somebody running `wp cron event run` in a loop all end up asking `RateUpdater` more
 * often, and it answers "fresh" without spending quota.
 *
 * **Handlers are registered unconditionally.** A cron run is a front-end request with
 * `DOING_CRON` set — `is_admin()` is false inside it — so a handler registered behind
 * `is_admin()` is registered in exactly the requests that never fire it, and the event
 * runs against no callback at all. WordPress does not complain about that: it reschedules
 * the event and moves on, so the symptom is an empty table and a perfectly healthy-looking
 * `wp cron event list`. Registration here is three unguarded `add_action()` calls.
 *
 * **The one-off at activation is not a nicety.** `wp_schedule_event()` on `daily` anchored
 * a day out means the first rates appear tomorrow; between activation and then the admin
 * page shows an empty table, which reads as a broken plugin rather than as a scheduled one.
 * So activation also queues a single event {@see self::KICKOFF_DELAY} seconds out, and the
 * table fills within a minute — `cron-loop.sh` asks for due events every `CRON_INTERVAL`
 * seconds, 60 by default.
 *
 * **Events are healed on `init`.** Scheduled events live in the `cron` option, so they are
 * lost to a database restore from before the plugin was activated, to a migration that
 * drops options, and to anyone who runs `wp cron event delete`. None of those re-runs
 * activation. Checking `wp_next_scheduled()` once a request is cheap — the option is
 * autoloaded and already in memory — and it is the only thing that gets the schedule back
 * without a deactivate/reactivate cycle.
 */
final class Scheduler {

	/**
	 * Recurrence for the rate sync. R5: once a day, and bounded at once a day.
	 */
	const RATES_RECURRENCE = 'daily';

	/**
	 * Recurrence for the metadata sync.
	 *
	 * Names, symbols and minor units change when a country redenominates. `weekly` is a
	 * core schedule since WordPress 5.4 and the plugin requires 6.0, so it needs no
	 * `cron_schedules` filter of its own.
	 */
	const CURRENCIES_RECURRENCE = 'weekly';

	/**
	 * How far out the activation kickoff and any healed event are scheduled, in seconds.
	 *
	 * Far enough that activation's own writes — `Schema::install()` runs in the same
	 * request — are committed before anything reads the tables, near enough that the first
	 * run lands inside one tick of `cron-loop.sh` rather than one day.
	 */
	const KICKOFF_DELAY = 30;

	/**
	 * Register the hooks. Called on every request, from `Plugin::boot()`.
	 *
	 * @return void
	 */
	public static function register() {
		// Unconditional, deliberately: see the class docblock. A cron run is a front-end
		// request, so `is_admin()` around either of these would mean the events fire into
		// nothing.
		add_action( Plugin::CRON_HOOK_RATES, array( self::class, 'run_rates' ) );
		add_action( Plugin::CRON_HOOK_CURRENCIES, array( self::class, 'run_currencies' ) );

		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Activation: put both recurring events on the calendar, and seed the tables now.
	 *
	 * The recurring events are anchored a full interval out, so the steady state is one
	 * rate sync a day at the time the plugin was activated. The kickoff events are what
	 * fill the tables in the first minute.
	 *
	 * @return void
	 */
	public static function activate() {
		self::schedule( Plugin::CRON_HOOK_RATES, self::RATES_RECURRENCE, time() + DAY_IN_SECONDS );
		self::schedule( Plugin::CRON_HOOK_CURRENCIES, self::CURRENCIES_RECURRENCE, time() + WEEK_IN_SECONDS );

		// Both, not just the rates: an admin page listing 33 rates against no names and no
		// symbols looks as unfinished as an empty one.
		self::kickoff( Plugin::CRON_HOOK_RATES );
		self::kickoff( Plugin::CRON_HOOK_CURRENCIES );
	}

	/**
	 * Deactivation: clear both hooks, including any kickoff still pending.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Plugin::CRON_HOOK_RATES );
		wp_clear_scheduled_hook( Plugin::CRON_HOOK_CURRENCIES );
	}

	/**
	 * Re-create either event if it has gone missing. Runs on `init`.
	 *
	 * A healed event is scheduled {@see self::KICKOFF_DELAY} seconds out rather than a full
	 * interval out, because an event that vanished has probably been missing for a while
	 * and the stored data is the thing that matters, not the calendar. That costs nothing
	 * when the data is in fact current: `RateUpdater` answers "fresh" and makes no request.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		self::schedule( Plugin::CRON_HOOK_RATES, self::RATES_RECURRENCE, time() + self::KICKOFF_DELAY );
		self::schedule( Plugin::CRON_HOOK_CURRENCIES, self::CURRENCIES_RECURRENCE, time() + self::KICKOFF_DELAY );
	}

	/**
	 * Run the rate sync. The callback for `currency_converter_update_rates`.
	 *
	 * @return void
	 */
	public static function run_rates() {
		self::run(
			Plugin::CRON_HOOK_RATES,
			static function () {
				return RateUpdater::from_config()->update_rates();
			}
		);
	}

	/**
	 * Run the metadata sync. The callback for `currency_converter_update_currencies`.
	 *
	 * @return void
	 */
	public static function run_currencies() {
		self::run(
			Plugin::CRON_HOOK_CURRENCIES,
			static function () {
				return RateUpdater::from_config()->update_currencies();
			}
		);
	}

	/**
	 * Schedule a recurring event, unless it is already scheduled.
	 *
	 * @param string $hook       Hook name.
	 * @param string $recurrence One of the registered schedules.
	 * @param int    $timestamp  When the first run is due, Unix time UTC.
	 * @return bool True when this call scheduled the event.
	 */
	private static function schedule( $hook, $recurrence, $timestamp ) {
		if ( wp_next_scheduled( $hook ) ) {
			return false;
		}

		if ( false === wp_schedule_event( $timestamp, $recurrence, $hook ) ) {
			// Only reachable through a `pre_schedule_event` or `schedule_event` filter that
			// refuses, which is rare and entirely silent otherwise — and it leaves the
			// module doing nothing at all, so it is worth a line in the log every time.
			self::log( sprintf( 'failed to schedule %s (%s)', $hook, $recurrence ) );

			return false;
		}

		return true;
	}

	/**
	 * Queue a single run shortly from now.
	 *
	 * Not guarded by `wp_next_scheduled()`: the recurring event is a day away and this one
	 * is thirty seconds away, so "already scheduled" says nothing useful about it.
	 * `wp_schedule_single_event()` does its own de-duplication against identical events
	 * within ten minutes, which is the case that actually matters — activating twice in a
	 * row queues one kickoff, not two.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private static function kickoff( $hook ) {
		if ( false === wp_schedule_single_event( time() + self::KICKOFF_DELAY, $hook ) ) {
			self::log( sprintf( 'failed to schedule the initial run of %s', $hook ) );
		}
	}

	/**
	 * Run one sync and report what happened, whatever happened.
	 *
	 * The callback throws on anything it cannot do — an unreachable API, a rejected key, a
	 * failed write — and this is a cron run, so there is nobody to hand the exception to.
	 * Catching it here means the log carries the reason and the other event still runs;
	 * letting it escape means WP-CLI reports a fatal for the whole `--due-now` sweep and
	 * whatever was scheduled behind it is skipped.
	 *
	 * Caught and logged is the end of it. Nothing is retried, nothing falls back to stale
	 * rates presented as current, and nothing writes a "synced" timestamp for a sync that
	 * did not happen — the next daily run is the retry.
	 *
	 * @param string   $hook Hook being run, for the log line.
	 * @param callable $sync Returns a `SyncResult`, or throws.
	 * @return void
	 */
	private static function run( $hook, callable $sync ) {
		try {
			$result = $sync();

			// One line per run, unconditionally: this is a daily job whose whole output is
			// this line, and `docker compose logs cron` is where anyone looks first when
			// the table is not what they expect. A skip is as informative as an update.
			self::log( sprintf( '%s: %s', $hook, $result->message() ) );
		} catch ( \Throwable $e ) {
			self::log( sprintf( '%s failed: %s', $hook, $e->getMessage() ) );
		}
	}

	/**
	 * Write one line where somebody will actually read it.
	 *
	 * Two destinations, because in this stack they are two different places and each one is
	 * missing the other's audience:
	 *
	 * - `error_log()` is the durable record. It is *not* the container's stderr on this
	 *   site — `WP_DEBUG_LOG` is true in development, so WordPress points PHP's error log at
	 *   `web/app/debug.log` and everything written here goes to the file instead.
	 * - WP-CLI's own output *is* the container's stdout, which is what
	 *   `docker compose logs cron` shows. The `cron` container is this stack's scheduler, so
	 *   that is the first place anyone looks when the table is not what they expect, and a
	 *   run that says nothing there looks like a run that did nothing.
	 *
	 * @param string $message The message, without a prefix.
	 * @return void
	 */
	private static function log( $message ) {
		$line = 'currency-converter: ' . $message;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $line );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
