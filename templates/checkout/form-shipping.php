<?php
/**
 * Custom shipping form template.
 */

defined( 'ABSPATH' ) || exit;

$needs_shipping_address = WC()->cart && WC()->cart->needs_shipping_address();
$is_checked             = 1 === (int) apply_filters(
    'woocommerce_ship_to_different_address_checked',
    'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0
);
?>
<div class="woocommerce-shipping-fields fls-checkout-shipping-fields">
    <?php if ( $needs_shipping_address ) : ?>
        <div class="fls-checkout-address-choice" data-fls-address-choice>
            <h3 class="fls-checkout-address-choice__title"><?php esc_html_e( 'What address should we use for billing?', 'fls-checkout-flow' ); ?></h3>

            <input
                id="ship-to-different-address-checkbox"
                class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox fls-checkout-address-choice__checkbox"
                <?php checked( $is_checked, true ); ?>
                type="checkbox"
                name="ship_to_different_address"
                value="1"
                hidden
            />

            <div class="fls-checkout-address-choice__options">
                <button
                    type="button"
                    class="fls-checkout-address-choice__option<?php echo $is_checked ? '' : ' is-active'; ?>"
                    data-fls-address-mode="same"
                    aria-pressed="<?php echo $is_checked ? 'false' : 'true'; ?>"
                >
                    <span class="fls-checkout-address-choice__radio" aria-hidden="true"></span>
                    <span class="fls-checkout-address-choice__text"><?php esc_html_e( 'My billing address is the same as the delivery address', 'fls-checkout-flow' ); ?></span>
                </button>

                <button
                    type="button"
                    class="fls-checkout-address-choice__option<?php echo $is_checked ? ' is-active' : ''; ?>"
                    data-fls-address-mode="different"
                    aria-pressed="<?php echo $is_checked ? 'true' : 'false'; ?>"
                >
                    <span class="fls-checkout-address-choice__radio" aria-hidden="true"></span>
                    <span class="fls-checkout-address-choice__text"><?php esc_html_e( 'Use a different billing address', 'fls-checkout-flow' ); ?></span>
                </button>
            </div>
        </div>

        <div class="shipping_address fls-checkout-shipping-address-fields"<?php echo $is_checked ? '' : ' style="display:none;"'; ?>>
            <?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>

            <div class="woocommerce-shipping-fields__field-wrapper">
                <?php
                $fields = $checkout->get_checkout_fields( 'shipping' );

                foreach ( $fields as $key => $field ) {
                    woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
                }
                ?>
            </div>

            <?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>
        </div>
    <?php endif; ?>
</div>
