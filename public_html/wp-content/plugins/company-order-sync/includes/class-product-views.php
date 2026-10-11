<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Product_Views {
	const DB_VERSION = '1';
	const DB_OPTION  = 'company_order_sync_product_views_db_version';

	public function hooks() {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		self::maybe_install();
		add_action( 'wp_footer', array( $this, 'tracking_script' ), 99 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'company_product_views_daily';
	}

	public static function maybe_install() {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			product_id bigint(20) unsigned NOT NULL,
			view_date date NOT NULL,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (product_id, view_date),
			KEY view_date (view_date)
		) {$charset};" );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public function tracking_script() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || is_admin() ) {
			return;
		}
		$product_id = get_queried_object_id();
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$endpoint = rest_url( 'company-sync/v1/product-view' );
		$token    = hash_hmac( 'sha256', (string) $product_id, wp_salt( 'nonce' ) );
		?>
		<script>
		(function(){try{var d=new Date(),day=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'),k='company_product_view_<?php echo absint( $product_id ); ?>_'+day;if(localStorage.getItem(k))return;localStorage.setItem(k,'1');fetch(<?php echo wp_json_encode( $endpoint ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:<?php echo absint( $product_id ); ?>,token:<?php echo wp_json_encode( $token ); ?>}),keepalive:true}).catch(function(){localStorage.removeItem(k)});}catch(e){}}());
		</script>
		<?php
	}

	public function record( WP_REST_Request $request ) {
		global $wpdb;
		$product_id = absint( $request->get_param( 'product_id' ) );
		$token      = strtolower( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		$today      = wp_date( 'Y-m-d' );
		$expected   = hash_hmac( 'sha256', (string) $product_id, wp_salt( 'nonce' ) );
		if ( ! $product_id || ! hash_equals( $expected, $token ) || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'invalid_product_view', 'درخواست ثبت بازدید معتبر نیست.', array( 'status'=>400 ) );
		}
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (product_id, view_date, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", $product_id, $today ) );
		return rest_ensure_response( array( 'success'=>true ) );
	}

	public function export( WP_REST_Request $request, array $auth ) {
		global $wpdb;
		$params = $request->get_json_params();
		$from   = $this->valid_date( $params['from'] ?? '' );
		$to     = $this->valid_date( $params['to'] ?? '' );
		if ( ! $from || ! $to || $from > $to ) {
			return Company_Order_Sync_Security::error( 'invalid_view_range', 'بازه گزارش بازدید معتبر نیست.', 400, $auth['request_id'] ?? '' );
		}
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, SUM(views) AS views FROM {$table} WHERE view_date BETWEEN %s AND %s GROUP BY product_id ORDER BY views DESC", $from, $to ), ARRAY_A );
		$products = array();
		foreach ( (array) $rows as $row ) {
			$product = wc_get_product( absint( $row['product_id'] ?? 0 ) );
			if ( ! $product ) continue;
			$products[] = array( 'name'=>sanitize_text_field( $product->get_name() ), 'sku'=>sanitize_text_field( $product->get_sku() ), 'views'=>absint( $row['views'] ?? 0 ) );
		}
		return array( 'success'=>true, 'products'=>$products, 'request_id'=>$auth['request_id'] ?? '' );
	}

	private function valid_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
