<?php
/**
 * Base test case.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

abstract class TestCase extends PolyfillTestCase {

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();

		// The escaping and translation helpers appear in almost every unit under
		// test and never carry logic worth asserting, so they pass their input
		// through. Anything with behaviour must be stubbed by the test itself.
		Functions\stubs(
			array(
				'esc_html',
				'esc_attr',
				'esc_url',
				'esc_textarea',
				'sanitize_text_field',
				'sanitize_key',
				'wp_unslash',
				'__',
				'esc_html__',
				'esc_attr__',
			)
		);
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();

		// Deliberately no Mockery::close() here: Monkey\tearDown() already closes
		// it, and closing twice throws on the second call.
	}
}
