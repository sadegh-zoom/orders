<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Plugin {

	private static $instance;

	public static function init() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COMPANY_CENTRAL_ORDERS_FILE, true );
		}
	}

	public static function boot() {
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Company Central Orders برای اجرا به WooCommerce نیاز دارد.', 'company-central-orders' ) . '</p></div>';
		}
	}

	private function __construct() {
		$admin = new Company_Central_Orders_Admin_Page();
		$rest = new Company_Central_Orders_REST_Controller();
		$email = new Company_Central_Orders_Email_Suppression();
		$print = new Company_Central_Orders_Print_Controller();
		$export = new Company_Central_Orders_Bulk_Export_Controller();
		$settings = new Company_Central_Orders_Print_Settings();
		$admin->hooks();
		$rest->hooks();
		$email->hooks();
		$print->hooks();
		$export->hooks();
		$settings->hooks();
		add_filter( 'user_has_cap', array( 'Company_Central_Orders_Access', 'grant_company_role_caps' ), 20, 4 );
		add_filter( 'user_has_cap', array( 'Company_Central_Orders_Access', 'restrict_user_management_caps' ), 100, 4 );
		add_filter( 'map_meta_cap', array( 'Company_Central_Orders_Access', 'restrict_user_meta_caps' ), 100, 4 );
	}
}
