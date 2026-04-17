<?php
/**
 * NMI (Network Merchants Inc) payment gateway.
 *
 * Integrates with NMI's Collect.js for client-side tokenization and the
 * NMI Direct Post API for server-side charge processing.
 *
 * Flow:
 *   1. Browser: Collect.js swaps PAN → payment-token (token never hits our server)
 *   2. Server:  POST /api/transact.php with security_key + payment_token + amount
 *   3. Server:  Parse response, update WC order status
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Gateway_NMI extends WC_Payment_Gateway {

    /** NMI Direct Post API endpoint. */
    private const API_ENDPOINT = 'https://secure.nmi.com/api/transact.php';

    /** NMI sandbox endpoint. */
    private const SANDBOX_ENDPOINT = 'https://secure.nmi.com/api/transact.php';

    private string $security_key;
    private string $collectjs_key;
    private bool   $test_mode;

    public function __construct() {
        $this->id                 = 'nmi';
        $this->method_title       = __( 'NMI (Network Merchants)', 'wc-gateway-hr' );
        $this->method_description = __( 'High-risk payment processing via NMI with Collect.js tokenization. PAN never touches your server.', 'wc-gateway-hr' );
        $this->has_fields         = true;
        $this->supports           = [ 'products', 'refunds', 'tokenization', 'add_payment_method' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title', 'Credit / Debit Card (NMI)' );
        $this->description  = $this->get_option( 'description', 'Pay securely via NMI. Your card details are tokenized and never stored on our server.' );
        $this->security_key = $this->get_option( 'security_key', '' );
        $this->collectjs_key = $this->get_option( 'collectjs_key', '' );
        $this->test_mode    = 'yes' === $this->get_option( 'test_mode', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'        => [ 'title' => __( 'Enable', 'wc-gateway-hr' ), 'type' => 'checkbox', 'default' => 'yes' ],
            'title'          => [ 'title' => __( 'Title', 'wc-gateway-hr' ), 'type' => 'text', 'default' => 'Credit / Debit Card' ],
            'description'    => [ 'title' => __( 'Description', 'wc-gateway-hr' ), 'type' => 'textarea' ],
            'security_key'   => [ 'title' => __( 'NMI Security Key', 'wc-gateway-hr' ), 'type' => 'password', 'description' => __( 'Found in NMI merchant portal → Security Keys.', 'wc-gateway-hr' ) ],
            'collectjs_key'  => [ 'title' => __( 'Collect.js Tokenization Key', 'wc-gateway-hr' ), 'type' => 'text' ],
            'webhook_secret' => [ 'title' => __( 'Webhook HMAC Secret', 'wc-gateway-hr' ), 'type' => 'password', 'description' => __( 'Used to verify NMI webhook signatures (SHA-512 HMAC).', 'wc-gateway-hr' ) ],
            'test_mode'      => [ 'title' => __( 'Test Mode', 'wc-gateway-hr' ), 'type' => 'checkbox', 'default' => 'yes', 'description' => __( 'Routes to NMI sandbox. Use test card 4111111111111111.', 'wc-gateway-hr' ) ],
        ];
    }

    public function enqueue_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_script(
            'nmi-collectjs',
            'https://secure.nmi.com/token/Collect.js',
            [],
            null,
            true
        );
        wp_add_inline_script( 'nmi-collectjs', sprintf(
            'CollectJS.configure({tokenizationKey: "%s", callback: function(response) { document.getElementById("nmi_payment_token").value = response.token; document.getElementById("nmi-checkout-form").submit(); }});',
            esc_js( $this->collectjs_key )
        ) );
    }

    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        if ( $this->test_mode ) {
            echo '<p style="background:#fff3cd;padding:8px;border-radius:4px;"><strong>Test mode active.</strong> Use card 4111111111111111, any future expiry, CVV 999.</p>';
        }
        // Hidden field — populated by Collect.js after tokenizing PAN in browser.
        echo '<input type="hidden" id="nmi_payment_token" name="nmi_payment_token" value="" />';
        // Collect.js renders card fields in these divs — PAN never reaches our form.
        echo '<div id="ccnumber"></div>';
        echo '<div id="ccexp"></div>';
        echo '<div id="cvv"></div>';
    }

    public function validate_fields(): bool {
        if ( empty( $_POST['nmi_payment_token'] ) ) {
            wc_add_notice( __( 'Payment tokenization failed. Please check your card details and try again.', 'wc-gateway-hr' ), 'error' );
            return false;
        }
        return true;
    }

    public function process_payment( int $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }

        // Idempotency check — prevent double-charge on network retry.
        $idempotency = new WC_Idempotency();
        if ( $idempotency->already_processed( $order_id ) ) {
            $order->add_order_note( __( 'NMI: duplicate submission detected; skipped.', 'wc-gateway-hr' ) );
            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        $token = sanitize_text_field( wp_unslash( $_POST['nmi_payment_token'] ?? '' ) );

        $response = $this->send_charge_request( $order, $token );

        if ( isset( $response['response'] ) && '1' === $response['response'] ) {
            // Approved.
            $order->payment_complete( $response['transactionid'] ?? '' );
            $order->add_order_note( sprintf(
                /* translators: 1: transaction ID, 2: auth code */
                __( 'NMI charge approved. Transaction ID: %1$s. Auth code: %2$s.', 'wc-gateway-hr' ),
                $response['transactionid'] ?? '',
                $response['authcode'] ?? ''
            ) );
            $idempotency->mark_processed( $order_id );

            // Store token for future purchases.
            $tm = new WC_Tokenization_Manager();
            $tm->save_token( $order->get_customer_id(), 'nmi', $response['customer_vault_id'] ?? $token );

            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        // Declined or error.
        $error_msg = $response['responsetext'] ?? __( 'Payment declined.', 'wc-gateway-hr' );
        $order->add_order_note( 'NMI payment failed: ' . $error_msg );
        wc_add_notice( $error_msg, 'error' );
        return [ 'result' => 'failure' ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        $order = wc_get_order( $order_id );
        $transaction_id = $order->get_transaction_id();

        if ( ! $transaction_id ) {
            return new \WP_Error( 'nmi_refund', __( 'No transaction ID found for this order.', 'wc-gateway-hr' ) );
        }

        $response = $this->post_to_api( [
            'type'          => 'refund',
            'security_key'  => $this->security_key,
            'transactionid' => $transaction_id,
            'amount'        => number_format( (float) $amount, 2, '.', '' ),
        ] );

        if ( isset( $response['response'] ) && '1' === $response['response'] ) {
            $order->add_order_note( sprintf( 'NMI refund of %s approved. Refund ID: %s', wc_price( $amount ), $response['transactionid'] ?? '' ) );
            return true;
        }

        return new \WP_Error( 'nmi_refund_failed', $response['responsetext'] ?? 'Refund failed.' );
    }

    /**
     * Build and send a charge request to the NMI API.
     *
     * @param \WC_Order $order   The WooCommerce order.
     * @param string    $token   Collect.js payment token.
     * @return array             Parsed NMI response key-value pairs.
     */
    private function send_charge_request( \WC_Order $order, string $token ): array {
        $params = [
            'type'             => 'sale',
            'security_key'     => $this->security_key,
            'payment_token'    => $token,
            'amount'           => number_format( (float) $order->get_total(), 2, '.', '' ),
            'currency'         => get_woocommerce_currency(),
            'orderid'          => $order->get_order_number(),
            'order_description' => sprintf( 'Order %s from %s', $order->get_order_number(), get_bloginfo( 'name' ) ),
            'firstname'        => $order->get_billing_first_name(),
            'lastname'         => $order->get_billing_last_name(),
            'email'            => $order->get_billing_email(),
            'address1'         => $order->get_billing_address_1(),
            'city'             => $order->get_billing_city(),
            'state'            => $order->get_billing_state(),
            'zip'              => $order->get_billing_postcode(),
            'country'          => $order->get_billing_country(),
            // Request a customer vault ID for future tokenized purchases.
            'customer_vault'   => 'add_customer',
        ];

        if ( $this->test_mode ) {
            $params['test_mode'] = 'enabled';
        }

        return $this->post_to_api( $params );
    }

    /**
     * POST parameters to NMI API; parse the key=value response.
     *
     * @param array $params Request parameters.
     * @return array        Parsed response.
     */
    private function post_to_api( array $params ): array {
        $endpoint = $this->test_mode ? self::SANDBOX_ENDPOINT : self::API_ENDPOINT;

        $http_response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 30,
            'body'    => $params,
        ] );

        if ( is_wp_error( $http_response ) ) {
            wc_get_logger()->error( 'NMI API error: ' . $http_response->get_error_message(), [ 'source' => 'nmi-gateway' ] );
            return [ 'response' => '3', 'responsetext' => 'Connection to payment processor failed.' ];
        }

        parse_str( wp_remote_retrieve_body( $http_response ), $parsed );
        return $parsed;
    }
}
