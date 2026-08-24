<?php
/**
 * A quota or rate limit was exhausted.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 429, carrying the remaining quota.
 *
 * The API returns 429 for two limits that need completely different handling, and the
 * body — `{"message":"Too Many Requests"}` — does not say which. Only the headers do,
 * so they are captured here:
 *
 * | Exhausted   | Clears            | What the caller should do        |
 * | ----------- | ----------------- | -------------------------------- |
 * | Per-minute  | within the minute | wait, then retry later           |
 * | Monthly     | end of the month  | stop; no amount of retrying helps |
 *
 * Remaining counts are nullable on purpose. A header that was not sent is `null`, never
 * `0`: `wp_remote_retrieve_header()` returns `''` for an absent header and `(int) ''` is
 * `0`, which reads as "quota exhausted" and would turn a missing header into a month of
 * refusing to sync.
 */
class RateLimitException extends ApiException {

	/**
	 * Requests left in the monthly quota, or null when the header was absent.
	 *
	 * @var int|null
	 */
	private $remaining_month;

	/**
	 * Requests left in the current minute, or null when the header was absent.
	 *
	 * @var int|null
	 */
	private $remaining_minute;

	/**
	 * Constructor.
	 *
	 * @param string          $message          Human-readable description.
	 * @param int             $status           HTTP status, normally 429.
	 * @param string          $api_error_code   The API's `error.code`, if any.
	 * @param int|null        $remaining_month  Monthly quota remaining, null if not reported.
	 * @param int|null        $remaining_minute Per-minute allowance remaining, null if not reported.
	 * @param \Throwable|null $previous         Underlying failure, if any.
	 */
	public function __construct( $message, $status = 429, $api_error_code = '', $remaining_month = null, $remaining_minute = null, ?\Throwable $previous = null ) {
		parent::__construct( $message, $status, $api_error_code, $previous );

		$this->remaining_month  = null === $remaining_month ? null : (int) $remaining_month;
		$this->remaining_minute = null === $remaining_minute ? null : (int) $remaining_minute;
	}

	/**
	 * Requests left in the monthly quota.
	 *
	 * @return int|null Remaining requests, or null when the API did not report it.
	 */
	public function remaining_month() {
		return $this->remaining_month;
	}

	/**
	 * Requests left in the current minute.
	 *
	 * @return int|null Remaining requests, or null when the API did not report it.
	 */
	public function remaining_minute() {
		return $this->remaining_minute;
	}

	/**
	 * Whether the monthly quota is the limit that was hit.
	 *
	 * Only true when the header actually reported zero. An unreported monthly remainder
	 * returns false, so an absent header is treated as the recoverable case rather than
	 * silently standing the sync down until the month turns.
	 *
	 * @return bool True when the monthly quota is exhausted.
	 */
	public function is_monthly_quota() {
		return 0 === $this->remaining_month;
	}

	/**
	 * Whether waiting a short while could clear this.
	 *
	 * True for the per-minute limit, false once the month is gone.
	 *
	 * @return bool True when the limit clears within the minute.
	 */
	public function is_retryable() {
		return ! $this->is_monthly_quota();
	}
}
