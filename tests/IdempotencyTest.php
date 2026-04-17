<?php
/**
 * Tests for WC_Idempotency.
 *
 * @package WC_Gateway_HighRisk\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

class IdempotencyTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wc_test_transients'] = [];
    }

    public function test_not_processed_by_default(): void {
        $idem = new WC_Idempotency();
        $this->assertFalse( $idem->already_processed( 42 ) );
    }

    public function test_mark_processed_sets_flag(): void {
        $idem = new WC_Idempotency();
        $idem->mark_processed( 42 );
        $this->assertTrue( $idem->already_processed( 42 ) );
    }

    public function test_clear_removes_flag(): void {
        $idem = new WC_Idempotency();
        $idem->mark_processed( 42 );
        $idem->clear( 42 );
        $this->assertFalse( $idem->already_processed( 42 ) );
    }

    public function test_different_order_ids_are_isolated(): void {
        $idem = new WC_Idempotency();
        $idem->mark_processed( 10 );
        $this->assertTrue( $idem->already_processed( 10 ) );
        $this->assertFalse( $idem->already_processed( 11 ) );
    }

    public function test_transient_key_is_order_specific(): void {
        $idem = new WC_Idempotency();
        $idem->mark_processed( 99 );
        // Verify the transient key format matches what we store.
        $this->assertArrayHasKey( 'wc_pay_idem_99', $GLOBALS['_wc_test_transients'] );
    }
}
