<?php

/**
 * Development overrides.
 *
 * Loaded after config/application.php, so Config::define() calls here replace
 * the base values rather than clashing with them.
 */

use Roots\WPConfig\Config;

Config::define('SAVEQUERIES', true);
Config::define('WP_DEBUG', true);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', true);
Config::define('SCRIPT_DEBUG', true);
Config::define('DISALLOW_INDEXING', true);

/**
 * Composer owns dependencies in every environment, but blocking file mods
 * locally also blocks `wp plugin install` and `wp theme install`, which are
 * useful while developing. Allow them here only.
 */
Config::define('DISALLOW_FILE_MODS', false);

ini_set('display_errors', '1');
