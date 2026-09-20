<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Product_Seller {

	const ROLE              = 'seller';
	const PRODUCT_META      = '_company_product_seller_id';
	const ITEM_ID_META      = '_company_assigned_seller_id';
	const ITEM_NAME_META    = '_company_assigned_seller_name';
	const ITEM_LOGIN_META   = '_company_assigned_seller_login';
	const ORDER_COLUMN      = 'company_product_sellers';
	const PRODUCT_COLUMN    = 'company_product_seller';
	const FILTER_QUERY_VAR  = 'company_agent';
	const FILTER_UNASSIGNED = 'unassigned';
	const BULK_FIELD        = '_company_bulk_product_seller_id';
	const PRIVILEGED_LOGIN  = 'armines1996';
	const AUTHOR_BACKFILL_HOOK = 'company_order_sync_backfill_product_sellers';
	const AUTHOR_BACKFILL_OPTION = 'company_order_sync_product_seller_backfill_version';

	public function hooks() {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		add_action( 'woocommerce_product_options_sold_individually', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ), 20, 1 );
		add_action( 'woocommerce_product_bulk_edit_end', array( $this, 'render_bulk_edit_field' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_bulk_edit_field' ), 20, 1 );
		add_action( 'wp_after_insert_post', array( $this, 'assign_new_product_author' ), 30, 4 );
		add_action( 'dm_dokan_product_duplicated_for_vendor', array( $this, 'assign_digimaster_product' ), 30, 4 );
		add_action( 'dm_dokan_spmv_attached_vendor', array( $this, 'assign_digimaster_product' ), 30, 4 );
		add_action( self::AUTHOR_BACKFILL_HOOK, array( $this, 'backfill_product_authors' ) );
		add_action( 'init', array( $this, 'ensure_author_backfill' ), 40 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture_checkout_item' ), 20, 4 );
		add_action( 'woocommerce_new_order_item', array( $this, 'capture_new_order_item' ), 20, 3 );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ), 30 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_order_column' ), 30, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ), 30 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_order_column' ), 30, 2 );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ), 30 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 30, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_classic_filters' ), 30, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_hpos_order_filter' ), 30, 2 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_order_query' ), 30 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'restrict_agent_order_queries' ), 30 );
		add_action( 'pre_get_posts', array( $this, 'filter_classic_admin_queries' ), 30 );
		add_filter( 'map_meta_cap', array( $this, 'restrict_agent_order_access' ), 30, 4 );
		add_filter( 'user_has_cap', array( $this, 'grant_agent_order_view_caps' ), 30, 4 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'remove_agent_order_bulk_actions' ), 999 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'remove_agent_order_bulk_actions' ), 999 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'remove_agent_product_bulk_actions' ), 999 );
		add_filter( 'post_row_actions', array( $this, 'remove_agent_product_row_actions' ), 999, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_agent_admin_access' ), 20, 1 );
		add_action( 'admin_init', array( $this, 'block_agent_product_creation' ), 5 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'block_agent_order_write' ), 1, 1 );
		add_action( 'admin_head', array( $this, 'order_column_styles' ) );
	}

	public function render_product_field() {
		if ( ! self::can_assign_sellers() ) {
			return;
		}

		global $post;
		$product = isset( $GLOBALS['product_object'] ) && $GLOBALS['product_object'] instanceof WC_Product
			? $GLOBALS['product_object']
			: ( $post ? wc_get_product( $post->ID ) : null );
		$value   = $product instanceof WC_Product ? absint( $product->get_meta( self::PRODUCT_META, true ) ) : 0;
		$options = array( '' => 'تخصیص ثبت نشده' );

		foreach ( self::seller_options() as $user_id => $label ) {
			$options[ $user_id ] = $label;
		}

		woocommerce_wp_select(
			array(
				'id'            => self::PRODUCT_META,
				'value'         => $value ?: '',
				'label'         => 'فروشنده کالا',
				'options'       => $options,
				'class'         => 'wc-enhanced-select',
				'wrapper_class' => 'show_if_simple show_if_variable',
				'desc_tip'      => true,
				'description'   => 'فقط کاربران دارای نقش seller نمایش داده می‌شوند. این فروشنده همراه آیتم سفارش به پنل Central منتقل می‌شود.',
			)
		);
	}

	public function save_product_field( $product ) {
		if (
			! $product instanceof WC_Product ||
			! self::can_assign_sellers() ||
			! current_user_can( 'edit_post', $product->get_id() ) ||
			! isset( $_POST[ self::PRODUCT_META ] )
		) {
			return;
		}

		$previous  = absint( $product->get_meta( self::PRODUCT_META, true ) );
		$seller_id = absint( wp_unslash( $_POST[ self::PRODUCT_META ] ) );
		$seller    = self::valid_seller( $seller_id );
		if ( $seller ) {
			$product->update_meta_data( self::PRODUCT_META, $seller->ID );
		} else {
			$product->delete_meta_data( self::PRODUCT_META );
		}
		if ( $previous !== ( $seller ? (int) $seller->ID : 0 ) ) {
			do_action( 'company_order_sync_product_seller_changed', $product->get_id() );
		}
	}

	public function render_bulk_edit_field() {
		if ( ! self::can_assign_sellers() ) {
			return;
		}

		echo '<div class="inline-edit-group company-product-seller-bulk">';
		echo '<label class="alignleft">';
		echo '<span class="title">' . esc_html__( 'فروشنده کالا', 'company-order-sync' ) . '</span>';
		echo '<span class="input-text-wrap"><select name="' . esc_attr( self::BULK_FIELD ) . '">';
		echo '<option value="__no_change__">' . esc_html__( '— بدون تغییر —', 'company-order-sync' ) . '</option>';
		echo '<option value="0">' . esc_html__( 'حذف تخصیص فروشنده', 'company-order-sync' ) . '</option>';
		foreach ( self::seller_options() as $user_id => $label ) {
			echo '<option value="' . esc_attr( $user_id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></span>';
		echo '</label>';
		echo '</div>';
	}

	public function save_bulk_edit_field( $product ) {
		if (
			! $product instanceof WC_Product ||
			! self::can_assign_sellers() ||
			! current_user_can( 'edit_post', $product->get_id() ) ||
			! isset( $_REQUEST[ self::BULK_FIELD ] )
		) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_REQUEST[ self::BULK_FIELD ] ) );
		if ( '__no_change__' === $value ) {
			return;
		}

		$previous = absint( $product->get_meta( self::PRODUCT_META, true ) );
		$seller   = self::valid_seller( absint( $value ) );
		if ( $seller ) {
			$product->update_meta_data( self::PRODUCT_META, $seller->ID );
		} elseif ( '0' === $value ) {
			$product->delete_meta_data( self::PRODUCT_META );
		}
		$product->save_meta_data();
		if ( $previous !== ( $seller ? (int) $seller->ID : 0 ) ) {
			do_action( 'company_order_sync_product_seller_changed', $product->get_id() );
		}
	}

	public static function can_assign_sellers() {
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		return is_super_admin( $user->ID ) ||
			in_array( 'administrator', (array) $user->roles, true ) ||
			0 === strcasecmp( (string) $user->user_login, self::PRIVILEGED_LOGIN );
	}

	/**
	 * A seller-created product belongs to its author immediately. DigiMaster sets
	 * post_author on its Dokan imports, while this plugin uses PRODUCT_META as the
	 * canonical assignment used by order visibility and synchronization.
	 */
	public function assign_new_product_author( $post_id, $post, $update, $post_before ) {
		if ( $update || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		$this->assign_product_to_seller( $post_id, absint( $post->post_author ), 'product-author' );
	}

	public function assign_digimaster_product( $product_id, $source_product_id, $seller_id, $extra_meta = array() ) {
		$this->assign_product_to_seller( $product_id, $seller_id, 'digimaster' );
	}

	private function assign_product_to_seller( $product_id, $seller_id, $source ) {
		$product_id = absint( $product_id );
		$seller     = self::valid_seller( absint( $seller_id ) );
		if ( ! $product_id || ! $seller || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		// An explicit/manual assignment always wins and is never overwritten.
		if ( absint( get_post_meta( $product_id, self::PRODUCT_META, true ) ) ) {
			return false;
		}

		update_post_meta( $product_id, self::PRODUCT_META, $seller->ID );
		update_post_meta( $product_id, '_company_product_seller_assignment_source', sanitize_key( $source ) );
		update_post_meta( $product_id, '_company_product_seller_assigned_at', gmdate( 'c' ) );
		do_action( 'company_order_sync_product_seller_changed', $product_id );
		return true;
	}

	public function ensure_author_backfill() {
		if ( COMPANY_ORDER_SYNC_VERSION === (string) get_option( self::AUTHOR_BACKFILL_OPTION, '' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::AUTHOR_BACKFILL_HOOK, array(), Company_Order_Sync_Queue::GROUP ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::AUTHOR_BACKFILL_HOOK, array(), Company_Order_Sync_Queue::GROUP, true );
		} elseif ( ! wp_next_scheduled( self::AUTHOR_BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::AUTHOR_BACKFILL_HOOK );
		}
	}

	public function backfill_product_authors() {
		$seller_ids = get_users( array( 'role'=>self::ROLE, 'fields'=>'ids' ) );
		if ( ! $seller_ids ) {
			update_option( self::AUTHOR_BACKFILL_OPTION, COMPANY_ORDER_SYNC_VERSION, false );
			return;
		}

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future' ),
				'author__in'     => array_map( 'absint', $seller_ids ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key'=>self::PRODUCT_META, 'compare'=>'NOT EXISTS' ),
				),
			)
		);

		foreach ( $product_ids as $product_id ) {
			$post = get_post( $product_id );
			if ( $post instanceof WP_Post ) {
				$this->assign_product_to_seller( $product_id, $post->post_author, 'author-backfill' );
			}
		}

		if ( count( $product_ids ) < 100 ) {
			update_option( self::AUTHOR_BACKFILL_OPTION, COMPANY_ORDER_SYNC_VERSION, false );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::AUTHOR_BACKFILL_HOOK, array(), Company_Order_Sync_Queue::GROUP, true );
		} else {
			wp_schedule_single_event( time() + 1, self::AUTHOR_BACKFILL_HOOK );
		}
	}

	private static function seller_options() {
		$options = array();
		foreach ( get_users( array( 'role'=>self::ROLE, 'orderby'=>'display_name', 'order'=>'ASC' ) ) as $user ) {
			$options[ $user->ID ] = $user->display_name ?: $user->user_login;
		}
		return $options;
	}

	public function capture_checkout_item( $item, $cart_item_key, $values, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product ? $values['data'] : $item->get_product();
		$this->snapshot_seller( $item, $product );
	}

	public function capture_new_order_item( $item_id, $item, $order_id ) {
		if ( ! $item instanceof WC_Order_Item_Product || $item->get_meta( self::ITEM_NAME_META, true ) ) {
			return;
		}
		if ( $this->snapshot_seller( $item, $item->get_product() ) && $item->get_id() ) {
			$item->save_meta_data();
		}
	}

	private function snapshot_seller( WC_Order_Item_Product $item, $product ) {
		$seller = $product instanceof WC_Product ? self::seller_for_product( $product ) : array( 'id'=>0, 'name'=>'' );
		if ( $seller['id'] && $seller['name'] ) {
			$item->update_meta_data( self::ITEM_ID_META, $seller['id'] );
			$item->update_meta_data( self::ITEM_NAME_META, $seller['name'] );
			$item->update_meta_data( self::ITEM_LOGIN_META, $seller['login'] ?? '' );
			return true;
		}
		$item->delete_meta_data( self::ITEM_ID_META );
		$item->delete_meta_data( self::ITEM_NAME_META );
		$item->delete_meta_data( self::ITEM_LOGIN_META );
		return false;
	}

	public static function seller_for_product( WC_Product $product ) {
		$product_ids = array_filter( array_unique( array( $product->get_id(), $product->get_parent_id() ) ) );
		foreach ( $product_ids as $product_id ) {
			$candidate = wc_get_product( $product_id );
			$seller    = $candidate instanceof WC_Product ? self::valid_seller( absint( $candidate->get_meta( self::PRODUCT_META, true ) ) ) : null;
			if ( $seller ) {
				return self::seller_data( $seller );
			}
		}
		return self::empty_seller();
	}

	public static function seller_for_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return self::empty_seller();
		}
		$product = $item->get_product();
		if ( $product instanceof WC_Product ) {
			return self::seller_for_product( $product );
		}
		$id   = absint( $item->get_meta( self::ITEM_ID_META, true ) );
		$name = sanitize_text_field( $item->get_meta( self::ITEM_NAME_META, true ) );
		$login = sanitize_user( $item->get_meta( self::ITEM_LOGIN_META, true ), false );
		if ( $name ) {
			$seller = self::valid_seller( $id );
			return array( 'id'=>$id, 'name'=>$name, 'login'=>$login ?: ( $seller ? $seller->user_login : '' ) );
		}
		return self::empty_seller();
	}

	private static function seller_data( WP_User $seller ) {
		return array(
			'id'    => (int) $seller->ID,
			'name'  => sanitize_text_field( $seller->display_name ?: $seller->user_login ),
			'login' => sanitize_user( $seller->user_login, false ),
		);
	}

	private static function empty_seller() {
		return array( 'id'=>0, 'name'=>'', 'login'=>'' );
	}

	private static function valid_seller( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) ? $user : null;
	}

	public function add_order_column( $columns ) {
		$result   = array();
		$inserted = false;
		foreach ( (array) $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$result[ self::ORDER_COLUMN ] = 'فروشنده‌های کالا';
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$result[ self::ORDER_COLUMN ] = 'فروشنده‌های کالا';
		}
		return $result;
	}

	public function render_legacy_order_column( $column, $post_id ) {
		if ( self::ORDER_COLUMN === $column ) {
			$this->render_order_sellers( wc_get_order( $post_id ) );
		}
	}

	public function render_hpos_order_column( $column, $order ) {
		if ( self::ORDER_COLUMN === $column ) {
			$this->render_order_sellers( $order instanceof WC_Order ? $order : wc_get_order( $order ) );
		}
	}

	public function add_product_column( $columns ) {
		$columns[ self::PRODUCT_COLUMN ] = 'Agent / فروشنده';
		return $columns;
	}

	public function render_product_column( $column, $post_id ) {
		if ( self::PRODUCT_COLUMN !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		$seller  = $product instanceof WC_Product ? self::seller_for_product( $product ) : self::empty_seller();
		echo $seller['name']
			? '<strong class="company-product-agent-name">' . esc_html( $seller['name'] ) . '</strong>'
			: '<span class="company-product-seller-empty">تخصیص ثبت نشده</span>';
	}

	public function render_classic_filters( $post_type, $which = '' ) {
		if ( 'product' === $post_type ) {
			if ( self::current_user_is_agent() ) {
				return;
			}
			$this->render_agent_filter( false );
		} elseif ( 'shop_order' === $post_type ) {
			$this->render_agent_filter( true );
		}
	}

	public function render_hpos_order_filter( $order_type, $which = '' ) {
		if ( 'shop_order' === $order_type ) {
			$this->render_agent_filter( true );
		}
	}

	private function render_agent_filter( $for_orders ) {
		$restricted = $for_orders && self::current_user_is_agent();
		$selected   = $for_orders ? $this->requested_order_agent_id() : $this->requested_filter_agent_id();
		echo '<select name="' . esc_attr( self::FILTER_QUERY_VAR ) . '" aria-label="فیلتر Agent"' . ( $restricted ? ' disabled' : '' ) . '>';
		echo '<option value="">همه Agentها</option>';
		if ( ! $restricted ) {
			echo '<option value="' . esc_attr( self::FILTER_UNASSIGNED ) . '" ' . selected( $selected, self::FILTER_UNASSIGNED, false ) . '>تخصیص ثبت نشده</option>';
		}
		foreach ( self::seller_options() as $user_id => $label ) {
			if ( $restricted && get_current_user_id() !== absint( $user_id ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $user_id ) . '" ' . selected( $selected, $user_id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( $restricted ) {
			echo '<input type="hidden" name="' . esc_attr( self::FILTER_QUERY_VAR ) . '" value="' . esc_attr( get_current_user_id() ) . '">';
		}
	}

	public function filter_hpos_order_query( $args ) {
		$agent_id = $this->requested_order_agent_id();
		if ( ! $agent_id ) {
			return $args;
		}
		$order_ids = self::FILTER_UNASSIGNED === $agent_id
			? $this->order_ids_with_unassigned_seller()
			: $this->order_ids_for_seller( $agent_id );
		return $this->include_only_order_ids( (array) $args, $order_ids );
	}

	public function restrict_agent_order_queries( $args ) {
		if ( ! self::current_user_is_agent() ) {
			return $args;
		}
		return $this->include_only_order_ids( (array) $args, $this->order_ids_for_seller( get_current_user_id() ) );
	}

	private function include_only_order_ids( array $args, array $allowed_ids ) {
		$allowed_ids = $allowed_ids ?: array( 0 );
		$existing    = isset( $args['post__in'] ) ? array_values( array_filter( array_map( 'absint', (array) $args['post__in'] ) ) ) : array();
		$args['post__in'] = $existing ? array_values( array_intersect( $existing, $allowed_ids ) ) ?: array( 0 ) : $allowed_ids;
		unset( $args['include'] );
		return $args;
	}

	public function filter_classic_admin_queries( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( 'product' === $post_type ) {
			$agent_id = self::current_user_is_agent()
				? get_current_user_id()
				: $this->requested_filter_agent_id();
			if ( $agent_id ) {
				$meta_query   = (array) $query->get( 'meta_query' );
				if ( self::FILTER_UNASSIGNED === $agent_id ) {
					$valid_seller_ids = array_map( 'absint', array_keys( self::seller_options() ) );
					$meta_query[] = array(
						'relation' => 'OR',
						array( 'key'=>self::PRODUCT_META, 'compare'=>'NOT EXISTS' ),
						array( 'key'=>self::PRODUCT_META, 'value'=>$valid_seller_ids ?: array( 0 ), 'compare'=>'NOT IN', 'type'=>'NUMERIC' ),
					);
				} else {
					$meta_query[] = array( 'key'=>self::PRODUCT_META, 'value'=>$agent_id, 'compare'=>'=' );
				}
				$query->set( 'meta_query', $meta_query );
			}
			return;
		}
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$agent_id = $this->requested_order_agent_id();
		if ( ! $agent_id ) {
			return;
		}
		$allowed  = self::FILTER_UNASSIGNED === $agent_id
			? $this->order_ids_with_unassigned_seller()
			: $this->order_ids_for_seller( $agent_id );
		$existing = array_values( array_filter( array_map( 'absint', (array) $query->get( 'post__in' ) ) ) );
		$query->set( 'post__in', $existing ? array_values( array_intersect( $existing, $allowed ) ) ?: array( 0 ) : ( $allowed ?: array( 0 ) ) );
	}

	public function restrict_agent_order_access( $caps, $cap, $user_id, $args ) {
		if (
			! self::current_user_is_agent( $user_id )
			|| ! in_array(
				$cap,
				array(
					'read_post', 'edit_post', 'delete_post',
					'read_shop_order', 'edit_shop_order', 'delete_shop_order',
					'read_product', 'edit_product', 'delete_product',
				),
				true
			)
		) {
			return $caps;
		}
		$object_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$order    = $object_id ? wc_get_order( $object_id ) : null;
		if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() && ! self::order_belongs_to_seller( $order, $user_id ) ) {
			return array( 'do_not_allow' );
		}

		$product = $object_id ? wc_get_product( $object_id ) : null;
		if ( $product instanceof WC_Product ) {
			$is_delete = in_array( $cap, array( 'delete_post', 'delete_product' ), true );
			if ( $is_delete || ! self::product_belongs_to_seller( $product, $user_id ) ) {
				return array( 'do_not_allow' );
			}
		}
		return $caps;
	}

	public function grant_agent_order_view_caps( $allcaps, $caps, $args, $user ) {
		if ( $user instanceof WP_User && self::current_user_is_agent( $user->ID ) ) {
			foreach ( array( 'read', 'edit_shop_orders', 'edit_others_shop_orders', 'read_private_shop_orders', 'edit_products', 'edit_others_products', 'read_private_products' ) as $view_cap ) {
				$allcaps[ $view_cap ] = true;
			}
		}
		return $allcaps;
	}

	public function remove_agent_order_bulk_actions( $actions ) {
		return self::current_user_is_agent() ? array() : $actions;
	}

	public function remove_agent_product_bulk_actions( $actions ) {
		return self::current_user_is_agent() ? array() : $actions;
	}

	public function remove_agent_product_row_actions( $actions, $post ) {
		if ( self::current_user_is_agent() && $post instanceof WP_Post && 'product' === $post->post_type ) {
			unset( $actions['trash'], $actions['delete'] );
		}
		return $actions;
	}

	public function allow_agent_admin_access( $prevent_access ) {
		return self::current_user_is_agent() ? false : $prevent_access;
	}

	public function block_agent_product_creation() {
		global $pagenow;
		if (
			self::current_user_is_agent()
			&& 'post-new.php' === $pagenow
			&& isset( $_GET['post_type'] )
			&& 'product' === sanitize_key( wp_unslash( $_GET['post_type'] ) )
		) {
			wp_die(
				esc_html__( 'حساب فروشنده فقط اجازه مشاهده محصولات تخصیص‌یافته به خود را دارد.', 'company-order-sync' ),
				esc_html__( 'دسترسی غیرمجاز', 'company-order-sync' ),
				array( 'response' => 403 )
			);
		}
	}

	public function block_agent_order_write( $order ) {
		if (
			self::current_user_is_agent()
			&& $order instanceof WC_Order
			&& $order->get_id()
			&& 'shop_order' === $order->get_type()
			&& ! Company_Order_Sync_Context::is_inbound()
		) {
			throw new WC_Data_Exception( 'company_agent_orders_read_only', 'حساب Agent فقط اجازه مشاهده سفارش‌های تخصیص‌یافته به خود را دارد.' );
		}
	}

	private function requested_filter_agent_id() {
		if ( ! isset( $_GET[ self::FILTER_QUERY_VAR ] ) || '' === (string) $_GET[ self::FILTER_QUERY_VAR ] ) {
			return 0;
		}
		$value = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_QUERY_VAR ] ) );
		if ( self::FILTER_UNASSIGNED === $value ) {
			return self::FILTER_UNASSIGNED;
		}
		$agent_id = absint( $value );
		return self::valid_seller( $agent_id ) ? $agent_id : -1;
	}

	private function requested_order_agent_id() {
		return self::current_user_is_agent() ? get_current_user_id() : $this->requested_filter_agent_id();
	}

	private static function current_user_is_agent( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		return $user instanceof WP_User
			&& in_array( self::ROLE, (array) $user->roles, true )
			&& ! in_array( 'administrator', (array) $user->roles, true );
	}

	private static function product_belongs_to_seller( WC_Product $product, $seller_id ) {
		$seller_id = absint( $seller_id );
		if ( ! $seller_id ) {
			return false;
		}
		$seller = self::seller_for_product( $product );
		return $seller_id === absint( $seller['id'] ?? 0 );
	}

	public static function order_belongs_to_seller( WC_Order $order, $seller_id ) {
		$seller_id = absint( $seller_id );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$seller = self::seller_for_order_item( $item );
			if ( $seller_id && $seller_id === absint( $seller['id'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	private function order_ids_for_seller( $seller_id ) {
		static $cache = array();
		$seller_id = absint( $seller_id );
		if ( ! $seller_id || ! self::valid_seller( $seller_id ) ) {
			return array();
		}
		if ( isset( $cache[ $seller_id ] ) ) {
			return $cache[ $seller_id ];
		}

		global $wpdb;
		$order_items  = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta    = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$product_meta = $wpdb->postmeta;
		$posts        = $wpdb->posts;
		$sql = "SELECT DISTINCT oi.order_id
			FROM {$order_items} oi
			LEFT JOIN {$item_meta} product_ref ON product_ref.order_item_id=oi.order_item_id AND product_ref.meta_key='_product_id'
			LEFT JOIN {$posts} source_product ON source_product.ID=CAST(product_ref.meta_value AS UNSIGNED) AND source_product.post_type IN ('product','product_variation')
			LEFT JOIN {$product_meta} assigned ON assigned.post_id=CAST(product_ref.meta_value AS UNSIGNED) AND assigned.meta_key=%s
			LEFT JOIN {$item_meta} snapshot ON snapshot.order_item_id=oi.order_item_id AND snapshot.meta_key=%s
			WHERE oi.order_item_type='line_item'
			AND (assigned.meta_value=%s OR (source_product.ID IS NULL AND snapshot.meta_value=%s))";
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, self::PRODUCT_META, self::ITEM_ID_META, (string) $seller_id, (string) $seller_id ) );
		$cache[ $seller_id ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		return $cache[ $seller_id ];
	}

	private function order_ids_with_unassigned_seller() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$order_items  = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta    = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$product_meta = $wpdb->postmeta;
		$posts        = $wpdb->posts;
		$valid_ids    = array_values( array_filter( array_map( 'absint', array_keys( self::seller_options() ) ) ) );

		$sql = "SELECT DISTINCT oi.order_id
			FROM {$order_items} oi
			LEFT JOIN {$item_meta} product_ref ON product_ref.order_item_id=oi.order_item_id AND product_ref.meta_key='_product_id'
			LEFT JOIN {$posts} source_product ON source_product.ID=CAST(product_ref.meta_value AS UNSIGNED) AND source_product.post_type='product'
			LEFT JOIN {$product_meta} assigned ON assigned.post_id=source_product.ID AND assigned.meta_key=%s
			LEFT JOIN {$item_meta} snapshot ON snapshot.order_item_id=oi.order_item_id AND snapshot.meta_key=%s
			WHERE oi.order_item_type='line_item'";
		$params = array( self::PRODUCT_META, self::ITEM_ID_META );
		if ( $valid_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $valid_ids ), '%d' ) );
			$sql .= " AND (
				(source_product.ID IS NOT NULL AND (assigned.meta_id IS NULL OR CAST(assigned.meta_value AS UNSIGNED) NOT IN ({$placeholders})))
				OR
				(source_product.ID IS NULL AND (snapshot.meta_id IS NULL OR CAST(snapshot.meta_value AS UNSIGNED) NOT IN ({$placeholders})))
			)";
			$params = array_merge( $params, $valid_ids, $valid_ids );
		}

		$cache = array_values( array_unique( array_filter( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) ) ) ) );
		return $cache;
	}

	private function render_order_sellers( $order ) {
		if ( ! $order instanceof WC_Order ) {
			echo '<span class="company-product-seller-empty">—</span>';
			return;
		}
		$names = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$seller = self::seller_for_order_item( $item );
			if ( $seller['name'] ) {
				$names[] = $seller['name'];
			}
		}
		$names = array_values( array_unique( $names ) );
		if ( ! $names ) {
			echo '<span class="company-product-seller-empty">تخصیص ثبت نشده</span>';
			return;
		}
		echo '<div class="company-product-sellers">';
		foreach ( $names as $name ) {
			echo '<span>' . esc_html( $name ) . '</span>';
		}
		echo '</div>';
	}

	public function order_column_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders', 'edit-product' ), true ) ) {
			return;
		}
		echo '<style>.column-company_product_sellers,.column-company_product_seller{width:150px}.company-product-sellers{display:flex;flex-direction:column;gap:4px}.company-product-sellers span{display:block;line-height:1.55}.company-product-seller-empty{color:#8c8f94}.company-product-agent-name{display:block;line-height:1.6}' . ( self::current_user_is_agent() && 'edit-product' === $screen->id ? '.wrap .page-title-action{display:none!important}' : '' ) . '</style>';
	}
}
