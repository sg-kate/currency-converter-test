<?php
/**
 * In-memory HTTP client for unit tests.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Fakes;

use Drozd\Currency\Http\HttpClientInterface;
use Drozd\Currency\Http\HttpResponse;
use Throwable;

/**
 * The whole reason `HttpClientInterface` exists.
 *
 * Substituted for `WpHttpClient`, it lets every branch of the API client be exercised
 * without a socket, a WordPress function, or a request against the 5000/month quota.
 *
 * It also *records* what it was asked to send, which is what makes assertions about the
 * request itself possible — how the API key was transmitted, what URL was built, whether
 * a query string was added. Those are requirements, and this is where they are observable.
 */
final class FakeHttpClient implements HttpClientInterface {

	/**
	 * Every request the client made, in order, as `array{url: string, headers: array}`.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>}>
	 */
	private $requests = array();

	/**
	 * Responses or throwables to return, in order. The last one repeats once exhausted.
	 *
	 * @var array<int, HttpResponse|Throwable>
	 */
	private $queue = array();

	/**
	 * Constructor.
	 *
	 * @param HttpResponse|Throwable ...$queue Responses to return, or throwables to throw.
	 */
	public function __construct( ...$queue ) {
		$this->queue = $queue;
	}

	/**
	 * Build a fake that answers every request with one response.
	 *
	 * @param int                          $status  HTTP status code.
	 * @param string                       $body    Response body.
	 * @param array<string, string|array>  $headers Response headers.
	 * @return self Configured fake.
	 */
	public static function responding( int $status, string $body, array $headers = array() ): self {
		return new self( new HttpResponse( $status, $body, $headers ) );
	}

	/**
	 * Build a fake whose transport always fails.
	 *
	 * @param Throwable $error The failure to throw.
	 * @return self Configured fake.
	 */
	public static function failing( Throwable $error ): self {
		return new self( $error );
	}

	/**
	 * Record the request and answer it from the queue.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @return HttpResponse The queued response.
	 * @throws Throwable When the queued item is a throwable.
	 */
	public function get( $url, array $headers = array() ) {
		$this->requests[] = array(
			'url'     => $url,
			'headers' => $headers,
		);

		$next = count( $this->queue ) > 1 ? array_shift( $this->queue ) : ( $this->queue[0] ?? null );

		if ( $next instanceof Throwable ) {
			throw $next;
		}

		if ( ! $next instanceof HttpResponse ) {
			throw new \LogicException( 'FakeHttpClient was given no response to return.' );
		}

		return $next;
	}

	/**
	 * Every recorded request.
	 *
	 * @return array<int, array{url: string, headers: array<string, string>}> Requests, in order.
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * How many requests were attempted.
	 *
	 * @return int Request count.
	 */
	public function call_count(): int {
		return count( $this->requests );
	}

	/**
	 * The URL of the most recent request.
	 *
	 * @return string URL, or an empty string when nothing was sent.
	 */
	public function last_url(): string {
		$last = end( $this->requests );

		return false === $last ? '' : $last['url'];
	}

	/**
	 * The headers of the most recent request.
	 *
	 * @return array<string, string> Headers, or an empty array when nothing was sent.
	 */
	public function last_headers(): array {
		$last = end( $this->requests );

		return false === $last ? array() : $last['headers'];
	}

	/**
	 * The URLs of every request, in order.
	 *
	 * @return array<int, string> URLs.
	 */
	public function urls(): array {
		return array_column( $this->requests, 'url' );
	}
}
