<?php

defined('ABSPATH') || exit;

use FLS_Checkout_Flow\Support\Template;

$current_step = 'details';
$steps = [
    'details' => [
        'label' => __('Details', 'fls-checkout-flow'),
        'icon'  => 'user',
    ],
    'shipping' => [
        'label' => __('Shipping', 'fls-checkout-flow'),
        'icon'  => 'truck',
    ],
    'payment' => [
        'label' => __('Payment', 'fls-checkout-flow'),
        'icon'  => 'card',
    ],
];

get_header('onlymobile');
?>

<main class="fls-checkout-flow fls-checkout-flow--checkout">
    <section class="fls-checkout-section">
        <div class="fls-checkout-container">
            <div class="fls-checkout-shell">
                <?php Template::render('parts/stepper', [
                    'steps'        => $steps,
                    'current_step' => $current_step,
                ]); ?>

                <div class="fls-checkout-layout">
                    <div class="fls-checkout-main">
                        <?php Template::render('steps/details', [
                            'current_step' => $current_step,
                        ]); ?>

                        <?php Template::render('steps/shipping', [
                            'current_step' => $current_step,
                        ]); ?>

                        <?php Template::render('steps/payment', [
                            'current_step' => $current_step,
                        ]); ?>
                    </div>

                    <aside class="fls-checkout-sidebar">
                        <?php Template::render('sidebar/order-details'); ?>
                    </aside>
                </div>
            </div>
        </div>
    </section>
</main>

<?php
get_footer('nofooter');
