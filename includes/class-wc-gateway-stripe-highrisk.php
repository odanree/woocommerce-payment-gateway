<?php
/**
 * Stripe payment gateway — failover processor for high-risk merchants.
 *
 * Uses Stripe PaymentIntents API with Stripe.js Elements for PAN tokenization.
 * Drop-in replacement for NMI: same WC_Payment_Gateway interface, swapped via
 * WC_Gateway_Failover_Manager config — no code change required.
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Stripe_HighRisk extends WC_Payment_Gateway {

    private const API_BASE = 'https://api.stripe.com/v1';

    private string $secret_key;
    private string $publishable_key;
    private bool $test_mode;

    public function __construct() {
        $this->id                 = 'stripe_highrisk';
        $this->method_title       = __( 'Stripe (Failover)', 'wc-gateway-hr' );
        $this->method_description = __( 'Stripe PaymentIntents with Stripe.js Elements tokenization. Activated automatically when NMI is unavailable.', 'wc-gateway-hr' );
        $this->has_fields         = true;
        $this->supports           = [ 'products', 'refunds', 'tokenization' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title           = $this->get_option( 'title', 'Credit / Debit Card (Stripe)' );
        $this->description     = $this->get_option( 'description', 'Secure card processing via Stripe.' );
        $this->secret_key      = $this->get_option( 'secret_key', '' );
        $this->publishable_key = $this->get_option( 'publishable_key', '' );
        $this->test_mode       = 'yes' === $this->get_option( 'test_mode', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'         => [ 'title' => __( 'Enable', 'wc-gateway-hr' ), 'type' => 'checkbox', 'default' => 'no' ],
            'title'           => [ 'title' => __( 'Title', 'wc-gateway-hr' ), 'type' => 'text', 'default' => 'Credit / Debit Card' ],
            'description'     => [ 'title' => __( 'Description', 'wc-gateway-hr' ), 'type' => 'textarea' ],
            'secret_key'      => [ 'title' => __( 'Stripe Secret Key', 'wc-gateway-hr' ), 'type' => 'password' ],
            'publishable_key' => [ 'title' => __( 'Stripe Publishable Key', 'wc-gateway-hr' ), 'type' => 'text' ],
            'webhook_secret'  => [ 'title' => __( 'Stripe Webhook Secret', 'wc-gateway-hr' ), 'type' => 'password', 'description' => __( 'whsec_... from Stripe dashboard. Used for Stripe-Signature header verification.', 'wc-gateway-hr' ) ],
            'test_mode'       => [ 'title' => __( 'Test Mode', 'wc-gateway-hr' ), 'type' => 'checkbox', 'default' => 'yes' ],
        ];
    }

    public function enqueue_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; version is controlled by Stripe.
        wp_add_inline_script( 'stripe-js', sprintf(
            'const stripe = Stripe("%s"); const elements = stripe.elements(); const cardElement = elements.create("card"); cardElement.mount("#stripe-card-element"); document.getElementById("stripe-checkout-form").addEventListener("submit", async (e) => { e.preventDefault(); const {paymentMethod, error} = await stripe.createPaymentMethod({type:"card", card:cardElement}); if(error){document.getElementById("stripe-card-errors").textContent=error.message;}else{document.getElementById("stripe_payment_method_id").value=paymentMethod.id; e.target.submit();} });',
            esc_js( $this->publishable_key )
        ) );
    }

    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        if ( $this->test_mode ) {
            echo '<p style="background:#fff3cd;padding:8px;border-radius:4px;"><strong>Test mode active.</strong> Use card 4242424242424242, any future expiry, any CVV.</p>';
        }
        echo '<input type="hidden" id="stripe_payment_method_id" name="stripe_payment_method_id" value="" />';
        echo '<div id="stripe-card-element"></div>';
        echo '<div id="stripe-card-errors" role="alert" style="color:#cc0000;margin-top:8px;"></div>';
    }

    public function validate_fields(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce (woocommerce-process-checkout) covers this field.
        if ( empty( $_POST['stripe_payment_method_id'] ) ) {
            wc_add_notice( __( 'Payment tokenization failed. Please check your card details.', 'wc-gateway-hr' ), 'error' );
            return false;
        }
        return true;
    }

    public function process_payment( int $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }

        $idempotency = new WC_Idempotency();
        if ( $idempotency->already_processed( $order_id ) ) {
            $order->add_order_note( __( 'Stripe: duplicate submission detected; skipped.', 'wc-gateway-hr' ) );
            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce (woocommerce-process-checkout) covers this field.
        $payment_method_id = sanitize_text_field( wp_unslash( $_POST['stripe_payment_method_id'] ?? '' ) );

        $intent = $this->create_payment_intent( $order, $payment_method_id );

        if ( isset( $intent['status'] ) && in_array( $intent['status'], [ 'succeeded', 'requires_capture' ], true ) ) {
            $order->payment_complete( $intent['id'] ?? '' );
            $order->add_order_note( sprintf(
                /* translators: %s: Stripe PaymentIntent ID */
                __( 'Stripe PaymentIntent succeeded. Intent ID: %s.', 'wc-gateway-hr' ),
                $intent['id'] ?? ''
            ) );
            $idempotency->mark_processed( $order_id );
            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        if ( isset( $intent['status'] ) && 'requires_action' === $intent['status'] ) {
            // 3DS authentication required — redirect to Stripe confirmation.
            return [
                'result'               => 'success',
                'redirect'             => $intent['next_action']['redirect_to_url']['url'] ?? wc_get_checkout_url(),
                'payment_intent'       => $intent['id'],
                'payment_intent_nonce' => wp_create_nonce( 'stripe_payment_intent' ),
            ];
        }

        $error_msg = $intent['error']['message'] ?? __( 'Stripe payment failed.', 'wc-gateway-hr' );
        $order->add_order_note( 'Stripe error: ' . $error_msg );
        wc_add_notice( $error_msg, 'error' );
        return [ 'result' => 'failure' ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        $order = wc_get_order( $order_id );
        $charge_id = $order->get_transaction_id();

        if ( ! $charge_id ) {
            return new \WP_Error( 'stripe_refund', __( 'No Stripe charge ID found.', 'wc-gateway-hr' ) );
        }

        $response = $this->stripe_request( 'POST', '/refunds', [
            'charge' => $charge_id,
            'amount' => (int) round( (float) $amount * 100 ), // Stripe uses cents.
            'reason' => ! empty( $reason ) ? $reason : 'requested_by_customer',
        ] );

        if ( isset( $response['id'] ) && 0 === strpos( $response['id'], 're_' ) ) {
            $order->add_order_note( sprintf( 'Stripe refund %s approved for %s.', $response['id'], wc_price( $amount ) ) );
            return true;
        }

        return new \WP_Error( 'stripe_refund_failed', $response['error']['message'] ?? 'Refund failed.' );
    }

    /**
     * Create a Stripe PaymentIntent and immediately confirm it.
     *
     * @param \WC_Order $order             WooCommerce order.
     * @param string    $payment_method_id Stripe payment method ID (pm_...).
     * @return array                       Stripe API response.
     */
    private function create_payment_intent( \WC_Order $order, string $payment_method_id ): array {
        $amount_cents = (int) round( (float) $order->get_total() * 100 );

        return $this->stripe_request( 'POST', '/payment_intents', [
            'amount'               => $amount_cents,
            'currency'             => strtolower( get_woocommerce_currency() ),
            'payment_method'       => $payment_method_id,
            'confirmation_method'  => 'manual',
            'confirm'              => 'true',
            'description'          => sprintf( 'WooCommerce Order %s', $order->get_order_number() ),
            'metadata'             => [
                'order_id'         => $order->get_id(),
                'order_number'     => $order->get_order_number(),
                'customer_email'   => $order->get_billing_email(),
            ],
            'receipt_email'        => $order->get_billing_email(),
        ] );
    }

    /**
     * Make an authenticated request to the Stripe REST API.
     *
     * @param string $method   HTTP method (GET, POST).
     * @param string $path     API path (e.g. '/payment_intents').
     * @param array  $body     Request body parameters.
     * @return array           Decoded JSON response.
     */
    public function stripe_request( string $method, string $path, array $body = [] ): array {
        $response = wp_remote_request( self::API_BASE . $path, [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2024-04-10',
            ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            wc_get_logger()->error( 'Stripe API error: ' . $response->get_error_message(), [ 'source' => 'stripe-gateway' ] );
            return [ 'error' => [ 'message' => 'Connection to Stripe failed.' ] ];
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
