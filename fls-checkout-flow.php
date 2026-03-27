<?php
/**
 * Plugin Name: FLS Checkout Flow
 * Description: Custom checkout for WooCommerce.
 * Version: 2.4.0
 * Author: MeysamWeb
 * Text Domain: fls-checkout-flow
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FLS_CHECKOUT_FLOW_FILE', __FILE__ );
define( 'FLS_CHECKOUT_FLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLS_CHECKOUT_FLOW_URL', plugin_dir_url( __FILE__ ) );

require_once FLS_CHECKOUT_FLOW_PATH . 'includes/class-fls-checkout-flow.php';

FLS_Checkout_Flow::init();
