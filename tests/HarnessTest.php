<?php
/**
 * Proves the test harness itself works, before there is anything to test with it.
 *
 * If these fail, the problem is the toolchain — autoload order, Patchwork, the
 * polyfills — not the code under test.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests;

use Brain\Monkey\Functions;

final class HarnessTest extends TestCase {

	public function test_undefined_wordpress_functions_can_be_defined(): void {
		Functions\when( 'get_option' )->justReturn( 'stubbed' );

		$this->assertSame( 'stubbed', get_option( 'anything' ) );
	}

	public function test_expectations_on_wordpress_functions_are_verified(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', 'some_callback' );

		add_action( 'init', 'some_callback' );

		// Mockery verifies the expectation during tear-down, which PHPUnit never
		// sees — so a test whose only check is an expectation counts as risky and,
		// with failOnRisky, fails. Counting it here keeps the signal honest.
		$this->addToAssertionCount( 1 );
	}

	public function test_passthrough_stubs_are_available(): void {
		$this->assertSame( '<b>', esc_html( '<b>' ) );
		$this->assertSame( 'Untranslated', __( 'Untranslated', 'test' ) );
	}

	public function test_bcmath_is_available_to_the_test_runner(): void {
		// The converter depends on bcmath. The runner and the web container are
		// the same image, so this failing means the site cannot do exact maths
		// either — see the extension-parity trap in the wp-stack skill.
		$this->assertTrue( function_exists( 'bcdiv' ), 'bcmath is missing from the app image' );
	}
}
