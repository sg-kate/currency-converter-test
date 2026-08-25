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

		/*
		 * Only PSR-4 name characters, and nothing that can climb out of `src/`.
		 *
		 * Without this the class name is pasted into a filesystem path and `..` survives
		 * `str_replace( '\\', '/', … )` intact: asking for `Drozd\Currency\..\..\..\wp-config`
		 * resolved to the plugin's grandparent and `require_once`d whatever was readable
		 * there. Nothing in this module passes a variable class name to the autoloader, so it
		 * was not reachable from here — but an autoloader is global, and any `class_exists()`
		 * or `new $name` anywhere on the site that lets a request reach a string with this
		 * prefix would have turned it into local file inclusion. Cheap to close, and it
		 * cannot be closed later from outside the plugin.
		 */
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative_class ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
