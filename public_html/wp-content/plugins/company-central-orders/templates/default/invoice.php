<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$installment_fee = 0;
foreach ( $order->get_items( 'fee' ) as $fee ) {
	$fee_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $fee->get_name(), 'UTF-8' ) : strtolower( $fee->get_name() );
	if ( false !== strpos( $fee_name, 'اقساط' ) || false !== strpos( $fee_name, 'installment' ) ) $installment_fee += (float) $fee->get_total() + (float) $fee->get_total_tax();
}
?>
<section class="cco-print-page cco-default-document">
	<header class="cco-print-header">
		<div><h1>فاکتور فروش</h1><p><?php echo esc_html( $profile['seller_name'] ?: get_bloginfo( 'name' ) ); ?></p></div>
		<div class="cco-print-order-no"><span>شماره سفارش مبدأ</span><strong>#<?php echo esc_html( $source['number'] ); ?></strong><small><?php echo esc_html( $source['label'] ); ?> · Central #<?php echo esc_html( $order->get_order_number() ); ?></small></div>
	</header>
	<section class="cco-print-grid">
		<div><span>نام مشتری</span><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></strong></div>
		<div><span>شماره تماس</span><strong dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></strong></div>
		<div><span>روش پرداخت</span><strong><?php echo esc_html( $order->get_payment_method_title() ?: '—' ); ?></strong></div>
		<div><span>تاریخ تحویل</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></strong></div>
		<div class="wide"><span>آدرس</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></strong></div>
	</section>
	<?php include COMPANY_CENTRAL_ORDERS_PATH . 'templates/partials/items-priced.php'; ?>
	<section class="cco-print-totals"><div><span>تخفیف</span><strong><?php echo wp_kses_post( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><div><span>هزینه ارسال</span><strong><?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><?php if ( $installment_fee > 0 ) : ?><div><span>افزوده بابت اقساط</span><strong><?php echo wp_kses_post( wc_price( $installment_fee, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><?php endif; ?><div class="grand"><span>مبلغ قابل پرداخت</span><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div></section>
	<section class="cco-signatures"><div><span>مهر و امضای فروشنده</span></div><div><span>نام و امضای تحویل‌گیرنده</span></div></section>
</section>
