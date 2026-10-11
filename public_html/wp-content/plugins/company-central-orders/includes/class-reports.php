<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Reports {
	const PATH = 'sadegh-url';
	const NS   = 'company-central/v1';

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	private function assets() {
		wp_enqueue_style( 'cco-reports-font', plugins_url( 'assets/iransansx.css', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-reports', plugins_url( 'assets/reports.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-font' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-reports-fixes', plugins_url( 'assets/reports-fixes.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_script( 'cco-reports', plugins_url( 'assets/reports.js', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_enqueue_script( 'cco-reports-detail', plugins_url( 'assets/reports-shipping-nav.js', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_enqueue_script( 'cco-product-ranking-cards', plugins_url( 'assets/product-ranking-cards.js', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_enqueue_style( 'cco-geography-report', plugins_url( 'assets/geography-report.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-fixes' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_script( 'cco-geography-cards', plugins_url( 'assets/geography-cards.js', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_localize_script( 'cco-reports', 'CCO_REPORTS', array(
			'url' => esc_url_raw( rest_url( self::NS . '/reports' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'ordersUrl' => admin_url( 'admin.php?page=' . Company_Central_Orders_Admin_Page::SLUG ),
			'shippingUrl' => home_url( '/' . self::PATH . '/shipping/' ),
			'productsApi' => esc_url_raw( rest_url( self::NS . '/reports/products' ) ),
			'productsUrl' => home_url( '/' . self::PATH . '/products/' ),
			'locationsApi' => esc_url_raw( rest_url( self::NS . '/reports/locations' ) ),
			'locationsUrl' => home_url( '/' . self::PATH . '/locations/' ),
			'iranMapUrl' => plugins_url( 'assets/iran-provinces.svg', COMPANY_CENTRAL_ORDERS_FILE ),
		) );
	}

	private function location_assets() {
		wp_enqueue_style( 'cco-reports-font', plugins_url( 'assets/iransansx.css', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-geography-report', plugins_url( 'assets/geography-report.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-font' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_script( 'cco-location-report', plugins_url( 'assets/location-report.js', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_localize_script( 'cco-location-report', 'CCO_LOCATION_REPORT', array(
			'url'=>esc_url_raw( rest_url( self::NS . '/reports/locations' ) ), 'nonce'=>wp_create_nonce( 'wp_rest' ),
			'reportsUrl'=>home_url( '/' . self::PATH . '/' ), 'period'=>sanitize_key( (string) ( $_GET['period'] ?? 'last_week' ) ),
			'province'=>sanitize_text_field( (string) ( $_GET['province'] ?? '' ) ),
		) );
	}

	private function shipping_assets( $method ) {
		wp_enqueue_style( 'cco-reports-font', plugins_url( 'assets/iransansx.css', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-shipping-report', plugins_url( 'assets/shipping-report.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-font' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_script( 'cco-shipping-report', plugins_url( 'assets/shipping-report.js', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_localize_script( 'cco-shipping-report', 'CCO_SHIPPING_REPORT', array(
			'url' => esc_url_raw( rest_url( self::NS . '/reports/orders' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			'method' => $method, 'reportsUrl' => home_url( '/' . self::PATH . '/' ),
			'filters' => array(
				'period'=>sanitize_key( (string) ( $_GET['period'] ?? 'day' ) ), 'store'=>sanitize_key( (string) ( $_GET['store'] ?? '' ) ),
				'agent'=>sanitize_user( (string) ( $_GET['agent'] ?? '' ), false ), 'from'=>sanitize_text_field( (string) ( $_GET['from'] ?? '' ) ), 'to'=>sanitize_text_field( (string) ( $_GET['to'] ?? '' ) ),
			),
		) );
	}

	private function product_assets() {
		wp_enqueue_style( 'cco-reports-font', plugins_url( 'assets/iransansx.css', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-reports', plugins_url( 'assets/reports.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-font' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-reports-fixes', plugins_url( 'assets/reports-fixes.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_style( 'cco-product-report', plugins_url( 'assets/product-report.css', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports-fixes' ), COMPANY_CENTRAL_ORDERS_VERSION );
		wp_enqueue_script( 'cco-product-report', plugins_url( 'assets/product-report.js', COMPANY_CENTRAL_ORDERS_FILE ), array(), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_enqueue_script( 'cco-product-report-fixes', plugins_url( 'assets/product-report-fixes.js', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-product-report' ), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_localize_script( 'cco-product-report', 'CCO_PRODUCT_REPORT', array(
			'url'=>esc_url_raw( rest_url( self::NS . '/reports/products' ) ), 'nonce'=>wp_create_nonce( 'wp_rest' ),
			'productOrdersUrl'=>esc_url_raw( rest_url( self::NS . '/reports/products/orders' ) ),
			'metric'=>sanitize_key( (string) ( $_GET['metric'] ?? 'sales' ) ), 'period'=>sanitize_key( (string) ( $_GET['period'] ?? 'yesterday' ) ),
			'store'=>sanitize_key( (string) ( $_GET['store'] ?? '' ) ), 'reportsUrl'=>home_url( '/' . self::PATH . '/' ),
			'adminOrdersUrl'=>admin_url( 'admin.php?page=' . Company_Central_Orders_Admin_Page::SLUG ),
		) );
	}

	public static function current_user_is_administrator() {
		$user = wp_get_current_user();
		return $user instanceof WP_User && $user->exists() && in_array( 'administrator', (array) $user->roles, true );
	}

	private function is_report_request() {
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		$report_path  = wp_parse_url( home_url( '/' . self::PATH . '/' ), PHP_URL_PATH );
		return untrailingslashit( (string) $request_path ) === untrailingslashit( (string) $report_path );
	}

	private function is_shipping_report_request() {
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		$detail_path  = wp_parse_url( home_url( '/' . self::PATH . '/shipping/' ), PHP_URL_PATH );
		return untrailingslashit( (string) $request_path ) === untrailingslashit( (string) $detail_path );
	}

	private function is_product_report_request() {
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		$detail_path  = wp_parse_url( home_url( '/' . self::PATH . '/products/' ), PHP_URL_PATH );
		return untrailingslashit( (string) $request_path ) === untrailingslashit( (string) $detail_path );
	}

	private function is_location_report_request() {
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		$detail_path  = wp_parse_url( home_url( '/' . self::PATH . '/locations/' ), PHP_URL_PATH );
		return untrailingslashit( (string) $request_path ) === untrailingslashit( (string) $detail_path );
	}

	public function routes() {
		register_rest_route( self::NS, '/reports', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'report' ),
			'permission_callback' => array( __CLASS__, 'current_user_is_administrator' ),
		) );
		register_rest_route( self::NS, '/reports/orders', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'report_orders' ),
			'permission_callback' => array( __CLASS__, 'current_user_is_administrator' ),
		) );
		register_rest_route( self::NS, '/reports/products', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'report_products' ),
			'permission_callback' => array( __CLASS__, 'current_user_is_administrator' ),
		) );
		register_rest_route( self::NS, '/reports/products/orders', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'report_product_orders' ),
			'permission_callback' => array( __CLASS__, 'current_user_is_administrator' ),
		) );
		register_rest_route( self::NS, '/reports/locations', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'report_locations' ),
			'permission_callback' => array( __CLASS__, 'current_user_is_administrator' ),
		) );
	}

	public function maybe_render() {
		$is_detail = $this->is_shipping_report_request();
		$is_product = $this->is_product_report_request();
		$is_location = $this->is_location_report_request();
		if ( ! $this->is_report_request() && ! $is_detail && ! $is_product && ! $is_location ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::PATH . '/' ) ) );
			exit;
		}
		if ( ! self::current_user_is_administrator() ) {
			wp_die( esc_html__( 'شما اجازه مشاهده گزارش‌ها را ندارید.', 'company-central-orders' ), '', array( 'response' => 403 ) );
		}

		status_header( 200 );
		nocache_headers();
		$method = $is_detail ? sanitize_text_field( (string) ( $_GET['method'] ?? '' ) ) : '';
		if ( $is_detail && '' === $method ) wp_die( esc_html__( 'روش ارسال مشخص نشده است.', 'company-central-orders' ), '', array( 'response'=>400 ) );
		if ( $is_detail ) $this->shipping_assets( $method ); elseif ( $is_product ) $this->product_assets(); elseif ( $is_location ) $this->location_assets(); else $this->assets();
		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<title><?php echo esc_html( $is_detail ? 'سفارش‌های روش ارسال ' . $method : ( $is_product ? 'رتبه‌بندی محصولات' : ( $is_location ? 'گزارش شهرها و استان‌ها' : 'گزارش‌های سفارش' ) ) ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="cco-reports-fullscreen">
			<?php if ( $is_detail ) : ?>
				<div id="cco-shipping-report-root" dir="rtl"><div class="shipping-loading">در حال دریافت سفارش‌ها…</div></div>
			<?php elseif ( $is_product ) : ?>
				<div id="cco-product-report-root" dir="rtl"><div class="shipping-loading">در حال دریافت رتبه‌بندی محصولات…</div></div>
			<?php elseif ( $is_location ) : ?>
				<div id="cco-location-report-root" dir="rtl"><div class="geo-loading">در حال دریافت گزارش شهرها…</div></div>
			<?php else : ?>
				<div id="cco-reports-root" dir="rtl"><div class="report-shell"><main><div class="cco-report-loading">در حال محاسبه گزارش‌ها…</div></main></div></div>
			<?php endif; ?>
			<?php wp_footer(); ?>
		</body>
		</html><?php
		exit;
	}

	public function report( WP_REST_Request $request ) {
		$preset = sanitize_key( (string) $request->get_param( 'period' ) );
		if ( ! in_array( $preset, array( 'today', 'day', 'week', 'month', 'year', 'custom' ), true ) ) $preset = 'today';
		$store = sanitize_key( (string) $request->get_param( 'store' ) );
		$agent = sanitize_user( (string) $request->get_param( 'agent' ), false );
		list( $from, $to, $previous_from, $previous_to ) = $this->ranges( $preset, $request );
		$key = 'cco_report_v2_' . md5( implode( '|', array( COMPANY_CENTRAL_ORDERS_VERSION, $from, $to, $previous_from, $previous_to, $store, $agent ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) return rest_ensure_response( $cached );

		$current  = $this->aggregate( $from, $to, $store, $agent );
		$previous = $this->aggregate( $previous_from, $previous_to, $store, $agent );
		$result = array(
			'range' => array(
				'from'          => $from,
				'to'            => $to,
				'previousFrom'  => $previous_from,
				'previousTo'    => $previous_to,
				'fromJalali'    => $this->jalali_date( $from ),
				'toJalali'      => $this->jalali_date( $to ),
				'previousJalali'=> $this->jalali_date( $previous_from ),
			),
			'current' => $current,
			'previous' => $previous,
			'stores' => $this->stores(),
			'agents' => $this->agents(),
			'availability' => array(
				'campaigns' => false,
				'orderBump' => true,
				'message' => 'تشخیص Order Bump از متادیتای اقلام سفارش انجام می‌شود. سفارش‌های قدیمی تا زمان همگام‌سازی دوباره قابل تشخیص نیستند.',
			),
		);
		set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $result );
	}

	public function report_orders( WP_REST_Request $request ) {
		$section = sanitize_key( (string) $request->get_param( 'section' ) );
		$value   = sanitize_text_field( (string) $request->get_param( 'value' ) );
		if ( 'shipping' !== $section || '' === $value ) {
			return new WP_Error( 'invalid_report_detail', 'روش ارسال معتبر نیست.', array( 'status' => 400 ) );
		}
		$preset = sanitize_key( (string) $request->get_param( 'period' ) );
		if ( ! in_array( $preset, array( 'today', 'day', 'week', 'month', 'year', 'custom' ), true ) ) $preset = 'today';
		$store = sanitize_key( (string) $request->get_param( 'store' ) );
		$agent = sanitize_user( (string) $request->get_param( 'agent' ), false );
		$detail_store = sanitize_key( (string) $request->get_param( 'detail_store' ) );
		$detail_from  = sanitize_text_field( (string) $request->get_param( 'detail_from' ) );
		$detail_to    = sanitize_text_field( (string) $request->get_param( 'detail_to' ) );
		$min_total    = max( 0, (float) $request->get_param( 'min_total' ) );
		$max_total    = max( 0, (float) $request->get_param( 'max_total' ) );
		$page         = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page     = absint( $request->get_param( 'per_page' ) );
		$per_page     = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
		$offset       = ( $page - 1 ) * $per_page;
		$detail_from_ts = $detail_from ? strtotime( $detail_from . ' 00:00:00 ' . wp_timezone_string() ) : 0;
		$detail_to_ts   = $detail_to ? strtotime( $detail_to . ' 23:59:59 ' . wp_timezone_string() ) : 0;
		list( $from, $to ) = $this->ranges( $preset, $request );
		$args = array(
			'type'=>'shop_order', 'limit'=>200, 'page'=>1, 'paginate'=>true, 'return'=>'ids',
			'orderby'=>'date', 'order'=>'DESC', 'date_created'=>$from . '...' . $to,
			'meta_query'=>array( array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ) ),
		);
		if ( $store ) $args['meta_query'][] = array( 'key'=>'_company_source_store', 'value'=>$store, 'compare'=>'=' );
		$items = array(); $total = 0; $stores = $this->stores();
		do {
			$result = wc_get_orders( $args );
			foreach ( $result->orders as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof WC_Order || $value !== ( $order->get_shipping_method() ?: 'تعیین نشده' ) ) continue;
				if ( $agent && ! Company_Central_Orders_Access::order_belongs_to_agent_login( $order, $agent ) ) continue;
				$source = sanitize_key( $order->get_meta( '_company_source_store', true ) );
				$date = $order->get_date_created();
				$timestamp = $date instanceof WC_DateTime ? $date->getTimestamp() : 0;
				$order_total = (float) $order->get_total();
				if ( $detail_store && $detail_store !== $source ) continue;
				if ( $detail_from_ts && $timestamp < $detail_from_ts ) continue;
				if ( $detail_to_ts && $timestamp > $detail_to_ts ) continue;
				if ( $min_total && $order_total < $min_total ) continue;
				if ( $max_total && $order_total > $max_total ) continue;
				$total++;
				if ( $total <= $offset || count( $items ) >= $per_page ) continue;
				$items[] = array(
					'id' => $order->get_id(),
					'number' => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ?: $order->get_order_number() ),
					'store' => $stores[ $source ] ?? $source,
					'storeId' => $source,
					'customer' => sanitize_text_field( $order->get_formatted_billing_full_name() ?: 'بدون نام' ),
					'status' => wc_get_order_status_name( $order->get_status() ),
					'date' => $date ? Company_Central_Orders_Jalali_Date::format_datetime( $date ) : '—',
					'total' => $order_total,
					'shipping' => (float) $order->get_shipping_total(),
					'url' => add_query_arg( array( 'page'=>Company_Central_Orders_Admin_Page::SLUG, 'legacy'=>1, 'order_id'=>$order->get_id() ), admin_url( 'admin.php' ) ),
				);
			}
			$args['page']++;
		} while ( $result->max_num_pages >= $args['page'] );
		return rest_ensure_response( array( 'orders'=>$items, 'total'=>$total, 'page'=>$page, 'perPage'=>$per_page, 'totalPages'=>max( 1, (int) ceil( $total / $per_page ) ), 'shippingMethod'=>$value, 'stores'=>$stores ) );
	}

	public function report_products( WP_REST_Request $request ) {
		$metric = sanitize_key( (string) $request->get_param( 'metric' ) );
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$store  = sanitize_key( (string) $request->get_param( 'store' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$sort   = sanitize_key( (string) $request->get_param( 'sort' ) );
		$order  = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'asc' : 'desc';
		$page   = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = in_array( $per_page, array( 10, 25, 50, 100 ), true ) ? $per_page : 10;
		if ( ! in_array( $metric, array( 'sales', 'cancelled', 'views' ), true ) ) $metric = 'sales';
		if ( ! in_array( $period, array( 'yesterday', 'week', 'month', 'year', 'all' ), true ) ) $period = 'yesterday';
		if ( ! in_array( $sort, array( 'quantity', 'revenue', 'orders', 'name' ), true ) ) $sort = 'quantity';
		$key = 'cco_products_' . md5( implode( '|', array( COMPANY_CENTRAL_ORDERS_VERSION, 'product-links-v2', $metric, $period, $store ) ) );
		$products = get_transient( $key );
		if ( ! is_array( $products ) ) {
			$products = 'views' === $metric ? $this->aggregate_product_views( $period, $store ) : $this->aggregate_products( $metric, $period, $store );
			set_transient( $key, $products, 10 * MINUTE_IN_SECONDS );
		}
		if ( is_wp_error( $products ) ) return rest_ensure_response( array( 'available'=>false, 'message'=>$products->get_error_message(), 'products'=>array(), 'total'=>0, 'page'=>1, 'totalPages'=>1, 'stores'=>$this->stores() ) );
		if ( '' !== $search ) $products = array_values( array_filter( $products, static function( $item ) use ( $search ) { return false !== mb_stripos( $item['name'] . ' ' . $item['sku'], $search ); } ) );
		usort( $products, static function( $a, $b ) use ( $sort, $order ) { $result = 'name' === $sort ? strnatcasecmp( $a['name'], $b['name'] ) : ( $a[$sort] <=> $b[$sort] ); return 'asc' === $order ? $result : -$result; } );
		$total = count( $products ); $offset = ( $page - 1 ) * $per_page;
		return rest_ensure_response( array( 'available'=>true, 'products'=>array_slice( $products, $offset, $per_page ), 'total'=>$total, 'page'=>$page, 'perPage'=>$per_page, 'totalPages'=>max( 1, (int) ceil( $total / $per_page ) ), 'stores'=>$this->stores() ) );
	}

	public function report_product_orders( WP_REST_Request $request ) {
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$store = sanitize_key( (string) $request->get_param( 'store' ) );
		$sku = sanitize_text_field( (string) $request->get_param( 'sku' ) );
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
		if ( ! in_array( $period, array( 'yesterday', 'week', 'month', 'year', 'all' ), true ) ) $period = 'yesterday';
		if ( '—' === $sku ) $sku = '';
		if ( '' === $sku && '' === $name ) return new WP_Error( 'product_required', 'محصول مشخص نشده است.', array( 'status'=>400 ) );
		list( $from, $to ) = $this->product_period_dates( $period );
		$args = array(
			'type'=>'shop_order', 'limit'=>200, 'page'=>1, 'paginate'=>true, 'return'=>'ids', 'orderby'=>'date', 'order'=>'DESC',
			'status'=>array( 'cancelled', 'failed', 'refunded' ),
			'date_created'=>$from . ' 00:00:00...' . $to . ' 23:59:59',
			'meta_query'=>array( array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ) ),
		);
		if ( $store ) $args['meta_query'][] = array( 'key'=>'_company_source_store', 'value'=>$store, 'compare'=>'=' );
		$offset = ( $page - 1 ) * $per_page; $total = 0; $orders = array(); $stores = $this->stores();
		do {
			$result = wc_get_orders( $args );
			foreach ( $result->orders as $id ) {
				$order = wc_get_order( $id ); if ( ! $order instanceof WC_Order ) continue;
				$matched_quantity = 0; $matched_total = 0.0;
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$item_sku = sanitize_text_field( $item->get_meta( '_company_source_sku', true ) );
					$item_name = sanitize_text_field( $item->get_name() );
					$sku_matches = $sku && $item_sku && 0 === strcasecmp( $item_sku, $sku );
					$name_matches = $name && 0 === mb_strcasecmp( trim( $item_name ), trim( $name ), 'UTF-8' );
					$matches = $sku_matches || $name_matches;
					if ( ! $matches ) continue;
					$matched_quantity += max( 0, (int) $item->get_quantity() );
					$matched_total += (float) $item->get_total();
				}
				if ( ! $matched_quantity ) continue;
				$total++; if ( $total <= $offset || count( $orders ) >= $per_page ) continue;
				$source = sanitize_key( $order->get_meta( '_company_source_store', true ) ); $date = $order->get_date_created();
				$orders[] = array(
					'id'=>$order->get_id(), 'number'=>sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ?: $order->get_order_number() ),
					'store'=>$stores[$source] ?? $source, 'customer'=>sanitize_text_field( $order->get_formatted_billing_full_name() ?: 'بدون نام' ),
					'date'=>$date ? Company_Central_Orders_Jalali_Date::format_datetime( $date ) : '—', 'status'=>wc_get_order_status_name( $order->get_status() ),
					'quantity'=>$matched_quantity, 'productTotal'=>$matched_total, 'orderTotal'=>(float) $order->get_total(),
					'url'=>add_query_arg( array( 'page'=>Company_Central_Orders_Admin_Page::SLUG, 'legacy'=>1, 'order_id'=>$order->get_id() ), admin_url( 'admin.php' ) ),
				);
			}
			$args['page']++;
		} while ( $result->max_num_pages >= $args['page'] );
		return rest_ensure_response( array( 'product'=>array( 'name'=>$name, 'sku'=>$sku ?: '—' ), 'orders'=>$orders, 'total'=>$total, 'page'=>$page, 'perPage'=>$per_page, 'totalPages'=>max( 1, (int) ceil( $total / $per_page ) ) ) );
	}

	public function report_locations( WP_REST_Request $request ) {
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		if ( ! in_array( $period, array( 'today', 'day', 'week', 'last_week', 'month', 'year' ), true ) ) $period = 'last_week';
		$province = $this->normalize_location( (string) $request->get_param( 'province' ) );
		$key = 'cco_locations_' . md5( COMPANY_CENTRAL_ORDERS_VERSION . '|' . $period );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			list( $from, $to ) = $this->ranges( $period, $request );
			$data = $this->aggregate_locations( $from, $to );
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
		$cities = $data['cities'];
		if ( $province ) $cities = array_values( array_filter( $cities, static function( $item ) use ( $province ) { return $item['province'] === $province; } ) );
		return rest_ensure_response( array( 'period'=>$period, 'province'=>$province, 'provinces'=>$data['provinces'], 'cities'=>$cities, 'topCities'=>array_slice( $data['cities'], 0, 10 ), 'totalOrders'=>$data['totalOrders'] ) );
	}

	private function normalize_location( $value ) {
		$value = sanitize_text_field( $value );
		$value = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'أ', 'إ' ), array( 'ی', 'ک', 'ه', 'ه', 'ا', 'ا' ), $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );
		return (string) $value;
	}

	private function province_name( WC_Order $order ) {
		$state = $this->normalize_location( $order->get_shipping_state() ?: $order->get_billing_state() );
		$states = WC()->countries->get_states( 'IR' );
		if ( isset( $states[ $state ] ) ) $state = $this->normalize_location( $states[ $state ] );
		$state = preg_replace( '/^(استان|Province of)\s+/ui', '', $state );
		$aliases = array( 'تهران'=>'تهران', 'اصفهان'=>'اصفهان', 'خراسان رضوی'=>'خراسان رضوی', 'آذربایجان شرقی'=>'آذربایجان شرقی', 'آذربایجان غربی'=>'آذربایجان غربی', 'سیستان و بلوچستان'=>'سیستان و بلوچستان', 'کهگیلویه و بویراحمد'=>'کهگیلویه و بویراحمد', 'چهارمحال و بختیاری'=>'چهارمحال و بختیاری' );
		return $aliases[ $state ] ?? $state;
	}

	private function aggregate_locations( $from, $to ) {
		$args = array( 'type'=>'shop_order', 'limit'=>200, 'page'=>1, 'paginate'=>true, 'return'=>'ids', 'orderby'=>'date', 'order'=>'DESC', 'date_created'=>$from . '...' . $to, 'meta_query'=>array( array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ) ) );
		$provinces = array(); $cities = array(); $total = 0;
		do {
			$result = wc_get_orders( $args );
			foreach ( $result->orders as $id ) {
				$order = wc_get_order( $id ); if ( ! $order instanceof WC_Order ) continue;
				$province = $this->province_name( $order );
				$city = $this->normalize_location( $order->get_shipping_city() ?: $order->get_billing_city() );
				if ( ! $province ) $province = 'استان نامشخص';
				if ( ! $city ) $city = 'شهر نامشخص';
				$provinces[ $province ] = ( $provinces[ $province ] ?? 0 ) + 1;
				$key = $province . '|' . $city;
				if ( ! isset( $cities[ $key ] ) ) $cities[ $key ] = array( 'province'=>$province, 'city'=>$city, 'orders'=>0 );
				$cities[ $key ]['orders']++; $total++;
			}
			$args['page']++;
		} while ( $result->max_num_pages >= $args['page'] );
		arsort( $provinces );
		$cities = array_values( $cities );
		usort( $cities, static function( $a, $b ) { return $b['orders'] <=> $a['orders'] ?: strnatcasecmp( $a['city'], $b['city'] ); } );
		return array( 'provinces'=>$provinces, 'cities'=>$cities, 'totalOrders'=>$total );
	}

	private function aggregate_products( $metric, $period, $store ) {
		$tz = wp_timezone(); $today = new DateTimeImmutable( 'today', $tz );
		$args = array( 'type'=>'shop_order', 'limit'=>200, 'page'=>1, 'paginate'=>true, 'return'=>'ids', 'orderby'=>'date', 'order'=>'DESC', 'meta_query'=>array( array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ) ) );
		if ( 'yesterday' === $period ) { $yesterday = $today->modify( '-1 day' ); $args['date_created'] = $yesterday->format( 'Y-m-d 00:00:00' ) . '...' . $yesterday->format( 'Y-m-d 23:59:59' ); }
		if ( 'week' === $period ) $args['date_created'] = $today->modify( '-' . ( ( (int) $today->format( 'w' ) + 1 ) % 7 ) . ' days' )->format( 'Y-m-d 00:00:00' ) . '...' . ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
		if ( 'month' === $period ) $args['date_created'] = $today->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' ) . '...' . ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
		if ( 'year' === $period ) $args['date_created'] = $today->setDate( (int) $today->format( 'Y' ), 1, 1 )->format( 'Y-m-d 00:00:00' ) . '...' . ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
		if ( $store ) $args['meta_query'][] = array( 'key'=>'_company_source_store', 'value'=>$store, 'compare'=>'=' );
		$out = array();
		do {
			$result = wc_get_orders( $args );
			foreach ( $result->orders as $id ) {
				$order = wc_get_order( $id ); if ( ! $order instanceof WC_Order ) continue;
				$status = $order->get_status(); $cancelled = in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true );
				$successful = ! $cancelled && ( $order->get_date_paid() instanceof WC_DateTime || in_array( $status, array( 'processing', 'completed', 'printed-send', 'moalagh-send' ), true ) );
				if ( ( 'sales' === $metric && ! $successful ) || ( 'cancelled' === $metric && ! $cancelled ) ) continue;
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$sku = sanitize_text_field( $item->get_meta( '_company_source_sku', true ) );
					$name = sanitize_text_field( $item->get_name() ); $id_key = $sku ? 'sku:' . mb_strtolower( $sku ) : 'name:' . mb_strtolower( $name );
					if ( ! isset( $out[$id_key] ) ) $out[$id_key] = array( 'name'=>$name, 'sku'=>$sku ?: '—', 'productUrl'=>'', 'quantity'=>0, 'orders'=>0, 'revenue'=>0.0 );
					if ( '' === $out[$id_key]['productUrl'] ) {
						$out[$id_key]['productUrl'] = $this->source_product_edit_url(
							sanitize_key( $order->get_meta( '_company_source_store', true ) ),
							absint( $item->get_meta( '_company_source_product_id', true ) ),
							$sku,
							$name
						);
					}
					$out[$id_key]['quantity'] += max( 0, (int) $item->get_quantity() ); $out[$id_key]['orders']++; $out[$id_key]['revenue'] += (float) $item->get_total();
				}
			}
			$args['page']++;
		} while ( $result->max_num_pages >= $args['page'] );
		return array_values( $out );
	}

	private function source_product_edit_url( $store_id, $product_id, $sku = '', $name = '' ) {
		if ( ! $store_id || ! class_exists( 'Company_Order_Sync_Settings' ) ) return '';
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		$base  = is_array( $store ) ? trim( (string) ( $store['url'] ?? '' ) ) : '';
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) return '';
		$base_url = strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' ) . untrailingslashit( $parts['path'] ?? '' );
		if ( $product_id ) return esc_url_raw( trailingslashit( $base_url ) . 'wp-admin/post.php?post=' . absint( $product_id ) . '&action=edit' );
		$search = trim( (string) $sku ) ?: trim( (string) $name );
		if ( '' === $search ) return '';
		return esc_url_raw( trailingslashit( $base_url ) . 'wp-admin/edit.php?post_type=product&s=' . rawurlencode( $search ) );
	}

	private function aggregate_product_views( $period, $store_filter ) {
		if ( ! class_exists( 'Company_Order_Sync_Settings' ) || ! class_exists( 'Company_Order_Sync_Security' ) ) {
			return new WP_Error( 'views_sync_missing', 'برای دریافت بازدیدها، نسخه جدید افزونه Company Order Sync باید روی Orders فعال باشد.' );
		}
		list( $from, $to ) = $this->product_period_dates( $period );
		$stores = Company_Order_Sync_Settings::enabled_central_stores();
		if ( $store_filter ) $stores = isset( $stores[$store_filter] ) ? array( $store_filter=>$stores[$store_filter] ) : array();
		if ( ! $stores ) return new WP_Error( 'views_store_missing', 'هیچ اتصال فروشگاه فعالی برای دریافت بازدیدها وجود ندارد.' );
		$out = array(); $errors = array();
		foreach ( $stores as $store_id=>$store ) {
			$body = wp_json_encode( array( 'store_id'=>$store_id, 'from'=>$from, 'to'=>$to ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$url = untrailingslashit( (string) ( $store['url'] ?? '' ) ) . '/wp-json/company-sync/v1/product-views';
			$response = wp_safe_remote_post( $url, array( 'timeout'=>20, 'redirection'=>0, 'headers'=>Company_Order_Sync_Security::headers( $store_id, (string) ( $store['outbound_secret'] ?? '' ), $body ), 'body'=>$body, 'data_format'=>'body' ) );
			if ( is_wp_error( $response ) ) { $errors[]=$store_id; continue; }
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true ); $http=(int) wp_remote_retrieve_response_code( $response );
			if ( $http < 200 || $http >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) { $errors[]=$store_id; continue; }
			foreach ( (array) ( $data['products'] ?? array() ) as $product ) {
				$sku=sanitize_text_field( $product['sku'] ?? '' ); $name=sanitize_text_field( $product['name'] ?? '' );
				$key=$sku ? 'sku:' . mb_strtolower( $sku ) : $store_id . ':name:' . mb_strtolower( $name );
				if ( ! isset( $out[$key] ) ) $out[$key]=array( 'name'=>$name, 'sku'=>$sku ?: '—', 'quantity'=>0, 'orders'=>0, 'revenue'=>0.0 );
				$out[$key]['quantity'] += absint( $product['views'] ?? 0 );
			}
		}
		if ( ! $out && $errors ) return new WP_Error( 'views_stores_unavailable', 'داده بازدید دریافت نشد؛ نسخه ۰.۱۴.۰ افزونه Sync را روی هر دو سایت مبدأ نصب و اتصال‌ها را بررسی کنید.' );
		return array_values( $out );
	}

	private function product_period_dates( $period ) {
		$today=new DateTimeImmutable( 'today', wp_timezone() ); $to=$today->format( 'Y-m-d' );
		if ( 'yesterday' === $period ) { $date=$today->modify( '-1 day' )->format( 'Y-m-d' ); return array( $date, $date ); }
		if ( 'week' === $period ) return array( $today->modify( '-' . ( ( (int) $today->format( 'w' ) + 1 ) % 7 ) . ' days' )->format( 'Y-m-d' ), $to );
		if ( 'month' === $period ) return array( $today->modify( 'first day of this month' )->format( 'Y-m-d' ), $to );
		if ( 'year' === $period ) return array( $today->setDate( (int) $today->format( 'Y' ), 1, 1 )->format( 'Y-m-d' ), $to );
		return array( '2020-01-01', $to );
	}

	private function jalali_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
		return $date ? Company_Central_Orders_Jalali_Date::format_timestamp( $date->getTimestamp(), false ) : '—';
	}

	private function ranges( $preset, WP_REST_Request $request ) {
		$tz = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$to = $today->setTime( 23, 59, 59 );
		if ( 'today' === $preset ) {
			$from = $today;
			$to   = new DateTimeImmutable( 'now', $tz );
		} elseif ( 'custom' === $preset ) {
			$raw_from = sanitize_text_field( (string) $request->get_param( 'from' ) );
			$raw_to = sanitize_text_field( (string) $request->get_param( 'to' ) );
			$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_from, $tz ) ?: $today;
			$to = ( DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_to, $tz ) ?: $today )->setTime( 23, 59, 59 );
			if ( $from > $to ) { $swap = $from; $from = $to->setTime( 0, 0 ); $to = $swap->setTime( 23, 59, 59 ); }
			$min = $today->modify( '-3 years' );
			if ( $from < $min ) $from = $min;
		} elseif ( 'week' === $preset ) {
			$from = $today->modify( '-' . ( ( (int) $today->format( 'w' ) + 1 ) % 7 ) . ' days' );
		} elseif ( 'last_week' === $preset ) {
			$current_week = $today->modify( '-' . ( ( (int) $today->format( 'w' ) + 1 ) % 7 ) . ' days' );
			$from = $current_week->modify( '-7 days' );
			$to = $current_week->modify( '-1 second' );
		} elseif ( 'month' === $preset ) {
			$from = $today->modify( 'first day of this month' );
		} elseif ( 'year' === $preset ) {
			$from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
		} else {
			// Compare two completed days; today's partial numbers are misleading.
			$from = $today->modify( '-1 day' );
			$to   = $from->setTime( 23, 59, 59 );
		}
		if ( 'today' === $preset ) {
			$previous_from = $from->modify( '-1 day' );
			$previous_to   = $to->modify( '-1 day' );
			return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), $previous_from->format( 'Y-m-d H:i:s' ), $previous_to->format( 'Y-m-d H:i:s' ) );
		}
		$seconds = $to->getTimestamp() - $from->getTimestamp() + 1;
		$previous_to = $from->modify( '-1 second' );
		$previous_from = $previous_to->modify( '-' . ( $seconds - 1 ) . ' seconds' );
		return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), $previous_from->format( 'Y-m-d H:i:s' ), $previous_to->format( 'Y-m-d H:i:s' ) );
	}

	private function aggregate( $from, $to, $store, $agent ) {
		$totals = array( 'orders'=>0, 'successful'=>0, 'revenue'=>0.0, 'cancelled'=>0, 'agentOrders'=>0, 'agentRevenue'=>0.0, 'agentCancelled'=>0, 'singleItem'=>0, 'multiItem'=>0, 'shippingCost'=>0.0, 'orderBumpOrders'=>0, 'orderBumpItems'=>0, 'orderBumpRevenue'=>0.0, 'orderBumpCoverage'=>0 );
		$by_store = array(); $gateways = array(); $shipping = array(); $coupons = array(); $users = array(); $daily = array(); $agent_stats = array();
		$args = array( 'type'=>'shop_order', 'limit'=>200, 'page'=>1, 'paginate'=>true, 'return'=>'ids', 'orderby'=>'date', 'order'=>'ASC', 'date_created'=>$from . '...' . $to, 'meta_query'=>array( array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ) ) );
		if ( $store ) $args['meta_query'][] = array( 'key'=>'_company_source_store', 'value'=>$store, 'compare'=>'=' );
		do {
			$result = wc_get_orders( $args );
			foreach ( $result->orders as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof WC_Order ) continue;
				$order_agents = array();
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$login = sanitize_user( $item->get_meta( '_company_assigned_seller_login', true ), false );
					$name = sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) ?: $item->get_meta( '_company_tamin_agent_name', true ) );
					if ( $login || $name ) $order_agents[ $login ?: sanitize_title( $name ) ] = $name ?: $login;
				}
				if ( $agent && ! isset( $order_agents[ $agent ] ) ) continue;
				$status = $order->get_status(); $cancelled = in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true );
				$successful = ! $cancelled && ( $order->get_date_paid() instanceof WC_DateTime || in_array( $status, array( 'processing', 'completed', 'printed-send', 'moalagh-send' ), true ) );
				$bump_items = 0; $bump_revenue = 0.0; $bump_coverage = true;
				foreach ( $order->get_items( 'line_item' ) as $line_item ) {
					if ( 'yes' !== $line_item->get_meta( '_company_source_order_bump_synced', true ) ) $bump_coverage = false;
					if ( 'yes' === $line_item->get_meta( '_company_source_is_order_bump', true ) ) {
						$bump_items += max( 0, (int) $line_item->get_quantity() );
						$bump_revenue += (float) $line_item->get_total() + (float) $line_item->get_total_tax();
					}
				}
				if ( $bump_coverage ) $totals['orderBumpCoverage']++;
				if ( $bump_items ) {
					$totals['orderBumpOrders']++;
					$totals['orderBumpItems'] += $bump_items;
					if ( $successful ) $totals['orderBumpRevenue'] += $bump_revenue;
				}
				$value = (float) $order->get_total(); $source = sanitize_key( $order->get_meta( '_company_source_store', true ) );
				$totals['orders']++; $totals['shippingCost'] += (float) $order->get_shipping_total();
				if ( $successful ) $totals['successful']++;
				// Revenue means money from successful orders, not the face value of cancelled/failed/refunded orders.
				if ( $successful ) $totals['revenue'] += $value;
				if ( $cancelled ) $totals['cancelled']++;
				$order->get_item_count() > 1 ? $totals['multiItem']++ : $totals['singleItem']++;
				if ( $order_agents ) { $totals['agentOrders']++; if ( $successful ) $totals['agentRevenue'] += $value; if ( $cancelled ) $totals['agentCancelled']++; }
				if ( ! isset( $by_store[$source] ) ) $by_store[$source] = array( 'orders'=>0, 'successful'=>0, 'revenue'=>0.0, 'cancelled'=>0 );
				$by_store[$source]['orders']++; if ( $successful ) { $by_store[$source]['successful']++; $by_store[$source]['revenue'] += $value; } if ( $cancelled ) $by_store[$source]['cancelled']++;
				$gateway = $order->get_payment_method_title() ?: 'تعیین نشده';
				if ( ! isset( $gateways[$gateway] ) ) $gateways[$gateway] = array( 'successful'=>0, 'cancelled'=>0, 'total'=>0 );
				$gateways[$gateway]['total']++; if ( $successful ) $gateways[$gateway]['successful']++; if ( $cancelled ) $gateways[$gateway]['cancelled']++;
				$ship = $order->get_shipping_method() ?: 'تعیین نشده';
				if ( ! isset( $shipping[$ship] ) ) $shipping[$ship] = array( 'orders'=>0, 'successful'=>0, 'cancelled'=>0, 'charged'=>0.0, 'cost'=>0.0, 'received'=>0.0 );
				$shipping_total = (float) $order->get_shipping_total();
				$shipping[$ship]['orders']++;
				$shipping[$ship]['charged'] += $shipping_total;
				$shipping[$ship]['cost'] += $shipping_total;
				if ( $successful ) { $shipping[$ship]['successful']++; $shipping[$ship]['received'] += $shipping_total; }
				if ( $cancelled ) $shipping[$ship]['cancelled']++;
				foreach ( $order->get_coupon_codes() as $code ) { if ( ! isset( $coupons[$code] ) ) $coupons[$code] = array( 'orders'=>0, 'discount'=>0.0, 'revenue'=>0.0 ); $coupons[$code]['orders']++; $coupons[$code]['discount'] += (float) $order->get_discount_total(); if ( $successful ) $coupons[$code]['revenue'] += $value; }
				foreach ( $order_agents as $login=>$name ) { if ( ! isset( $agent_stats[$login] ) ) $agent_stats[$login] = array( 'name'=>$name, 'orders'=>0, 'revenue'=>0.0, 'cancelled'=>0 ); $agent_stats[$login]['orders']++; if ( $successful ) $agent_stats[$login]['revenue'] += $value; if ( $cancelled ) $agent_stats[$login]['cancelled']++; }
				$customer = $order->get_customer_id() ? 'u:' . $order->get_customer_id() : 'e:' . strtolower( $order->get_billing_email() ); if ( 'e:' !== $customer ) $users[$customer] = true;
				$date = $order->get_date_created(); if ( $date ) { $day = $date->date_i18n( 'Y-m-d' ); if ( ! isset( $daily[$day] ) ) $daily[$day] = array( 'orders'=>0, 'revenue'=>0.0 ); $daily[$day]['orders']++; if ( $successful ) $daily[$day]['revenue'] += $value; }
			}
			$args['page']++;
		} while ( $result->max_num_pages >= $args['page'] );
		ksort( $daily );
		$totals['customers'] = count( $users );
		$totals['dailyAverage'] = count( $daily ) ? round( $totals['orders'] / count( $daily ), 1 ) : 0;
		return array( 'totals'=>$totals, 'stores'=>$by_store, 'gateways'=>$gateways, 'shipping'=>$shipping, 'coupons'=>$coupons, 'agents'=>$agent_stats, 'daily'=>$daily );
	}

	private function stores() {
		$stores = apply_filters( 'company_order_sync_registered_stores', array() );
		$out = array();
		foreach ( (array) $stores as $key=>$store ) {
			$out[ sanitize_key( $key ) ] = sanitize_text_field( is_array( $store ) ? ( $store['label'] ?? $key ) : $store );
		}
		return $out;
	}

	private function agents() {
		$out = array(); foreach ( get_users( array( 'role'=>'seller', 'orderby'=>'display_name' ) ) as $user ) $out[ $user->user_login ] = $user->display_name;
		return $out;
	}
}
