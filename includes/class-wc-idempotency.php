<?php
/**
 * Idempotency guard for payment processing.
 *
 * Prevents double-charges caused by:
 * - Customer double-clicking the submit button
 * - Browser retry on network timeout
 * - WooCommerce order processing retry hooks
 *
 * Uses WordPress transients (backed by Redis object cache in production)
 * with a TTL of 10 minutes — long enough to catch retries, short enough
 * to allow legitimate re-attempts after a genuine failure.
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Idempotency {

    /** Transient TTL in seconds. */
    private const TTL = 600;

    /** Transient key prefix. */
    private const PREFIX = 'wc_pay_idem_';

    /**
     * Check whether this order has already been successfully processed.
     *
     * @param int $order_id WooCommerce order ID.
     * @return bool         True if already processed.
     */
    public function already_processed( int $order_id ): bool {
        return (bool) get_transient( self::PREFIX . $order_id );
    }

    /**
     * Mark an order as successfully processed.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function mark_processed( int $order_id ): void {
        set_transient( self::PREFIX . $order_id, '1', self::TTL );
    }

    /**
     * Clear the idempotency lock (e.g., after a confirmed failure that should
     * allow the customer to retry with a different card).
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function clear( int $order_id ): void {
        delete_transient( self::PREFIX . $order_id );
    }
}
