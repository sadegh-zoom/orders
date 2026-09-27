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
		wp_enqueue_script( 'cco-reports-detail', plugins_url( 'assets/reports-detail-table.js', COMPANY_CENTRAL_ORDERS_FILE ), array( 'cco-reports' ), COMPANY_CENTRAL_ORDERS_VERSION, true );
		wp_localize_script( 'cco-reports', 'CCO_REPORTS', array(
			'url' => esc_url_raw( rest_url( self::NS . '/reports' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'ordersUrl' => admin_url( 'admin.php?page=' . Company_Central_Orders_Admin_Page::SLUG ),
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
	}

	public function maybe_render() {
		if ( ! $this->is_report_request() ) {
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
		$this->assets();
		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<title><?php echo esc_html__( 'گزارش‌های سفارش', 'company-central-orders' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="cco-reports-fullscreen">
			<div id="cco-reports-root" dir="rtl"><div class="report-shell"><main><div class="cco-report-loading">در حال محاسبه گزارش‌ها…</div></main></div></div>
			<?php wp_footer(); ?>
		</body>
		</html><?php
		exit;
	}

	public function report( WP_REST_Request $request ) {
		$preset = sanitize_key( (string) $request->get_param( 'period' ) );
		if ( ! in_array( $preset, array( 'day', 'week', 'month', 'year', 'custom' ), true ) ) $preset = 'day';
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
				'orderBump' => false,
				'message' => 'Campaign و Order Bump در payload همگام‌سازی فعلی فیلد مستقل ندارند؛ اعداد حدسی نمایش داده نمی‌شوند.',
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
		if ( ! in_array( $preset, array( 'day', 'week', 'month', 'year', 'custom' ), true ) ) $preset = 'day';
		$store = sanitize_key( (string) $request->get_param( 'store' ) );
		$agent = sanitize_user( (string) $request->get_param( 'agent' ), false );
		$detail_store = sanitize_key( (string) $request->get_param( 'detail_store' ) );
		$detail_from  = sanitize_text_field( (string) $request->get_param( 'detail_from' ) );
		$detail_to    = sanitize_text_field( (string) $request->get_param( 'detail_to' ) );
		$min_total    = max( 0, (float) $request->get_param( 'min_total' ) );
		$max_total    = max( 0, (float) $request->get_param( 'max_total' ) );
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
				if ( count( $items ) >= 100 ) continue;
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
		return rest_ensure_response( array( 'orders'=>$items, 'total'=>$total, 'limited'=>$total > count( $items ), 'shippingMethod'=>$value, 'stores'=>$stores ) );
	}

	private function jalali_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
		return $date ? Company_Central_Orders_Jalali_Date::format_timestamp( $date->getTimestamp(), false ) : '—';
	}

	private function ranges( $preset, WP_REST_Request $request ) {
		$tz = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$to = $today->setTime( 23, 59, 59 );
		if ( 'custom' === $preset ) {
			$raw_from = sanitize_text_field( (string) $request->get_param( 'from' ) );
			$raw_to = sanitize_text_field( (string) $request->get_param( 'to' ) );
			$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_from, $tz ) ?: $today;
			$to = ( DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_to, $tz ) ?: $today )->setTime( 23, 59, 59 );
			if ( $from > $to ) { $swap = $from; $from = $to->setTime( 0, 0 ); $to = $swap->setTime( 23, 59, 59 ); }
			$min = $today->modify( '-3 years' );
			if ( $from < $min ) $from = $min;
		} elseif ( 'week' === $preset ) {
			$from = $today->modify( '-' . ( ( (int) $today->format( 'w' ) + 1 ) % 7 ) . ' days' );
		} elseif ( 'month' === $preset ) {
			$from = $today->modify( 'first day of this month' );
		} elseif ( 'year' === $preset ) {
			$from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
		} else {
			// Compare two completed days; today's partial numbers are misleading.
			$from = $today->modify( '-1 day' );
			$to   = $from->setTime( 23, 59, 59 );
		}
		$seconds = $to->getTimestamp() - $from->getTimestamp() + 1;
		$previous_to = $from->modify( '-1 second' );
		$previous_from = $previous_to->modify( '-' . ( $seconds - 1 ) . ' seconds' );
		return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), $previous_from->format( 'Y-m-d H:i:s' ), $previous_to->format( 'Y-m-d H:i:s' ) );
	}

	private function aggregate( $from, $to, $store, $agent ) {
		$totals = array( 'orders'=>0, 'successful'=>0, 'revenue'=>0.0, 'cancelled'=>0, 'agentOrders'=>0, 'agentRevenue'=>0.0, 'agentCancelled'=>0, 'singleItem'=>0, 'multiItem'=>0, 'shippingCost'=>0.0 );
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
