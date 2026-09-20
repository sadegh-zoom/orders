<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$installment_fee = 0;
foreach ( $order->get_items( 'fee' ) as $fee ) {
	$fee_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $fee->get_name(), 'UTF-8' ) : strtolower( $fee->get_name() );
	if ( false !== strpos( $fee_name, 'اقساط' ) || false !== strpos( $fee_name, 'installment' ) ) $installment_fee += (float) $fee->get_total() + (float) $fee->get_total_tax();
}
?>
<section class="cco-print-page cco-zoom-invoice">
	<header class="cco-zoom-head">
		<div class="cco-zoom-brand">
			<?php if ( $profile['logo_url'] ) : ?><img src="<?php echo esc_url( $profile['logo_url'] ); ?>" alt="<?php echo esc_attr( $profile['seller_name'] ); ?>"><?php endif; ?>
			<div><h1><?php echo esc_html( $profile['seller_name'] ); ?></h1><p>فاکتور فروش کالا</p></div>
		</div>
		<div class="cco-zoom-order"><span>شماره سفارش</span><strong><?php echo esc_html( $source['number'] ); ?></strong><small>تاریخ ثبت: <?php echo esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $order->get_date_created() ) ); ?></small></div>
	</header>
	<div class="cco-zoom-title">مشخصات فروشنده</div>
	<section class="cco-zoom-party">
		<div><span>نام فروشنده</span><strong><?php echo esc_html( $profile['seller_name'] ); ?></strong></div>
		<div><span>تلفن</span><strong dir="ltr"><?php echo esc_html( $profile['phone'] ?: '—' ); ?></strong></div>
		<div><span>کد اقتصادی</span><strong><?php echo esc_html( $profile['economic_code'] ?: '—' ); ?></strong></div>
		<div><span>شماره ثبت</span><strong><?php echo esc_html( $profile['registration_number'] ?: '—' ); ?></strong></div>
		<div class="wide"><span>نشانی</span><strong><?php echo esc_html( $profile['address'] ?: '—' ); ?><?php echo $profile['postcode'] ? esc_html( ' · کدپستی ' . $profile['postcode'] ) : ''; ?></strong></div>
	</section>
	<div class="cco-zoom-title">مشخصات خریدار</div>
	<section class="cco-zoom-party">
		<div><span>نام و نام خانوادگی</span><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></strong></div>
		<div><span>شماره تماس</span><strong dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></strong></div>
		<div><span>روش پرداخت</span><strong><?php echo esc_html( $order->get_payment_method_title() ?: '—' ); ?></strong></div>
		<div><span>تاریخ تحویل</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></strong></div>
		<div class="wide"><span>نشانی تحویل</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></strong></div>
	</section>
	<?php include COMPANY_CENTRAL_ORDERS_PATH . 'templates/partials/items-priced.php'; ?>
	<section class="cco-zoom-summary">
		<div><span>روش ارسال</span><strong><?php echo esc_html( $order->get_shipping_method() ?: '—' ); ?></strong></div>
		<div><span>هزینه ارسال</span><strong><?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
		<div><span>تخفیف</span><strong><?php echo wp_kses_post( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
		<?php if ( $installment_fee > 0 ) : ?><div><span>افزوده بابت اقساط</span><strong><?php echo wp_kses_post( wc_price( $installment_fee, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><?php endif; ?>
		<div class="grand"><span>جمع کل</span><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
	</section>
	<?php if ( $order->get_customer_note() ) : ?><p class="cco-zoom-note"><strong>یادداشت مشتری:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></p><?php endif; ?>
	<footer class="cco-zoom-sign"><div>مهر و امضای فروشنده</div><div>نام و امضای خریدار</div></footer>
</section>
