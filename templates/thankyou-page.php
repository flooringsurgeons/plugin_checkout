<?php

defined('ABSPATH') || exit;

get_header('onlymobile');
?>

<main class="fls-checkout-flow fls-checkout-flow--thankyou">
    <section class="fls-checkout-hero">
        <div class="fls-checkout-container">
            <div class="fls-thankyou-card">
                <h1 class="fls-checkout-title"><?php esc_html_e('صفحه تشکر جدید', 'fls-checkout-flow'); ?></h1>
                <p class="fls-checkout-text"><?php esc_html_e('این تمپلیت برای توسعه thank you page آماده شده است و در مرحله بعد می‌توانیم شماره سفارش، خلاصه خرید و CTAهای موردنظرت را داخل آن پیاده کنیم.', 'fls-checkout-flow'); ?></p>
            </div>
        </div>
    </section>
</main>

<?php
get_footer();
