<?php

namespace FLS_Checkout_Flow\Front;

defined('ABSPATH') || exit;

class Assets
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'register']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    public function register(): void
    {
        wp_register_style(
            'fls-checkout-flow',
            FLS_CHECKOUT_FLOW_URL . 'dist/css/frontend.css',
            [],
            $this->asset_version('dist/css/frontend.css')
        );

        if (file_exists(FLS_CHECKOUT_FLOW_PATH . 'vendor/preline/preline.js')) {
            wp_register_script(
                'fls-preline',
                FLS_CHECKOUT_FLOW_URL . 'vendor/preline/preline.js',
                [],
                $this->asset_version('vendor/preline/preline.js'),
	            ['in_footer' => true]
            );
        }

        wp_register_script(
            'fls-checkout-flow',
            FLS_CHECKOUT_FLOW_URL . 'dist/js/frontend.js',
            ['jquery'],
            $this->asset_version('dist/js/frontend.js'),
            ['in_footer' => true]
        );
    }

    public function enqueue(): void
    {
        if (!$this->should_enqueue()) {
            return;
        }

        wp_enqueue_style('fls-checkout-flow');

        if (wp_script_is('fls-preline', 'registered')) {
            wp_enqueue_script('fls-preline');
        }

        wp_enqueue_script('fls-checkout-flow');
    }

    private function should_enqueue(): bool
    {
        if (!function_exists('is_checkout')) {
            return false;
        }

        return is_checkout() || (function_exists('is_order_received_page') && is_order_received_page());
    }

    private function asset_version(string $relative_path): string
    {
        $absolute_path = FLS_CHECKOUT_FLOW_PATH . ltrim($relative_path, '/');

        if (!file_exists($absolute_path)) {
            return '0.2.0';
        }

        return (string) filemtime($absolute_path);
    }
}
