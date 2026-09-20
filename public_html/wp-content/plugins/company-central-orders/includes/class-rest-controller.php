<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_REST_Controller extends WP_REST_Controller {

	const NAMESPACE = 'company-central/v1';
	const CHANGE_TOKEN_OPTION = 'company_central_orders_change_token';

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'company_order_sync_central_order_changed', array( $this, 'bump_change_token' ) );
		add_action( 'woocommerce_update_order', array( $this, 'bump_change_token_for_order' ), 100, 1 );
		add_action( 'woocommerce_order_note_added', array( $this, 'bump_change_token_for_note' ), 100, 2 );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_change_token' ),
				'permission_callback' => array( $this, 'can_manage_orders' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'can_manage_orders' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/bulk-status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'bulk_update_status' ),
				'permission_callback' => array( $this, 'can_manage_orders' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => array( $this, 'can_manage_orders' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order_notes' ),
					'permission_callback' => array( $this, 'can_manage_orders' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => array( $this, 'can_manage_orders' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/tracking',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'submit_tracking' ),
				'permission_callback' => array( $this, 'can_manage_orders' ),
			)
		);
	}

	public function can_manage_orders() {
		return current_user_can( Company_Central_Orders_Access::CAP );
	}

	public function get_change_token() {
		return rest_ensure_response( array( 'token'=>(string) get_option( self::CHANGE_TOKEN_OPTION, 'initial' ) ) );
	}

	public function bump_change_token() {
		update_option( self::CHANGE_TOKEN_OPTION, wp_generate_uuid4(), false );
	}

	public function bump_change_token_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->get_meta( '_company_source_store', true ) ) {
			$this->bump_change_token();
		}
	}

	public function bump_change_token_for_note( $note_id, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_company_source_store', true ) ) {
			$this->bump_change_token();
		}
	}

	public function get_orders( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$requested_per_page = absint( $request->get_param( 'per_page' ) ) ?: 10;
		$per_page   = in_array( $requested_per_page, array( 10, 20, 30, 50, 100, 200 ), true ) ? $requested_per_page : 10;
		$status     = $this->key_values_from_request( $request->get_param( 'status' ) );
		$store      = sanitize_key( (string) $request->get_param( 'store' ) );
		$payment    = $this->key_values_from_request( $request->get_param( 'payment' ) );
		$shipping   = $this->shipping_values_from_request( $request->get_param( 'shipping' ) );
		$agent      = $this->agent_logins_from_request( $request->get_param( 'agent' ) );
		if ( Company_Central_Orders_Access::current_user_is_agent() ) {
			$agent = array( Company_Central_Orders_Access::current_agent_login() );
		}
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$search_type = sanitize_key( (string) $request->get_param( 'search_type' ) );
		if ( ! in_array( $search_type, array( 'city', 'order', 'name', 'mobile', 'sku' ), true ) ) {
			$search_type = 'order';
		}
		$date_from  = $this->sanitize_date( $request->get_param( 'date_from' ) );
		$date_to    = $this->sanitize_date( $request->get_param( 'date_to' ) );
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_company_source_store',
				'compare' => 'EXISTS',
			),
		);

		if ( $store ) {
			$meta_query[] = array(
				'key'     => '_company_source_store',
				'value'   => $store,
				'compare' => '=',
			);
		}
		$args = array(
			'limit'      => $per_page,
			'paged'      => $page,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'type'       => 'shop_order',
			'meta_query' => $meta_query,
		);

		$visible_statuses = Company_Central_Orders_Access::visible_statuses();
		$valid_statuses = array_map(
			static function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( $visible_statuses )
		);
		$status = array_values( array_intersect( $status, $valid_statuses ) );
		if ( $status ) {
			$args['status'] = $status;
		} elseif ( $valid_statuses && count( $valid_statuses ) < count( wc_get_order_statuses() ) ) {
			$args['status'] = $valid_statuses;
		}
		$agent_order_ids = $agent ? $this->order_ids_for_agent_logins( $agent ) : null;
		if ( is_array( $agent_order_ids ) ) {
			if ( ! $agent_order_ids ) {
				return $this->empty_orders_response( $page, $per_page, $this->shipping_options_for_filters( null, $status, $valid_statuses, $store, $payment, $date_from, $date_to ) );
			}
			// post__in is understood by both the legacy CPT store and HPOS. The
			// WC_Order_Query "include" argument is not supported and is ignored.
			$args['post__in'] = $agent_order_ids;
		}
		$date_query = $this->date_created_query( $date_from, $date_to );
		if ( $date_query ) {
			$args['date_created'] = $date_query;
		}
		$search_ids       = '' !== $search ? $this->targeted_search_ids( $search_type, $search ) : null;
		if ( is_array( $search_ids ) && is_array( $agent_order_ids ) ) {
			$search_ids = array_values( array_intersect( $search_ids, $agent_order_ids ) );
		}
		$shipping_options = $this->shipping_options_for_filters( $search_ids, $status, $valid_statuses, $store, $payment, $date_from, $date_to );
		if ( is_array( $search_ids ) ) {
			if ( ! $search_ids ) {
				return $this->empty_orders_response( $page, $per_page, $shipping_options );
			}
			return $this->searched_orders_response( $search_ids, $search_type, $search, $page, $per_page, $status, $valid_statuses, $store, $payment, $shipping, $agent, $date_from, $date_to, $shipping_options );
		}
		if ( $payment ) {
			$payment_ids = $this->payment_filter_ids( $args, $payment );
			if ( ! $payment_ids ) {
				return $this->empty_orders_response( $page, $per_page, $shipping_options );
			}
			$args['post__in'] = $payment_ids;
		}
		if ( $shipping ) {
			$shipping_ids = $this->shipping_filter_ids( $args, $shipping );
			if ( ! $shipping_ids ) {
				return $this->empty_orders_response( $page, $per_page, $shipping_options );
			}
			$args['post__in'] = $shipping_ids;
		}

		$results = wc_get_orders( $args );
		$orders  = array();
		foreach ( $results->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$orders[] = $this->prepare_order( $order );
			}
		}

		return rest_ensure_response(
			array(
				'orders'     => $orders,
				'total'      => (int) $results->total,
				'totalPages' => (int) $results->max_num_pages,
				'page'       => $page,
				'perPage'    => $per_page,
				'shippingOptions' => $shipping_options,
				'changeToken' => (string) get_option( self::CHANGE_TOKEN_OPTION, 'initial' ),
			)
		);
	}

	private function key_values_from_request( $value ) {
		$values = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_unique( array_filter( array_map( static function( $item ) {
			return sanitize_key( (string) $item );
		}, $values ) ) ) );
	}

	private function agent_logins_from_request( $value ) {
		$values = is_array( $value ) ? $value : explode( ',', (string) $value );
		$logins = array();
		foreach ( $values as $item ) {
			$login = $this->agent_login_from_value( $item );
			if ( '' !== $login ) {
				$logins[] = $login;
			}
		}
		return array_values( array_unique( $logins ) );
	}

	private function agent_login_from_value( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( 'unassigned' === $value ) {
			return 'unassigned';
		}

		// New clients send the local Central user ID, so Unicode usernames do
		// not have to be serialized into a filter value. Keep login support for
		// requests saved by older browser sessions.
		if ( ctype_digit( $value ) ) {
			$user = get_userdata( absint( $value ) );
			return $user instanceof WP_User && in_array( 'seller', (array) $user->roles, true )
				? sanitize_user( $user->user_login, false )
				: '';
		}

		return sanitize_user( $value, false );
	}

	private function order_ids_for_agent_logins( $agents ) {
		$matched = array();
		foreach ( (array) $agents as $agent ) {
			$ids = 'unassigned' === $agent
				? Company_Central_Orders_Access::order_ids_with_unassigned_agent()
				: Company_Central_Orders_Access::order_ids_for_agent_login( $agent );
			$matched = array_merge( $matched, (array) $ids );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $matched ) ) ) );
	}

	private function payment_filter_ids( array $base_args, $payments ) {
		$matched = array();
		foreach ( (array) $payments as $payment ) {
			$query_args = $base_args;
			$query_args['limit']          = -1;
			$query_args['return']         = 'ids';
			$query_args['paginate']       = false;
			$query_args['payment_method'] = sanitize_key( $payment );
			unset( $query_args['paged'], $query_args['orderby'], $query_args['order'] );
			$matched = array_merge( $matched, (array) wc_get_orders( $query_args ) );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $matched ) ) ) );
	}

	private function targeted_search_ids( $type, $term ) {
		$normalized_term = $this->normalize_search_text( $term );
		return '' === $normalized_term ? array() : $this->database_search_ids( $type, $term, $normalized_term );
	}

	private function all_synced_order_ids() {
		return wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'meta_query' => array(
					array(
						'key'     => '_company_source_store',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	private function shipping_options_for_filters( $candidate_ids, $status, $valid_statuses, $store, $payment, $date_from, $date_to ) {
		$options = array();
		foreach ( $this->shipping_groups() as $group ) {
			$options[] = array( 'value'=>$group['value'], 'label'=>$group['label'] );
		}
		return $options;
	}

	private function shipping_groups() {
		return array(
			array( 'needles'=>array( 'تیپاکس', 'tipax', 'ماهکس', 'mahex' ), 'value'=>'group:tipax-mahex', 'label'=>'تیپاکس / ماهکس' ),
			array( 'needles'=>array( 'پیک', 'موتوری', 'courier' ), 'value'=>'group:courier', 'label'=>'پیک موتوری' ),
			array( 'needles'=>array( 'پست', 'پیشتاز', 'post', 'pishtaz' ), 'value'=>'group:post', 'label'=>'پست' ),
		);
	}

	private function shipping_values_from_request( $value ) {
		$values = is_array( $value ) ? $value : explode( ',', (string) $value );
		$valid  = wp_list_pluck( $this->shipping_groups(), 'value' );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values ), static function( $item ) use ( $valid ) {
			return in_array( $item, $valid, true );
		} ) ) );
	}

	private function shipping_meta_clause( $value ) {
		foreach ( $this->shipping_groups() as $group ) {
			if ( $group['value'] !== $value ) {
				continue;
			}
			$clause = array( 'relation'=>'OR' );
			foreach ( $group['needles'] as $needle ) {
				$clause[] = array( 'key'=>'_company_shipping_method_filter', 'value'=>$needle, 'compare'=>'LIKE' );
			}
			return $clause;
		}
		return array();
	}

	private function shipping_filter_ids( array $base_args, $shipping ) {
		$shipping = (array) $shipping;
		$candidate_args = $base_args;
		$candidate_args['limit']    = -1;
		$candidate_args['return']   = 'ids';
		$candidate_args['paginate'] = false;
		unset( $candidate_args['paged'], $candidate_args['orderby'], $candidate_args['order'] );
		$candidate_ids = array_values( array_filter( array_map( 'absint', (array) wc_get_orders( $candidate_args ) ) ) );
		if ( ! $candidate_ids ) {
			return array();
		}

		global $wpdb;
		$is_hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table   = $is_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_col  = $is_hpos ? 'order_id' : 'post_id';
		$matched = array();
		foreach ( array_chunk( $candidate_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql = "SELECT {$id_col} AS order_id, meta_value FROM {$table} WHERE meta_key='_company_shipping_method_filter' AND {$id_col} IN ({$placeholders})";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$chunk ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$value = $this->shipping_group_from_title( $row['meta_value'] );
				if ( in_array( $value, $shipping, true ) ) {
					$matched[] = absint( $row['order_id'] );
				}
			}
		}
		return array_values( array_unique( array_filter( $matched ) ) );
	}

	private function shipping_group( WC_Order $order ) {
		$title = sanitize_text_field( $order->get_meta( '_company_shipping_method_filter', true ) ?: $order->get_shipping_method() );
		$value = $this->shipping_group_from_title( $title );
		foreach ( $this->shipping_groups() as $group ) {
			if ( $group['value'] === $value ) {
				return array( 'value'=>$value, 'label'=>$group['label'] );
			}
		}
		return array( 'value'=>'', 'label'=>'' );
	}

	private function shipping_group_from_title( $title ) {
		$text  = $this->normalize_search_text( $title );
		foreach ( $this->shipping_groups() as $group ) {
			foreach ( $group['needles'] as $needle ) {
				if ( false !== strpos( $text, $this->normalize_search_text( $needle ) ) ) {
					return $group['value'];
				}
			}
		}
		return '';
	}

	private function empty_orders_response( $page, $per_page, $shipping_options = array() ) {
		return rest_ensure_response( array( 'orders'=>array(), 'total'=>0, 'totalPages'=>0, 'page'=>$page, 'perPage'=>$per_page, 'shippingOptions'=>$shipping_options, 'changeToken'=>(string) get_option( self::CHANGE_TOKEN_OPTION, 'initial' ) ) );
	}

	private function searched_orders_response( $ids, $search_type, $search_term, $page, $per_page, $status, $valid_statuses, $store, $payment, $shipping, $agent, $date_from, $date_to, $shipping_options ) {
		$orders = array();
		foreach ( (array) $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if (
				! $order instanceof WC_Order
				|| ! $this->order_matches_search( $order, $search_type, $search_term )
				|| ! $this->order_matches_filters( $order, $status, $valid_statuses, $store, $payment, $shipping, $agent, $date_from, $date_to )
			) {
				continue;
			}
			$orders[] = $order;
		}

		usort(
			$orders,
			static function( WC_Order $left, WC_Order $right ) {
				$left_date  = $left->get_date_created();
				$right_date = $right->get_date_created();
				return ( $right_date ? $right_date->getTimestamp() : 0 ) <=> ( $left_date ? $left_date->getTimestamp() : 0 );
			}
		);

		$total       = count( $orders );
		$total_pages = $total ? (int) ceil( $total / $per_page ) : 0;
		$orders      = array_slice( $orders, ( $page - 1 ) * $per_page, $per_page );

		return rest_ensure_response(
			array(
				'orders'     => array_map( array( $this, 'prepare_order' ), $orders ),
				'total'      => $total,
				'totalPages' => $total_pages,
				'page'       => $page,
				'perPage'    => $per_page,
				'shippingOptions' => $shipping_options,
				'changeToken' => (string) get_option( self::CHANGE_TOKEN_OPTION, 'initial' ),
			)
		);
	}

	private function order_matches_search( WC_Order $order, $type, $term ) {
		$needle = $this->normalize_search_text( $term );
		if ( '' === $needle ) {
			return false;
		}
		if ( 'mobile' === $type && strlen( $needle ) > 7 ) {
			$needle = substr( $needle, -7 );
		}

		switch ( $type ) {
			case 'city':
				$value = $order->get_billing_city() . ' ' . $order->get_shipping_city();
				break;
			case 'name':
				$value = implode(
					' ',
					array(
						$order->get_billing_first_name(),
						$order->get_billing_last_name(),
						$order->get_shipping_first_name(),
						$order->get_shipping_last_name(),
					)
				);
				break;
			case 'mobile':
				$value = $order->get_billing_phone() . ' ' . $order->get_shipping_phone();
				break;
			case 'sku':
				$skus = array();
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$skus[] = $item->get_meta( '_company_source_sku', true );
				}
				$value = implode( ' ', $skus );
				break;
			case 'order':
			default:
				$value = implode(
					' ',
					array(
						$order->get_id(),
						$order->get_order_number(),
						$order->get_meta( '_company_source_order_id', true ),
						$order->get_meta( '_company_source_order_number', true ),
					)
				);
				break;
		}

		return false !== strpos( $this->normalize_search_text( $value ), $needle );
	}

	private function order_matches_filters( WC_Order $order, $status, $valid_statuses, $store, $payment, $shipping, $agent, $date_from, $date_to ) {
		$source_store = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		if ( ! $source_store || ( $store && $source_store !== $store ) ) {
			return false;
		}

		$order_status = sanitize_key( $order->get_status() );
		if ( $status ) {
			if ( ! in_array( $order_status, (array) $status, true ) ) {
				return false;
			}
		} elseif ( $valid_statuses && count( $valid_statuses ) < count( wc_get_order_statuses() ) && ! in_array( $order_status, $valid_statuses, true ) ) {
			return false;
		}

		if ( $payment && ! in_array( sanitize_key( $order->get_payment_method() ), (array) $payment, true ) ) {
			return false;
		}
		$order_shipping = $this->shipping_group( $order );
		if ( $shipping && ! in_array( $order_shipping['value'], (array) $shipping, true ) ) {
			return false;
		}
		if ( $agent ) {
			$agent_matches = false;
			foreach ( (array) $agent as $agent_login ) {
				$agent_matches = 'unassigned' === $agent_login
					? Company_Central_Orders_Access::order_has_unassigned_agent( $order )
					: Company_Central_Orders_Access::order_belongs_to_agent_login( $order, $agent_login );
				if ( $agent_matches ) {
					break;
				}
			}
			if ( ! $agent_matches ) {
				return false;
			}
		}

		$created = $order->get_date_created();
		if ( ( $date_from || $date_to ) && ! $created ) {
			return false;
		}
		$created_date = $created ? $created->date_i18n( 'Y-m-d' ) : '';
		if ( $date_from && $created_date < $date_from ) {
			return false;
		}
		if ( $date_to && $created_date > $date_to ) {
			return false;
		}
		return true;
	}

	private function database_search_ids( $type, $term, $normalized_term ) {
		global $wpdb;
		$is_hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$term = $normalized_term;
		if ( 'mobile' === $type ) {
			$term = strlen( $normalized_term ) > 7 ? substr( $normalized_term, -7 ) : $normalized_term;
		}
		$like = '%' . $wpdb->esc_like( (string) $term ) . '%';

		if ( 'sku' === $type ) {
			$order_items = $wpdb->prefix . 'woocommerce_order_items';
			$item_meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
			$sku_where   = "REPLACE(REPLACE(REPLACE(REPLACE(im.meta_value, ' ', ''), '-', ''), 'ي', 'ی'), 'ك', 'ک') LIKE %s";
			if ( $is_hpos ) {
				$orders = $wpdb->prefix . 'wc_orders';
				$meta   = $wpdb->prefix . 'wc_orders_meta';
				$sql    = "SELECT DISTINCT oi.order_id FROM {$order_items} oi
					INNER JOIN {$item_meta} im ON im.order_item_id=oi.order_item_id AND im.meta_key='_company_source_sku'
					INNER JOIN {$orders} o ON o.id=oi.order_id AND o.type='shop_order'
					INNER JOIN {$meta} synced ON synced.order_id=o.id AND synced.meta_key='_company_source_store'
					WHERE oi.order_item_type='line_item' AND {$sku_where}";
			} else {
				$posts    = $wpdb->posts;
				$postmeta = $wpdb->postmeta;
				$sql      = "SELECT DISTINCT oi.order_id FROM {$order_items} oi
					INNER JOIN {$item_meta} im ON im.order_item_id=oi.order_item_id AND im.meta_key='_company_source_sku'
					INNER JOIN {$posts} p ON p.ID=oi.order_id AND p.post_type='shop_order'
					INNER JOIN {$postmeta} synced ON synced.post_id=p.ID AND synced.meta_key='_company_source_store'
					WHERE oi.order_item_type='line_item' AND {$sku_where}";
			}
			return array_values( array_filter( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $like ) ) ) ) );
		}

		if ( $is_hpos ) {
			$orders    = $wpdb->prefix . 'wc_orders';
			$addresses = $wpdb->prefix . 'wc_order_addresses';
			$meta      = $wpdb->prefix . 'wc_orders_meta';
			$expressions = array(
				'city'   => "REPLACE(REPLACE(REPLACE(CONCAT_WS('', billing.city, shipping.city), ' ', ''), 'ي', 'ی'), 'ك', 'ک') LIKE %s",
				'name'   => "REPLACE(REPLACE(REPLACE(CONCAT_WS('', billing.first_name, billing.last_name, shipping.first_name, shipping.last_name), ' ', ''), 'ي', 'ی'), 'ك', 'ک') LIKE %s",
				'mobile' => "REPLACE(REPLACE(REPLACE(REPLACE(CONCAT_WS('', billing.phone, shipping.phone), ' ', ''), '-', ''), '(', ''), ')', '') LIKE %s",
				'order'  => "(CAST(o.id AS CHAR) LIKE %s OR source.meta_value LIKE %s)",
			);
			$where = $expressions[ $type ];
			$sql = "SELECT DISTINCT o.id FROM {$orders} o
				INNER JOIN {$meta} synced ON synced.order_id=o.id AND synced.meta_key='_company_source_store'
				LEFT JOIN {$addresses} billing ON billing.order_id=o.id AND billing.address_type='billing'
				LEFT JOIN {$addresses} shipping ON shipping.order_id=o.id AND shipping.address_type='shipping'
				LEFT JOIN {$meta} source ON source.order_id=o.id AND source.meta_key IN ('_company_source_order_id','_company_source_order_number')
				WHERE o.type='shop_order' AND {$where}";
			$prepared = 'order' === $type ? $wpdb->prepare( $sql, $like, $like ) : $wpdb->prepare( $sql, $like );
		} else {
			$postmeta = $wpdb->postmeta;
			$posts    = $wpdb->posts;
			$expressions = array(
				'city'   => "REPLACE(REPLACE(REPLACE(CONCAT_WS('', bcity.meta_value, scity.meta_value), ' ', ''), 'ي', 'ی'), 'ك', 'ک') LIKE %s",
				'name'   => "REPLACE(REPLACE(REPLACE(CONCAT_WS('', bfirst.meta_value, blast.meta_value, sfirst.meta_value, slast.meta_value), ' ', ''), 'ي', 'ی'), 'ك', 'ک') LIKE %s",
				'mobile' => "REPLACE(REPLACE(REPLACE(REPLACE(CONCAT_WS('', bphone.meta_value, sphone.meta_value), ' ', ''), '-', ''), '(', ''), ')', '') LIKE %s",
				'order'  => "(CAST(p.ID AS CHAR) LIKE %s OR source.meta_value LIKE %s)",
			);
			$where = $expressions[ $type ];
			$sql = "SELECT DISTINCT p.ID FROM {$posts} p
				INNER JOIN {$postmeta} synced ON synced.post_id=p.ID AND synced.meta_key='_company_source_store'
				LEFT JOIN {$postmeta} bcity ON bcity.post_id=p.ID AND bcity.meta_key='_billing_city'
				LEFT JOIN {$postmeta} scity ON scity.post_id=p.ID AND scity.meta_key='_shipping_city'
				LEFT JOIN {$postmeta} bfirst ON bfirst.post_id=p.ID AND bfirst.meta_key='_billing_first_name'
				LEFT JOIN {$postmeta} blast ON blast.post_id=p.ID AND blast.meta_key='_billing_last_name'
				LEFT JOIN {$postmeta} sfirst ON sfirst.post_id=p.ID AND sfirst.meta_key='_shipping_first_name'
				LEFT JOIN {$postmeta} slast ON slast.post_id=p.ID AND slast.meta_key='_shipping_last_name'
				LEFT JOIN {$postmeta} bphone ON bphone.post_id=p.ID AND bphone.meta_key='_billing_phone'
				LEFT JOIN {$postmeta} sphone ON sphone.post_id=p.ID AND sphone.meta_key='_shipping_phone'
				LEFT JOIN {$postmeta} source ON source.post_id=p.ID AND source.meta_key IN ('_company_source_order_id','_company_source_order_number')
				WHERE p.post_type='shop_order' AND {$where}";
			$prepared = 'order' === $type ? $wpdb->prepare( $sql, $like, $like ) : $wpdb->prepare( $sql, $like );
		}

		return array_values( array_filter( array_map( 'absint', (array) $wpdb->get_col( $prepared ) ) ) );
	}

	private function normalize_search_text( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = strtr( $value, array( 'ي'=>'ی', 'ك'=>'ک', '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '+98'=>'0' ) );
		$value = preg_replace( '/^0098/', '0', $value );
		$value = preg_replace( '/[\s\-()]+/u', '', $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	public function update_status( WP_REST_Request $request ) {
		$order = $this->get_synced_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// A panel request must finish after Central has persisted the status.
		// Remote store delivery is queued by Company Order Sync so a slow store
		// cannot make the operator request time out after only a partial change.
		add_filter( 'company_order_sync_defer_central_status_dispatch', '__return_true', PHP_INT_MAX, 4 );
		try {
			$result = $this->apply_status( $order, sanitize_key( (string) $request->get_param( 'status' ) ), 'پنل Ant Design' );
		} finally {
			remove_filter( 'company_order_sync_defer_central_status_dispatch', '__return_true', PHP_INT_MAX );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_order( wc_get_order( $order->get_id() ) ?: $order ) );
	}

	public function bulk_update_status( WP_REST_Request $request ) {
		$params     = $request->get_json_params();
		$order_ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['order_ids'] ?? array() ) ) ) ) );
		$new_status = sanitize_key( (string) ( $params['status'] ?? '' ) );
		if ( ! $order_ids || count( $order_ids ) > 50 || ! $new_status ) {
			return new WP_Error( 'cco_invalid_bulk_status', 'بین ۱ تا ۵۰ سفارش و یک وضعیت معتبر انتخاب کنید.', array( 'status'=>422 ) );
		}

		$updated = array();
		$failed  = array();
		// Never perform one remote HTTP request per order inside the bulk REST
		// request. Persist every local status first, then let Sync dispatch the
		// pending statuses asynchronously. This makes a 50-order bulk operation
		// atomic from the operator's point of view instead of timing out halfway.
		add_filter( 'company_order_sync_defer_central_status_dispatch', '__return_true', PHP_INT_MAX, 4 );
		try {
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) ) {
					$failed[] = array( 'orderId'=>$order_id, 'message'=>'سفارش همگام‌شده پیدا نشد.' );
					continue;
				}
				$result = $this->apply_status( $order, $new_status, 'تغییر وضعیت گروهی پنل Ant Design' );
				if ( is_wp_error( $result ) ) {
					$failed[] = array( 'orderId'=>$order_id, 'message'=>$result->get_error_message() );
					continue;
				}
				$updated[] = $this->prepare_order( wc_get_order( $order->get_id() ) ?: $order );
			}
		} finally {
			remove_filter( 'company_order_sync_defer_central_status_dispatch', '__return_true', PHP_INT_MAX );
		}

		return rest_ensure_response(
			array(
				'updated'      => $updated,
				'updatedCount' => count( $updated ),
				'failed'       => $failed,
				'failedCount'  => count( $failed ),
			)
		);
	}

	private function apply_status( WC_Order $order, $new_status, $channel ) {
		if ( ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			return new WP_Error( 'cco_read_only_role', 'این سفارش برای نقش شما فقط قابل مشاهده است.', array( 'status'=>403 ) );
		}
		$allowed    = array_map(
			static function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( Company_Central_Orders_Access::allowed_statuses( $order ) )
		);
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return new WP_Error( 'cco_forbidden_status', 'این تغییر وضعیت برای نقش شما مجاز نیست.', array( 'status' => 403 ) );
		}

		if ( $order->get_status() !== $new_status ) {
			$user = wp_get_current_user();
			$order->update_status( $new_status, sprintf( 'وضعیت از %1$s توسط %2$s تغییر کرد.', sanitize_text_field( $channel ), $user->display_name ?: $user->user_login ), true );
			$verified = wc_get_order( $order->get_id() );
			if ( ! $verified instanceof WC_Order || $verified->get_status() !== $new_status ) {
				return new WP_Error( 'cco_status_not_saved', 'وضعیت انتخاب‌شده در Central ذخیره نشد.', array( 'status'=>409 ) );
			}
		}
		return true;
	}

	public function add_note( WP_REST_Request $request ) {
		$order = $this->get_synced_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			return new WP_Error( 'cco_read_only_role', 'این سفارش برای نقش شما فقط قابل مشاهده است.', array( 'status'=>403 ) );
		}
		$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		if ( '' === $note || function_exists( 'mb_strlen' ) && mb_strlen( $note ) > 2000 ) {
			return new WP_Error( 'cco_invalid_note', 'متن یادداشت معتبر نیست.', array( 'status' => 422 ) );
		}
		$user = wp_get_current_user();
		$note_id = $order->add_order_note( sprintf( '[یادداشت داخلی توسط %s] %s', $user->display_name ?: $user->user_login, $note ), false, false );
		if ( $note_id ) {
			do_action( 'company_order_sync_queue_note', $order->get_id(), $note_id );
		}
		$order = wc_get_order( $order->get_id() );
		return rest_ensure_response( array( 'order'=>$this->prepare_order( $order ), 'notes'=>$this->prepare_notes( $order ) ) );
	}

	public function get_order_notes( WP_REST_Request $request ) {
		$order = $this->get_synced_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		return rest_ensure_response( array( 'notes'=>$this->prepare_notes( $order ) ) );
	}

	public function submit_tracking( WP_REST_Request $request ) {
		$order = $this->get_synced_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			return new WP_Error( 'cco_read_only_role', 'این سفارش برای نقش شما فقط قابل مشاهده است.', array( 'status'=>403 ) );
		}
		$code     = trim( sanitize_text_field( (string) $request->get_param( 'code' ) ) );
		$provider = esc_url_raw( (string) $request->get_param( 'provider' ) );
		$allowed  = array(
			'https://tracking.post.ir/',
			'https://tipaxco.com/tracking',
			'https://mahex.com/tracking',
		);
		if ( '' === $code || strlen( $code ) > 200 || ! in_array( $provider, $allowed, true ) ) {
			return new WP_Error( 'cco_invalid_tracking', 'کد رهگیری یا ارائه‌دهنده معتبر نیست.', array( 'status' => 422 ) );
		}
		if ( ! has_action( 'company_order_sync_queue_tracking' ) ) {
			return new WP_Error( 'cco_sync_unavailable', 'نسخه سازگار Company Order Sync فعال نیست.', array( 'status' => 503 ) );
		}

		$order->update_meta_data( '_company_tracking_code', $code );
		$order->update_meta_data( '_company_tracking_provider_url', $provider );
		$order->update_meta_data( '_company_tracking_sync_status', 'pending' );
		$order->delete_meta_data( '_company_tracking_last_error' );
		$order->save_meta_data();
		do_action( 'company_order_sync_queue_tracking', $order->get_id(), $code, $provider );

		// The sync plugin performs the first delivery attempt synchronously. Reload
		// so the API reports its actual result instead of the pre-dispatch state.
		$order = wc_get_order( $order->get_id() ) ?: $order;
		return rest_ensure_response( $this->prepare_order( $order ) );
	}

	private function get_synced_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) ) {
			return new WP_Error( 'cco_order_not_found', 'سفارش همگام‌شده پیدا نشد.', array( 'status' => 404 ) );
		}
		return $order;
	}

	private function prepare_order( WC_Order $order ) {
		$source      = $this->source_data( $order );
		$items       = array();
		$currency    = $order->get_currency();
		$format_money = function( $amount ) use ( $currency ) {
			return $this->plain_text( wc_price( $amount, array( 'currency' => $currency ) ) );
		};

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$quantity = max( 1, (int) $item->get_quantity() );
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();
			$assigned_seller = sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) );
			$items[]  = array(
				'id'          => $item->get_id(),
				'name'        => $item->get_name(),
				'sku'         => sanitize_text_field( $item->get_meta( '_company_source_sku', true ) ) ?: '—',
				'quantity'    => (int) $item->get_quantity(),
				'unitPrice'   => $format_money( $subtotal / $quantity ),
				'discount'    => $format_money( max( 0, $subtotal - $total ) ),
				'total'       => $format_money( $total + (float) $item->get_total_tax() ),
				'assignedSeller' => $assigned_seller,
				'agent'       => $assigned_seller ?: sanitize_text_field( $item->get_meta( '_company_tamin_agent_name', true ) ),
				'supplier'    => $assigned_seller ? '' : sanitize_text_field( $item->get_meta( '_company_tamin_supplier_name', true ) ),
				'taminStatus' => sanitize_key( $item->get_meta( '_company_tamin_status', true ) ),
				'needId'      => sanitize_text_field( $item->get_meta( '_company_tamin_need_id', true ) ),
			);
		}

		$allowed_statuses = array();
		foreach ( Company_Central_Orders_Access::allowed_statuses( $order ) as $key => $label ) {
			$allowed_statuses[] = array(
				'value' => str_replace( 'wc-', '', $key ),
				'label' => wp_strip_all_tags( $label ),
			);
		}

		return array(
			'id'              => $order->get_id(),
			'number'          => (string) $order->get_order_number(),
			'source'          => $source,
			'createdAt'       => Company_Central_Orders_Jalali_Date::format_datetime( $order->get_date_created() ),
			'deliveryAt'      => Company_Central_Orders_Delivery_Data::display( $order ),
			'deliveryDate'    => Company_Central_Orders_Delivery_Data::date_display( $order ),
			'deliverySlot'    => Company_Central_Orders_Delivery_Data::time_slot_display( $order ),
			'status'          => $order->get_status(),
			'statusLabel'     => wc_get_order_status_name( $order->get_status() ),
			'allowedStatuses' => $allowed_statuses,
			'canModify'       => Company_Central_Orders_Access::can_modify_orders( $order ),
			'syncStatus'      => sanitize_key( $order->get_meta( '_company_sync_status', true ) ) ?: 'pending',
			'customer'        => array(
				'name'    => trim( $order->get_formatted_billing_full_name() ) ?: 'بدون نام',
				'phone'   => $order->get_billing_phone() ?: '—',
				'email'   => $order->get_billing_email() ?: '—',
				'address' => $this->delivery_address( $order ),
			),
			'payment'         => array(
				'method'      => $order->get_payment_method_title() ?: 'تعیین نشده',
				'transaction' => $order->get_transaction_id() ?: '—',
			),
			'shipping'        => array(
				'method' => $order->get_shipping_method() ?: 'تعیین نشده',
				'filter' => $this->shipping_group( $order )['value'],
				'cost'   => $format_money( $order->get_shipping_total() ),
			),
			'discount'        => $format_money( $order->get_discount_total() ),
			'hasDiscount'     => (float) $order->get_discount_total() > 0,
			'total'           => $this->plain_text( $order->get_formatted_order_total() ),
			'customerNote'    => trim( (string) $order->get_customer_note() ),
			'items'           => $items,
			'notes'           => array(),
			'tracking'        => array(
				'code'     => sanitize_text_field( $order->get_meta( '_company_tracking_code', true ) ),
				'provider' => esc_url_raw( $order->get_meta( '_company_tracking_provider_url', true ) ) ?: 'https://tracking.post.ir/',
				'sync'     => sanitize_key( $order->get_meta( '_company_tracking_sync_status', true ) ),
				'error'    => sanitize_text_field( $order->get_meta( '_company_tracking_last_error', true ) ),
			),
			'printUrls'       => array(
				'invoice'  => $this->print_url( $order, 'invoice' ),
				'label'    => $this->print_url( $order, 'label' ),
				'delivery' => $this->print_url( $order, 'delivery' ),
			),
		);
	}

	private function prepare_notes( WC_Order $order ) {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 0,
				'orderby'  => 'date_created_gmt',
				'order'    => 'DESC',
			)
		);
		return array_map(
			static function( $note ) {
				return array(
					'id'      => $note->id,
					'content' => wp_strip_all_tags( $note->content ),
					'date'    => Company_Central_Orders_Jalali_Date::format_datetime( $note->date_created ),
				);
			},
			$notes
		);
	}

	private function source_data( WC_Order $order ) {
		$store_id = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$stores   = apply_filters( 'company_order_sync_registered_stores', array() );
		return array(
			'id'     => $store_id,
			'number' => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: absint( $order->get_meta( '_company_source_order_id', true ) ),
			'url'    => esc_url_raw( $order->get_meta( '_company_source_url', true ) ),
			'label'  => isset( $stores[ $store_id ] ) ? $stores[ $store_id ] : $store_id,
		);
	}

	private function print_url( WC_Order $order, $document ) {
		$url = add_query_arg(
			array(
				'action'   => 'company_orders_print',
				'order_id' => $order->get_id(),
				'document' => $document,
			),
			admin_url( 'admin-post.php' )
		);
		return add_query_arg( '_cco_nonce', wp_create_nonce( 'company_orders_print_' . $order->get_id() . '_' . $document ), $url );
	}

	private function plain_text( $value, $preserve_breaks = false ) {
		$value = (string) $value;
		if ( $preserve_breaks ) {
			$value = preg_replace( '/<br\s*\/?\s*>/i', '، ', $value );
		}
		$value   = wp_strip_all_tags( $value, true );
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, $charset );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	private function delivery_address( WC_Order $order ) {
		$type    = $order->get_shipping_address_1() ? 'shipping' : 'billing';
		$address = $order->get_address( $type );
		foreach ( array( 'first_name', 'last_name', 'company', 'email', 'phone' ) as $field ) {
			unset( $address[ $field ] );
		}
		$formatted = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_formatted_address( $address ) : '';
		return $this->plain_text( $formatted, true ) ?: '—';
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return '';
		}
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
	}

	private function date_created_query( $date_from, $date_to ) {
		if ( ! $date_from && ! $date_to ) {
			return '';
		}
		$timezone = wp_timezone();
		$from     = $date_from ? new DateTimeImmutable( $date_from . ' 00:00:00', $timezone ) : null;
		$to       = $date_to ? new DateTimeImmutable( $date_to . ' 23:59:59', $timezone ) : null;
		if ( $from && $to ) {
			return $from->getTimestamp() . '...' . $to->getTimestamp();
		}
		return $from ? '>=' . $from->getTimestamp() : '<=' . $to->getTimestamp();
	}
}
