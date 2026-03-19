<?php

defined('ABSPATH') || exit;

get_header('onlymobile');
?>

<main class="fls-checkout-flow fls-checkout-flow--order-pay">
    <section class="fls-checkout-hero">
        <div class="fls-checkout-container">
            <div class="fls-order-pay-card">
                <h1 class="fls-checkout-title"><?php esc_html_e('پرداخت سفارش', 'fls-checkout-flow'); ?></h1>
                <p class="fls-checkout-text"><?php esc_html_e('این صفحه برای order-pay است؛ یعنی وقتی ووکامرس از کاربر می‌خواهد هزینه یک سفارش موجود را پرداخت یا دوباره پرداخت کند. ظاهر صفحه از پلاگین می‌آید ولی محتوای اصلی پرداخت همان محتوای ووکامرس است.', 'fls-checkout-flow'); ?></p>
            </div>

            <div class="fls-order-pay-card" style="margin-top: 24px;">
                <?php
                while (have_posts()) :
                    the_post();
                    the_content();
                endwhile;
                ?>
            </div>
        </div>
    </section>
</main>

<?php
get_footer();
