<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Print_Settings {

	const OPTION = 'company_central_orders_print_settings';
	const SITE2_OPTION = 'company_central_orders_print_settings_site2';
	const SLUG   = 'company-orders-print-settings';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'option_page_capability_cco_print_settings', static function() { return 'manage_woocommerce'; } );
	}

	public function menu() {
		add_submenu_page(
			Company_Central_Orders_Admin_Page::SLUG,
			'تنظیمات چاپ فروشگاه‌ها',
			'تنظیمات چاپ',
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register() {
		register_setting( 'cco_print_settings', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
		register_setting( 'cco_print_settings', self::SITE2_OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$data  = array();
		foreach ( array( 'seller_name', 'address', 'phone', 'postcode', 'economic_code', 'registration_number', 'email', 'website' ) as $key ) {
			$data[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		}
		$data['logo_url'] = esc_url_raw( $input['logo_url'] ?? '' );
		return $data;
	}

	public static function profile( $store_id = 'site1' ) {
		$saved = 'site1' === $store_id
			? (array) get_option( self::OPTION, array() )
			: ( 'site2' === $store_id ? (array) get_option( self::SITE2_OPTION, array() ) : array() );
		$store_address = implode(
			'، ',
			array_filter(
				array(
					sanitize_text_field( get_option( 'woocommerce_store_address', '' ) ),
					sanitize_text_field( get_option( 'woocommerce_store_address_2', '' ) ),
					sanitize_text_field( get_option( 'woocommerce_store_city', '' ) ),
				)
			)
		);
		return wp_parse_args(
			$saved,
			array(
				'seller_name'         => 'site1' === $store_id ? 'Zoom Bazar' : ( 'site2' === $store_id ? 'Smart Pishro' : get_bloginfo( 'name' ) ),
				'logo_url'            => '',
				'address'             => $store_address,
				'phone'               => sanitize_text_field( get_option( 'woocommerce_store_phone', '' ) ),
				'postcode'            => sanitize_text_field( get_option( 'woocommerce_store_postcode', '' ) ),
				'economic_code'       => '',
				'registration_number' => '',
				'email'               => sanitize_email( get_option( 'admin_email', '' ) ),
				'website'             => 'site1' === $store_id ? 'zoombazar.com' : ( 'site2' === $store_id ? 'smartpishro.com' : home_url() ),
				'store_id'            => sanitize_key( $store_id ),
			)
		);
	}

	public static function store_label( $store_id ) {
		$fixed = array( 'site1' => 'Zoom Bazar', 'site2' => 'Smart Pishro' );
		if ( isset( $fixed[ $store_id ] ) ) {
			return $fixed[ $store_id ];
		}
		$stores = apply_filters( 'company_order_sync_registered_stores', array() );
		return isset( $stores[ $store_id ] ) ? $stores[ $store_id ] : $store_id;
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}
		$fields  = array(
			'seller_name'         => 'نام فروشگاه / فروشنده',
			'logo_url'            => 'آدرس اینترنتی لوگو',
			'address'             => 'آدرس فروشنده',
			'phone'               => 'تلفن',
			'postcode'            => 'کد پستی',
			'economic_code'       => 'کد اقتصادی',
			'registration_number' => 'شماره ثبت',
			'email'               => 'ایمیل',
			'website'             => 'وب‌سایت',
		);

		echo '<div class="wrap"><h1>تنظیمات چاپ فروشگاه‌ها</h1><p>قالب چاپ هر سفارش براساس فروشگاه مبدأ و با اندازه استاندارد خودش ساخته می‌شود.</p><form method="post" action="options.php">';
		settings_fields( 'cco_print_settings' );
		foreach ( array( 'site1'=>array( 'option'=>self::OPTION, 'title'=>'Zoom Bazar' ), 'site2'=>array( 'option'=>self::SITE2_OPTION, 'title'=>'Smart Pishro' ) ) as $store_id => $store ) {
			$profile = self::profile( $store_id );
			echo '<h2>' . esc_html( $store['title'] ) . ' <code>' . esc_html( $store_id ) . '</code></h2><table class="form-table" role="presentation">';
			foreach ( $fields as $key => $label ) {
				$field_id = 'cco-' . $store_id . '-' . $key;
				echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $store['option'] ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $profile[ $key ] ) . '" type="text"></td></tr>';
			}
			echo '</table>';
		}
		submit_button( 'ذخیره تنظیمات چاپ' );
		echo '</form></div>';
	}
}
