<?php

defined('ABSPATH') || exit;

get_header('onlymobile');
?>

<main class="fls-checkout-flow fls-checkout-flow--thankyou">
    <section class="fls-checkout-hero">
        <div class="fls-checkout-container">
            <div class="fls-thankyou-card">
                <h1 class="fls-checkout-title"><?php esc_html_e('Thank you Page', 'fls-checkout-flow'); ?></h1>
            </div>
        </div>
    </section>
</main>

<?php
get_footer();
