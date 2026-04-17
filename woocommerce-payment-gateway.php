<?php
/**
 * Plugin Name: WooCommerce High-Risk Payment Gateway
 * Plugin URI:  https://github.com/odanree/woocommerce-payment-gateway
 * Description: NMI (primary) + Stripe (failover) payment gateway for high-risk WooCommerce merchants. Tokenization, idempotency, webhook verification, multi-step checkout.
 * Version:     1.0.0
 * Author:      Danh Le
 * License:     GPL-2.0-or-later
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.5
 * WC tested up to: 9.0
 *
 * @package WC_Gateway_HighRisk
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WC_GATEWAY_HR_VERSION', '1.0.0' );
define( 'WC_GATEWAY_HR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_GATEWAY_HR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize plugin after WooCommerce loads.
 */
add_action( 'plugins_loaded', 'wc_gateway_hr_init', 0 );

function wc_gateway_hr_init(): void {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p>';
            esc_html_e( 'WooCommerce High-Risk Payment Gateway requires WooCommerce to be active.', 'wc-gateway-hr' );
            echo '</p></div>';
        } );
        return;
    }

    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-nmi.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-stripe-highrisk.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-failover-manager.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-tokenization-manager.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-idempotency.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-webhook-handler.php';
    require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-checkout-multistep.php';

    // Register webhook endpoints for NMI and Stripe.
    WC_Webhook_Handler::register();

    // Register multi-step checkout.
    WC_Checkout_Multistep::init();
}

/**
 * Register our gateway classes with WooCommerce.
 *
 * @param array $gateways Existing gateway classes.
 * @return array
 */
add_filter( 'woocommerce_payment_gateways', 'wc_gateway_hr_register_gateways' );

function wc_gateway_hr_register_gateways( array $gateways ): array {
    $gateways[] = WC_Gateway_Failover_Manager::class;
    return $gateways;
}

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
