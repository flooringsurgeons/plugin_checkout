<?php
defined( 'ABSPATH' ) || exit;

get_header();
?>
	<main class="fls-thankyou-page">
		<div class="fls-thankyou-page__inner">
			<?php do_action( 'fls_thankyou_page_before_content' ); ?>

			<?php
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
			?>

			<?php do_action( 'fls_thankyou_page_after_content' ); ?>
		</div>
	</main>
<?php
get_footer();