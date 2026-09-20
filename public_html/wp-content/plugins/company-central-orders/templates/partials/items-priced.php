<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<table class="cco-print-items"><thead><tr><th>ردیف</th><th>کد / SKU</th><th>شرح کالا</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody>
<?php $row = 0; foreach ( $order->get_items( 'line_item' ) as $item ) : ++$row; $quantity = max( 1, (int) $item->get_quantity() ); $subtotal = (float) $item->get_subtotal(); $total = (float) $item->get_total(); ?>
	<tr><td><?php echo esc_html( $row ); ?></td><td dir="ltr"><?php echo esc_html( $item->get_meta( '_company_source_sku', true ) ?: '—' ); ?></td><td><strong><?php echo esc_html( $item->get_name() ); ?></strong></td><td><?php echo esc_html( $item->get_quantity() ); ?></td><td><?php echo wp_kses_post( wc_price( $subtotal / $quantity, array( 'currency' => $order->get_currency() ) ) ); ?></td><td><?php echo wp_kses_post( wc_price( max( 0, $subtotal - $total ), array( 'currency' => $order->get_currency() ) ) ); ?></td><td><?php echo wp_kses_post( wc_price( $total + (float) $item->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
