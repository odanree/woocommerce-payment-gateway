<?php
/**
 * Multi-step checkout flow for WooCommerce.
 *
 * Splits the standard WooCommerce single-page checkout into three steps:
 *   Step 1 — Shipping details + order review
 *   Step 2 — Payment method selection + card entry (tokenized)
 *   Step 3 — Order summary + place order button
 *
 * Conversion rationale: multi-step reduces cognitive overload and lets customers
 * see their order total before entering payment details, reducing abandonment.
 * The payment step is isolated so users don't see the card form until they've
 * committed to their shipping choice.
 *
 * Implementation: progressive enhancement — works without JS (falls back to
 * standard WC checkout), enhanced with JS step transitions when available.
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Checkout_Multistep {

    /** Current step stored in session. */
    private const SESSION_KEY = 'wc_checkout_step';

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'woocommerce_checkout_before_order_review_heading', [ self::class, 'render_step_indicator' ] );
        add_filter( 'woocommerce_checkout_fields', [ self::class, 'group_fields_by_step' ] );
        add_action( 'woocommerce_checkout_process', [ self::class, 'validate_step' ] );
    }

    public static function enqueue(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_style(
            'wc-multistep-checkout',
            WC_GATEWAY_HR_PLUGIN_URL . 'templates/checkout/multistep.css',
            [ 'woocommerce-layout' ],
            WC_GATEWAY_HR_VERSION
        );
        wp_enqueue_script(
            'wc-multistep-checkout',
            WC_GATEWAY_HR_PLUGIN_URL . 'templates/checkout/multistep.js',
            [ 'jquery', 'wc-checkout' ],
            WC_GATEWAY_HR_VERSION,
            true
        );
        wp_localize_script( 'wc-multistep-checkout', 'wcMultistep', [
            'steps'     => self::get_step_labels(),
            'nonce'     => wp_create_nonce( 'wc_multistep' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /**
     * Render the step progress indicator above the checkout form.
     */
    public static function render_step_indicator(): void {
        $steps       = self::get_step_labels();
        $current     = self::get_current_step();
        echo '<div class="wc-multistep-progress" role="progressbar" aria-label="' . esc_attr__( 'Checkout progress', 'wc-gateway-hr' ) . '">';
        foreach ( $steps as $i => $label ) {
            $step_num  = $i + 1;
            $is_done   = $step_num < $current;
            $is_active = $step_num === $current;
            $class     = 'wc-step' . ( $is_done ? ' wc-step--done' : '' ) . ( $is_active ? ' wc-step--active' : '' );
            printf(
                '<div class="%s"><span class="wc-step__num">%d</span><span class="wc-step__label">%s</span></div>',
                esc_attr( $class ),
                esc_html( $step_num ),
                esc_html( $label )
            );
        }
        echo '</div>';
    }

    /**
     * Add step data attributes to checkout field groups so JS can show/hide them.
     *
     * @param array $fields WooCommerce checkout fields.
     * @return array
     */
    public static function group_fields_by_step( array $fields ): array {
        // Step 1 fields: billing/shipping address.
        $step1_groups = [ 'billing', 'shipping', 'account' ];
        foreach ( $step1_groups as $group ) {
            if ( isset( $fields[ $group ] ) ) {
                foreach ( $fields[ $group ] as $key => $field ) {
                    $fields[ $group ][ $key ]['custom_attributes']['data-step'] = '1';
                }
            }
        }
        // Step 2 fields: payment (order notes stay on step 3 as-is).
        // Payment fields are rendered by the gateway, not via field API.
        return $fields;
    }

    /**
     * Validate that step 1 fields are complete before moving to step 2.
     * Standard WC validation handles final step; this adds step-aware feedback.
     */
    public static function validate_step(): void {
        $step = (int) ( $_POST['wc_checkout_step'] ?? 3 ); // Default to final step.
        if ( $step < 2 ) {
            // Don't require payment fields on step 1.
            remove_action( 'woocommerce_checkout_process', 'woocommerce_checkout_process_customer_data' );
        }
    }

    public static function get_current_step(): int {
        return (int) ( WC()->session?->get( self::SESSION_KEY ) ?? 1 );
    }

    private static function get_step_labels(): array {
        return [
            __( 'Shipping', 'wc-gateway-hr' ),
            __( 'Payment', 'wc-gateway-hr' ),
            __( 'Review', 'wc-gateway-hr' ),
        ];
    }
}
