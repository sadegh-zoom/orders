<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Plugin {

	private static $instance;

	public static function init() {
		Company_Order_Sync_Environment_Guard::hooks();
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function activate() {
		if ( Company_Order_Sync_Environment_Guard::blocked() ) {
			return;
		}
		Company_Order_Sync_Replay_Store::install();
		self::schedule_cleanup();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'company_order_sync_cleanup_replay' );
		Company_Order_Sync_Queue::unschedule_reconciliation();
		Company_Order_Sync_Status_Repair::unschedule_auto();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Company_Order_Sync_Status_Audit::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( Company_Order_Sync_Status_Audit::HOOK );
		Company_Order_Sync_Retention_Maintenance::unschedule_all();
	}

	public static function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COMPANY_ORDER_SYNC_FILE, true );
		}
	}

	public static function boot() {
		// Do not bind workers, shutdown pushes, admin mutations or REST handlers
		// on a cloned installation. Existing queued actions have no sync callbacks.
		if ( Company_Order_Sync_Environment_Guard::blocked() ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( ! self::$instance ) {
			self::$instance = new self();
		}
	}

	public static function woocommerce_missing_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Company Order Sync برای اجرا به WooCommerce نیاز دارد.', 'company-order-sync' ) . '</p></div>';
		}
	}

	private function __construct() {
		Company_Order_Sync_Central_Rebuild::bind_request();
		Company_Order_Sync_Replay_Store::maybe_install();
		self::schedule_cleanup();

		$settings = new Company_Order_Sync_Settings();
		$queue    = new Company_Order_Sync_Queue();
		$rest     = new Company_Order_Sync_REST_Controller();
		$snapshot = new Company_Order_Sync_Snapshot_Sync();
		$status_repair = new Company_Order_Sync_Status_Repair();
		$status_audit = new Company_Order_Sync_Status_Audit();
		$maintenance = new Company_Order_Sync_Retention_Maintenance();
		$product_seller = new Company_Order_Sync_Product_Seller();

		$settings->hooks();
		// Labels are read-only presentation data, needed even while workers pause.
		add_filter( 'company_order_sync_registered_stores', array( $this, 'registered_stores' ) );
		( new Company_Order_Sync_Central_Rebuild() )->hooks();
		if ( Company_Order_Sync_Central_Rebuild::active() ) {
			$rest->hooks();
			add_action( 'init', array( $snapshot, 'register_remote_statuses' ) );
			add_filter( 'wc_order_statuses', array( $snapshot, 'merge_remote_statuses' ), 20 );
			return;
		}
		$queue->hooks();
		$rest->hooks();
		$snapshot->hooks();
		$status_repair->hooks();
		$status_audit->hooks();
		$maintenance->hooks();
		$product_seller->hooks();

		add_action( 'company_order_sync_cleanup_replay', array( 'Company_Order_Sync_Replay_Store', 'cleanup' ) );
	}

	public function registered_stores( $stores ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return $stores;
		}

		foreach ( Company_Order_Sync_Settings::enabled_central_stores() as $store_id => $store ) {
			$stores[ $store_id ] = $store['label'] ?: $store_id;
		}

		return $stores;
	}

	private static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'company_order_sync_cleanup_replay' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'company_order_sync_cleanup_replay' );
		}
	}
}
