<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Access {

	const ROLE      = 'company_order_operator';
	const CAP       = 'company_manage_orders';
	const RETRY_CAP = 'company_retry_order_sync';
	const COMPANY_ROLES = array( 'administrator', 'acc_manager', 'digikala_admin', 'logistics', 'seller', 'shop_manager', 'customer_support' );

	public static function activate() {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			$role = add_role(
				self::ROLE,
				'اپراتور سفارشات',
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		} elseif ( ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}

		foreach ( self::COMPANY_ROLES as $role_name ) {
			$manager = get_role( $role_name );
			if ( $manager ) {
				$manager->add_cap( self::CAP );
				if ( in_array( $role_name, array( 'administrator', 'shop_manager' ), true ) ) {
					$manager->add_cap( self::RETRY_CAP );
				}
			}
		}
	}

	public static function is_operator_only() {
		return current_user_can( self::CAP ) && ! current_user_can( 'manage_woocommerce' );
	}

	public static function grant_company_role_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $allcaps;
		}

		$roles = (array) $user->roles;
		if ( array_intersect( self::COMPANY_ROLES, $roles ) ) {
			$allcaps[ self::CAP ] = true;
		}
		if ( array_intersect( array( 'administrator', 'shop_manager' ), $roles ) ) {
			$allcaps[ self::RETRY_CAP ] = true;
		}

		return $allcaps;
	}

	public static function allowed_statuses( WC_Order $order ) {
		$statuses = wc_get_order_statuses();
		$roles    = (array) wp_get_current_user()->roles;
		$current  = $order->get_status();
		if ( self::current_user_is_agent() ) {
			return array( 'wc-' . $current => wc_get_order_status_name( $current ) );
		}
		if ( array_intersect( array( 'administrator', 'acc_manager' ), $roles ) ) {
			return apply_filters( 'company_central_orders_allowed_statuses', $statuses, $order, wp_get_current_user() );
		}

		if ( in_array( 'digikala_admin', $roles, true ) ) {
			return array( 'wc-' . $current => wc_get_order_status_name( $current ) );
		}

		if ( in_array( 'logistics', $roles, true ) ) {
			$allowed = array( 'wc-' . $current => wc_get_order_status_name( $current ) );
			if ( 'printed-send' === $current && self::logistics_can_complete( $order ) && isset( $statuses['wc-completed'] ) ) {
				$allowed['wc-completed'] = $statuses['wc-completed'];
			}
			return $allowed;
		}

		if ( in_array( 'shop_manager', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			if ( 'refunded' === $current && 1942 === absint( get_user_meta( get_current_user_id(), '_company_source_user_id', true ) ) ) {
				return array( 'wc-refunded' => wc_get_order_status_name( 'refunded' ) );
			}
			$transitions = array(
				'processing'   => array( 'printed-send' ),
				'printed-send' => array( 'moalagh-send', 'completed' ),
				'moalagh-send' => array( 'printed-send' ),
			);
			$allowed = array( 'wc-' . $current => wc_get_order_status_name( $current ) );
			foreach ( $transitions[ $current ] ?? array() as $slug ) {
				if ( isset( $statuses[ 'wc-' . $slug ] ) ) $allowed[ 'wc-' . $slug ] = $statuses[ 'wc-' . $slug ];
			}
			return $allowed;
		}

		if ( in_array( 'customer_support', $roles, true ) ) {
			$transitions = array(
				'printed-send' => array( 'moalagh-send' ),
				'moalagh-send' => array( 'printed-send' ),
			);
			$allowed = array( 'wc-' . $current => wc_get_order_status_name( $current ) );
			foreach ( $transitions[ $current ] ?? array() as $slug ) {
				if ( isset( $statuses[ 'wc-' . $slug ] ) ) {
					$allowed[ 'wc-' . $slug ] = $statuses[ 'wc-' . $slug ];
				}
			}
			return $allowed;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			unset( $statuses['wc-refunded'], $statuses['wc-cancelled'] );
		}

		return apply_filters( 'company_central_orders_allowed_statuses', $statuses, $order, wp_get_current_user() );
	}

	public static function visible_statuses() {
		$all   = wc_get_order_statuses();
		$roles = (array) wp_get_current_user()->roles;
		if ( array_intersect( array( 'administrator', 'digikala_admin', 'shop_manager' ), $roles ) ) {
			return $all;
		}
		if ( in_array( 'logistics', $roles, true ) ) {
			return array_intersect_key( $all, array_flip( array( 'wc-processing', 'wc-printed-send', 'wc-moalagh-send', 'wc-completed' ) ) );
		}
		if ( in_array( 'customer_support', $roles, true ) ) {
			unset( $all['wc-refunded'] );
		}
		return $all;
	}

	public static function can_modify_orders( $order = null ) {
		$roles = (array) wp_get_current_user()->roles;
		if ( in_array( 'digikala_admin', $roles, true ) || self::current_user_is_agent() ) {
			return false;
		}
		if (
			$order instanceof WC_Order &&
			'refunded' === $order->get_status() &&
			in_array( 'shop_manager', $roles, true ) &&
			1942 === absint( get_user_meta( get_current_user_id(), '_company_source_user_id', true ) )
		) {
			return false;
		}
		return true;
	}

	public static function current_user_is_agent() {
		$user = wp_get_current_user();
		return $user instanceof WP_User
			&& $user->exists()
			&& in_array( 'seller', (array) $user->roles, true )
			&& ! in_array( 'administrator', (array) $user->roles, true );
	}

	public static function current_agent_login() {
		return self::current_user_is_agent() ? sanitize_user( wp_get_current_user()->user_login, false ) : '';
	}

	public static function order_visible_to_current_user( $order ) {
		if ( ! self::current_user_is_agent() ) {
			return true;
		}
		return $order instanceof WC_Order && self::order_belongs_to_agent_login( $order, self::current_agent_login() );
	}

	public static function order_belongs_to_agent_login( WC_Order $order, $login ) {
		$login = sanitize_user( $login, false );
		if ( ! $login ) {
			return false;
		}
		$user        = get_user_by( 'login', $login );
		$display_name = $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : '';
		$source_id   = $user instanceof WP_User ? absint( get_user_meta( $user->ID, '_company_source_user_id', true ) ) : 0;
		$source_store = $user instanceof WP_User ? sanitize_key( get_user_meta( $user->ID, '_company_source_store', true ) ) : '';
		$order_store = sanitize_key( $order->get_meta( '_company_source_store', true ) );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item_login = sanitize_user( $item->get_meta( '_company_assigned_seller_login', true ), false );
			$item_name  = sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) );
			$item_id    = absint( $item->get_meta( '_company_assigned_seller_id', true ) );
			if (
				( $item_login && 0 === strcasecmp( $item_login, $login ) ) ||
				( ! $item_login && $display_name && $item_name && 0 === strcasecmp( $item_name, $display_name ) ) ||
				( ! $item_login && $source_id && $source_store === $order_store && $source_id === $item_id )
			) {
				return true;
			}
		}
		return false;
	}

	public static function order_ids_for_agent_login( $login ) {
		$login = sanitize_user( $login, false );
		if ( ! $login ) {
			return array();
		}
		static $cache = array();
		if ( isset( $cache[ $login ] ) ) {
			return $cache[ $login ];
		}

		$user         = get_user_by( 'login', $login );
		$display_name = $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : '';
		$source_id    = $user instanceof WP_User ? absint( get_user_meta( $user->ID, '_company_source_user_id', true ) ) : 0;
		$source_store = $user instanceof WP_User ? sanitize_key( get_user_meta( $user->ID, '_company_source_store', true ) ) : '';
		global $wpdb;
		$order_items = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$is_hpos     = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$order_meta  = $is_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$order_id_column = $is_hpos ? 'order_id' : 'post_id';
		$sql = "SELECT DISTINCT oi.order_id
			FROM {$order_items} oi
			INNER JOIN {$item_meta} seller ON seller.order_item_id=oi.order_item_id
			LEFT JOIN {$order_meta} source_store ON source_store.{$order_id_column}=oi.order_id AND source_store.meta_key='_company_source_store'
			WHERE oi.order_item_type='line_item'
			AND ((seller.meta_key='_company_assigned_seller_login' AND seller.meta_value=%s)";
		$params = array( $login );
		if ( $display_name ) {
			$sql .= " OR (seller.meta_key='_company_assigned_seller_name' AND seller.meta_value=%s)";
			$params[] = $display_name;
		}
		if ( $source_id && $source_store ) {
			$sql .= " OR (seller.meta_key='_company_assigned_seller_id' AND seller.meta_value=%s AND source_store.meta_value=%s)";
			$params[] = (string) $source_id;
			$params[] = $source_store;
		}
		$sql .= ')';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
		$cache[ $login ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		return $cache[ $login ];
	}

	public static function order_has_unassigned_agent( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$assigned_seller = trim( sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) ) );
			$tamin_agent     = trim( sanitize_text_field( $item->get_meta( '_company_tamin_agent_name', true ) ) );
			if ( '' === $assigned_seller && '' === $tamin_agent ) {
				return true;
			}
		}
		return false;
	}

	public static function order_ids_with_unassigned_agent() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}

		global $wpdb;
		$order_items = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$sql = "SELECT DISTINCT oi.order_id
			FROM {$order_items} oi
			WHERE oi.order_item_type='line_item'
			AND NOT EXISTS (
				SELECT 1 FROM {$item_meta} agent_meta
				WHERE agent_meta.order_item_id=oi.order_item_id
				AND agent_meta.meta_key IN ('_company_assigned_seller_name','_company_tamin_agent_name')
				AND TRIM(agent_meta.meta_value)<>''
			)";
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $wpdb->get_col( $sql ) ) ) ) );
		return $ids;
	}

	public static function restrict_user_management_caps( $allcaps, $caps, $args, $user ) {
		if ( $user instanceof WP_User && ! in_array( 'administrator', (array) $user->roles, true ) ) {
			foreach ( array( 'delete_users', 'edit_users', 'promote_users', 'remove_users' ) as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	public static function restrict_user_meta_caps( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' ), true ) ) {
			$target_user_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
			if ( ! $target_user_id || $target_user_id === absint( $user_id ) ) {
				return $caps;
			}
			$user = get_userdata( $user_id );
			if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
				return array( 'do_not_allow' );
			}
		}
		return $caps;
	}

	private static function logistics_can_complete( WC_Order $order ) {
		$allowed = array( '32', '33', '43' );
		$blocked = array( '31', '40', '41' );
		$text    = '';
		foreach ( $order->get_shipping_methods() as $item ) {
			$instance = method_exists( $item, 'get_instance_id' ) ? (string) $item->get_instance_id() : '';
			if ( in_array( $instance, $blocked, true ) ) return false;
			if ( in_array( $instance, $allowed, true ) ) return true;
			$text .= ' ' . $item->get_method_id() . ' ' . $item->get_method_title() . ' ' . $item->get_name();
		}
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		if ( false !== strpos( $text, 'پیک' ) || false !== strpos( $text, 'موتوری' ) ) return false;
		return false !== strpos( $text, 'پست' ) || false !== strpos( $text, 'پیشتاز' ) || false !== strpos( $text, 'تیپاکس' ) || false !== strpos( $text, 'tipax' );
	}
}
