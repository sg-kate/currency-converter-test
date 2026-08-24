<?php
/**
 * Site footer.
 *
 * @package TestTheme
 */

defined( 'ABSPATH' ) || exit;
?>
	</main>

	<footer class="site-footer">
		<?php if ( is_active_sidebar( 'footer' ) ) : ?>
			<div class="footer-widgets">
				<?php dynamic_sidebar( 'footer' ); ?>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: 1: current year, 2: site name. */
				esc_html__( '&copy; %1$s %2$s', 'test-theme' ),
				esc_html( gmdate( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>
	</footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
