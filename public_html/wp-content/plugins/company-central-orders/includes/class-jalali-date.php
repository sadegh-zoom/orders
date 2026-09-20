<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Jalali_Date {

	public static function format_timestamp( $timestamp, $with_time = true ) {
		$timestamp = absint( $timestamp );
		if ( ! $timestamp ) {
			return '—';
		}
		$parts = array_map( 'intval', explode( '-', wp_date( 'Y-n-j-H-i', $timestamp, wp_timezone() ) ) );
		list( $year, $month, $day ) = self::gregorian_to_jalali( $parts[0], $parts[1], $parts[2] );
		$date = sprintf( '%04d/%02d/%02d', $year, $month, $day );
		return $with_time ? $date . sprintf( ' %02d:%02d', $parts[3], $parts[4] ) : $date;
	}

	public static function format_datetime( $date, $with_time = true ) {
		if ( ! $date || ! is_callable( array( $date, 'getTimestamp' ) ) ) {
			return '—';
		}
		return self::format_timestamp( $date->getTimestamp(), $with_time );
	}

	public static function normalize_display( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( ! $value ) {
			return '';
		}
		$value = strtr( $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) );
		if ( preg_match( '/^(1[34]\d{2})[\/-](\d{1,2})[\/-](\d{1,2})(.*)$/u', $value, $matches ) ) {
			return sprintf( '%04d/%02d/%02d', $matches[1], $matches[2], $matches[3] ) . $matches[4];
		}
		if ( preg_match( '/^(20\d{2})[\/-](\d{1,2})[\/-](\d{1,2})(.*)$/u', $value, $matches ) ) {
			list( $year, $month, $day ) = self::gregorian_to_jalali( (int) $matches[1], (int) $matches[2], (int) $matches[3] );
			return sprintf( '%04d/%02d/%02d', $year, $month, $day ) . $matches[4];
		}
		return $value;
	}

	public static function long_date( $value ) {
		$value = self::normalize_display( $value );
		if ( ! preg_match( '/^(1[34]\d{2})\/(\d{2})\/(\d{2})/', $value, $matches ) ) {
			return $value;
		}
		$months = array( 1=>'فروردین', 2=>'اردیبهشت', 3=>'خرداد', 4=>'تیر', 5=>'مرداد', 6=>'شهریور', 7=>'مهر', 8=>'آبان', 9=>'آذر', 10=>'دی', 11=>'بهمن', 12=>'اسفند' );
		return sprintf( '%d %s %d', (int) $matches[3], $months[ (int) $matches[2] ], (int) $matches[1] );
	}

	private static function gregorian_to_jalali( $gy, $gm, $gd ) {
		$month_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		if ( $gy > 1600 ) {
			$jy = 979;
			$gy -= 1600;
		} else {
			$jy = 0;
			$gy -= 621;
		}
		$gy2  = $gm > 2 ? $gy + 1 : $gy;
		$days = ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) - 80 + $gd + $month_days[ $gm - 1 ];
		$jy  += 33 * intdiv( $days, 12053 );
		$days %= 12053;
		$jy  += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}
}
