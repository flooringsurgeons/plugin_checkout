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
		add_action( 'template_redirect', array( $this, 'maybe_clear_shipping_session' ) );

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

		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_post_price_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_post_price', array( $this, 'render_post_price_settings_tab' ) );
		add_action( 'admin_init', array( $this, 'handle_post_price_settings_save' ) );

		add_action( 'wp_ajax_fls_calculate_shipping', array( $this, 'ajax_calculate_shipping' ) );
		add_action( 'wp_ajax_nopriv_fls_calculate_shipping', array( $this, 'ajax_calculate_shipping' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'inject_pickup_rate_if_missing' ), 98, 2 );
		add_filter( 'woocommerce_package_rates', array( $this, 'override_shipping_rates_with_post_price' ), 99, 2 );

		add_filter( 'woocommerce_order_button_html', array( $this, 'custom_payment_order_button_html' ) );
		add_action( 'woocommerce_checkout_before_terms_and_conditions', array( $this, 'render_payment_email_opt_in' ) );
		add_filter( 'woocommerce_get_privacy_policy_text', array( $this, 'custom_checkout_privacy_policy_text' ), 10, 2 );
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', array( $this, 'custom_terms_checkbox_text' ) );
	}

	public function handle_post_price_settings_save() {
		if ( ! isset( $_POST['fls_post_price_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fls_post_price_nonce'] ) ), 'fls-post-price-settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$regions = isset( $_POST['fls_post_price_regions'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['fls_post_price_regions'] ) )
			: array();

		$raw_prices      = isset( $_POST['fls_post_price_region_prices'] ) ? wp_unslash( (array) $_POST['fls_post_price_region_prices'] ) : array();
		$sanitized_prices = array();

		foreach ( $raw_prices as $key => $price ) {
			$sanitized_prices[ sanitize_key( $key ) ] = abs( (float) $price );
		}

		$free_shipping_threshold = isset( $_POST['fls_free_shipping_threshold'] )
			? abs( (float) $_POST['fls_free_shipping_threshold'] )
			: 0;

		$free_shipping_regions = isset( $_POST['fls_free_shipping_regions'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['fls_free_shipping_regions'] ) )
			: array();

		update_option(
			'fls_post_price_settings',
			array(
				'enabled_regions'         => $regions,
				'region_prices'           => $sanitized_prices,
				'free_shipping_threshold' => $free_shipping_threshold,
				'free_shipping_regions'   => $free_shipping_regions,
			)
		);
	}

	public function add_post_price_settings_tab( $tabs ) {
		$tabs['post_price'] = __( 'Post Price', 'fls-checkout-flow' );
		return $tabs;
	}

	public function render_post_price_settings_tab() {
		$view = FLS_CHECKOUT_FLOW_PATH . 'includes/admin/views/html-post-price-settings.php';
		if ( file_exists( $view ) ) {
			include $view;
		}
	}

	public function get_post_price_settings() {
		return (array) get_option( 'fls_post_price_settings', array() );
	}

	public function maybe_override_checkout_page_template( $template ) {
		if ( ! $this->should_override_checkout() ) {
			return $template;
		}

		$custom_template = FLS_CHECKOUT_FLOW_PATH . 'templates/checkout-page.php';

		return file_exists( $custom_template ) ? $custom_template : $template;
	}

	public function maybe_override_woocommerce_template( $template, $template_name, $template_path ) {
		if ( ! $this->should_override_checkout() && ! $this->should_override_thankyou() ) {
			return $template;
		}

		$allowed_templates = array(
			'checkout/form-checkout.php',
			'checkout/form-billing.php',
			'checkout/form-shipping.php',
			'checkout/thankyou.php',
		);

		if ( ! in_array( $template_name, $allowed_templates, true ) ) {
			return $template;
		}

		$custom_template = FLS_CHECKOUT_FLOW_PATH . 'templates/' . $template_name;

		return file_exists( $custom_template ) ? $custom_template : $template;
	}

	public function enqueue_assets(){
		if ( ! $this->should_override_checkout() && ! $this->should_override_thankyou() ) {
			return;
		}

		wp_enqueue_style(
			'fls-checkout-flow-flatpickr',
			FLS_CHECKOUT_FLOW_URL . 'assets/vendor/flatpickr/flatpickr.min.css',
			array(),
			'4.6.13'
		);

		wp_enqueue_style(
			'fls-checkout-flow',
			FLS_CHECKOUT_FLOW_URL . 'assets/css/checkout.css',
			array( 'fls-checkout-flow-flatpickr' ),
			'2.8.25'
		);

		wp_enqueue_script(
			'fls-checkout-flow-flatpickr',
			FLS_CHECKOUT_FLOW_URL . 'assets/vendor/flatpickr/flatpickr.min.js',
			array(),
			'4.6.13',
			['in_footer' => true]
		);

		wp_enqueue_script(
			'fls-checkout-flow',
			FLS_CHECKOUT_FLOW_URL . 'assets/js/checkout.js',
			array( 'jquery', 'wc-checkout', 'fls-checkout-flow-flatpickr' ),
			'2.8.7',
			true
		);

		$backorder_min_date = '';
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
				if ( ! ( $product instanceof WC_Product ) ) {
					continue;
				}
				if ( 'onbackorder' === $product->get_stock_status() ) {
					$avail_date = get_post_meta( $product->get_id(), 'woo_feed_availability_date', true );
					if ( $avail_date ) {
						if ( empty( $backorder_min_date ) || $avail_date > $backorder_min_date ) {
							$backorder_min_date = sanitize_text_field( $avail_date );
						}
					}
				}
			}
		}

		wp_localize_script(
			'fls-checkout-flow',
			'flsCheckoutFlow',
			array(
				'activeStep'        => 1,
				'backorderMinDate'  => $backorder_min_date,
				'coupon'     => array(
					'applyNonce'  => wp_create_nonce( 'apply-coupon' ),
					'removeNonce' => wp_create_nonce( 'remove-coupon' ),
				),
				'shipping'   => array(
					'calcNonce' => wp_create_nonce( 'fls-calculate-shipping' ),
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
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

		// WC()->cart->get_total() already includes shipping because the
		// override_shipping_rates_with_post_price filter replaces the rate
		// at calculation time. With proper cache invalidation this is
		// always in sync.
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

	private function has_coupon_discount_row( $discount_rows ) {
		if ( empty( $discount_rows ) || ! is_array( $discount_rows ) ) {
			return false;
		}

		foreach ( $discount_rows as $discount_row ) {
			if ( ! empty( $discount_row['type'] ) && 'coupon' === $discount_row['type'] ) {
				return true;
			}
		}

		return false;
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
		$has_coupon_discount      = $this->has_coupon_discount_row( $discount_rows );
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
                        <div class="fls-order-details__item<?php echo $is_sample ? ' fls-order-details__item--sample' : ''; ?>">
                            <div class="fls-order-details__item-thumb">
								<?php echo wp_kses_post( $thumbnail_html ); ?>
								<?php if ( $is_sample ) : ?>
                                    <span class="fls-order-details__sample-badge"><?php esc_html_e( 'Sample', 'fls-checkout-flow' ); ?></span>
								<?php endif; ?>
							</div>

                            <div class="fls-order-details__item-main">
                                <span class="fls-order-details__item-name"><?php echo esc_html( $product->get_name() ); ?></span>

								<?php if ( $is_sample ) : ?>
                                    <span class="fls-order-details__item-meta"><?php esc_html_e( 'Free Sample', 'fls-checkout-flow' ); ?></span>
								<?php elseif ( ! empty( $qty_label ) ) : ?>
                                    <span class="fls-order-details__item-meta"><?php echo esc_html( $qty_label ); ?></span>
								<?php endif; ?>
                            </div>

                            <span class="fls-order-details__item-price"><?php
								if ( $is_sample && isset( $cart_item['sample_price'] ) ) {
									$product->set_price( (float) $cart_item['sample_price'] );
								}
								echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) );
							?></span>
                        </div>
					<?php endforeach; ?>
                </div>

	            <?php echo $this->get_coupon_block_html(); ?>

                <div class="fls-order-details__totals">
                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
                    </div>

                    <?php $shipping_html = $this->get_shipping_total_html(); ?>
                    <?php if ( null !== $shipping_html ) : ?>
                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( $shipping_html ); ?></span>
                    </div>
                    <?php endif; ?>

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
	                    <?php if ( $discount_total > 0 && $has_coupon_discount ) : ?>
                            <div class="fls-order-details__row fls-order-details__row--discount-total">
                                <span><?php esc_html_e( 'Discount total', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-order-details__row-value fls-order-details__row-value--discount">
                                    - <?php echo wp_kses_post( wc_price( $discount_total ) ); ?>
                                </span>
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

		// Detect whether we have already calculated and delivery is unavailable.
		$has_calculated     = WC()->session && ! empty( WC()->session->get( 'fls_calculated_shipping_postcode' ) );
		$delivery_available = WC()->session ? WC()->session->get( 'fls_delivery_available' ) : null;
		$delivery_blocked   = $has_calculated && ! $delivery_available;

		$active_mode = 'pickup' === $stored_mode && ! empty( $pickup_rates ) ? 'pickup' : 'delivery';

		// When delivery is blocked and no delivery rates exist, still keep
		// the Delivery tab visible (so we can show the warning).
		if ( empty( $delivery_rates ) && ! $delivery_blocked && ! empty( $pickup_rates ) ) {
			$active_mode = 'pickup';
		}

		$pickup_rate = ! empty( $pickup_rates ) ? $pickup_rates[0] : null;
		$pickup_data = $this->get_pickup_location_data( $pickup_rate ? $pickup_rate['rate'] : null );
		?>
        <div class="fls-delivery-method" data-fls-delivery-method data-default-mode="<?php echo esc_attr( $active_mode ); ?>">
            <input type="hidden" name="fls_delivery_mode" value="<?php echo esc_attr( $active_mode ); ?>" data-fls-delivery-mode-input />
            <input type="hidden" name="fls_delivery_date" value="<?php echo esc_attr( $stored_date ); ?>" data-fls-delivery-date-input />

            <div class="fls-delivery-method__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Delivery type', 'fls-checkout-flow' ); ?>">
				<?php if ( ! empty( $delivery_rates ) || $delivery_blocked ) : ?>
                    <button type="button" class="fls-delivery-method__tab<?php echo 'delivery' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-tab="delivery" role="tab" aria-selected="<?php echo 'delivery' === $active_mode ? 'true' : 'false'; ?>">
                        <span aria-hidden="true">🚚</span>
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

			<?php if ( ! empty( $delivery_rates ) || $delivery_blocked ) : ?>
                <div class="fls-delivery-method__panel<?php echo 'delivery' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-panel="delivery">
					<?php if ( ! empty( $delivery_rates ) ) : ?>
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
					<?php endif; ?>

					<?php if ( $delivery_blocked ) : ?>
                    <div class="fls-delivery-method__warning" data-fls-delivery-warning>
                        <span class="fls-delivery-method__warning-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18.3337C14.6024 18.3337 18.3333 14.6027 18.3333 10.0003C18.3333 5.39795 14.6024 1.66699 10 1.66699C5.39762 1.66699 1.66666 5.39795 1.66666 10.0003C1.66666 14.6027 5.39762 18.3337 10 18.3337Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.66699V10.8337" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.99539 13.333H10.0029" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </span>
                        <span class="fls-delivery-method__warning-text">
                            <strong><?php esc_html_e( 'Delivery is not available in your area yet.', 'fls-checkout-flow' ); ?></strong>
                            <span><?php esc_html_e( 'Enter another postcode or select in-store pickup to continue.', 'fls-checkout-flow' ); ?></span>
                        </span>
                    </div>
					<?php endif; ?>
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

		$vat_data = $this->get_manual_vat_breakdown_data();
		$order->update_meta_data( '_fls_vat_breakdown', $vat_data );

		$discount_data = $this->get_order_details_discount_rows();
		$order->update_meta_data( '_fls_discount_rows', $discount_data['rows'] );
		$order->update_meta_data( '_fls_discount_total', (float) $discount_data['total'] );
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

	public function render_payment_html() {
		woocommerce_checkout_payment();
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

	/**
	 * Replace WooCommerce's default "Place order" button with Back + Complete Order buttons.
	 */
	public function custom_payment_order_button_html( $button_html ) {
		if ( ! $this->should_modify_payment_output() ) {
			return $button_html;
		}

		ob_start();
		?>
		<div class="fls-checkout-step__actions fls-checkout-step__actions--split">
			<button type="button" class="fls-checkout-step__button fls-checkout-step__button--secondary" data-fls-step-prev="2">
				<?php esc_html_e( 'Back', 'fls-checkout-flow' ); ?>
			</button>
			<button type="submit" class="fls-checkout-step__button" name="woocommerce_checkout_place_order" id="place_order" value="<?php esc_attr_e( 'Complete Order', 'fls-checkout-flow' ); ?>" data-value="<?php esc_attr_e( 'Complete Order', 'fls-checkout-flow' ); ?>">
				<?php esc_html_e( 'Complete Order', 'fls-checkout-flow' ); ?>
			</button>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Output the "Email me exclusive offers" opt-in checkbox before the terms and conditions.
	 */
	public function render_payment_email_opt_in() {
		if ( ! $this->should_modify_payment_output() ) {
			return;
		}
		?>
		<div class="fls-checkout-payment__email-optin">
			<label class="fls-checkout-payment__email-optin-label">
				<input type="checkbox" name="fls_email_optin" value="1" class="fls-checkout-payment__email-optin-checkbox" />
				<span><?php esc_html_e( 'Email me exclusive offers and updates (optional)', 'fls-checkout-flow' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Shorten the WooCommerce privacy policy text on checkout to match the design.
	 */
	public function custom_checkout_privacy_policy_text( $text, $type ) {
		if ( ! $this->should_modify_payment_output() ) {
			return $text;
		}

		if ( 'checkout' !== $type ) {
			return $text;
		}

		$privacy_page_id = wc_privacy_policy_page_id();
		$privacy_link    = $privacy_page_id
			? '<a href="' . esc_url( get_permalink( $privacy_page_id ) ) . '" class="woocommerce-privacy-policy-link" target="_blank">' . __( 'Privacy Policy', 'fls-checkout-flow' ) . '</a>'
			: __( 'Privacy Policy', 'fls-checkout-flow' );

		/* translators: %s privacy policy link */
		return sprintf( __( 'Your personal data will be used to process your order in accordance with our %s.', 'fls-checkout-flow' ), $privacy_link );
	}

	/**
	 * Change the terms and conditions checkbox text to match the design.
	 */
	public function custom_terms_checkbox_text( $text ) {
		if ( ! $this->should_modify_payment_output() ) {
			return $text;
		}

		$terms_page_id = wc_terms_and_conditions_page_id();
		$terms_link    = $terms_page_id
			? '<a href="' . esc_url( get_permalink( $terms_page_id ) ) . '" class="woocommerce-terms-and-conditions-link" target="_blank">' . __( 'Terms &amp; Conditions', 'fls-checkout-flow' ) . '</a>'
			: __( 'Terms &amp; Conditions', 'fls-checkout-flow' );

		/* translators: %s terms and conditions link */
		return sprintf( __( 'I agree to the %s', 'fls-checkout-flow' ), $terms_link );
	}

	public function maybe_clear_shipping_session() {
		if ( ! $this->should_override_checkout() ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( 'fls_calculated_shipping_postcode', '' );
		WC()->session->set( 'fls_calculated_shipping_amount', null );
		WC()->session->set( 'fls_delivery_available', null );
		WC()->session->set( 'fls_free_shipping', null );

		// Invalidate WC shipping rate transient cache so WC recalculates
		// rates from scratch on this fresh page load.
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	private function get_shipping_total_html() {
		if ( ! WC()->cart->needs_shipping() ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		if ( ! WC()->session ) {
			return null;
		}

		$postcode = WC()->session->get( 'fls_calculated_shipping_postcode' );

		// Before the user has entered a postcode, hide the shipping row entirely.
		if ( empty( $postcode ) ) {
			return null;
		}

		// Use the WC chosen shipping method to derive the shipping total for
		// the Order Details sidebar.  This respects both our injected rates
		// (free / standard / pickup) and any user selection change in step 2.
		$chosen_methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$packages       = WC()->shipping()->get_packages();

		// Determine whether the user has explicitly posted a delivery mode
		// (sent by the checkout form during update_order_review requests).
		$posted_mode = isset( $_POST['fls_delivery_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['fls_delivery_mode'] ) )
			: '';

		foreach ( $packages as $pkg_index => $package ) {
			$chosen_id = isset( $chosen_methods[ $pkg_index ] ) ? $chosen_methods[ $pkg_index ] : '';

			if ( ! empty( $chosen_id ) && ! empty( $package['rates'][ $chosen_id ] ) ) {
				$rate = $package['rates'][ $chosen_id ];

				if ( 'local_pickup' === $rate->get_method_id() ) {
					// If user explicitly chose pickup mode, hide the shipping row.
					if ( 'pickup' === $posted_mode ) {
						return null;
					}
					// Delivery mode but pickup is still the WC-chosen method
					// (transitional state just after postcode calculation).
					// Break out and use the session-based delivery price fallback.
					break;
				}

				$cost  = (float) $rate->get_cost();
				$taxes = array_sum( (array) $rate->get_taxes() );
				$total = $cost + (float) $taxes;

				return $total <= 0
					? esc_html__( 'Free', 'woocommerce' )
					: wc_price( $total );
			}
		}

		// Fallback: read from our session values.
		// If the user is explicitly in pickup mode, hide the shipping row.
		if ( 'pickup' === $posted_mode ) {
			return null;
		}

		$delivery_available = WC()->session->get( 'fls_delivery_available' );
		$calculated_amount  = WC()->session->get( 'fls_calculated_shipping_amount' );

		// Region not configured (and no calculated amount either) — show nothing.
		if ( ! $delivery_available && null === $calculated_amount ) {
			return null;
		}

		// Free shipping (threshold met or only samples).
		if ( WC()->session->get( 'fls_free_shipping' ) ) {
			return esc_html__( 'Free', 'woocommerce' );
		}

		if ( null !== $calculated_amount ) {
			$amount = (float) $calculated_amount;
			return $amount <= 0 ? esc_html__( 'Free', 'woocommerce' ) : wc_price( $amount );
		}

		// Last resort: use WC's own cart shipping total, which is already
		// correct after override_shipping_rates_with_post_price has run.
		$wc_shipping = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();

		if ( $wc_shipping > 0 ) {
			return wc_price( $wc_shipping );
		}

		// WC computed £0 — if any delivery method is chosen it means free shipping.
		$wc_chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( $wc_chosen as $method_id ) {
			if ( false === strpos( (string) $method_id, 'local_pickup' ) ) {
				return esc_html__( 'Free', 'woocommerce' );
			}
		}

		return null;
	}

	private function get_total_tax_amount() {
		$totals = WC()->cart->get_totals();

		return isset( $totals['total_tax'] ) ? (float) $totals['total_tax'] : 0;
	}

	/* -------------------------------------------------------
	 * Post-price shipping: calculation helpers
	 * ------------------------------------------------------- */

	/**
	 * Call the postcodes.io API to look up the country/region for a UK postcode.
	 *
	 * Returns one of our four internal region keys:
	 *   'england' | 'scotland' | 'wales' | 'northern_ireland'
	 *
	 * Returns null on any HTTP / parsing error so callers can fall back gracefully.
	 *
	 * @param string $postcode Raw postcode entered by the customer.
	 * @return string|null
	 */
	private function fetch_postcode_region_from_api( $postcode ) {
		$postcode = rawurlencode( strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) ) );

		if ( empty( $postcode ) ) {
			return null;
		}

		$url      = 'https://api.postcodes.io/postcodes/' . $postcode;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'user-agent' => 'FLS-Checkout/' . ( defined( 'FLS_CHECKOUT_FLOW_VERSION' ) ? FLS_CHECKOUT_FLOW_VERSION : '1.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $http_code ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['result']['country'] ) ) {
			return null;
		}

		// Map postcodes.io country string to our internal region key.
		$country_map = array(
			'England'          => 'england',
			'Scotland'         => 'scotland',
			'Wales'            => 'wales',
			'Northern Ireland' => 'northern_ireland',
		);

		$country = (string) $data['result']['country'];

		return isset( $country_map[ $country ] ) ? $country_map[ $country ] : null;
	}

	/**
	 * Resolve a UK postcode to one of our four internal region keys.
	 *
	 * Uses postcodes.io as the single source of truth.
	 *
	 * If API lookup fails or returns an unknown country, returns null so
	 * callers can surface a hard error to the user.
	 *
	 * @param string $postcode
	 * @return string|null  One of: 'england' | 'scotland' | 'wales' | 'northern_ireland'
	 */
	private function get_uk_region_for_postcode( $postcode ) {
		return $this->fetch_postcode_region_from_api( $postcode );
	}

	/**
	 * Calculate the post-price shipping cost for the current cart given a postcode.
	 *
	 * Returns the calculated amount (float) or null when the region is not
	 * enabled in the post-price settings (fall back to WooCommerce default).
	 *
	 * Uses the HIGHEST shipping-class price (not the sum) as the base cost.
	 * Sample products are excluded — they always ship free.
	 *
	 * @param string $postcode
	 * @return float|null
	 */
	private function calculate_post_price_shipping_cost( $postcode ) {
		if ( ! WC()->cart ) {
			return null;
		}

		$region   = $this->get_uk_region_for_postcode( $postcode );

		if ( null === $region ) {
			return null;
		}

		$settings = $this->get_post_price_settings();

		$enabled_regions = isset( $settings['enabled_regions'] ) ? (array) $settings['enabled_regions'] : array();

		if ( empty( $enabled_regions ) || ! in_array( $region, $enabled_regions, true ) ) {
			return null;
		}

		$region_prices  = isset( $settings['region_prices'] ) ? (array) $settings['region_prices'] : array();
		$max_shipping   = 0.0;
		$has_shippable  = false;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
			$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

			if ( ! $product || ! $product->exists() || $quantity <= 0 ) {
				continue;
			}

			// Skip sample products — they always ship free.
			if ( ! empty( $cart_item['sample_product'] ) ) {
				continue;
			}

			// This is a real shippable product regardless of whether a
			// shipping class is configured for it.
			$has_shippable = true;

			$shipping_class_id = $product->get_shipping_class_id();

			if ( ! $shipping_class_id ) {
				continue;
			}

			$price_key = $region . '_' . $shipping_class_id;

			if ( isset( $region_prices[ $price_key ] ) ) {
				$class_price = (float) $region_prices[ $price_key ];
				if ( $class_price > $max_shipping ) {
					$max_shipping = $class_price;
				}
			}
		}

		// Cart only contains samples (no shippable products) — shipping is free.
		if ( ! $has_shippable ) {
			return 0.0;
		}

		return $max_shipping;
	}

	/**
	 * Check whether the current cart qualifies for free shipping based on the
	 * Free Shipping Threshold configured in admin.
	 *
	 * @return bool
	 */
	private function cart_qualifies_for_free_shipping() {
		$settings       = $this->get_post_price_settings();
		$free_threshold = isset( $settings['free_shipping_threshold'] ) ? (float) $settings['free_shipping_threshold'] : 0;

		if ( $free_threshold <= 0 || ! WC()->cart ) {
			return false;
		}

		// Check if the current region is eligible for free shipping.
		$free_regions = isset( $settings['free_shipping_regions'] ) ? (array) $settings['free_shipping_regions'] : array();

		if ( empty( $free_regions ) ) {
			return false;
		}

		$postcode = WC()->session ? WC()->session->get( 'fls_calculated_shipping_postcode' ) : '';

		if ( empty( $postcode ) ) {
			return false;
		}

		$region = $this->get_uk_region_for_postcode( $postcode );

		if ( ! in_array( $region, $free_regions, true ) ) {
			return false;
		}

		// Only count non-sample items toward the free-shipping threshold.
		// Sample products should never contribute to unlocking free shipping.
		$cart_subtotal = 0.0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['sample_product'] ) ) {
				continue;
			}
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
			$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			if ( ! $product || ! $product->exists() || $quantity <= 0 ) {
				continue;
			}
			$cart_subtotal += (float) $product->get_price() * $quantity;
		}

		return $cart_subtotal >= $free_threshold;
	}

	/**
	 * AJAX: calculate shipping for a postcode and store it in the WC session.
	 *
	 * Returns delivery_available (whether the region is configured), is_free
	 * (whether the cart qualifies for free shipping), and the base amount.
	 */
	public function ajax_calculate_shipping() {
		check_ajax_referer( 'fls-calculate-shipping', 'nonce' );

		$postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';

		if ( empty( $postcode ) ) {
			wp_send_json_error( array( 'message' => __( 'Postcode is required.', 'fls-checkout-flow' ), 'error_type' => 'postcode_required' ) );
			return;
		}

		if ( ! WC()->session || ! WC()->customer ) {
			wp_send_json_error( array( 'message' => __( 'Session not available.', 'fls-checkout-flow' ), 'error_type' => 'session_error' ) );
			return;
		}

		$resolved_region = $this->get_uk_region_for_postcode( $postcode );

		if ( null === $resolved_region ) {
			WC()->session->set( 'fls_calculated_shipping_postcode', '' );
			WC()->session->set( 'fls_calculated_shipping_amount', null );
			WC()->session->set( 'fls_delivery_available', null );
			WC()->session->set( 'fls_free_shipping', null );

			wp_send_json_error( array( 'message' => __( 'We could not validate this postcode right now. Please check the postcode and try again.', 'fls-checkout-flow' ), 'error_type' => 'service_error' ) );
			return;
		}

		// Store postcode in WC customer so standard WC shipping zones also update.
		WC()->customer->set_billing_postcode( $postcode );
		WC()->customer->set_shipping_postcode( $postcode );
		WC()->customer->save();

		$calculated_amount  = $this->calculate_post_price_shipping_cost( $postcode );
		$delivery_available = null !== $calculated_amount;
		$is_free            = $delivery_available && ( $calculated_amount <= 0 || $this->cart_qualifies_for_free_shipping() );

		WC()->session->set( 'fls_calculated_shipping_postcode', $postcode );
		WC()->session->set( 'fls_calculated_shipping_amount', $calculated_amount );
		WC()->session->set( 'fls_delivery_available', $delivery_available );
		WC()->session->set( 'fls_free_shipping', $is_free );

		// Invalidate WC shipping rate transient cache so the next
		// update_checkout recalculates rates with our override filter.
		WC_Cache_Helper::get_transient_version( 'shipping', true );

		wp_send_json_success(
			array(
				'postcode'           => $postcode,
				'amount'             => $calculated_amount,
				'delivery_available' => $delivery_available,
				'is_free'            => $is_free,
			)
		);
	}

	/**
	 * Inject the post-price custom shipping rate when one has been calculated.
	 *
	 * @param WC_Shipping_Rate[] $rates
	 * @param array              $package
	 * @return WC_Shipping_Rate[]
	 */
	/**
	 * Always inject a free local pickup rate so the Pickup tab is visible
	 * regardless of whether a local_pickup shipping method exists in any zone.
	 */
	public function inject_pickup_rate_if_missing( $rates, $package ) {
		foreach ( $rates as $rate ) {
			if ( 'local_pickup' === $rate->get_method_id() ) {
				return $rates; // A real local_pickup already exists — nothing to do.
			}
		}

		$store_name  = get_bloginfo( 'name' );
		$store_parts = array_filter(
			array(
				get_option( 'woocommerce_store_address' ),
				get_option( 'woocommerce_store_city' ),
				get_option( 'woocommerce_store_postcode' ),
			)
		);
		$address = implode( ', ', $store_parts );

		// Build label in "Title | Description" format so the card splits it correctly.
		$label = ! empty( $address ) ? $store_name . ' | ' . $address : $store_name;

		$pickup_rate = new WC_Shipping_Rate(
			'fls_local_pickup',
			apply_filters( 'fls_checkout_pickup_rate_label', $label ),
			0,
			array(),
			'local_pickup'
		);

		$rates['fls_local_pickup'] = $pickup_rate;

		return $rates;
	}

	public function override_shipping_rates_with_post_price( $rates, $package ) {
		if ( ! WC()->session ) {
			return $rates;
		}

		$postcode = WC()->session->get( 'fls_calculated_shipping_postcode' );

		// Only override once a postcode calculation has been performed.
		if ( empty( $postcode ) ) {
			return $rates;
		}

		$delivery_available = WC()->session->get( 'fls_delivery_available' );
		$amount             = WC()->session->get( 'fls_calculated_shipping_amount' );

		// Preserve local pickup rates so the Pickup tab remains visible.
		$pickup_rates = array();
		foreach ( $rates as $rate_id => $rate ) {
			if ( 'local_pickup' === $rate->get_method_id() ) {
				$pickup_rates[ $rate_id ] = $rate;
			}
		}

		// If delivery is not available for this region, remove all delivery
		// rates and keep only pickup.
		if ( ! $delivery_available || null === $amount ) {
			return $pickup_rates;
		}

		// Build an outward-code label from the postcode (e.g. "EC1").
		$clean_postcode = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );
		$outward_code   = preg_replace( '/\d[A-Z]{2}$/', '', $clean_postcode );

		$is_free   = (bool) WC()->session->get( 'fls_free_shipping' );
		$new_rates = array();

		if ( $is_free ) {
			// Free shipping rate — pre-selected.
			$free_label = __( 'Free Shipping', 'fls-checkout-flow' );
			$free_rate  = new WC_Shipping_Rate(
				'fls_free_shipping',
				$free_label,
				0,
				array(),
				'free_shipping'
			);
			$new_rates['fls_free_shipping'] = $free_rate;

			// Also show the standard paid rate as an alternative.
			if ( (float) $amount > 0 ) {
				$std_label    = __( 'Standard Shipping', 'fls-checkout-flow' );
				$standard_rate = new WC_Shipping_Rate(
					'fls_post_price_shipping',
					$std_label,
					(float) $amount,
					array(),
					'flat_rate'
				);
				$new_rates['fls_post_price_shipping'] = $standard_rate;
			}
		} else {
			// Standard shipping with region description.
			$std_label    = __( 'Standard Shipping', 'fls-checkout-flow' );
			if ( ! empty( $outward_code ) ) {
				$std_label .= ' | ' . $outward_code;
			}
			$standard_rate = new WC_Shipping_Rate(
				'fls_post_price_shipping',
				$std_label,
				(float) $amount,
				array(),
				'flat_rate'
			);
			$new_rates['fls_post_price_shipping'] = $standard_rate;
		}

		return array_merge( $new_rates, $pickup_rates );
	}

	private function should_modify_payment_output() {
		if ( is_admin() ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			$wc_ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc-ajax'] ) ) : '';
			return in_array( $wc_ajax, array( 'update_order_review', 'checkout' ), true );
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

	private function should_override_thankyou() {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return false;
		}

		return true;
	}

	public function maybe_override_thankyou_page_template( $template ) {
		if ( ! $this->should_override_thankyou() ) {
			return $template;
		}

		$custom_template = FLS_CHECKOUT_FLOW_PATH . 'templates/thankyou-page.php';

		return file_exists( $custom_template ) ? $custom_template : $template;
	}

	public function save_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
		$product = $item->get_product();

		if ( ! empty( $values['sample_product'] ) ) {
			$item->add_meta_data( '_fls_is_sample_product', 'yes', true );
			return;
		}

		$item->add_meta_data( '_fls_is_sample_product', 'no', true );

		if ( ! $product ) {
			return;
		}

		$pack_data = $this->get_order_item_pack_data( $values, $product );

		if ( ! empty( $pack_data['packs'] ) ) {
			$item->add_meta_data( '_fls_pack_count', (int) $pack_data['packs'], true );
		}

		if ( isset( $pack_data['total'] ) && null !== $pack_data['total'] ) {
			$item->add_meta_data( '_fls_room_size', wc_format_decimal( (float) $pack_data['total'], 2 ), true );
		}
	}
}
