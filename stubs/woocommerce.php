<?php
/**
 * WooCommerce stubs for PHPStan.
 *
 * These declarations are only loaded by static analysis (see `phpstan.neon`).
 * They should never be included at runtime.
 */

class WC_DateTime extends \DateTime {
	public function date( string $format ): string {}

	public function setTimezone( \DateTimeZone $timezone ): WC_DateTime {}
}

class WC_Product {
	public function get_id(): int {}

	public function get_name(): string {}

	public function get_sku(): string {}

	/** @return int|null */
	public function get_stock_quantity() {}
}

class WC_Product_Simple extends WC_Product {
	public function set_name( string $name ): void {}

	public function set_sku( string $sku ): void {}

	public function set_regular_price( string $price ): void {}

	public function set_description( string $description ): void {}

	public function set_short_description( string $description ): void {}

	public function set_status( string $status ): void {}

	public function set_catalog_visibility( string $visibility ): void {}

	public function set_stock_status( string $status ): void {}

	public function save(): int {}
}

class WC_Order_Item {
	public function get_name(): string {}

	public function get_quantity(): int {}

	public function get_total(): float {}
}

class WC_Order_Item_Product extends WC_Order_Item {
	public function get_product_id(): int {}

	public function get_variation_id(): int {}
}

class WC_Shipping_Method {
	public function get_method_title(): string {}
}

class WC_Abstract_Order {
	public function get_id(): int {}

	public function get_status(): string {}

	/** @return float|string */
	public function get_total() {}

	/** @return float|string */
	public function get_total_refunded() {}

	public function get_currency(): string {}

	public function get_date_created(): ?WC_DateTime {}

	public function get_date_modified(): ?WC_DateTime {}

	/** @return int|string|null */
	public function get_customer_id() {}

	public function get_billing_email(): string {}

	public function get_payment_method_title(): string {}

	/** @return array<int,WC_Shipping_Method> */
	public function get_shipping_methods(): array {}

	/** @return array<int,WC_Order_Item> */
	public function get_items(): array {}

	/** @return array<string, mixed> */
	public function get_address( string $type = 'billing' ): array {}

	public function get_formatted_billing_full_name(): string {}

	public function get_remaining_refund_amount(): float {}

	/** @return mixed */
	public function get_meta( string $key = '', bool $single = false ) {}

	public function update_meta_data( string $key, $value ): void {}

	public function save(): int {}

	public function update_status( string $status, string $note = '' ): void {}

	public function add_order_note( string $note, bool $is_customer_note = false ): int {}

	public function add_product( WC_Product $product, int $quantity = 1, array $args = array() ): void {}

	public function set_address( array $address, string $type = 'billing' ): void {}

	public function set_date_created( WC_DateTime $date ): void {}

	public function set_date_paid( WC_DateTime $date ): void {}

	public function set_date_completed( WC_DateTime $date ): void {}

	public function calculate_totals(): void {}

	public function set_status( string $status ): void {}

	public function send_customer_refund_notification( int $refund_id ): void {}
}

class WC_Order extends WC_Abstract_Order {
	public function get_billing_first_name(): string {}

	public function get_billing_last_name(): string {}

	public function get_billing_company(): string {}

	public function get_billing_address_1(): string {}

	public function get_billing_address_2(): string {}

	public function get_billing_city(): string {}

	public function get_billing_state(): string {}

	public function get_billing_postcode(): string {}

	public function get_billing_country(): string {}

	public function get_billing_phone(): string {}

	public function get_shipping_first_name(): string {}

	public function get_shipping_last_name(): string {}

	public function get_shipping_company(): string {}

	public function get_shipping_address_1(): string {}

	public function get_shipping_address_2(): string {}

	public function get_shipping_city(): string {}

	public function get_shipping_state(): string {}

	public function get_shipping_postcode(): string {}

	public function get_shipping_country(): string {}
}

class WC_Order_Refund extends WC_Abstract_Order {
	public function get_id(): int {}
}

class WC_Logger {
	public function emergency( string $message, array $context = array() ): void {}

	public function alert( string $message, array $context = array() ): void {}

	public function critical( string $message, array $context = array() ): void {}

	public function error( string $message, array $context = array() ): void {}

	public function warning( string $message, array $context = array() ): void {}

	public function notice( string $message, array $context = array() ): void {}

	public function info( string $message, array $context = array() ): void {}

	public function debug( string $message, array $context = array() ): void {}
}

class WC_Payment_Gateway {
	public function supports( string $feature ): bool {}
}

class WC_Payment_Gateways {
	/** @return array<string,WC_Payment_Gateway> */
	public function payment_gateways(): array {}
}

class WC_Global {
	public function payment_gateways(): ?WC_Payment_Gateways {}

	public function mailer(): ?WC_Mailer {}
}

function WC(): ?WC_Global {}

/** @return WC_Order|false|null */
function wc_get_order( $order_id ) {}

/** @return array<int, int|WC_Order>|object */
function wc_get_orders( array $args = array() ) {}

/** @return WC_Product|false|null */
function wc_get_product( $product_id ) {}

/** @return array<int,int|WC_Product> */
function wc_get_products( array $args = array() ): array {}

function wc_get_product_id_by_sku( string $sku ): int {}

/** @return WC_Order|false|null */
function wc_create_order( array $args = array() ) {}

/** @return WC_Order_Refund|\WP_Error */
function wc_create_refund( array $args = array() ) {}

function wc_get_order_status_name( string $status ): string {}

function wc_get_logger(): WC_Logger {}

function wc_get_price_decimals(): int {}

/** @param float|int|string $number */
function wc_format_decimal( $number, $dp = false, $trim_zeros = false ): string {}

/** @return mixed|null */
function wc_get_payment_gateway_by_order( $order ) {}

/** @param int|string $stock_quantity */
function wc_update_product_stock( WC_Product $product, $stock_quantity ) {}

/** @return array<string,string> */
function wc_get_order_statuses(): array {}

class WC_Mailer {
	/** @var array<string, object> */
	public array $emails = array();
}
