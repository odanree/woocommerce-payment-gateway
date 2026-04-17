<?php
/**
 * Tokenization manager — stores and retrieves processor tokens in WooCommerce.
 *
 * Wraps WooCommerce's built-in payment token API. Tokens are stored in
 * wp_woocommerce_payment_tokens — no PAN data ever touches the database.
 *
 * For NMI: stores Customer Vault ID (a reference to NMI's stored card profile).
 * For Stripe: stores Stripe PaymentMethod ID (pm_...).
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class WC_Tokenization_Manager {

    /**
     * Save a processor token for a customer.
     *
     * @param int    $customer_id   WP user ID.
     * @param string $processor     'nmi' or 'stripe'.
     * @param string $token_value   NMI vault ID or Stripe pm_ ID.
     * @param array  $card_meta     Optional display metadata (last4, expiry, brand).
     */
    public function save_token( int $customer_id, string $processor, string $token_value, array $card_meta = [] ): void {
        if ( 0 === $customer_id ) {
            // Guest checkout — no token storage.
            return;
        }

        $gateway_id = 'nmi' === $processor ? 'nmi' : 'stripe_highrisk';

        $token = new WC_Payment_Token_CC();
        $token->set_token( $token_value );
        $token->set_gateway_id( $gateway_id );
        $token->set_user_id( $customer_id );
        $token->set_last4( $card_meta['last4'] ?? '0000' );
        $token->set_expiry_month( $card_meta['exp_month'] ?? '12' );
        $token->set_expiry_year( $card_meta['exp_year'] ?? (string) ( (int) gmdate( 'Y' ) + 3 ) );
        $token->set_card_type( strtolower( $card_meta['brand'] ?? 'visa' ) );
        $token->save();
    }

    /**
     * Retrieve the most recent stored token for a customer and processor.
     *
     * @param int    $customer_id WP user ID.
     * @param string $processor   'nmi' or 'stripe'.
     * @return WC_Payment_Token_CC|null
     */
    public function get_token( int $customer_id, string $processor ): ?WC_Payment_Token_CC {
        $gateway_id = 'nmi' === $processor ? 'nmi' : 'stripe_highrisk';
        $tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );

        if ( empty( $tokens ) ) {
            return null;
        }

        // Return the most recently added token.
        $last = end( $tokens );
        return $last ? $last : null;
    }

    /**
     * Delete all stored tokens for a customer (on account closure, GDPR erasure, etc.).
     *
     * @param int $customer_id WP user ID.
     */
    public function delete_tokens( int $customer_id ): void {
        $tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id );
        foreach ( $tokens as $token ) {
            $token->delete();
        }
    }
}
