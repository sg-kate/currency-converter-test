<?php
/**
 * The main template file. Used whenever no more specific template matches.
 *
 * @package TestTheme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<?php if ( have_posts() ) : ?>

	<?php if ( is_home() && ! is_front_page() ) : ?>
		<h1 class="page-title"><?php single_post_title(); ?></h1>
	<?php elseif ( is_archive() ) : ?>
		<h1 class="page-title"><?php the_archive_title(); ?></h1>
	<?php elseif ( is_search() ) : ?>
		<h1 class="page-title">
			<?php
			printf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for: %s', 'test-theme' ),
				esc_html( get_search_query() )
			);
			?>
		</h1>
	<?php endif; ?>

	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>

		<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry' ); ?>>
			<?php if ( is_singular() ) : ?>
				<h1 class="entry-title"><?php the_title(); ?></h1>
			<?php else : ?>
				<h2 class="entry-title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h2>
			<?php endif; ?>

			<?php if ( 'post' === get_post_type() ) : ?>
				<p class="entry-meta">
					<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
						<?php echo esc_html( get_the_date() ); ?>
					</time>
				</p>
			<?php endif; ?>

			<?php if ( has_post_thumbnail() && ! is_singular() ) : ?>
				<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'large' ); ?></a>
			<?php elseif ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'large' ); ?>
			<?php endif; ?>

			<div class="entry-content">
				<?php
				if ( is_singular() ) {
					the_content();
					wp_link_pages(
						array(
							'before' => '<p class="page-links">',
							'after'  => '</p>',
						)
					);
				} else {
					the_excerpt();
				}
				?>
			</div>
		</article>

		<?php
		if ( is_singular() && ( comments_open() || get_comments_number() ) ) {
			comments_template();
		}
		?>

	<?php endwhile; ?>

	<?php
	the_posts_pagination(
		array(
			'mid_size'  => 1,
			'prev_text' => esc_html__( 'Previous', 'test-theme' ),
			'next_text' => esc_html__( 'Next', 'test-theme' ),
		)
	);
	?>

<?php else : ?>

	<article class="entry">
		<h1 class="entry-title"><?php esc_html_e( 'Nothing found', 'test-theme' ); ?></h1>
		<div class="entry-content">
			<p><?php esc_html_e( 'No content matched your request.', 'test-theme' ); ?></p>
			<?php get_search_form(); ?>
		</div>
	</article>

<?php endif; ?>

<?php
get_footer();
