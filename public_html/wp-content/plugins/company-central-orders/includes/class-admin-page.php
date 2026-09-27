<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Admin_Page {

	const SLUG = 'company-orders';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_post_company_orders_update_status', array( $this, 'handle_status_update' ) );
		add_action( 'admin_post_company_orders_retry_sync', array( $this, 'handle_retry_sync' ) );
		add_action( 'admin_post_company_orders_add_note', array( $this, 'handle_add_note' ) );
		add_action( 'admin_post_company_orders_submit_tracking', array( $this, 'handle_submit_tracking' ) );
		add_action( 'admin_init', array( $this, 'redirect_operator_dashboard' ) );
		add_action( 'admin_menu', array( $this, 'trim_operator_menu' ), 999 );
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'redirect_after_woocommerce_login' ), 20, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_company_staff_admin' ), 20, 1 );
		add_action( 'template_redirect', array( $this, 'redirect_central_front_page' ), 1 );
	}

	public function register_menu() {
		add_menu_page(
			'سفارشات شرکت',
			'سفارشات شرکت',
			Company_Central_Orders_Access::CAP,
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-clipboard',
			2
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'company-central-orders',
			plugins_url( 'assets/admin.css', COMPANY_CENTRAL_ORDERS_FILE ),
			array(),
			COMPANY_CENTRAL_ORDERS_VERSION
		);
		wp_enqueue_script(
			'company-central-orders-legacy',
			plugins_url( 'assets/admin.js', COMPANY_CENTRAL_ORDERS_FILE ),
			array(),
			COMPANY_CENTRAL_ORDERS_VERSION,
			true
		);

		$app_script = COMPANY_CENTRAL_ORDERS_PATH . 'assets/app.js';
		$legacy     = ! Company_Central_Orders_Access::current_user_is_agent() && isset( $_GET['legacy'] ) && 1 === absint( $_GET['legacy'] );
		if ( file_exists( $app_script ) && ! $legacy ) {
			wp_enqueue_style(
				'company-central-orders-iransansx',
				plugins_url( 'assets/iransansx.css', COMPANY_CENTRAL_ORDERS_FILE ),
				array(),
				COMPANY_CENTRAL_ORDERS_VERSION
			);
			wp_enqueue_style(
				'company-central-orders-app',
				plugins_url( 'assets/app.css', COMPANY_CENTRAL_ORDERS_FILE ),
				array( 'company-central-orders-iransansx' ),
				COMPANY_CENTRAL_ORDERS_VERSION
			);
			wp_enqueue_script(
				'company-central-orders-app',
				plugins_url( 'assets/app.js', COMPANY_CENTRAL_ORDERS_FILE ),
				array(),
				COMPANY_CENTRAL_ORDERS_VERSION,
				true
			);
			wp_enqueue_script(
				'company-central-orders-reports-nav',
				plugins_url( 'assets/reports-nav.js', COMPANY_CENTRAL_ORDERS_FILE ),
				array( 'company-central-orders-app' ),
				COMPANY_CENTRAL_ORDERS_VERSION,
				true
			);
			wp_localize_script(
				'company-central-orders-app',
				'CCO_APP_CONFIG',
				array(
					'restUrl'     => esc_url_raw( rest_url( Company_Central_Orders_REST_Controller::NAMESPACE ) ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'adminPostUrl' => admin_url( 'admin-post.php' ),
					'exportNonce' => wp_create_nonce( 'company_orders_bulk_export' ),
					'printNonce'  => wp_create_nonce( 'company_orders_bulk_print' ),
					'userName'    => wp_get_current_user()->display_name,
					'legacyUrl'   => add_query_arg( array( 'page' => self::SLUG, 'legacy' => 1 ), admin_url( 'admin.php' ) ),
					'stores'      => $this->stores_for_app(),
					'statuses'    => $this->statuses_for_app(),
					'payments'    => $this->payments_for_app(),
					'agents'      => $this->agents_for_app(),
					'agentLocked' => Company_Central_Orders_Access::current_user_is_agent(),
				)
			);
		}
	}

	public function admin_body_class( $classes ) {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$legacy = ! Company_Central_Orders_Access::current_user_is_agent() && isset( $_GET['legacy'] ) && 1 === absint( $_GET['legacy'] );
		if ( self::SLUG === $page && ! $legacy && file_exists( COMPANY_CENTRAL_ORDERS_PATH . 'assets/app.js' ) ) {
			$classes .= ' cco-ant-fullscreen';
		}
		return $classes;
	}

	public function redirect_operator_dashboard() {
		if (
			$this->is_company_staff()
			&& is_admin()
			&& ! wp_doing_ajax()
			&& isset( $GLOBALS['pagenow'] )
			&& 'index.php' === $GLOBALS['pagenow']
		) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
	}

	public function trim_operator_menu() {
		if ( $this->is_company_staff() ) {
			remove_menu_page( 'index.php' );
		}
	}

	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && $user->exists() && ! in_array( 'administrator', (array) $user->roles, true ) && user_can( $user, Company_Central_Orders_Access::CAP ) ) {
			return admin_url( 'admin.php?page=' . self::SLUG );
		}
		return $redirect_to;
	}

	public function redirect_after_woocommerce_login( $redirect_to, $user ) {
		return $this->redirect_after_login( $redirect_to, '', $user );
	}

	public function allow_company_staff_admin( $prevent_access ) {
		return current_user_can( Company_Central_Orders_Access::CAP ) ? false : $prevent_access;
	}

	public function redirect_central_front_page() {
		if (
			is_admin()
			|| wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		$panel_url = admin_url( 'admin.php?page=' . self::SLUG );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $panel_url ) );
			exit;
		}
		wp_safe_redirect( $this->is_company_staff() ? $panel_url : admin_url() );
		exit;
	}

	private function is_company_staff() {
		$user = wp_get_current_user();
		return $user instanceof WP_User
			&& $user->exists()
			&& ! in_array( 'administrator', (array) $user->roles, true )
			&& current_user_can( Company_Central_Orders_Access::CAP );
	}

	public function render_page() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'company-central-orders' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$legacy   = ! Company_Central_Orders_Access::current_user_is_agent() && isset( $_GET['legacy'] ) && 1 === absint( $_GET['legacy'] );

		if ( ! $legacy && file_exists( COMPANY_CENTRAL_ORDERS_PATH . 'assets/app.js' ) ) {
			echo '<div id="cco-ant-app" dir="rtl"><div class="cco-app-loading"><span class="spinner is-active"></span><p>در حال بارگذاری پنل عملیاتی…</p></div></div>';
			return;
		}

		echo '<div class="wrap cco-wrap">';
		$this->render_notice();
		if ( $order_id ) {
			$this->render_order_details( $order_id );
		} else {
			$this->render_orders_list();
		}
		echo '</div>';
	}

	private function stores_for_app() {
		$items = array();
		foreach ( apply_filters( 'company_order_sync_registered_stores', array() ) as $value => $label ) {
			$items[] = array( 'value' => sanitize_key( $value ), 'label' => wp_strip_all_tags( $label ) );
		}
		return $items;
	}

	private function statuses_for_app() {
		$options = $this->synced_order_filter_options();
		$visible = array_map( static function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( Company_Central_Orders_Access::visible_statuses() ) );
		return array_values( array_filter( $options['statuses'], static function( $item ) use ( $visible ) { return in_array( $item['value'], $visible, true ); } ) );
	}

	private function payments_for_app() {
		$options = $this->synced_order_filter_options();
		return $options['payments'];
	}

	private function agents_for_app() {
		$items = array();
		if ( ! Company_Central_Orders_Access::current_user_is_agent() ) {
			$items[] = array(
				'value' => 'unassigned',
				'label' => 'تخصیص ثبت نشده',
			);
		}
		foreach ( get_users( array( 'role'=>'seller', 'orderby'=>'display_name', 'order'=>'ASC' ) ) as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( Company_Central_Orders_Access::current_user_is_agent() && get_current_user_id() !== (int) $user->ID ) {
				continue;
			}
			$items[] = array(
				'value' => (string) $user->ID,
				'label' => sanitize_text_field( $user->display_name ?: $user->user_login ),
			);
		}
		return $items;
	}

	private function synced_order_filter_options() {
		static $options = null;
		if ( null !== $options ) {
			return $options;
		}
		$options  = array( 'statuses' => array(), 'payments' => array(), 'shippings' => array() );
		$statuses = array();
		$payments = array();
		$shippings = array();
		foreach ( (array) get_option( 'company_order_sync_remote_statuses', array() ) as $remote_statuses ) {
			foreach ( (array) $remote_statuses as $remote_status ) {
				$slug = sanitize_key( $remote_status['slug'] ?? '' );
				if ( $slug ) $statuses[ $slug ] = sanitize_text_field( $remote_status['label'] ?? $slug );
			}
		}
		$orders   = wc_get_orders(
			array(
				'limit'      => 500,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'type'       => 'shop_order',
				'meta_query' => array(
					array(
						'key'     => '_company_source_store',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! sanitize_key( $order->get_meta( '_company_source_store', true ) ) ) {
				continue;
			}
			$status = sanitize_key( $order->get_status() );
			if ( $status && ! isset( $statuses[ $status ] ) ) {
				$statuses[ $status ] = wc_get_order_status_name( $status );
			}
			$payment = sanitize_key( $order->get_payment_method() );
			if ( $payment && ! isset( $payments[ $payment ] ) ) {
				$payments[ $payment ] = wp_strip_all_tags( $order->get_payment_method_title() ?: $payment );
			}
			$shipping = sanitize_text_field( $order->get_meta( '_company_shipping_method_filter', true ) ?: $order->get_shipping_method() );
			if ( $shipping ) $shippings[ $shipping ] = $shipping;
		}
		foreach ( $statuses as $value => $label ) {
			$options['statuses'][] = array( 'value' => $value, 'label' => $label );
		}
		foreach ( $payments as $value => $label ) {
			$options['payments'][] = array( 'value' => $value, 'label' => $label );
		}
		foreach ( $shippings as $value => $label ) {
			$options['shippings'][] = array( 'value' => $value, 'label' => $label );
		}
		return $options;
	}

	private function render_notice() {
		$notice = isset( $_GET['cco_notice'] ) ? sanitize_key( wp_unslash( $_GET['cco_notice'] ) ) : '';
		if ( 'updated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>وضعیت سفارش با موفقیت تغییر کرد و برای Sync در صف قرار گرفت.</p></div>';
		} elseif ( 'retry' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>تلاش مجدد Sync در صف قرار گرفت.</p></div>';
		} elseif ( 'note' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>یادداشت داخلی سفارش ثبت شد.</p></div>';
		} elseif ( 'tracking' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>کد رهگیری برای ثبت در سایت مبدأ و ارسال پیامک در صف قرار گرفت.</p></div>';
		}
	}

	private function render_orders_list() {
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$store     = isset( $_GET['store'] ) ? sanitize_key( wp_unslash( $_GET['store'] ) ) : '';
		$payment   = isset( $_GET['payment'] ) ? sanitize_key( wp_unslash( $_GET['payment'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? $this->sanitize_date( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? $this->sanitize_date( wp_unslash( $_GET['date_to'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$meta_query = array(
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
			'limit'      => 10,
			'paged'      => $paged,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'type'       => 'shop_order',
			'meta_query' => $meta_query,
		);
		if ( Company_Central_Orders_Access::current_user_is_agent() ) {
			$agent_ids       = Company_Central_Orders_Access::order_ids_for_agent_login( Company_Central_Orders_Access::current_agent_login() );
			$args['post__in'] = $agent_ids ?: array( 0 );
		}

		$valid_statuses = $this->status_slugs( wc_get_order_statuses() );
		if ( $status && in_array( $status, $valid_statuses, true ) ) {
			$args['status'] = $status;
		}
		if ( $payment ) {
			$args['payment_method'] = $payment;
		}
		$date_query = $this->date_created_query( $date_from, $date_to );
		if ( $date_query ) {
			$args['date_created'] = $date_query;
		}

		if ( '' !== $search ) {
			$ids             = array_values( array_filter( array_unique( array_map( 'absint', (array) wc_order_search( $search ) ) ) ) );
			$search_ids = $ids ?: array( 0 );
			$args['post__in'] = isset( $args['post__in'] )
				? ( array_values( array_intersect( (array) $args['post__in'], $search_ids ) ) ?: array( 0 ) )
				: $search_ids;
		}

		$results = wc_get_orders( $args );
		$stores  = apply_filters( 'company_order_sync_registered_stores', array() );

		echo '<div class="cco-header">';
		echo '<div><h1>پنل عملیاتی سفارشات</h1><p>اطلاعات مشتری، کالا، پرداخت، تأمین و چاپ‌ها در یک صفحه.</p></div>';
		echo '<div class="cco-count">' . esc_html( number_format_i18n( (int) $results->total ) ) . '<span> سفارش</span></div>';
		echo '</div>';

		echo '<form method="get" class="cco-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<div class="cco-search"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="شماره سفارش، نام مشتری، موبایل یا ایمیل…"></div>';
		echo '<select name="store" aria-label="فروشگاه"><option value="">همه فروشگاه‌ها</option>';
		foreach ( $stores as $store_id => $label ) {
			echo '<option value="' . esc_attr( $store_id ) . '" ' . selected( $store, $store_id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<select name="status" aria-label="وضعیت"><option value="">همه وضعیت‌های سفارش</option>';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = str_replace( 'wc-', '', $key );
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<select name="payment" aria-label="روش پرداخت"><option value="">همه روش‌های پرداخت</option>';
		foreach ( $this->payment_methods() as $method_id => $label ) {
			echo '<option value="' . esc_attr( $method_id ) . '" ' . selected( $payment, $method_id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label class="cco-date-field"><span>از تاریخ</span><input type="date" name="date_from" value="' . esc_attr( $date_from ) . '"></label>';
		echo '<label class="cco-date-field"><span>تا تاریخ</span><input type="date" name="date_to" value="' . esc_attr( $date_to ) . '"></label>';
		echo '<button class="button button-primary" type="submit">اعمال فیلتر</button>';
		echo '<a class="button" href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '">پاک کردن</a></form>';

		if ( ! empty( $results->orders ) ) {
			$this->render_export_toolbar();
		}

		echo '<div class="cco-order-list">';
		if ( empty( $results->orders ) ) {
			echo '<div class="cco-empty">سفارش همگام‌شده‌ای با این فیلترها پیدا نشد.</div>';
		} else {
			foreach ( $results->orders as $order ) {
				Company_Central_Orders_Order_Card::render( $order );
			}
		}
		echo '</div>';

		$this->render_pagination( $paged, (int) $results->max_num_pages, $status, $store, $payment, $date_from, $date_to, $search );
	}

	private function render_export_toolbar() {
		echo '<div class="cco-export-toolbar">';
		echo '<label class="cco-select-all"><input type="checkbox" id="cco-select-all"> <span>انتخاب همه سفارش‌های این صفحه</span></label>';
		echo '<strong class="cco-selection-count" aria-live="polite"><span>۰</span> سفارش انتخاب شده</strong>';
		echo '<form id="cco-bulk-export-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="company_orders_bulk_export">';
		wp_nonce_field( 'company_orders_bulk_export', '_cco_nonce' );
		echo '<button class="button button-primary cco-export-button" type="submit" name="format" value="pdf" disabled>دانلود PDF</button>';
		echo '<button class="button cco-export-button" type="submit" name="format" value="docx" disabled>دانلود Word</button>';
		echo '<button class="button cco-export-button" type="submit" name="format" value="xlsx" disabled>دانلود Excel</button>';
		echo '</form>';
		echo '<form id="cco-bulk-print-form" method="post" target="_blank" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="company_orders_bulk_print">';
		wp_nonce_field( 'company_orders_bulk_print', '_cco_print_nonce' );
		echo '<button class="button cco-print-button" type="submit" name="document" value="invoice" disabled>چاپ فاکتور</button>';
		echo '<button class="button cco-print-button" type="submit" name="document" value="label" disabled>چاپ لیبل</button>';
		echo '<button class="button cco-print-button" type="submit" name="document" value="delivery" disabled>چاپ برگه تحویل</button>';
		echo '<button class="button cco-print-button" type="submit" name="document" value="warehouse" disabled>لیست تجمیعی انبار</button>';
		echo '</form></div>';
	}

	private function render_order_row( WC_Order $order ) {
		$details_url = add_query_arg( array( 'page' => self::SLUG, 'order_id' => $order->get_id() ), admin_url( 'admin.php' ) );
		$name        = trim( $order->get_formatted_billing_full_name() ) ?: 'بدون نام';
		$date        = $order->get_date_created();
		$source      = $this->source_data( $order );
		$sync        = sanitize_key( $order->get_meta( '_company_sync_status', true ) ) ?: 'pending';

		echo '<tr>';
		echo '<td><strong>#' . esc_html( $order->get_order_number() ) . '</strong></td>';
		echo '<td><strong>' . esc_html( $source['label'] ) . '</strong><br><small>#' . esc_html( $source['number'] ) . '</small></td>';
		echo '<td><strong>' . esc_html( $name ) . '</strong><br><small dir="ltr">' . esc_html( $order->get_billing_phone() ?: $order->get_billing_email() ) . '</small></td>';
		echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
		echo '<td>' . $this->status_badge( $order->get_status() ) . '</td>';
		echo '<td>' . $this->sync_badge( $sync ) . '</td>';
		echo '<td>' . esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $date ) ) . '</td>';
		echo '<td><a class="button" href="' . esc_url( $details_url ) . '">جزئیات</a>';
		$this->render_quick_status_form( $order );
		echo '</td></tr>';
	}

	private function render_quick_status_form( WC_Order $order ) {
		$statuses = Company_Central_Orders_Access::allowed_statuses( $order );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cco-quick-status">';
		echo '<input type="hidden" name="action" value="company_orders_update_status"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="return_order_id" value="0">';
		wp_nonce_field( 'company_orders_update_status_' . $order->get_id(), '_cco_nonce' );
		echo '<select name="new_status" aria-label="تغییر سریع وضعیت">';
		foreach ( $statuses as $key => $label ) {
			$slug = str_replace( 'wc-', '', $key );
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $order->get_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button class="button" type="submit">ثبت</button></form>';
	}

	private function render_pagination( $paged, $max_pages, $status, $store, $payment, $date_from, $date_to, $search ) {
		if ( $max_pages <= 1 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'   => self::SLUG,
				'status' => $status,
				'store'  => $store,
				'payment' => $payment,
				'date_from' => $date_from,
				'date_to' => $date_to,
				's'      => $search,
				'paged'  => 999999999,
			),
			admin_url( 'admin.php' )
		);
		$base = str_replace( '999999999', '%#%', $base );

		echo '<div class="cco-pagination">' . wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'current'   => $paged,
					'total'     => $max_pages,
					'prev_text' => '→ قبلی',
					'next_text' => 'بعدی ←',
				)
			)
		) . '</div>';
	}

	private function render_order_details( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) ) {
			echo '<div class="notice notice-error"><p>سفارش همگام‌شده پیدا نشد.</p></div>';
			return;
		}

		$source   = $this->source_data( $order );
		$back_url = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		echo '<div class="cco-detail-header"><div><a class="cco-back" href="' . esc_url( $back_url ) . '">→ بازگشت به سفارش‌ها</a><h1>سفارش #' . esc_html( $order->get_order_number() ) . '</h1><p>مبدأ: <strong>' . esc_html( $source['label'] . ' #' . $source['number'] ) . '</strong></p></div><div class="cco-detail-total">' . wp_kses_post( $order->get_formatted_order_total() ) . '</div></div>';

		echo '<div class="cco-grid">';
		$this->render_customer_card( $order );
		$this->render_status_card( $order );
		$this->render_sync_card( $order, $source );
		echo '</div>';

		$this->render_items( $order );
		$customer_note = trim( (string) $order->get_customer_note() );
		if ( $customer_note ) {
			echo '<section class="cco-card"><h2>یادداشت مشتری</h2><p>' . nl2br( esc_html( $customer_note ) ) . '</p></section>';
		}
		$this->render_notes( $order );
	}

	private function render_customer_card( WC_Order $order ) {
		echo '<section class="cco-card"><h2>مشتری</h2><p><strong>' . esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ) . '</strong></p><p dir="ltr">' . esc_html( $order->get_billing_phone() ?: '—' ) . '</p><p>' . esc_html( $order->get_billing_email() ?: '—' ) . '</p>';
		$billing = $order->get_formatted_billing_address();
		if ( $billing ) {
			echo '<h3>آدرس صورتحساب</h3><div class="cco-address">' . wp_kses_post( $billing ) . '</div>';
		}
		$shipping = $order->get_formatted_shipping_address();
		if ( $shipping ) {
			echo '<h3>آدرس ارسال</h3><div class="cco-address">' . wp_kses_post( $shipping ) . '</div>';
		}
		echo '</section>';
	}

	private function render_status_card( WC_Order $order ) {
		echo '<section class="cco-card"><h2>وضعیت سفارش</h2>';
		$this->render_detail_status_form( $order );
		echo '<div class="cco-meta"><p><span>روش پرداخت</span><strong>' . esc_html( $order->get_payment_method_title() ?: '—' ) . '</strong></p><p><span>تاریخ ثبت</span><strong>' . esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $order->get_date_created() ) ) . '</strong></p><p><span>تاریخ تحویل</span><strong>' . esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ) . '</strong></p></div></section>';
	}

	private function render_detail_status_form( WC_Order $order ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cco-status-form"><input type="hidden" name="action" value="company_orders_update_status"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="return_order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( 'company_orders_update_status_' . $order->get_id(), '_cco_nonce' );
		echo '<select name="new_status">';
		foreach ( Company_Central_Orders_Access::allowed_statuses( $order ) as $key => $label ) {
			$slug = str_replace( 'wc-', '', $key );
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $order->get_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button type="submit" class="button button-primary button-large">ذخیره وضعیت</button></form><p class="description">پس از ذخیره، تغییر وضعیت به فروشگاه مبدأ ارسال می‌شود.</p>';
	}

	private function render_sync_card( WC_Order $order, array $source ) {
		$status     = sanitize_key( $order->get_meta( '_company_sync_status', true ) ) ?: 'pending';
		$last_sync  = sanitize_text_field( $order->get_meta( '_company_last_sync_at', true ) );
		$last_error = sanitize_textarea_field( $order->get_meta( '_company_last_sync_error', true ) );
		$request_id = sanitize_text_field( $order->get_meta( '_company_last_sync_request_id', true ) );

		echo '<section class="cco-card"><h2>همگام‌سازی</h2><p>' . $this->sync_badge( $status ) . '</p><div class="cco-meta">';
		echo '<p><span>فروشگاه</span><strong>' . esc_html( $source['label'] ) . '</strong></p><p><span>شناسه مبدأ</span><strong>#' . esc_html( $source['id'] ) . '</strong></p><p><span>آخرین Sync</span><strong dir="ltr">' . esc_html( $last_sync ?: '—' ) . '</strong></p><p><span>Request ID</span><strong dir="ltr">' . esc_html( $request_id ?: '—' ) . '</strong></p></div>';
		if ( $source['url'] ) {
			echo '<p><a href="' . esc_url( $source['url'] ) . '" target="_blank" rel="noopener noreferrer">باز کردن فروشگاه مبدأ</a></p>';
		}
		if ( $last_error ) {
			echo '<div class="cco-sync-error">' . nl2br( esc_html( $last_error ) ) . '</div>';
		}
		if ( current_user_can( Company_Central_Orders_Access::RETRY_CAP ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="company_orders_retry_sync"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			wp_nonce_field( 'company_orders_retry_sync_' . $order->get_id(), '_cco_nonce' );
			echo '<button class="button" type="submit">تلاش مجدد Sync</button></form>';
		}
		echo '</section>';
	}

	private function render_items( WC_Order $order ) {
		echo '<section class="cco-card cco-items-card"><h2>اقلام سفارش</h2><table class="widefat striped"><thead><tr><th>محصول</th><th>SKU</th><th>تعداد</th><th>جمع</th></tr></thead><tbody>';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			echo '<tr><td><strong>' . esc_html( $item->get_name() ) . '</strong></td><td dir="ltr">' . esc_html( $item->get_meta( '_company_source_sku', true ) ?: '—' ) . '</td><td>' . esc_html( $item->get_quantity() ) . '</td><td>' . wp_kses_post( wc_price( $item->get_total() + $item->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private function render_notes( WC_Order $order ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 0, 'orderby' => 'date_created_gmt', 'order' => 'DESC' ) );
		echo '<section class="cco-card"><h2>تاریخچه</h2>';
		if ( ! $notes ) {
			echo '<p>یادداشتی ثبت نشده است.</p>';
		} else {
			echo '<div class="cco-timeline">';
			foreach ( $notes as $note ) {
				echo '<div class="cco-note"><div>' . wp_kses_post( $note->content ) . '</div><small>' . esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $note->date_created ) ) . '</small></div>';
			}
			echo '</div>';
		}
		echo '</section>';
	}

	public function handle_status_update() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}

		$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$return_order_id = isset( $_POST['return_order_id'] ) ? absint( $_POST['return_order_id'] ) : 0;
		$new_status     = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
		$nonce          = isset( $_POST['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cco_nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'company_orders_update_status_' . $order_id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'company-central-orders' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) || ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			wp_die( esc_html__( 'سفارش همگام‌شده پیدا نشد.', 'company-central-orders' ), 404 );
		}

		$allowed = $this->status_slugs( Company_Central_Orders_Access::allowed_statuses( $order ) );
		if ( ! in_array( $new_status, $allowed, true ) ) {
			wp_die( esc_html__( 'این تغییر وضعیت برای نقش شما مجاز نیست.', 'company-central-orders' ), 403 );
		}

		if ( $order->get_status() !== $new_status ) {
			$user = wp_get_current_user();
			$order->update_status( $new_status, sprintf( 'وضعیت از پنل داخلی توسط %s تغییر کرد.', $user->display_name ?: $user->user_login ), true );
		}

		$url = add_query_arg( array( 'page' => self::SLUG, 'cco_notice' => 'updated' ), admin_url( 'admin.php' ) );
		if ( $return_order_id ) {
			$url = add_query_arg( 'order_id', $return_order_id, $url );
		} else {
			$url .= '#cco-order-' . $order_id;
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_retry_sync() {
		if ( ! current_user_can( Company_Central_Orders_Access::RETRY_CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cco_nonce'] ) ) : '';
		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'company_orders_retry_sync_' . $order_id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'company-central-orders' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) ) {
			wp_die( esc_html__( 'سفارش همگام‌شده پیدا نشد.', 'company-central-orders' ), 404 );
		}
		if ( ! has_action( 'company_order_sync_manual_retry' ) ) {
			wp_die( esc_html__( 'افزونه Company Order Sync فعال نیست.', 'company-central-orders' ), 503 );
		}

		do_action( 'company_order_sync_manual_retry', $order_id );
		$url = add_query_arg( array( 'page' => self::SLUG, 'order_id' => $order_id, 'cco_notice' => 'retry' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_add_note() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cco_nonce'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$note     = trim( $note );

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'company_orders_add_note_' . $order_id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'company-central-orders' ), 400 );
		}
		if ( '' === $note ) {
			wp_die( esc_html__( 'متن یادداشت خالی است.', 'company-central-orders' ), 422 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) || ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			wp_die( esc_html__( 'سفارش همگام‌شده پیدا نشد.', 'company-central-orders' ), 404 );
		}

		$user = wp_get_current_user();
		$note_id = $order->add_order_note( sprintf( '[یادداشت داخلی توسط %s] %s', $user->display_name ?: $user->user_login, $note ), false, false );
		if ( $note_id ) {
			do_action( 'company_order_sync_queue_note', $order->get_id(), $note_id );
		}
		$url = add_query_arg( array( 'page' => self::SLUG, 'cco_notice' => 'note' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url . '#cco-order-' . $order_id );
		exit;
	}

	public function handle_submit_tracking() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cco_nonce'] ) ) : '';
		$code     = isset( $_POST['tracking_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tracking_code'] ) ) ) : '';
		$provider = isset( $_POST['provider_url'] ) ? esc_url_raw( wp_unslash( $_POST['provider_url'] ) ) : '';
		$allowed  = array(
			'https://tracking.post.ir/',
			'https://tipaxco.com/tracking',
			'https://mahex.com/tracking',
		);

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'company_orders_submit_tracking_' . $order_id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'company-central-orders' ), 400 );
		}
		if ( '' === $code || strlen( $code ) > 200 || ! in_array( $provider, $allowed, true ) ) {
			wp_die( esc_html__( 'کد رهگیری یا ارائه‌دهنده معتبر نیست.', 'company-central-orders' ), 422 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) || ! Company_Central_Orders_Access::can_modify_orders( $order ) ) {
			wp_die( esc_html__( 'سفارش همگام‌شده پیدا نشد.', 'company-central-orders' ), 404 );
		}
		if ( ! has_action( 'company_order_sync_queue_tracking' ) ) {
			wp_die( esc_html__( 'نسخه سازگار Company Order Sync فعال نیست.', 'company-central-orders' ), 503 );
		}

		$order->update_meta_data( '_company_tracking_code', $code );
		$order->update_meta_data( '_company_tracking_provider_url', $provider );
		$order->update_meta_data( '_company_tracking_sync_status', 'pending' );
		$order->delete_meta_data( '_company_tracking_last_error' );
		$order->save_meta_data();
		do_action( 'company_order_sync_queue_tracking', $order_id, $code, $provider );

		$url = add_query_arg( array( 'page' => self::SLUG, 'cco_notice' => 'tracking' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url . '#cco-order-' . $order_id );
		exit;
	}

	private function payment_methods() {
		$methods = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				$methods[ sanitize_key( $gateway->id ) ] = wp_strip_all_tags( $gateway->get_title() ?: $gateway->get_method_title() );
			}
		}
		return apply_filters( 'company_central_orders_payment_methods', $methods );
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

	private function source_data( WC_Order $order ) {
		$store_id = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$stores   = apply_filters( 'company_order_sync_registered_stores', array() );
		return array(
			'id'     => absint( $order->get_meta( '_company_source_order_id', true ) ),
			'number' => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: absint( $order->get_meta( '_company_source_order_id', true ) ),
			'url'    => esc_url_raw( $order->get_meta( '_company_source_url', true ) ),
			'label'  => isset( $stores[ $store_id ] ) ? $stores[ $store_id ] : $store_id,
		);
	}

	private function status_badge( $status ) {
		return '<span class="cco-status cco-status-' . esc_attr( $status ) . '">' . esc_html( wc_get_order_status_name( $status ) ) . '</span>';
	}

	private function sync_badge( $status ) {
		$labels = array( 'synced' => 'همگام', 'pending' => 'در صف', 'failed' => 'ناموفق', 'conflict' => 'تعارض' );
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="cco-sync cco-sync-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	private function status_slugs( array $statuses ) {
		return array_map(
			static function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( $statuses )
		);
	}
}
