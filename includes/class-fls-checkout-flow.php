<?php
defined( 'ABSPATH' ) || exit;

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

	public function load_textdomain() {
		load_plugin_textdomain( 'fls-checkout-flow', false, dirname( plugin_basename( FLS_CHECKOUT_FLOW_FILE ) ) . '/languages' );
	}

	public function boot() {
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

	public function woocommerce_missing_notice() {
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

	public function enqueue_assets() {
		if ( ! $this->should_override_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'fls-checkout-flow-flatpickr',
			FLS_CHECKOUT_FLOW_URL . 'assets/vendor/flatpickr/flatpickr.min.css',
			array(),
			'4.6.13-local'
		);

		wp_enqueue_style(
			'fls-checkout-flow',
			FLS_CHECKOUT_FLOW_URL . 'assets/css/checkout.css',
			array( 'fls-checkout-flow-flatpickr' ),
			'2.7.0'
		);

		wp_enqueue_script(
			'fls-checkout-flow-flatpickr',
			FLS_CHECKOUT_FLOW_URL . 'assets/vendor/flatpickr/flatpickr.min.js',
			array(),
			'4.6.13-local',
			true
		);

		wp_enqueue_script(
			'fls-checkout-flow',
			FLS_CHECKOUT_FLOW_URL . 'assets/js/checkout.js',
			array( 'jquery', 'wc-checkout', 'fls-checkout-flow-flatpickr' ),
			'2.7.0',
			true
		);

		wp_localize_script(
			'fls-checkout-flow',
			'flsCheckoutFlow',
			array(
				'activeStep' => 1,
				'coupon'     => array(
					'applyNonce'  => wp_create_nonce( 'apply-coupon' ),
					'removeNonce' => wp_create_nonce( 'remove-coupon' ),
				),
				'i18n'       => array(
					'stepOneError'      => __( 'Please complete the required customer details before continuing.', 'fls-checkout-flow' ),
					'stepTwoError'      => __( 'Please choose a delivery option before continuing.', 'fls-checkout-flow' ),
					'stepTwoDateError'  => __( 'Please choose a date before continuing.', 'fls-checkout-flow' ),
					'chooseDate'        => __( 'Select your date', 'fls-checkout-flow' ),
					'discountApplied'  => __( 'Discount Applied', 'fls-checkout-flow' ),
					'couponRemoved'    => __( 'Coupon has been removed.', 'woocommerce' ),
					'couponEmpty'      => __( 'Please enter a discount code.', 'fls-checkout-flow' ),
					'couponApplyError' => __( 'Something went wrong while applying the coupon.', 'fls-checkout-flow' ),
					'couponRemoveError'=> __( 'Something went wrong while removing the coupon.', 'fls-checkout-flow' ),
					'couponApplyLabel' => __( 'Apply', 'woocommerce' ),
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

	public function ship_to_different_address_checked( $checked ) {
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

		$fragments['#fls-checkout-order-details']                  = $this->get_order_details_html();
		$fragments['#fls-checkout-shipping-methods']               = $this->get_shipping_methods_html();
		$fragments['#fls-checkout-payment']                        = $this->get_payment_html( $checkout );
		$fragments['.fls-checkout-step__section--shipping-fields'] = $this->get_shipping_customer_section_html( $checkout );

		return $fragments;
	}

	public function get_cart_items_count_label(): string {
		$count = 0;

		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item['quantity'] ) && (int) $cart_item['quantity'] > 0 ) {
					$count++;
				}
			}
		}

		return sprintf( _n( '%d Item', '%d Items', $count, 'fls-checkout-flow' ), $count );
	}

	public function get_checkout_logo_html() {
		$logo = get_custom_logo();

		if ( ! empty( $logo ) ) {
			return $logo;
		}

        $site_logo = FLS_CHECKOUT_FLOW_URL .'assets/image/svg/site-logo.svg';

		return '<a href="' . esc_url( home_url() ) . '" class="fls-checkout-topbar__site-name"><img src="' . esc_html( $site_logo ) . '" alt="flooring surgeons"></a>';
	}

	public function get_checkout_account_url() {
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

	public function render_account_redirect_field() {
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

	private function is_accessories_product( $product ) {
		$check_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return has_term( 142, 'product_cat', $check_id );
	}

	private function get_order_item_pack_data( $cart_item, $product ) {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}

		$product_id = $product->get_id();

		if ( $product->is_type( 'variation' ) && ! get_field( 'pack_size', $product_id ) ) {
			$product_id = $product->get_parent_id();
		}

		$pack_size       = get_field( 'pack_size', $product_id );
		$total_required  = get_field( 'total_m2_required', $product_id );
		$number_of_packs = get_field( 'number_of_packs', $product_id );

		$has_pack_size       = '' !== $pack_size && null !== $pack_size;
		$has_total_required  = '' !== $total_required && null !== $total_required;
		$has_number_of_packs = '' !== $number_of_packs && null !== $number_of_packs;

		if ( ! $has_pack_size && ! $has_total_required && ! $has_number_of_packs ) {
			return null;
		}

		$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		$packs    = $has_number_of_packs ? (int) $number_of_packs : $quantity;
		$total    = null;

		if ( $has_total_required ) {
			$total = (float) $total_required;
		} elseif ( $has_pack_size ) {
			$total = (float) $quantity * (float) $pack_size;
		}

		return array(
			'packs' => $packs,
			'total' => $total,
		);
	}

	private function get_order_item_qty_label( $cart_item, $product ) {
		$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

		if ( $quantity <= 0 ) {
			return '';
		}

		if ( $this->is_accessories_product( $product ) ) {
			return sprintf( __( 'Qty: %d', 'fls-checkout-flow' ), $quantity );
		}

		$pack_data = $this->get_order_item_pack_data( $cart_item, $product );

		if ( empty( $pack_data ) ) {
			return sprintf( __( 'Qty: %d', 'fls-checkout-flow' ), $quantity );
		}

		$packs      = isset( $pack_data['packs'] ) ? (int) $pack_data['packs'] : $quantity;
		$packs_text = sprintf(
			_n( '%d pack', '%d packs', $packs, 'fls-checkout-flow' ),
			$packs
		);

		$label = sprintf( __( 'Qty: %s', 'fls-checkout-flow' ), $packs_text );

		if ( isset( $pack_data['total'] ) && null !== $pack_data['total'] ) {
			$label .= ' (' . wc_format_decimal( (float) $pack_data['total'], 2 ) . 'm²)';
		}

		return $label;
	}

	private function get_order_item_thumbnail_html( $product ) {
		return $product ? $product->get_image( 'woocommerce_thumbnail' ) : '';
	}


	private function get_manual_vat_breakdown_data() {
		$data = array(
			'total'        => 0,
			'vat'          => 0,
			'total_ex_vat' => 0,
			'decimals'     => wc_get_price_decimals(),
		);

		if ( ! WC()->cart ) {
			return $data;
		}

		$total = (float) WC()->cart->get_total( 'edit' );
		$vat   = $total > 0 ? ( $total / 120 ) * 20 : 0;

		$data['total']        = $total;
		$data['vat']          = $vat;
		$data['total_ex_vat'] = $total - $vat;

		return $data;
	}

	private function get_product_sale_discount_total() {
		$total = 0.0;

		if ( ! WC()->cart ) {
			return $total;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
			$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

			if ( ! $product || ! $product->exists() || $quantity <= 0 ) {
				continue;
			}

			$regular = (float) $product->get_regular_price();
			$current = (float) $product->get_price();

			if ( $regular > $current ) {
				$total += ( $regular - $current ) * $quantity;
			}
		}

		return $total;
	}

	private function get_order_details_discount_rows() {
		$rows  = array();
		$total = 0.0;

		if ( ! WC()->cart ) {
			return array(
				'rows'  => $rows,
				'total' => $total,
			);
		}

		$product_discount = $this->get_product_sale_discount_total();

		if ( $product_discount > 0 ) {
			$rows[] = array(
				'label'     => __( 'Discount', 'woocommerce' ),
				'amount'    => $product_discount,
				'type'      => 'product_discount',
				'removable' => false,
			);
			$total += $product_discount;
		}

		foreach ( WC()->cart->get_fees() as $fee ) {
			$fee_total = isset( $fee->total ) ? (float) $fee->total : (float) $fee->amount;

			if ( $fee_total < 0 ) {
				$rows[] = array(
					'label'     => $fee->name,
					'amount'    => abs( $fee_total ),
					'type'      => 'fee_discount',
					'removable' => false,
				);
				$total += abs( $fee_total );
			}
		}

		$applied_coupons = array_values( WC()->cart->get_applied_coupons() );

		foreach ( $applied_coupons as $code ) {
			$coupon_amount = (float) WC()->cart->get_coupon_discount_amount( $code ) + (float) WC()->cart->get_coupon_discount_tax_amount( $code );

			if ( $coupon_amount <= 0 ) {
				continue;
			}

			$rows[] = array(
				'label'     => wc_format_coupon_code( $code ),
				'code'      => $code,
				'amount'    => $coupon_amount,
				'type'      => 'coupon',
				'removable' => true,
			);

			$total += $coupon_amount;
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	private function get_coupon_block_html() {
		ob_start();
		?>
		<?php if ( wc_coupons_enabled() ) : ?>
            <div class="fls-order-details__coupon-block" data-fls-coupon-block>
                <h4 class="fls-order-details__block-title"><?php esc_html_e( 'Have Discount Code?', 'fls-checkout-flow' ); ?></h4>

                <div class="fls-order-details__coupon-form" data-fls-coupon-form>
                    <div class="fls-order-details__coupon-input-wrap">
                        <input
                                id="fls_coupon_code"
                                type="text"
                                name="coupon_code"
                                class="fls-order-details__coupon-input"
                                value=""
                                placeholder="<?php echo esc_attr__( 'Enter Discount Code', 'fls-checkout-flow' ); ?>"
                                autocomplete="off"
                        />
                    </div>

                    <button type="button" class="fls-order-details__coupon-button" data-fls-coupon-submit>
						<?php echo esc_html__( 'Apply', 'woocommerce' ); ?>
                    </button>
                </div>

				<?php if ( floorista_option( 'show_coupon_limit_text' ) ) : ?>
                    <p class="fls-order__coupon_limit_text"><?php esc_html_e( 'Only one discount code can be used per order', 'fls-checkout-flow' ); ?></p>
				<?php endif; ?>
            </div>
		<?php endif; ?>
		<?php

		return ob_get_clean();
	}

	public function get_order_details_html() {
		$discount_data            = $this->get_order_details_discount_rows();
		$discount_rows            = $discount_data['rows'];
		$discount_total           = $discount_data['total'];
		$vat_data                 = $this->get_manual_vat_breakdown_data();

		ob_start();
		?>
        <div id="fls-checkout-order-details" class="fls-order-details">
            <div class="fls-checkout-toast-stack" data-fls-toast-stack aria-live="polite" aria-atomic="true"></div>
            <div class="fls-order-details__card">
                <div class="fls-order-details__header">
                    <div>
                        <h3 class="fls-order-details__title"><?php esc_html_e( 'Order Details', 'fls-checkout-flow' ); ?></h3>
                    </div>
                    <span class="fls-order-details__count" data-fls-checkout-item-count><?php echo esc_html( $this->get_cart_items_count_label() ); ?></span>
                </div>

                <button class="fls-order-details__summary-toggle" type="button" data-fls-summary-toggle aria-expanded="false">
                    <span><?php esc_html_e( 'Basket Summary', 'fls-checkout-flow' ); ?></span>
                    <span class="fls-order-details__summary-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 9L12 15L18 9" stroke="#020617" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                </button>

                <div class="fls-order-details__summary" data-fls-summary-body style="display:none;">
					<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) : ?>
						<?php
						$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;

						if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
							continue;
						}

						$is_sample      = ! empty( $cart_item['sample_product'] );
						$qty_label      = $this->get_order_item_qty_label( $cart_item, $product );
						$thumbnail_html = $this->get_order_item_thumbnail_html( $product );
						?>
                        <div class="fls-order-details__item">
                            <div class="fls-order-details__item-thumb"><?php echo wp_kses_post( $thumbnail_html ); ?></div>

                            <div class="fls-order-details__item-main">
                                <span class="fls-order-details__item-name"><?php echo esc_html( $product->get_name() ); ?></span>

								<?php if ( $is_sample ) : ?>
                                    <span class="fls-order-details__item-note"><?php esc_html_e( 'This is a sample product.', 'fls-checkout-flow' ); ?></span>
								<?php elseif ( ! empty( $qty_label ) ) : ?>
                                    <span class="fls-order-details__item-meta"><?php echo esc_html( $qty_label ); ?></span>
								<?php endif; ?>
                            </div>

                            <span class="fls-order-details__item-price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?></span>
                        </div>
					<?php endforeach; ?>
                </div>

	            <?php echo $this->get_coupon_block_html(); ?>

                <div class="fls-order-details__totals">
                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
                    </div>

                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( $this->get_shipping_total_html() ); ?></span>
                    </div>

					<?php foreach ( $discount_rows as $discount_row ) : ?>
                        <div class="fls-order-details__row fls-order-details__row--discount-line">
                            <span><?php echo esc_html( $discount_row['label'] ); ?></span>

                            <span class="fls-order-details__row-value fls-order-details__row-value--discount">
                                - <?php echo wp_kses_post( wc_price( $discount_row['amount'] ) ); ?>

								<?php if ( ! empty( $discount_row['removable'] ) && ! empty( $discount_row['code'] ) ) : ?>
                                    <button
                                            type="button"
                                            class="fls-order-details__discount-remove"
                                            data-fls-coupon-remove
                                            data-coupon-code="<?php echo esc_attr( $discount_row['code'] ); ?>"
                                            aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s coupon', 'fls-checkout-flow' ), $discount_row['label'] ) ); ?>"
                                    >
                                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.99984 18.9582C5.05817 18.9582 1.0415 14.9415 1.0415 9.99984C1.0415 5.05817 5.05817 1.0415 9.99984 1.0415C14.9415 1.0415 18.9582 5.05817 18.9582 9.99984C18.9582 14.9415 14.9415 18.9582 9.99984 18.9582ZM9.99984 2.2915C5.74984 2.2915 2.2915 5.74984 2.2915 9.99984C2.2915 14.2498 5.74984 17.7082 9.99984 17.7082C14.2498 17.7082 17.7082 14.2498 17.7082 9.99984C17.7082 5.74984 14.2498 2.2915 9.99984 2.2915Z" fill="#E60023"/><path d="M7.64147 12.9831C7.48314 12.9831 7.3248 12.9248 7.1998 12.7998C6.95814 12.5581 6.95814 12.1581 7.1998 11.9165L11.9165 7.1998C12.1581 6.95814 12.5581 6.95814 12.7998 7.1998C13.0415 7.44147 13.0415 7.84147 12.7998 8.08314L8.08314 12.7998C7.96647 12.9248 7.7998 12.9831 7.64147 12.9831Z" fill="#E60023"/><path d="M12.3581 12.9831C12.1998 12.9831 12.0415 12.9248 11.9165 12.7998L7.1998 8.08314C6.95814 7.84147 6.95814 7.44147 7.1998 7.1998C7.44147 6.95814 7.84147 6.95814 8.08314 7.1998L12.7998 11.9165C13.0415 12.1581 13.0415 12.5581 12.7998 12.7998C12.6748 12.9248 12.5165 12.9831 12.3581 12.9831Z" fill="#E60023"/></svg>
                                    </button>
								<?php endif; ?>
                            </span>
                        </div>
					<?php endforeach; ?>

                    <div class="fls-order-details__vat-block">
                        <button
                                type="button"
                                class="fls-order-details__row fls-order-details__row--vat-toggle"
                                data-fls-vat-toggle
                                aria-expanded="false"
                        >
		                <span class="fls-order-details__vat-label">
			                <span><?php esc_html_e( 'VAT Breakdown', 'fls-checkout-flow' ); ?></span>
			                <span class="fls-order-details__vat-arrow" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6L8 10L12 6" stroke="#4B5563" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
		                </span>
                            <span><?php echo wp_kses_post( wc_price( $vat_data['total'] ) ); ?></span>
                        </button>

                        <div class="fls-order-details__vat-breakdown" data-fls-vat-breakdown style="display:none;">
                            <div class="fls-order-details__row fls-order-details__row--vat-meta">
                                <span><?php esc_html_e( 'VAT TAX', 'fls-checkout-flow' ); ?></span>
                                <span><?php echo wp_kses_post( wc_price( $vat_data['vat'] ) ); ?></span>
                            </div>

                            <div class="fls-order-details__row fls-order-details__row--vat-meta">
                                <span><?php esc_html_e( 'Total EX-VAT', 'fls-checkout-flow' ); ?></span>
                                <span><?php echo wp_kses_post( wc_price( $vat_data['total_ex_vat'] ) ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fls-order-details__row--total">
	                    <?php if ( $discount_total > 0 ) : ?>
                            <div class="fls-order-details__row fls-order-details__row--discount-total">
                                <span><?php esc_html_e( 'Discount total', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-order-details__row-value fls-order-details__row-value--discount">- <?php echo wp_kses_post( wc_price( $discount_total ) ); ?></span>
                            </div>
	                    <?php endif; ?>

                        <div class="fls-order-details__row">
                            <span><?php esc_html_e( 'Total', 'woocommerce' ); ?></span>
                            <strong><?php echo wp_kses_post( wc_price( $vat_data['total'] ) ); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="fls-order-details__assurance">
                    <span>
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" fill="white"/><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" stroke="#E5E7EB"/><path d="M15.3333 22.486C15.536 22.603 15.766 22.6646 16 22.6646C16.234 22.6646 16.464 22.603 16.6667 22.486L21.3333 19.8193C21.5358 19.7024 21.704 19.5343 21.821 19.3318C21.938 19.1294 21.9998 18.8998 22 18.666V13.3326C21.9998 13.0988 21.938 12.8692 21.821 12.6667C21.704 12.4643 21.5358 12.2962 21.3333 12.1793L16.6667 9.51262C16.464 9.39559 16.234 9.33398 16 9.33398C15.766 9.33398 15.536 9.39559 15.3333 9.51262L10.6667 12.1793C10.4642 12.2962 10.296 12.4643 10.179 12.6667C10.062 12.8692 10.0002 13.0988 10 13.3326V18.666C10.0002 18.8998 10.062 19.1294 10.179 19.3318C10.296 19.5343 10.4642 19.7024 10.6667 19.8193L15.3333 22.486Z" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 22.6667V16" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.1934 12.666L16 15.9993L21.8067 12.666" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 10.8457L19 14.279" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php esc_html_e( 'Free returns within 30 days', 'fls-checkout-flow' ); ?>
                    </span>
                    <span>
                         <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" fill="white"/><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" stroke="#E5E7EB"/><path d="M20.6673 11.334L11.334 20.6673" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.3327 13.9993C13.2532 13.9993 13.9993 13.2532 13.9993 12.3327C13.9993 11.4122 13.2532 10.666 12.3327 10.666C11.4122 10.666 10.666 11.4122 10.666 12.3327C10.666 13.2532 11.4122 13.9993 12.3327 13.9993Z" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M19.6667 21.3333C20.5871 21.3333 21.3333 20.5871 21.3333 19.6667C21.3333 18.7462 20.5871 18 19.6667 18C18.7462 18 18 18.7462 18 19.6667C18 20.5871 18.7462 21.3333 19.6667 21.3333Z" stroke="#4B5563" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php esc_html_e( 'Price match guarantee', 'fls-checkout-flow' ); ?>
                    </span>
                </div>
            </div>
        </div>
		<?php

		return ob_get_clean();
	}

	public function get_shipping_methods_html() {
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

	public function get_shipping_customer_section_html( $checkout ) {
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

	private function render_shipping_methods_markup() {
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

                    <div class="fls-delivery-method__date-row" data-fls-date-wrap="delivery">
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

                    <div class="fls-delivery-method__date-row" data-fls-date-wrap="pickup">
                        <label class="screen-reader-text" for="fls-pickup-date-display"><?php esc_html_e( 'Pickup date', 'fls-checkout-flow' ); ?></label>
                        <input id="fls-pickup-date-display" type="text" class="fls-delivery-method__date-input" data-fls-date-display="pickup" placeholder="<?php echo esc_attr__( 'Select Your Date', 'fls-checkout-flow' ); ?>" autocomplete="off" readonly />
                        <span class="fls-delivery-method__date-icon" aria-hidden="true">🗓</span>
                    </div>
                </div>
			<?php endif; ?>
        </div>
		<?php
	}

	private function render_shipping_rate_card( $shipping_rate_data, $mode ) {
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

	private function get_grouped_shipping_rates() {
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
					'package_index' => $package_index,
					'rate_id'       => $rate_id,
					'input_id'      => $input_id,
					'checked'       => $default_value === $rate_id,
					'requires_date' => $this->rate_requires_date( $rate ),
					'rate'          => $rate,
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

	private function rate_requires_date( $rate ) {
		$requires_date = 'local_pickup' === $rate->get_method_id();
		$label         = strtolower( wp_strip_all_tags( (string) $rate->get_label() ) );

		if ( false !== strpos( $label, 'date' ) ) {
			$requires_date = true;
		}

		return (bool) apply_filters( 'fls_checkout_rate_requires_date', $requires_date, $rate );
	}

	private function get_posted_checkout_value( $key ) {
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

	public function validate_step_two_fields() {
		if ( empty( $_POST['shipping_method'] ) || ! is_array( $_POST['shipping_method'] ) ) {
			return;
		}

		$shipping_method_values = array_map( 'sanitize_text_field', wp_unslash( $_POST['shipping_method'] ) );
		$chosen_rate_id         = reset( $shipping_method_values );
		$delivery_date          = isset( $_POST['fls_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) : '';
		$rate                   = $this->find_shipping_rate_by_id( $chosen_rate_id );

		if ( $rate && empty( $delivery_date ) ) {
			wc_add_notice( __( 'Please choose a date for your delivery method.', 'fls-checkout-flow' ), 'error' );
		}
	}

	public function save_step_two_fields( $order, $data ) {
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

	private function get_shipping_rate_cost_html( $rate ) {
		$cost  = (float) $rate->get_cost();
		$taxes = array_sum( (array) $rate->get_taxes() );
		$total = $cost + (float) $taxes;

		if ( $total <= 0 ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		return wc_price( $total );
	}

	public function get_payment_html( $checkout ) {
		ob_start();
		?>
        <div id="fls-checkout-payment" class="fls-checkout-payment">
			<?php woocommerce_checkout_payment(); ?>
        </div>
		<?php

		return ob_get_clean();
	}

	private function get_shipping_total_html() {
		if ( ! WC()->cart->needs_shipping() ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		$shipping_total = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();

		if ( WC()->cart->has_calculated_shipping() && $shipping_total <= 0 ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		if ( $shipping_total <= 0 ) {
			return esc_html__( 'Calculated at next step', 'fls-checkout-flow' );
		}

		return wc_price( $shipping_total );
	}

	private function get_total_tax_amount() {
		$totals = WC()->cart->get_totals();

		return isset( $totals['total_tax'] ) ? (float) $totals['total_tax'] : 0;
	}

	private function should_override_checkout() {
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
