(function ($) {
    'use strict';

    // -- Config -----------------------------------------------------------------

    const Config = {
        totalSteps: 3,
        animDuration: 240,
        shippingCalcTimeout: 8000,

        get(key, fallback) {
            return (window.flsCheckoutFlow && window.flsCheckoutFlow[key] != null)
                ? window.flsCheckoutFlow[key]
                : fallback;
        },

        i18n(key, fallback) {
            const map = this.get('i18n', {});
            return map[key] || fallback;
        },

        couponNonce(action) {
            const c = this.get('coupon', {});
            return (action === 'apply' ? c.applyNonce : c.removeNonce) || '';
        },

        shippingCalcNonce() {
            return this.get('shipping', {}).calcNonce || '';
        },

        shippingAjaxUrl() {
            const s = this.get('shipping', {});
            return s.ajaxUrl || (typeof wc_checkout_params !== 'undefined' ? wc_checkout_params.ajax_url : '');
        },

        wcAjaxUrl(endpoint) {
            if (typeof wc_checkout_params === 'undefined' || !wc_checkout_params.wc_ajax_url) return '';
            return wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', endpoint);
        },

        getBackorderMinDate() {
            const raw = this.get('backorderMinDate', '');
            if (!raw) return null;
            const d = new Date(raw);
            if (isNaN(d.getTime())) return null;
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return d > today ? d : null;
        },

        getUkDeliveryMinDate() {
            const now = new Date();
            const ukDate = new Date(now.toLocaleString('en-US', { timeZone: 'Europe/London' }));
            const day = ukDate.getDay(); // 0=Sun, 6=Sat
            const hour = ukDate.getHours();

            let offset;
            if (day === 6) {
                offset = 4; // Saturday: skip Sat+Sun + 2 working days = Wednesday
            } else if (day === 0) {
                offset = 3; // Sunday: skip Sun + 2 working days = Wednesday
            } else {
                offset = (hour >= 9 && hour < 17) ? 2 : 3; // working hours vs after hours
            }

            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const min = new Date(today);
            min.setDate(min.getDate() + offset);

            // If result lands on weekend, advance to Monday
            while (min.getDay() === 0 || min.getDay() === 6) {
                min.setDate(min.getDate() + 1);
            }

            return min;
        },

        deliveryMinDate() {
            const backorder = this.getBackorderMinDate();
            if (backorder) return backorder;
            return this.getUkDeliveryMinDate();
        }
    };

    // -- State ------------------------------------------------------------------

    const State = {
        _data: null,

        _defaults() {
            const steps = {};
            for (let i = 1; i <= Config.totalSteps; i++) {
                steps[i] = { available: i === 1, completed: false };
            }
            return {
                activeStep: parseInt(Config.get('activeStep', 1), 10) || 1,
                deliveryMode: 'delivery',
                calculatingShipping: false,
                deliveryAvailable: null,
                shippingIsFree: false,
                dates: { delivery: '', pickup: '' },
                steps
            };
        },

        get() {
            if (!this._data) this._data = this._defaults();
            return this._data;
        }
    };

    // -- DOM Helpers ------------------------------------------------------------

    const DOM = {
        stepBody(step) {
            return $('[data-fls-step-body="' + step + '"]').first();
        },

        isRequired($field) {
            return $field.hasClass('validate-required') || $field.find('[aria-required="true"], [required]').length > 0;
        },

        isVisible($field) {
            return $field.is(':visible') && !$field.closest('[hidden]').length;
        },

        fieldInput($field) {
            return $field.find('input, select, textarea').filter(function () {
                return $(this).attr('type') !== 'hidden' && !$(this).is(':disabled');
            }).first();
        },

        inputHasValue($input) {
            if (!$input.length) return true;
            if ($input.is(':radio')) return $('[name="' + $input.attr('name') + '"]:checked').length > 0;
            if ($input.is(':checkbox')) return $input.is(':checked');
            return $.trim($input.val() || '') !== '';
        },

        setButtonLoading($btn, loading) {
            if (!$btn.length) return;
            $btn.prop('disabled', !!loading).toggleClass('is-loading', !!loading);
        },

        setButtonState($btn, enabled) {
            if (!$btn.length) return;
            $btn.prop('disabled', !enabled).toggleClass('is-ready', !!enabled);
        },

        safeHtml(text) {
            return $('<div />').text(text).html();
        }
    };

    // -- Toast ------------------------------------------------------------------

    const Toast = {
        _icons: {
            success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18.3337C14.6024 18.3337 18.3333 14.6027 18.3333 10.0003C18.3333 5.39795 14.6024 1.66699 10 1.66699C5.39762 1.66699 1.66666 5.39795 1.66666 10.0003C1.66666 14.6027 5.39762 18.3337 10 18.3337Z" stroke="currentColor" stroke-width="1.5"/><path d="M6.25 10.0003L8.75 12.5003L13.75 7.50033" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            notice:  '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18.3337C14.6024 18.3337 18.3333 14.6027 18.3333 10.0003C18.3333 5.39795 14.6024 1.66699 10 1.66699C5.39762 1.66699 1.66666 5.39795 1.66666 10.0003C1.66666 14.6027 5.39762 18.3337 10 18.3337Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.66699V10.8337" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.99539 13.333H10.0029" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            error:   '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 18.3337C14.6024 18.3337 18.3333 14.6027 18.3333 10.0003C18.3333 5.39795 14.6024 1.66699 10 1.66699C5.39762 1.66699 1.66666 5.39795 1.66666 10.0003C1.66666 14.6027 5.39762 18.3337 10 18.3337Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.66699V10.8337" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.99539 13.333H10.0029" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        },

        _stack() {
            let $s = $('[data-fls-toast-stack]').first();
            if (!$s.length) {
                $s = $('<div class="fls-checkout-toast-stack" data-fls-toast-stack aria-live="polite" aria-atomic="true"></div>');
                $('body').append($s);
            }
            return $s;
        },

        position() {
            const $stack = this._stack();
            if (!$stack.length) return;

            if (window.matchMedia('(max-width: 991px)').matches) {
                $stack.css({ top: 'auto', bottom: '12px', left: '12px', right: '12px', width: 'auto', maxWidth: 'none' });
                return;
            }

            const $card = $('#fls-checkout-order-details .fls-order-details__card').first();
            if (!$card.length) {
                $stack.css({ top: '20px', bottom: 'auto', left: 'auto', right: '20px', width: '360px', maxWidth: 'calc(100vw - 24px)' });
                return;
            }

            const rect = $card.get(0).getBoundingClientRect();
            const pad = 16;
            const left = Math.max(rect.left, pad);
            const width = Math.min(rect.width, window.innerWidth - left - pad);
            $stack.css({ top: (rect.bottom + 16) + 'px', bottom: 'auto', left: left + 'px', right: 'auto', width: width + 'px', maxWidth: 'none' });
        },

        clear() {
            $('[data-fls-toast]').remove();
        },

        show(type, message) {
            if (!message) return;
            const t = ['success', 'notice', 'error'].includes(type) ? type : 'error';
            const id = 'fls-toast-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
            const $toast = $(
                '<div class="fls-checkout-toast fls-checkout-toast--' + t + '" data-fls-toast="' + id + '">' +
                '<span class="fls-checkout-toast__icon" aria-hidden="true">' + (this._icons[t] || this._icons.error) + '</span>' +
                '<span class="fls-checkout-toast__text">' + DOM.safeHtml(message) + '</span>' +
                '</div>'
            );
            this._stack().append($toast);
            this.position();
            requestAnimationFrame(function () { $toast.addClass('is-visible'); });
            setTimeout(function () {
                $toast.removeClass('is-visible');
                setTimeout(function () { $toast.remove(); }, 240);
            }, 3500);
        },

        parseWcResponse(response, fallbackType, fallbackMessage) {
            const $m = $('<div />').html(response || '');
            const selectors = [
                ['.woocommerce-error li',   'error'],
                ['.woocommerce-error',       'error'],
                ['.woocommerce-message li',  'success'],
                ['.woocommerce-message',     'success'],
                ['.woocommerce-info li',     'notice'],
                ['.woocommerce-info',        'notice']
            ];
            for (const [sel, msgType] of selectors) {
                const $el = $m.find(sel).first();
                if ($el.length) return { type: msgType, message: $.trim($el.text()) || fallbackMessage || '' };
            }
            return { type: fallbackType || 'success', message: fallbackMessage || $.trim($m.text()) || '' };
        }
    };

    // -- DatePicker -------------------------------------------------------------

    const DatePicker = {
        instances: {},

        destroy() {
            Object.keys(this.instances).forEach(function (mode) {
                const inst = DatePicker.instances[mode];
                if (inst && typeof inst.destroy === 'function') inst.destroy();
            });
            this.instances = {};
        },

        init() {
            if (typeof window.flatpickr !== 'function') return;
            this.destroy();
            $('[data-fls-date-display]').each(function () {
                const input = this;
                const mode = $(input).attr('data-fls-date-display');
                const $wrap = $(input).closest('[data-fls-date-wrap]');
                const isDelivery = mode === 'delivery';

                const options = {
                    minDate: Config.deliveryMinDate(),
                    dateFormat: 'F j, Y',
                    disableMobile: true,
                    defaultDate: Delivery.getDate(mode) || null,
                    allowInput: false,
                    clickOpens: true,
                    static: true,
                    appendTo: $wrap.length ? $wrap.get(0) : undefined,
                    positionElement: input,
                    onReady(_, __, instance) {
                        if (instance.calendarContainer) {
                            $(instance.calendarContainer).addClass('fls-flatpickr-calendar');
                        }
                    },
                    onOpen(_, __, instance) {
                        if (instance.calendarContainer) {
                            $(instance.calendarContainer).closest('[data-fls-date-wrap]').addClass('is-open');
                        }
                    },
                    onClose(_, __, instance) {
                        if (instance.calendarContainer) {
                            $(instance.calendarContainer).closest('[data-fls-date-wrap]').removeClass('is-open');
                        }
                    },
                    onChange(_, dateStr) {
                        Delivery.setDate(mode, dateStr);
                        $('[data-fls-date-wrap="' + mode + '"]').removeClass('is-invalid');
                        Delivery.syncHiddenFields();
                        Validation.maybeDowngrade();
                        Steps.updateButtons();
                    }
                };

                if (isDelivery) {
                    options.disable = [function (date) {
                        return date.getDay() === 0 || date.getDay() === 6;
                    }];
                }

                DatePicker.instances[mode] = window.flatpickr(input, options);
            });
        },

        open(mode) {
            if (!mode) return;
            if (!this.instances[mode]) this.init();
            const inst = this.instances[mode];
            if (inst && typeof inst.open === 'function') {
                if (typeof inst.redraw === 'function') inst.redraw();
                inst.open();
                return;
            }
            $('[data-fls-date-display="' + mode + '"]').first().trigger('focus').trigger('click');
        }
    };

    // -- Delivery ---------------------------------------------------------------

    const Delivery = {
        getMode() {
            return State.get().deliveryMode || $('[data-fls-delivery-method]').attr('data-default-mode') || 'delivery';
        },

        getDate(mode) {
            return $.trim(State.get().dates[mode] || '');
        },

        setDate(mode, value) {
            State.get().dates[mode] = $.trim(value || '');
        },

        needsShipping() {
            return $('[data-fls-shipping-card]').length > 0;
        },

        selectedCard(mode) {
            const $input = $('[data-fls-shipping-card][data-mode="' + mode + '"] .shipping_method:checked').first();
            return $input.length ? $input.closest('[data-fls-shipping-card]') : $();
        },

        setMode(mode) {
            State.get().deliveryMode = mode;
            $('[data-fls-delivery-mode-input]').val(mode);

            $('[data-fls-delivery-tab]').each(function () {
                const isActive = $(this).attr('data-fls-delivery-tab') === mode;
                $(this).toggleClass('is-active', isActive).attr('aria-selected', isActive ? 'true' : 'false');
            });

            $('[data-fls-delivery-panel]').each(function () {
                const $panel = $(this);
                const isActive = $panel.attr('data-fls-delivery-panel') === mode;
                $panel.toggleClass('is-active', isActive);
                if (isActive) {
                    if (!$panel.is(':visible')) {
                        $panel.stop(true, true).hide().slideDown(Config.animDuration, function () { $panel.css('display', 'grid'); });
                    } else {
                        $panel.css('display', 'grid');
                    }
                } else {
                    $panel.stop(true, true).slideUp(Config.animDuration);
                }
            });
        },

        syncCards() {
            $('[data-fls-shipping-card]').each(function () {
                $(this).toggleClass('is-selected', $(this).find('.shipping_method').is(':checked'));
            });
        },

        ensureSelectedRate(mode) {
            if (this.selectedCard(mode).length) return;
            const $first = $('[data-fls-shipping-card][data-mode="' + mode + '"]').first();
            if ($first.length) $first.find('.shipping_method').prop('checked', true).trigger('change');
        },

        syncHiddenFields() {
            const mode = this.getMode();
            $('[data-fls-delivery-mode-input]').val(mode);
            $('[data-fls-delivery-date-input]').val(this.getDate(mode));
        },

        syncUi() {
            const mode = this.getMode();
            const date = this.getDate(mode);
            const $dateWrap = $('[data-fls-date-wrap="' + mode + '"]');

            $('[data-fls-date-display]').each(function () {
                $(this).val(Delivery.getDate($(this).attr('data-fls-date-display')));
            });

            $('[data-fls-date-wrap]').not($dateWrap).hide().removeClass('is-invalid');

            if ($dateWrap.length && !$dateWrap.is(':visible')) {
                $dateWrap.stop(true, true).slideDown(Config.animDuration);
            }
            $dateWrap.removeClass('is-invalid').find('[data-fls-date-display]').val(date);

            const $pickupDetails = $('[data-fls-pickup-details]');
            if (mode === 'pickup' && this.selectedCard('pickup').length) {
                $pickupDetails.stop(true, true).slideDown(Config.animDuration);
            } else {
                $pickupDetails.stop(true, true).slideUp(Config.animDuration);
            }

            this.syncHiddenFields();
        },

        setPanelDisabled(disabled) {
            const $panel = $('[data-fls-delivery-panel="delivery"]');
            $panel.find('[data-fls-shipping-card]').toggleClass('is-disabled', disabled);
            $panel.find('.shipping_method').prop('disabled', disabled);
            $panel.find('[data-fls-date-wrap="delivery"]').toggleClass('is-disabled', disabled);
            $panel.find('[data-fls-date-display="delivery"]').prop('disabled', disabled);
            if (DatePicker.instances['delivery']) {
                DatePicker.instances['delivery'].set('clickOpens', !disabled);
            }
        },

        showPanelError(message) {
            $('[data-fls-delivery-service-error]').remove();
            const icon = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
                + '<path d="M8.57465 3.21667L1.51631 15C1.37079 15.2529 1.29379 15.5389 1.29297 15.8304C1.29215 16.1219 1.36754 16.4083 1.51163 16.662C1.65572 16.9157 1.86342 17.1276 2.11384 17.2764C2.36425 17.4252 2.64864 17.5057 2.93965 17.5H17.0563C17.3473 17.5057 17.6317 17.4252 17.8821 17.2764C18.1325 17.1276 18.3402 16.9157 18.4843 16.662C18.6284 16.4083 18.7038 16.1219 18.703 15.8304C18.7022 15.5389 18.6252 15.2529 18.4796 15L11.4213 3.21667C11.2727 2.97138 11.0635 2.76865 10.814 2.62882C10.5645 2.48899 10.2836 2.41602 9.99798 2.41602C9.71235 2.41602 9.43143 2.48899 9.18197 2.62882C8.93251 2.76865 8.72324 2.97138 8.57465 3.21667Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
                + '<path d="M10 7.5V10.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
                + '<path d="M10 13.75H10.0083" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>';
            const subtext = Config.i18n('deliveryNotAvailableSub', 'Enter another postcode or select in-store pickup to continue.');
            const html = '<div class="fls-delivery-method__warning" data-fls-delivery-service-error>'
                + '<span class="fls-delivery-method__warning-icon" aria-hidden="true">' + icon + '</span>'
                + '<span class="fls-delivery-method__warning-text"><strong>' + DOM.safeHtml(message) + '</strong><span>' + DOM.safeHtml(subtext) + '</span></span>'
                + '</div>';

            const $actions = $('#fls-checkout-shipping-methods .fls-checkout-step__actions').first();
            if ($actions.length) {
                $actions.before(html);
            } else {
                ($('[data-fls-delivery-panel="delivery"]').length
                    ? $('[data-fls-delivery-panel="delivery"]')
                    : $('[data-fls-delivery-method]')
                ).append(html);
            }
            this.setPanelDisabled(true);
        },

        ensureBlockedUi() {
            if ($('[data-fls-delivery-warning]').length) {
                this.setPanelDisabled(true);
            } else {
                this.showPanelError(Config.i18n('deliveryNotAvailable', 'Delivery is not available in your area yet.'));
            }
        },

        calculateShipping(postcode, onDone) {
            const nonce = Config.shippingCalcNonce();
            const ajaxUrl = Config.shippingAjaxUrl();
            const state = State.get();
            const useDifferent = $('#ship-to-different-address-checkbox').is(':checked');
            const $postcodeField = useDifferent ? $('#shipping_postcode_field') : $('#billing_postcode_field');
            const $continueBtn = $('[data-fls-step-next="2"]');

            const done = function (success, message) {
                state.calculatingShipping = false;
                $postcodeField.removeClass('fls-field--calculating');
                DOM.setButtonLoading($continueBtn, false);
                if (typeof onDone === 'function') onDone(!!success, message || '');
            };

            if (!postcode || !nonce || !ajaxUrl) {
                done(false, Config.i18n('shippingCalcError', 'Unable to calculate shipping right now. Please try again.'));
                return;
            }

            state.calculatingShipping = true;
            $('[data-fls-delivery-service-error]').remove();
            Delivery.setPanelDisabled(false);
            $postcodeField.addClass('fls-field--calculating');
            DOM.setButtonLoading($continueBtn, true);

            $.ajax({ type: 'POST', url: ajaxUrl, data: { action: 'fls_calculate_shipping', nonce: nonce, postcode: postcode } })
                .done(function (response) {
                    if (!response || !response.success) {
                        state.shippingIsFree = false;
                        done(false, (response && response.data && response.data.message)
                            ? response.data.message
                            : Config.i18n('shippingCalcError', 'Unable to calculate shipping right now. Please try again.'));
                        return;
                    }

                    if (response.data) {
                        state.deliveryAvailable = !!response.data.delivery_available;
                        state.shippingIsFree = !!response.data.is_free;
                    }

                    if (!state.deliveryAvailable) {
                        $(document.body).trigger('update_checkout');
                        done(false, Config.i18n('deliveryNotAvailable', 'Delivery is not available in your area yet.'));
                        return;
                    }

                    Delivery.setMode('delivery');

                    const timeout = setTimeout(function () {
                        $(document.body).off('updated_checkout.flsShippingCalc');
                        done(true);
                    }, Config.shippingCalcTimeout);

                    $(document.body).one('updated_checkout.flsShippingCalc', function () {
                        clearTimeout(timeout);
                        done(true);
                    });

                    $(document.body).trigger('update_checkout');
                })
                .fail(function () {
                    state.shippingIsFree = false;
                    done(false, Config.i18n('shippingCalcError', 'Unable to calculate shipping right now. Please try again.'));
                });
        }
    };

    // -- Validation -------------------------------------------------------------

    const Validation = {
        step(stepNum, focusInvalid) {
            let valid = true;
            let $firstInvalid = $();

            $('[data-fls-step="' + stepNum + '"] .form-row').each(function () {
                const $field = $(this);
                if (!DOM.isRequired($field) || !DOM.isVisible($field)) {
                    $field.removeClass('fls-checkout__field--invalid');
                    return;
                }
                const $input = DOM.fieldInput($field);
                const filled = DOM.inputHasValue($input);
                $field.toggleClass('fls-checkout__field--invalid', !filled);
                if (!filled && !$firstInvalid.length) {
                    $firstInvalid = $input;
                    valid = false;
                }
            });

            if (!valid && focusInvalid && $firstInvalid.length) {
                $firstInvalid.trigger('focus');
            }
            return valid;
        },

        stepTwo() {
            if (!Delivery.needsShipping()) return true;
            const mode = Delivery.getMode();
            return Delivery.selectedCard(mode).length > 0 && !!Delivery.getDate(mode);
        },

        maybeDowngrade() {
            const state = State.get();
            if (!this.step(1, false)) {
                state.steps[1].completed = false;
                if (!state.steps[2].completed) {
                    state.steps[2].available = false;
                    state.steps[3].available = false;
                    state.steps[3].completed = false;
                }
            }
            if (!this.stepTwo()) {
                state.steps[2].completed = false;
                state.steps[3].available = false;
                if (Steps.active() < 3) state.steps[3].completed = false;
            }
        }
    };

    // -- Steps ------------------------------------------------------------------

    const Steps = {
        active() {
            return State.get().activeStep || 1;
        },

        updateButtons() {
            DOM.setButtonState($('[data-fls-step-next="2"]'), Validation.step(1, false));
            DOM.setButtonState($('[data-fls-step-next="3"]'), Validation.stepTwo());
        },

        showNotice(type, message) {
            $('.fls-checkout-step-notice').remove();
            const html = '<div class="fls-checkout-step-notice fls-checkout-step-notice--' + type + '">' + DOM.safeHtml(message) + '</div>';
            $('.fls-checkout-shell').first().prepend(html);
        },

        _maxAllowed() {
            const state = State.get();
            let max = 1;
            for (let i = 1; i <= Config.totalSteps; i++) {
                if (state.steps[i] && state.steps[i].available) max = i;
            }
            return max;
        },

        _togglePanels(step, immediate) {
            $('[data-fls-step]').each(function () {
                const current = parseInt($(this).attr('data-fls-step'), 10);
                const $body = DOM.stepBody(current);
                if (!$body.length) return;
                const shouldOpen = current === step;
                if (immediate) {
                    $body.stop(true, true)[shouldOpen ? 'show' : 'hide']();
                    return;
                }
                if (shouldOpen && !$body.is(':visible')) {
                    $body.stop(true, true).slideDown(Config.animDuration);
                } else if (!shouldOpen && $body.is(':visible')) {
                    $body.stop(true, true).slideUp(Config.animDuration);
                }
            });
        },

        syncUi(step, immediate) {
            const state = State.get();

            $('[data-fls-step]').each(function () {
                const current = parseInt($(this).attr('data-fls-step'), 10);
                $(this)
                    .toggleClass('is-active', current === step)
                    .toggleClass('is-complete', !!state.steps[current].completed);
            });

            $('[data-fls-step-trigger]').each(function () {
                const $trigger = $(this);
                const current = parseInt($trigger.attr('data-fls-step-trigger'), 10);
                if (!$trigger.hasClass('fls-checkout-steps-nav__item')) return;
                $trigger
                    .toggleClass('is-active', current === step)
                    .toggleClass('is-complete', !!state.steps[current].completed)
                    .toggleClass('is-locked', !state.steps[current].available)
                    .attr('aria-disabled', state.steps[current].available ? 'false' : 'true');
            });

            $('.fls-checkout-steps-nav__line').each(function (index) {
                const stepNum = index + 1;
                $(this)
                    .toggleClass('is-complete', !!state.steps[stepNum].completed && step > stepNum)
                    .toggleClass('is-active', stepNum === step && step < Config.totalSteps);
            });

            this._togglePanels(step, immediate);
            this.updateButtons();
        },

        go(step, options) {
            const settings = options || {};
            const state = State.get();
            const previousStep = state.activeStep || 1;
            const normalized = Math.max(1, Math.min(step, this._maxAllowed()));

            state.steps[normalized].available = true;
            state.activeStep = normalized;
            this.syncUi(normalized, !!settings.immediate);

            if (normalized === 3 && previousStep !== 3) {
                const $payment = $('#fls-checkout-payment');
                if ($payment.length) {
                    setTimeout(function () {
                        $(document.body).trigger('update_checkout');
                        setTimeout(function () {
                            const $sel = $payment.find('input[name="payment_method"]:checked');
                            if ($sel.length) $sel.trigger('change');
                            $(document.body).trigger('payment_method_selected');
                        }, 420);
                    }, 80);
                }
            }
        },

        next(targetStep) {
            const state = State.get();
            const current = this.active();

            if (current === 1) {
                if (!Validation.step(1, true)) {
                    this.showNotice('error', Config.i18n('stepOneError', 'Please complete the required customer details before continuing.'));
                    this.go(1);
                    return;
                }
                state.steps[1].completed = true;
                state.steps[2].available = true;

                const useDifferent = $('#ship-to-different-address-checkbox').is(':checked');
                const postcode = $.trim((useDifferent ? $('#shipping_postcode') : $('#billing_postcode')).val() || '');

                if (postcode) {
                    $('.fls-checkout-step-notice').remove();
                    Delivery.calculateShipping(postcode, function (success, message) {
                        if (!success && message) Delivery.showPanelError(message);
                        Steps.go(targetStep);
                    });
                    return;
                }
            }

            if (current === 2) {
                const mode = Delivery.getMode();
                if (!Delivery.needsShipping() || !Delivery.selectedCard(mode).length) {
                    this.showNotice('error', Config.i18n('stepTwoError', 'Please choose a delivery option before continuing.'));
                    this.go(2);
                    return;
                }
                if (!Delivery.getDate(mode)) {
                    $('[data-fls-date-wrap="' + mode + '"]').addClass('is-invalid');
                    this.showNotice('error', Config.i18n('stepTwoDateError', 'Please choose a date before continuing.'));
                    this.go(2);
                    return;
                }
                state.steps[2].completed = true;
                state.steps[3].available = true;
            }

            $('.fls-checkout-step-notice').remove();
            this.go(targetStep);
        },

        prev(targetStep) {
            $('.fls-checkout-step-notice').remove();
            this.go(targetStep);
        }
    };

    // -- Payment ----------------------------------------------------------------

    const Payment = {
        sync() {
            const $payment = $('#fls-checkout-payment');
            if (!$payment.length) return;
            $payment.find('.wc_payment_method').removeClass('is-selected');
            $payment.find('input[name="payment_method"]:checked').each(function () {
                $(this).closest('.wc_payment_method').addClass('is-selected');
            });
        },

        bind() {
            $(document)
                .off('change.flsPaymentMethod')
                .on('change.flsPaymentMethod', '#fls-checkout-payment input[name="payment_method"]', function () {
                    Payment.sync();
                });
        }
    };

    // -- Address ----------------------------------------------------------------

    const Address = {
        syncChoiceUi() {
            const $choice = $('[data-fls-address-choice]').first();
            const $checkbox = $('#ship-to-different-address-checkbox');
            if (!$choice.length || !$checkbox.length) return;
            const isDifferent = $checkbox.is(':checked');
            $choice.find('[data-fls-address-mode="same"]')
                .toggleClass('is-active', !isDifferent)
                .attr('aria-pressed', !isDifferent ? 'true' : 'false');
            $choice.find('[data-fls-address-mode="different"]')
                .toggleClass('is-active', isDifferent)
                .attr('aria-pressed', isDifferent ? 'true' : 'false');
        },

        syncShippingVisibility(immediate) {
            const $checkbox = $('#ship-to-different-address-checkbox');
            const $address = $('.shipping_address').first();
            if (!$checkbox.length || !$address.length) return;
            if ($checkbox.is(':checked')) {
                immediate ? $address.show() : $address.stop(true, true).slideDown(Config.animDuration);
            } else {
                immediate ? $address.hide() : $address.stop(true, true).slideUp(Config.animDuration);
            }
        },

        bind() {
            $(document)
                .off('click.flsAddressChoice')
                .on('click.flsAddressChoice', '[data-fls-address-mode]', function () {
                    const $checkbox = $('#ship-to-different-address-checkbox');
                    const shouldCheck = $(this).attr('data-fls-address-mode') === 'different';
                    if (!$checkbox.length || $checkbox.is(':checked') === shouldCheck) return;
                    $checkbox.prop('checked', shouldCheck).trigger('change');
                })
                .off('change.flsAddressCheckbox')
                .on('change.flsAddressCheckbox', '#ship-to-different-address-checkbox', function () {
                    Address.syncChoiceUi();
                    Address.syncShippingVisibility(false);
                    Validation.maybeDowngrade();
                    Steps.updateButtons();
                    Steps.syncUi(Steps.active(), true);
                    $(document.body).trigger('update_checkout');
                });
        }
    };

    // -- Coupon -----------------------------------------------------------------

    const Coupon = {
        apply(code, $button) {
            const ajaxUrl = Config.wcAjaxUrl('apply_coupon');
            const nonce = Config.couponNonce('apply');
            if (!ajaxUrl || !code) return;
            Toast.clear();
            DOM.setButtonLoading($button, true);
            $.ajax({ type: 'POST', url: ajaxUrl, dataType: 'html', data: { security: nonce, coupon_code: code } })
                .done(function (response) {
                    const notice = Toast.parseWcResponse(response, 'success', Config.i18n('discountApplied', 'Discount Applied'));
                    Toast.show(notice.type, notice.message);
                    if (notice.type !== 'error') $(document.body).trigger('update_checkout');
                })
                .fail(function () {
                    Toast.show('error', Config.i18n('couponApplyError', 'Something went wrong while applying the coupon.'));
                })
                .always(function () { DOM.setButtonLoading($button, false); });
        },

        remove(code, $button) {
            const ajaxUrl = Config.wcAjaxUrl('remove_coupon');
            const nonce = Config.couponNonce('remove');
            if (!ajaxUrl || !code) return;
            Toast.clear();
            DOM.setButtonLoading($button, true);
            $.ajax({ type: 'POST', url: ajaxUrl, dataType: 'html', data: { security: nonce, coupon: code } })
                .done(function (response) {
                    const notice = Toast.parseWcResponse(response, 'success', Config.i18n('couponRemoved', 'Coupon has been removed.'));
                    Toast.show(notice.type, notice.message);
                    if (notice.type !== 'error') $(document.body).trigger('update_checkout');
                })
                .fail(function () {
                    Toast.show('error', Config.i18n('couponRemoveError', 'Something went wrong while removing the coupon.'));
                })
                .always(function () { DOM.setButtonLoading($button, false); });
        },

        bind() {
            $(document)
                .off('click.flsCouponSubmit')
                .on('click.flsCouponSubmit', '[data-fls-coupon-submit]', function (event) {
                    event.preventDefault();
                    const $button = $(this);
                    const $input = $button.closest('[data-fls-coupon-form]').find('[name="coupon_code"]').first();
                    const code = $.trim($input.val() || '');
                    if (!code) {
                        Toast.show('error', Config.i18n('couponEmpty', 'Please enter a discount code.'));
                        return;
                    }
                    Coupon.apply(code, $button);
                })
                .off('click.flsCouponRemove')
                .on('click.flsCouponRemove', '[data-fls-coupon-remove]', function (event) {
                    event.preventDefault();
                    const code = $.trim($(this).attr('data-coupon-code') || '');
                    if (code) Coupon.remove(code, $(this));
                })
                .off('keydown.flsCouponEnter')
                .on('keydown.flsCouponEnter', '[data-fls-coupon-form] [name="coupon_code"]', function (event) {
                    if (event.key !== 'Enter') return;
                    event.preventDefault();
                    $(this).closest('[data-fls-coupon-form]').find('[data-fls-coupon-submit]').first().trigger('click');
                });
        }
    };

    // -- Events -----------------------------------------------------------------

    const Events = {
        bindSteps() {
            $(document)
                .off('click.flsStepTrigger')
                .on('click.flsStepTrigger', '[data-fls-step-trigger]', function () {
                    const step = parseInt($(this).attr('data-fls-step-trigger'), 10);
                    const state = State.get();
                    if (!step || !state.steps[step] || !state.steps[step].available) return;
                    $('.fls-checkout-step-notice').remove();
                    Steps.go(step);
                })
                .off('click.flsStepNext')
                .on('click.flsStepNext', '[data-fls-step-next]', function () {
                    const step = parseInt($(this).attr('data-fls-step-next'), 10);
                    if (step) Steps.next(step);
                })
                .off('click.flsStepPrev')
                .on('click.flsStepPrev', '[data-fls-step-prev]', function () {
                    const step = parseInt($(this).attr('data-fls-step-prev'), 10);
                    if (step) Steps.prev(step);
                });
        },

        bindSummaryToggle() {
            $(document)
                .off('click.flsSummaryToggle')
                .on('click.flsSummaryToggle', '[data-fls-summary-toggle]', function () {
                    const $button = $(this);
                    const $body = $('[data-fls-summary-body]').first();
                    const expanded = $button.attr('aria-expanded') === 'true';
                    $button.attr('aria-expanded', expanded ? 'false' : 'true').toggleClass('is-open', !expanded);
                    $body.stop(true, true).slideToggle(Config.animDuration);
                });
        },

        bindVatToggle() {
            $(document)
                .off('click.flsVatToggle')
                .on('click.flsVatToggle', '[data-fls-vat-toggle]', function () {
                    const $button = $(this);
                    const $breakdown = $button.closest('.fls-order-details__vat-block').find('[data-fls-vat-breakdown]').first();
                    const expanded = $button.attr('aria-expanded') === 'true';
                    $button.attr('aria-expanded', expanded ? 'false' : 'true').toggleClass('is-open', !expanded);
                    $breakdown.stop(true, true).slideToggle(Config.animDuration);
                });
        },

        bindDelivery() {
            $(document)
                .off('click.flsDeliveryTab')
                .on('click.flsDeliveryTab', '[data-fls-delivery-tab]', function () {
                    const mode = $(this).attr('data-fls-delivery-tab');
                    Delivery.setMode(mode);
                    Delivery.ensureSelectedRate(mode);
                    Delivery.syncCards();
                    Delivery.syncUi();
                    Validation.maybeDowngrade();
                    Steps.updateButtons();
                })
                .off('change.flsShippingCard')
                .on('change.flsShippingCard', '.shipping_method', function () {
                    const $card = $(this).closest('[data-fls-shipping-card]');
                    const mode = $card.attr('data-mode') || Delivery.getMode();
                    Delivery.setMode(mode);
                    Delivery.syncCards();
                    Delivery.syncUi();
                    Validation.maybeDowngrade();
                    Steps.updateButtons();
                    $(document.body).trigger('update_checkout');
                });
        },

        bindDatePicker() {
            $(document)
                .off('click.flsDatePickerOpen')
                .on('click.flsDatePickerOpen', '[data-fls-date-display], .fls-delivery-method__date-icon', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const $target = $(this);
                    const mode = $target.attr('data-fls-date-display')
                        || $target.closest('[data-fls-date-wrap]').attr('data-fls-date-wrap');
                    DatePicker.open(mode);
                });
        },

        bindFieldWatchers() {
            $(document)
                .off('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow')
                .on('input.flsCheckoutFlow change.flsCheckoutFlow blur.flsCheckoutFlow',
                    '[data-fls-step="1"] input, [data-fls-step="1"] select, [data-fls-step="1"] textarea',
                    function () {
                        State.get().deliveryAvailable = null;
                        Validation.maybeDowngrade();
                        Steps.updateButtons();
                        Steps.syncUi(Steps.active(), true);
                    });
        },

        bindAll() {
            this.bindSteps();
            this.bindSummaryToggle();
            this.bindVatToggle();
            Coupon.bind();
            Address.bind();
            this.bindDelivery();
            this.bindDatePicker();
            this.bindFieldWatchers();
            Payment.bind();
        }
    };

    // -- Boot -------------------------------------------------------------------

    function initDeliveryState() {
        const defaultMode = $('[data-fls-delivery-method]').attr('data-default-mode') || 'delivery';
        const state = State.get();
        const hiddenMode = $.trim($('[data-fls-delivery-mode-input]').val());
        const hiddenDate = $.trim($('[data-fls-delivery-date-input]').val());

        if (!state.deliveryMode) state.deliveryMode = hiddenMode || defaultMode;
        if (hiddenDate && !state.dates[state.deliveryMode]) state.dates[state.deliveryMode] = hiddenDate;

        Delivery.setMode(state.deliveryMode || defaultMode);
        Delivery.ensureSelectedRate(Delivery.getMode());
        Delivery.syncCards();
        DatePicker.init();
        Delivery.syncUi();
    }

    function init(immediate) {
        Events.bindAll();
        Address.syncChoiceUi();
        Address.syncShippingVisibility(!!immediate);
        initDeliveryState();
        Validation.maybeDowngrade();
        Steps.go(Steps.active(), { immediate: !!immediate });
        Toast.position();
        Payment.sync();
    }

    $(window)
        .off('resize.flsToastPosition scroll.flsToastPosition')
        .on('resize.flsToastPosition scroll.flsToastPosition', function () {
            Toast.position();
        });

    $(document.body).on('updated_checkout', function () {
        if (State.get().calculatingShipping) {
            initDeliveryState();
            Steps.updateButtons();
        } else {
            init(true);
        }
        if (State.get().deliveryAvailable === false) {
            Delivery.ensureBlockedUi();
        }
        setTimeout(function () { Toast.position(); }, 60);
    });

    $(function () {
        init(true);
    });

})(jQuery);
