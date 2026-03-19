<?php

defined('ABSPATH') || exit;

get_header('onlymobile');
?>

<main class="fls-checkout-flow fls-checkout-flow--checkout">
    <section class="fls-checkout-hero">
        <div class="fls-checkout-container">
            <div class="fls-checkout-shell">
                <h1 class="fls-checkout-title"><?php esc_html_e('new checkout', 'fls-checkout-flow'); ?></h1>
                <div class="fls-steps" aria-label="Checkout steps">
                    <div class="fls-step-row">
                        <div class="fls-step-item">
                            <button type="button" class="fls-step-button is-active" data-fls-step="details" aria-current="step">
                                <span class="fls-step-indicator">
                                    <span class="fls-step-circle" aria-hidden="true"></span>
                                    <span class="fls-step-check" aria-hidden="true">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M16.25 5.625L7.65625 14.2188L3.75 10.3125" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </span>
                                <span class="fls-step-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12 12C14.2091 12 16 10.2091 16 8C16 5.79086 14.2091 4 12 4C9.79086 4 8 5.79086 8 8C8 10.2091 9.79086 12 12 12Z" fill="currentColor"/>
                                        <path d="M4 19C4 16.7909 5.79086 15 8 15H16C18.2091 15 20 16.7909 20 19V20H4V19Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                <span class="fls-step-label"><?php esc_html_e('Details', 'fls-checkout-flow'); ?></span>
                            </button>
                            <span class="fls-step-line" data-fls-step-line data-fls-step-line-index="0" aria-hidden="true"></span>
                        </div>

                        <div class="fls-step-item">
                            <button type="button" class="fls-step-button is-inactive" data-fls-step="shipping">
                                <span class="fls-step-indicator">
                                    <span class="fls-step-circle" aria-hidden="true"></span>
                                    <span class="fls-step-check" aria-hidden="true">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M16.25 5.625L7.65625 14.2188L3.75 10.3125" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </span>
                                <span class="fls-step-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M13 5H10V10H13V5Z" fill="currentColor"/>
                                        <path d="M17.5 10H15V7H17.1716C17.702 7 18.2107 7.21071 18.5858 7.58579L20.4142 9.41421C20.7893 9.78929 21 10.298 21 10.8284V15H19.75C19.1983 15 18.75 14.5517 18.75 14C18.75 12.7574 17.7426 11.75 16.5 11.75C15.2574 11.75 14.25 12.7574 14.25 14C14.25 14.5517 13.8017 15 13.25 15H9.75C9.19829 15 8.75 14.5517 8.75 14C8.75 12.7574 7.74264 11.75 6.5 11.75C5.25736 11.75 4.25 12.7574 4.25 14C4.25 14.5517 3.80171 15 3.25 15H3V9C3 7.89543 3.89543 7 5 7H13.5C14.3284 7 15 7.67157 15 8.5V10H17.5Z" fill="currentColor"/>
                                        <path d="M6.5 18.5C7.88071 18.5 9 17.3807 9 16C9 14.6193 7.88071 13.5 6.5 13.5C5.11929 13.5 4 14.6193 4 16C4 17.3807 5.11929 18.5 6.5 18.5Z" fill="currentColor"/>
                                        <path d="M16.5 18.5C17.8807 18.5 19 17.3807 19 16C19 14.6193 17.8807 13.5 16.5 13.5C15.1193 13.5 14 14.6193 14 16C14 17.3807 15.1193 18.5 16.5 18.5Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                <span class="fls-step-label"><?php esc_html_e('Shipping', 'fls-checkout-flow'); ?></span>
                            </button>
                            <span class="fls-step-line" data-fls-step-line data-fls-step-line-index="1" aria-hidden="true"></span>
                        </div>

                        <div class="fls-step-item">
                            <button type="button" class="fls-step-button is-inactive" data-fls-step="payment">
                                <span class="fls-step-indicator">
                                    <span class="fls-step-circle" aria-hidden="true"></span>
                                    <span class="fls-step-check" aria-hidden="true">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M16.25 5.625L7.65625 14.2188L3.75 10.3125" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                </span>
                                <span class="fls-step-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
                                        <path d="M3 10H21" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                </span>
                                <span class="fls-step-label"><?php esc_html_e('Payment', 'fls-checkout-flow'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="fls-step-panels">
                    <section class="fls-step-pane" data-fls-step-pane="details" aria-hidden="false">
                        <h2 class="fls-pane-title"><?php esc_html_e('Customer information', 'fls-checkout-flow'); ?></h2>
                        <div class="fls-pane-grid">
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('First name', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Meysam" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Last name', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Kiani" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Email address', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="email" placeholder="name@example.com" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Phone number', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="tel" placeholder="+1 (___) ___-____" />
                            </label>
                        </div>

                        <div class="fls-pane-actions">
                            <button type="button" class="fls-btn fls-btn-primary" data-fls-go-step="shipping"><?php esc_html_e('Continue to Shipping', 'fls-checkout-flow'); ?></button>
                        </div>
                    </section>

                    <section class="fls-step-pane is-hidden" data-fls-step-pane="shipping" aria-hidden="true">
                        <h2 class="fls-pane-title"><?php esc_html_e('Shipping', 'fls-checkout-flow'); ?></h2>

                        <div class="fls-pane-grid">
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Country', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Canada" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('City', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Peterborough" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Address', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="123 Example Street" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Postal code', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="K0K 0K0" />
                            </label>
                        </div>

                        <div class="fls-pane-actions">
                            <button type="button" class="fls-btn fls-btn-secondary" data-fls-go-step="details"><?php esc_html_e('Back', 'fls-checkout-flow'); ?></button>
                            <button type="button" class="fls-btn fls-btn-primary" data-fls-go-step="payment"><?php esc_html_e('Continue to Payment', 'fls-checkout-flow'); ?></button>
                        </div>
                    </section>

                    <section class="fls-step-pane is-hidden" data-fls-step-pane="payment" aria-hidden="true">
                        <h2 class="fls-pane-title"><?php esc_html_e('Payment', 'fls-checkout-flow'); ?></h2>

                        <div class="fls-pane-grid">
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Gateway title', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Credit Card / Klarna / PayPal" />
                            </label>
                            <label class="fls-field">
                                <span class="fls-field-label"><?php esc_html_e('Notes', 'fls-checkout-flow'); ?></span>
                                <input class="fls-field-control" type="text" placeholder="Native WooCommerce payment UI will be placed here" />
                            </label>
                        </div>

                        <div class="fls-pane-actions">
                            <button type="button" class="fls-btn fls-btn-secondary" data-fls-go-step="shipping"><?php esc_html_e('Back', 'fls-checkout-flow'); ?></button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>
</main>

<?php
get_footer('nofooter');
