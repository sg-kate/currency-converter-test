<?php
/**
 * Response parsing and error mapping in the freecurrencyapi client.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Api;

use Brain\Monkey\Functions;
use Drozd\Currency\Api\ApiException;
use Drozd\Currency\Api\AuthenticationException;
use Drozd\Currency\Api\FreeCurrencyApiClient;
use Drozd\Currency\Api\RateLimitException;
use Drozd\Currency\Api\TransportException;
use Drozd\Currency\Api\ValidationException;
use Drozd\Currency\Http\TransportException as HttpTransportException;
use Tests\Fakes\FakeHttpClient;
use Tests\Fixture;
use Tests\TestCase;

/**
 * Every branch of the client, against captured responses and a fake transport.
 *
 * No socket is opened and no quota is spent. The bodies come from `tests/Fixtures/`,
 * captured in one run by `scripts/capture-fixtures.sh`; `PROVENANCE.md` there records
 * which are real captures and which are documented shapes.
 *
 * The rate-limit headers are supplied by the tests rather than read from a fixture,
 * because they are the part that carries the meaning: a 429 body does not say whether
 * the minute or the month was exhausted, and only the headers do.
 */
final class FreeCurrencyApiClientTest extends TestCase {

	private const KEY = 'fca_live_test0000000000000000000';

	/**
	 * Headers as they ride on any response from a request that authenticated.
	 *
	 * @return array<string, string> Rate-limit headers.
	 */
	private function quota_headers(): array {
		return array(
			'X-RateLimit-Limit-Quota-Month'      => '5000',
			'X-RateLimit-Remaining-Quota-Month'  => '4963',
			'X-RateLimit-Limit-Quota-Minute'     => '10',
			'X-RateLimit-Remaining-Quota-Minute' => '9',
		);
	}

	/**
	 * A client answering with the given response.
	 *
	 * @param int                         $status  HTTP status.
	 * @param string                      $body    Response body.
	 * @param array<string, string|array> $headers Response headers.
	 * @return FreeCurrencyApiClient Configured client.
	 */
	private function client_returning( int $status, string $body, array $headers = array() ): FreeCurrencyApiClient {
		Functions\when( 'update_option' )->justReturn( true );

		return new FreeCurrencyApiClient( FakeHttpClient::responding( $status, $body, $headers ), self::KEY );
	}

	// -- Parsing successful responses -------------------------------------------------

	public function test_it_parses_a_successful_latest_response(): void {
		$client = $this->client_returning( 200, Fixture::raw( 'latest.json' ), $this->quota_headers() );

		$rates = $client->latest();

		$this->assertNotEmpty( $rates );
		$this->assertArrayHasKey( 'EUR', $rates );
		$this->assertArrayHasKey( 'USD', $rates );

		// Values arrive as JSON numbers and must stay floats: the column is DECIMAL(24,12)
		// and the narrowing is the database's job, not a cast here.
		$this->assertIsFloat( $rates['EUR'] );
		$this->assertSame( 1.0, $rates['USD'] );

		$expected = Fixture::json( 'latest.json' )['data'];
		$this->assertSame( (float) $expected['EUR'], $rates['EUR'] );
		$this->assertCount( count( $expected ), $rates );
	}

	public function test_latest_keys_rates_by_uppercase_code(): void {
		$client = $this->client_returning( 200, '{"data":{"eur":0.9,"rub":93.0}}', $this->quota_headers() );

		$this->assertSame( array( 'EUR' => 0.9, 'RUB' => 93.0 ), $client->latest() );
	}

	public function test_it_parses_a_successful_currencies_response(): void {
		$client = $this->client_returning( 200, Fixture::raw( 'currencies.json' ), $this->quota_headers() );

		$currencies = $client->currencies();

		$this->assertNotEmpty( $currencies );
		$this->assertArrayHasKey( 'EUR', $currencies );
		$this->assertSame( 'Euro', $currencies['EUR']['name'] );

		// The reason convert() returns an unrounded float: the correct scale is
		// per-currency data, and JPY is 0, not 2.
		$this->assertSame( 0, $currencies['JPY']['decimal_digits'] );
		$this->assertSame( 2, $currencies['EUR']['decimal_digits'] );
	}

	public function test_status_unwraps_quotas_rather_than_data(): void {
		$client = $this->client_returning(
			200,
			'{"account_id":1,"quotas":{"month":{"total":5000,"used":37,"remaining":4963}}}',
			$this->quota_headers()
		);

		$this->assertSame( 4963, $client->status()['month']['remaining'] );
	}

	public function test_a_200_carrying_no_rates_is_a_failure(): void {
		$client = $this->client_returning( 200, '{"data":{}}', $this->quota_headers() );

		// Not an empty array: storing that would overwrite good rates with nothing.
		$this->expectException( ApiException::class );
		$client->latest();
	}

	public function test_a_200_with_an_undecodable_body_is_a_failure(): void {
		$client = $this->client_returning( 200, '<html>maintenance</html>', $this->quota_headers() );

		$this->expectException( ApiException::class );
		$client->latest();
	}

	// -- Error mapping ----------------------------------------------------------------

	public function test_401_maps_to_authentication_exception(): void {
		$client = $this->client_returning( 401, Fixture::raw( 'error-401.json' ) );

		try {
			$client->latest();
			$this->fail( 'Expected an AuthenticationException.' );
		} catch ( AuthenticationException $e ) {
			$this->assertSame( 401, $e->status() );
			$this->assertSame( 'invalid_api_key', $e->api_error_code() );
			$this->assertFalse( $e->is_plan_restriction() );
			$this->assertFalse( $e->is_retryable(), 'Retrying a 401 is how a key gets banned.' );
		}
	}

	public function test_the_401_body_marketing_urls_never_reach_the_message(): void {
		$client = $this->client_returning( 401, Fixture::raw( 'error-401.json' ) );

		// The captured body genuinely carries sign-up and docs URLs; the exception message
		// is built from the status code alone so none of it lands in an admin notice.
		$this->assertStringContainsString( 'utm_source', Fixture::raw( 'error-401.json' ) );

		try {
			$client->latest();
			$this->fail( 'Expected an AuthenticationException.' );
		} catch ( AuthenticationException $e ) {
			$this->assertSame( 'freecurrencyapi returned HTTP 401', $e->getMessage() );
			$this->assertStringNotContainsString( 'http', $e->getMessage() );
		}
	}

	public function test_403_maps_to_authentication_exception_as_a_plan_restriction(): void {
		$client = $this->client_returning(
			403,
			'{"message":"subscription plan does not support this feature"}',
			$this->quota_headers()
		);

		try {
			$client->latest();
			$this->fail( 'Expected an AuthenticationException.' );
		} catch ( AuthenticationException $e ) {
			$this->assertSame( 403, $e->status() );
			$this->assertTrue( $e->is_plan_restriction(), 'A 403 is a design error, not an account problem.' );
		}
	}

	public function test_422_maps_to_validation_exception_naming_the_parameter(): void {
		$client = $this->client_returning(
			422,
			'{"message":"Validation error","errors":{"currencies":["The selected currencies is invalid."]}}',
			$this->quota_headers()
		);

		try {
			$client->latest();
			$this->fail( 'Expected a ValidationException.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( 422, $e->status() );
			$this->assertSame( array( 'currencies' ), $e->invalid_parameters() );
			$this->assertFalse( $e->is_retryable(), 'The identical request cannot succeed.' );
		}
	}

	public function test_429_maps_to_rate_limit_exception_carrying_the_remaining_quota(): void {
		$client = $this->client_returning(
			429,
			Fixture::raw( 'error-429.json' ),
			array(
				'X-RateLimit-Remaining-Quota-Month'  => '0',
				'X-RateLimit-Remaining-Quota-Minute' => '0',
			)
		);

		try {
			$client->latest();
			$this->fail( 'Expected a RateLimitException.' );
		} catch ( RateLimitException $e ) {
			$this->assertSame( 429, $e->status() );
			$this->assertSame( 0, $e->remaining_month() );
			$this->assertTrue( $e->is_monthly_quota() );
			$this->assertFalse( $e->is_retryable(), 'The month does not clear before the month does.' );
		}
	}

	public function test_429_from_the_per_minute_limit_is_not_treated_as_the_monthly_one(): void {
		// Same status, same body. Only the headers distinguish the two, which is the
		// whole reason they are carried on the exception.
		$client = $this->client_returning(
			429,
			Fixture::raw( 'error-429.json' ),
			array(
				'X-RateLimit-Remaining-Quota-Month'  => '4900',
				'X-RateLimit-Remaining-Quota-Minute' => '0',
			)
		);

		try {
			$client->latest();
			$this->fail( 'Expected a RateLimitException.' );
		} catch ( RateLimitException $e ) {
			$this->assertSame( 4900, $e->remaining_month() );
			$this->assertFalse( $e->is_monthly_quota() );
			$this->assertTrue( $e->is_retryable(), 'The per-minute limit clears within the minute.' );
		}
	}

	public function test_an_unexpected_status_maps_to_the_base_exception(): void {
		$client = $this->client_returning( 500, '{"message":"boom"}', $this->quota_headers() );

		try {
			$client->latest();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::class, $e::class );
			$this->assertSame( 500, $e->status() );
		}
	}

	public function test_a_transport_failure_is_rethrown_into_the_api_hierarchy(): void {
		Functions\when( 'update_option' )->justReturn( true );

		$original = new HttpTransportException( 'cURL error 6: Could not resolve host', 'http_request_failed' );
		$client   = new FreeCurrencyApiClient( FakeHttpClient::failing( $original ), self::KEY );

		try {
			$client->latest();
			$this->fail( 'Expected a TransportException.' );
		} catch ( TransportException $e ) {
			$this->assertInstanceOf( ApiException::class, $e, 'One catch must cover a DNS failure too.' );
			$this->assertSame( 'http_request_failed', $e->error_code() );
			$this->assertSame( $original, $e->getPrevious() );
			$this->assertSame( 0, $e->status(), 'There was no response, so there is no status.' );
			$this->assertTrue( $e->is_retryable(), 'The only retryable branch in the hierarchy.' );
		}
	}

	// -- Quota capture ----------------------------------------------------------------

	public function test_the_remaining_monthly_quota_is_captured_from_an_error_response(): void {
		Functions\expect( 'update_option' )
			->once()
			->with(
				FreeCurrencyApiClient::QUOTA_OPTION,
				\Mockery::on(
					static function ( $value ) {
						return is_array( $value ) && 4900 === $value['remaining'] && $value['checked_at'] > 0;
					}
				),
				'no'
			)
			->andReturn( true );

		$client = new FreeCurrencyApiClient(
			FakeHttpClient::responding(
				429,
				Fixture::raw( 'error-429.json' ),
				array( 'X-RateLimit-Remaining-Quota-Month' => '4900' )
			),
			self::KEY
		);

		try {
			$client->latest();
		} catch ( RateLimitException $e ) {
			$this->assertSame( 4900, $e->remaining_month() );
		}
	}

	public function test_the_remaining_monthly_quota_is_captured_from_a_successful_response(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( FreeCurrencyApiClient::QUOTA_OPTION, \Mockery::type( 'array' ), 'no' )
			->andReturn( true );

		$client = new FreeCurrencyApiClient(
			FakeHttpClient::responding( 200, Fixture::raw( 'latest.json' ), $this->quota_headers() ),
			self::KEY
		);

		$this->assertNotEmpty( $client->latest() );
	}

	public function test_a_response_without_quota_headers_writes_nothing(): void {
		// A 401 never reached an account and carries no X-RateLimit-* headers at all.
		// wp_remote_retrieve_header() returns '' there, and (int) '' is 0 — writing that
		// would record an exhausted quota and read as one until the month turned.
		Functions\expect( 'update_option' )->never();

		$client = new FreeCurrencyApiClient(
			FakeHttpClient::responding( 401, Fixture::raw( 'error-401.json' ) ),
			self::KEY
		);

		try {
			$client->latest();
			$this->fail( 'Expected an AuthenticationException.' );
		} catch ( AuthenticationException $e ) {
			$this->assertSame( 401, $e->status() );
		}
	}
}
