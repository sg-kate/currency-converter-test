<?php
/**
 * The outcome of a sync attempt.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Service;

defined( 'ABSPATH' ) || exit;

/**
 * What a sync did, in a form the CLI, the admin page and a log can all read.
 *
 * A sync has three outcomes and only one of them writes anything: it updated, it was
 * skipped because the data is still fresh, or it was skipped because another run holds the
 * lock. Returning a bare row count cannot express that — zero rows is ambiguous between
 * "refused to run" and "ran and stored nothing", and those need different reactions from
 * an operator. The status says which, `message()` says it in one line, and the structured
 * fields are there for anything that wants to react rather than print.
 *
 * A *failed* sync is not represented here. A sync that could not reach the API throws, so
 * that a caller cannot mistake a failure for a skip and present stale rates as current —
 * the rule in `.claude/agents/_TASK_CONTRACT.md` that overrides "make it work".
 */
final class SyncResult {

	/**
	 * Data was fetched and stored.
	 */
	const STATUS_UPDATED = 'updated';

	/**
	 * Nothing was fetched: what is stored is still inside the freshness window.
	 */
	const STATUS_FRESH = 'fresh';

	/**
	 * Nothing was fetched: another run holds the lock.
	 */
	const STATUS_LOCKED = 'locked';

	/**
	 * One of the STATUS_* constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * One line describing the outcome, for CLI output and logs.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Rows written. Always 0 unless the status is `updated`.
	 *
	 * @var int
	 */
	private $count;

	/**
	 * Codes the API returned that are not on the predefined list.
	 *
	 * @var array<int, string>
	 */
	private $unknown_codes;

	/**
	 * Codes on the predefined list that the API did not return.
	 *
	 * @var array<int, string>
	 */
	private $missing_codes;

	/**
	 * When the data was fetched, or when the stored data was last fetched for a skip.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private $timestamp;

	/**
	 * Constructor.
	 *
	 * @param string                  $status        One of the STATUS_* constants.
	 * @param string                  $message       One-line description.
	 * @param int                     $count         Rows written.
	 * @param array<int, string>      $unknown_codes Codes returned but not predefined.
	 * @param array<int, string>      $missing_codes Codes predefined but not returned.
	 * @param \DateTimeImmutable|null $timestamp     Fetch time, or the stored one on a skip.
	 */
	public function __construct( $status, $message, $count = 0, array $unknown_codes = array(), array $missing_codes = array(), ?\DateTimeImmutable $timestamp = null ) {
		$this->status        = (string) $status;
		$this->message       = (string) $message;
		$this->count         = (int) $count;
		$this->unknown_codes = array_values( $unknown_codes );
		$this->missing_codes = array_values( $missing_codes );
		$this->timestamp     = $timestamp;
	}

	/**
	 * A sync that fetched and stored.
	 *
	 * @param string                  $message       One-line description.
	 * @param int                     $count         Rows written.
	 * @param array<int, string>      $unknown_codes Codes returned but not predefined.
	 * @param array<int, string>      $missing_codes Codes predefined but not returned.
	 * @param \DateTimeImmutable|null $timestamp     When the data was fetched.
	 * @return self The result.
	 */
	public static function updated( $message, $count, array $unknown_codes = array(), array $missing_codes = array(), ?\DateTimeImmutable $timestamp = null ) {
		return new self( self::STATUS_UPDATED, $message, $count, $unknown_codes, $missing_codes, $timestamp );
	}

	/**
	 * A sync refused because what is stored is still fresh.
	 *
	 * @param string                  $message   One-line description.
	 * @param \DateTimeImmutable|null $timestamp When the stored data was fetched.
	 * @return self The result.
	 */
	public static function fresh( $message, ?\DateTimeImmutable $timestamp = null ) {
		return new self( self::STATUS_FRESH, $message, 0, array(), array(), $timestamp );
	}

	/**
	 * A sync refused because another run holds the lock.
	 *
	 * @param string $message One-line description.
	 * @return self The result.
	 */
	public static function locked( $message ) {
		return new self( self::STATUS_LOCKED, $message );
	}

	/**
	 * The outcome.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Whether anything was written.
	 *
	 * @return bool True when the sync stored data.
	 */
	public function is_updated() {
		return self::STATUS_UPDATED === $this->status;
	}

	/**
	 * Whether the sync declined to run.
	 *
	 * @return bool True for both skip reasons.
	 */
	public function is_skipped() {
		return ! $this->is_updated();
	}

	/**
	 * Rows written.
	 *
	 * @return int Row count; 0 for a skip.
	 */
	public function count() {
		return $this->count;
	}

	/**
	 * One line describing the outcome.
	 *
	 * @return string The message.
	 */
	public function message() {
		return $this->message;
	}

	/**
	 * Codes the API returned that are not on the predefined list.
	 *
	 * @return array<int, string> Codes, empty when there was no drift.
	 */
	public function unknown_codes() {
		return $this->unknown_codes;
	}

	/**
	 * Codes on the predefined list that the API did not return.
	 *
	 * @return array<int, string> Codes, empty when there was no drift.
	 */
	public function missing_codes() {
		return $this->missing_codes;
	}

	/**
	 * The fetch time, or the stored one when the sync was skipped as fresh.
	 *
	 * @return \DateTimeImmutable|null UTC timestamp, or null when there is none.
	 */
	public function timestamp() {
		return $this->timestamp;
	}

	/**
	 * Flat representation, for `--format=json` and for tests.
	 *
	 * @return array<string, mixed> The fields.
	 */
	public function to_array() {
		return array(
			'status'        => $this->status,
			'message'       => $this->message,
			'count'         => $this->count,
			'unknown_codes' => $this->unknown_codes,
			'missing_codes' => $this->missing_codes,
			'timestamp'     => null === $this->timestamp ? null : $this->timestamp->format( 'c' ),
		);
	}
}
