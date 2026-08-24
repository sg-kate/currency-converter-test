<?php
/**
 * Comments template.
 *
 * Required by core: calling comments_template() without one triggers a
 * deprecation notice, as core falls back to its own template.
 *
 * @package TestTheme
 */

defined( 'ABSPATH' ) || exit;

// Do not load for password-protected posts until the password is supplied.
if ( post_password_required() ) {
	return;
}
?>

<section id="comments" class="comments-area">

	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			$comment_count = get_comments_number();
			printf(
				/* translators: %s: comment count. */
				esc_html( _n( '%s comment', '%s comments', $comment_count, 'test-theme' ) ),
				esc_html( number_format_i18n( $comment_count ) )
			);
			?>
		</h2>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 40,
				)
			);
			?>
		</ol>

		<?php
		the_comments_pagination(
			array(
				'prev_text' => esc_html__( 'Previous', 'test-theme' ),
				'next_text' => esc_html__( 'Next', 'test-theme' ),
			)
		);
		?>

		<?php if ( ! comments_open() ) : ?>
			<p class="no-comments"><?php esc_html_e( 'Comments are closed.', 'test-theme' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php comment_form(); ?>

</section>
