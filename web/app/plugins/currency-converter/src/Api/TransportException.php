<?php
/**
 * The request never produced an HTTP response.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * No status code at all: DNS failure, connection refused, TLS handshake failure, connect
 * or read timeout — everything `wp_remote_get()` reports as a `WP_Error`.
 *
 * The API-layer counterpart of `Http\TransportException`. The HTTP layer throws its own,
 * because it knows nothing about this API; `FreeCurrencyApiClient` catches that and
 * rethrows this one, so a caller can put a single `catch ( ApiException $e )` around a
 * sync and still be told about a DNS failure. The original is kept as `getPrevious()`.
 *
 * The one retryable failure in the hierarchy, and one retry is enough for a job that
 * runs daily. It also spends no quota, because the request never arrived — which is why
 * a silent retry loop here is invisible in the monthly usage and must be logged instead.
 */
class TransportException extends ApiException {

	/**
	 * The originating `WP_Error` code, such as `http_request_failed`.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Constructor.
	 *
	 * @param string          $message    Human-readable failure description.
	 * @param string          $error_code Originating `WP_Error` code, if any.
	 * @param \Throwable|null $previous   The underlying `Http\TransportException`.
	 */
	public function __construct( $message, $error_code = '', ?\Throwable $previous = null ) {
		// Status 0: there was no response, and no status code to report.
		parent::__construct( $message, 0, '', $previous );

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

	/**
	 * Whether retrying could plausibly succeed.
	 *
	 * @return bool Always true: this is the retryable branch of the hierarchy.
	 */
	public function is_retryable() {
		return true;
	}
}
