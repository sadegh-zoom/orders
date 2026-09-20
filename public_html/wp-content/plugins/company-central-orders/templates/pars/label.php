<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$source_number = $source['number'];
$barcode       = Company_Central_Orders_Barcode::data_uri( $source_number );
$created       = $order->get_date_created();
$is_zoombazar  = 'site1' === $source['store_id'];
$is_smartpishro = 'site2' === $source['store_id'];
$logo_url      = $profile['logo_url'];
if ( ! $logo_url && $is_zoombazar ) {
	$logo_url = plugins_url( 'assets/zoombazar-logo.svg', COMPANY_CENTRAL_ORDERS_FILE );
} elseif ( ! $logo_url && $is_smartpishro ) {
	$logo_url = plugins_url( 'assets/smartpishro-logo.svg', COMPANY_CENTRAL_ORDERS_FILE );
}
?>
<section class="cco-print-page cco-pars-page cco-pars-label">
	<div class="min-factor" dir="rtl"><div class="pars-factor-main"><section class="prk-label"><div class="order-label"><table class="table-label"><tbody>
		<tr><td class="label-dates"><div class="label-shoper shoper"><span><i>گیرنده:</i> <?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></span><span><i>نام کامل:</i> <?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></span><span><i>کد پستی:</i> <?php echo esc_html( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ?: '—' ); ?></span><span><i>تلفن:</i> <b dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></b></span><span><i>تاریخ سفارش:</i> <?php echo esc_html( Company_Central_Orders_Jalali_Date::format_datetime( $created, false ) ); ?></span><span><i>تاریخ تحویل:</i> <?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></span><?php if ( $order->get_customer_note() ) : ?><span><i>یادداشت:</i> <?php echo esc_html( $order->get_customer_note() ); ?></span><?php endif; ?><?php if ( $barcode ) : ?><span class="align-senter barcode"><img src="<?php echo esc_attr( $barcode ); ?>" alt="<?php echo esc_attr( $source_number ); ?>"><b><?php echo esc_html( $source_number ); ?></b></span><?php endif; ?></div></td>
		<td class="label-dates"><div class="label-shoper seller"><?php if ( $logo_url ) : ?><span class="label-logo"><img src="<?php echo esc_url( $logo_url ); ?>" width="132" alt="<?php echo esc_attr( $profile['seller_name'] ); ?>"></span><?php endif; ?><h2><?php echo esc_html( $profile['seller_name'] ); ?></h2><span><i>آدرس:</i> <?php echo esc_html( $profile['address'] ?: '—' ); ?></span><?php if ( $profile['postcode'] ) : ?><span><i>کد پستی:</i> <?php echo esc_html( $profile['postcode'] ); ?></span><?php endif; ?><span><i>تلفن:</i> <b dir="ltr"><?php echo esc_html( $profile['phone'] ?: '—' ); ?></b></span><?php if ( $profile['email'] ) : ?><span><i>ایمیل:</i> <?php echo esc_html( $profile['email'] ); ?></span><?php endif; ?><?php if ( $profile['website'] ) : ?><span><i>وب‌سایت:</i> <?php echo esc_html( $profile['website'] ); ?></span><?php endif; ?></div></td></tr>
		<tr><td class="label-orders" colspan="2"><div class="flex-labels"><span><i>روش حمل‌ونقل:</i> <?php echo esc_html( $order->get_shipping_method() ?: '—' ); ?></span><span><i>هزینه ارسال:</i> <?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?></span><span class="label-order-id"><i>شناسه سفارش:</i> <?php echo esc_html( $source_number ); ?></span></div></td></tr>
	</tbody></table></div></section></div></div>
</section>
