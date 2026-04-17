/**
 * Multi-step checkout controller
 *
 * Progressive enhancement — the checkout still submits correctly without JS
 * (all fields are present in the DOM; PHP validates the full form on submit).
 * JS adds step transitions, validation feedback, and the back button.
 */
/* global wcMultistep, jQuery */
(function ($) {
    'use strict';

    const TOTAL_STEPS = wcMultistep.steps.length;
    let currentStep = 1;

    /**
     * Show the given step panel, hide others, update progress indicator.
     *
     * @param {number} step 1-based step number
     */
    function goToStep(step) {
        if (step < 1 || step > TOTAL_STEPS) return;

        // Update panels
        $('.wc-checkout-step').removeClass('wc-checkout-step--active');
        $(`.wc-checkout-step[data-step="${step}"]`).addClass('wc-checkout-step--active');

        // Update progress indicator
        $('.wc-step').each(function (i) {
            const stepNum = i + 1;
            $(this)
                .toggleClass('wc-step--done', stepNum < step)
                .toggleClass('wc-step--active', stepNum === step);
        });

        // Update hidden field so PHP knows which step was submitted
        $('input[name="wc_checkout_step"]').val(step);

        // Scroll to top of checkout form
        $('html, body').animate({
            scrollTop: $('#customer_details').offset()?.top - 80 ?? 0,
        }, 200);

        currentStep = step;
        updateNavButtons();
    }

    /**
     * Show/hide back button; change next button label on last step.
     */
    function updateNavButtons() {
        const $back = $('.wc-step-nav__back');
        const $next = $('.wc-step-nav__next');

        $back.toggle(currentStep > 1);

        if (currentStep === TOTAL_STEPS) {
            $next.hide();
            // On the final step, the standard WC place-order button takes over.
            $('#place_order').show();
        } else {
            $next.show().text(currentStep === TOTAL_STEPS - 1 ? 'Review order →' : 'Continue →');
            $('#place_order').hide();
        }
    }

    /**
     * Validate required fields in the current step before advancing.
     * Relies on WooCommerce's existing required field markup (aria-required, .required).
     *
     * @returns {boolean} true if all required fields in current step are filled
     */
    function validateCurrentStep() {
        let valid = true;
        const $panel = $(`.wc-checkout-step[data-step="${currentStep}"]`);

        $panel.find('[required], .validate-required input, .validate-required select').each(function () {
            const val = $(this).val()?.trim() ?? '';
            if (!val) {
                $(this).closest('.form-row').addClass('woocommerce-invalid woocommerce-invalid-required-field');
                $(this).trigger('focus');
                valid = false;
                return false; // break — stop on first invalid field
            } else {
                $(this).closest('.form-row').removeClass('woocommerce-invalid');
            }
        });

        if (!valid) {
            // Trigger WooCommerce's built-in error scroll
            $(document.body).trigger('update_checkout');
        }

        return valid;
    }

    /**
     * Wrap checkout form sections into step panels.
     * Called once on DOM ready.
     */
    function initStepPanels() {
        // Step 1: billing/shipping fieldsets
        const $step1 = $('<div class="wc-checkout-step" data-step="1">');
        $('#customer_details').wrap($step1);

        // Step 2: payment method
        const $step2 = $('<div class="wc-checkout-step" data-step="2">');
        $('#payment').wrap($step2);

        // Step 3: order review
        const $step3 = $('<div class="wc-checkout-step" data-step="3">');
        $('.woocommerce-checkout-review-order').wrap($step3);

        // Add hidden step field
        $('<input type="hidden" name="wc_checkout_step" value="1">').appendTo('#order_review');

        // Add nav buttons below each step panel (except last)
        for (let s = 1; s < TOTAL_STEPS; s++) {
            const $nav = $(`
                <div class="wc-step-nav">
                    <button type="button" class="wc-step-nav__back" style="display:none">← Back</button>
                    <button type="button" class="wc-step-nav__next">Continue →</button>
                </div>
            `);
            $(`.wc-checkout-step[data-step="${s}"]`).append($nav);
        }

        // Move place_order button to step 3 (it exists in WC markup inside #payment)
        $('#place_order').appendTo(`.wc-checkout-step[data-step="${TOTAL_STEPS}"]`);

        goToStep(1);
    }

    /**
     * Event delegation for step navigation.
     */
    function bindEvents() {
        $(document).on('click', '.wc-step-nav__next', function () {
            if (validateCurrentStep()) {
                goToStep(currentStep + 1);
            }
        });

        $(document).on('click', '.wc-step-nav__back', function () {
            goToStep(currentStep - 1);
        });

        // When WooCommerce triggers a checkout error (e.g. payment declined),
        // jump back to the payment step so the customer can fix their card.
        $(document.body).on('checkout_error', function () {
            if (currentStep === TOTAL_STEPS) {
                goToStep(2); // back to payment step
            }
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────

    $(function () {
        if ($('.woocommerce-checkout').length === 0) return;

        initStepPanels();
        bindEvents();
    });
}(jQuery));
