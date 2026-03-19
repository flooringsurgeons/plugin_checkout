<?php

namespace FLS_Checkout_Flow\Core;

use FLS_Checkout_Flow\Front\Assets;
use FLS_Checkout_Flow\Front\TemplateLoader;

defined('ABSPATH') || exit;

class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot(): void
    {
        load_plugin_textdomain('fls-checkout-flow', false, dirname(plugin_basename(FLS_CHECKOUT_FLOW_FILE)) . '/languages');

        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        new Assets();
        new TemplateLoader();
    }

    public function woocommerce_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('FLS Checkout Flow requires WooCommerce to be active.', 'fls-checkout-flow')
            . '</p></div>';
    }

    private function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce');
    }
}
