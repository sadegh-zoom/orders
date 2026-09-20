<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Print_Controller {

	const MAX_ORDERS = 200;

	public function hooks() {
		add_action( 'admin_post_company_orders_print', array( $this, 'render' ) );
		add_action( 'admin_post_company_orders_bulk_print', array( $this, 'render_bulk' ) );
	}

	public function render() {
		$this->authorize();
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$document = isset( $_GET['document'] ) ? sanitize_key( wp_unslash( $_GET['document'] ) ) : '';
		$nonce    = isset( $_GET['_cco_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_cco_nonce'] ) ) : '';

		if ( ! $order_id || ! in_array( $document, $this->allowed_documents(), true ) || ! wp_verify_nonce( $nonce, 'company_orders_print_' . $order_id . '_' . $document ) ) {
			wp_die( esc_html__( 'درخواست چاپ نامعتبر است.', 'company-central-orders' ), 400 );
		}

		$order = $this->get_synced_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'سفارش همگام‌شده پیدا نشد.', 'company-central-orders' ), 404 );
		}

		$document = 'warehouse' === $document ? 'label' : $document;
		$this->output( array( $order ), $document );
	}

	public function render_bulk() {
		$this->authorize();
		check_admin_referer( 'company_orders_bulk_print', '_cco_print_nonce' );

		$document = isset( $_POST['document'] ) ? sanitize_key( wp_unslash( $_POST['document'] ) ) : '';
		$ids      = isset( $_POST['order_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) ) ) : array();

		if ( ! in_array( $document, $this->allowed_documents(), true ) || ! $ids ) {
			wp_die( esc_html__( 'حداقل یک سفارش و نوع چاپ معتبر انتخاب کنید.', 'company-central-orders' ), 400 );
		}
		if ( count( $ids ) > self::MAX_ORDERS ) {
			wp_die( esc_html( sprintf( 'در هر نوبت حداکثر %d سفارش قابل چاپ است.', self::MAX_ORDERS ) ), 400 );
		}

		$orders = array();
		foreach ( $ids as $id ) {
			$order = $this->get_synced_order( $id );
			if ( $order ) {
				$orders[] = $order;
			}
		}
		if ( ! $orders ) {
			wp_die( esc_html__( 'سفارش همگام‌شده‌ای پیدا نشد.', 'company-central-orders' ), 404 );
		}
		if ( count( $orders ) !== count( $ids ) ) {
			wp_die( 'بعضی سفارش‌های انتخاب‌شده حذف شده‌اند یا قابل دسترسی نیستند. فهرست را تازه‌سازی و دوباره انتخاب کنید؛ چاپ ناقص تولید نشد.', 409 );
		}

		$this->output( $orders, $document );
	}

	private function output( array $orders, $document ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		$this->document_start( $document, count( $orders ) );

		if ( 'warehouse' === $document ) {
			$this->warehouse_list( $orders );
		} else {
			foreach ( $orders as $order ) {
				$this->render_template( $order, $document );
			}
		}

		echo '</main></body></html>';
		exit;
	}

	private function document_start( $document, $count ) {
		$titles = array( 'invoice' => 'فاکتور فروش', 'delivery' => 'برگه تحویل', 'label' => 'لیبل ارسال', 'warehouse' => 'لیست تجمیعی انبار' );
		$page   = 'label' === $document ? 'A5 landscape' : ( 'invoice' === $document ? 'A5 portrait' : 'A4 landscape' );
		echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $titles[ $document ] . ' · ' . $count . ' سفارش' ) . '</title>';
		echo '<style>@media print{@page{size:' . esc_attr( $page ) . ';margin:4mm}}</style>';
		echo '<link rel="stylesheet" href="' . esc_url( plugins_url( 'assets/print.css', COMPANY_CENTRAL_ORDERS_FILE ) ) . '?ver=' . esc_attr( COMPANY_CENTRAL_ORDERS_VERSION ) . '">';
		echo '<link rel="stylesheet" href="' . esc_url( plugins_url( 'assets/pars-factor.css', COMPANY_CENTRAL_ORDERS_FILE ) ) . '?ver=' . esc_attr( COMPANY_CENTRAL_ORDERS_VERSION ) . '">';
		echo '<link rel="stylesheet" href="' . esc_url( plugins_url( 'assets/print-page-size.css', COMPANY_CENTRAL_ORDERS_FILE ) ) . '?ver=' . esc_attr( COMPANY_CENTRAL_ORDERS_VERSION ) . '"></head><body class="cco-print cco-print-' . esc_attr( $document ) . '"><main>';
		echo '<div class="cco-print-toolbar"><button type="button" onclick="window.print()">چاپ</button><button type="button" onclick="window.close()">بستن</button><span>' . esc_html( $count ) . ' سفارش</span></div>';
	}

	private function render_template( WC_Order $order, $document ) {
		$source  = $this->source_data( $order );
		$profile = Company_Central_Orders_Print_Settings::profile( $source['store_id'] );
		$variant = in_array( $document, array( 'invoice', 'label' ), true ) ? 'pars' : 'default';
		$file    = COMPANY_CENTRAL_ORDERS_PATH . 'templates/' . $variant . '/' . $document . '.php';

		if ( ! is_readable( $file ) ) {
			$file = COMPANY_CENTRAL_ORDERS_PATH . 'templates/default/' . $document . '.php';
		}
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	private function warehouse_list( array $orders ) {
		$groups = array();
		foreach ( $orders as $order ) {
			$source = $this->source_data( $order );
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$sku   = sanitize_text_field( $item->get_meta( '_company_source_sku', true ) );
				$key   = $sku ? 'sku:' . strtolower( $sku ) : 'name:' . md5( $item->get_name() );
				$agent = sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) ?: $item->get_meta( '_company_tamin_agent_name', true ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array( 'name' => $item->get_name(), 'sku' => $sku, 'quantity' => 0, 'orders' => array(), 'agents' => array() );
				}
				$groups[ $key ]['quantity'] += (float) $item->get_quantity();
				$groups[ $key ]['orders'][]  = $source['number'] . ' · ' . $source['label'];
				if ( $agent ) {
					$groups[ $key ]['agents'][] = $agent;
				}
			}
		}

		echo '<section class="cco-print-page cco-warehouse-list"><header class="cco-print-header"><div><h1>لیست تجمیعی انبار</h1><p>جمع اقلام سفارش‌های انتخاب‌شده</p></div><div class="cco-print-order-no"><span>تعداد سفارش</span><strong>' . esc_html( count( $orders ) ) . '</strong><small>' . esc_html( Company_Central_Orders_Jalali_Date::format_timestamp( time() ) ) . '</small></div></header>';
		echo '<table class="cco-print-items"><thead><tr><th>ردیف</th><th>SKU</th><th>نام کالا</th><th>تعداد کل</th><th>سفارش‌ها</th><th>Agent تأمین</th><th>کنترل</th></tr></thead><tbody>';
		$row = 0;
		foreach ( $groups as $group ) {
			++$row;
			echo '<tr><td>' . esc_html( $row ) . '</td><td dir="ltr">' . esc_html( $group['sku'] ?: '—' ) . '</td><td><strong>' . esc_html( $group['name'] ) . '</strong></td><td><strong>' . esc_html( wc_stock_amount( $group['quantity'] ) ) . '</strong></td><td>' . esc_html( implode( '، ', array_unique( $group['orders'] ) ) ) . '</td><td>' . esc_html( $group['agents'] ? implode( '، ', array_unique( $group['agents'] ) ) : 'تخصیص ثبت نشده' ) . '</td><td class="cco-check-box">□</td></tr>';
		}
		echo '</tbody></table><footer class="cco-zoom-label-footer"><span>کنترل‌کننده:</span><span>تاریخ و ساعت:</span><span>امضا:</span></footer></section>';
	}

	private function source_data( WC_Order $order ) {
		$store_id = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$source_id = absint( $order->get_meta( '_company_source_order_id', true ) );
		return array(
			'store_id'       => $store_id,
			'number'         => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: $source_id,
			'label'          => Company_Central_Orders_Print_Settings::store_label( $store_id ),
			'central_number' => $order->get_order_number(),
		);
	}

	private function get_synced_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'shop_order' !== $order->get_type() || ! $order->get_meta( '_company_source_store', true ) || ! Company_Central_Orders_Access::order_visible_to_current_user( $order ) ) {
			return null;
		}
		return $order;
	}

	private function allowed_documents() {
		return array( 'invoice', 'delivery', 'label', 'warehouse' );
	}

	private function authorize() {
		if ( ! current_user_can( Company_Central_Orders_Access::CAP ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-central-orders' ), 403 );
		}
	}
}
