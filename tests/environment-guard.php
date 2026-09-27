<?php
// Isolated regression tests: no WordPress bootstrap, database or network.
// Run: php tests/environment-guard.php production|staging|development|local
define( 'ABSPATH', '/unused/' );
define( 'HOUR_IN_SECONDS', 3600 );
$environment = $argv[1] ?? 'production';
$actions = $filters = $calls = array();
function wp_get_environment_type() { return $GLOBALS['environment']; }
function add_action( $name, $callback, ...$args ) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][$name][] = $callback; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( ...$args ) { $GLOBALS['calls'][] = 'schedule'; }
function current_user_can( $cap ) { return true; }
function esc_html__( $text, $domain ) { return htmlspecialchars( $text ); }
class WP_Error { public function __construct( public $code, public $message, public $data ) {} }
class WooCommerce {}
class Probe {
	public static function __callStatic( $method, $args ) { $GLOBALS['calls'][] = static::class . ':' . $method; return false; }
	public function hooks() { $GLOBALS['calls'][] = static::class . ':hooks'; }
}
class Company_Order_Sync_Central_Rebuild extends Probe {}
class Company_Order_Sync_Replay_Store extends Probe {}
class Company_Order_Sync_Settings extends Probe {}
class Company_Order_Sync_Queue extends Probe {}
class Company_Order_Sync_REST_Controller extends Probe {}
class Company_Order_Sync_Snapshot_Sync extends Probe {}
class Company_Order_Sync_Status_Repair extends Probe {}
class Company_Order_Sync_Status_Audit extends Probe {}
class Company_Order_Sync_Retention_Maintenance extends Probe {}
class Company_Order_Sync_Product_Seller extends Probe {}
require __DIR__ . '/../public_html/wp-content/plugins/company-order-sync/includes/class-environment-guard.php';
require __DIR__ . '/../public_html/wp-content/plugins/company-order-sync/includes/class-plugin.php';
function check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
}
$blocked = 'production' !== $environment;
Company_Order_Sync_Plugin::init();
check( isset($filters['rest_pre_dispatch'], $filters['pre_http_request']), 'Transport guards registered' );
Company_Order_Sync_Plugin::activate();
Company_Order_Sync_Plugin::boot();
if ( $blocked ) {
	check( !$calls, 'Non-production must not initialize DB, schedules or sync handlers' );
} else {
	foreach ( array('Queue','REST_Controller','Snapshot_Sync','Status_Repair','Status_Audit','Retention_Maintenance','Product_Seller','Settings','Central_Rebuild') as $component ) {
		check( in_array('Company_Order_Sync_' . $component . ':hooks', $calls, true), 'Production hooks: ' . $component );
	}
	check( in_array('Company_Order_Sync_Replay_Store:install', $calls, true), 'Production activation installs ledger' );
	check( in_array('schedule', $calls, true), 'Production schedules still run' );
}
foreach ( array(
	'https://zoombazar.com/wp-json/company-sync/v1/orders/123/status',
	'https://zoombazar.com/stg-zb1/wp-json/company-sync/v1/orders',
	'https://orders.zoombazar.com/wp-json/company-sync/v1/orders/batch',
	'https://orders.zoombazar.com/?rest_route=%2Fcompany-sync%2Fv1%2Fsnapshot',
) as $url ) {
	$result = Company_Order_Sync_Environment_Guard::protect_http(false, array(), $url);
	check( $blocked ? $result instanceof WP_Error : false === $result, 'Transport protection: ' . $url );
}
foreach ( array('https://zoombazar.com/wp-json/wc/v3/orders','https://zoombazar.com/stg-zb1/','https://api.wordpress.org/core/version-check/1.7/') as $url ) {
	check( 'unchanged' === Company_Order_Sync_Environment_Guard::protect_http('unchanged', array(), $url), 'Unrelated traffic unchanged' );
}
foreach ( array('/company-sync/v1/orders','/company-sync/v1/orders/1/status','/company-sync/v1/orders/1/tracking','/company-sync/v1/orders/1/notes','/company-sync/v1/snapshot','/company-sync/v1/status-snapshot') as $route ) {
	$request = new class($route) { public function __construct(private $route) {} public function get_route(){return $this->route;} };
	$result = Company_Order_Sync_Environment_Guard::protect_rest(null, null, $request);
	check( $blocked ? $result instanceof WP_Error && 403 === $result->data['status'] : null === $result, 'Inbound: ' . $route );
}
$request = new class { public function get_route(){ return '/wc/v3/orders'; } };
check( 'unchanged' === Company_Order_Sync_Environment_Guard::protect_rest('unchanged', null, $request), 'Other REST APIs unchanged' );
ob_start();
Company_Order_Sync_Environment_Guard::notice();
$notice = ob_get_clean();
check( $blocked ? '' !== $notice : '' === $notice, 'Notice only in non-production' );
echo "PASS: $environment (bootstrap, activation, workers, REST, HTTP, same-domain paths, notice)\n";
