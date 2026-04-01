<?php
/**
 * Plugin Name: FLS Checkout Flow
 * Description: Custom checkout flow foundation for Floorista. Overrides WooCommerce checkout and thank you page templates with a plugin structure.
 * Version: 2.0.0
 * Author: MeysamWeb
 * Text Domain: fls-checkout-flow
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FLS_CHECKOUT_FLOW_FILE', __FILE__ );
define( 'FLS_CHECKOUT_FLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLS_CHECKOUT_FLOW_URL', plugin_dir_url( __FILE__ ) );

require_once FLS_CHECKOUT_FLOW_PATH . 'includes/class-fls-checkout-flow.php';

FLS_Checkout_Flow::init();
