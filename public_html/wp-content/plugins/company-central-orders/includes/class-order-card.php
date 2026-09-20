<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Order_Card {

	public static function render( WC_Order $order ) {
		$source       = self::source_data( $order );
		$name         = trim( $order->get_formatted_billing_full_name() ) ?: 'بدون نام';
		$created      = $order->get_date_created();
		$sync_status  = sanitize_key( $order->get_meta( '_company_sync_status', true ) ) ?: 'pending';
		$department   = apply_filters( 'company_central_orders_department', 'فروش', $order );
		$shipping     = $order->get_shipping_method() ?: 'تعیین نشده';
		$delivery     = Company_Central_Orders_Delivery_Data::display( $order );
		$customer_note = trim( (string) $order->get_customer_note() );

		echo '<article class="cco-order-card" id="cco-order-' . esc_attr( $order->get_id() ) . '">';
		echo '<header class="cco-order-card__header">';
		echo '<div class="cco-order-identity"><label class="cco-order-selector" title="انتخاب سفارش برای خروجی"><input class="cco-order-checkbox" type="checkbox" form="cco-bulk-export-form" name="order_ids[]" value="' . esc_attr( $order->get_id() ) . '" aria-label="انتخاب سفارش ' . esc_attr( $source['number'] ) . '"></label><span class="cco-order-store">' . esc_html( $source['label'] ) . '</span><strong>سفارش مبدأ #' . esc_html( $source['number'] ) . '</strong><small>Central #' . esc_html( $order->get_order_number() ) . '</small></div>';
		echo '<div class="cco-order-header-meta"><span>ثبت: <strong>' . esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $created ) ) . '</strong></span><span class="cco-delivery-date">تاریخ تحویل: <strong>' . esc_html( $delivery ) . '</strong></span><span>بخش: <strong>' . esc_html( $department ) . '</strong></span><span>ارسال: <strong>' . esc_html( $shipping ) . '</strong></span></div>';
		echo '<div class="cco-order-header-state">' . self::status_badge( $order->get_status() ) . self::sync_badge( $sync_status ) . '</div>';
		echo '</header>';

		echo '<div class="cco-order-card__body">';
		self::render_main( $order, $name, $customer_note );
		self::render_activity( $order );
		self::render_actions( $order, $source );
		echo '</div></article>';
	}

	private static function render_main( WC_Order $order, $name, $customer_note ) {
		$billing  = $order->get_formatted_billing_address();
		$shipping = $order->get_formatted_shipping_address();
		$address  = $shipping ?: $billing;

		echo '<section class="cco-order-main">';
		echo '<div class="cco-customer-grid">';
		self::data_cell( 'نام مشتری', $name );
		self::data_cell( 'شماره تماس', $order->get_billing_phone() ?: '—', true );
		self::data_cell( 'ایمیل', $order->get_billing_email() ?: '—', true );
		self::data_cell( 'روش پرداخت', $order->get_payment_method_title() ?: '—' );
		echo '<div class="cco-data-cell cco-data-cell--wide"><span>آدرس تحویل</span><strong class="cco-address-inline">' . ( $address ? wp_kses_post( $address ) : '—' ) . '</strong></div>';
		echo '</div>';

		self::render_items( $order );
		self::render_totals( $order );
		self::render_operations( $order );

		if ( $customer_note ) {
			echo '<div class="cco-customer-note"><strong>یادداشت مشتری:</strong> ' . nl2br( esc_html( $customer_note ) ) . '</div>';
		}
		echo '</section>';
	}

	private static function render_items( WC_Order $order ) {
		echo '<div class="cco-order-items"><table><thead><tr><th>کد / SKU</th><th>محصول</th><th>تأمین</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody>';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$quantity      = max( 1, (int) $item->get_quantity() );
			$subtotal      = (float) $item->get_subtotal();
			$total         = (float) $item->get_total();
			$unit_price    = $subtotal / $quantity;
			$discount      = max( 0, $subtotal - $total );
			$sku           = sanitize_text_field( $item->get_meta( '_company_source_sku', true ) ) ?: '—';
			$source_line   = absint( $item->get_meta( '_company_source_line_item_id', true ) );
			$assigned_seller = sanitize_text_field( $item->get_meta( '_company_assigned_seller_name', true ) );
			$agent         = $assigned_seller ?: sanitize_text_field( $item->get_meta( '_company_tamin_agent_name', true ) );
			$supplier      = $assigned_seller ? '' : sanitize_text_field( $item->get_meta( '_company_tamin_supplier_name', true ) );
			$tamin_status  = sanitize_key( $item->get_meta( '_company_tamin_status', true ) );
			$need_id       = sanitize_text_field( $item->get_meta( '_company_tamin_need_id', true ) );

			echo '<tr>';
			echo '<td class="cco-item-code"><strong dir="ltr">' . esc_html( $sku ) . '</strong>' . ( $source_line ? '<small>Line #' . esc_html( $source_line ) . '</small>' : '' ) . '</td>';
			echo '<td class="cco-item-name"><strong>' . esc_html( $item->get_name() ) . '</strong></td>';
			echo '<td>' . self::supply_cell( $agent, $supplier, $tamin_status, $need_id, (bool) $assigned_seller ) . '</td>';
			echo '<td>' . esc_html( $item->get_quantity() ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $unit_price, array( 'currency' => $order->get_currency() ) ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $discount, array( 'currency' => $order->get_currency() ) ) ) . '</td>';
			echo '<td><strong>' . wp_kses_post( wc_price( $total + (float) $item->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ) . '</strong></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function supply_cell( $agent, $supplier, $status, $need_id, $is_product_seller = false ) {
		if ( ! $agent ) {
			return '<span class="cco-supply-state cco-supply-state--unassigned">تخصیص ثبت نشده</span>';
		}

		$labels = array(
			'sourcing'       => 'در حال تأمین',
			'price_approval' => 'بررسی قیمت',
			'purchased'      => 'خریداری‌شده',
			'warehouse_qc'   => 'تحویل انبار',
			'completed'      => 'تکمیل‌شده',
			'conflict'       => 'تداخل تخصیص',
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : ( $status ?: 'منتسب‌شده' );
		$html  = '<div class="cco-supply"><strong>Agent: ' . esc_html( $agent ) . '</strong>';
		if ( ! $is_product_seller ) {
			$html .= '<span>' . esc_html( $supplier ? 'تأمین‌کننده: ' . $supplier : 'تأمین‌کننده تعیین نشده' ) . '</span>';
		}
		$html .= '<small>' . esc_html( $label . ( $need_id ? ' · ' . $need_id : '' ) ) . '</small></div>';

		return $html;
	}

	private static function render_totals( WC_Order $order ) {
		echo '<div class="cco-order-totals">';
		echo '<span>روش ارسال: <strong>' . esc_html( $order->get_shipping_method() ?: 'تعیین نشده' ) . '</strong></span>';
		echo '<span>هزینه ارسال: <strong>' . wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ) . '</strong></span>';
		if ( (float) $order->get_discount_total() > 0 ) {
			echo '<span class="cco-order-discount">تخفیف: <strong>' . wp_kses_post( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ) . '</strong></span>';
		}
		echo '<span class="cco-order-grand-total">مجموع سفارش: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></span>';
		echo '</div>';
	}

	private static function render_operations( WC_Order $order ) {
		echo '<div class="cco-order-operations">';
		echo '<div class="cco-order-operation cco-order-operation--status"><h3>تغییر وضعیت سفارش</h3>';
		self::render_status_form( $order );
		echo '</div>';
		self::render_tracking_form( $order );
		echo '</div>';
	}

	private static function render_tracking_form( WC_Order $order ) {
		$code      = sanitize_text_field( $order->get_meta( '_company_tracking_code', true ) );
		$provider  = esc_url_raw( $order->get_meta( '_company_tracking_provider_url', true ) ) ?: 'https://tracking.post.ir/';
		$status    = sanitize_key( $order->get_meta( '_company_tracking_sync_status', true ) );
		$error     = sanitize_text_field( $order->get_meta( '_company_tracking_last_error', true ) );
		$providers = array(
			'https://tracking.post.ir/'      => 'شرکت ملی پست',
			'https://tipaxco.com/tracking'   => 'تیپاکس',
			'https://mahex.com/tracking'     => 'ماهکس',
		);

		echo '<div class="cco-order-operation cco-order-operation--tracking"><div class="cco-operation-title"><h3>ثبت کد رهگیری و ارسال پیامک</h3>' . ( $status ? '<span class="cco-tracking-status cco-tracking-status--' . esc_attr( $status ) . '">' . esc_html( self::tracking_status_label( $status ) ) . '</span>' : '' ) . '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cco-tracking-form">';
		echo '<input type="hidden" name="action" value="company_orders_submit_tracking"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( 'company_orders_submit_tracking_' . $order->get_id(), '_cco_nonce' );
		echo '<label for="cco-tracking-code-' . esc_attr( $order->get_id() ) . '">کد رهگیری<input id="cco-tracking-code-' . esc_attr( $order->get_id() ) . '" name="tracking_code" type="text" maxlength="200" required value="' . esc_attr( $code ) . '" placeholder="کد رهگیری مرسوله"></label>';
		echo '<label for="cco-tracking-provider-' . esc_attr( $order->get_id() ) . '">ارائه‌دهنده<select id="cco-tracking-provider-' . esc_attr( $order->get_id() ) . '" name="provider_url">';
		foreach ( $providers as $url => $label ) {
			echo '<option value="' . esc_attr( $url ) . '" ' . selected( $provider, $url, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><button class="button button-primary" type="submit">ثبت و ارسال پیامک</button></form>';
		if ( 'failed' === $status && $error ) {
			echo '<p class="cco-tracking-error">' . esc_html( $error ) . '</p>';
		}
		echo '</div>';
	}

	private static function tracking_status_label( $status ) {
		$labels = array(
			'pending' => 'در صف ارسال',
			'synced'  => 'ثبت و ارسال شد',
			'failed'  => 'ارسال ناموفق',
		);
		return $labels[ $status ] ?? $status;
	}

	private static function render_activity( WC_Order $order ) {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 3,
				'orderby'  => 'date_created_gmt',
				'order'    => 'DESC',
			)
		);

		echo '<section class="cco-order-activity">';
		echo '<div class="cco-payment-summary"><strong>' . esc_html( $order->get_payment_method_title() ?: 'روش پرداخت نامشخص' ) . '</strong><span>شناسه تراکنش</span><code dir="ltr">' . esc_html( $order->get_transaction_id() ?: '—' ) . '</code></div>';
		echo '<div class="cco-recent-notes"><h3>آخرین رویدادها</h3>';
		if ( ! $notes ) {
			echo '<p class="cco-muted">هنوز یادداشتی ثبت نشده است.</p>';
		} else {
			foreach ( $notes as $note ) {
				echo '<div class="cco-mini-note"><div>' . wp_kses_post( $note->content ) . '</div><small>' . esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $note->date_created ) ) . '</small></div>';
			}
		}
		echo '</div>';
		self::render_note_form( $order );
		echo '</section>';
	}

	private static function render_note_form( WC_Order $order ) {
		echo '<form class="cco-note-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="company_orders_add_note"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( 'company_orders_add_note_' . $order->get_id(), '_cco_nonce' );
		echo '<label for="cco-note-' . esc_attr( $order->get_id() ) . '">یادداشت داخلی</label><textarea id="cco-note-' . esc_attr( $order->get_id() ) . '" name="note" rows="3" maxlength="2000" placeholder="یادداشت مربوط به پیگیری سفارش…"></textarea>';
		echo '<button class="button button-primary" type="submit">ثبت یادداشت</button></form>';
	}

	private static function render_actions( WC_Order $order, array $source ) {
		echo '<aside class="cco-order-actions">';
		echo '<div class="cco-action-summary"><span>وضعیت</span>' . self::status_badge( $order->get_status() ) . '<strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></div>';
		echo '<div class="cco-print-actions">';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::print_url( $order, 'invoice' ) ) . '">چاپ فاکتور</a>';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::print_url( $order, 'delivery' ) ) . '">چاپ برگه تحویل</a>';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::print_url( $order, 'label' ) ) . '">چاپ لیبل</a>';
		echo '</div>';

		$details_url = add_query_arg( array( 'page' => Company_Central_Orders_Admin_Page::SLUG, 'order_id' => $order->get_id() ), admin_url( 'admin.php' ) );
		echo '<a class="button cco-details-button" href="' . esc_url( $details_url ) . '">جزئیات و تاریخچه کامل</a>';
		if ( $source['url'] ) {
			echo '<a class="cco-source-link" target="_blank" rel="noopener" href="' . esc_url( $source['url'] ) . '">باز کردن فروشگاه مبدأ</a>';
		}
		echo '</aside>';
	}

	private static function render_status_form( WC_Order $order ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cco-card-status-form">';
		echo '<input type="hidden" name="action" value="company_orders_update_status"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="return_order_id" value="0">';
		wp_nonce_field( 'company_orders_update_status_' . $order->get_id(), '_cco_nonce' );
		echo '<label for="cco-status-' . esc_attr( $order->get_id() ) . '">تغییر وضعیت سفارش</label><select id="cco-status-' . esc_attr( $order->get_id() ) . '" name="new_status">';
		foreach ( Company_Central_Orders_Access::allowed_statuses( $order ) as $key => $label ) {
			$slug = str_replace( 'wc-', '', $key );
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $order->get_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button class="button button-primary" type="submit">ثبت وضعیت</button></form>';
	}

	private static function print_url( WC_Order $order, $document ) {
		$url = add_query_arg(
			array(
				'action'   => 'company_orders_print',
				'order_id' => $order->get_id(),
				'document' => $document,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'company_orders_print_' . $order->get_id() . '_' . $document, '_cco_nonce' );
	}

	private static function data_cell( $label, $value, $ltr = false ) {
		echo '<div class="cco-data-cell"><span>' . esc_html( $label ) . '</span><strong' . ( $ltr ? ' dir="ltr"' : '' ) . '>' . esc_html( $value ) . '</strong></div>';
	}

	private static function source_data( WC_Order $order ) {
		$store_id = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$stores   = apply_filters( 'company_order_sync_registered_stores', array() );
		return array(
			'id'     => absint( $order->get_meta( '_company_source_order_id', true ) ),
			'number' => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: absint( $order->get_meta( '_company_source_order_id', true ) ),
			'url'    => esc_url_raw( $order->get_meta( '_company_source_url', true ) ),
			'label'  => isset( $stores[ $store_id ] ) ? $stores[ $store_id ] : $store_id,
		);
	}

	private static function status_badge( $status ) {
		return '<span class="cco-status cco-status-' . esc_attr( $status ) . '">' . esc_html( wc_get_order_status_name( $status ) ) . '</span>';
	}

	private static function sync_badge( $status ) {
		$labels = array( 'synced' => 'همگام', 'pending' => 'در صف Sync', 'failed' => 'Sync ناموفق', 'conflict' => 'تعارض Sync' );
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="cco-sync cco-sync-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}
}
