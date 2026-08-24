<?php
/**
 * An HTTP response, decoupled from the transport that produced it.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object: status, body, headers.
 *
 * Deliberately transport-agnostic and free of WordPress. A test constructs one directly
 * from a fixture, so nothing above the HTTP layer needs `wp_remote_retrieve_*` or a
 * faked `pre_http_request` filter to exercise response handling.
 *
 * Deliberately *not* a parser. The body stays a string and JSON decoding belongs to
 * `Api\Client`, which is the layer that knows what shape to expect. Existing here, a
 * `json()` helper would have to guess what an undecodable body means.
 *
 * Every instance represents an answer from a server. Requests that produced no answer
 * are `TransportException`, never a response with a synthetic status of 0 — a sentinel
 * status would be silently comparable to a real one, and the two need different handling.
 */
final class HttpResponse {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Raw response body, undecoded.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Response headers, names lower-cased, values already joined.
	 *
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * Constructor.
	 *
	 * @param int                         $status  HTTP status code.
	 * @param string                      $body    Raw response body.
	 * @param array<string, string|array> $headers Response headers. Names are lower-cased
	 *                                             here, so callers may pass them in any case;
	 *                                             a value given as an array is joined with
	 *                                             ', ' in the manner of a repeated header.
	 */
	public function __construct( $status, $body, array $headers = array() ) {
		$this->status  = (int) $status;
		$this->body    = (string) $body;
		$this->headers = array();

		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( (string) $name ) ] = is_array( $value )
				? implode( ', ', $value )
				: (string) $value;
		}
	}

	/**
	 * The HTTP status code.
	 *
	 * @return int Status code.
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * The raw, undecoded response body.
	 *
	 * @return string Response body.
	 */
	public function body() {
		return $this->body;
	}

	/**
	 * Whether the status is in the 2xx range.
	 *
	 * A convenience for the transport layer only. It is not sufficient for this API:
	 * a 200 carrying `{"data":{}}` is a failure for the module's purposes, so callers
	 * must check the payload as well as the status.
	 *
	 * @return bool True when the status is 200-299.
	 */
	public function is_success() {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * One response header, by name.
	 *
	 * Case-insensitive. The default is returned for a header that is absent, which is
	 * not the same as a header present with a value of zero: freecurrencyapi omits the
	 * `X-RateLimit-*` headers entirely on a 401, where the request never reached an
	 * account. Code reading a remaining-quota header must distinguish "absent" from
	 * "none left" rather than casting the default to an integer and believing it.
	 *
	 * @param string $name     Header name, in any case.
	 * @param string $fallback Value to return when the header is absent.
	 * @return string Header value, or `$fallback`.
	 */
	public function header( $name, $fallback = '' ) {
		$key = strtolower( (string) $name );

		return array_key_exists( $key, $this->headers ) ? $this->headers[ $key ] : $fallback;
	}

	/**
	 * Whether a header is present, whatever its value.
	 *
	 * @param string $name Header name, in any case.
	 * @return bool True when the header was sent.
	 */
	public function has_header( $name ) {
		return array_key_exists( strtolower( (string) $name ), $this->headers );
	}

	/**
	 * Every response header.
	 *
	 * @return array<string, string> Headers, names lower-cased.
	 */
	public function headers() {
		return $this->headers;
	}
}
