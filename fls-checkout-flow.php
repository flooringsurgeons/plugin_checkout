<?php
/**
 * Plugin Name:       FLS Checkout Flow
 * Description:       Custom checkout flow foundation for Floorista. Overrides WooCommerce checkout, thank you, and order-pay templates with a plugin-driven structure.
 * Version:           2.0.0
 * Text Domain:       fls-checkout-flow
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

if (!defined('FLS_CHECKOUT_FLOW_FILE')) {
    define('FLS_CHECKOUT_FLOW_FILE', __FILE__);
}

if (!defined('FLS_CHECKOUT_FLOW_PATH')) {
    define('FLS_CHECKOUT_FLOW_PATH', plugin_dir_path(__FILE__));
}

if (!defined('FLS_CHECKOUT_FLOW_URL')) {
    define('FLS_CHECKOUT_FLOW_URL', plugin_dir_url(__FILE__));
}

spl_autoload_register(static function ($class) {
    $prefix = 'FLS_Checkout_Flow\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class);
    $file           = FLS_CHECKOUT_FLOW_PATH . 'app/' . $relative_path . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

if (!function_exists('fls_checkout_flow')) {
    function fls_checkout_flow(): FLS_Checkout_Flow\Core\Plugin
    {
        return FLS_Checkout_Flow\Core\Plugin::instance();
    }
}

fls_checkout_flow();
