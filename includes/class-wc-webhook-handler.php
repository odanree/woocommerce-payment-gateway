<?php
/**
 * Webhook handler for NMI and Stripe post-payment notifications.
 *
 * Both processors send async webhooks for:
 * - Delayed settlement notifications
 * - Dispute / chargeback creation
 * - Refund confirmation
 * - Subscription renewal (if using NMI recurring billing)
 *
 * Security controls:
 * - NMI:    HMAC-SHA512 signature in X-Webhook-Signature header
 * - Stripe: Stripe-Signature header (HMAC-SHA256 with timestamp replay protection)
 *
 * ADR: docs/adr/003-tokenization-approach.md
 *      docs/pci-dss/webhook-security-controls.md
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Webhook_Handler {

    /**
     * Register WooCommerce API endpoints.
     * Called from plugin init — hooks into `woocommerce_api_{slug}`.
     */
    public static function register(): void {
        add_action( 'woocommerce_api_nmi_webhook', [ self::class, 'handle_nmi' ] );
        add_action( 'woocommerce_api_stripe_webhook', [ self::class, 'handle_stripe' ] );
    }

    /**
     * Handle incoming NMI webhook.
     * Endpoint: POST /wc-api/nmi_webhook
     */
    public static function handle_nmi(): void {
        $raw_body = file_get_contents( 'php://input' );
        $sig      = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        $secret   = get_option( 'wc_gateway_nmi_webhook_secret', '' );

        if ( ! self::verify_nmi_signature( $raw_body, $sig, $secret ) ) {
            wc_get_logger()->warning( 'NMI webhook: invalid signature', [ 'source' => 'nmi-webhook' ] );
            status_header( 401 );
            exit( 'Unauthorized' );
        }

        parse_str( $raw_body, $data );
        $transaction_id = sanitize_text_field( $data['transaction_id'] ?? '' );
        $event_type     = sanitize_text_field( $data['type'] ?? '' );

        wc_get_logger()->info( 'NMI webhook received: ' . $event_type, [ 'source' => 'nmi-webhook' ] );

        $order = self::find_order_by_transaction_id( $transaction_id );
        if ( ! $order ) {
            status_header( 200 ); // Acknowledge; ignore unknown transactions.
            exit;
        }

        match ( $event_type ) {
            'sale_complete'     => $order->payment_complete( $transaction_id ),
            'refund'            => $order->update_status( 'refunded', 'NMI refund confirmed via webhook.' ),
            'chargeback_opened' => self::flag_chargeback( $order, 'NMI chargeback opened.' ),
            'chargeback_won'    => $order->add_order_note( 'NMI chargeback resolved in merchant favor.' ),
            default             => $order->add_order_note( 'NMI webhook: ' . $event_type ),
        };

        status_header( 200 );
        exit( 'OK' );
    }

    /**
     * Handle incoming Stripe webhook.
     * Endpoint: POST /wc-api/stripe_webhook
     */
    public static function handle_stripe(): void {
        $raw_body       = file_get_contents( 'php://input' );
        $sig_header     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhook_secret = get_option( 'wc_gateway_stripe_webhook_secret', '' );

        if ( ! self::verify_stripe_signature( $raw_body, $sig_header, $webhook_secret ) ) {
            wc_get_logger()->warning( 'Stripe webhook: invalid signature', [ 'source' => 'stripe-webhook' ] );
            status_header( 401 );
            exit( 'Unauthorized' );
        }

        $event = json_decode( $raw_body, true );
        if ( ! is_array( $event ) ) {
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $event_type = $event['type'] ?? '';
        $object     = $event['data']['object'] ?? [];

        wc_get_logger()->info( 'Stripe webhook received: ' . $event_type, [ 'source' => 'stripe-webhook' ] );

        match ( $event_type ) {
            'payment_intent.succeeded'       => self::handle_stripe_payment_succeeded( $object ),
            'payment_intent.payment_failed'  => self::handle_stripe_payment_failed( $object ),
            'charge.refunded'                => self::handle_stripe_refund( $object ),
            'charge.dispute.created'         => self::handle_stripe_dispute( $object ),
            default                          => null, // Unhandled events: return 200 to prevent Stripe retries.
        };

        status_header( 200 );
        exit( 'OK' );
    }

    // ── Signature verification ─────────────────────────────────────────────

    /**
     * Verify NMI webhook HMAC-SHA512 signature.
     *
     * @param string $payload Raw POST body.
     * @param string $sig     Signature from X-Webhook-Signature header.
     * @param string $secret  HMAC secret from gateway settings.
     * @return bool
     */
    public static function verify_nmi_signature( string $payload, string $sig, string $secret ): bool {
        if ( empty( $secret ) || empty( $sig ) ) {
            return false;
        }
        $expected = hash_hmac( 'sha512', $payload, $secret );
        return hash_equals( $expected, strtolower( $sig ) );
    }

    /**
     * Verify Stripe webhook signature (Stripe-Signature header, HMAC-SHA256).
     * Includes timestamp replay protection — rejects events older than 5 minutes.
     *
     * @param string $payload    Raw request body.
     * @param string $sig_header Stripe-Signature header value.
     * @param string $secret     whsec_... webhook signing secret.
     * @return bool
     */
    public static function verify_stripe_signature( string $payload, string $sig_header, string $secret ): bool {
        if ( empty( $secret ) || empty( $sig_header ) ) {
            return false;
        }

        $parts = [];
        foreach ( explode( ',', $sig_header ) as $part ) {
            [ $key, $value ] = array_pad( explode( '=', $part, 2 ), 2, '' );
            $parts[ $key ] = $value;
        }

        $timestamp = (int) ( $parts['t'] ?? 0 );
        $received_sig = $parts['v1'] ?? '';

        // Replay protection: reject events > 5 minutes old.
        if ( abs( time() - $timestamp ) > 300 ) {
            return false;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac( 'sha256', $signed_payload, $secret );

        return hash_equals( $expected, $received_sig );
    }

    // ── Event handlers ─────────────────────────────────────────────────────

    private static function handle_stripe_payment_succeeded( array $intent ): void {
        $order_id = $intent['metadata']['order_id'] ?? 0;
        $order = wc_get_order( (int) $order_id );
        if ( $order ) {
            $order->payment_complete( $intent['id'] ?? '' );
        }
    }

    private static function handle_stripe_payment_failed( array $intent ): void {
        $order_id = $intent['metadata']['order_id'] ?? 0;
        $order = wc_get_order( (int) $order_id );
        if ( $order ) {
            $order->update_status( 'failed', 'Stripe PaymentIntent failed: ' . ( $intent['last_payment_error']['message'] ?? 'Unknown error' ) );
        }
    }

    private static function handle_stripe_refund( array $charge ): void {
        $order = self::find_order_by_transaction_id( $charge['id'] ?? '' );
        if ( $order ) {
            $order->update_status( 'refunded', 'Stripe refund confirmed via webhook.' );
        }
    }

    private static function handle_stripe_dispute( array $dispute ): void {
        $charge_id = $dispute['charge'] ?? '';
        $order = self::find_order_by_transaction_id( $charge_id );
        if ( $order ) {
            self::flag_chargeback( $order, sprintf( 'Stripe dispute opened. Dispute ID: %s. Reason: %s.', $dispute['id'] ?? '', $dispute['reason'] ?? '' ) );
        }
    }

    private static function flag_chargeback( \WC_Order $order, string $note ): void {
        $order->update_status( 'on-hold', $note );
        // Notify store admin.
        wp_mail(
            get_option( 'admin_email' ),
            sprintf( 'Chargeback alert: Order #%s', $order->get_order_number() ),
            $note
        );
    }

    private static function find_order_by_transaction_id( string $transaction_id ): ?\WC_Order {
        if ( empty( $transaction_id ) ) {
            return null;
        }
        $orders = wc_get_orders( [ 'transaction_id' => $transaction_id, 'limit' => 1 ] );
        return $orders[0] ?? null;
    }
}
