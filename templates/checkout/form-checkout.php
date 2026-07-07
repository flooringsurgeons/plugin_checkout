<?php

/**
 * Custom checkout form.
 */

defined('ABSPATH') || exit;

$checkout = $checkout ?? WC()->checkout();
$flow     = FLS_Checkout_Flow::init();

do_action('woocommerce_before_checkout_form', $checkout);

if (! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in()) {
    echo esc_html__('You must be logged in to checkout.', 'woocommerce');
    return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout fls-checkout" action="<?= esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" aria-label="<?= esc_attr__('Checkout', 'woocommerce'); ?>">
    <?php do_action('fls_checkout_before_layout', $checkout); ?>

    <div class="fls-checkout-shell">
        <div class="fls-checkout-topbar">
            <a class="fls-checkout-topbar__back" href="<?= esc_url(wc_get_cart_url()); ?>">
                <span aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9.56945 18.82C9.37945 18.82 9.18945 18.75 9.03945 18.6L2.96945 12.53C2.67945 12.24 2.67945 11.76 2.96945 11.47L9.03945 5.4C9.32945 5.11 9.80945 5.11 10.0995 5.4C10.3895 5.69 10.3895 6.17 10.0995 6.46L4.55945 12L10.0995 17.54C10.3895 17.83 10.3895 18.31 10.0995 18.6C9.95945 18.75 9.75945 18.82 9.56945 18.82Z" fill="#292D32" />
                        <path d="M20.4999 12.75H3.66992C3.25992 12.75 2.91992 12.41 2.91992 12C2.91992 11.59 3.25992 11.25 3.66992 11.25H20.4999C20.9099 11.25 21.2499 11.59 21.2499 12C21.2499 12.41 20.9099 12.75 20.4999 12.75Z" fill="#292D32" />
                    </svg>
                </span>
                <span><?php esc_html_e('Back to basket', 'fls-checkout-flow'); ?></span>
            </a>

            <div class="fls-checkout-topbar__brand">
                <?= wp_kses_post($flow->get_checkout_logo_html()); ?>
            </div>
        </div>

        <?php if (! is_user_logged_in()) : ?>
            <div class="fls-checkout-mobile-login">
                <span class="fls-checkout-mobile-login__text"><?php esc_html_e('Log in to speed up checkout', 'fls-checkout-flow'); ?></span>
                <a class="fls-checkout-steps-nav__account" href="<?= esc_url($flow->get_checkout_account_url()); ?>">
                    <?= esc_html(__('Login/Register', 'fls-checkout-flow')); ?>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8.33398 14.1666L12.5007 9.99998L8.33398 5.83331" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12.5 10H2.5" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12.5 2.5H15.8333C16.2754 2.5 16.6993 2.67559 17.0118 2.98816C17.3244 3.30072 17.5 3.72464 17.5 4.16667V15.8333C17.5 16.2754 17.3244 16.6993 17.0118 17.0118C16.6993 17.3244 16.2754 17.5 15.8333 17.5H12.5" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            </div>
        <?php endif; ?>

        <div class="fls-checkout-nav-row">
            <div class="fls-checkout-steps-nav" data-fls-steps-nav>
                <button type="button" class="fls-checkout-steps-nav__item is-active" data-fls-step-trigger="1">
                    <span class="fls-checkout-steps-nav__dot"></span>
                    <svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7.87537 0C5.94252 0 4.37512 1.56666 4.37512 3.50025C4.37512 5.43383 5.94252 7.00049 7.87537 7.00049C9.80822 7.00049 11.3756 5.4331 11.3756 3.50025C11.3756 1.5674 9.80896 0 7.87537 0ZM7.87537 8.64024C5.24705 8.64024 0 9.95957 0 12.5776V14.7651H15.75V12.5776C15.75 9.95957 10.5037 8.64024 7.87463 8.64024H7.87537Z" fill="currentColor" />
                    </svg>
                    <span class="fls-checkout-steps-nav__label"><?php esc_html_e('Details', 'fls-checkout-flow'); ?></span>
                </button>

                <span class="fls-checkout-steps-nav__line" aria-hidden="true"></span>

                <button type="button" class="fls-checkout-steps-nav__item" data-fls-step-trigger="2">
                    <span class="fls-checkout-steps-nav__dot"></span>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15.9497 6.65002H2.68969C2.43969 6.65002 2.23969 6.85002 2.23969 7.10002V12.2C2.23969 12.3 2.31969 12.38 2.41969 12.38H5.83969C6.11969 12.38 6.33969 12.6 6.33969 12.88C6.33969 13.16 6.11969 13.38 5.83969 13.38L2.50969 13.41L2.49969 14.34L4.46969 14.39C4.74969 14.39 4.96969 14.61 4.96969 14.89C4.96969 15.17 4.74969 15.39 4.46969 15.39H0.929688C0.649687 15.39 0.429688 15.61 0.429688 15.89C0.429688 16.17 0.649687 16.39 0.929688 16.39L5.08969 16.38C5.24969 15.27 6.18969 14.42 7.33969 14.42C8.48969 14.42 9.42969 15.28 9.58969 16.38H15.9497C16.1997 16.38 16.3997 16.18 16.3997 15.93V7.10002C16.4097 6.85002 16.1997 6.65002 15.9497 6.65002Z"></path>
                        <path d="M6.84969 14.39H2.04969C1.76969 14.39 1.54969 14.17 1.54969 13.89C1.54969 13.61 1.76969 13.39 2.04969 13.39H6.84969C7.12969 13.39 7.34969 13.61 7.34969 13.89C7.34969 14.16 7.12969 14.39 6.84969 14.39Z"></path>
                        <path d="M23.5902 13.42L21.0902 9.18997C21.0102 9.04997 20.8602 8.96997 20.7002 8.96997H17.6102C17.3602 8.96997 17.1602 9.16997 17.1602 9.41997V15.44C17.1602 15.69 17.3602 15.89 17.6102 15.89H17.9502C18.2802 15.03 19.1002 14.42 20.0702 14.42C21.0402 14.42 21.8702 15.03 22.1902 15.89H23.1902C23.4402 15.89 23.6402 15.69 23.6402 15.44V13.65C23.6502 13.56 23.6302 13.49 23.5902 13.42ZM21.1202 12.78H18.7702C18.5202 12.78 18.3202 12.58 18.3202 12.33V10.34C18.3202 10.09 18.5202 9.88997 18.7702 9.88997H19.9402C20.1002 9.88997 20.2502 9.96997 20.3302 10.11L21.5002 12.1C21.6902 12.39 21.4802 12.78 21.1202 12.78Z"></path>
                        <path d="M7.33969 17.74C7.91407 17.74 8.37969 17.2744 8.37969 16.7C8.37969 16.1257 7.91407 15.66 7.33969 15.66C6.76531 15.66 6.29969 16.1257 6.29969 16.7C6.29969 17.2744 6.76531 17.74 7.33969 17.74Z" stroke-linecap="round" stroke-linejoin="round"></path>
                        <path d="M20.08 17.74C20.6544 17.74 21.12 17.2744 21.12 16.7C21.12 16.1257 20.6544 15.66 20.08 15.66C19.5057 15.66 19.04 16.1257 19.04 16.7C19.04 17.2744 19.5057 17.74 20.08 17.74Z" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <span class="fls-checkout-steps-nav__label"><?php esc_html_e('Shipping', 'fls-checkout-flow'); ?></span>
                </button>

                <span class="fls-checkout-steps-nav__line" aria-hidden="true"></span>

                <button type="button" class="fls-checkout-steps-nav__item" data-fls-step-trigger="3">
                    <span class="fls-checkout-steps-nav__dot"></span>
                    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M24.056 5H3.944C3.42842 5 2.93396 5.20089 2.56938 5.55846C2.20481 5.91604 2 6.40102 2 6.90671V21.0933C2 21.3437 2.05028 21.5916 2.14798 21.823C2.24567 22.0543 2.38887 22.2645 2.56938 22.4415C2.7499 22.6186 2.96421 22.759 3.20006 22.8549C3.43592 22.9507 3.68871 23 3.944 23H24.056C24.3113 23 24.5641 22.9507 24.7999 22.8549C25.0358 22.759 25.2501 22.6186 25.4306 22.4415C25.6111 22.2645 25.7543 22.0543 25.852 21.823C25.9497 21.5916 26 21.3437 26 21.0933V6.90671C26 6.65632 25.9497 6.40838 25.852 6.17705C25.7543 5.94571 25.6111 5.73552 25.4306 5.55846C25.2501 5.38141 25.0358 5.24096 24.7999 5.14514C24.5641 5.04932 24.3113 5 24.056 5ZM5.144 7.35658H22.856C23.0524 7.35727 23.2405 7.4341 23.3794 7.57031C23.5183 7.70652 23.5966 7.89107 23.5973 8.0837V10.027H4.39733V8.0837C4.39768 7.98787 4.41727 7.89304 4.45499 7.80464C4.4927 7.71624 4.5478 7.63598 4.61713 7.56846C4.68646 7.50095 4.76868 7.44748 4.85908 7.41113C4.94948 7.37477 5.0463 7.35624 5.144 7.35658ZM22.856 20.6434H5.144C4.9476 20.6427 4.75945 20.5659 4.62057 20.4297C4.4817 20.2935 4.40337 20.1089 4.40267 19.9163V13.6077H23.6027V19.9163C23.6023 20.0121 23.5827 20.107 23.545 20.1954C23.5073 20.2838 23.4522 20.364 23.3829 20.4315C23.3135 20.4991 23.2313 20.5525 23.1409 20.5889C23.0505 20.6252 22.9537 20.6438 22.856 20.6434Z" fill="currentColor" />
                    </svg>
                    <span class="fls-checkout-steps-nav__label"><?php esc_html_e('Payment', 'fls-checkout-flow'); ?></span>
                </button>
            </div>

            <?php if (!is_user_logged_in()): ?>
                <a class="fls-checkout-steps-nav__account" href="<?= esc_url($flow->get_checkout_account_url()); ?>">
                    <?= esc_html(__('Login/Register', 'fls-checkout-flow')); ?>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8.33398 14.1666L12.5007 9.99998L8.33398 5.83331" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12.5 10H2.5" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12.5 2.5H15.8333C16.2754 2.5 16.6993 2.67559 17.0118 2.98816C17.3244 3.30072 17.5 3.72464 17.5 4.16667V15.8333C17.5 16.2754 17.3244 16.6993 17.0118 17.0118C16.6993 17.3244 16.2754 17.5 15.8333 17.5H12.5" stroke="#454545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($checkout->get_checkout_fields()) : ?>
            <?php do_action('woocommerce_checkout_before_customer_details'); ?>

            <div class="fls-checkout-layout">
                <section class="fls-checkout-main">
                    <section class="fls-checkout-step is-active" data-fls-step="1">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="1">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e('1- Customer Details', 'fls-checkout-flow'); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e('Step 1 of 3', 'fls-checkout-flow'); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e('Edit', 'fls-checkout-flow'); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="1">
                            <div class="fls-checkout-step__section fls-checkout-step__section--billing">
                                <?php do_action('woocommerce_checkout_billing'); ?>
                            </div>

                            <div class="fls-checkout-step__section fls-checkout-step__section--shipping-fields">
                                <?php do_action('woocommerce_checkout_shipping'); ?>
                            </div>

                            <?php if (! is_user_logged_in()) : ?>
                                <div class="fls-account-section" data-fls-account-section style="display:none;">
                                    <h3 class="fls-account-section__title"><?php esc_html_e('Create an account to Support and Track your Orders', 'fls-checkout-flow'); ?></h3>
                                    <div class="fls-account-box" data-fls-account-box>
                                        <span class="fls-account-box__icon" aria-hidden="true">
                                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M18.0063 0H6.625C5.06869 0 3.8125 1.25625 3.8125 2.8125V29.1875C3.8125 30.7436 5.06869 32 6.625 32H25.375C26.9312 32 28.1875 30.7436 28.1875 29.1875V4.6875L18.0063 0Z" fill="url(#fls_acct_grad0)" />
                                                <path d="M12.25 26.3125C11.7323 26.3125 11.3125 26.7323 11.3125 27.25C11.3125 27.7677 11.7323 28.1875 12.25 28.1875C12.7677 28.1875 13.1875 27.7677 13.1875 27.25C13.1875 26.7323 12.7677 26.3125 12.25 26.3125ZM16 26.3125C15.4823 26.3125 15.0625 26.7323 15.0625 27.25C15.0625 27.7677 15.4823 28.1875 16 28.1875C16.5177 28.1875 16.9375 27.7677 16.9375 27.25C16.9375 26.7323 16.5177 26.3125 16 26.3125ZM19.75 26.3125C19.2323 26.3125 18.8125 26.7323 18.8125 27.25C18.8125 27.7677 19.2323 28.1875 19.75 28.1875C20.2677 28.1875 20.6875 27.7677 20.6875 27.25C20.6875 26.7323 20.2677 26.3125 19.75 26.3125ZM18.8125 8.5C18.8125 6.94913 17.5509 5.6875 16 5.6875C14.4491 5.6875 13.1875 6.94913 13.1875 8.5C13.1875 10.0509 14.4491 11.3125 16 11.3125C17.5509 11.3125 18.8125 10.0509 18.8125 8.5ZM12.25 16.9375H19.75C20.2682 16.9375 20.6875 16.5182 20.6875 16V14.125C20.6875 12.5741 19.4259 11.3125 17.875 11.3125H16H14.125C12.5741 11.3125 11.3125 12.5741 11.3125 14.125V16C11.3125 16.5182 11.7318 16.9375 12.25 16.9375ZM21.625 18.8125H10.375C8.82413 18.8125 7.5625 20.0741 7.5625 21.625C7.5625 23.1759 8.82413 24.4375 10.375 24.4375H21.625C23.1759 24.4375 24.4375 23.1759 24.4375 21.625C24.4375 20.0741 23.1759 18.8125 21.625 18.8125ZM25.375 0H18.0063C17.3875 2.49362 19.2437 4.6875 21.625 4.6875H28.1875V2.8125C28.1875 1.25625 26.9312 0 25.375 0Z" fill="url(#fls_acct_grad1)" />
                                                <defs>
                                                    <linearGradient id="fls_acct_grad0" x1="16" y1="32" x2="16" y2="0" gradientUnits="userSpaceOnUse">
                                                        <stop stop-color="#5558FF" />
                                                        <stop offset="1" stop-color="#00C0FF" />
                                                    </linearGradient>
                                                    <linearGradient id="fls_acct_grad1" x1="17.875" y1="28.1875" x2="17.875" y2="0" gradientUnits="userSpaceOnUse">
                                                        <stop stop-color="#ADDCFF" />
                                                        <stop offset="0.5028" stop-color="#EAF6FF" />
                                                        <stop offset="1" stop-color="#EAF6FF" />
                                                    </linearGradient>
                                                </defs>
                                            </svg>
                                        </span>
                                        <span class="fls-account-box__text" data-fls-account-text></span>
                                        <label class="fls-toggle fls-account-box__toggle" aria-label="<?php esc_attr_e('Account creation option', 'fls-checkout-flow'); ?>">
                                            <input type="checkbox" class="fls-toggle__input" name="fls_create_account" value="1" checked data-fls-account-toggle />
                                            <span class="fls-toggle__slider">
                                                <span class="fls-toggle__knob">
                                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                        <path d="M2 6L4.5 8.5L10 3" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="fls-checkout-step__actions">
                                <button type="button" class="fls-checkout-step__button" data-fls-step-next="2" disabled><?php esc_html_e('Continue to Shipping', 'fls-checkout-flow'); ?></button>
                            </div>
                        </div>
                    </section>

                    <section class="fls-checkout-step" data-fls-step="2">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="2">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e('2- Delivery Method', 'fls-checkout-flow'); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e('Step 2 of 3', 'fls-checkout-flow'); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e('Edit', 'fls-checkout-flow'); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="2">
                            <?= $flow->get_shipping_methods_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                            ?>
                        </div>
                    </section>

                    <section class="fls-checkout-step" data-fls-step="3">
                        <button type="button" class="fls-checkout-step__header" data-fls-step-trigger="3">
                            <span class="fls-checkout-step__title-wrap">
                                <span class="fls-checkout-step__title"><?php esc_html_e('3- Payment Method', 'fls-checkout-flow'); ?></span>
                                <span class="fls-checkout-step__header-side">
                                    <span class="fls-checkout-step__meta"><?php esc_html_e('Step 3 of 3', 'fls-checkout-flow'); ?></span>
                                    <span class="fls-checkout-step__edit"><?php esc_html_e('Edit', 'fls-checkout-flow'); ?></span>
                                </span>
                            </span>
                        </button>

                        <div class="fls-checkout-step__body" data-fls-step-body="3">
                            <div id="fls-checkout-payment" class="fls-checkout-payment">
                                <?php $flow->render_payment_html(); ?>
                            </div>
                        </div>
                    </section>
                </section>

                <aside class="fls-checkout-sidebar">
                    <?php do_action('floorista_before_checkout_totals'); ?>
                    <?= $flow->get_order_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                    ?>
                </aside>
            </div>
        <?php endif; ?>
    </div>

    <?php do_action('fls_checkout_after_layout', $checkout); ?>
</form>
<?php do_action('woocommerce_after_checkout_form', $checkout); ?>
