<?php
/**
 * Access to the captured API responses in tests/Fixtures/.
 *
 * @package Tests
 */

declare( strict_types=1 );

namespace Tests;

/**
 * Loads fixture files.
 *
 * The fixtures are real responses wherever a real one could be obtained without an
 * unacceptable quota cost — `tests/Fixtures/PROVENANCE.md` records which is which, and
 * is the thing to read before trusting a test that depends on one. They are captured by
 * `scripts/capture-fixtures.sh` in a single run and then never fetched again.
 */
final class Fixture {

	/**
	 * The raw contents of a fixture file, exactly as the API sent it.
	 *
	 * @param string $name File name inside tests/Fixtures/.
	 * @return string File contents.
	 */
	public static function raw( string $name ): string {
		$path = __DIR__ . '/Fixtures/' . $name;

		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException(
				"Fixture {$name} is missing. Run scripts/capture-fixtures.sh to capture it."
			);
		}

		return (string) file_get_contents( $path );
	}

	/**
	 * A fixture decoded as an associative array.
	 *
	 * @param string $name File name inside tests/Fixtures/.
	 * @return array<string, mixed> Decoded body.
	 */
	public static function json( string $name ): array {
		return (array) json_decode( self::raw( $name ), true, 512, JSON_THROW_ON_ERROR );
	}
}
