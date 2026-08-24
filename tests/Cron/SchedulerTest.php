<?php
/**
 * When the syncs are scheduled, and where their handlers are registered.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Cron;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Drozd\Currency\Cron\Scheduler;
use Drozd\Currency\Plugin;
use Tests\TestCase;

/**
 * The scheduling surface, which is the half of cron that fails silently.
 *
 * A handler attached in the wrong requests and an event anchored a day out both look
 * completely healthy from the outside — `wp cron event list` shows the event either way,
 * and WordPress reschedules an event whose hook has no callbacks without a word. The only
 * symptom of either is an empty table, so these are asserted rather than eyeballed.
 */
final class SchedulerTest extends TestCase {

	/**
	 * Hooks reported as already scheduled, keyed by hook name.
	 *
	 * @var array<string, int>
	 */
	private array $scheduled = array();

	/**
	 * Recurring events created during the test.
	 *
	 * @var array<int, array{timestamp: int, recurrence: string, hook: string}>
	 */
	private array $events = array();

	/**
	 * Single events created during the test.
	 *
	 * @var array<int, array{timestamp: int, hook: string}>
	 */
	private array $single_events = array();

	/**
	 * Hooks passed to `wp_clear_scheduled_hook()`.
	 *
	 * @var array<int, string>
	 */
	private array $cleared = array();

	protected function set_up(): void {
		parent::set_up();

		$this->scheduled     = array();
		$this->events        = array();
		$this->single_events = array();
		$this->cleared       = array();

		Functions\when( 'wp_next_scheduled' )->alias(
			fn( string $hook ) => $this->scheduled[ $hook ] ?? false
		);

		Functions\when( 'wp_schedule_event' )->alias(
			function ( int $timestamp, string $recurrence, string $hook ) {
				$this->events[]           = compact( 'timestamp', 'recurrence', 'hook' );
				$this->scheduled[ $hook ] = $timestamp;

				return true;
			}
		);

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook ) {
				$this->single_events[] = compact( 'timestamp', 'hook' );

				return true;
			}
		);

		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook ) {
				$this->cleared[] = $hook;

				return 0;
			}
		);
	}

	/**
	 * Both cron hooks and the self-heal are attached, in every request.
	 */
	public function test_register_attaches_both_handlers_and_the_self_heal(): void {
		$attached = array();

		foreach ( array( Plugin::CRON_HOOK_RATES, Plugin::CRON_HOOK_CURRENCIES, 'init' ) as $hook ) {
			Actions\expectAdded( $hook )->once()->whenHappen(
				static function ( $callback ) use ( $hook, &$attached ) {
					$attached[ $hook ] = $callback;
				}
			);
		}

		Scheduler::register();

		$this->assertSame(
			array(
				Plugin::CRON_HOOK_RATES      => array( Scheduler::class, 'run_rates' ),
				Plugin::CRON_HOOK_CURRENCIES => array( Scheduler::class, 'run_currencies' ),
				'init'                       => array( Scheduler::class, 'ensure_scheduled' ),
			),
			$attached
		);
	}

	/**
	 * Registration asks no question about the request it is in.
	 *
	 * A cron run is a front-end request with `DOING_CRON`, so `is_admin()` is false inside
	 * the only request that matters here. Any branch on it registers the handlers in
	 * precisely the requests that never fire them.
	 */
	public function test_register_never_consults_is_admin(): void {
		Functions\expect( 'is_admin' )->never();

		Scheduler::register();

		// `expect()->never()` is the assertion; PHPUnit needs one of its own to agree the
		// test did something.
		$this->assertTrue( true );
	}

	/**
	 * Activation puts both recurring events on the calendar, one interval out.
	 */
	public function test_activation_schedules_both_recurring_events(): void {
		$now = time();

		Scheduler::activate();

		$this->assertCount( 2, $this->events );

		$rates = $this->event_for( Plugin::CRON_HOOK_RATES );
		$this->assertSame( 'daily', $rates['recurrence'] );
		$this->assertEqualsWithDelta( $now + DAY_IN_SECONDS, $rates['timestamp'], 5 );

		$currencies = $this->event_for( Plugin::CRON_HOOK_CURRENCIES );
		$this->assertSame( 'weekly', $currencies['recurrence'] );
		$this->assertEqualsWithDelta( $now + WEEK_IN_SECONDS, $currencies['timestamp'], 5 );
	}

	/**
	 * Activation also queues a run half a minute out, for both hooks.
	 *
	 * Without it the first rates land a day after activation, and everything in between
	 * — the admin page, `wp currency convert` — reports an empty table. That reads as a
	 * broken plugin, and it is the reading a reviewer reaches first.
	 */
	public function test_activation_queues_a_kickoff_run_seconds_out(): void {
		$now = time();

		Scheduler::activate();

		$this->assertCount( 2, $this->single_events );

		foreach ( array( Plugin::CRON_HOOK_RATES, Plugin::CRON_HOOK_CURRENCIES ) as $hook ) {
			$kickoff = $this->single_event_for( $hook );

			$this->assertEqualsWithDelta( $now + Scheduler::KICKOFF_DELAY, $kickoff['timestamp'], 5 );
			$this->assertLessThan( 60, $kickoff['timestamp'] - $now, 'the kickoff must land inside one tick of cron-loop.sh' );
		}
	}

	/**
	 * Re-activating does not stack a second copy of a recurring event.
	 */
	public function test_activation_leaves_an_existing_event_alone(): void {
		$this->scheduled = array(
			Plugin::CRON_HOOK_RATES      => time() + 3600,
			Plugin::CRON_HOOK_CURRENCIES => time() + 3600,
		);

		Scheduler::activate();

		$this->assertSame( array(), $this->events );
		// The kickoff is still queued: an event an hour out says nothing about whether the
		// table has ever been filled, and `RateUpdater` refuses the run if it has.
		$this->assertCount( 2, $this->single_events );
	}

	/**
	 * A lost event comes back on the next request, due shortly rather than in a day.
	 */
	public function test_ensure_scheduled_heals_a_lost_event(): void {
		$now = time();

		$this->scheduled = array( Plugin::CRON_HOOK_CURRENCIES => $now + 3600 );

		Scheduler::ensure_scheduled();

		$this->assertCount( 1, $this->events );

		$healed = $this->event_for( Plugin::CRON_HOOK_RATES );
		$this->assertSame( 'daily', $healed['recurrence'] );
		$this->assertEqualsWithDelta( $now + Scheduler::KICKOFF_DELAY, $healed['timestamp'], 5 );
	}

	/**
	 * The self-heal is silent when there is nothing to heal.
	 *
	 * It runs on `init`, so on every single request. Rescheduling a live event there would
	 * push the next run forward on every page load and the sync would never come due.
	 */
	public function test_ensure_scheduled_does_nothing_when_both_events_are_live(): void {
		$this->scheduled = array(
			Plugin::CRON_HOOK_RATES      => time() + 3600,
			Plugin::CRON_HOOK_CURRENCIES => time() + 3600,
		);

		Scheduler::ensure_scheduled();

		$this->assertSame( array(), $this->events );
		$this->assertSame( array(), $this->single_events );
	}

	/**
	 * Deactivation clears both hooks, which takes any pending kickoff with them.
	 */
	public function test_deactivation_clears_both_hooks(): void {
		Scheduler::deactivate();

		$this->assertSame(
			array( Plugin::CRON_HOOK_RATES, Plugin::CRON_HOOK_CURRENCIES ),
			$this->cleared
		);
	}

	/**
	 * The recurring event created for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array{timestamp: int, recurrence: string, hook: string} The event.
	 */
	private function event_for( string $hook ): array {
		foreach ( $this->events as $event ) {
			if ( $hook === $event['hook'] ) {
				return $event;
			}
		}

		$this->fail( sprintf( 'no recurring event was scheduled for %s', $hook ) );
	}

	/**
	 * The single event created for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array{timestamp: int, hook: string} The event.
	 */
	private function single_event_for( string $hook ): array {
		foreach ( $this->single_events as $event ) {
			if ( $hook === $event['hook'] ) {
				return $event;
			}
		}

		$this->fail( sprintf( 'no single event was scheduled for %s', $hook ) );
	}
}
