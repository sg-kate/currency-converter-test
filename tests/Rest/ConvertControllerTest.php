<?php
/**
 * What the public REST endpoint accepts before it converts anything.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Rest;

use Brain\Monkey\Functions;
use Drozd\Currency\Rest\ConvertController;
use Tests\TestCase;

/**
 * The validation callbacks, which are the endpoint's whole security posture.
 *
 * The route is reachable by anonymous visitors — deliberately, because it backs a block
 * they read — so everything that reaches `convert()` has to have been checked first. These
 * run before the callback does, which is what keeps a malformed request a 400 with a reason
 * rather than an exception escaping into a 500.
 *
 * The arithmetic is not retested here; `CurrencyConverterTest` owns that. What is tested is
 * the boundary: exactly the values `convert()` accepts get through, and nothing else does.
 */
final class ConvertControllerTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		// `Currencies::codes()` runs its list through a filter. Unfiltered here: this test
		// is about the validators, not about somebody else's filter.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * @dataProvider valid_amounts
	 */
	public function test_it_accepts_the_amounts_convert_accepts( string $amount ): void {
		$this->assertTrue( ConvertController::validate_amount( $amount ), $amount );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function valid_amounts(): array {
		return array(
			'integer'          => array( '123' ),
			'decimal'          => array( '123.45' ),
			'negative refund'  => array( '-99.99' ),
			'explicit plus'    => array( '+10' ),
			'zero'             => array( '0' ),
			'many places'      => array( '1.123456789012' ),
			'surrounded by ws' => array( '  123.45  ' ),
		);
	}

	/**
	 * @dataProvider invalid_amounts
	 */
	public function test_it_refuses_everything_else( string $amount ): void {
		$this->assertFalse( ConvertController::validate_amount( $amount ), $amount );
	}

	/**
	 * The exponent and hex cases are the ones a permissive `is_numeric()` would let past,
	 * and `bcmath` does not read either.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function invalid_amounts(): array {
		return array(
			'empty'        => array( '' ),
			'exponent'     => array( '1e5' ),
			'hex'          => array( '0x1A' ),
			'words'        => array( 'one hundred' ),
			'comma groups' => array( '1,234.56' ),
			'sql'          => array( "1' OR 1=1--" ),
			'markup'       => array( '<script>alert(1)</script>' ),
			'two dots'     => array( '1.2.3' ),
			'trailing dot' => array( '12.' ),
		);
	}

	public function test_a_non_scalar_amount_is_refused_rather_than_coerced(): void {
		$this->assertFalse( ConvertController::validate_amount( array( '123' ) ) );
		$this->assertFalse( ConvertController::validate_amount( null ) );
	}

	public function test_it_accepts_codes_the_module_serves(): void {
		$this->assertTrue( ConvertController::validate_code( 'USD' ) );
		$this->assertTrue( ConvertController::validate_code( 'RUB' ) );
		$this->assertTrue( ConvertController::validate_code( 'jpy' ), 'case is normalised' );
	}

	/**
	 * Well-formed but not served is still a refusal — the two failures stay apart
	 * everywhere else in the module and they stay apart here.
	 */
	public function test_a_well_formed_but_unserved_code_is_refused(): void {
		$this->assertFalse( ConvertController::validate_code( 'ZZZ' ) );
	}

	/**
	 * A reader is told the conversion failed; nobody else's business is disclosed.
	 *
	 * The route is public and `view.js` prints the reply into the page. The module's own
	 * messages are written for an administrator — `RatesUnavailableException::nothing_stored()`
	 * names a WP-CLI command and the state of the daily sync, `UnknownCurrencyException`
	 * lists every served currency. Passing those through put internal detail in front of an
	 * anonymous visitor who could do nothing with it, which is the policy `Shortcode` had
	 * already settled the other way.
	 */
	public function test_an_anonymous_caller_gets_a_generic_refusal(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$detail = 'No exchange rates are stored for base USD. The daily sync has not completed '
			. 'successfully yet; run `wp currency rates update --force`.';

		$message = ConvertController::client_message( $detail );

		$this->assertSame( 'That conversion is not available right now.', $message );
		$this->assertStringNotContainsString( 'wp currency', $message );
		$this->assertStringNotContainsString( 'sync', $message );
	}

	/**
	 * Somebody who can fix it still gets told what to fix.
	 */
	public function test_an_administrator_gets_the_real_message(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$detail = 'No exchange rates are stored for base USD.';

		$this->assertSame( $detail, ConvertController::client_message( $detail ) );
	}

	/**
	 * @dataProvider malformed_codes
	 */
	public function test_a_malformed_code_is_refused_without_throwing( $code ): void {
		$this->assertFalse( ConvertController::validate_code( $code ) );
	}

	/**
	 * `validate_code()` must never throw: a validation callback that raises turns a bad
	 * request into a 500, which is exactly what it exists to prevent.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function malformed_codes(): array {
		return array(
			'too short'  => array( 'US' ),
			'too long'   => array( 'USDD' ),
			'digits'     => array( 'U5D' ),
			'sql'        => array( "RUB' OR 1=1--" ),
			'empty'      => array( '' ),
			'null'       => array( null ),
			'array'      => array( array( 'USD' ) ),
			'whitespace' => array( '   ' ),
		);
	}
}
