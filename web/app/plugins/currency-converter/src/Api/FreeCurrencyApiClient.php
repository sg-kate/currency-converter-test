<?php
/**
 * Hand-written client for freecurrencyapi.com.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Http\HttpClientInterface;
use Drozd\Currency\Http\HttpResponse;
use Drozd\Currency\Http\TransportException as HttpTransportException;
use Drozd\Currency\Http\WpHttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to freecurrencyapi.com and turns its answers into PHP values or exceptions.
 *
 * Written by hand. Client libraries for this API are forbidden by the brief — see
 * `.claude/agents/_TASK_CONTRACT.md` R8, which names the package so this file does not
 * have to: the delivery gate greps `web/app/plugins/` for that name, and a mention here
 * would fail it. The plugin also ships as a zip onto sites with no `vendor/` directory,
 * so it carries no runtime dependency of any kind.
 *
 * It makes no requests itself: everything goes through `HttpClientInterface`, so a test
 * substitutes a fake and exercises every branch below — including all four error
 * mappings — without opening a socket or spending quota.
 *
 * Requests carry no query parameters at all. `/latest` is deliberately called bare: no
 * `currencies` filter, which is what "all available currencies" means on this plan, and
 * no `base_currency`, which the free plan refuses with a 403. There is no plumbing here
 * for adding either, so neither can be added by accident.
 */
final class FreeCurrencyApiClient {

	/**
	 * API root. No trailing slash; paths below start with one.
	 */
	const BASE_URL = 'https://api.freecurrencyapi.com/v1';

	/**
	 * The header the API key travels in.
	 *
	 * A header, never a query parameter. See `docs/DECISIONS.md`.
	 */
	const API_KEY_HEADER = 'apikey';

	/**
	 * Option holding the last observed monthly quota. Not autoloaded: read on one admin
	 * screen, so it has no business in every page load's `alloptions` cache.
	 */
	const QUOTA_OPTION = 'currency_converter_quota';

	/**
	 * Response header carrying the monthly quota remainder.
	 */
	const HEADER_REMAINING_MONTH = 'x-ratelimit-remaining-quota-month';

	/**
	 * Response header carrying the monthly allowance.
	 */
	const HEADER_LIMIT_MONTH = 'x-ratelimit-limit-quota-month';

	/**
	 * Response header carrying the per-minute remainder.
	 */
	const HEADER_REMAINING_MINUTE = 'x-ratelimit-remaining-quota-minute';

	/**
	 * The transport. The module's only route to the network.
	 *
	 * @var HttpClientInterface
	 */
	private $http;

	/**
	 * The API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * The key is injected rather than read from the constant here, so a test can build a
	 * client without defining anything global.
	 *
	 * @param HttpClientInterface $http    Transport to send requests through.
	 * @param string              $api_key The freecurrencyapi key.
	 */
	public function __construct( HttpClientInterface $http, $api_key ) {
		$this->http    = $http;
		$this->api_key = (string) $api_key;
	}

	/**
	 * Build a client from the project's configuration.
	 *
	 * `FREECURRENCYAPI_KEY` is defined by `config/application.php` through
	 * `Roots\WPConfig\Config::apply()`, which means `wp config get` cannot see it — check
	 * it with `bin/wp eval` instead. An undefined constant yields an empty key here, and
	 * `request()` refuses to send an unauthenticated request rather than earning a 401.
	 *
	 * @param HttpClientInterface|null $http Transport override, for tests. Defaults to the real one.
	 * @return self Configured client.
	 */
	public static function from_config( ?HttpClientInterface $http = null ) {
		return new self(
			$http instanceof HttpClientInterface ? $http : new WpHttpClient(),
			// The environment first, an administrator-supplied option second. See `ApiKey`.
			ApiKey::get()
		);
	}

	/**
	 * Current exchange rates for every currency the plan serves.
	 *
	 * One request, no filter. The base is USD and is *not* echoed back in the response,
	 * so the caller writes `base_code` from its own configuration rather than the payload.
	 *
	 * @return array<string, float> Rates keyed by upper-case currency code.
	 * @throws ApiException When the request fails or returns no usable rates.
	 */
	public function latest() {
		$decoded = $this->request( '/latest' );
		$data    = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		$rates = array();

		foreach ( $data as $code => $rate ) {
			// The key is checked as well as the value, which is what `update_currencies()`
			// has always done and this did not. The asymmetry mattered: an unrecognised code
			// survives into `RateUpdater`'s drift report, and that message is handed to
			// `add_settings_error()`, which WordPress renders into the admin notice *without
			// escaping it* — core escapes `code` and `type` there and not `message`. So a key
			// like `<img src=x onerror=…>` in a `/latest` payload became markup on the
			// settings screen. Reaching that needs a hostile upstream — the host is a `const`
			// and TLS is verified — so this is the loose end tidied, not a live hole.
			if ( ! Currency::is_valid_code( $code ) ) {
				continue;
			}

			// Numeric strings included: JSON gives floats, but a value we cannot read as
			// a number is dropped rather than silently cast to 0.0.
			if ( is_numeric( $rate ) ) {
				$rates[ strtoupper( (string) $code ) ] = (float) $rate;
			}
		}

		if ( array() === $rates ) {
			// A 200 carrying `{"data":{}}` is a failure, not a day with no rates. Storing
			// the empty result would overwrite good rates with nothing.
			throw new ApiException(
				'freecurrencyapi returned HTTP 200 with no usable rates',
				200
			);
		}

		return $rates;
	}

	/**
	 * Display metadata for the supported currencies.
	 *
	 * Call this when seeding the currency list, not on every sync: the data changes
	 * essentially never, and each successful call spends quota the daily sync needs.
	 *
	 * @return array<string, array<string, mixed>> Metadata keyed by currency code.
	 * @throws ApiException When the request fails or returns no metadata.
	 */
	public function currencies() {
		$decoded = $this->request( '/currencies' );
		$data    = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		if ( array() === $data ) {
			throw new ApiException(
				'freecurrencyapi returned HTTP 200 with no currency metadata',
				200
			);
		}

		return $data;
	}

	/**
	 * Account quota, as the API reports it.
	 *
	 * Note the shape difference from the other two endpoints: this one answers with
	 * `quotas`, not `data`. A client that unwraps `data` unconditionally reads null here.
	 *
	 * A successful call spends quota like any other, so this is for deliberate checks,
	 * not for a health probe on a schedule.
	 *
	 * @return array<string, mixed> The `quotas` object: `month` and `grace` buckets.
	 * @throws ApiException When the request fails or returns no quota block.
	 */
	public function status() {
		$decoded = $this->request( '/status' );

		if ( ! isset( $decoded['quotas'] ) || ! is_array( $decoded['quotas'] ) ) {
			throw new ApiException(
				'freecurrencyapi returned HTTP 200 with no quota information',
				200
			);
		}

		return $decoded['quotas'];
	}

	/**
	 * The last quota reading the client observed.
	 *
	 * For the settings page. Returns null when no authenticated response has been seen
	 * yet — which is not the same as a quota of zero, and must not be rendered as one.
	 *
	 * @return array{remaining:int, limit:int|null, checked_at:int}|null Last reading, or null.
	 */
	public static function stored_quota() {
		$stored = get_option( self::QUOTA_OPTION );

		return is_array( $stored ) && isset( $stored['remaining'] ) ? $stored : null;
	}

	/**
	 * Send a request and return the decoded body.
	 *
	 * @param string $path Path below `BASE_URL`, starting with a slash.
	 * @return array<string, mixed> Decoded response body.
	 * @throws AuthenticationException When no key is configured, before anything is sent.
	 * @throws TransportException When the request produced no HTTP response at all.
	 * @throws ApiException When the API returns an error status, or the body is not JSON.
	 *                      `map_error()` raises the more specific subclasses.
	 */
	private function request( $path ) {
		if ( '' === $this->api_key ) {
			// Refuse locally rather than spending a round trip to be told the same thing.
			// The API answers identically for a missing key and a wrong one, so failing
			// here is what keeps the two distinguishable in our own logs.
			throw new AuthenticationException(
				'No freecurrencyapi key is configured; refusing to send an unauthenticated request',
				401
			);
		}

		try {
			$response = $this->http->get(
				self::BASE_URL . $path,
				array( self::API_KEY_HEADER => $this->api_key )
			);
		} catch ( HttpTransportException $e ) {
			// Re-thrown into this hierarchy so one `catch ( ApiException $e )` around a
			// sync covers a DNS failure too. The original is kept as the previous throwable.
			throw new TransportException( $e->getMessage(), $e->error_code(), $e );
		}

		if ( ! $response->is_success() ) {
			$this->map_error( $response );
		}

		$this->record_remaining_quota( $response );

		$decoded = json_decode( $response->body(), true );

		if ( ! is_array( $decoded ) ) {
			throw new ApiException(
				sprintf( 'freecurrencyapi returned HTTP %d with a body that is not JSON', $response->status() ),
				$response->status()
			);
		}

		return $decoded;
	}

	/**
	 * Turn an error response into the matching exception, and always throw.
	 *
	 * Records the remaining monthly quota first, because an error response is often the
	 * most recent authenticated answer available — a 429 in particular is the one reading
	 * the settings page most needs to show.
	 *
	 * Messages are built from the status code and never from the body. The 401 body
	 * carries marketing URLs, and these messages reach logs and admin notices; the API's
	 * own `error.code` is carried on the exception instead, where it can be branched on.
	 *
	 * @param HttpResponse $response The failing response.
	 * @return void Never returns; always throws.
	 * @throws AuthenticationException On 401 and 403.
	 * @throws ValidationException On 422.
	 * @throws RateLimitException On 429.
	 * @throws ApiException On any other non-2xx status.
	 */
	private function map_error( HttpResponse $response ) {
		$this->record_remaining_quota( $response );

		$status  = $response->status();
		$decoded = json_decode( $response->body(), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$api_error_code = isset( $decoded['error']['code'] ) && is_string( $decoded['error']['code'] )
			? $decoded['error']['code']
			: '';

		$message = sprintf( 'freecurrencyapi returned HTTP %d', $status );

		switch ( $status ) {
			case 401:
			case 403:
				throw new AuthenticationException( $message, $status, $api_error_code );

			case 422:
				throw new ValidationException(
					$message,
					$status,
					$api_error_code,
					isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ? $decoded['errors'] : array()
				);

			case 429:
				throw new RateLimitException(
					$message,
					$status,
					$api_error_code,
					$this->header_int( $response, self::HEADER_REMAINING_MONTH ),
					$this->header_int( $response, self::HEADER_REMAINING_MINUTE )
				);

			default:
				throw new ApiException( $message, $status, $api_error_code );
		}
	}

	/**
	 * Store the monthly quota remainder reported by a response.
	 *
	 * The header is the only place the remainder appears before the month ends, and it
	 * rides on every response from a request that authenticated — successes and errors
	 * alike. Nothing is written when it is absent: a 401 never reached an account and so
	 * carries no quota headers at all, and recording the `(int) ''` of a missing header
	 * would write a remaining count of 0 and read as an exhausted quota forever.
	 *
	 * @param HttpResponse $response Any response from an authenticated request.
	 * @return void
	 */
	private function record_remaining_quota( HttpResponse $response ) {
		$remaining = $this->header_int( $response, self::HEADER_REMAINING_MONTH );

		if ( null === $remaining ) {
			return;
		}

		update_option(
			self::QUOTA_OPTION,
			array(
				'remaining'  => $remaining,
				'limit'      => $this->header_int( $response, self::HEADER_LIMIT_MONTH ),
				// A quota figure with no age is misleading on a settings page: it could
				// be from this minute or from last month.
				'checked_at' => time(),
			),
			'no'
		);
	}

	/**
	 * Read a numeric response header.
	 *
	 * @param HttpResponse $response Response to read from.
	 * @param string       $name     Header name, lower-case.
	 * @return int|null The value, or null when the header is absent or not numeric.
	 */
	private function header_int( HttpResponse $response, $name ) {
		if ( ! $response->has_header( $name ) ) {
			return null;
		}

		$value = $response->header( $name );

		return is_numeric( $value ) ? (int) $value : null;
	}
}
