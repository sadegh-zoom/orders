<?php
/**
 * Plugin Name: Company Order Sync
 * Description: همگام‌سازی امن و دوطرفه سفارش‌های WooCommerce بین فروشگاه‌ها و سایت مرکزی.
 * Version: 0.13.19
 * Author: Internal
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Text Domain: company-order-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMPANY_ORDER_SYNC_VERSION', '0.13.19' );
define( 'COMPANY_ORDER_SYNC_FILE', __FILE__ );
define( 'COMPANY_ORDER_SYNC_PATH', plugin_dir_path( __FILE__ ) );

require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-context.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-environment-guard.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-logger.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-settings.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-replay-store.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-security.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-product-seller.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-order-serializer.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-order-mapper.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-queue.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-rest-controller.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-snapshot-sync.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-status-repair.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-status-audit.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-retention-maintenance.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-central-rebuild.php';
require_once COMPANY_ORDER_SYNC_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Company_Order_Sync_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Company_Order_Sync_Plugin', 'deactivate' ) );

Company_Order_Sync_Plugin::init();
