<?php
/**
 * Plugin Name: FLS Checkout Flow
 * Description: Custom checkout flow foundation for Floorista. Overrides WooCommerce checkout and thank you page templates with a plugin structure.
 * Version: 2.0.0
 * Author: MeysamWeb & Ghaseminia
 * Text Domain: fls-checkout-flow
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FLS_CHECKOUT_FLOW_FILE', __FILE__ );
define( 'FLS_CHECKOUT_FLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLS_CHECKOUT_FLOW_URL', plugin_dir_url( __FILE__ ) );

require_once FLS_CHECKOUT_FLOW_PATH . 'includes/class-fls-checkout-flow.php';

FLS_Checkout_Flow::init();

/**
 * Returns the free shipping threshold from saved settings.
 * Result is cached in the WordPress object cache and cleared whenever
 * the post-price settings option is updated.
 */
function fls_get_free_shipping_threshold(): float {
	$cached = wp_cache_get( 'free_shipping_threshold', 'fls_checkout' );
	if ( false !== $cached ) {
		return (float) $cached;
	}

	$settings = get_option( 'fls_post_price_settings', [] );
	$value    = isset( $settings['free_shipping_threshold'] ) ? (float) $settings['free_shipping_threshold'] : 0.0;

	wp_cache_set( 'free_shipping_threshold', $value, 'fls_checkout' );

	return $value;
}

add_action( 'update_option_fls_post_price_settings', function () {
	wp_cache_delete( 'free_shipping_threshold', 'fls_checkout' );
} );
