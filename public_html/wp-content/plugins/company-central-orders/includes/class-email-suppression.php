<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Email_Suppression {

	public function hooks() {
		$email_ids = array(
			'customer_cancelled_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_failed_order',
			'customer_invoice',
			'customer_note',
		);

		foreach ( $email_ids as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( $this, 'maybe_disable' ), 20, 2 );
		}
	}

	public function maybe_disable( $enabled, $object ) {
		if ( ! $enabled || ! $object instanceof WC_Order ) {
			return $enabled;
		}

		return $object->get_meta( '_company_source_store', true ) ? false : $enabled;
	}
}
