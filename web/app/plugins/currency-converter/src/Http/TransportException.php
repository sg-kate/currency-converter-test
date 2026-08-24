<?php
/**
 * Failure to obtain any HTTP response at all.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a request never reached the point of having a status code.
 *
 * DNS failure, connection refused, TLS handshake failure, connect or read timeout —
 * the cases `wp_remote_get()` reports as a `WP_Error` rather than a response. This is
 * the only failure class worth retrying, and one retry is enough for a job that runs
 * once a day. An HTTP error status is *not* this: 401, 403 and 422 are permanent for
 * the request as written, and retrying them is how a bug becomes a banned key.
 *
 * Spends no quota, because the request never arrived.
 */
class TransportException extends \RuntimeException {

	/**
	 * The `WP_Error` code that produced this, for logging.
	 *
	 * A string such as `http_request_failed`, not an HTTP status. Empty when the
	 * failure did not come from a `WP_Error`.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $message    Human-readable failure description.
	 * @param string $error_code Originating `WP_Error` code, if any.
	 */
	public function __construct( $message, $error_code = '' ) {
		parent::__construct( $message );

		$this->error_code = (string) $error_code;
	}

	/**
	 * The originating `WP_Error` code.
	 *
	 * @return string Error code, or an empty string when there was none.
	 */
	public function error_code() {
		return $this->error_code;
	}
}
