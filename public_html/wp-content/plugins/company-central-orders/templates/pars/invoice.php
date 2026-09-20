<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$source_number = $source['number'];
$barcode       = Company_Central_Orders_Barcode::data_uri( $source_number );
$created       = $order->get_date_created();
$customer_name = $order->get_formatted_billing_full_name() ?: 'بدون نام';
$customer_addr = Company_Central_Orders_Delivery_Data::formatted_address( $order );
$state         = Company_Central_Orders_Delivery_Data::state_name( $order );
$is_zoombazar  = 'site1' === $source['store_id'];
$is_smartpishro = 'site2' === $source['store_id'];
$logo_url      = $profile['logo_url'];
if ( ! $logo_url && $is_zoombazar ) {
	$logo_url = plugins_url( 'assets/zoombazar-logo.svg', COMPANY_CENTRAL_ORDERS_FILE );
} elseif ( ! $logo_url && $is_smartpishro ) {
	$logo_url = plugins_url( 'assets/smartpishro-logo.svg', COMPANY_CENTRAL_ORDERS_FILE );
}
$installment_fee = 0;
foreach ( $order->get_items( 'fee' ) as $fee ) {
	$fee_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $fee->get_name(), 'UTF-8' ) : strtolower( $fee->get_name() );
	if ( false !== strpos( $fee_name, 'اقساط' ) || false !== strpos( $fee_name, 'installment' ) ) {
		$installment_fee += (float) $fee->get_total() + (float) $fee->get_total_tax();
	}
}
?>
<section class="cco-print-page cco-pars-page cco-pars-invoice">
	<div class="min-factor" dir="rtl">
		<header class="header-factor"><div class="flex-center">
			<div class="factor-logo"><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $profile['seller_name'] ); ?>"><?php else : ?><strong><?php echo esc_html( $profile['seller_name'] ); ?></strong><?php endif; ?></div>
			<div class="factor-title"><span class="status on-processing">فاکتور</span></div>
			<div class="factor-dates"><span class="factor-number">شماره فاکتور: <b><?php echo esc_html( $source_number ); ?></b></span><?php if ( $barcode ) : ?><span class="factor-barcode"><img src="<?php echo esc_attr( $barcode ); ?>" alt="<?php echo esc_attr( $source_number ); ?>"></span><?php endif; ?><span class="factor-date_times">تاریخ سفارش: <i><?php echo esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $created, false ) ); ?></i></span></div>
		</div></header>
		<div class="pars-factor-main"><section class="main-factor"><div class="order-seller">
			<table class="tab-order"><thead><tr><th class="tab-head seller">فروشنده</th><th class="tab-head shoper">خریدار</th></tr></thead><tbody><tr>
				<td class="tab-main seller"><span class="mleft"><i>فروشنده:</i> <?php echo esc_html( $profile['seller_name'] ); ?></span><?php if ( $profile['postcode'] ) : ?><span><i>کد پستی:</i> <?php echo esc_html( $profile['postcode'] ); ?></span><?php endif; ?><?php if ( $profile['phone'] ) : ?><span><i>تلفن:</i> <b dir="ltr"><?php echo esc_html( $profile['phone'] ); ?></b></span><?php endif; ?><?php if ( $profile['economic_code'] ) : ?><span><i>کد اقتصادی:</i> <?php echo esc_html( $profile['economic_code'] ); ?></span><?php endif; ?><?php if ( $profile['registration_number'] ) : ?><span><i>شماره ثبت:</i> <?php echo esc_html( $profile['registration_number'] ); ?></span><?php endif; ?><span class="bloked"><i>نشانی:</i> <?php echo esc_html( $profile['address'] ?: '—' ); ?></span></td>
				<td class="tab-main shoper"><span class="mleft"><i>خریدار:</i> <?php echo esc_html( $customer_name ); ?></span><span><i>استان:</i> <?php echo esc_html( $state ?: '—' ); ?></span><span><i>شهر:</i> <?php echo esc_html( $order->get_shipping_city() ?: $order->get_billing_city() ?: '—' ); ?></span><span><i>کد پستی:</i> <?php echo esc_html( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ?: '—' ); ?></span><span><i>شماره تماس:</i> <b dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></b></span><?php if ( $order->get_billing_email() ) : ?><span><i>ایمیل:</i> <?php echo esc_html( $order->get_billing_email() ); ?></span><?php endif; ?><span class="bloked"><i>نشانی:</i> <?php echo esc_html( $customer_addr ); ?></span></td>
			</tr></tbody></table>
			<table class="order-dates"><thead><tr class="head-dates"><th width="7%">ردیف</th><th>شرح کالا یا خدمات</th><th width="8%">تعداد</th><th width="17%">مبلغ واحد</th><th width="17%">مبلغ کل</th></tr></thead><tbody>
			<?php $row = 0; foreach ( $order->get_items( 'line_item' ) as $item ) : ++$row; $qty = max( 1, (int) $item->get_quantity() ); $total = (float) $item->get_total() + (float) $item->get_total_tax(); ?>
				<tr class="head-dates while"><td><?php echo esc_html( $row ); ?></td><td class="alin-right"><div class="flexed_start"><?php echo esc_html( $item->get_name() ); ?></div></td><td><?php echo esc_html( $item->get_quantity() ); ?></td><td class="item-vs prk-price"><?php echo wp_kses_post( wc_price( $total / $qty, array( 'currency' => $order->get_currency() ) ) ); ?></td><td><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
			<?php endforeach; ?>
				<tr class="head-dates grye"><th colspan="3" class="alin-right">روش پرداخت: <?php echo esc_html( $order->get_payment_method_title() ?: '—' ); ?></th><th>تخفیف</th><th><?php echo wp_kses_post( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ); ?></th></tr>
				<tr class="head-dates grye"><th colspan="3" class="alin-right">روش ارسال: <?php echo esc_html( $order->get_shipping_method() ?: '—' ); ?></th><th>هزینه ارسال</th><th><?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></th></tr>
				<?php if ( $installment_fee > 0 ) : ?><tr class="head-dates grye"><th colspan="3" class="alin-right">هزینه مربوط به روش پرداخت اقساطی</th><th>افزوده بابت اقساط</th><th><?php echo wp_kses_post( wc_price( $installment_fee, array( 'currency' => $order->get_currency() ) ) ); ?></th></tr><?php endif; ?>
				<tr class="head-dates grye delivery-row"><th colspan="3" class="alin-right">تاریخ تحویل: <?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></th><th>مبلغ قابل پرداخت</th><th><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></th></tr>
			</tbody></table>
			<?php if ( $order->get_customer_note() ) : ?><div class="factor-note"><strong>یادداشت مشتری:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div><?php endif; ?>
			<div class="factor-thanks">لبخند رضایتتان را به دنیا نمی‌دهیم</div>
		</div></section></div>
	</div>
</section>
