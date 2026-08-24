<?php
/**
 * Where the API key is read from, and what may be said about it.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Api;

use Brain\Monkey\Functions;
use Drozd\Currency\Admin\SettingsPage;
use Drozd\Currency\Api\ApiKey;
use Tests\TestCase;

/**
 * The rules that keep a credential out of the places credentials leak from.
 *
 * `FREECURRENCYAPI_KEY` is defined once per PHP process and cannot be redefined, so the
 * bootstrap defines it empty — which is exactly how `config/application.php` leaves it on a
 * checkout with no key in `.env`, and the case worth pinning here: a constant that exists but
 * holds nothing must not count as "the environment supplies the key", or the settings page
 * disables its own field and no key can ever be configured.
 *
 * The other direction — a non-empty constant overriding a stored option — cannot be reached
 * from this process. It is verified against the running stack instead, by starting a
 * container with the variable set; see the note in the delivery summary rather than assuming
 * this file covers it.
 */
final class ApiKeyStorageTest extends TestCase {

	/**
	 * Options as the fake `get_option`/`add_option`/`update_option` see them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * The `$autoload` argument each option was created with.
	 *
	 * @var array<string, mixed>
	 */
	private array $autoload = array();

	/**
	 * Options written through `update_option` rather than `add_option`.
	 *
	 * @var array<int, string>
	 */
	private array $updated = array();

	protected function set_up(): void {
		parent::set_up();

		$this->options  = array();
		$this->autoload = array();
		$this->updated  = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);

		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				unset( $deprecated );

				if ( isset( $this->options[ $name ] ) ) {
					return false;
				}

				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;

				return true;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				$this->updated[]        = $name;

				return true;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * A constant that is defined but empty is not a configured key.
	 */
	public function test_an_empty_constant_is_not_an_environment_key(): void {
		$this->assertSame( '', constant( ApiKey::CONSTANT ), 'the bootstrap is expected to define it empty' );
		$this->assertFalse( ApiKey::is_from_environment() );
		$this->assertFalse( ApiKey::is_configured() );
	}

	/**
	 * With no constant to speak of, the stored option is the key.
	 */
	public function test_the_stored_option_is_used_when_the_environment_is_empty(): void {
		ApiKey::store( 'fca_live_STOREDKEY_0123456789' );

		$this->assertSame( 'fca_live_STOREDKEY_0123456789', ApiKey::get() );
		$this->assertTrue( ApiKey::is_configured() );
		$this->assertTrue( ApiKey::is_stored() );
		$this->assertFalse( ApiKey::is_from_environment() );
	}

	/**
	 * The option is created with `autoload='no'`, which is the whole point of pre-creating it.
	 *
	 * An autoloaded option is read into `alloptions` on every request and cached there. The
	 * Settings API saves through `update_option()` with no autoload argument, so if the
	 * option does not already exist it is created with WordPress's default — autoloaded.
	 */
	public function test_the_key_option_is_never_autoloaded(): void {
		ApiKey::ensure_option_exists();

		$this->assertArrayHasKey( ApiKey::OPTION, $this->autoload );
		$this->assertSame( 'no', $this->autoload[ ApiKey::OPTION ] );
	}

	/**
	 * Storing a key does not re-create the option, so its autoload setting survives.
	 */
	public function test_storing_a_key_writes_through_update_and_keeps_the_autoload_setting(): void {
		ApiKey::ensure_option_exists();
		ApiKey::store( 'fca_live_STOREDKEY_0123456789' );

		$this->assertSame( 'no', $this->autoload[ ApiKey::OPTION ] );
		$this->assertContains( ApiKey::OPTION, $this->updated );
	}

	/**
	 * Pre-creating the option survives a sanitiser that calls back into it.
	 *
	 * This is the shape that took the settings screen down with a 500. WordPress runs
	 * `sanitize_option()` at the *top* of `add_option()`, before the row-exists check, and
	 * this option's sanitiser — `SettingsPage::sanitize_api_key()` — calls
	 * `ensure_option_exists()`. With the row absent, each call re-entered `add_option()` and
	 * the request died on exhausted memory. An administrator reaches it by deleting the
	 * stored key and then saving a new one.
	 *
	 * The fakes in `set_up()` cannot see it: their `add_option` writes to an array and runs no
	 * filters, so the loop does not exist there. This test supplies an `add_option` that
	 * behaves like the real one — sanitise first, then check — and bounds the nesting so a
	 * regression fails as an assertion instead of taking the suite down with it.
	 */
	public function test_pre_creating_the_option_does_not_recurse_through_the_sanitiser(): void {
		$depth = 0;
		$max   = 0;

		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = 'yes' ) use ( &$depth, &$max ) {
				unset( $deprecated );

				++$depth;
				$max = max( $max, $depth );

				if ( $depth > 5 ) {
					--$depth;

					throw new \RuntimeException( 'add_option() recursed; ensure_option_exists() is re-entering itself' );
				}

				// Core's order, and the whole reason the bug existed: the sanitiser runs
				// before the row-exists check below.
				$value = SettingsPage::sanitize_api_key( $value );

				if ( isset( $this->options[ $name ] ) ) {
					--$depth;

					return false;
				}

				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;

				--$depth;

				return true;
			}
		);

		ApiKey::ensure_option_exists();

		$this->assertSame( 1, $max, 'add_option() should be entered exactly once' );
		$this->assertArrayHasKey( ApiKey::OPTION, $this->options );
		$this->assertSame( 'no', $this->autoload[ ApiKey::OPTION ], 'the autoload guarantee must survive the fix' );
	}

	/**
	 * A key saved while the option row is absent is stored, and stored un-autoloaded.
	 *
	 * The end-to-end version of the case above: "Delete stored key" removes the row, then the
	 * next save goes through the sanitiser with nothing in the database. Before the guard this
	 * never returned.
	 */
	public function test_a_key_saves_when_the_option_row_does_not_exist(): void {
		Functions\when( 'add_settings_error' )->justReturn( true );

		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				unset( $deprecated );

				$value = SettingsPage::sanitize_api_key( $value );

				if ( isset( $this->options[ $name ] ) ) {
					return false;
				}

				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;

				return true;
			}
		);

		$this->assertArrayNotHasKey( ApiKey::OPTION, $this->options, 'precondition: no row' );

		$stored = SettingsPage::sanitize_api_key( 'fca_live_SAVEDAFTERDELETE_01234' );

		$this->assertSame( 'fca_live_SAVEDAFTERDELETE_01234', $stored );
		$this->assertSame( 'no', $this->autoload[ ApiKey::OPTION ] );
	}

	/**
	 * The setting is registered without a `default`, and must stay that way.
	 *
	 * `register_setting( … 'default' => '' )` registers a `default_option_…` filter, and
	 * `update_option()` compares its result against the current value:
	 *
	 *     if ( apply_filters( "default_option_{$option}", … ) === $old_value ) {
	 *         return add_option( $option, $value, '', $autoload );
	 *     }
	 *
	 * The option is pre-created empty to pin `autoload='no'`, so `$old_value` is `''` — equal
	 * to that default. Core then saved through `add_option()`, which reset the autoload column
	 * and put the key into `alloptions` on every page load. Verified against the running stack:
	 * with the default registered the row came back `autoload=auto`, without it `autoload=off`.
	 *
	 * This is a unit test of the registration arguments, not of core's behaviour — it exists so
	 * that adding a seemingly harmless `'default' => ''` fails here rather than silently
	 * autoloading a credential.
	 */
	public function test_the_setting_is_registered_without_a_default(): void {
		$args = null;

		Functions\when( 'register_setting' )->alias(
			function ( $group, $option, $options = array() ) use ( &$args ) {
				unset( $group );

				if ( ApiKey::OPTION === $option ) {
					$args = $options;
				}

				return true;
			}
		);
		Functions\when( 'add_settings_section' )->justReturn( true );
		Functions\when( 'add_settings_field' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );

		SettingsPage::register_settings();

		$this->assertIsArray( $args );
		$this->assertArrayNotHasKey( 'default', $args, 'a registered default reroutes the save through add_option() and autoloads the key' );
		$this->assertFalse( $args['show_in_rest'], 'the key must never be REST-readable' );
	}

	/**
	 * Deleting removes it entirely.
	 */
	public function test_forget_deletes_the_option(): void {
		ApiKey::store( 'fca_live_STOREDKEY_0123456789' );
		ApiKey::forget();

		$this->assertSame( '', ApiKey::get() );
		$this->assertFalse( ApiKey::is_stored() );
	}

	/**
	 * The hint reveals the last four characters and nothing else.
	 *
	 * Not the prefix: `fca_live_` is the same on every key the plan issues, so showing it
	 * identifies nothing while making the visible tail look longer than it is.
	 */
	public function test_the_hint_reveals_only_the_last_four_characters(): void {
		ApiKey::store( 'fca_live_SECRETVALUE_abcd' );

		$hint = ApiKey::hint();

		$this->assertStringEndsWith( 'abcd', $hint );
		$this->assertStringNotContainsString( 'SECRETVALUE', $hint );
		$this->assertStringNotContainsString( 'fca_live_', $hint );
		$this->assertSame( 4, strlen( preg_replace( '/[^A-Za-z0-9_-]/', '', $hint ) ) );
	}

	/**
	 * Something too short to be a key gets no hint at all.
	 *
	 * Four characters of a six-character string is not a hint, it is most of the value.
	 */
	public function test_a_short_key_gets_no_hint(): void {
		$this->options[ ApiKey::OPTION ] = 'abc123';

		$this->assertSame( '', ApiKey::hint() );
	}

	/**
	 * What counts as key-shaped.
	 *
	 * @dataProvider provide_candidate_keys
	 *
	 * @param string $candidate The string to test.
	 * @param bool   $expected  Whether it should be accepted.
	 */
	public function test_key_shape_is_checked( string $candidate, bool $expected ): void {
		$this->assertSame( $expected, ApiKey::is_well_formed( $candidate ) );
	}

	/**
	 * Candidate keys and whether they are well formed.
	 *
	 * @return array<string, array{0: string, 1: bool}> Test cases.
	 */
	public static function provide_candidate_keys(): array {
		return array(
			'a live key'            => array( 'fca_live_abcdefghijklmnopqrstuvwxyz0123', true ),
			'hyphens are allowed'   => array( 'fca-live-abcdefghijklmnop', true ),
			'empty'                 => array( '', false ),
			'too short'             => array( 'fca_live_abc', false ),
			'a pasted fragment'     => array( 'fca_live_abcdefgh…', false ),
			'whitespace inside'     => array( 'fca_live_abcdefgh 12345678', false ),
			'a whole curl command'  => array( 'curl -H "apikey: fca_live_abcdefghijkl"', false ),
			'a newline on the end'  => array( "fca_live_abcdefghijklmnop\n", false ),
		);
	}
}
