<?php
/**
 * The module's one outbound HTTP abstraction.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Performs outbound GET requests.
 *
 * Every network call the module makes goes through this interface, and exactly one
 * implementation touches a transport function. That is what makes the rest of the
 * module unit-testable: a test supplies its own implementation returning canned
 * `HttpResponse` objects, and no socket is opened. Nothing above this layer may call
 * `wp_remote_get()`, `curl_*`, or `file_get_contents()` on a URL.
 *
 * Deliberately narrow. The module needs GET and nothing else, so there is no `post()`
 * to stub in tests and no request-body handling to get wrong.
 *
 * Responses are returned whatever their status: a 401, 422 or 429 is an answer from the
 * server, and mapping status to meaning belongs to the caller, not here. Only a request
 * that never produced a status at all — DNS failure, connect or read timeout — throws.
 * That split matters because it is exactly the retryable/not-retryable line: a
 * `TransportException` is the one failure worth retrying, and every status code this
 * API returns as an error is permanent for the request as written.
 */
interface HttpClientInterface {

	/**
	 * Send a GET request and return whatever the server answered.
	 *
	 * @param string                $url     Absolute URL, query string already built.
	 * @param array<string, string> $headers Request headers as name => value. The API key
	 *                                       travels here and never in `$url`, because query
	 *                                       strings are recorded by access logs and proxy caches.
	 * @return HttpResponse The response, for any status code.
	 * @throws TransportException When the request produced no HTTP response at all.
	 */
	public function get( $url, array $headers = array() );
}
