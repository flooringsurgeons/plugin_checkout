<?php
/**
 * Custom checkout form.
 */

defined( 'ABSPATH' ) || exit;

$checkout = isset( $checkout ) ? $checkout : WC()->checkout();
$flow     = FLS_Checkout_Flow::init();

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
    echo esc_html__( 'You must be logged in to checkout.', 'woocommerce' );
    return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout fls-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">
    <?php do_action( 'fls_checkout_before_layout', $checkout ); ?>

    <div class="fls-checkout-shell">
        <div class="fls-checkout-topbar">
            <a class="fls-checkout-topbar__back" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
                <span aria-hidden="true">←</span>
                <span><?php esc_html_e( 'Back to basket', 'fls-checkout-flow' ); ?></span>
            </a>

            <div class="fls-checkout-topbar__brand">
                <?php echo wp_kses_post( $flow->get_checkout_logo_html() ); ?>
            </div>
        </div>

        <div class="fls-checkout-steps-nav" data-fls-steps-nav>
            <button type="button" class="fls-checkout-steps-nav__item is-active" data-fls-step-trigger="1">
                <span class="fls-checkout-steps-nav__dot"></span>
                <span class="fls-checkout-steps-nav__label"><?php esc_html_e( 'Details', 'fls-checkout-flow' ); ?></span>
            </button>

            <span class="fls-checkout-steps-nav__line" aria-hidden="true"></span>

            <button type="button" class="fls-checkout-steps-nav__item" data-fls-step-trigger="2">
                <span class="fls-checkout-steps-nav__dot"></span>
                <span class="fls-checkout-steps-nav__label"><?php esc_html_e( 'Shipping', 'fls-checkout-flow' ); ?></span>
            </button>

            <span class="fls-checkout-steps-nav__line" aria-hidden="true"></span>

            <button type="button" class="fls-checkout-steps-nav__item" data-fls-step-trigger="3">
                <span class="fls-checkout-steps-nav__dot"></span>
                <span class="fls-checkout-steps-nav__label"><?php esc_html_e( 'Payment', 'fls-checkout-flow' ); ?></span>
            </button>

            <a class="fls-checkout-steps-nav__account" href="<?php echo esc_url( $flow->get_checkout_account_url() ); ?>">
                <?php echo esc_html( is_user_logged_in() ? __( 'My Account', 'fls-checkout-flow' ) : __( 'Login/Register', 'fls-checkout-flow' ) ); ?>
            </a>
        </div>

        <?php if ( $checkout->get_checkout_fields() ) : ?>
            <div class="fls-checkout-layout">
                <section class="fls-checkout-main">
                    <?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

                    <section class="fls-checkout-step is-active" data-fls-step="1">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="1">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e( '1- Customer Details', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e( 'Step 1 of 3', 'fls-checkout-flow' ); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e( 'Edit', 'fls-checkout-flow' ); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="1">
                            <div class="fls-checkout-step__section fls-checkout-step__section--billing">
                                <?php do_action( 'woocommerce_checkout_billing' ); ?>
                            </div>

                            <div class="fls-checkout-step__section fls-checkout-step__section--shipping-fields">
                                <?php do_action( 'woocommerce_checkout_shipping' ); ?>
                            </div>

                            <div class="fls-checkout-step__actions">
                                <button type="button" class="fls-checkout-step__button" data-fls-step-next="2" disabled><?php esc_html_e( 'Continue to Shipping', 'fls-checkout-flow' ); ?></button>
                            </div>
                        </div>
                    </section>

                    <?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

                    <section class="fls-checkout-step" data-fls-step="2">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="2">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e( '2- Delivery Method', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e( 'Step 2 of 3', 'fls-checkout-flow' ); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e( 'Edit', 'fls-checkout-flow' ); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="2">
                            <?php echo $flow->get_shipping_methods_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </section>

                    <section class="fls-checkout-step" data-fls-step="3">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="3">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e( '3- Payment Method', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e( 'Step 3 of 3', 'fls-checkout-flow' ); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e( 'Edit', 'fls-checkout-flow' ); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="3">
                            <?php echo $flow->get_payment_html( $checkout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </section>
                </section>

                <aside class="fls-checkout-sidebar">
                    <?php echo $flow->get_order_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </aside>
            </div>
        <?php endif; ?>
    </div>

    <?php do_action( 'fls_checkout_after_layout', $checkout ); ?>
</form>
<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
