<?php
/**
 * PHPUnit bootstrap.
 *
 * The autoloader has to be required on the FIRST line that does any work.
 * Brain\Monkey redefines WordPress functions through Patchwork, and Patchwork can
 * only intercept files loaded after itself. Load anything testable before this
 * and the redefinitions silently do nothing — the tests then fail in ways that
 * point everywhere except here.
 *
 * @package Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
