<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Central_Orders_Barcode {

	public static function data_uri( $value ) {
		$value = strtr(
			(string) $value,
			array( '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9' )
		);
		$value = preg_replace( '/[^0-9]/', '', $value );
		if ( '' === $value ) {
			return '';
		}

		$patterns = array(
			'0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
			'4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
			'8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', '*' => 'nwnnwnwnn',
		);
		$encoded  = '*' . $value . '*';
		$x        = 8;
		$bars     = '';

		foreach ( str_split( $encoded ) as $character ) {
			$pattern = $patterns[ $character ];
			foreach ( str_split( $pattern ) as $index => $width_code ) {
				$width = 'w' === $width_code ? 3 : 1;
				if ( 0 === $index % 2 ) {
					$bars .= '<rect x="' . $x . '" y="2" width="' . $width . '" height="38" fill="#000"/>';
				}
				$x += $width;
			}
			$x += 1;
		}

		$width = $x + 8;
		$svg   = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="46" viewBox="0 0 ' . $width . ' 46">' . $bars . '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
