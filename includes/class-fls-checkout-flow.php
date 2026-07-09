<?php
defined( 'ABSPATH' ) || exit;

class FLS_Checkout_Flow {
	private static $instance = null;
	private $suppress_new_account_email = false;

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
		add_filter( 'woocommerce_package_rates', array( $this, 'inject_pickup_rate_if_missing' ), 998, 2 );
		add_filter( 'woocommerce_package_rates', array( $this, 'override_shipping_rates_with_post_price' ), 999, 2 );

		add_filter( 'woocommerce_order_button_html', array( $this, 'custom_payment_order_button_html' ) );
		add_filter( 'woocommerce_get_privacy_policy_text', array( $this, 'custom_checkout_privacy_policy_text' ), 10, 2 );
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', array( $this, 'custom_terms_checkbox_text' ) );

		add_action( 'wp_ajax_nopriv_fls_check_email_account', array( $this, 'ajax_check_email_account' ) );
		add_action( 'wp_ajax_fls_check_email_account', array( $this, 'ajax_check_email_account' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_create_account_on_checkout' ), 10, 3 );

		add_action( 'wp_ajax_nopriv_fls_save_checkout_draft', array( $this, 'ajax_save_checkout_draft' ) );
		add_action( 'wp_ajax_fls_save_checkout_draft', array( $this, 'ajax_save_checkout_draft' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_checkout_draft' ), 5, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'maybe_add_account_info_to_email' ), 10, 4 );
		add_filter( 'woocommerce_email_enabled_customer_new_account', array( $this, 'maybe_suppress_new_account_email_filter' ) );
		add_filter( 'woocommerce_order_received_verify_known_shoppers', array( $this, 'maybe_skip_order_received_verify' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'maybe_send_account_email_on_failed_order' ), 10, 2 );
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
			'2.9.28'
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
			'2.8.49',
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
				'account'    => array(
					'checkNonce' => wp_create_nonce( 'fls-check-email-account' ),
				),
				'draft'      => array(
					'saveNonce' => wp_create_nonce( 'fls-save-checkout-draft' ),
					'fields'    => $this->get_checkout_draft_for_js(),
				),
				'freeSample' => array(
					'enabled'   => class_exists( 'bleezlabs\floorista\includes\FreeSampleOrder' ) && \bleezlabs\floorista\includes\FreeSampleOrder::$is_enabled,
					'isPerUser' => class_exists( 'bleezlabs\floorista\includes\FreeSampleOrder' ) && 'per_user' === \bleezlabs\floorista\includes\FreeSampleOrder::$limit_type,
				),
				'i18n'       => array(
					'stepOneError'          => __( 'Please complete the required customer details before continuing.', 'fls-checkout-flow' ),
					'stepTwoError'          => __( 'Please choose a delivery option before continuing.', 'fls-checkout-flow' ),
					'stepTwoDateError'      => __( 'Please choose a date before continuing.', 'fls-checkout-flow' ),
					'chooseDate'            => __( 'Select your date', 'fls-checkout-flow' ),
					'deliveryNotAvailable'  => __( 'Delivery is not available in your area yet.', 'fls-checkout-flow' ),
					'deliveryNotAvailableSub' => __( 'Enter another postcode or select in-store pickup to continue.', 'fls-checkout-flow' ),
					'deliveryOptionsMissing' => __( 'Delivery options are not available for this postcode.', 'fls-checkout-flow' ),
					'discountApplied'       => __( 'Discount Applied', 'fls-checkout-flow' ),
					'couponRemoved'         => __( 'Coupon has been removed.', 'woocommerce' ),
					'couponEmpty'           => __( 'Please enter a discount code.', 'fls-checkout-flow' ),
					'couponApplyError'      => __( 'Something went wrong while applying the coupon.', 'fls-checkout-flow' ),
					'couponRemoveError'     => __( 'Something went wrong while removing the coupon.', 'fls-checkout-flow' ),
					'couponApplyLabel'      => __( 'Apply', 'woocommerce' ),
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
				'billing_address_1'  => array( 'width' => 'wide', 'placeholder' => __( 'House number and street name', 'fls-checkout-flow' ), 'priority' => 50 ),
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

			if ( ! empty( $cart_item['sample_product'] ) ) {
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

	private function get_free_sample_discount_total() {
		$total = 0.0;

		if ( ! WC()->cart ) {
			return $total;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['sample_product'] ) ) {
				continue;
			}

			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
			$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

			if ( ! $product || ! $product->exists() || $quantity <= 0 ) {
				continue;
			}

			$sample_price = isset( $cart_item['sample_price'] ) ? (float) $cart_item['sample_price'] : 0.0;

			if ( $sample_price <= 0 ) {
				$regular_price = (float) $product->get_regular_price();
				$current_price = (float) $product->get_price();
				$sample_price  = $regular_price > 0 ? $regular_price : $current_price;
			}

			if ( $sample_price > 0 ) {
				$total += $sample_price * $quantity;
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

		if ( empty( WC()->cart->get_fees() ) ) {
			WC()->cart->calculate_fees();
		}

		$has_free_sample_fee = false;

		foreach ( WC()->cart->get_fees() as $fee ) {
			$fee_total = ! empty( $fee->total ) ? (float) $fee->total : (float) $fee->amount;

			if ( $fee_total < 0 ) {
				if ( false !== strpos( strtolower( (string) $fee->name ), 'sample' ) ) {
					$has_free_sample_fee = true;
				}

				$rows[] = array(
					'label'     => $fee->name,
					'amount'    => abs( $fee_total ),
					'type'      => 'fee_discount',
					'removable' => false,
				);
				$total += abs( $fee_total );
			}
		}

		$free_sample_discount = $this->get_free_sample_discount_total();

		if ( $free_sample_discount > 0 && ! $has_free_sample_fee ) {
			$rows[] = array(
				'label'     => __( 'Free Sample', 'fls-checkout-flow' ),
				'amount'    => $free_sample_discount,
				'type'      => 'free_sample',
				'removable' => false,
			);
			$total += $free_sample_discount;
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

                    <button type="button" class="fls-order-details__coupon-button" data-fls-coupon-submit disabled>
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

		$product_discount_amount = 0.0;
		foreach ( $discount_rows as $row ) {
			if ( 'product_discount' === $row['type'] ) {
				$product_discount_amount = $row['amount'];
				break;
			}
		}
		$has_product_discount = $product_discount_amount > 0;

		if ( $has_product_discount ) {
			$cart = WC()->cart;
			if ( $cart->display_prices_including_tax() ) {
				$current_subtotal_float = $cart->get_subtotal() + $cart->get_subtotal_tax();
			} else {
				$current_subtotal_float = $cart->get_subtotal();
			}
			$original_subtotal_formatted = wc_price( $current_subtotal_float + $product_discount_amount );
		}

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
								<?php else : ?>
									<?php get_template_part( 'includes/admin/campaignManager/view/front/sections/campaign', 'badge', [ 'on_thumbnail' => true, 'product_id' => $product->get_id() ] ); ?>
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
                    <div class="fls-order-details__row<?php echo $has_product_discount ? ' fls-order-details__row--subtotal-discounted' : ''; ?>">
						<?php if ( $has_product_discount ) : ?>
                            <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                            <span class="fls-order-details__subtotal-prices">
                                <span class="fls-order-details__subtotal-original"><?php echo wp_kses_post( $original_subtotal_formatted ); ?></span>
                                <span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
                            </span>
						<?php else : ?>
                            <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                            <span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
						<?php endif; ?>
                    </div>

                    <?php $shipping_html = $this->get_shipping_total_html(); ?>
                    <?php if ( null !== $shipping_html ) : ?>
                    <div class="fls-order-details__row">
                        <span><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></span>
                        <span><?php echo wp_kses_post( $shipping_html ); ?></span>
                    </div>
                    <?php endif; ?>

					<?php foreach ( $discount_rows as $discount_row ) : ?>
						<?php if ( 'product_discount' === $discount_row['type'] ) : continue; endif; ?>
                        <div class="fls-order-details__row fls-order-details__row--discount-line">
                            <span><?php echo esc_html( $discount_row['label'] ); ?><?php if ( $has_product_discount && 'coupon' === $discount_row['type'] ) : ?> <span class="fls-order-details__subtotal-discount-tag">(<?php esc_html_e( 'Additional Discount', 'fls-checkout-flow' ); ?>)</span><?php endif; ?></span>

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
                        </button>

                        <div class="fls-order-details__vat-breakdown" data-fls-vat-breakdown style="display:none;">
                            <div class="fls-order-details__row fls-order-details__row--vat-meta">
                                <span><?php esc_html_e( 'VAT TAX', 'fls-checkout-flow' ); ?></span>
                                <span><?php echo wp_kses_post( wc_price( $vat_data['vat'] ) ); ?></span>
                            </div>

                            <div class="fls-order-details__row fls-order-details__row--vat-meta">
                                <span><?php esc_html_e( 'Total Exc. VAT', 'fls-checkout-flow' ); ?></span>
                                <span><?php echo wp_kses_post( wc_price( $vat_data['total_ex_vat'] ) ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fls-order-details__row--total">
	                    <?php if ( $discount_total > 0 ) : ?>
                            <div class="fls-order-details__row fls-order-details__row--discount-total">
                                <span><?php esc_html_e( 'Discount total', 'fls-checkout-flow' ); ?></span>
                                <span class="fls-order-details__row-value fls-order-details__row-value--discount">
                                    - <?php echo wp_kses_post( wc_price( $discount_total ) ); ?>
                                </span>
                            </div>
	                    <?php endif; ?>

                        <div class="fls-order-details__row">
                            <span><?php esc_html_e( 'Total', 'woocommerce' ); ?> <span class="fls-order-details__total-vat-label">(<?php esc_html_e( 'inc VAT', 'fls-checkout-flow' ); ?>)</span></span>
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
        <div id="fls-checkout-shipping-methods" class="fls-checkout-shipping-methods" data-needs-shipping="<?php echo WC()->cart->needs_shipping() ? '1' : '0'; ?>">
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

		// The session flag is the single source of truth for delivery availability.
		// When delivery is blocked, discard any stale WC-cached or race-condition
		// delivery rates so the panel renders ONLY the unavailability warning.
		if ( $delivery_blocked ) {
			$delivery_rates = array();
		}

		$active_mode = 'pickup' === $stored_mode && ! empty( $pickup_rates ) ? 'pickup' : 'delivery';

		// When delivery is blocked and no delivery rates exist, still keep
		// the Delivery tab visible (so we can show the warning).
		// Only force pickup when postcode has actually been calculated and confirmed no delivery rates —
		// before calculation ($has_calculated = false) we always default to delivery so the tab stays visible.
		if ( empty( $delivery_rates ) && ! $delivery_blocked && $has_calculated && ! empty( $pickup_rates ) ) {
			$active_mode = 'pickup';
		}

		$pickup_rate = ! empty( $pickup_rates ) ? $pickup_rates[0] : null;
		$pickup_data = $this->get_pickup_location_data( $pickup_rate ? $pickup_rate['rate'] : null );
		?>
        <div class="fls-delivery-method" data-fls-delivery-method data-default-mode="<?php echo esc_attr( $active_mode ); ?>">
            <input type="hidden" name="fls_delivery_mode" value="<?php echo esc_attr( $active_mode ); ?>" data-fls-delivery-mode-input />
            <input type="hidden" name="fls_delivery_date" value="<?php echo esc_attr( $stored_date ); ?>" data-fls-delivery-date-input />

            <div class="fls-delivery-method__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Delivery type', 'fls-checkout-flow' ); ?>">
				<?php if ( ! empty( $delivery_rates ) || $delivery_blocked || ! $has_calculated ) : ?>
                    <button type="button" class="fls-delivery-method__tab<?php echo 'delivery' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-tab="delivery" role="tab" aria-selected="<?php echo 'delivery' === $active_mode ? 'true' : 'false'; ?>">
                        <span><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
													<path d="M15.9497 6.65002H2.68969C2.43969 6.65002 2.23969 6.85002 2.23969 7.10002V12.2C2.23969 12.3 2.31969 12.38 2.41969 12.38H5.83969C6.11969 12.38 6.33969 12.6 6.33969 12.88C6.33969 13.16 6.11969 13.38 5.83969 13.38L2.50969 13.41L2.49969 14.34L4.46969 14.39C4.74969 14.39 4.96969 14.61 4.96969 14.89C4.96969 15.17 4.74969 15.39 4.46969 15.39H0.929688C0.649687 15.39 0.429688 15.61 0.429688 15.89C0.429688 16.17 0.649687 16.39 0.929688 16.39L5.08969 16.38C5.24969 15.27 6.18969 14.42 7.33969 14.42C8.48969 14.42 9.42969 15.28 9.58969 16.38H15.9497C16.1997 16.38 16.3997 16.18 16.3997 15.93V7.10002C16.4097 6.85002 16.1997 6.65002 15.9497 6.65002Z"/>
													<path d="M6.84969 14.39H2.04969C1.76969 14.39 1.54969 14.17 1.54969 13.89C1.54969 13.61 1.76969 13.39 2.04969 13.39H6.84969C7.12969 13.39 7.34969 13.61 7.34969 13.89C7.34969 14.16 7.12969 14.39 6.84969 14.39Z" />
													<path d="M23.5902 13.42L21.0902 9.18997C21.0102 9.04997 20.8602 8.96997 20.7002 8.96997H17.6102C17.3602 8.96997 17.1602 9.16997 17.1602 9.41997V15.44C17.1602 15.69 17.3602 15.89 17.6102 15.89H17.9502C18.2802 15.03 19.1002 14.42 20.0702 14.42C21.0402 14.42 21.8702 15.03 22.1902 15.89H23.1902C23.4402 15.89 23.6402 15.69 23.6402 15.44V13.65C23.6502 13.56 23.6302 13.49 23.5902 13.42ZM21.1202 12.78H18.7702C18.5202 12.78 18.3202 12.58 18.3202 12.33V10.34C18.3202 10.09 18.5202 9.88997 18.7702 9.88997H19.9402C20.1002 9.88997 20.2502 9.96997 20.3302 10.11L21.5002 12.1C21.6902 12.39 21.4802 12.78 21.1202 12.78Z" />
													<path d="M7.33969 17.74C7.91407 17.74 8.37969 17.2744 8.37969 16.7C8.37969 16.1257 7.91407 15.66 7.33969 15.66C6.76531 15.66 6.29969 16.1257 6.29969 16.7C6.29969 17.2744 6.76531 17.74 7.33969 17.74Z"  stroke-linecap="round" stroke-linejoin="round"/>
													<path d="M20.08 17.74C20.6544 17.74 21.12 17.2744 21.12 16.7C21.12 16.1257 20.6544 15.66 20.08 15.66C19.5057 15.66 19.04 16.1257 19.04 16.7C19.04 17.2744 19.5057 17.74 20.08 17.74Z"  stroke-linecap="round" stroke-linejoin="round"/>
												</svg></span>
                        <span><?php esc_html_e( 'Delivery', 'fls-checkout-flow' ); ?></span>
                    </button>
				<?php endif; ?>

				<?php if ( ! empty( $pickup_rates ) ) : ?>
                    <button type="button" class="fls-delivery-method__tab<?php echo 'pickup' === $active_mode ? ' is-active' : ''; ?>" data-fls-delivery-tab="pickup" role="tab" aria-selected="<?php echo 'pickup' === $active_mode ? 'true' : 'false'; ?>">
                        <span><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.7168 5.84586C6.7168 7.1645 7.78063 8.23761 9.09519 8.25613H9.16297C10.4773 8.23784 11.5414 7.1645 11.5414 5.84586C11.5414 4.51597 10.4592 3.43372 9.12896 3.43372C7.79869 3.43372 6.7168 4.51573 6.7168 5.84586ZM16.0003 11.7883H14.2852V13.2228C14.2852 13.285 14.2604 13.3446 14.2165 13.3886C14.1725 13.4326 14.1128 13.4573 14.0506 13.4573H12.8531C12.7909 13.4573 12.7312 13.4326 12.6873 13.3886C12.6433 13.3446 12.6186 13.285 12.6186 13.2228V11.7883H10.9034V16.5661H16.0003V11.7883Z" fill="currentColor"/>
                            <path d="M9.87738 8.72473H9.16393C9.15244 8.72473 9.14142 8.72637 9.12992 8.72637C9.11843 8.72637 9.10741 8.72473 9.09592 8.72473H8.38247C7.77577 8.72572 7.17584 8.85231 6.62047 9.09653C6.0651 9.34075 5.56632 9.6973 5.15554 10.1437C4.41091 10.9515 3.99842 12.0103 4.00047 13.1089V14.616L4 14.6184V15.6373C4.00025 15.8836 4.09817 16.1196 4.27228 16.2938C4.4464 16.4679 4.68248 16.5659 4.92875 16.5662H9.50611C10.0183 16.5662 10.4351 16.1495 10.4351 15.6373C10.4351 15.1251 10.0183 14.7087 9.50611 14.7087H6.49941C6.32748 14.7085 6.16264 14.6401 6.04107 14.5185C5.9195 14.397 5.85111 14.2322 5.85093 14.0602V12.3884C5.85093 12.3262 5.87564 12.2666 5.91962 12.2226C5.9636 12.1786 6.02326 12.1539 6.08546 12.1539C6.14766 12.1539 6.20732 12.1786 6.2513 12.2226C6.29528 12.2666 6.31999 12.3262 6.31999 12.3884V14.0602C6.31999 14.159 6.40044 14.2396 6.49941 14.2396H9.50611C9.84908 14.2397 10.1799 14.3665 10.4351 14.5956V11.5538C10.4351 11.4916 10.4598 11.432 10.5038 11.388C10.5478 11.344 10.6074 11.3193 10.6696 11.3193H13.8752C13.6843 10.8881 13.424 10.4911 13.1045 10.1442C12.6937 9.69765 12.1949 9.341 11.6394 9.09674C11.084 8.85248 10.4839 8.72589 9.87714 8.72497L9.87738 8.72473Z" fill="currentColor"/>
                            <path d="M13.0879 11.7886H13.8163V12.9886H13.0879V11.7886Z" fill="currentColor"/>
                        </svg></span>
                        <span><?php esc_html_e( 'Pickup', 'fls-checkout-flow' ); ?></span>
                    </button>
				<?php endif; ?>
            </div>

			<?php if ( ! empty( $delivery_rates ) || $delivery_blocked || ! $has_calculated ) : ?>
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
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.57465 3.21667L1.51631 15C1.37079 15.2529 1.29379 15.5389 1.29297 15.8304C1.29215 16.1219 1.36754 16.4083 1.51163 16.662C1.65572 16.9157 1.86342 17.1276 2.11384 17.2764C2.36425 17.4252 2.64864 17.5057 2.93965 17.5H17.0563C17.3473 17.5057 17.6317 17.4252 17.8821 17.2764C18.1325 17.1276 18.3402 16.9157 18.4843 16.662C18.6284 16.4083 18.7038 16.1219 18.703 15.8304C18.7022 15.5389 18.6252 15.2529 18.4796 15L11.4213 3.21667C11.2727 2.97138 11.0635 2.76865 10.814 2.62882C10.5645 2.48899 10.2836 2.41602 9.99798 2.41602C9.71235 2.41602 9.43143 2.48899 9.18197 2.62882C8.93251 2.76865 8.72324 2.97138 8.57465 3.21667Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 7.5V10.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 13.75H10.0083" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="fls-delivery-method__warning-text">
                            <strong><?php esc_html_e( 'Delivery is not available in your area yet.', 'fls-checkout-flow' ); ?></strong>
                            <span><?php esc_html_e( 'Enter another postcode or select in-store pickup to continue.', 'fls-checkout-flow' ); ?></span>
                        </span>
                    </div>
					<?php endif; ?>

					<?php if ( empty( $delivery_rates ) && ! $delivery_blocked ) : ?>
                    <div class="fls-delivery-method__warning" data-fls-delivery-warning>
                        <span class="fls-delivery-method__warning-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18.3337C14.6024 18.3337 18.3333 14.6027 18.3333 10.0003C18.3333 5.39795 14.6024 1.66699 10 1.66699C5.39762 1.66699 1.66666 5.39795 1.66666 10.0003C1.66666 14.6027 5.39762 18.3337 10 18.3337Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.66699V10.8337" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.99539 13.333H10.0029" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </span>
                        <span class="fls-delivery-method__warning-text">
                            <strong><?php esc_html_e( 'Delivery options are not ready yet.', 'fls-checkout-flow' ); ?></strong>
                            <span><?php esc_html_e( 'Go back and check your postcode so we can calculate the available delivery methods.', 'fls-checkout-flow' ); ?></span>
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
		$address = $this->get_pickup_address();
		$title   = $rate ? $this->get_rate_primary_label( $rate ) : __( 'Pick up address', 'fls-checkout-flow' );

		$data = array(
			'title'   => $title,
			'address' => $address,
			'map_url' => ! empty( $address ) ? 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed' : '',
		);

		return apply_filters( 'fls_checkout_pickup_location', $data, $rate );
	}

	private function get_pickup_address() {
		return '214A Dudley Road, Birmingham B63 3NJ';
	}

	private function parse_checkout_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return null;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$formats  = array( 'F j, Y', 'Y-m-d' );

		foreach ( $formats as $format ) {
			$datetime = DateTimeImmutable::createFromFormat( '!' . $format, $date, $timezone );
			$errors   = DateTimeImmutable::getLastErrors();

			if ( $datetime && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $datetime;
			}
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return null;
		}

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
	}

	private function is_weekend_checkout_date( $date ) {
		$datetime = $this->parse_checkout_date( $date );
		if ( ! $datetime ) {
			return false;
		}

		return in_array( (int) $datetime->format( 'w' ), array( 0, 6 ), true );
	}

	public function validate_step_two_fields() {
		if ( empty( $_POST['shipping_method'] ) || ! is_array( $_POST['shipping_method'] ) ) {
			if ( WC()->cart && WC()->cart->needs_shipping() ) {
				wc_add_notice( __( 'Please choose a delivery option before continuing.', 'fls-checkout-flow' ), 'error' );
			}
			return;
		}

		$shipping_method_values = array_map( 'sanitize_text_field', wp_unslash( $_POST['shipping_method'] ) );
		$chosen_rate_id         = reset( $shipping_method_values );
		$delivery_date          = isset( $_POST['fls_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) : '';
		$rate                   = $this->find_shipping_rate_by_id( $chosen_rate_id );
		$is_pickup              = $rate && 'local_pickup' === $rate->get_method_id();

		if ( ! empty( $delivery_date ) && $this->is_weekend_checkout_date( $delivery_date ) ) {
			wc_add_notice( __( 'Saturday and Sunday are not available. Please choose another date.', 'fls-checkout-flow' ), 'error' );
			return;
		}

		if ( ! $is_pickup ) {
			$postcode           = WC()->session ? WC()->session->get( 'fls_calculated_shipping_postcode' ) : '';
			$delivery_available = WC()->session ? WC()->session->get( 'fls_delivery_available' ) : false;

			if ( empty( $postcode ) || ! $delivery_available ) {
				wc_add_notice( __( 'The selected delivery option is no longer available. Please choose another option.', 'fls-checkout-flow' ), 'error' );
				return;
			}

			if ( empty( $delivery_date ) ) {
				wc_add_notice( __( 'Please choose a date for your delivery method.', 'fls-checkout-flow' ), 'error' );
			}
		}
	}

	public function save_step_two_fields( $order, $data ) {
		$delivery_mode = ! empty( $_POST['fls_delivery_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_mode'] ) ) : '';
		$delivery_date = ! empty( $_POST['fls_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) : '';

		if ( $delivery_mode ) {
			$order->update_meta_data( '_fls_delivery_mode', $delivery_mode );

			// Mirror into the theme meta so the order admin screen (and CRM) show the
			// shipping choice the same way legacy orders do.
			$order->update_meta_data( '_custom_shipping_choice', 'pickup' === $delivery_mode ? 'pickup' : 'delivery' );
		}

		if ( $delivery_date ) {
			$order->update_meta_data( '_fls_delivery_date', $delivery_date );

			// The theme renders the "Delivery Date" row from _delivery_date and the CRM
			// reads _requested_fulfilment_date, both expecting a Y-m-d value. Normalise
			// our display date ("F j, Y") so it lands in the same place as legacy orders.
			$timestamp  = strtotime( $delivery_date );
			$normalised = $timestamp ? gmdate( 'Y-m-d', $timestamp ) : $delivery_date;
			$order->update_meta_data( '_delivery_date', $normalised );
			$order->update_meta_data( '_requested_fulfilment_date', $normalised );
		}

		if ( ! is_user_logged_in() ) {
			$order->update_meta_data( '_fls_create_account', ! empty( $_POST['fls_create_account'] ) ? 1 : 0 );
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

	// -- Checkout Draft --------------------------------------------------------

	public function ajax_save_checkout_draft() {
		if ( ! check_ajax_referer( 'fls-save-checkout-draft', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		if ( ! WC()->session ) {
			wp_send_json_error( array( 'message' => 'No session' ) );
			return;
		}

		$allowed_fields = array(
			'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
			'billing_address_1', 'billing_city', 'billing_postcode', 'billing_country', 'billing_state',
			'ship_to_different_address',
			'shipping_first_name', 'shipping_last_name', 'shipping_address_1',
			'shipping_city', 'shipping_postcode', 'shipping_country', 'shipping_state',
		);

		$raw   = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$draft = array();

		foreach ( $allowed_fields as $field ) {
			if ( isset( $raw[ $field ] ) ) {
				$draft[ $field ] = sanitize_text_field( $raw[ $field ] );
			}
		}

		WC()->session->set( 'fls_checkout_draft', $draft );
		WC()->session->set( 'fls_checkout_draft_pending', true );

		wp_send_json_success();
	}

	private function get_checkout_draft_for_js() {
		if ( ! WC()->session ) {
			return null;
		}

		if ( ! WC()->session->get( 'fls_checkout_draft_pending' ) ) {
			return null;
		}

		$draft = WC()->session->get( 'fls_checkout_draft' );

		// One-shot: clear immediately so it never auto-fills on a future visit.
		WC()->session->set( 'fls_checkout_draft_pending', false );
		WC()->session->set( 'fls_checkout_draft', null );

		return ( ! empty( $draft ) && is_array( $draft ) ) ? $draft : null;
	}

	public function clear_checkout_draft( $order_id, $posted_data, $order ) {
		if ( ! WC()->session ) {
			return;
		}
		WC()->session->set( 'fls_checkout_draft', null );
		WC()->session->set( 'fls_checkout_draft_pending', false );
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
		WC()->session->__unset( 'custom_shipping_choice' );
		WC()->session->__unset( 'custom_delivery_region' );
		WC()->session->__unset( 'custom_delivery_price' );
		WC()->session->__unset( 'custom_delivery_label' );
		WC()->session->__unset( 'custom_delivery_class' );

		// Invalidate WC shipping rate transient cache so WC recalculates
		// rates from scratch on this fresh page load.
		$this->reset_shipping_package_cache();
	}

	private function reset_shipping_package_cache() {
		WC_Cache_Helper::get_transient_version( 'shipping', true );

		if ( ! WC()->session || ! WC()->cart ) {
			return;
		}

		foreach ( array_keys( WC()->cart->get_shipping_packages() ) as $package_index ) {
			WC()->session->__unset( 'shipping_for_package_' . $package_index );
		}
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

		// Determine whether the user has explicitly posted a delivery mode.
		// During update_order_review AJAX calls, form fields arrive inside
		// $_POST['post_data'], not as top-level POST vars — so we must use
		// get_posted_checkout_value() which checks both locations.
		$posted_mode = $this->get_posted_checkout_value( 'fls_delivery_mode' );

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
	 * @param string|null $region Already-resolved UK region key, or null to resolve from session postcode.
	 * @return bool
	 */
	private function cart_qualifies_for_free_shipping( $region = null ) {
		$settings       = $this->get_post_price_settings();
		$free_threshold = fls_get_free_shipping_threshold();

		if ( $free_threshold <= 0 || ! WC()->cart ) {
			return false;
		}

		// Check if the current region is eligible for free shipping.
		$free_regions = isset( $settings['free_shipping_regions'] ) ? (array) $settings['free_shipping_regions'] : array();

		if ( empty( $free_regions ) ) {
			return false;
		}

		if ( null === $region ) {
			$postcode = WC()->session ? WC()->session->get( 'fls_calculated_shipping_postcode' ) : '';

			if ( empty( $postcode ) ) {
				return false;
			}

			$region = $this->get_uk_region_for_postcode( $postcode );
		}

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
			WC()->session->__unset( 'custom_shipping_choice' );
			WC()->session->__unset( 'custom_delivery_region' );
			WC()->session->__unset( 'custom_delivery_price' );
			WC()->session->__unset( 'custom_delivery_label' );
			WC()->session->__unset( 'custom_delivery_class' );

			wp_send_json_error( array( 'message' => __( 'We could not validate this postcode right now. Please check the postcode and try again.', 'fls-checkout-flow' ), 'error_type' => 'service_error' ) );
			return;
		}

		// Store postcode in WC customer so standard WC shipping zones also update.
		WC()->customer->set_billing_postcode( $postcode );
		WC()->customer->set_shipping_postcode( $postcode );
		WC()->customer->save();

		$calculated_amount  = $this->calculate_post_price_shipping_cost( $postcode );
		$delivery_available = null !== $calculated_amount;
		$is_free            = $delivery_available && ( $calculated_amount <= 0 || $this->cart_qualifies_for_free_shipping( $resolved_region ) );

		WC()->session->set( 'fls_calculated_shipping_postcode', $postcode );
		WC()->session->set( 'fls_calculated_shipping_amount', $calculated_amount );
		WC()->session->set( 'fls_delivery_available', $delivery_available );
		WC()->session->set( 'fls_free_shipping', $is_free );

		// Invalidate WC shipping caches so the next update_checkout receives
		// rates built from the postcode result stored above.
		$this->reset_shipping_package_cache();
		if ( WC()->cart ) {
			WC()->cart->calculate_shipping();
		}

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

		$store_name = get_bloginfo( 'name' );
		$address    = $this->get_pickup_address();

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

		// Before a postcode calculation has been performed, suppress all delivery
		// rates so no shipping cost is added to the cart total. Keep only local
		// pickup rates so that tab remains visible.
		if ( empty( $postcode ) ) {
			$pickup_rates = array();
			foreach ( $rates as $rate_id => $rate ) {
				if ( 'local_pickup' === $rate->get_method_id() ) {
					$pickup_rates[ $rate_id ] = $rate;
				}
			}
			return $pickup_rates;
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
			// Free shipping only — do not show paid alternatives alongside it.
			$new_rates['fls_free_shipping'] = new WC_Shipping_Rate(
				'fls_free_shipping',
				__( 'Free Shipping', 'fls-checkout-flow' ),
				0,
				array(),
				'free_shipping'
			);
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

	/* -------------------------------------------------------
	 * Account management
	 * ------------------------------------------------------- */

	public function ajax_check_email_account() {
		check_ajax_referer( 'fls-check-email-account', 'nonce' );

		if ( is_user_logged_in() ) {
			wp_send_json_success( array( 'status' => 'logged_in' ) );
			return;
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'status' => 'invalid_email' ) );
			return;
		}

		if ( email_exists( $email ) ) {
			wp_send_json_success(
				array(
					'status'    => 'existing_account',
					'login_url' => $this->get_checkout_account_url(),
				)
			);
		} else {
			wp_send_json_success( array( 'status' => 'new_account' ) );
		}
	}

	public function maybe_suppress_new_account_email_filter( $enabled ) {
		return $this->suppress_new_account_email ? false : $enabled;
	}

	public function maybe_create_account_on_checkout( $order_id, $posted_data, $order ) {
		try {
			$this->do_create_account_on_checkout( $order_id, $order );
		} catch ( Exception $e ) {
			$this->suppress_new_account_email = false;
		}
	}

	private function do_create_account_on_checkout( $order_id, $order ) {
		if ( is_user_logged_in() ) {
			return;
		}

		if ( ! ( $order instanceof WC_Abstract_Order ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_fls_account_created' ) ) {
			return;
		}

		if ( $order->get_user_id() ) {
			return;
		}

		if ( ! (int) $order->get_meta( '_fls_create_account' ) ) {
			return;
		}

		$email = $order->get_billing_email();

		if ( empty( $email ) || ! is_email( $email ) || email_exists( $email ) ) {
			return;
		}

		$username = function_exists( 'wc_create_new_customer_username' )
			? wc_create_new_customer_username( $email )
			: sanitize_user( current( explode( '@', $email ) ), true );

		if ( username_exists( $username ) ) {
			$username = $username . '_' . time();
		}

		$this->suppress_new_account_email = true;
		$user_id = wc_create_new_customer( $email, $username, wp_generate_password( 12, false ) );
		$this->suppress_new_account_email = false;

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$order->set_customer_id( $user_id );

		$user      = get_user_by( 'id', $user_id );
		$reset_url = '';

		if ( $user ) {
			$reset_key = get_password_reset_key( $user );

			if ( ! is_wp_error( $reset_key ) ) {
				$reset_url = add_query_arg(
					array(
						'action' => 'rp',
						'key'    => $reset_key,
						'login'  => rawurlencode( $user->user_login ),
					),
					function_exists( 'wc_get_page_permalink' )
						? wc_get_page_permalink( 'myaccount' ) . 'lost-password/'
						: wp_login_url()
				);
			}
		}

		$order->update_meta_data( '_fls_account_created', 1 );
		$order->update_meta_data( '_fls_new_account_email', $email );
		$order->update_meta_data( '_fls_new_account_username', $username );
		$order->update_meta_data( '_fls_new_account_reset_url', $reset_url );
		$order->save();

		$this->send_new_account_email( $order, $email, $username, $reset_url );
	}

	private function send_new_account_email( $order, $account_email, $account_username, $reset_url ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your %s account details', 'fls-checkout-flow' ), $site_name );

		$set_password_button = '';
		if ( $reset_url ) {
			$set_password_button = '<p style="margin:14px 0 0;"><a href="' . esc_url( $reset_url ) . '" style="display:inline-block;padding:10px 20px;background:#389382;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">' . esc_html__( 'Set Your Password', 'fls-checkout-flow' ) . '</a></p>';
		}

		$message = '<div style="margin:0;padding:32px 0;background:#f3f4f6;font-family:sans-serif;">'
			. '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
			. '<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:560px;width:100%;">'
			. '<tr><td style="padding:32px;">'
			. '<h2 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#111827;">' . esc_html__( 'Your account has been created', 'fls-checkout-flow' ) . '</h2>'
			. '<p style="margin:0 0 20px;font-size:14px;color:#374151;">' . esc_html__( 'We created an account for you so you can track your orders and manage your purchases.', 'fls-checkout-flow' ) . '</p>'
			. '<div style="padding:16px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">'
			. '<table style="font-size:13px;color:#374151;border-collapse:collapse;width:100%;">'
			. '<tr><td style="padding:3px 12px 3px 0;font-weight:600;">' . esc_html__( 'Email:', 'fls-checkout-flow' ) . '</td><td style="padding:3px 0;">' . esc_html( $account_email ) . '</td></tr>'
			. '<tr><td style="padding:3px 12px 3px 0;font-weight:600;">' . esc_html__( 'Username:', 'fls-checkout-flow' ) . '</td><td style="padding:3px 0;">' . esc_html( $account_username ) . '</td></tr>'
			. '</table>'
			. $set_password_button
			. '</div>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr></table>'
			. '</div>';

		wp_mail( $account_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

		$order->update_meta_data( '_fls_account_email_sent', 1 );
		$order->save();
	}

	public function maybe_skip_order_received_verify( $verify ) {
		if ( ! $verify ) {
			return $verify;
		}

		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id ) {
			return $verify;
		}

		$order = wc_get_order( $order_id );

		if ( $order && (int) $order->get_meta( '_fls_account_created' ) === 1 ) {
			return false;
		}

		return $verify;
	}

	public function maybe_send_account_email_on_failed_order( $order_id, $order ) {
		if ( ! ( $order instanceof WC_Abstract_Order ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		if ( ! (int) $order->get_meta( '_fls_account_created' ) ) {
			return;
		}

		if ( (int) $order->get_meta( '_fls_account_email_sent' ) ) {
			return;
		}

		$account_email    = $order->get_meta( '_fls_new_account_email' );
		$account_username = $order->get_meta( '_fls_new_account_username' );
		$reset_url        = $order->get_meta( '_fls_new_account_reset_url' );

		if ( empty( $account_email ) ) {
			return;
		}

		$this->send_new_account_email( $order, $account_email, $account_username, $reset_url );
	}

	public function maybe_add_account_info_to_email( $order, $sent_to_admin, $plain_text, $email_object ) {
		if ( $sent_to_admin ) {
			return;
		}

		$email_id = isset( $email_object->id ) ? $email_object->id : '';
		if ( ! in_array( $email_id, array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' ), true ) ) {
			return;
		}

		if ( ! $order->get_meta( '_fls_account_created' ) ) {
			return;
		}

		$account_email    = $order->get_meta( '_fls_new_account_email' );
		$account_username = $order->get_meta( '_fls_new_account_username' );
		$reset_url        = $order->get_meta( '_fls_new_account_reset_url' );

		if ( empty( $account_email ) ) {
			return;
		}

		if ( (int) $order->get_meta( '_fls_account_email_sent' ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n\n" . esc_html__( '--- Your Account Details ---', 'fls-checkout-flow' ) . "\n";
			/* translators: %s: account email address */
			echo esc_html( sprintf( __( 'Email: %s', 'fls-checkout-flow' ), $account_email ) ) . "\n";
			/* translators: %s: account username */
			echo esc_html( sprintf( __( 'Username: %s', 'fls-checkout-flow' ), $account_username ) ) . "\n";
			if ( $reset_url ) {
				/* translators: %s: password set URL */
				echo esc_html( sprintf( __( 'Set your password: %s', 'fls-checkout-flow' ), $reset_url ) ) . "\n";
			}
		} else {
			?>
			<div style="margin-top:24px;padding:16px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
				<h3 style="margin:0 0 8px;font-size:15px;font-weight:700;color:#1e3a5f;"><?php esc_html_e( 'Your Account Has Been Created', 'fls-checkout-flow' ); ?></h3>
				<p style="margin:0 0 10px;font-size:13px;color:#374151;"><?php esc_html_e( 'To track your order, submit warranty or damage requests, and access your purchase history:', 'fls-checkout-flow' ); ?></p>
				<table style="font-size:13px;color:#374151;border-collapse:collapse;">
					<tr>
						<td style="padding:3px 12px 3px 0;font-weight:600;"><?php esc_html_e( 'Email:', 'fls-checkout-flow' ); ?></td>
						<td style="padding:3px 0;"><?php echo esc_html( $account_email ); ?></td>
					</tr>
					<tr>
						<td style="padding:3px 12px 3px 0;font-weight:600;"><?php esc_html_e( 'Username:', 'fls-checkout-flow' ); ?></td>
						<td style="padding:3px 0;"><?php echo esc_html( $account_username ); ?></td>
					</tr>
				</table>
				<?php if ( $reset_url ) : ?>
					<p style="margin:14px 0 0;">
						<a href="<?php echo esc_url( $reset_url ); ?>" style="display:inline-block;padding:10px 20px;background:#389382;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">
							<?php esc_html_e( 'Set Your Password', 'fls-checkout-flow' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		$order->update_meta_data( '_fls_account_email_sent', 1 );
		$order->save();
	}
}
