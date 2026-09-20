<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="cco-print-page cco-default-document">
	<header class="cco-print-header"><div><h1>برگه تحویل سفارش</h1><p><?php echo esc_html( $source['label'] ); ?></p></div><div class="cco-print-order-no"><span>سفارش مبدأ</span><strong>#<?php echo esc_html( $source['number'] ); ?></strong><small>Central #<?php echo esc_html( $order->get_order_number() ); ?></small></div></header>
	<section class="cco-print-grid">
		<div><span>نام تحویل‌گیرنده</span><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></strong></div>
		<div><span>شماره تماس</span><strong dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></strong></div>
		<div><span>روش ارسال</span><strong><?php echo esc_html( $order->get_shipping_method() ?: '—' ); ?></strong></div>
		<div><span>تاریخ تحویل برنامه‌ریزی‌شده</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></strong></div>
		<div class="wide"><span>آدرس</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></strong></div>
	</section>
	<?php include COMPANY_CENTRAL_ORDERS_PATH . 'templates/partials/items-simple.php'; ?>
	<section class="cco-print-notice"><strong>توضیحات تحویل:</strong><div class="cco-writing-lines"></div></section>
	<section class="cco-signatures"><div><span>نام و امضای تحویل‌دهنده</span></div><div><span>نام و امضای تحویل‌گیرنده</span></div><div><span>تاریخ و ساعت تحویل واقعی</span></div></section>
</section>
