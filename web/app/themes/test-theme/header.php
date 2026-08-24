<?php
/**
 * Site header.
 *
 * @package TestTheme
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="site">
	<header class="site-header">
		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php endif; ?>

		<p class="site-title">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<?php bloginfo( 'name' ); ?>
			</a>
		</p>

		<?php $description = get_bloginfo( 'description', 'display' ); ?>
		<?php if ( $description ) : ?>
			<p class="site-description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<nav class="site-nav" aria-label="<?php esc_attr_e( 'Primary Menu', 'test-theme' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'depth'          => 2,
					)
				);
				?>
			</nav>
		<?php endif; ?>
	</header>

	<main class="site-main">
