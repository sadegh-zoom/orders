<?php

define( 'ABSPATH', __DIR__ );

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wc_get_order_notes( $args ) {
	return array();
}
function wc_get_order_statuses() {
	return array( 'wc-processing' => 'Processing' );
}
function wc_get_orders( $args ) {
	return (object) array( 'orders' => array_keys( $GLOBALS['orders'] ), 'max_num_pages' => 1 );
}
function wc_get_order( $id ) {
	return $GLOBALS['orders'][ $id ] ?? null;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['options'][ $key ] = $value;
}
class Company_Order_Sync_Settings {
	public static function mode() { return 'central'; }
}
class Company_Order_Sync_Central_Rebuild {
	public static function blocked() { return false; }
}

class WC_Order {
	public $meta = array();
	public $method = '';

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_items( $type = '' ) {
		return array();
	}

	public function get_status() {
		return 'processing';
	}

	public function get_payment_method() {
		return $this->method;
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data() {}

	public function __call( $name, $args ) {
		return '';
	}
}

$plugin = dirname( __DIR__ ) . '/public_html/wp-content/plugins/company-order-sync/includes/';
require $plugin . 'class-order-serializer.php';
require $plugin . 'class-order-mapper.php';
require $plugin . 'class-payment-gateway-backfill.php';

$order = new WC_Order();
$order->meta['_company_source_sync_revision'] = '123';
$serializer = new Company_Order_Sync_Order_Serializer();
$mapper = new Company_Order_Sync_Order_Mapper();
$can_skip = new ReflectionMethod( $mapper, 'can_skip_unchanged' );
$payload = array( 'sync_revision'=>'123', 'status'=>'processing', 'line_items'=>array(), 'payment_gateway_type'=>'cash' );

foreach ( array( 'cash', 'installment' ) as $type ) {
	$order->meta['_zb_payment_gateway_type'] = $type;
	$serialized = $serializer->serialize( $order );
	if ( $type !== $serialized['payment_gateway_type'] ) {
		throw new RuntimeException( 'A valid source value was not serialized.' );
	}
}

foreach ( array( '', 'other', array( 'cash' ) ) as $invalid ) {
	$order->meta['_zb_payment_gateway_type'] = $invalid;
	if ( '' !== $serializer->serialize( $order )['payment_gateway_type'] ) {
		throw new RuntimeException( 'An unsupported source value was accepted.' );
	}
}

unset( $order->meta['_zb_payment_gateway_type'] );
if ( $can_skip->invoke( $mapper, $order, $payload ) ) {
	throw new RuntimeException( 'The unchanged revision hid a missing Central gateway type.' );
}
$order->meta['_zb_payment_gateway_type'] = 'cash';
if ( ! $can_skip->invoke( $mapper, $order, $payload ) ) {
	throw new RuntimeException( 'An already synchronized order was needlessly remapped.' );
}
$fallback_payload = array( 'sync_revision'=>'123', 'status'=>'processing', 'line_items'=>array(), 'payment_method'=>'WC_ZPal', 'payment_gateway_type'=>'' );
unset( $order->meta['_zb_payment_gateway_type'] );
if ( $can_skip->invoke( $mapper, $order, $fallback_payload ) ) {
	throw new RuntimeException( 'An unchanged legacy payload hid a missing Central gateway type.' );
}
$order->meta['_zb_payment_gateway_type'] = 'cash';
if ( ! $can_skip->invoke( $mapper, $order, $fallback_payload ) ) {
	throw new RuntimeException( 'A populated Central gateway type was remapped without a source value.' );
}

$cash_methods = array( 'WC_ZPal', 'melli_pay', 'WC_AsanPardakht' );
$installment_methods = array( 'aghsat_market_gateway', 'bwdk', 'tara360', 'WC_Gateway_SnappPay', 'WC_Gateway_TorobPay', 'WCDigiPay', 'WC_ZarinPlus', 'WC_ZarinPlus_vibe', 'WC_ZarinPlus_ewano' );
foreach ( $cash_methods as $method ) {
	if ( 'cash' !== Company_Order_Sync_Payment_Gateway_Backfill::type_for_method( $method ) ) {
		throw new RuntimeException( 'A confirmed cash gateway was misclassified.' );
	}
}
foreach ( $installment_methods as $method ) {
	if ( 'installment' !== Company_Order_Sync_Payment_Gateway_Backfill::type_for_method( $method ) ) {
		throw new RuntimeException( 'A confirmed installment gateway was misclassified.' );
	}
}
if ( '' !== Company_Order_Sync_Payment_Gateway_Backfill::type_for_method( 'unknown' ) ) {
	throw new RuntimeException( 'An unknown gateway was classified.' );
}

$cash = new WC_Order();
$cash->method = 'WC_ZPal';
$cash->meta['_company_source_store'] = 'site2';
$installment = new WC_Order();
$installment->method = 'tara360';
$installment->meta['_company_source_store'] = 'site1';
$preserved = new WC_Order();
$preserved->method = 'WC_ZPal';
$preserved->meta['_company_source_store'] = 'site1';
$preserved->meta['_zb_payment_gateway_type'] = 'installment';
$GLOBALS['orders'] = array( 1 => $cash, 2 => $installment, 3 => $preserved );
( new Company_Order_Sync_Payment_Gateway_Backfill() )->process();
$state = $GLOBALS['options'][ Company_Order_Sync_Payment_Gateway_Backfill::STATE_OPTION ];
if ( 'cash' !== $cash->meta['_zb_payment_gateway_type'] || 'installment' !== $installment->meta['_zb_payment_gateway_type'] || 'installment' !== $preserved->meta['_zb_payment_gateway_type'] || 2 !== $state['updated'] || ! $state['complete'] ) {
	throw new RuntimeException( 'Central backfill did not preserve and populate the expected orders.' );
}
$late = new WC_Order();
$late->method = 'WC_Gateway_SnappPay';
$late->meta['_company_source_store'] = 'site2';
$GLOBALS['orders'][4] = $late;
( new Company_Order_Sync_Payment_Gateway_Backfill() )->fill_changed_order( 4 );
if ( 'installment' !== $late->meta['_zb_payment_gateway_type'] ) {
	throw new RuntimeException( 'An order added after the historical scan was not filled.' );
}

echo "Payment gateway type contract OK\n";
