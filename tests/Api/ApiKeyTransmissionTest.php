<?php
/**
 * How the API key is transmitted.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Api;

use Brain\Monkey\Functions;
use Drozd\Currency\Api\ApiException;
use Drozd\Currency\Api\FreeCurrencyApiClient;
use Tests\Fakes\FakeHttpClient;
use Tests\Fixture;
use Tests\TestCase;

/**
 * The key travels in the `apikey` request header and never in the URL.
 *
 * This is a requirement, not an implementation detail, and it is tested as one: every
 * assertion below reads the request the client actually handed to the transport, not the
 * client's internals. Rewrite `FreeCurrencyApiClient` however you like — change the URL
 * builder, add an endpoint, swap the transport — and these tests still say whether the
 * requirement holds.
 *
 * The reason it is a requirement is recorded in `docs/DECISIONS.md` D1: a query string is
 * copied verbatim into web server access logs, into proxy and CDN cache keys, into Query
 * Monitor's HTTP panel, and into the exported transcripts this project has to ship. The
 * last one cannot be undone by rotating the key afterwards — the transcript keeps it.
 *
 * A separate file on purpose. When this fails, the failure should name the requirement
 * that broke rather than arriving as one red line among twenty parsing assertions.
 */
final class ApiKeyTransmissionTest extends TestCase {

	/**
	 * A key distinctive enough that any leak into a URL is findable by substring.
	 */
	private const SENTINEL_KEY = 'fca_live_SENTINEL0000000000000000';

	/**
	 * Every endpoint the client can reach, as a callable on it.
	 *
	 * Written as a provider so a new endpoint added to the client without a matching
	 * entry here is a visible omission rather than a silent gap in coverage.
	 *
	 * @return array<string, array{0: string}> Endpoint method names.
	 */
	public static function endpoint_provider(): array {
		return array(
			'/v1/latest'     => array( 'latest' ),
			'/v1/currencies' => array( 'currencies' ),
			'/v1/status'     => array( 'status' ),
		);
	}

	/**
	 * Build a client whose transport answers everything plausibly and records the request.
	 *
	 * @return array{0: FreeCurrencyApiClient, 1: FakeHttpClient} Client and its transport.
	 */
	private function client(): array {
		Functions\when( 'update_option' )->justReturn( true );

		// A body that satisfies latest(), currencies() and status() alike, so the
		// assertions below are about the request and never about response parsing.
		$http = FakeHttpClient::responding(
			200,
			'{"data":{"EUR":0.9},"quotas":{"month":{"remaining":4963}}}',
			array( 'X-RateLimit-Remaining-Quota-Month' => '4963' )
		);

		return array( new FreeCurrencyApiClient( $http, self::SENTINEL_KEY ), $http );
	}

	public function test_the_key_is_sent_as_the_apikey_header(): void {
		list( $client, $http ) = $this->client();

		$client->latest();

		$this->assertArrayHasKey(
			'apikey',
			$http->last_headers(),
			'The API key must be sent in an "apikey" request header.'
		);
		$this->assertSame( self::SENTINEL_KEY, $http->last_headers()['apikey'] );
	}

	public function test_the_key_never_appears_in_the_request_url(): void {
		list( $client, $http ) = $this->client();

		$client->latest();

		$this->assertStringNotContainsString(
			self::SENTINEL_KEY,
			$http->last_url(),
			'The API key leaked into the URL, where access logs and Query Monitor will record it.'
		);
	}

	public function test_no_endpoint_puts_the_key_in_the_url(): void {
		list( $client, $http ) = $this->client();

		// Driven by the provider, so an endpoint added there is covered here for free.
		foreach ( self::endpoint_provider() as $spec ) {
			$method = $spec[0];
			$client->$method();
		}

		$this->assertCount(
			count( self::endpoint_provider() ),
			$http->urls(),
			'Expected one request per endpoint.'
		);

		foreach ( $http->urls() as $url ) {
			$this->assertStringNotContainsString( self::SENTINEL_KEY, $url, "Key leaked into {$url}" );
			$this->assertStringNotContainsString( 'apikey', $url, "Key parameter leaked into {$url}" );
		}
	}

	/**
	 * @dataProvider endpoint_provider
	 *
	 * @param string $method Client method to call.
	 */
	public function test_each_endpoint_sends_a_url_with_no_query_string( string $method ): void {
		list( $client, $http ) = $this->client();

		$client->$method();

		$this->assertStringNotContainsString(
			'?',
			$http->last_url(),
			'The client builds no query string at all, so a key cannot be added to one by accident.'
		);
	}

	public function test_the_key_is_absent_from_a_failure_message(): void {
		Functions\when( 'update_option' )->justReturn( true );

		// The real 401 body, captured live. It is the response most likely to be logged
		// or surfaced in an admin notice, so it is the one worth checking for leakage.
		$http   = FakeHttpClient::responding( 401, Fixture::raw( 'error-401.json' ) );
		$client = new FreeCurrencyApiClient( $http, self::SENTINEL_KEY );

		try {
			$client->latest();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertStringNotContainsString(
				self::SENTINEL_KEY,
				$e->getMessage(),
				'Exception messages reach logs and admin notices; the key must not be in one.'
			);
			$this->assertStringNotContainsString( 'fca_live_', $e->getMessage() );
		}
	}

	public function test_no_request_is_sent_at_all_when_no_key_is_configured(): void {
		$http   = FakeHttpClient::responding( 200, '{"data":{"EUR":0.9}}' );
		$client = new FreeCurrencyApiClient( $http, '' );

		try {
			$client->latest();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 401, $e->status() );
		}

		$this->assertSame(
			0,
			$http->call_count(),
			'An unauthenticated request must not be sent: the API answers identically for a '
				. 'missing key and a wrong one, so failing locally keeps the two distinguishable.'
		);
	}
}
