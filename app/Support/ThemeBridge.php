<?php

namespace FLS_Checkout_Flow\Support;

defined('ABSPATH') || exit;

class ThemeBridge
{
    public static function render(string $theme_template, array $args = []): void
    {
        $located = locate_template([$theme_template]);

        if (!$located) {
            return;
        }

        if (!empty($args)) {
            extract($args, EXTR_SKIP);
        }

        include $located;
    }
}
