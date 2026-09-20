<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="cco-print-page cco-zoom-label">
	<header class="cco-zoom-label-head">
		<div><?php if ( $profile['logo_url'] ) : ?><img src="<?php echo esc_url( $profile['logo_url'] ); ?>" alt="Zoom Bazar"><?php else : ?><strong><?php echo esc_html( $profile['seller_name'] ); ?></strong><?php endif; ?></div>
		<div><span>شناسه مرسوله / سفارش</span><strong dir="ltr"><?php echo esc_html( $source['number'] ); ?></strong><small>ZOOM BAZAR</small></div>
	</header>
	<section class="cco-zoom-route">
		<div class="receiver"><h2>گیرنده</h2><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: 'بدون نام' ); ?></strong><p><?php echo esc_html( Company_Central_Orders_Delivery_Data::formatted_address( $order ) ); ?></p><b dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></b></div>
		<div class="sender"><h2>فرستنده</h2><strong><?php echo esc_html( $profile['seller_name'] ); ?></strong><p><?php echo esc_html( $profile['address'] ?: 'آدرس فروشنده در تنظیمات چاپ وارد نشده است.' ); ?></p><b dir="ltr"><?php echo esc_html( $profile['phone'] ?: '—' ); ?></b></div>
	</section>
	<section class="cco-zoom-label-meta"><div><span>روش ارسال</span><strong><?php echo esc_html( $order->get_shipping_method() ?: 'تعیین نشده' ); ?></strong></div><div><span>تاریخ تحویل</span><strong><?php echo esc_html( Company_Central_Orders_Delivery_Data::display( $order ) ); ?></strong></div><div><span>تعداد اقلام</span><strong><?php echo esc_html( $order->get_item_count() ); ?></strong></div></section>
	<?php include COMPANY_CENTRAL_ORDERS_PATH . 'templates/partials/items-simple.php'; ?>
	<footer class="cco-zoom-label-footer"><span>کنترل کالا □</span><span>بسته‌بندی □</span><span>خروج از انبار □</span></footer>
</section>
