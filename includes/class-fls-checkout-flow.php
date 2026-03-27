<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FLS_Checkout_Flow {
    private static $instance = null;

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'fls-checkout-flow', false, dirname( plugin_basename( FLS_CHECKOUT_FLOW_FILE ) ) . '/languages' );
    }

    public function boot(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        add_filter( 'template_include', array( $this, 'maybe_override_checkout_page_template' ), 99 );
        add_filter( 'woocommerce_locate_template', array( $this, 'maybe_override_woocommerce_template' ), 99, 3 );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_filter( 'woocommerce_checkout_fields', array( $this, 'customize_checkout_fields' ), 20 );
        add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );
        add_filter( 'woocommerce_ship_to_different_address_checked', array( $this, 'ship_to_different_address_checked' ) );

        add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_checkout_fragments' ) );

        add_action( 'woocommerce_login_form', array( $this, 'render_account_redirect_field' ) );
        add_action( 'woocommerce_register_form', array( $this, 'render_account_redirect_field' ) );
        add_filter( 'woocommerce_login_redirect', array( $this, 'filter_account_redirect_after_login' ), 10, 2 );
        add_filter( 'woocommerce_registration_redirect', array( $this, 'filter_account_redirect_after_registration' ) );

        add_action( 'woocommerce_checkout_process', array( $this, 'validate_step_two_fields' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_step_two_fields' ), 20, 2 );
    }

    public function woocommerce_missing_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__( 'FLS Checkout Flow requires WooCommerce to be active.', 'fls-checkout-flow' ) . '</p></div>';
    }

    public function maybe_override_checkout_page_template( $template ) {
        if ( ! $this->should_override_checkout() ) {
            return $template;
        }

        $custom_template = FLS_CHECKOUT_FLOW_PATH . 'templates/checkout-page.php';

        return file_exists( $custom_template ) ? $custom_template : $template;
    }

    public function maybe_override_woocommerce_template( $template, $template_name, $template_path ) {
        if ( ! $this->should_override_checkout() ) {
            return $template;
        }

        $allowed_templates = array(
            'checkout/form-checkout.php',
            'checkout/form-billing.php',
            'checkout/form-shipping.php',
        );

        if ( ! in_array( $template_name, $allowed_templates, true ) ) {
            return $template;
        }

        $custom_template = FLS_CHECKOUT_FLOW_PATH . 'templates/' . $template_name;

        return file_exists( $custom_template ) ? $custom_template : $template;
    }

    public function enqueue_assets(): void {
        if ( ! $this->should_override_checkout() ) {
            return;
        }

        wp_enqueue_style(
            'fls-checkout-flow-flatpickr',
	        FLS_CHECKOUT_FLOW_URL . 'assets/flatpickr/flatpickr.min.css',
            array(),
            '4.6.13'
        );

        wp_enqueue_style(
            'fls-checkout-flow',
            FLS_CHECKOUT_FLOW_URL . 'assets/css/checkout.css',
            array( 'fls-checkout-flow-flatpickr' ),
            '2.4.0'
        );

        wp_enqueue_script(
            'fls-checkout-flow-flatpickr',
	        FLS_CHECKOUT_FLOW_URL . 'assets/flatpickr/flatpickr.min.js',
            array(),
            '4.6.13',
            ['in_footer' => true]
        );

        wp_enqueue_script(
            'fls-checkout-flow',
            FLS_CHECKOUT_FLOW_URL . 'assets/js/checkout.js',
            array( 'jquery', 'wc-checkout', 'fls-checkout-flow-flatpickr' ),
            '2.4.0',
            ['in_footer' => true]
        );

        wp_localize_script(
            'fls-checkout-flow',
            'flsCheckoutFlow',
            array(
                'activeStep' => 1,
                'i18n'       => array(
                    'stepOneError'     => __( 'Please complete the required customer details before continuing.', 'fls-checkout-flow' ),
                    'stepTwoError'     => __( 'Please choose a delivery option before continuing.', 'fls-checkout-flow' ),
                    'stepTwoDateError' => __( 'Please choose a date before continuing.', 'fls-checkout-flow' ),
                    'chooseDate'       => __( 'Select your date', 'fls-checkout-flow' ),
                ),
            )
        );
    }

    public function customize_checkout_fields( $fields ) {
        if ( isset( $fields['billing']['billing_company'] ) ) {
            unset( $fields['billing']['billing_company'] );
        }

        if ( isset( $fields['billing']['billing_address_2'] ) ) {
            unset( $fields['billing']['billing_address_2'] );
        }

        if ( isset( $fields['shipping']['shipping_company'] ) ) {
            unset( $fields['shipping']['shipping_company'] );
        }

        if ( isset( $fields['shipping']['shipping_address_2'] ) ) {
            unset( $fields['shipping']['shipping_address_2'] );
        }

        if ( isset( $fields['order']['order_comments'] ) ) {
            unset( $fields['order']['order_comments'] );
        }

        $field_map = array(
            'billing'  => array(
                'billing_first_name' => array( 'width' => 'half', 'placeholder' => __( 'First Name', 'fls-checkout-flow' ), 'priority' => 10 ),
                'billing_last_name'  => array( 'width' => 'half', 'placeholder' => __( 'Last Name', 'fls-checkout-flow' ), 'priority' => 20 ),
                'billing_email'      => array( 'width' => 'half', 'placeholder' => __( 'Email Address', 'fls-checkout-flow' ), 'priority' => 30 ),
                'billing_phone'      => array( 'width' => 'half', 'placeholder' => __( 'Contact Number', 'fls-checkout-flow' ), 'priority' => 40 ),
                'billing_address_1'  => array( 'width' => 'wide', 'placeholder' => __( 'Street Address', 'fls-checkout-flow' ), 'priority' => 50 ),
                'billing_city'       => array( 'width' => 'half', 'placeholder' => __( 'Town/City', 'fls-checkout-flow' ), 'priority' => 60 ),
                'billing_postcode'   => array( 'width' => 'half', 'placeholder' => __( 'Postcode', 'fls-checkout-flow' ), 'priority' => 70 ),
                'billing_country'    => array( 'width' => 'half', 'placeholder' => __( 'Country/Region', 'fls-checkout-flow' ), 'priority' => 80 ),
                'billing_state'      => array( 'width' => 'half', 'placeholder' => __( 'County/State', 'fls-checkout-flow' ), 'priority' => 90 ),
            ),
            'shipping' => array(
                'shipping_first_name' => array( 'width' => 'half', 'placeholder' => __( 'First Name', 'fls-checkout-flow' ), 'priority' => 10 ),
                'shipping_last_name'  => array( 'width' => 'half', 'placeholder' => __( 'Last Name', 'fls-checkout-flow' ), 'priority' => 20 ),
                'shipping_address_1'  => array( 'width' => 'wide', 'placeholder' => __( 'Street Address', 'fls-checkout-flow' ), 'priority' => 30 ),
                'shipping_city'       => array( 'width' => 'half', 'placeholder' => __( 'Town/City', 'fls-checkout-flow' ), 'priority' => 40 ),
                'shipping_postcode'   => array( 'width' => 'half', 'placeholder' => __( 'Postcode', 'fls-checkout-flow' ), 'priority' => 50 ),
                'shipping_country'    => array( 'width' => 'half', 'placeholder' => __( 'Country/Region', 'fls-checkout-flow' ), 'priority' => 60 ),
                'shipping_state'      => array( 'width' => 'half', 'placeholder' => __( 'County/State', 'fls-checkout-flow' ), 'priority' => 70 ),
            ),
        );

        foreach ( $field_map as $group_key => $group_fields ) {
            if ( empty( $fields[ $group_key ] ) || ! is_array( $fields[ $group_key ] ) ) {
                continue;
            }

            foreach ( $group_fields as $field_key => $settings ) {
                if ( empty( $fields[ $group_key ][ $field_key ] ) ) {
                    continue;
                }

                $placeholder = ! empty( $settings['placeholder'] ) ? $settings['placeholder'] : '';
                $width       = ! empty( $settings['width'] ) ? $settings['width'] : 'wide';
                $priority    = isset( $settings['priority'] ) ? (int) $settings['priority'] : 100;

                if ( empty( $placeholder ) && ! empty( $fields[ $group_key ][ $field_key ]['label'] ) ) {
                    $placeholder = wp_strip_all_tags( $fields[ $group_key ][ $field_key ]['label'] );
                }

                $fields[ $group_key ][ $field_key ]['placeholder'] = $placeholder;
                $fields[ $group_key ][ $field_key ]['label_class'] = array( 'screen-reader-text' );
                $fields[ $group_key ][ $field_key ]['input_class'] = array( 'fls-checkout__input' );
                $fields[ $group_key ][ $field_key ]['class']      = array(
                    'form-row',
                    'fls-checkout__field',
                    'half' === $width ? 'fls-checkout__field--half' : 'fls-checkout__field--wide',
                );
                $fields[ $group_key ][ $field_key ]['priority']   = $priority;
                $fields[ $group_key ][ $field_key ]['label']      = isset( $fields[ $group_key ][ $field_key ]['label'] ) ? $fields[ $group_key ][ $field_key ]['label'] : $placeholder;
            }
        }

        $fields = $this->remove_optional_house_name_fields( $fields );

        return $fields;
    }

    private function remove_optional_house_name_fields( $fields ) {
        foreach ( array( 'billing', 'shipping' ) as $group_key ) {
            if ( empty( $fields[ $group_key ] ) || ! is_array( $fields[ $group_key ] ) ) {
                continue;
            }

            foreach ( $fields[ $group_key ] as $field_key => $field ) {
                $label       = isset( $field['label'] ) ? strtolower( wp_strip_all_tags( (string) $field['label'] ) ) : '';
                $placeholder = isset( $field['placeholder'] ) ? strtolower( wp_strip_all_tags( (string) $field['placeholder'] ) ) : '';
                $is_optional = empty( $field['required'] );
                $is_house    = false !== strpos( $field_key, 'house_name' ) || false !== strpos( $label, 'house name' ) || false !== strpos( $placeholder, 'house name' );

                if ( $is_house && $is_optional ) {
                    unset( $fields[ $group_key ][ $field_key ] );
                }
            }
        }

        return $fields;
    }

    public function ship_to_different_address_checked( $checked ): bool {
        if ( isset( $_POST['ship_to_different_address'] ) ) {
            return 1 === (int) wp_unslash( $_POST['ship_to_different_address'] );
        }

        return (bool) $checked;
    }

    public function update_checkout_fragments( $fragments ) {
        if ( ! WC()->cart ) {
            return $fragments;
        }

        $checkout = WC()->checkout();

        $fragments['#fls-checkout-order-details']                         = $this->get_order_details_html();
        $fragments['#fls-checkout-shipping-methods']                      = $this->get_shipping_methods_html();
        $fragments['#fls-checkout-payment']                               = $this->get_payment_html( $checkout );
        $fragments['[data-fls-checkout-item-count]']                      = '<span data-fls-checkout-item-count>' . esc_html( $this->get_cart_items_count_label() ) . '</span>';
        $fragments['.fls-checkout-step__section--shipping-fields']        = $this->get_shipping_customer_section_html( $checkout );

        return $fragments;
    }

    public function get_cart_items_count_label(): string {
        $count = 0;

        if ( WC()->cart ) {
            $count = (int) WC()->cart->get_cart_contents_count();
        }

        return sprintf( _n( '%d Item', '%d Items', $count, 'fls-checkout-flow' ), $count );
    }

    public function get_checkout_logo_html(): string {
        $logo = get_custom_logo();

        if ( ! empty( $logo ) ) {
            return $logo;
        }

        return '<span class="fls-checkout-topbar__site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
    }

    public function get_checkout_account_url(): string {
        $redirect_to = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );

        if ( function_exists( 'wc_get_page_permalink' ) ) {
            return add_query_arg(
                'redirect_to',
                rawurlencode( $redirect_to ),
                wc_get_page_permalink( 'myaccount' )
            );
        }

        return wp_login_url( $redirect_to );
    }

    public function render_account_redirect_field(): void {
        $redirect = $this->get_account_redirect_target();

        if ( empty( $redirect ) ) {
            return;
        }

        echo '<input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '" />';
    }

    public function filter_account_redirect_after_login( $redirect, $user ) {
        $target = $this->get_account_redirect_target();

        if ( empty( $target ) ) {
            return $redirect;
        }

        return $target;
    }

    public function filter_account_redirect_after_registration( $redirect ) {
        $target = $this->get_account_redirect_target();

        if ( empty( $target ) ) {
            return $redirect;
        }

        return $target;
    }

    private function get_account_redirect_target() {
        $fallback = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
        $target   = '';

        if ( ! empty( $_POST['redirect'] ) ) {
            $target = wp_unslash( $_POST['redirect'] );
        } elseif ( ! empty( $_GET['redirect_to'] ) ) {
            $target = rawurldecode( wp_unslash( $_GET['redirect_to'] ) );
        } elseif ( function_exists( 'wc_get_raw_referer' ) ) {
            $referer = wc_get_raw_referer();

            if ( $referer && false !== strpos( $referer, wc_get_checkout_url() ) ) {
                $target = $referer;
            }
        }

        return wp_validate_redirect( $target, $fallback );
    }

    public function get_order_details_html(): false|string {
        ob_start();
        ?>
        <div id="fls-checkout-order-details" class="fls-order-details">
            <div class="fls-order-details__card">
                <div class="fls-order-details__header">
                    <div>
                        <h3 class="fls-order-details__title"><?php esc_html_e( 'Order Details', 'fls-checkout-flow' ); ?></h3>
                    </div>
                    <span class="fls-order-details__count" data-fls-checkout-item-count><?php echo esc_html( $this->get_cart_items_count_label() ); ?></span>
                </div>

                <button class="fls-order-details__summary-toggle" type="button" data-fls-summary-toggle aria-expanded="true">
                    <span><?php esc_html_e( 'Basket Summary', 'fls-checkout-flow' ); ?></span>
                    <span class="fls-order-details__summary-icon" aria-hidden="true">⌄</span>
                </button>

                <div class="fls-order-details__summary" data-fls-summary-body>
                    <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) : ?>
                        <?php
                        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;

                        if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
                            continue;
                        }
                        ?>
                        <div class="fls-order-details__item">
                            <div class="fls-order-details__item-main">
                                <span class="fls-order-details__item-name"><?php echo esc_html( $product->get_name() ); ?></span>
                                <span class="fls-order-details__item-qty"><?php echo esc_html( sprintf( '× %d', (int) $cart_item['quantity'] ) ); ?></span>
                            </div>
                            <span class="fls-order-details__item-price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( wc_coupons_enabled() ) : ?>
                    <div class="fls-order-details__coupon-block">
                        <h4 class="fls-order-details__block-title"><?php esc_html_e( 'Have Discount Code?', 'fls-checkout-flow' ); ?></h4>

                        <form class="fls-order-details__coupon-form" data-fls-coupon-form>
                            <label class="screen-reader-text" for="fls_coupon_code"><?php esc_html_e( 'Coupon code', 'woocommerce' ); ?></label>
                            <input id="fls_coupon_code" type="text" name="coupon_code" class="fls-order-details__coupon-input" placeholder="<?php echo esc_attr__( 'Enter Discount Code', 'fls-checkout-flow' ); ?>" autocomplete="off" />
                            <button type="submit" class="fls-order-details__coupon-button"><?php esc_html_e( 'Apply', 'woocommerce' ); ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="fls-order-details__totals">
                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
                    </div>

                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( $this->get_shipping_total_html() ); ?></span>
                    </div>

                    <?php if ( WC()->cart->get_discount_total() > 0 ) : ?>
                        <div class="fls-order-details__row fls-order-details__row--discount">
                            <span><?php esc_html_e( 'Discount', 'woocommerce' ); ?></span>
                            <span>- <?php echo wp_kses_post( wc_price( WC()->cart->get_discount_total() + WC()->cart->get_discount_tax() ) ); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ( wc_tax_enabled() ) : ?>
                        <div class="fls-order-details__row">
                            <span><?php esc_html_e( 'VAT', 'fls-checkout-flow' ); ?></span>
                            <span><?php echo wp_kses_post( wc_price( $this->get_total_tax_amount() ) ); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="fls-order-details__row fls-order-details__row--total">
                        <span><?php esc_html_e( 'Total', 'woocommerce' ); ?></span>
                        <strong><?php echo wp_kses_post( WC()->cart->get_total() ); ?></strong>
                    </div>
                </div>

                <div class="fls-order-details__assurance">
                    <span><?php esc_html_e( 'Free returns within 30 days', 'fls-checkout-flow' ); ?></span>
                    <span><?php esc_html_e( 'Price match guarantee', 'fls-checkout-flow' ); ?></span>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    public function get_shipping_methods_html(): false|string {
        ob_start();
        ?>
        <div id="fls-checkout-shipping-methods" class="fls-checkout-shipping-methods">
            <?php if ( WC()->cart->needs_shipping() ) : ?>
                <?php $this->render_shipping_methods_markup(); ?>
            <?php else : ?>
                <p class="fls-checkout-step__empty"><?php esc_html_e( 'No shipping method is required for this order.', 'fls-checkout-flow' ); ?></p>
            <?php endif; ?>

            <div class="fls-checkout-step__actions fls-checkout-step__actions--split">
                <button type="button" class="fls-checkout-step__button fls-checkout-step__button--secondary" data-fls-step-prev="1"><?php esc_html_e( 'Back', 'fls-checkout-flow' ); ?></button>
                <button type="button" class="fls-checkout-step__button" data-fls-step-next="3"><?php esc_html_e( 'Continue to Payment', 'fls-checkout-flow' ); ?></button>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    public function get_shipping_customer_section_html( $checkout ): false|string {
        ob_start();
        echo '<div class="fls-checkout-step__section fls-checkout-step__section--shipping-fields">';

        $template = FLS_CHECKOUT_FLOW_PATH . 'templates/checkout/form-shipping.php';

        if ( file_exists( $template ) ) {
            include $template;
        } else {
            wc_get_template(
                'checkout/form-shipping.php',
                array(
                    'checkout' => $checkout,
                )
            );
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function render_shipping_methods_markup(): void {
        $grouped_rates  = $this->get_grouped_shipping_rates();
        $delivery_rates = $grouped_rates['delivery'];
        $pickup_rates   = $grouped_rates['pickup'];
        $stored_date    = $this->get_posted_checkout_value( 'fls_delivery_date' );
        $stored_mode    = $this->get_posted_checkout_value( 'fls_delivery_mode' );
        $active_mode    = 'pickup' === $stored_mode && ! empty( $pickup_rates ) ? 'pickup' : 'delivery';

        if ( empty( $delivery_rates ) && ! empty( $pickup_rates ) ) {
            $active_mode = 'pickup';
        }

        $pickup_rate = ! empty( $pickup_rates ) ? $pickup_rates[0] : null;
        $pickup_data = $this->get_pickup_location_data( $pickup_rate ? $pickup_rate['rate'] : null );
        ?>
        <div class="fls-delivery-method" data-fls-delivery-method data-default-mode="<?php echo esc_attr( $active_mode ); ?>">
            <input type="hidden" name="fls_delivery_mode" value="<?php echo esc_attr( $active_mode ); ?>" data-fls-delivery-mode-input />
            <input type="hidden" name="fls_delivery_date" value="<?php echo esc_attr( $stored_date ); ?>" data-fls-delivery-date-input />

            <div class="fls-delivery-method__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Delivery type', 'fls-checkout-flow' ); ?>">
                <?php if ( ! empty( $delivery_rates ) ) : ?>
                    <button type="button" class="fls-delivery-method__tab<?php echo 'delivery' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-tab="delivery" role="tab" aria-selected="<?php echo 'delivery' === $active_mode ? 'true' : 'false'; ?>">
                        <span aria-hidden="true">🛵</span>
                        <span><?php esc_html_e( 'Delivery', 'fls-checkout-flow' ); ?></span>
                    </button>
                <?php endif; ?>

                <?php if ( ! empty( $pickup_rates ) ) : ?>
                    <button type="button" class="fls-delivery-method__tab<?php echo 'pickup' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-tab="pickup" role="tab" aria-selected="<?php echo 'pickup' === $active_mode ? 'true' : 'false'; ?>">
                        <span aria-hidden="true">🚶</span>
                        <span><?php esc_html_e( 'Pickup', 'fls-checkout-flow' ); ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $delivery_rates ) ) : ?>
                <div class="fls-delivery-method__panel<?php echo 'delivery' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-panel="delivery">
                    <div class="fls-delivery-method__options">
                        <?php foreach ( $delivery_rates as $delivery_rate ) : ?>
                            <?php $this->render_shipping_rate_card( $delivery_rate, 'delivery' ); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="fls-delivery-method__date-row" data-fls-date-wrap="delivery" style="display:none;">
                        <label class="screen-reader-text" for="fls-delivery-date-display"><?php esc_html_e( 'Delivery date', 'fls-checkout-flow' ); ?></label>
                        <input id="fls-delivery-date-display" type="text" class="fls-delivery-method__date-input" data-fls-date-display="delivery" placeholder="<?php echo esc_attr__( 'Select Your Date', 'fls-checkout-flow' ); ?>" autocomplete="off" readonly />
                        <span class="fls-delivery-method__date-icon" aria-hidden="true">🗓</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $pickup_rate ) ) : ?>
                <div class="fls-delivery-method__panel<?php echo 'pickup' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-panel="pickup">
                    <div class="fls-delivery-method__options fls-delivery-method__options--single">
                        <?php $this->render_shipping_rate_card( $pickup_rate, 'pickup' ); ?>
                    </div>

                    <div class="fls-delivery-method__pickup-details" data-fls-pickup-details style="display:none;">
                        <h4 class="fls-delivery-method__pickup-title"><?php echo esc_html( $pickup_data['title'] ); ?></h4>
                        <?php if ( ! empty( $pickup_data['address'] ) ) : ?>
                            <p class="fls-delivery-method__pickup-address"><?php echo esc_html( $pickup_data['address'] ); ?></p>
                        <?php endif; ?>

                        <?php if ( ! empty( $pickup_data['map_url'] ) ) : ?>
                            <div class="fls-delivery-method__pickup-map">
                                <iframe src="<?php echo esc_url( $pickup_data['map_url'] ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="fls-delivery-method__date-row" data-fls-date-wrap="pickup" style="display:none;">
                        <label class="screen-reader-text" for="fls-pickup-date-display"><?php esc_html_e( 'Pickup date', 'fls-checkout-flow' ); ?></label>
                        <input id="fls-pickup-date-display" type="text" class="fls-delivery-method__date-input" data-fls-date-display="pickup" placeholder="<?php echo esc_attr__( 'Select Your Date', 'fls-checkout-flow' ); ?>" autocomplete="off" readonly />
                        <span class="fls-delivery-method__date-icon" aria-hidden="true">🗓</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_shipping_rate_card( $shipping_rate_data, $mode ): void {
        $rate          = $shipping_rate_data['rate'];
        $title         = $this->get_rate_primary_label( $rate );
        $description   = $this->get_rate_secondary_label( $rate, $mode );
        $input_id      = $shipping_rate_data['input_id'];
        $package_index = $shipping_rate_data['package_index'];
        $rate_id       = $shipping_rate_data['rate_id'];
        $is_checked    = ! empty( $shipping_rate_data['checked'] );
        $requires_date = ! empty( $shipping_rate_data['requires_date'] );
        ?>
        <label class="fls-shipping-card<?php echo $is_checked ? ' is-selected' : ''; ?>" data-fls-shipping-card data-mode="<?php echo esc_attr( $mode ); ?>" data-requires-date="<?php echo $requires_date ? '1' : '0'; ?>" for="<?php echo esc_attr( $input_id ); ?>">
            <input type="radio" class="shipping_method fls-shipping-card__input" name="shipping_method[<?php echo esc_attr( $package_index ); ?>]" data-index="<?php echo esc_attr( $package_index ); ?>" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $rate_id ); ?>" <?php checked( $is_checked ); ?> />
            <span class="fls-shipping-card__radio" aria-hidden="true"></span>
            <span class="fls-shipping-card__content">
                <span class="fls-shipping-card__text">
                    <strong class="fls-shipping-card__title"><?php echo esc_html( $title ); ?></strong>
                    <?php if ( ! empty( $description ) ) : ?>
                        <span class="fls-shipping-card__description"><?php echo esc_html( $description ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="fls-shipping-card__meta">
                    <?php if ( 'delivery' === $mode && $requires_date ) : ?>
                        <span class="fls-shipping-card__calendar" aria-hidden="true">🗓</span>
                    <?php else : ?>
                        <span class="fls-shipping-card__price"><?php echo wp_kses_post( $this->get_shipping_rate_cost_html( $rate ) ); ?></span>
                    <?php endif; ?>
                </span>
            </span>
        </label>
        <?php
    }

    private function get_grouped_shipping_rates(): array {
        $packages       = WC()->shipping()->get_packages();
        $chosen_methods = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $grouped        = array(
            'delivery' => array(),
            'pickup'   => array(),
        );

        if ( empty( $packages ) ) {
            return $grouped;
        }

        foreach ( $packages as $package_index => $package ) {
            $rates = isset( $package['rates'] ) ? $package['rates'] : array();

            if ( empty( $rates ) ) {
                continue;
            }

            $default_value = isset( $chosen_methods[ $package_index ] ) ? $chosen_methods[ $package_index ] : key( $rates );

            foreach ( $rates as $rate_id => $rate ) {
                $input_id = 'shipping_method_' . $package_index . '_' . sanitize_title( $rate_id );
                $mode     = 'local_pickup' === $rate->get_method_id() ? 'pickup' : 'delivery';

                $grouped[ $mode ][] = array(
                    'package_index'  => $package_index,
                    'rate_id'        => $rate_id,
                    'input_id'       => $input_id,
                    'checked'        => $default_value === $rate_id,
                    'requires_date'  => $this->rate_requires_date( $rate ),
                    'rate'           => $rate,
                );
            }
        }

        if ( count( $grouped['pickup'] ) > 1 ) {
            $grouped['pickup'] = array_slice( $grouped['pickup'], 0, 1 );
        }

        return $grouped;
    }

    private function get_rate_primary_label( $rate ) {
        $label = wp_strip_all_tags( (string) $rate->get_label() );

        if ( false !== strpos( $label, '|' ) ) {
            $parts = array_map( 'trim', explode( '|', $label, 2 ) );
            return ! empty( $parts[0] ) ? $parts[0] : $label;
        }

        if ( false !== strpos( $label, ' - ' ) ) {
            $parts = array_map( 'trim', explode( ' - ', $label, 2 ) );
            return ! empty( $parts[0] ) ? $parts[0] : $label;
        }

        return $label;
    }

    private function get_rate_secondary_label( $rate, $mode ) {
        $label = wp_strip_all_tags( (string) $rate->get_label() );

        if ( false !== strpos( $label, '|' ) ) {
            $parts = array_map( 'trim', explode( '|', $label, 2 ) );
            return ! empty( $parts[1] ) ? $parts[1] : '';
        }

        if ( false !== strpos( $label, ' - ' ) ) {
            $parts = array_map( 'trim', explode( ' - ', $label, 2 ) );
            return ! empty( $parts[1] ) ? $parts[1] : '';
        }

        if ( 'pickup' === $mode ) {
            $pickup_data = $this->get_pickup_location_data( $rate );
            return ! empty( $pickup_data['address'] ) ? $pickup_data['address'] : '';
        }

        return apply_filters( 'fls_checkout_shipping_rate_description', '', $rate, $mode );
    }

    private function rate_requires_date( $rate ): bool {
        $requires_date = 'local_pickup' === $rate->get_method_id();
        $label         = strtolower( wp_strip_all_tags( (string) $rate->get_label() ) );

        if ( false !== strpos( $label, 'date' ) ) {
            $requires_date = true;
        }

        return (bool) apply_filters( 'fls_checkout_rate_requires_date', $requires_date, $rate );
    }

    private function get_posted_checkout_value( $key ): string {
        if ( isset( $_POST[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        }

        if ( ! empty( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $posted_data );

            if ( isset( $posted_data[ $key ] ) ) {
                return sanitize_text_field( $posted_data[ $key ] );
            }
        }

        return '';
    }

    private function get_pickup_location_data( $rate = null ) {
        $store_parts = array_filter(
            array(
                get_option( 'woocommerce_store_address' ),
                get_option( 'woocommerce_store_city' ),
                get_option( 'woocommerce_store_postcode' ),
            )
        );

        $address = implode( ', ', $store_parts );
        $title   = $rate ? $this->get_rate_primary_label( $rate ) : __( 'Pick up address', 'fls-checkout-flow' );

        $data = array(
            'title'   => $title,
            'address' => $address,
            'map_url' => ! empty( $address ) ? 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed' : '',
        );

        return apply_filters( 'fls_checkout_pickup_location', $data, $rate );
    }

    public function validate_step_two_fields(): void {
        if ( empty( $_POST['shipping_method'] ) || ! is_array( $_POST['shipping_method'] ) ) {
            return;
        }

        $shipping_method_values = array_map( 'sanitize_text_field', wp_unslash( $_POST['shipping_method'] ) );
        $chosen_rate_id         = reset( $shipping_method_values );
        $delivery_date          = isset( $_POST['fls_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) : '';
        $rate                   = $this->find_shipping_rate_by_id( $chosen_rate_id );

        if ( $rate && $this->rate_requires_date( $rate ) && empty( $delivery_date ) ) {
            wc_add_notice( __( 'Please choose a date for your delivery method.', 'fls-checkout-flow' ), 'error' );
        }
    }

    public function save_step_two_fields( $order, $data ): void {
        if ( ! empty( $_POST['fls_delivery_mode'] ) ) {
            $order->update_meta_data( '_fls_delivery_mode', sanitize_text_field( wp_unslash( $_POST['fls_delivery_mode'] ) ) );
        }

        if ( ! empty( $_POST['fls_delivery_date'] ) ) {
            $order->update_meta_data( '_fls_delivery_date', sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) );
        }
    }

    private function find_shipping_rate_by_id( $rate_id ) {
        $packages = WC()->shipping()->get_packages();

        foreach ( $packages as $package ) {
            if ( empty( $package['rates'][ $rate_id ] ) ) {
                continue;
            }

            return $package['rates'][ $rate_id ];
        }

        return null;
    }

    private function get_shipping_rate_cost_html( $rate ): string {
        $cost  = (float) $rate->get_cost();
        $taxes = array_sum( (array) $rate->get_taxes() );
        $total = $cost + (float) $taxes;

        if ( $total <= 0 ) {
            return esc_html__( 'Free', 'woocommerce' );
        }

        return wc_price( $total );
    }

    public function get_payment_html( $checkout ): false|string {
        ob_start();
        ?>
        <div id="fls-checkout-payment" class="fls-checkout-payment">
            <?php woocommerce_checkout_payment(); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function get_shipping_total_html(): string {
        if ( ! WC()->cart->needs_shipping() ) {
            return esc_html__( 'Free', 'woocommerce' );
        }

        $shipping_total = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();

        if ( $shipping_total <= 0 ) {
            return esc_html__( 'Calculated at next step', 'fls-checkout-flow' );
        }

        return wc_price( $shipping_total );
    }

    private function get_total_tax_amount(): float|int {
        $totals = WC()->cart->get_totals();

        return isset( $totals['total_tax'] ) ? (float) $totals['total_tax'] : 0;
    }

    private function should_override_checkout(): bool {
        if ( is_admin() || wp_doing_ajax() ) {
            return false;
        }

        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return false;
        }

        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            return false;
        }

        if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
            return false;
        }

        return true;
    }
}
