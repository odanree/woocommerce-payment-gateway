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
        // Back button: visible on steps 2+
        $('.wc-step-nav__back').toggle(currentStep > 1);

        // Next buttons per panel — only show the one for the current step.
        // #place_order stays inside #payment (step 2) at all times; the review
        // step uses .wc-place-order-trigger which programmatically submits.
        for (let s = 1; s <= TOTAL_STEPS; s++) {
            const $nav = $(`.wc-checkout-step[data-step="${s}"] .wc-step-nav`);
            $nav.toggle(s === currentStep);
        }
        // Always keep real WC button hidden — we drive submission from step 3 trigger
        $('#place_order').hide();
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
        const $form = $('form.woocommerce-checkout');

        // Detach pieces we need to rearrange
        const $payment  = $('#payment').detach();
        const $progress = $('.wc-multistep-progress').detach();
        const $heading  = $('#order_review_heading').detach();
        const $review   = $('#order_review').detach();

        // ── Build 2-column layout ────────────────────────────────────────────
        // Main column: progress bar + step panels (left)
        // Sidebar: order summary always visible (right)
        const $layout  = $('<div class="wc-checkout-layout">');
        const $main    = $('<div class="wc-checkout-main">');
        const $sidebar = $('<div class="wc-checkout-sidebar">');

        // Step 1: billing / shipping
        const $step1 = $('<div class="wc-checkout-step" data-step="1">');
        $step1.append($('#customer_details'));
        $main.append($step1);

        // Step 2: payment
        const $step2 = $('<div class="wc-checkout-step" data-step="2">');
        $step2.append($payment);
        $main.append($step2);

        // Step 3: confirmation (order summary is always visible in sidebar)
        const $step3 = $('<div class="wc-checkout-step" data-step="3">');
        $step3.append('<p class="wc-review-note">Review your order in the summary, then place your order.</p>');
        $main.append($step3);

        // Sidebar: heading + order review (always visible on all steps)
        $sidebar.append($heading, $review);

        // Hidden step field and assemble
        $('<input type="hidden" name="wc_checkout_step" value="1">').appendTo($review);
        $layout.append($main, $sidebar);
        // Progress bar sits above the 2-column layout (spans full width)
        $form.append($progress, $layout);

        // ── Nav buttons ──────────────────────────────────────────────────────
        for (let s = 1; s < TOTAL_STEPS; s++) {
            const $nav = $(`
                <div class="wc-step-nav">
                    <button type="button" class="wc-step-nav__back" style="display:none">← Back</button>
                    <button type="button" class="wc-step-nav__next">Continue →</button>
                </div>
            `);
            $(`.wc-checkout-step[data-step="${s}"]`).append($nav);
        }

        // Step 3 place order trigger
        $step3.append(`
            <div class="wc-step-nav wc-step-nav--place-order">
                <button type="button" class="wc-step-nav__back">← Back</button>
                <button type="button" class="wc-step-nav__next wc-place-order-trigger">Place Order</button>
            </div>
        `);

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

        // Place Order trigger on review step: click the real WC button in step 2.
        // We keep #place_order inside #payment so WC's update_checkout AJAX never
        // destroys it; we just programmatically fire it from the review step.
        $(document).on('click', '.wc-place-order-trigger', function () {
            $('#place_order').trigger('click');
        });

        // When WooCommerce triggers a checkout error (e.g. payment declined),
        // jump back to the payment step so the customer can fix their card.
        $(document.body).on('checkout_error', function () {
            if (currentStep === TOTAL_STEPS) {
                goToStep(2); // back to payment step
            }
        });

        // After WC replaces #payment content, keep #place_order hidden.
        $(document.body).on('updated_checkout', function () {
            $('#place_order').hide();
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────

    $(function () {
        if ($('.woocommerce-checkout').length === 0) return;

        initStepPanels();
        bindEvents();
    });
}(jQuery));
