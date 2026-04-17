<?php
/**
 * PHPUnit bootstrap — sets up WordPress/WooCommerce stubs for unit tests.
 *
 * We test in isolation without a real WordPress install. Key stubs:
 * - WordPress functions (get_option, wc_get_order, etc.)
 * - WC_Payment_Gateway base class
 * - WC_Order stub
 *
 * @package WC_Gateway_HighRisk\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/tests/stubs/' );
define( 'WC_GATEWAY_HR_VERSION', '1.0.0' );
define( 'WC_GATEWAY_HR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WC_GATEWAY_HR_PLUGIN_URL', 'http://localhost/' );

require_once __DIR__ . '/stubs/wordpress-functions.php';
require_once __DIR__ . '/stubs/woocommerce-stubs.php';

// Load plugin classes under test.
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-idempotency.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-tokenization-manager.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-webhook-handler.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-nmi.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-stripe-highrisk.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-gateway-failover-manager.php';
require_once WC_GATEWAY_HR_PLUGIN_DIR . 'includes/class-wc-checkout-multistep.php';
