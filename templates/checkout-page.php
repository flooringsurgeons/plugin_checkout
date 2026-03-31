<?php
defined( 'ABSPATH' ) || exit;

get_header( 'noheader' );
?>
    <section class="fls-checkout-mobile-topbar">
        <a class="fls-checkout-topbar__back" href="<?= esc_url( wc_get_cart_url() ); ?>">
                <span aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9.56945 18.82C9.37945 18.82 9.18945 18.75 9.03945 18.6L2.96945 12.53C2.67945 12.24 2.67945 11.76 2.96945 11.47L9.03945 5.4C9.32945 5.11 9.80945 5.11 10.0995 5.4C10.3895 5.69 10.3895 6.17 10.0995 6.46L4.55945 12L10.0995 17.54C10.3895 17.83 10.3895 18.31 10.0995 18.6C9.95945 18.75 9.75945 18.82 9.56945 18.82Z"
                              fill="#292D32"/>
                        <path d="M20.4999 12.75H3.66992C3.25992 12.75 2.91992 12.41 2.91992 12C2.91992 11.59 3.25992 11.25 3.66992 11.25H20.4999C20.9099 11.25 21.2499 11.59 21.2499 12C21.2499 12.41 20.9099 12.75 20.4999 12.75Z"
                              fill="#292D32"/>
                    </svg>
                </span>
            <span><?php esc_html_e( 'Back to basket', 'fls-checkout-flow' ); ?></span>
        </a>

        <div class="fls-checkout-topbar__brand">
			<?php
			$site_mobile_logo = FLS_CHECKOUT_FLOW_URL . 'assets/image/svg/site-mobile-logo.svg';
			?>
            <a href="<?= esc_url( home_url() ) ?>" class="fls-checkout-topbar__site-name"><img
                        src="<?= esc_html( $site_mobile_logo ) ?>" alt="flooring surgeons"></a>
        </div>
    </section>
    <main class="fls-checkout-page">
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
