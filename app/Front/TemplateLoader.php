<?php

namespace FLS_Checkout_Flow\Front;

defined('ABSPATH') || exit;

class TemplateLoader
{
    public function __construct()
    {
        add_filter('template_include', [$this, 'override_template'], 9999);
    }

    public function override_template(string $template): string
    {
        if (!function_exists('is_checkout')) {
            return $template;
        }

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return $this->locate('templates/thankyou-page.php', $template);
        }

        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            return $this->locate('templates/order-pay-page.php', $template);
        }

        if (is_checkout()) {
            return $this->locate('templates/checkout-page.php', $template);
        }

        return $template;
    }

    private function locate(string $relative_path, string $fallback): string
    {
        $path = FLS_CHECKOUT_FLOW_PATH . ltrim($relative_path, '/');

        return file_exists($path) ? $path : $fallback;
    }
}
