<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Order_Serializer {

	public function serialize( WC_Order $order ) {
		return array(
			'id'                   => $order->get_id(),
			'number'               => $order->get_order_number(),
			'sync_revision'        => $this->sync_revision( $order ),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'version'              => $order->get_version(),
			'prices_include_tax'   => $order->get_prices_include_tax(),
			'date_created_gmt'     => $this->date_gmt( $order->get_date_created() ),
			'date_modified_gmt'    => $this->date_gmt( $order->get_date_modified() ),
			'discount_total'       => $order->get_discount_total(),
			'discount_tax'         => $order->get_discount_tax(),
			'shipping_total'       => $order->get_shipping_total(),
			'shipping_tax'         => $order->get_shipping_tax(),
			'cart_tax'             => $order->get_cart_tax(),
			'total'                => $order->get_total(),
			'total_tax'            => $order->get_total_tax(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'transaction_id'       => $order->get_transaction_id(),
			'customer_note'        => $order->get_customer_note(),
			'billing'              => $this->address( $order, 'billing' ),
			'shipping'             => $this->address( $order, 'shipping' ),
			'address_extras'       => array(
				'billing'  => $this->address_extras( $order, 'billing' ),
				'shipping' => $this->address_extras( $order, 'shipping' ),
			),
			'delivery'             => $this->delivery( $order ),
			'line_items'           => $this->line_items( $order ),
			'shipping_lines'       => $this->shipping_lines( $order ),
			'fee_lines'            => $this->fee_lines( $order ),
			'coupon_lines'         => $this->coupon_lines( $order ),
			'notes'                => $this->notes( $order ),
		);
	}

	private function sync_revision( WC_Order $order ) {
		$stored   = (int) $order->get_meta( '_company_sync_revision', true );
		$modified = $order->get_date_modified();
		$fallback = $modified instanceof WC_DateTime ? $modified->getTimestamp() * 1000000 : 0;
		return (string) max( $stored, $fallback );
	}

	private function date_gmt( $date ) {
		return $date instanceof WC_DateTime ? gmdate( 'c', $date->getTimestamp() ) : null;
	}

	private function address( WC_Order $order, $type ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$data   = array();

		foreach ( $fields as $field ) {
			$getter = 'get_' . $type . '_' . $field;
			if ( is_callable( array( $order, $getter ) ) ) {
				$data[ $field ] = (string) $order->{$getter}();
			}
		}

		return $data;
	}

	private function address_extras( WC_Order $order, $type ) {
		return array(
			'plaque' => $this->first_meta( $order, array( '_' . $type . '_plaque', $type . '_plaque', '_' . $type . '_plate', $type . '_plate' ) ),
			'unit'    => $this->first_meta( $order, array( '_' . $type . '_unit', $type . '_unit' ) ),
			'floor'   => $this->first_meta( $order, array( '_' . $type . '_floor', $type . '_floor' ) ),
		);
	}

	private function delivery( WC_Order $order ) {
		$timestamp = absint( $this->first_meta( $order, array( '_orddd_timestamp', 'orddd_timestamp' ) ) );
		$time_slot = $this->first_meta( $order, array( '_orddd_time_slot', 'orddd_time_slot', 'Delivery Time', 'delivery_time' ) );
		$date_text = '';
		if ( ! $time_slot && class_exists( 'orddd_common' ) && is_callable( array( 'orddd_common', 'orddd_get_order_timeslot' ) ) ) {
			try {
				$time_slot = sanitize_text_field( (string) orddd_common::orddd_get_order_timeslot( $order->get_id() ) );
			} catch ( Throwable $error ) {
				$time_slot = '';
			}
		}

		if ( $timestamp ) {
			$date_text = wp_date( 'Y/m/d', $timestamp, wp_timezone() );
		} elseif ( class_exists( 'orddd_common' ) && is_callable( array( 'orddd_common', 'orddd_get_order_delivery_date' ) ) ) {
			try {
				$date_text = sanitize_text_field( (string) orddd_common::orddd_get_order_delivery_date( $order->get_id() ) );
			} catch ( Throwable $error ) {
				$date_text = '';
			}
		}

		$time_text = '';
		if ( preg_match( '/([0-2]?[0-9]:[0-5][0-9])/', $time_slot, $matches ) ) {
			$time_text = $matches[1];
		} elseif ( $timestamp && '00:00' !== wp_date( 'H:i', $timestamp, wp_timezone() ) ) {
			$time_text = wp_date( 'H:i', $timestamp, wp_timezone() );
		}

		$display = trim( $date_text . ' ' . $time_text );
		if ( ! $display && $time_slot ) {
			$display = $time_slot;
		}

		return array(
			'timestamp' => $timestamp,
			'time_slot' => $time_slot,
			'display'   => $display,
			'at_iso'    => $timestamp ? wp_date( 'c', $timestamp, wp_timezone() ) : '',
		);
	}

	private function first_meta( WC_Order $order, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key, true );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

	private function line_items( WC_Order $order ) {
		$data = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			$seller  = Company_Order_Sync_Product_Seller::seller_for_order_item( $item );
			$data[]  = array(
				'id'           => absint( $item_id ),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'subtotal_tax' => $item->get_subtotal_tax(),
				'total'        => $item->get_total(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product ? $product->get_sku() : '',
				'assigned_seller_id'   => absint( $seller['id'] ?? 0 ),
				'assigned_seller_name' => sanitize_text_field( $seller['name'] ?? '' ),
				'assigned_seller_login' => sanitize_user( $seller['login'] ?? '', false ),
			);
		}

		return $data;
	}

	private function shipping_lines( WC_Order $order ) {
		$data = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$data[] = array(
				'method_title' => $item->get_method_title(),
				'method_id'    => $item->get_method_id(),
				'instance_id'  => $item->get_instance_id(),
				'total'        => $item->get_total(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
			);
		}
		return $data;
	}

	private function fee_lines( WC_Order $order ) {
		$data = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			$data[] = array(
				'name'       => $item->get_name(),
				'tax_class'  => $item->get_tax_class(),
				'tax_status' => $item->get_tax_status(),
				'amount'     => $item->get_amount(),
				'total'      => $item->get_total(),
				'total_tax'  => $item->get_total_tax(),
				'taxes'      => $item->get_taxes(),
			);
		}
		return $data;
	}

	private function coupon_lines( WC_Order $order ) {
		$data = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$data[] = array(
				'code'         => $item->get_code(),
				'discount'     => $item->get_discount(),
				'discount_tax' => $item->get_discount_tax(),
			);
		}
		return $data;
	}

	private function notes( WC_Order $order ) {
		$data  = array();
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				// WordPress converts a negative comment limit with absint(), so -1
				// unexpectedly returns exactly one note. Zero means no LIMIT.
				'limit'    => 0,
				'orderby'  => 'date_created_gmt',
				'order'    => 'ASC',
			)
		);

		foreach ( $notes as $note ) {
			$data[] = array(
				'id'               => (int) $note->id,
				'content'          => (string) $note->content,
				'customer_note'    => (bool) $note->customer_note,
				'added_by'         => (string) $note->added_by,
				'date_created_gmt' => $this->date_gmt( $note->date_created ),
			);
		}

		return $data;
	}
}
