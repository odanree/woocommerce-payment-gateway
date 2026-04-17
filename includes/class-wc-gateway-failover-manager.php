<?php
/**
 * Failover manager — routes payment processing to NMI or Stripe based on config.
 *
 * This class is what WooCommerce sees as the payment gateway. It delegates to
 * the active processor class based on the `wc_gateway_active` WordPress option.
 *
 * Switching processors requires ONE config change — no code rewrite:
 *   update_option('wc_gateway_active', 'stripe');
 *
 * ADR: docs/adr/002-stripe-failover-config-swap.md
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Failover_Manager extends WC_Payment_Gateway {

    private WC_Payment_Gateway $active_gateway;

    public function __construct() {
        $this->id                 = 'highrisk_gateway';
        $this->method_title       = __( 'High-Risk Payment Gateway', 'wc-gateway-hr' );
        $this->method_description = __( 'Routes to NMI (primary) or Stripe (failover) based on active gateway setting.', 'wc-gateway-hr' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'Credit / Debit Card' );
        $this->description = $this->get_option( 'description', '' );

        $this->active_gateway = $this->resolve_active_gateway();

        // Forward WC admin save hook.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'        => [ 'title' => __( 'Enable', 'wc-gateway-hr' ), 'type' => 'checkbox', 'default' => 'yes' ],
            'title'          => [ 'title' => __( 'Title', 'wc-gateway-hr' ), 'type' => 'text', 'default' => 'Credit / Debit Card' ],
            'description'    => [ 'title' => __( 'Description', 'wc-gateway-hr' ), 'type' => 'textarea' ],
            'active_gateway' => [
                'title'       => __( 'Active Gateway', 'wc-gateway-hr' ),
                'type'        => 'select',
                'options'     => [ 'nmi' => 'NMI (Primary)', 'stripe' => 'Stripe (Failover)' ],
                'default'     => 'nmi',
                'description' => __( 'Switch processors without any code change. NMI is the primary high-risk processor; switch to Stripe instantly if NMI relationship is terminated.', 'wc-gateway-hr' ),
            ],
        ];
    }

    /**
     * Resolve the active gateway instance from settings.
     *
     * @return WC_Payment_Gateway
     */
    private function resolve_active_gateway(): WC_Payment_Gateway {
        $active = $this->get_option( 'active_gateway', 'nmi' );

        return match ( $active ) {
            'stripe' => new WC_Gateway_Stripe_HighRisk(),
            default  => new WC_Gateway_NMI(),
        };
    }

    /**
     * Return the currently active processor identifier.
     */
    public function get_active_processor(): string {
        return $this->get_option( 'active_gateway', 'nmi' );
    }

    // ── Delegate all gateway methods to the active processor ─────────────────

    public function has_fields(): bool {
        return $this->active_gateway->has_fields();
    }

    public function payment_fields(): void {
        $this->active_gateway->payment_fields();
    }

    public function validate_fields(): bool {
        return $this->active_gateway->validate_fields();
    }

    public function process_payment( $order_id ): array {
        return $this->active_gateway->process_payment( (int) $order_id );
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        return $this->active_gateway->process_refund( $order_id, $amount, $reason );
    }

    public function admin_options(): void {
        echo '<h2>' . esc_html( $this->method_title ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Active processor:', 'wc-gateway-hr' ) . '</strong> ' . esc_html( strtoupper( $this->get_active_processor() ) ) . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html( $this->form_fields, true );
        echo '</table>';
    }
}
