<?php
/**
 * WordPress function stubs for PHPUnit.
 * Only the functions our plugin actually calls.
 */

declare( strict_types=1 );

// Transient store (simulates Redis/DB in tests).
$GLOBALS['_wc_test_transients'] = [];
$GLOBALS['_wc_test_options']    = [];
$GLOBALS['_wc_test_notices']    = [];

function get_transient( string $key ) {
    return $GLOBALS['_wc_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
    $GLOBALS['_wc_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( string $key ): bool {
    unset( $GLOBALS['_wc_test_transients'][ $key ] );
    return true;
}

function get_option( string $key, $default = false ) {
    return $GLOBALS['_wc_test_options'][ $key ] ?? $default;
}

function update_option( string $key, $value ): bool {
    $GLOBALS['_wc_test_options'][ $key ] = $value;
    return true;
}

function get_woocommerce_currency(): string {
    return 'USD';
}

function get_bloginfo( string $key ): string {
    return 'Test Store';
}

function wc_add_notice( string $message, string $type = 'success' ): void {
    $GLOBALS['_wc_test_notices'][] = [ 'message' => $message, 'type' => $type ];
}

function wc_get_notices( string $type = '' ): array {
    if ( $type ) {
        return array_filter( $GLOBALS['_wc_test_notices'], fn( $n ) => $n['type'] === $type );
    }
    return $GLOBALS['_wc_test_notices'];
}

function wc_get_logger(): object {
    return new class {
        public function error( string $msg, array $ctx = [] ): void {}
        public function warning( string $msg, array $ctx = [] ): void {}
        public function info( string $msg, array $ctx = [] ): void {}
    };
}

function wc_get_order( int $id ): ?object {
    return $GLOBALS['_wc_test_orders'][ $id ] ?? null;
}

function wc_get_orders( array $args ): array {
    $tid = $args['transaction_id'] ?? '';
    foreach ( $GLOBALS['_wc_test_orders'] ?? [] as $order ) {
        if ( $order->get_transaction_id() === $tid ) {
            return [ $order ];
        }
    }
    return [];
}

function wp_remote_post( string $url, array $args = [] ): array {
    return $GLOBALS['_wc_test_http_response'] ?? [ 'body' => '', 'response' => [ 'code' => 200 ] ];
}

function wp_remote_request( string $url, array $args = [] ): array {
    return $GLOBALS['_wc_test_http_response'] ?? [ 'body' => '{}', 'response' => [ 'code' => 200 ] ];
}

function wp_remote_retrieve_body( array $response ): string {
    return $response['body'] ?? '';
}

function is_wp_error( $thing ): bool {
    return $thing instanceof WP_Error;
}

function wp_unslash( $value ) {
    return $value;
}

function sanitize_text_field( string $str ): string {
    return trim( $str );
}

function wp_kses_post( string $content ): string {
    return $content;
}

function esc_attr( string $s ): string { return htmlspecialchars( $s ); }
function esc_html( string $s ): string { return htmlspecialchars( $s ); }
function esc_js( string $s ): string { return addslashes( $s ); }

function wp_create_nonce( string $action ): string { return 'test_nonce'; }
function wp_enqueue_script(): void {}
function wp_enqueue_style(): void {}
function wp_add_inline_script(): void {}
function wp_localize_script(): void {}
function add_action(): void {}
function add_filter(): void {}
function remove_action(): void {}
function apply_filters( string $tag, $value, ...$args ) { return $value; }
function status_header( int $code ): void {}
function wp_mail(): bool { return true; }
function is_checkout(): bool { return true; }
function admin_url( string $path = '' ): string { return 'http://localhost/wp-admin/' . $path; }
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( string $file ): string { return 'http://localhost/'; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function sprintf( ...$args ): string { return \sprintf( ...$args ); }
function number_format( ...$args ): string { return \number_format( ...$args ); }
function defined( string $constant ): bool { return \defined( $constant ); }
function date( string $format ): string { return \date( $format ); }
function get_woocommerce_currency_symbol(): string { return '$'; }
function wc_price( $price ): string { return '$' . number_format( (float) $price, 2 ); }

class WP_Error {
    public string $code;
    public string $message;
    public function __construct( string $code = '', string $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_message(): string { return $this->message; }
}
