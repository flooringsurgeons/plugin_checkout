(function ($) {
    'use strict';

    var totalSteps = 3;
    var animationDuration = 240;
    var flatpickrInstances = {};

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
            window.flsCheckoutFlowState.dates = { delivery: '', pickup: '' };
        }

        for (var step = 1; step <= totalSteps; step += 1) {
            if (!window.flsCheckoutFlowState.steps[step]) {
                window.flsCheckoutFlowState.steps[step] = { available: step === 1, completed: false };
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

    function setActiveDeliveryMode(mode) {
        var state = getState();
        state.deliveryMode = mode;
        $('[data-fls-delivery-mode-input]').val(mode);

        $('[data-fls-delivery-tab]').each(function () {
            var $tab = $(this);
            var currentMode = $tab.attr('data-fls-delivery-tab');
            var active = currentMode === mode;
            $tab.toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
        });

        $('[data-fls-delivery-panel]').each(function () {
            var $panel = $(this);
            var currentMode = $panel.attr('data-fls-delivery-panel');
            var active = currentMode === mode;

            $panel.toggleClass('is-active', active);

            if (active) {
                if (!$panel.is(':visible')) {
                    $panel.stop(true, true).hide().slideDown(animationDuration, function () {
                        $panel.css('display', 'grid');
                    });
                } else {
                    $panel.css('display', 'grid');
                }
            } else if ($panel.is(':visible')) {
                $panel.stop(true, true).slideUp(animationDuration);
            }
        });
    }

    function getDateForMode(mode) {
        return $.trim((getState().dates[mode] || ''));
    }

    function setDateForMode(mode, value) {
        var state = getState();
        state.dates[mode] = $.trim(value || '');
    }

    function WCNeedsShipping() {
        return $('[data-fls-shipping-card]').length > 0;
    }

    function getSelectedShippingCard(mode) {
        var selector = '[data-fls-shipping-card][data-mode="' + mode + '"] .shipping_method:checked';
        var $input = $(selector).first();
        return $input.length ? $input.closest('[data-fls-shipping-card]') : $();
    }

    function getSelectedShippingCardAny() {
        var $input = $('.shipping_method:checked').first();
        return $input.length ? $input.closest('[data-fls-shipping-card]') : $();
    }

    function selectedRateRequiresDate(mode) {
        return WCNeedsShipping() && getSelectedShippingCard(mode).length > 0;
    }

    function syncSelectedShippingCards() {
        $('[data-fls-shipping-card]').each(function () {
            var $card = $(this);
            var checked = $card.find('.shipping_method').is(':checked');
            $card.toggleClass('is-selected', checked);
        });
    }

    function ensureSelectedRateForMode(mode) {
        var $selected = getSelectedShippingCard(mode);

        if ($selected.length) {
            return;
        }

        var $first = $('[data-fls-shipping-card][data-mode="' + mode + '"]').first();
        if ($first.length) {
            $first.find('.shipping_method').prop('checked', true).trigger('change');
        }
    }

    function syncDeliveryHiddenFields() {
        var mode = getActiveDeliveryMode();
        $('[data-fls-delivery-mode-input]').val(mode);
        $('[data-fls-delivery-date-input]').val(getDateForMode(mode));
    }

    function syncDeliveryUi() {
        var mode = getActiveDeliveryMode();
        var currentDate = getDateForMode(mode);
        var $dateWrap = $('[data-fls-date-wrap="' + mode + '"]');
        var $pickupDetails = $('[data-fls-pickup-details]');

        $('[data-fls-date-display]').each(function () {
            var inputMode = $(this).attr('data-fls-date-display');
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

    function validateRequiredFields(step, focusInvalid) {
        var isValid = true;
        var $firstInvalid = $();

        $('[data-fls-step="' + step + '"] .form-row').each(function () {
            var $field = $(this);

            if (!isFieldRequired($field) || !isFieldVisible($field)) {
                $field.removeClass('fls-checkout__field--invalid');
                return;
            }

            var $input = getFieldInput($field);
            var filled = inputHasValue($input);

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
        if (!WCNeedsShipping()) {
            return true;
        }

        var mode = getActiveDeliveryMode();
        var $card = getSelectedShippingCard(mode);

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
        var markup = '<div class="fls-checkout-step-notice fls-checkout-step-notice--' + type + '">' + $('<div />').text(message).html() + '</div>';
        $('.fls-checkout-shell').first().prepend(markup);
    }

    function toggleStepPanels(step, immediate) {
        $('[data-fls-step]').each(function () {
            var $step = $(this);
            var current = parseInt($step.attr('data-fls-step'), 10);
            var $body = getStepBody(current);
            var shouldOpen = current === step;

            if (!$body.length) {
                return;
            }

            if (immediate) {
                $body.stop(true, true)[shouldOpen ? 'show' : 'hide']();
                return;
            }

            if (shouldOpen) {
                if (!$body.is(':visible')) {
                    $body.stop(true, true).slideToggle(animationDuration);
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
        var state = getState();

        $('[data-fls-step]').each(function () {
            var $step = $(this);
            var current = parseInt($step.attr('data-fls-step'), 10);

            $step.toggleClass('is-active', current === step);
            $step.toggleClass('is-complete', !!state.steps[current].completed);
        });

        $('[data-fls-step-trigger]').each(function () {
            var $trigger = $(this);
            var current = parseInt($trigger.attr('data-fls-step-trigger'), 10);

            if (!$trigger.hasClass('fls-checkout-steps-nav__item')) {
                return;
            }

            var isAvailable = !!state.steps[current].available;
            var isComplete = !!state.steps[current].completed;

            $trigger.toggleClass('is-active', current === step);
            $trigger.toggleClass('is-complete', isComplete);
            $trigger.toggleClass('is-locked', !isAvailable);
            $trigger.attr('aria-disabled', isAvailable ? 'false' : 'true');
        });

        $('.fls-checkout-steps-nav__line').each(function (index) {
            var stepNumber = index + 1;
            var isComplete = !!state.steps[stepNumber].completed && step > stepNumber;
            var isActive = stepNumber === step && step < totalSteps;

            $(this)
                .toggleClass('is-complete', isComplete)
                .toggleClass('is-active', isActive);
        });

        toggleStepPanels(step, immediate);
        updateStepButtons();
    }

    function getMaxAllowedStep() {
        var state = getState();
        var maxAllowedStep = 1;

        for (var step = 1; step <= totalSteps; step += 1) {
            if (state.steps[step] && state.steps[step].available) {
                maxAllowedStep = step;
            }
        }

        return maxAllowedStep;
    }

    function refreshPaymentUi() {
        var $payment = $('#fls-checkout-payment');

        if (!$payment.length) {
            return;
        }

        setTimeout(function () {
            $(document.body).trigger('update_checkout');

            setTimeout(function () {
                var $selectedMethod = $payment.find('input[name="payment_method"]:checked');
                if ($selectedMethod.length) {
                    $selectedMethod.trigger('change');
                }
                $(document.body).trigger('payment_method_selected');
            }, 420);
        }, 80);
    }

    function setStep(step, options) {
        options = options || {};

        var state = getState();
        var immediate = !!options.immediate;
        var maxAllowedStep = getMaxAllowedStep();
        var previousStep = state.activeStep || 1;

        step = Math.max(1, Math.min(step, maxAllowedStep));
        state.steps[step].available = true;
        state.activeStep = step;

        syncStepUi(step, immediate);

        if (step === 3 && previousStep !== 3) {
            refreshPaymentUi();
        }
    }

    function maybeDowngradeCompletedState() {
        var state = getState();

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
        var state = getState();
        var currentStep = getActiveStep();

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
            if (!WCNeedsShipping() || !getSelectedShippingCard(getActiveDeliveryMode()).length) {
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

    function bindStepEvents() {
        $(document)
            .off('click.flsStepTrigger')
            .on('click.flsStepTrigger', '[data-fls-step-trigger]', function () {
                var step = parseInt($(this).attr('data-fls-step-trigger'), 10);
                var state = getState();

                if (!step || !state.steps[step] || !state.steps[step].available) {
                    return;
                }

                $('.fls-checkout-step-notice').remove();
                setStep(step);
            })
            .off('click.flsStepNext')
            .on('click.flsStepNext', '[data-fls-step-next]', function () {
                var step = parseInt($(this).attr('data-fls-step-next'), 10);
                if (step) {
                    goToNextStep(step);
                }
            })
            .off('click.flsStepPrev')
            .on('click.flsStepPrev', '[data-fls-step-prev]', function () {
                var step = parseInt($(this).attr('data-fls-step-prev'), 10);
                if (step) {
                    goToPreviousStep(step);
                }
            });
    }

    function bindSummaryToggle() {
        $(document)
            .off('click.flsSummaryToggle')
            .on('click.flsSummaryToggle', '[data-fls-summary-toggle]', function () {
                var $button = $(this);
                var $body = $('[data-fls-summary-body]').first();
                var expanded = $button.attr('aria-expanded') === 'true';

                $button.attr('aria-expanded', expanded ? 'false' : 'true');
                $body.stop(true, true).slideToggle(animationDuration);
            });
    }

    function bindCouponForm() {
        $(document)
            .off('submit.flsCouponForm')
            .on('submit.flsCouponForm', '[data-fls-coupon-form]', function (event) {
                event.preventDefault();

                if (typeof wc_checkout_params === 'undefined') {
                    return;
                }

                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                var couponCode = $.trim($form.find('[name="coupon_code"]').val());

                if (!couponCode) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
                    data: {
                        security: wc_checkout_params.apply_coupon_nonce,
                        coupon_code: couponCode
                    }
                }).done(function (response) {
                    $('.woocommerce-error, .woocommerce-message, .woocommerce-info').remove();

                    if (response && response.messages) {
                        $('form.checkout').before(response.messages);
                    }

                    $(document.body).trigger('update_checkout');
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
    }

    function updateAddressChoiceUi() {
        var $choice = $('[data-fls-address-choice]').first();
        var $checkbox = $('#ship-to-different-address-checkbox');
        var isDifferent = $checkbox.is(':checked');

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
        var $checkbox = $('#ship-to-different-address-checkbox');
        var $address = $('.shipping_address').first();

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
                var $checkbox = $('#ship-to-different-address-checkbox');
                var shouldCheck = $(this).attr('data-fls-address-mode') === 'different';

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

    function bindDeliveryMethodEvents() {
        $(document)
            .off('click.flsDeliveryTab')
            .on('click.flsDeliveryTab', '[data-fls-delivery-tab]', function () {
                var mode = $(this).attr('data-fls-delivery-tab');
                setActiveDeliveryMode(mode);
                ensureSelectedRateForMode(mode);
                syncSelectedShippingCards();
                syncDeliveryUi();
                maybeDowngradeCompletedState();
                updateStepButtons();
            })
            .off('change.flsShippingCard')
            .on('change.flsShippingCard', '.shipping_method', function () {
                var $card = $(this).closest('[data-fls-shipping-card]');
                var mode = $card.attr('data-mode') || getActiveDeliveryMode();

                setActiveDeliveryMode(mode);
                syncSelectedShippingCards();
                syncDeliveryUi();
                maybeDowngradeCompletedState();
                updateStepButtons();
            });
    }

    function initFlatpickrInputs() {
        if (typeof window.flatpickr !== 'function') {
            return;
        }

        $('[data-fls-date-display]').each(function () {
            var input = this;
            var mode = $(input).attr('data-fls-date-display');
            var $wrap = $(input).closest('[data-fls-date-wrap]');

            if (flatpickrInstances[mode]) {
                flatpickrInstances[mode].setDate(getDateForMode(mode), false, 'F j, Y');
                return;
            }

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

        var $input = $('[data-fls-date-display="' + mode + '"]').first();
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

                var $target = $(this);
                var mode = $target.attr('data-fls-date-display');

                if (!mode) {
                    mode = $target.closest('[data-fls-date-wrap]').attr('data-fls-date-wrap');
                }

                openDatePicker(mode);
            });
    }

    function bindCompletionWatchers() {
        $(document)
            .off('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow')
            .on('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow', '[data-fls-step="1"] input, [data-fls-step="1"] select, [data-fls-step="1"] textarea', function () {
                maybeDowngradeCompletedState();
                updateStepButtons();
                syncStepUi(getActiveStep(), true);
            });
    }

    function initDeliveryState() {
        var defaultMode = $('[data-fls-delivery-method]').attr('data-default-mode') || 'delivery';
        var state = getState();
        var hiddenMode = $.trim($('[data-fls-delivery-mode-input]').val());
        var hiddenDate = $.trim($('[data-fls-delivery-date-input]').val());

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
        bindCouponForm();
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

    $(document.body).on('updated_checkout', function () {
        init(true);
    });

    $(function () {
        init(true);
    });
})(jQuery);
