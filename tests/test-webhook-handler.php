<?php
/**
 * Tests for WC_Webhook_Handler — focuses on signature verification logic.
 *
 * @package WC_Gateway_HighRisk\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

class WebhookHandlerTest extends TestCase {

    // ── NMI HMAC-SHA512 verification ─────────────────────────────────────

    public function test_nmi_valid_signature(): void {
        $secret  = 'my-nmi-webhook-secret';
        $payload = 'type=sale_complete&transaction_id=TXN123&amount=99.99';
        $sig     = hash_hmac( 'sha512', $payload, $secret );

        $this->assertTrue( WC_Webhook_Handler::verify_nmi_signature( $payload, $sig, $secret ) );
    }

    public function test_nmi_invalid_signature_rejected(): void {
        $secret  = 'my-nmi-webhook-secret';
        $payload = 'type=sale_complete&transaction_id=TXN123&amount=99.99';
        $bad_sig = 'deadbeef';

        $this->assertFalse( WC_Webhook_Handler::verify_nmi_signature( $payload, $bad_sig, $secret ) );
    }

    public function test_nmi_empty_secret_rejected(): void {
        $this->assertFalse( WC_Webhook_Handler::verify_nmi_signature( 'payload', 'sig', '' ) );
    }

    public function test_nmi_empty_signature_rejected(): void {
        $this->assertFalse( WC_Webhook_Handler::verify_nmi_signature( 'payload', '', 'secret' ) );
    }

    public function test_nmi_tampered_payload_rejected(): void {
        $secret   = 'my-nmi-webhook-secret';
        $original = 'amount=99.99';
        $tampered = 'amount=0.01';
        $sig      = hash_hmac( 'sha512', $original, $secret );

        $this->assertFalse( WC_Webhook_Handler::verify_nmi_signature( $tampered, $sig, $secret ) );
    }

    // ── Stripe HMAC-SHA256 + timestamp replay protection ─────────────────

    public function test_stripe_valid_signature(): void {
        $secret    = 'whsec_test_secret';
        $timestamp = time();
        $payload   = '{"type":"payment_intent.succeeded"}';
        $signed    = $timestamp . '.' . $payload;
        $sig       = hash_hmac( 'sha256', $signed, $secret );
        $header    = "t={$timestamp},v1={$sig}";

        $this->assertTrue( WC_Webhook_Handler::verify_stripe_signature( $payload, $header, $secret ) );
    }

    public function test_stripe_replayed_event_rejected(): void {
        $secret    = 'whsec_test_secret';
        $timestamp = time() - 400; // More than 5 minutes ago.
        $payload   = '{"type":"payment_intent.succeeded"}';
        $signed    = $timestamp . '.' . $payload;
        $sig       = hash_hmac( 'sha256', $signed, $secret );
        $header    = "t={$timestamp},v1={$sig}";

        $this->assertFalse( WC_Webhook_Handler::verify_stripe_signature( $payload, $header, $secret ) );
    }

    public function test_stripe_invalid_signature_rejected(): void {
        $timestamp = time();
        $header    = "t={$timestamp},v1=invalidsig";

        $this->assertFalse( WC_Webhook_Handler::verify_stripe_signature( 'payload', $header, 'secret' ) );
    }

    public function test_stripe_empty_secret_rejected(): void {
        $this->assertFalse( WC_Webhook_Handler::verify_stripe_signature( 'payload', 'header', '' ) );
    }

    public function test_stripe_tampered_payload_rejected(): void {
        $secret    = 'whsec_test_secret';
        $timestamp = time();
        $original  = '{"amount":9999}';
        $tampered  = '{"amount":1}';
        $signed    = $timestamp . '.' . $original;
        $sig       = hash_hmac( 'sha256', $signed, $secret );
        $header    = "t={$timestamp},v1={$sig}";

        $this->assertFalse( WC_Webhook_Handler::verify_stripe_signature( $tampered, $header, $secret ) );
    }

    public function test_stripe_missing_v1_component_rejected(): void {
        $timestamp = time();
        $header    = "t={$timestamp}";  // No v1= component.

        $this->assertFalse( WC_Webhook_Handler::verify_stripe_signature( 'payload', $header, 'secret' ) );
    }
}
