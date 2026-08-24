<?php
/**
 * Plugin Name: Bedrock Autoloader
 * Description: Loads must-use plugins that live in their own directory. WordPress only picks up top-level PHP files in mu-plugins, so anything Composer installs there is invisible without this.
 * Version:     1.0.0
 * License:     MIT
 *
 * @package TestProject
 */

defined( 'ABSPATH' ) || exit;

/*
 * WordPress boots fully during installation, before its tables exist. The
 * autoloader caches the plugins it finds in an option, so running it here fills
 * a brand new debug.log with "table wp_options doesn't exist" errors that look
 * like a broken install.
 */
if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

if ( ! class_exists( 'Roots\\Bedrock\\Autoloader' ) ) {
	return;
}

new Roots\Bedrock\Autoloader();
