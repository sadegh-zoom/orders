<?php
/**
 * Plugin Name: Company Central Orders
 * Description: پنل داخلی مدیریت سفارش‌های همگام‌شده WooCommerce در سایت مرکزی.
 * Version: 0.16.8
 * Author: Internal
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Text Domain: company-central-orders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMPANY_CENTRAL_ORDERS_VERSION', '0.16.8' );
define( 'COMPANY_CENTRAL_ORDERS_FILE', __FILE__ );
define( 'COMPANY_CENTRAL_ORDERS_PATH', plugin_dir_path( __FILE__ ) );

require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-access.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-email-suppression.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-jalali-date.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-delivery-data.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-print-settings.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-barcode.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-order-card.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-rest-controller.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-print-controller.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-bulk-export-controller.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-admin-page.php';
require_once COMPANY_CENTRAL_ORDERS_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Company_Central_Orders_Access', 'activate' ) );

Company_Central_Orders_Plugin::init();
