<?php
defined( 'ABSPATH' ) || exit;

class FLS_Checkout_Flow {
	/** Orders placed after this UK hour are processed on the next working day. */
	const DISPATCH_CUTOFF_HOUR = 14;

	/** Working days between the processing day and the delivery date. */
	const WORKING_DAYS_LEAD = 2;

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
			'2.9.34'
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
			'2.8.55',
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
				'bankHolidays'      => $this->get_bank_holidays(),
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
		$fragments['#fls-checkout-flash']                          = $this->get_checkout_flash_html();

		return $fragments;
	}

	/**
	 * Hidden carrier element the JS reads after update_checkout to raise a one-off toast.
	 *
	 * Consuming the queued notice here is what makes the toast fire once per event
	 * rather than on every checkout refresh.
	 */
	public function get_checkout_flash_html() {
		$type    = '';
		$message = '';

		if ( WC()->session && 'pending' === WC()->session->get( 'fls_free_shipping_coupon_notice' ) ) {
			WC()->session->__unset( 'fls_free_shipping_coupon_notice' );

			$threshold = fls_get_free_shipping_threshold();
			$decimals  = ( fmod( $threshold, 1.0 ) > 0 ) ? wc_get_price_decimals() : 0;
			$formatted = html_entity_decode(
				wp_strip_all_tags( wc_price( $threshold, array( 'decimals' => $decimals ) ) ),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);

			$type    = 'notice';
			$message = sprintf(
				/* translators: %s: free shipping threshold amount, e.g. £500. */
				__( 'After applying your discount code, your order total fell below %s, so delivery charges now apply.', 'fls-checkout-flow' ),
				$formatted
			);
		}

		// WooCommerce skips replaceWith() for a fragment whose HTML is byte-identical
		// to the previous response, so a repeat of the same notice needs to differ.
		$sequence = '';

		if ( '' !== $message && WC()->session ) {
			$sequence = (int) WC()->session->get( 'fls_checkout_flash_sequence' ) + 1;
			WC()->session->set( 'fls_checkout_flash_sequence', $sequence );
		}

		return sprintf(
			'<div id="fls-checkout-flash" hidden data-fls-flash-seq="%1$s" data-fls-flash-type="%2$s" data-fls-flash-message="%3$s"></div>',
			esc_attr( $sequence ),
			esc_attr( $type ),
			esc_attr( $message )
		);
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

	/**
	 * Whether the cart is made up exclusively of free sample products.
	 *
	 * Sample-only orders skip delivery date selection — they are dispatched on a
	 * fixed two-working-day promise instead of a customer-chosen date.
	 *
	 * @return bool
	 */
	private function cart_has_only_samples() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['sample_product'] ) ) {
				return false;
			}
		}

		return true;
	}

	private function render_shipping_methods_markup() {
		$grouped_rates  = $this->get_grouped_shipping_rates();
		$delivery_rates = $grouped_rates['delivery'];
		$pickup_rates   = $grouped_rates['pickup'];
		$stored_date    = $this->get_posted_checkout_value( 'fls_delivery_date' );
		$stored_mode    = $this->get_posted_checkout_value( 'fls_delivery_mode' );
		$samples_only   = $this->cart_has_only_samples();

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
        <div class="fls-delivery-method" data-fls-delivery-method data-default-mode="<?php echo esc_attr( $active_mode ); ?>" data-samples-only="<?php echo $samples_only ? '1' : '0'; ?>">
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

						<?php if ( $samples_only ) : ?>
							<?php $this->render_sample_delivery_note(); ?>
						<?php else : ?>
                    <div class="fls-delivery-method__date-row" data-fls-date-wrap="delivery">
                        <label class="screen-reader-text" for="fls-delivery-date-display"><?php esc_html_e( 'Delivery date', 'fls-checkout-flow' ); ?></label>
                        <input id="fls-delivery-date-display" type="text" class="fls-delivery-method__date-input" data-fls-date-display="delivery" placeholder="<?php echo esc_attr__( 'Select Your Date', 'fls-checkout-flow' ); ?>" autocomplete="off" readonly />
                        <span class="fls-delivery-method__date-icon" aria-hidden="true">🗓</span>
                    </div>
						<?php endif; ?>
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

	/**
	 * Info banner shown instead of the date picker for sample-only orders.
	 */
	private function render_sample_delivery_note() {
		?>
        <div class="fls-delivery-method__note" data-fls-sample-note>
            <span class="fls-delivery-method__note-icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2.88001" y="2.88001" width="44.16" height="44.16" rx="6.72" fill="#389382"/>
                    <rect x="2.88001" y="2.88001" width="44.16" height="44.16" rx="6.72" stroke="#389382" stroke-width="1.92"/>
                    <path d="M31.0891 16.475H10.6466C10.2612 16.475 9.95286 16.775 9.95286 17.15V24.8C9.95286 24.95 10.0762 25.07 10.2304 25.07H15.5029C15.9345 25.07 16.2737 25.4 16.2737 25.82C16.2737 26.24 15.9345 26.57 15.5029 26.57L10.3691 26.615L10.3537 28.01L13.3908 28.085C13.8224 28.085 14.1616 28.415 14.1616 28.835C14.1616 29.255 13.8224 29.585 13.3908 29.585H7.93328C7.50161 29.585 7.16245 29.915 7.16245 30.335C7.16245 30.755 7.50161 31.085 7.93328 31.085L14.3466 31.07C14.5933 29.405 16.0424 28.13 17.8154 28.13C19.5883 28.13 21.0374 29.42 21.2841 31.07H31.0891C31.4745 31.07 31.7829 30.77 31.7829 30.395V17.15C31.7983 16.775 31.4745 16.475 31.0891 16.475Z" fill="white"/>
                    <path d="M17.0599 28.085H9.65993C9.22827 28.085 8.8891 27.755 8.8891 27.335C8.8891 26.915 9.22827 26.585 9.65993 26.585H17.0599C17.4916 26.585 17.8308 26.915 17.8308 27.335C17.8308 27.74 17.4916 28.085 17.0599 28.085Z" fill="white"/>
                    <path d="M42.8682 26.63L39.014 20.285C38.8907 20.075 38.6594 19.955 38.4128 19.955H33.649C33.2636 19.955 32.9553 20.255 32.9553 20.63V29.66C32.9553 30.035 33.2636 30.335 33.649 30.335H34.1732C34.6819 29.045 35.9461 28.13 37.4415 28.13C38.9369 28.13 40.2165 29.045 40.7098 30.335H42.2515C42.6369 30.335 42.9453 30.035 42.9453 29.66V26.975C42.9607 26.84 42.9298 26.735 42.8682 26.63ZM39.0603 25.67H35.4373C35.0519 25.67 34.7436 25.37 34.7436 24.995V22.01C34.7436 21.635 35.0519 21.335 35.4373 21.335H37.2411C37.4878 21.335 37.719 21.455 37.8423 21.665L39.6461 24.65C39.939 25.085 39.6153 25.67 39.0603 25.67Z" fill="white"/>
                    <path d="M17.8154 33.1101C18.7009 33.1101 19.4187 32.4116 19.4187 31.5501C19.4187 30.6885 18.7009 29.9901 17.8154 29.9901C16.9299 29.9901 16.212 30.6885 16.212 31.5501C16.212 32.4116 16.9299 33.1101 17.8154 33.1101Z" stroke="white" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M37.4567 33.1101C38.3422 33.1101 39.0601 32.4116 39.0601 31.5501C39.0601 30.6885 38.3422 29.9901 37.4567 29.9901C36.5712 29.9901 35.8534 30.6885 35.8534 31.5501C35.8534 32.4116 36.5712 33.1101 37.4567 33.1101Z" stroke="white" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M33.6 0L36.48 1.92H29.76L33.6 0Z" fill="#00B6FC"/>
                    <path d="M-1.25889e-07 37.44L1.92 29.76L1.92 40.32L-1.25889e-07 37.44Z" fill="#00B6FC"/>
                    <path d="M0 37.44L33.6 0H3.84C1.71923 0 0 1.71923 0 3.84001V37.44Z" fill="#72D8FF"/>
                    <path d="M5.48424 17.4034L1.88098 13.9171L4.33227 11.3836L5.12048 12.1462L3.61566 13.7015L4.23497 14.3007L5.58999 12.9002L6.3782 13.6629L5.02319 15.0633L6.43071 16.4252L5.48424 17.4034Z" fill="#0099D4"/>
                    <path d="M8.28364 14.5101L4.68038 11.0238L6.1852 9.46847C6.44395 9.20104 6.71903 9.01414 7.01044 8.90776C7.30186 8.80138 7.59296 8.781 7.88375 8.84661C8.17454 8.91221 8.44778 9.06872 8.70348 9.31612C8.96153 9.56578 9.1235 9.83377 9.1894 10.1201C9.25643 10.4052 9.23524 10.6947 9.12582 10.9885C9.01753 11.2812 8.83061 11.5647 8.56506 11.8392L7.66625 12.7682L6.90619 12.0328L7.61434 11.3009C7.72556 11.1859 7.80624 11.0744 7.8564 10.9662C7.90651 10.8557 7.9209 10.7481 7.89955 10.6434C7.87933 10.5376 7.8182 10.4353 7.71616 10.3366C7.61294 10.2367 7.50751 10.1778 7.39988 10.16C7.2922 10.1398 7.1834 10.1561 7.07346 10.2087C6.96349 10.259 6.85289 10.3416 6.74168 10.4565L6.40803 10.8014L9.23011 13.5318L8.28364 14.5101ZM8.67254 10.7986L11.198 11.498L10.1698 12.5606L7.66479 11.8402L8.67254 10.7986Z" fill="#0099D4"/>
                    <path d="M11.4856 11.2006L7.88238 7.71435L10.3949 5.11747L11.1832 5.88009L9.61706 7.49874L10.2364 8.09795L11.6731 6.61301L12.4613 7.37563L11.0246 8.86057L11.6439 9.45977L13.2032 7.84816L13.9914 8.61078L11.4856 11.2006Z" fill="#0099D4"/>
                    <path d="M14.4676 8.11859L10.8644 4.63231L13.3769 2.03543L14.1651 2.79805L12.599 4.41671L13.2183 5.01591L14.6551 3.53097L15.4433 4.29359L14.0066 5.77853L14.6259 6.37774L16.1852 4.76612L16.9734 5.52874L14.4676 8.11859Z" fill="#0099D4"/>
                    <path d="M5.07886 21.494C4.95249 21.3899 4.81844 21.3454 4.67672 21.3604C4.53614 21.3743 4.38924 21.4604 4.23604 21.6187C4.13844 21.7196 4.07075 21.8142 4.03296 21.9026C3.99514 21.9886 3.982 22.0667 3.99355 22.1369C4.00509 22.2072 4.03669 22.2684 4.08834 22.3206C4.12947 22.365 4.17614 22.3954 4.22835 22.4118C4.28052 22.426 4.33997 22.4267 4.40671 22.4141C4.47227 22.4003 4.54627 22.3731 4.6287 22.3325C4.71114 22.2919 4.80257 22.2373 4.90301 22.1687L5.25233 21.9344C5.48748 21.7758 5.70832 21.6591 5.91484 21.5841C6.12136 21.5091 6.31523 21.4719 6.49645 21.4724C6.67649 21.4717 6.845 21.5064 7.00198 21.5766C7.16009 21.6455 7.30833 21.7458 7.4467 21.8774C7.6825 22.1078 7.82576 22.3611 7.87648 22.6373C7.92721 22.9135 7.88984 23.202 7.76438 23.5031C7.64006 23.8029 7.43037 24.1053 7.13531 24.4103C6.8323 24.7234 6.52155 24.9519 6.20305 25.0957C5.88569 25.2382 5.56841 25.281 5.25121 25.2239C4.93398 25.1645 4.62407 24.9896 4.32149 24.6991L5.2203 23.7701C5.33623 23.8709 5.45556 23.933 5.57829 23.9564C5.70102 23.9797 5.82486 23.9656 5.94982 23.9139C6.07591 23.861 6.1991 23.7724 6.3194 23.6481C6.4204 23.5437 6.49205 23.4438 6.53433 23.3485C6.57662 23.2531 6.59248 23.1652 6.58192 23.0845C6.57135 23.0039 6.53731 22.9347 6.4798 22.8767C6.42354 22.8246 6.35786 22.7962 6.28276 22.7917C6.20762 22.7849 6.11562 22.8048 6.00674 22.8516C5.89669 22.8973 5.76231 22.9728 5.60362 23.0782L5.17894 23.3622C4.80105 23.6143 4.44209 23.7541 4.10206 23.7816C3.76198 23.8069 3.45176 23.6827 3.17139 23.4092C2.94153 23.1891 2.80056 22.9346 2.74847 22.6457C2.69634 22.3546 2.7283 22.0528 2.84433 21.7404C2.96151 21.4268 3.15798 21.1275 3.43375 20.8425C3.71519 20.5516 4.00729 20.3471 4.31006 20.229C4.61282 20.1109 4.90837 20.08 5.19669 20.1365C5.48499 20.1906 5.74758 20.3311 5.98447 20.558L5.07886 21.494Z" fill="#0099D4"/>
                    <path d="M9.63927 21.7378L8.6179 22.7934L6.16538 18.1178L7.45912 16.7806L12.2131 19.0776L11.1917 20.1332L7.81817 18.3952L7.79093 18.4234L9.63927 21.7378ZM8.02701 20.5594L9.94719 18.5748L10.6791 19.2829L8.75893 21.2675L8.02701 20.5594Z" fill="#0099D4"/>
                    <path d="M8.94563 15.2442L10.1236 14.0267L12.9748 15.1639L13.0157 15.1217L11.785 12.3095L12.963 11.092L16.5663 14.5783L15.6402 15.5354L13.5571 13.5199L13.5299 13.5481L14.8086 16.3386L14.2503 16.9157L11.405 15.7161L11.3777 15.7443L13.4749 17.7734L12.5489 18.7305L8.94563 15.2442Z" fill="#0099D4"/>
                    <path d="M17.0148 14.1147L13.4116 10.6284L14.9164 9.07312C15.1751 8.80569 15.4543 8.62276 15.7539 8.52433C16.0536 8.42589 16.3529 8.41345 16.6519 8.487C16.9509 8.56056 17.2282 8.72103 17.4839 8.96843C17.742 9.2181 17.9098 9.49176 17.9874 9.78942C18.0662 10.0859 18.0567 10.3867 17.9591 10.6919C17.8625 10.9959 17.6814 11.2851 17.4159 11.5596L16.5171 12.4886L15.757 11.7532L16.4652 11.0213C16.5764 10.9063 16.6512 10.7891 16.6896 10.6696C16.728 10.5477 16.7307 10.4288 16.6976 10.3128C16.6656 10.1956 16.5987 10.0876 16.4966 9.98888C16.3934 9.88902 16.2839 9.82619 16.168 9.8004C16.0521 9.7723 15.9351 9.78058 15.817 9.82524C15.6988 9.86758 15.5841 9.94623 15.4729 10.0612L15.1392 10.406L17.9613 13.1365L17.0148 14.1147Z" fill="#0099D4"/>
                    <path d="M20.1607 10.8633L16.5574 7.37706L17.5039 6.39883L20.3189 9.12248L21.7284 7.6657L22.5166 8.42832L20.1607 10.8633Z" fill="#0099D4"/>
                    <path d="M22.8992 8.03289L19.2959 4.54662L21.8085 1.94974L22.5967 2.71236L21.0306 4.33101L21.6499 4.93022L23.0867 3.44528L23.8749 4.2079L22.4381 5.69284L23.0575 6.29204L24.6167 4.68043L25.405 5.44305L22.8992 8.03289Z" fill="#0099D4"/>
                </svg>
            </span>
            <span class="fls-delivery-method__note-text">
				<?php esc_html_e( 'Your sample order will be delivered within the next two working days — no date selection needed.', 'fls-checkout-flow' ); ?>
            </span>
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

		// Sample-only carts never ask for a delivery date, so their delivery cards
		// keep showing the price instead of the calendar affordance.
		$samples_only = $this->cart_has_only_samples();

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
					'requires_date' => ( $samples_only && 'delivery' === $mode ) ? false : $this->rate_requires_date( $rate ),
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

	/**
	 * Bank holidays (England & Wales) excluded from working-day calculations.
	 *
	 * This is the single source of truth — the checkout script receives the same
	 * list through wp_localize_script so the calendar and the server-side date
	 * calculation can never drift apart.
	 *
	 * @return string[] Y-m-d dates.
	 */
	private function get_bank_holidays() {
		$holidays = array(
			// 2026.
			'2026-08-31', // Summer bank holiday.
			'2026-12-25', // Christmas Day.
			'2026-12-28', // Boxing Day (substitute day).
			// 2027.
			'2027-01-01', // New Year's Day.
			'2027-03-26', // Good Friday.
			'2027-03-29', // Easter Monday.
			'2027-05-03', // Early May bank holiday.
			'2027-05-31', // Spring bank holiday.
			'2027-08-30', // Summer bank holiday.
			'2027-12-27', // Christmas Day (substitute day).
			'2027-12-28', // Boxing Day (substitute day).
			// 2028.
			'2028-01-03', // New Year's Day (substitute day).
			'2028-04-14', // Good Friday.
			'2028-04-17', // Easter Monday.
			'2028-05-01', // Early May bank holiday.
			'2028-05-29', // Spring bank holiday.
			'2028-08-28', // Summer bank holiday.
			'2028-12-25', // Christmas Day.
			'2028-12-26', // Boxing Day.
		);

		return (array) apply_filters( 'fls_checkout_bank_holidays', $holidays );
	}

	private function is_working_day( DateTimeImmutable $date, array $holidays ) {
		if ( (int) $date->format( 'N' ) >= 6 ) {
			return false;
		}

		return ! in_array( $date->format( 'Y-m-d' ), $holidays, true );
	}

	/**
	 * The delivery date promised to customers who are not shown a calendar.
	 *
	 * Mirrors the calendar's minimum selectable date: orders placed after the
	 * cutoff (or on a non-working day) start processing on the next working day,
	 * then two further working days are added.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_promised_delivery_date() {
		$holidays = $this->get_bank_holidays();
		$now      = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/London' ) );
		$start    = $now->setTime( 0, 0 );

		if ( (int) $now->format( 'G' ) >= self::DISPATCH_CUTOFF_HOUR || ! $this->is_working_day( $start, $holidays ) ) {
			do {
				$start = $start->modify( '+1 day' );
			} while ( ! $this->is_working_day( $start, $holidays ) );
		}

		$date  = $start;
		$added = 0;

		while ( $added < self::WORKING_DAYS_LEAD ) {
			$date = $date->modify( '+1 day' );

			if ( $this->is_working_day( $date, $holidays ) ) {
				++$added;
			}
		}

		return $date;
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

			// Sample-only orders ship on a fixed two-working-day promise, so no
			// date is collected for them.
			if ( empty( $delivery_date ) && ! $this->cart_has_only_samples() ) {
				wc_add_notice( __( 'Please choose a date for your delivery method.', 'fls-checkout-flow' ), 'error' );
			}
		}
	}

	public function save_step_two_fields( $order, $data ) {
		$delivery_mode = ! empty( $_POST['fls_delivery_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_mode'] ) ) : '';
		$delivery_date = ! empty( $_POST['fls_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fls_delivery_date'] ) ) : '';

		// Sample-only deliveries are never shown a calendar, so stamp the date we
		// promised them in checkout. Without this the order would reach the theme
		// and the CRM with no fulfilment date at all.
		if ( empty( $delivery_date ) && 'pickup' !== $delivery_mode && $this->cart_has_only_samples() ) {
			$delivery_date = $this->get_promised_delivery_date()->format( 'F j, Y' );
		}

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
		WC()->session->set( 'fls_calculated_shipping_region', null );
		WC()->session->set( 'fls_delivery_available', null );
		WC()->session->set( 'fls_free_shipping', null );
		WC()->session->__unset( 'custom_shipping_choice' );
		WC()->session->__unset( 'custom_delivery_region' );
		WC()->session->__unset( 'custom_delivery_price' );
		WC()->session->__unset( 'custom_delivery_label' );
		WC()->session->__unset( 'custom_delivery_class' );
		// Drop any notice queued but never rendered, so a fresh page load cannot
		// surface a toast about a coupon the customer applied in an earlier visit.
		WC()->session->__unset( 'fls_free_shipping_coupon_notice' );

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

	const DEFAULT_SHIPPING_CLASS_SLUG = 'all-flooring';

	private function get_default_shipping_class_id() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$slug = apply_filters( 'fls_default_shipping_class_slug', self::DEFAULT_SHIPPING_CLASS_SLUG );
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );

		$cached = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;

		return $cached;
	}

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
				$shipping_class_id = $this->get_default_shipping_class_id();
			}

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
		if ( null === $this->get_eligible_free_shipping_region( $region ) ) {
			return false;
		}

		return $this->get_free_shipping_qualifying_subtotal( true ) >= fls_get_free_shipping_threshold();
	}

	/**
	 * Resolve the region and confirm the free-shipping threshold can apply to it.
	 *
	 * @param string|null $region Already-resolved UK region key, or null to resolve from session postcode.
	 * @return string|null Eligible region key, or null when the threshold cannot apply at all.
	 */
	private function get_eligible_free_shipping_region( $region = null ) {
		$settings       = $this->get_post_price_settings();
		$free_threshold = fls_get_free_shipping_threshold();

		if ( $free_threshold <= 0 || ! WC()->cart ) {
			return null;
		}

		// Check if the current region is eligible for free shipping.
		$free_regions = isset( $settings['free_shipping_regions'] ) ? (array) $settings['free_shipping_regions'] : array();

		if ( empty( $free_regions ) ) {
			return null;
		}

		if ( null === $region ) {
			$postcode = WC()->session ? WC()->session->get( 'fls_calculated_shipping_postcode' ) : '';

			if ( empty( $postcode ) ) {
				return null;
			}

			$region = $this->get_uk_region_for_postcode( $postcode );
		}

		if ( ! in_array( $region, $free_regions, true ) ) {
			return null;
		}

		return $region;
	}

	/**
	 * Sum the cart lines that count toward the free-shipping threshold.
	 *
	 * Only non-sample items count — sample products should never contribute to
	 * unlocking free shipping.
	 *
	 * @param bool $after_coupons Whether to measure the coupon-discounted amount.
	 *                            False measures the line subtotal, i.e. what the
	 *                            cart would be worth with no coupon applied.
	 * @return float
	 */
	private function get_free_shipping_qualifying_subtotal( $after_coupons = true ) {
		if ( ! WC()->cart ) {
			return 0.0;
		}

		// WooCommerce writes both keys during calculate_item_totals(), which runs
		// before shipping is calculated, so an applied coupon is already reflected
		// in line_total while line_subtotal still holds the pre-coupon amount.
		$total_key = $after_coupons ? 'line_total' : 'line_subtotal';
		$tax_key   = $after_coupons ? 'line_tax' : 'line_subtotal_tax';

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

			// A coupon that drops the cart below the threshold must also drop free
			// shipping, so the discounted amount is what the threshold is tested against.
			if ( isset( $cart_item[ $total_key ] ) ) {
				$line_amount = (float) $cart_item[ $total_key ];

				// Keep the same tax basis the threshold was configured against:
				// when catalog prices include tax, compare tax-inclusive amounts.
				if ( wc_prices_include_tax() ) {
					$line_amount += isset( $cart_item[ $tax_key ] ) ? (float) $cart_item[ $tax_key ] : 0.0;
				}

				$cart_subtotal += $line_amount;
				continue;
			}

			// Totals have not been calculated yet — fall back to the raw price.
			$cart_subtotal += (float) $product->get_price() * $quantity;
		}

		return $cart_subtotal;
	}

	/**
	 * Whether an applied coupon is the reason the cart no longer ships free.
	 *
	 * True only when the cart would still clear the threshold without its coupons
	 * but falls short with them — so a cart that never qualified, a region without
	 * free shipping, and a cart that shipped free for another reason (sample-only)
	 * are all excluded.
	 *
	 * @param string|null $region Already-resolved UK region key, or null to resolve from session postcode.
	 * @return bool
	 */
	private function cart_lost_free_shipping_to_coupon( $region = null ) {
		if ( ! WC()->cart || ! WC()->session ) {
			return false;
		}

		if ( empty( WC()->cart->get_applied_coupons() ) ) {
			return false;
		}

		// A customer collecting in store pays no delivery either way, so telling them
		// delivery charges now apply would be wrong. The session still holds the
		// selection made before this recalculation, which is the one to judge on.
		$chosen_methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );

		foreach ( $chosen_methods as $method_id ) {
			if ( false !== strpos( (string) $method_id, 'local_pickup' ) ) {
				return false;
			}
		}

		// Delivery has to be chargeable in the first place: an unserviced region has
		// no rate to lose, and a zero base amount (sample-only cart) ships free on
		// its own regardless of the threshold.
		if ( ! WC()->session->get( 'fls_delivery_available' ) ) {
			return false;
		}

		$amount = WC()->session->get( 'fls_calculated_shipping_amount' );

		if ( null === $amount || (float) $amount <= 0 ) {
			return false;
		}

		if ( null === $this->get_eligible_free_shipping_region( $region ) ) {
			return false;
		}

		$free_threshold = fls_get_free_shipping_threshold();

		return $this->get_free_shipping_qualifying_subtotal( false ) >= $free_threshold
			&& $this->get_free_shipping_qualifying_subtotal( true ) < $free_threshold;
	}

	/**
	 * Recalculate the free-shipping flag from the current cart and persist it.
	 *
	 * The flag used to be written only when the postcode was calculated, so any
	 * later cart change (most visibly applying or removing a coupon) left a stale
	 * "free shipping" behind. Recalculating at the point the rates are built keeps
	 * the flag in step with the discounted cart total.
	 *
	 * @return bool
	 */
	private function refresh_free_shipping_flag() {
		if ( ! WC()->session || ! WC()->cart ) {
			return false;
		}

		$postcode = WC()->session->get( 'fls_calculated_shipping_postcode' );
		$amount   = WC()->session->get( 'fls_calculated_shipping_amount' );
		$region   = WC()->session->get( 'fls_calculated_shipping_region' );

		// Nothing calculated yet — leave whatever the session holds untouched.
		// The region must come from the session too: resolving it from the postcode
		// costs a remote postcodes.io call, and this runs on every rate calculation.
		if ( empty( $postcode ) || null === $amount || empty( $region ) ) {
			return (bool) WC()->session->get( 'fls_free_shipping' );
		}

		$was_free = (bool) WC()->session->get( 'fls_free_shipping' );

		// A zero base amount means the cart ships free on its own (sample-only
		// carts), independently of the threshold.
		$is_free = ( (float) $amount <= 0 ) || $this->cart_qualifies_for_free_shipping( $region );

		// Queue the warning on the edge only — the moment free shipping is actually
		// lost, and only when a coupon is what pushed the cart under the threshold.
		// Reading it as a transition keeps the toast from re-firing on every later
		// update_checkout while the same coupon stays applied.
		if ( $was_free && ! $is_free && $this->cart_lost_free_shipping_to_coupon( $region ) ) {
			WC()->session->set( 'fls_free_shipping_coupon_notice', 'pending' );
		}

		WC()->session->set( 'fls_free_shipping', $is_free );

		return $is_free;
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
			WC()->session->set( 'fls_calculated_shipping_region', null );
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
		// Cached so later recalculations (e.g. after a coupon) can re-check the
		// free-shipping threshold without another postcodes.io lookup.
		WC()->session->set( 'fls_calculated_shipping_region', $resolved_region );
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

		// Recalculate rather than trusting the flag stored at postcode time — the
		// cart total may have moved since (e.g. a coupon was applied).
		$is_free   = $this->refresh_free_shipping_flag();
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

		// Populate the new customer's name and address meta from the order.
		// wc_create_new_customer() only creates the bare account, so without this
		// the user has no billing_first_name/first_name meta. Gateways that read
		// customer details from user meta (e.g. WooCommerce Stripe, which builds
		// the required Stripe customer "name" from user meta for logged-in orders)
		// would otherwise fail with "Missing required customer field: name".
		$this->populate_customer_meta_from_order( $user_id, $order );

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

	/**
	 * Copy the order's billing/shipping details onto a freshly created customer
	 * so gateways and the account profile can read them from user meta.
	 *
	 * @param int      $user_id New customer user ID.
	 * @param WC_Order $order   The order the account was created for.
	 */
	private function populate_customer_meta_from_order( $user_id, $order ) {
		if ( ! $user_id || ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		try {
			$customer = new WC_Customer( $user_id );
		} catch ( Exception $e ) {
			return;
		}

		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();

		// WP core name fields — Stripe falls back to these when billing_* is empty.
		$customer->set_first_name( $first_name );
		$customer->set_last_name( $last_name );

		// Billing address.
		$customer->set_billing_first_name( $first_name );
		$customer->set_billing_last_name( $last_name );
		$customer->set_billing_company( $order->get_billing_company() );
		$customer->set_billing_email( $order->get_billing_email() );
		$customer->set_billing_phone( $order->get_billing_phone() );
		$customer->set_billing_address_1( $order->get_billing_address_1() );
		$customer->set_billing_address_2( $order->get_billing_address_2() );
		$customer->set_billing_city( $order->get_billing_city() );
		$customer->set_billing_state( $order->get_billing_state() );
		$customer->set_billing_postcode( $order->get_billing_postcode() );
		$customer->set_billing_country( $order->get_billing_country() );

		// Shipping address (fall back to billing when no separate shipping name).
		$ship_first = $order->get_shipping_first_name() ? $order->get_shipping_first_name() : $first_name;
		$ship_last  = $order->get_shipping_last_name() ? $order->get_shipping_last_name() : $last_name;
		$customer->set_shipping_first_name( $ship_first );
		$customer->set_shipping_last_name( $ship_last );
		$customer->set_shipping_company( $order->get_shipping_company() );
		$customer->set_shipping_address_1( $order->get_shipping_address_1() );
		$customer->set_shipping_address_2( $order->get_shipping_address_2() );
		$customer->set_shipping_city( $order->get_shipping_city() );
		$customer->set_shipping_state( $order->get_shipping_state() );
		$customer->set_shipping_postcode( $order->get_shipping_postcode() );
		$customer->set_shipping_country( $order->get_shipping_country() );

		$customer->save();
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
