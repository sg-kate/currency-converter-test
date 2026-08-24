<?php
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * Hand-written on purpose. The plugin ships as a zip onto sites that have no `vendor/`
 * directory, so it must not depend on this project's Composer autoloader. It has no
 * runtime dependencies either, so its own classes are the only thing there is to load.
 *
 * `Drozd\Currency\Db\Schema` resolves to `src/Db/Schema.php`.
 *
 * @package Currency_Converter
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	/**
	 * Load a class from `src/`, ignoring every namespace but our own.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	static function ( $class_name ) {
		$prefix = 'Drozd\\Currency\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative_class = substr( $class_name, $length );
		$path           = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
