<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<table class="cco-print-items"><thead><tr><th>ردیف</th><th>کد / SKU</th><th>شرح کالا</th><th>تعداد</th></tr></thead><tbody>
<?php $row = 0; foreach ( $order->get_items( 'line_item' ) as $item ) : ++$row; ?>
	<tr><td><?php echo esc_html( $row ); ?></td><td dir="ltr"><?php echo esc_html( $item->get_meta( '_company_source_sku', true ) ?: '—' ); ?></td><td><strong><?php echo esc_html( $item->get_name() ); ?></strong></td><td><?php echo esc_html( $item->get_quantity() ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
