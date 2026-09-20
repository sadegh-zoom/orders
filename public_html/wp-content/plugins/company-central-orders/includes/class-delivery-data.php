<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Delivery_Data {

	public static function display( WC_Order $order ) {
		$date = self::date_display( $order );
		$slot = self::time_slot_display( $order );
		if ( 'تعیین نشده' === $date ) {
			return $slot ?: $date;
		}
		return $slot ? $date . '، ' . $slot : $date;
	}

	public static function date_display( WC_Order $order ) {
		$display = sanitize_text_field( $order->get_meta( '_company_delivery_display', true ) );
		if ( $display && preg_match( '/((?:1[34]|20)\d{2}[\/-]\d{1,2}[\/-]\d{1,2})/u', $display, $matches ) ) {
			return Company_Central_Orders_Jalali_Date::long_date( $matches[1] );
		}

		$timestamp = absint( $order->get_meta( '_company_delivery_timestamp', true ) );
		if ( $timestamp ) {
			return Company_Central_Orders_Jalali_Date::long_date( Company_Central_Orders_Jalali_Date::format_timestamp( $timestamp, false ) );
		}

		$iso = sanitize_text_field( $order->get_meta( '_company_delivery_at', true ) );
		if ( $iso ) {
			try {
				$date = new DateTimeImmutable( $iso );
				return Company_Central_Orders_Jalali_Date::long_date( Company_Central_Orders_Jalali_Date::format_timestamp( $date->getTimestamp(), false ) );
			} catch ( Throwable $error ) {
				return Company_Central_Orders_Jalali_Date::normalize_display( $iso );
			}
		}
		if ( $display ) {
			$value = trim( preg_replace( '/\b[0-2]?\d:[0-5]\d\b(?:\s*[-–]\s*[0-2]?\d:[0-5]\d)?/u', '', $display ) );
			return $value ?: 'تعیین نشده';
		}

		return 'تعیین نشده';
	}

	public static function time_slot_display( WC_Order $order ) {
		$value = sanitize_text_field( $order->get_meta( '_company_delivery_time_slot', true ) );
		if ( ! $value ) {
			$value = sanitize_text_field( $order->get_meta( '_company_delivery_display', true ) );
		}
		$value = strtr( $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9' ) );
		if ( ! preg_match_all( '/\b([0-2]?\d:[0-5]\d)\b/u', $value, $matches ) || empty( $matches[1] ) ) {
			return '';
		}
		$times = array_values( array_unique( $matches[1] ) );
		return count( $times ) > 1 ? $times[0] . ' - ' . $times[1] : $times[0];
	}

	public static function time_slot( WC_Order $order ) {
		return sanitize_text_field( $order->get_meta( '_company_delivery_time_slot', true ) );
	}

	public static function extra( WC_Order $order, $type, $field ) {
		if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) || ! in_array( $field, array( 'plaque', 'unit', 'floor' ), true ) ) {
			return '';
		}

		return sanitize_text_field( $order->get_meta( '_company_' . $type . '_' . $field, true ) );
	}

	public static function formatted_address( WC_Order $order ) {
		$type    = $order->get_shipping_address_1() ? 'shipping' : 'billing';
		$data    = $order->get_address( $type );
		foreach ( array( 'first_name', 'last_name', 'company', 'email', 'phone' ) as $field ) {
			unset( $data[ $field ] );
		}
		$address = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_formatted_address( $data ) : '';
		$extras  = array();
		$labels  = array( 'plaque' => 'پلاک', 'unit' => 'واحد', 'floor' => 'طبقه' );

		foreach ( $labels as $field => $label ) {
			$value = self::extra( $order, $type, $field );
			if ( $value ) {
				$extras[] = $label . ' ' . $value;
			}
		}

		$address = $address ? wp_strip_all_tags( preg_replace( '/<br\s*\/?\s*>/i', '، ', $address ) ) : '';
		if ( $extras ) {
			$address .= ( $address ? '، ' : '' ) . implode( '، ', $extras );
		}

		return $address ?: '—';
	}

	public static function state_name( WC_Order $order ) {
		$type    = $order->get_shipping_address_1() ? 'shipping' : 'billing';
		$country = 'shipping' === $type ? $order->get_shipping_country() : $order->get_billing_country();
		$state   = 'shipping' === $type ? $order->get_shipping_state() : $order->get_billing_state();
		if ( ! $state ) {
			return '';
		}

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				return sanitize_text_field( $states[ $state ] );
			}
		}

		return sanitize_text_field( $state );
	}
}
