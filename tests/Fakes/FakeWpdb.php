<?php
/**
 * In-memory stand-in for `$wpdb`.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests\Fakes;

/**
 * Records what the repositories asked the database to do, and answers with canned rows.
 *
 * The repositories exist to turn domain objects into SQL, so the SQL *is* the behaviour
 * worth asserting: that a batch goes out as one statement rather than thirty-three, that
 * the rate is bound with `%s`, that `USD => 1.000000000000` is written first, and that a
 * hostile `orderby` never reaches `ORDER BY`. None of that is observable through a return
 * value, and all of it is observable here.
 *
 * `prepare()` mimics the real one closely enough for those assertions — it substitutes
 * `%s`, `%d` and `%f`, quoting strings — and deliberately not further: this is not a
 * database, and a test that needs one belongs in an integration run against MariaDB.
 */
final class FakeWpdb {

	/**
	 * Table prefix, as the real handle exposes it.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last database error, read by the repositories when a write fails.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Every statement that reached the database, after substitution, in order.
	 *
	 * @var array<int, string>
	 */
	public $queries = array();

	/**
	 * Every `prepare()` call as `array{query: string, args: array}` — the template before
	 * substitution, which is where `%s` versus `%f` is visible.
	 *
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public $prepared = array();

	/**
	 * What `query()` returns. `false` makes the repositories treat the write as failed.
	 *
	 * @var int|false
	 */
	private $query_result = 1;

	/**
	 * Rows handed back by `get_results()` and `get_row()`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $rows = array();

	/**
	 * Value handed back by `get_var()`.
	 *
	 * @var mixed
	 */
	private $var = null;

	/**
	 * Queue the rows the next read returns.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows as `$wpdb` would return them: every
	 *                                               column a string, which is the point.
	 * @return self This fake, for chaining.
	 */
	public function will_return_rows( array $rows ): self {
		$this->rows = $rows;

		return $this;
	}

	/**
	 * Queue the value the next `get_var()` returns.
	 *
	 * @param mixed $value Scalar value.
	 * @return self This fake, for chaining.
	 */
	public function will_return_var( $value ): self {
		$this->var = $value;

		return $this;
	}

	/**
	 * Make the next write fail the way a real one does: `false` plus `last_error`.
	 *
	 * @param string $message The database error message.
	 * @return self This fake, for chaining.
	 */
	public function will_fail( string $message ): self {
		$this->query_result = false;
		$this->last_error   = $message;

		return $this;
	}

	/**
	 * Substitute bound values into a query, as `wpdb::prepare()` does.
	 *
	 * @param string $query     Query with `%s`, `%d` or `%f` placeholders.
	 * @param mixed  ...$args   Values, or a single array of them.
	 * @return string The query with values substituted.
	 */
	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$this->prepared[] = array(
			'query' => $query,
			'args'  => array_values( $args ),
		);

		$index = 0;

		return (string) preg_replace_callback(
			'/%[sdf]/',
			static function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index ] ?? null;
				++$index;

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}

				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * Record a statement and report what was configured.
	 *
	 * @param string $query The statement.
	 * @return int|false Rows affected, or false when the fake was told to fail.
	 */
	public function query( $query ) {
		$this->queries[] = (string) $query;

		return $this->query_result;
	}

	/**
	 * Record a read and answer with the queued rows.
	 *
	 * @param string $query  The statement.
	 * @param string $output Output format; ignored, the fake always returns arrays.
	 * @return array<int, array<string, mixed>> The queued rows.
	 */
	public function get_results( $query, $output = 'OBJECT' ): array {
		$this->queries[] = (string) $query;

		return $this->rows;
	}

	/**
	 * Record a read and answer with the first queued row.
	 *
	 * @param string $query  The statement.
	 * @param string $output Output format; ignored.
	 * @return array<string, mixed>|null The first queued row, or null when there is none.
	 */
	public function get_row( $query, $output = 'OBJECT' ) {
		$this->queries[] = (string) $query;

		return $this->rows[0] ?? null;
	}

	/**
	 * Record a read and answer with the queued scalar.
	 *
	 * @param string $query The statement.
	 * @return mixed The queued value.
	 */
	public function get_var( $query ) {
		$this->queries[] = (string) $query;

		return $this->var;
	}

	/**
	 * How many statements have been executed.
	 *
	 * @return int Statement count.
	 */
	public function query_count(): int {
		return count( $this->queries );
	}

	/**
	 * The most recent statement, after substitution.
	 *
	 * @return string The statement, or an empty string when nothing has run.
	 */
	public function last_query(): string {
		$last = end( $this->queries );

		return false === $last ? '' : $last;
	}

	/**
	 * The template of the most recent `prepare()` call, before substitution.
	 *
	 * @return string The template, or an empty string when nothing was prepared.
	 */
	public function last_prepared_template(): string {
		$last = end( $this->prepared );

		return false === $last ? '' : $last['query'];
	}

	/**
	 * The bound values of the most recent `prepare()` call.
	 *
	 * @return array<int, mixed> The values.
	 */
	public function last_prepared_args(): array {
		$last = end( $this->prepared );

		return false === $last ? array() : $last['args'];
	}

	/**
	 * Forget everything recorded, keeping the configured answers.
	 *
	 * @return void
	 */
	public function reset_log(): void {
		$this->queries  = array();
		$this->prepared = array();
	}
}
