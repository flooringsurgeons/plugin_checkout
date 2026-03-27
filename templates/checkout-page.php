<?php

defined( 'ABSPATH' ) || exit;

get_header( 'noheader' );
?>
<main id="primary" class="fls-checkout-page">
    <div class="fls-checkout-page__inner">
        <?php do_action( 'fls_checkout_page_before_content' ); ?>

        <?php
        while ( have_posts() ) :
            the_post();

            wc_get_template(
                'checkout/form-checkout.php',
                array(
                    'checkout' => WC()->checkout(),
                )
            );
        endwhile;
        ?>

        <?php do_action( 'fls_checkout_page_after_content' ); ?>
    </div>
</main>
<?php
get_footer( 'nofooter' );
