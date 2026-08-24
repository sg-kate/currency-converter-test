<?php
/**
 * Base class for every failure of a call to freecurrencyapi.com.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Something went wrong talking to the API.
 *
 * Root of the module's API exception hierarchy, so a caller that does not care why a
 * sync failed catches this one type and logs it, while a caller that must distinguish
 * a dead key from an exhausted quota catches the subclasses:
 *
 *     ApiException
 *     ├── AuthenticationException  401, 403 — permanent, the key or the plan is wrong
 *     ├── ValidationException      422      — permanent, the request as written is wrong
 *     ├── RateLimitException       429      — carries the remaining quota
 *     └── TransportException       no HTTP response at all — the one retryable case
 *
 * Only `TransportException` is worth retrying. The others are permanent for the request
 * as written, and retrying a 401 in a loop is how a key gets banned.
 *
 * These carry the status code and the API's own machine-readable error code, never the
 * response body: the 401 body ships marketing URLs, and an exception message ends up in
 * logs and admin notices.
 */
class ApiException extends \RuntimeException {

	/**
	 * HTTP status that produced this, or 0 when no response was obtained.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * The API's own error code, such as `invalid_api_key`.
	 *
	 * The stable machine-readable field: branch on this, never on `message`, which is
	 * prose and may be reworded without notice. Empty when the body carried none.
	 *
	 * @var string
	 */
	private $api_error_code;

	/**
	 * Constructor.
	 *
	 * @param string          $message        Human-readable failure description, safe to log.
	 * @param int             $status         HTTP status code, or 0 when there was no response.
	 * @param string          $api_error_code The API's `error.code` value, when the body had one.
	 * @param \Throwable|null $previous       Underlying failure, preserved for the stack trace.
	 */
	public function __construct( $message, $status = 0, $api_error_code = '', ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->status         = (int) $status;
		$this->api_error_code = (string) $api_error_code;
	}

	/**
	 * The HTTP status that produced this failure.
	 *
	 * @return int Status code, or 0 when no HTTP response was obtained.
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * The API's machine-readable error code.
	 *
	 * @return string Error code, or an empty string when the body carried none.
	 */
	public function api_error_code() {
		return $this->api_error_code;
	}

	/**
	 * Whether retrying the identical request could plausibly succeed.
	 *
	 * False for everything but a transport failure. A 401, 403 or 422 will return the
	 * same answer forever, and a 429 needs a wait rather than a retry.
	 *
	 * @return bool True when a retry is worth attempting.
	 */
	public function is_retryable() {
		return false;
	}
}
