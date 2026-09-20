<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="cco-print-page cco-default-document">
	<header class="cco-print-header"><div><h1>لیبل ارسال</h1><p><?php echo esc_html( $source['label'] ); ?></p></div><div class="cco-print-order-no"><span>سفارش مبدأ</span><strong>#<?php echo esc_html( $source['number'] ); ?></strong></div></header>
	<section class="cco-warehouse-label">
		<div><span>گیرنده</span><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></strong></div>
		<div><span>تماس</span><strong dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></strong></div>
		<div class="wide"><span>آدرس</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></strong></div>
		<div><span>روش ارسال</span><strong><?php echo esc_html( $order->get_shipping_method() ?: 'تعیین نشده' ); ?></strong></div>
		<div><span>تاریخ تحویل</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></strong></div>
	</section>
	<?php include COMPANY_CENTRAL_ORDERS_PATH . 'templates/partials/items-simple.php'; ?>
	<div class="cco-warehouse-footer"><span>کنترل انبار</span><span>بسته‌بندی</span><span>خروج</span></div>
</section>
