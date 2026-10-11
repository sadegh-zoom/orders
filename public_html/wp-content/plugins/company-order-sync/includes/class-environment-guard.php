<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Restrict synchronization to the production WordPress installation. */
final class Company_Order_Sync_Environment_Guard {

	public static function blocked() {
		$environment = function_exists( 'wp_get_environment_type' )
			? wp_get_environment_type()
			: ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production' );
		return 'production' !== $environment;
	}

	public static function hooks() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'protect_rest' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_http_request', array( __CLASS__, 'protect_http' ), PHP_INT_MAX, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function error() {
		return new WP_Error(
			'company_sync_environment_blocked',
			'Company Order Sync is disabled in non-production environments.',
			array( 'status' => 403 )
		);
	}

	public static function protect_rest( $result, $server, $request ) {
		if ( self::blocked() && self::is_sync_route( $request->get_route() ) ) {
			return self::error();
		}
		return $result;
	}

	public static function protect_http( $preempt, $args, $url ) {
		if ( ! self::blocked() ) {
			return $preempt;
		}
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		$route = isset( $params['rest_route'] ) && is_string( $params['rest_route'] ) ? $params['rest_route'] : '';
		if ( preg_match( '~/(?:index\.php/)?wp-json/company-sync/v1(?:/|$)~', $path ) || self::is_sync_route( $route ) ) {
			return self::error();
		}
		return $preempt;
	}

	private static function is_sync_route( $route ) {
		return (bool) preg_match( '~^/company-sync/v1(?:/|$)~', (string) $route );
	}

	public static function notice() {
		if ( self::blocked() && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Company Order Sync: این نصب وردپرس محیط production نیست. ارسال، دریافت و پردازش صف همگام‌سازی غیرفعال است. اتصال سایت اصلی مستقل باقی می‌ماند.', 'company-order-sync' ) . '</p></div>';
		}
	}
}
