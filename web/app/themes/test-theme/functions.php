<?php
/**
 * Theme setup.
 *
 * @package TestTheme
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TEST_THEME_VERSION' ) ) {
	define( 'TEST_THEME_VERSION', '1.0.0' );
}

/**
 * Register theme support and the primary navigation menu.
 */
function test_theme_setup() {
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'test-theme' ),
		)
	);

	load_theme_textdomain( 'test-theme', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'test_theme_setup' );

/**
 * Enqueue the stylesheet.
 */
function test_theme_enqueue_assets() {
	wp_enqueue_style(
		'test-theme',
		get_stylesheet_uri(),
		array(),
		TEST_THEME_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'test_theme_enqueue_assets' );

/**
 * Register the footer widget area.
 */
function test_theme_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Footer', 'test-theme' ),
			'id'            => 'footer',
			'description'   => __( 'Widgets shown in the site footer.', 'test-theme' ),
			'before_widget' => '<div id="%1$s" class="widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'test_theme_widgets_init' );
