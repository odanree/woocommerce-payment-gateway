<?php
/**
 * WooCommerce class stubs for PHPUnit.
 */

declare( strict_types=1 );

class WC_Payment_Gateway {
    public string $id          = '';
    public string $method_title = '';
    public string $method_description = '';
    public string $title       = '';
    public string $description = '';
    public bool   $has_fields  = false;
    public array  $supports    = [];
    protected array $settings  = [];
    protected array $form_fields = [];

    public function init_form_fields(): void {}
    public function init_settings(): void {}
    public function get_option( string $key, $default = '' ) {
        return $this->settings[ $key ] ?? $default;
    }
    public function set_option( string $key, $value ): void {
        $this->settings[ $key ] = $value;
    }
    public function process_admin_options(): bool { return true; }
    public function generate_settings_html( array $fields, bool $echo = false ): string { return ''; }
    public function has_fields(): bool { return $this->has_fields; }
    public function get_return_url( ?object $order = null ): string { return 'http://localhost/order-received/'; }
}

class WC_Payment_Token_CC {
    private array $data = [];
    public function set_token( string $token ): void { $this->data['token'] = $token; }
    public function set_gateway_id( string $id ): void { $this->data['gateway_id'] = $id; }
    public function set_user_id( int $id ): void { $this->data['user_id'] = $id; }
    public function set_last4( string $last4 ): void { $this->data['last4'] = $last4; }
    public function set_expiry_month( string $m ): void { $this->data['exp_month'] = $m; }
    public function set_expiry_year( string $y ): void { $this->data['exp_year'] = $y; }
    public function set_card_type( string $type ): void { $this->data['card_type'] = $type; }
    public function save(): int { return 1; }
    public function delete(): bool { return true; }
    public function get_token(): string { return $this->data['token'] ?? ''; }
}

class WC_Payment_Tokens {
    public static function get_customer_tokens( int $customer_id, string $gateway_id = '' ): array {
        return [];
    }
}

/** Minimal WC_Order base so type hints in gateway methods are satisfied in tests. */
abstract class WC_Order {}

/** @internal Test-only order stub */
class WC_Order_Stub extends WC_Order {
    private array $data;
    private array $notes = [];

    public function __construct( array $data = [] ) {
        $this->data = array_merge( [
            'id'              => 1,
            'order_number'    => '100',
            'total'           => '99.99',
            'customer_id'     => 1,
            'transaction_id'  => '',
            'status'          => 'pending',
            'billing_email'   => 'test@example.com',
            'billing_first_name' => 'Jane',
            'billing_last_name'  => 'Doe',
            'billing_address_1'  => '123 Main St',
            'billing_city'       => 'Austin',
            'billing_state'      => 'TX',
            'billing_postcode'   => '78701',
            'billing_country'    => 'US',
        ], $data );
    }

    public function get_id(): int { return (int) $this->data['id']; }
    public function get_order_number(): string { return $this->data['order_number']; }
    public function get_total(): string { return $this->data['total']; }
    public function get_customer_id(): int { return (int) $this->data['customer_id']; }
    public function get_transaction_id(): string { return $this->data['transaction_id']; }
    public function get_billing_email(): string { return $this->data['billing_email']; }
    public function get_billing_first_name(): string { return $this->data['billing_first_name']; }
    public function get_billing_last_name(): string { return $this->data['billing_last_name']; }
    public function get_billing_address_1(): string { return $this->data['billing_address_1']; }
    public function get_billing_city(): string { return $this->data['billing_city']; }
    public function get_billing_state(): string { return $this->data['billing_state']; }
    public function get_billing_postcode(): string { return $this->data['billing_postcode']; }
    public function get_billing_country(): string { return $this->data['billing_country']; }
    public function get_status(): string { return $this->data['status']; }
    public function get_notes(): array { return $this->notes; }

    public function payment_complete( string $transaction_id = '' ): void {
        $this->data['status']         = 'processing';
        $this->data['transaction_id'] = $transaction_id;
    }
    public function update_status( string $status, string $note = '' ): void {
        $this->data['status'] = $status;
        if ( $note ) {
            $this->notes[] = $note;
        }
    }
    public function add_order_note( string $note ): int {
        $this->notes[] = $note;
        return count( $this->notes );
    }
}
