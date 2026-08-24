<?php
/**
 * The WordPress-backed HTTP client. The only file in the module that makes a request.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Http;

defined( 'ABSPATH' ) || exit;

/**
 * `HttpClientInterface` implemented on `wp_remote_get()`.
 *
 * `wp_remote_get()` is the chosen transport because it is already present: it carries a
 * cURL backend, timeout handling and proxy support, so the module adds no Composer
 * dependency to make a request. That matters here beyond tidiness — the plugin ships as
 * a zip onto sites where this project's `vendor/` does not exist, so it has no runtime
 * dependencies at all.
 *
 * This class is the module's entire network surface. Nothing else may call
 * `wp_remote_get()`, `curl_*` or `file_get_contents()` on a URL, and the check is:
 *
 *     grep -rln 'wp_remote_get\|curl_init\|file_get_contents\|GuzzleHttp' \
 *         web/app/plugins/currency-converter/
 *     # expected: exactly this file
 *
 * It holds no state and takes no constructor arguments, so a caller that needs the real
 * network writes `new WpHttpClient()` and a test writes its own implementation instead.
 */
final class WpHttpClient implements HttpClientInterface {

	/**
	 * Request timeout, in seconds.
	 *
	 * WordPress defaults to 5, which does not survive a cold TLS handshake from inside a
	 * container: the first request after the stack comes up pays DNS, connect and full
	 * handshake costs at once, and intermittently exceeds 5 seconds. A timeout is a
	 * `WP_Error`, so a value that is merely usually enough turns into a
	 * `TransportException` and a failed daily sync a few times a month, for no reason
	 * other than the number being too small.
	 *
	 * 15 is generous against an API that normally answers well inside a second, and the
	 * sync runs once a day on cron, where the wait costs nothing.
	 */
	const TIMEOUT = 15;

	/**
	 * Send a GET request through the WordPress HTTP API.
	 *
	 * @param string                $url     Absolute URL, query string already built.
	 * @param array<string, string> $headers Request headers as name => value.
	 * @return HttpResponse The response, for any status code.
	 * @throws TransportException When `wp_remote_get()` returns a `WP_Error`, meaning no
	 *                            HTTP response was obtained.
	 */
	public function get( $url, array $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers'   => $headers,
				'timeout'   => self::TIMEOUT,

				/*
				 * Certificates are verified. Never flip this to silence a handshake
				 * failure: without it the API key is handed to whatever answers, which
				 * turns a loud local misconfiguration into a silent credential leak in
				 * production. A genuinely broken CA bundle is fixed in the image.
				 */
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TransportException(
				$response->get_error_message(),
				$response->get_error_code()
			);
		}

		return new HttpResponse(
			wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response ),
			self::headers_to_array( wp_remote_retrieve_headers( $response ) )
		);
	}

	/**
	 * Flatten retrieved headers into a plain array.
	 *
	 * `wp_remote_retrieve_headers()` returns a case-insensitive dictionary object rather
	 * than an array, so it is unwrapped here and `HttpResponse` stays free of the
	 * transport's types. A repeated header arrives as an array of values and is left as
	 * one for `HttpResponse` to join.
	 *
	 * @param mixed $headers Whatever `wp_remote_retrieve_headers()` returned.
	 * @return array<string, string|array> Headers as name => value.
	 */
	private static function headers_to_array( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return $headers->getAll();
		}

		return is_array( $headers ) ? $headers : array();
	}
}
