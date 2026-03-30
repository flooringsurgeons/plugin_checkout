(function ($) {
    const totalSteps = 3;
    const animationDuration = 240;
    let flatpickrInstances = {};

    /* ---------------------------------------------
     * Helpers
     * --------------------------------------------- */
    function getI18nMessage(key, fallback) {
        if (window.flsCheckoutFlow && window.flsCheckoutFlow.i18n && window.flsCheckoutFlow.i18n[key]) {
            return window.flsCheckoutFlow.i18n[key];
        }

        return fallback;
    }

    function getInitialStep() {
        if (window.flsCheckoutFlow && window.flsCheckoutFlow.activeStep) {
            return parseInt(window.flsCheckoutFlow.activeStep, 10) || 1;
        }

        return 1;
    }

    function getDefaultState() {
        return {
            activeStep: getInitialStep(),
            deliveryMode: 'delivery',
            dates: {
                delivery: '',
                pickup: ''
            },
            steps: {
                1: { available: true, completed: false },
                2: { available: false, completed: false },
                3: { available: false, completed: false }
            }
        };
    }

    function getState() {
        if (!window.flsCheckoutFlowState) {
            window.flsCheckoutFlowState = getDefaultState();
        }

        if (!window.flsCheckoutFlowState.steps) {
            window.flsCheckoutFlowState.steps = getDefaultState().steps;
        }

        if (!window.flsCheckoutFlowState.dates) {
            window.flsCheckoutFlowState.dates = {
                delivery: '',
                pickup: ''
            };
        }

        for (let step = 1; step <= totalSteps; step += 1) {
            if (!window.flsCheckoutFlowState.steps[step]) {
                window.flsCheckoutFlowState.steps[step] = {
                    available: step === 1,
                    completed: false
                };
            }
        }

        return window.flsCheckoutFlowState;
    }

    function getStepBody(step) {
        return $('[data-fls-step-body="' + step + '"]').first();
    }

    function getActiveStep() {
        return getState().activeStep || 1;
    }

    function getActiveDeliveryMode() {
        return getState().deliveryMode || $('[data-fls-delivery-method]').attr('data-default-mode') || 'delivery';
    }

    function getDateForMode(mode) {
        return $.trim(getState().dates[mode] || '');
    }

    function setDateForMode(mode, value) {
        const state = getState();
        state.dates[mode] = $.trim(value || '');
    }

    function needsShipping() {
        return $('[data-fls-shipping-card]').length > 0;
    }

    function getSelectedShippingCard(mode) {
        const selector = '[data-fls-shipping-card][data-mode="' + mode + '"] .shipping_method:checked';
        const $input = $(selector).first();

        return $input.length ? $input.closest('[data-fls-shipping-card]') : $();
    }

    function isFieldRequired($field) {
        return $field.hasClass('validate-required') || $field.find('[aria-required="true"], [required]').length > 0;
    }

    function isFieldVisible($field) {
        return $field.is(':visible') && !$field.closest('[hidden]').length;
    }

    function getFieldInput($field) {
        return $field.find('input, select, textarea').filter(function () {
            return $(this).attr('type') !== 'hidden' && !$(this).is(':disabled');
        }).first();
    }

    function inputHasValue($input) {
        if (!$input.length) {
            return true;
        }

        if ($input.is(':radio')) {
            return $('[name="' + $input.attr('name') + '"]:checked').length > 0;
        }

        if ($input.is(':checkbox')) {
            return $input.is(':checked');
        }

        return $.trim($input.val() || '') !== '';
    }

    function getWcAjaxUrl(endpoint) {
        if (typeof wc_checkout_params === 'undefined' || !wc_checkout_params.wc_ajax_url) {
            return '';
        }

        return wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', endpoint);
    }

    function getApplyCouponNonce() {
        if (window.flsCheckoutFlow && window.flsCheckoutFlow.coupon && window.flsCheckoutFlow.coupon.applyNonce) {
            return window.flsCheckoutFlow.coupon.applyNonce;
        }

        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.apply_coupon_nonce) {
            return wc_checkout_params.apply_coupon_nonce;
        }

        return '';
    }

    function getRemoveCouponNonce() {
        if (window.flsCheckoutFlow && window.flsCheckoutFlow.coupon && window.flsCheckoutFlow.coupon.removeNonce) {
            return window.flsCheckoutFlow.coupon.removeNonce;
        }

        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.remove_coupon_nonce) {
            return wc_checkout_params.remove_coupon_nonce;
        }

        return '';
    }

    function getCouponFeedbackBox() {
        return $('[data-fls-coupon-feedback]').first();
    }

    function clearCouponFeedback() {
        const $box = getCouponFeedbackBox();

        if (!$box.length) {
            return;
        }

        $box.removeClass('is-error is-success').empty();
    }

    function setCouponFeedback(type, message, allowHtml) {
        const $box = getCouponFeedbackBox();

        if (!$box.length) {
            return;
        }

        $box.removeClass('is-error is-success');

        if (type) {
            $box.addClass('is-' + type);
        }

        if (allowHtml) {
            $box.html(message || '');
        } else {
            $box.text(message || '');
        }
    }

    function extractNoticeMessage(messages, fallback) {
        if (!messages) {
            return fallback || '';
        }

        const $markup = $('<div />').html(messages);
        const $errorItem = $markup.find('.woocommerce-error li').first();

        if ($errorItem.length) {
            return $.trim($errorItem.text());
        }

        const $errorText = $markup.find('.woocommerce-error').first();
        if ($errorText.length) {
            return $.trim($errorText.text());
        }

        const $successItem = $markup.find('.woocommerce-message li').first();
        if ($successItem.length) {
            return $.trim($successItem.text());
        }

        const $successText = $markup.find('.woocommerce-message').first();
        if ($successText.length) {
            return $.trim($successText.text());
        }

        const plainText = $.trim($markup.text());
        return plainText || fallback || '';
    }

    function setButtonLoading($button, loading) {
        if (!$button.length) {
            return;
        }

        $button.prop('disabled', !!loading);
        $button.toggleClass('is-loading', !!loading);
    }

    /* ---------------------------------------------
     * Delivery mode and shipping UI
     * --------------------------------------------- */
    function setActiveDeliveryMode(mode) {
        const state = getState();
        state.deliveryMode = mode;

        $('[data-fls-delivery-mode-input]').val(mode);

        $('[data-fls-delivery-tab]').each(function () {
            const $tab = $(this);
            const currentMode = $tab.attr('data-fls-delivery-tab');
            const isActive = currentMode === mode;

            $tab.toggleClass('is-active', isActive).attr('aria-selected', isActive ? 'true' : 'false');
        });

        $('[data-fls-delivery-panel]').each(function () {
            const $panel = $(this);
            const currentMode = $panel.attr('data-fls-delivery-panel');
            const isActive = currentMode === mode;

            $panel.toggleClass('is-active', isActive);

            if (isActive) {
                if (!$panel.is(':visible')) {
                    $panel.stop(true, true).hide().slideDown(animationDuration, function () {
                        $panel.css('display', 'grid');
                    });
                } else {
                    $panel.css('display', 'grid');
                }
            } else {
                $panel.stop(true, true).slideUp(animationDuration);
            }
        });
    }

    function syncSelectedShippingCards() {
        $('[data-fls-shipping-card]').each(function () {
            const $card = $(this);
            const checked = $card.find('.shipping_method').is(':checked');

            $card.toggleClass('is-selected', checked);
        });
    }

    function ensureSelectedRateForMode(mode) {
        const $selected = getSelectedShippingCard(mode);

        if ($selected.length) {
            return;
        }

        const $first = $('[data-fls-shipping-card][data-mode="' + mode + '"]').first();

        if ($first.length) {
            $first.find('.shipping_method').prop('checked', true).trigger('change');
        }
    }

    function syncDeliveryHiddenFields() {
        const mode = getActiveDeliveryMode();

        $('[data-fls-delivery-mode-input]').val(mode);
        $('[data-fls-delivery-date-input]').val(getDateForMode(mode));
    }

    function syncDeliveryUi() {
        const mode = getActiveDeliveryMode();
        const currentDate = getDateForMode(mode);
        const $dateWrap = $('[data-fls-date-wrap="' + mode + '"]');
        const $pickupDetails = $('[data-fls-pickup-details]');

        $('[data-fls-date-display]').each(function () {
            const inputMode = $(this).attr('data-fls-date-display');
            $(this).val(getDateForMode(inputMode));
        });

        $('[data-fls-date-wrap]').not($dateWrap).hide().removeClass('is-invalid');

        if ($dateWrap.length && !$dateWrap.is(':visible')) {
            $dateWrap.stop(true, true).slideDown(animationDuration);
        }

        $dateWrap.removeClass('is-invalid').find('[data-fls-date-display]').val(currentDate);

        if (mode === 'pickup') {
            if (getSelectedShippingCard('pickup').length) {
                $pickupDetails.stop(true, true).slideDown(animationDuration);
            } else {
                $pickupDetails.stop(true, true).slideUp(animationDuration);
            }
        } else {
            $pickupDetails.stop(true, true).slideUp(animationDuration);
        }

        syncDeliveryHiddenFields();
    }

    /* ---------------------------------------------
     * Step validation
     * --------------------------------------------- */
    function validateRequiredFields(step, focusInvalid) {
        let isValid = true;
        let $firstInvalid = $();

        $('[data-fls-step="' + step + '"] .form-row').each(function () {
            const $field = $(this);

            if (!isFieldRequired($field) || !isFieldVisible($field)) {
                $field.removeClass('fls-checkout__field--invalid');
                return;
            }

            const $input = getFieldInput($field);
            const filled = inputHasValue($input);

            $field.toggleClass('fls-checkout__field--invalid', !filled);

            if (!filled && !$firstInvalid.length) {
                $firstInvalid = $input;
                isValid = false;
            }
        });

        if (!isValid && focusInvalid && $firstInvalid.length) {
            $firstInvalid.trigger('focus');
        }

        return isValid;
    }

    function validateRequiredFieldsSilently(step) {
        return validateRequiredFields(step, false);
    }

    function isStepTwoValid() {
        if (!needsShipping()) {
            return true;
        }

        const mode = getActiveDeliveryMode();
        const $card = getSelectedShippingCard(mode);

        if (!$card.length) {
            return false;
        }

        if (!getDateForMode(mode)) {
            return false;
        }

        return true;
    }

    function showStepMessage(type, message) {
        $('.fls-checkout-step-notice').remove();

        const safeMessage = $('<div />').text(message).html();
        const markup = '<div class="fls-checkout-step-notice fls-checkout-step-notice--' + type + '">' + safeMessage + '</div>';

        $('.fls-checkout-shell').first().prepend(markup);
    }

    /* ---------------------------------------------
     * Step UI
     * --------------------------------------------- */
    function toggleStepPanels(step, immediate) {
        $('[data-fls-step]').each(function () {
            const $step = $(this);
            const current = parseInt($step.attr('data-fls-step'), 10);
            const $body = getStepBody(current);
            const shouldOpen = current === step;

            if (!$body.length) {
                return;
            }

            if (immediate) {
                $body.stop(true, true)[shouldOpen ? 'show' : 'hide']();
                return;
            }

            if (shouldOpen) {
                if (!$body.is(':visible')) {
                    $body.stop(true, true).slideDown(animationDuration);
                }
            } else if ($body.is(':visible')) {
                $body.stop(true, true).slideUp(animationDuration);
            }
        });
    }

    function setButtonState($button, enabled) {
        if (!$button.length) {
            return;
        }

        $button.prop('disabled', !enabled);
        $button.toggleClass('is-ready', enabled);
    }

    function updateStepButtons() {
        setButtonState($('[data-fls-step-next="2"]'), validateRequiredFieldsSilently(1));
        setButtonState($('[data-fls-step-next="3"]'), isStepTwoValid());
    }

    function syncStepUi(step, immediate) {
        const state = getState();

        $('[data-fls-step]').each(function () {
            const $step = $(this);
            const current = parseInt($step.attr('data-fls-step'), 10);

            $step.toggleClass('is-active', current === step);
            $step.toggleClass('is-complete', !!state.steps[current].completed);
        });

        $('[data-fls-step-trigger]').each(function () {
            const $trigger = $(this);
            const current = parseInt($trigger.attr('data-fls-step-trigger'), 10);

            if (!$trigger.hasClass('fls-checkout-steps-nav__item')) {
                return;
            }

            const isAvailable = !!state.steps[current].available;
            const isComplete = !!state.steps[current].completed;

            $trigger.toggleClass('is-active', current === step);
            $trigger.toggleClass('is-complete', isComplete);
            $trigger.toggleClass('is-locked', !isAvailable);
            $trigger.attr('aria-disabled', isAvailable ? 'false' : 'true');
        });

        $('.fls-checkout-steps-nav__line').each(function (index) {
            const stepNumber = index + 1;
            const isComplete = !!state.steps[stepNumber].completed && step > stepNumber;
            const isActive = stepNumber === step && step < totalSteps;

            $(this)
                .toggleClass('is-complete', isComplete)
                .toggleClass('is-active', isActive);
        });

        toggleStepPanels(step, immediate);
        updateStepButtons();
    }

    function getMaxAllowedStep() {
        const state = getState();
        let maxAllowedStep = 1;

        for (let step = 1; step <= totalSteps; step += 1) {
            if (state.steps[step] && state.steps[step].available) {
                maxAllowedStep = step;
            }
        }

        return maxAllowedStep;
    }

    function refreshPaymentUi() {
        const $payment = $('#fls-checkout-payment');

        if (!$payment.length) {
            return;
        }

        setTimeout(function () {
            $(document.body).trigger('update_checkout');

            setTimeout(function () {
                const $selectedMethod = $payment.find('input[name="payment_method"]:checked');

                if ($selectedMethod.length) {
                    $selectedMethod.trigger('change');
                }

                $(document.body).trigger('payment_method_selected');
            }, 420);
        }, 80);
    }

    function setStep(step, options) {
        const settings = options || {};
        const state = getState();
        const immediate = !!settings.immediate;
        const maxAllowedStep = getMaxAllowedStep();
        const previousStep = state.activeStep || 1;
        const normalizedStep = Math.max(1, Math.min(step, maxAllowedStep));

        state.steps[normalizedStep].available = true;
        state.activeStep = normalizedStep;

        syncStepUi(normalizedStep, immediate);

        if (normalizedStep === 3 && previousStep !== 3) {
            refreshPaymentUi();
        }
    }

    function maybeDowngradeCompletedState() {
        const state = getState();

        if (!validateRequiredFieldsSilently(1)) {
            state.steps[1].completed = false;

            if (!state.steps[2].completed) {
                state.steps[2].available = false;
                state.steps[3].available = false;
                state.steps[3].completed = false;
            }
        }

        if (!isStepTwoValid()) {
            state.steps[2].completed = false;
            state.steps[3].available = false;

            if (getActiveStep() < 3) {
                state.steps[3].completed = false;
            }
        }
    }

    function goToNextStep(targetStep) {
        const state = getState();
        const currentStep = getActiveStep();

        if (currentStep === 1) {
            if (!validateRequiredFields(1, true)) {
                showStepMessage('error', getI18nMessage('stepOneError', 'Please complete the required customer details before continuing.'));
                setStep(1);
                return;
            }

            state.steps[1].completed = true;
            state.steps[2].available = true;
        }

        if (currentStep === 2) {
            if (!needsShipping() || !getSelectedShippingCard(getActiveDeliveryMode()).length) {
                showStepMessage('error', getI18nMessage('stepTwoError', 'Please choose a delivery option before continuing.'));
                setStep(2);
                return;
            }

            if (!getDateForMode(getActiveDeliveryMode())) {
                $('[data-fls-date-wrap="' + getActiveDeliveryMode() + '"]').addClass('is-invalid');
                showStepMessage('error', getI18nMessage('stepTwoDateError', 'Please choose a date before continuing.'));
                setStep(2);
                return;
            }

            state.steps[2].completed = true;
            state.steps[3].available = true;
        }

        $('.fls-checkout-step-notice').remove();
        setStep(targetStep);
    }

    function goToPreviousStep(targetStep) {
        $('.fls-checkout-step-notice').remove();
        setStep(targetStep);
    }

    /* ---------------------------------------------
     * Coupon actions
     * --------------------------------------------- */
    function applyCoupon(couponCode, $button) {
        const ajaxUrl = getWcAjaxUrl('apply_coupon');
        const nonce = getApplyCouponNonce();

        if (!ajaxUrl || !couponCode) {
            return;
        }

        clearCouponFeedback();
        setButtonLoading($button, true);

        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                security: nonce,
                coupon_code: couponCode
            }
        }).done(function (response) {
            const message = extractNoticeMessage(
                response && response.messages ? response.messages : '',
                'Discount Applied'
            );

            if (response && response.result === 'failure') {
                setCouponFeedback('error', message, false);
                return;
            }

            setCouponFeedback('success', message || 'Discount Applied', false);
            $(document.body).trigger('update_checkout');
        }).fail(function () {
            setCouponFeedback('error', 'Something went wrong while applying the coupon.', false);
        }).always(function () {
            setButtonLoading($button, false);
        });
    }

    function removeCoupon(couponCode, $button) {
        const ajaxUrl = getWcAjaxUrl('remove_coupon');
        const nonce = getRemoveCouponNonce();

        if (!ajaxUrl || !couponCode) {
            return;
        }

        clearCouponFeedback();
        setButtonLoading($button, true);

        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                security: nonce,
                coupon: couponCode
            }
        }).done(function (response) {
            const message = extractNoticeMessage(
                response && response.messages ? response.messages : '',
                'Coupon removed.'
            );

            setCouponFeedback('success', message || 'Coupon removed.', false);
            $(document.body).trigger('update_checkout');
        }).fail(function () {
            setCouponFeedback('error', 'Something went wrong while removing the coupon.', false);
        }).always(function () {
            setButtonLoading($button, false);
        });
    }

    function bindCouponActions() {
        $(document)
            .off('click.flsCouponSubmit')
            .on('click.flsCouponSubmit', '[data-fls-coupon-submit]', function (event) {
                event.preventDefault();

                const $button = $(this);
                const $form = $button.closest('[data-fls-coupon-form]');
                const $input = $form.find('[name="coupon_code"]').first();
                const couponCode = $.trim($input.val() || '');
                const isDeleteMode = $form.attr('data-delete-mode') === '1';

                if (isDeleteMode) {
                    removeCoupon(couponCode, $button);
                    return;
                }

                if (!couponCode) {
                    setCouponFeedback('error', getI18nMessage('couponEmpty', 'Please enter a discount code.'), false);
                    return;
                }

                applyCoupon(couponCode, $button);
            })
            .off('click.flsCouponRemove')
            .on('click.flsCouponRemove', '[data-fls-coupon-remove]', function (event) {
                event.preventDefault();

                const $button = $(this);
                const couponCode = $.trim($button.attr('data-coupon-code') || '');

                if (!couponCode) {
                    return;
                }

                removeCoupon(couponCode, $button);
            })
            .off('keydown.flsCouponEnter')
            .on('keydown.flsCouponEnter', '[data-fls-coupon-form] [name="coupon_code"]', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                $(this).closest('[data-fls-coupon-form]').find('[data-fls-coupon-submit]').first().trigger('click');
            });
    }

    /* ---------------------------------------------
     * Step navigation events
     * --------------------------------------------- */
    function bindStepEvents() {
        $(document)
            .off('click.flsStepTrigger')
            .on('click.flsStepTrigger', '[data-fls-step-trigger]', function () {
                const step = parseInt($(this).attr('data-fls-step-trigger'), 10);
                const state = getState();

                if (!step || !state.steps[step] || !state.steps[step].available) {
                    return;
                }

                $('.fls-checkout-step-notice').remove();
                setStep(step);
            })
            .off('click.flsStepNext')
            .on('click.flsStepNext', '[data-fls-step-next]', function () {
                const step = parseInt($(this).attr('data-fls-step-next'), 10);

                if (step) {
                    goToNextStep(step);
                }
            })
            .off('click.flsStepPrev')
            .on('click.flsStepPrev', '[data-fls-step-prev]', function () {
                const step = parseInt($(this).attr('data-fls-step-prev'), 10);

                if (step) {
                    goToPreviousStep(step);
                }
            });
    }

    /* ---------------------------------------------
     * Basket summary toggle
     * --------------------------------------------- */
    function bindSummaryToggle() {
        $(document)
            .off('click.flsSummaryToggle')
            .on('click.flsSummaryToggle', '[data-fls-summary-toggle]', function () {
                const $button = $(this);
                const $body = $('[data-fls-summary-body]').first();
                const expanded = $button.attr('aria-expanded') === 'true';

                $button.attr('aria-expanded', expanded ? 'false' : 'true');
                $body.stop(true, true).slideToggle(animationDuration);
            });
    }

    /* ---------------------------------------------
     * VAT toggle
     * --------------------------------------------- */
    function bindVatToggle() {
        $(document)
            .off('click.flsVatToggle')
            .on('click.flsVatToggle', '[data-fls-vat-toggle]', function () {
                const $button = $(this);
                const $breakdown = $button.closest('.fls-order-details__vat-block').find('[data-fls-vat-breakdown]').first();
                const expanded = $button.attr('aria-expanded') === 'true';

                $button.attr('aria-expanded', expanded ? 'false' : 'true');
                $button.toggleClass('is-open', !expanded);
                $breakdown.stop(true, true).slideToggle(animationDuration);
            });
    }

    /* ---------------------------------------------
     * Billing / shipping address choice
     * --------------------------------------------- */
    function updateAddressChoiceUi() {
        const $choice = $('[data-fls-address-choice]').first();
        const $checkbox = $('#ship-to-different-address-checkbox');
        const isDifferent = $checkbox.is(':checked');

        if (!$choice.length || !$checkbox.length) {
            return;
        }

        $choice.find('[data-fls-address-mode="same"]')
            .toggleClass('is-active', !isDifferent)
            .attr('aria-pressed', !isDifferent ? 'true' : 'false');

        $choice.find('[data-fls-address-mode="different"]')
            .toggleClass('is-active', isDifferent)
            .attr('aria-pressed', isDifferent ? 'true' : 'false');
    }

    function syncShippingAddressVisibility(immediate) {
        const $checkbox = $('#ship-to-different-address-checkbox');
        const $address = $('.shipping_address').first();

        if (!$checkbox.length || !$address.length) {
            return;
        }

        if ($checkbox.is(':checked')) {
            if (immediate) {
                $address.show();
            } else {
                $address.stop(true, true).slideDown(animationDuration);
            }
        } else {
            if (immediate) {
                $address.hide();
            } else {
                $address.stop(true, true).slideUp(animationDuration);
            }
        }
    }

    function bindAddressChoice() {
        $(document)
            .off('click.flsAddressChoice')
            .on('click.flsAddressChoice', '[data-fls-address-mode]', function () {
                const $checkbox = $('#ship-to-different-address-checkbox');
                const shouldCheck = $(this).attr('data-fls-address-mode') === 'different';

                if (!$checkbox.length || $checkbox.is(':checked') === shouldCheck) {
                    return;
                }

                $checkbox.prop('checked', shouldCheck).trigger('change');
            })
            .off('change.flsAddressCheckbox')
            .on('change.flsAddressCheckbox', '#ship-to-different-address-checkbox', function () {
                updateAddressChoiceUi();
                syncShippingAddressVisibility(false);
                maybeDowngradeCompletedState();
                updateStepButtons();
                syncStepUi(getActiveStep(), true);
                $(document.body).trigger('update_checkout');
            });
    }

    /* ---------------------------------------------
     * Delivery method events
     * --------------------------------------------- */
    function bindDeliveryMethodEvents() {
        $(document)
            .off('click.flsDeliveryTab')
            .on('click.flsDeliveryTab', '[data-fls-delivery-tab]', function () {
                const mode = $(this).attr('data-fls-delivery-tab');

                setActiveDeliveryMode(mode);
                ensureSelectedRateForMode(mode);
                syncSelectedShippingCards();
                syncDeliveryUi();
                maybeDowngradeCompletedState();
                updateStepButtons();
            })
            .off('change.flsShippingCard')
            .on('change.flsShippingCard', '.shipping_method', function () {
                const $card = $(this).closest('[data-fls-shipping-card]');
                const mode = $card.attr('data-mode') || getActiveDeliveryMode();

                setActiveDeliveryMode(mode);
                syncSelectedShippingCards();
                syncDeliveryUi();
                maybeDowngradeCompletedState();
                updateStepButtons();
            });
    }

    /* ---------------------------------------------
     * Flatpickr
     * --------------------------------------------- */
    function destroyFlatpickrInstances() {
        Object.keys(flatpickrInstances).forEach(function (mode) {
            if (flatpickrInstances[mode] && typeof flatpickrInstances[mode].destroy === 'function') {
                flatpickrInstances[mode].destroy();
            }
        });

        flatpickrInstances = {};
    }

    function initFlatpickrInputs() {
        if (typeof window.flatpickr !== 'function') {
            return;
        }

        destroyFlatpickrInstances();

        $('[data-fls-date-display]').each(function () {
            const input = this;
            const mode = $(input).attr('data-fls-date-display');
            const $wrap = $(input).closest('[data-fls-date-wrap]');

            flatpickrInstances[mode] = window.flatpickr(input, {
                minDate: 'today',
                dateFormat: 'F j, Y',
                disableMobile: true,
                defaultDate: getDateForMode(mode) || null,
                allowInput: false,
                clickOpens: true,
                static: true,
                appendTo: $wrap.length ? $wrap.get(0) : undefined,
                positionElement: input,
                onReady: function (selectedDates, dateStr, instance) {
                    if (instance && instance.calendarContainer) {
                        $(instance.calendarContainer).addClass('fls-flatpickr-calendar');
                    }
                },
                onOpen: function (selectedDates, dateStr, instance) {
                    if (instance && instance.calendarContainer) {
                        $(instance.calendarContainer).closest('[data-fls-date-wrap]').addClass('is-open');
                    }
                },
                onClose: function (selectedDates, dateStr, instance) {
                    if (instance && instance.calendarContainer) {
                        $(instance.calendarContainer).closest('[data-fls-date-wrap]').removeClass('is-open');
                    }
                },
                onChange: function (selectedDates, dateStr) {
                    setDateForMode(mode, dateStr);
                    $('[data-fls-date-wrap="' + mode + '"]').removeClass('is-invalid');
                    syncDeliveryHiddenFields();
                    maybeDowngradeCompletedState();
                    updateStepButtons();
                }
            });
        });
    }

    function openDatePicker(mode) {
        if (!mode) {
            return;
        }

        if (!flatpickrInstances[mode]) {
            initFlatpickrInputs();
        }

        if (flatpickrInstances[mode] && typeof flatpickrInstances[mode].open === 'function') {
            if (typeof flatpickrInstances[mode].redraw === 'function') {
                flatpickrInstances[mode].redraw();
            }

            flatpickrInstances[mode].open();
            return;
        }

        const $input = $('[data-fls-date-display="' + mode + '"]').first();

        if ($input.length) {
            $input.trigger('focus').trigger('click');
        }
    }

    function bindDatePickerOpeners() {
        $(document)
            .off('click.flsDatePickerOpen')
            .on('click.flsDatePickerOpen', '[data-fls-date-display], .fls-delivery-method__date-icon', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $target = $(this);
                let mode = $target.attr('data-fls-date-display');

                if (!mode) {
                    mode = $target.closest('[data-fls-date-wrap]').attr('data-fls-date-wrap');
                }

                openDatePicker(mode);
            });
    }

    /* ---------------------------------------------
     * Live watchers
     * --------------------------------------------- */
    function bindCompletionWatchers() {
        $(document)
            .off('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow')
            .on('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow', '[data-fls-step="1"] input, [data-fls-step="1"] select, [data-fls-step="1"] textarea', function () {
                maybeDowngradeCompletedState();
                updateStepButtons();
                syncStepUi(getActiveStep(), true);
            });
    }

    /* ---------------------------------------------
     * Initial state setup
     * --------------------------------------------- */
    function initDeliveryState() {
        const defaultMode = $('[data-fls-delivery-method]').attr('data-default-mode') || 'delivery';
        const state = getState();
        const hiddenMode = $.trim($('[data-fls-delivery-mode-input]').val());
        const hiddenDate = $.trim($('[data-fls-delivery-date-input]').val());

        if (!state.deliveryMode) {
            state.deliveryMode = hiddenMode || defaultMode;
        }

        if (hiddenDate && !state.dates[state.deliveryMode]) {
            state.dates[state.deliveryMode] = hiddenDate;
        }

        setActiveDeliveryMode(state.deliveryMode || defaultMode);
        ensureSelectedRateForMode(getActiveDeliveryMode());
        syncSelectedShippingCards();
        initFlatpickrInputs();
        syncDeliveryUi();
    }

    function init(immediate) {
        bindStepEvents();
        bindSummaryToggle();
        bindVatToggle();
        bindCouponActions();
        bindAddressChoice();
        bindDeliveryMethodEvents();
        bindDatePickerOpeners();
        bindCompletionWatchers();

        updateAddressChoiceUi();
        syncShippingAddressVisibility(!!immediate);
        initDeliveryState();
        maybeDowngradeCompletedState();
        setStep(getActiveStep(), { immediate: !!immediate });
    }

    /* ---------------------------------------------
     * Boot
     * --------------------------------------------- */
    $(document.body).on('updated_checkout', function () {
        init(true);
    });

    $(function () {
        init(true);
    });
})(jQuery);