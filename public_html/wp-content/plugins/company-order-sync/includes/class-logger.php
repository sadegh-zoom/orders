<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Logger {

	const SOURCE = 'company-order-sync';

	public static function log( $level, $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context = self::redact( $context );
		$context['source'] = self::SOURCE;
		wc_get_logger()->log( $level, $message, $context );
	}

	private static function redact( array $context ) {
		$sensitive = array( 'authorization', 'secret', 'signature', 'payload', 'body', 'response_excerpt', 'phone', 'email', 'address', 'billing', 'shipping', 'customer' );
		$out = array();
		foreach ( $context as $key => $value ) {
			$key_text = strtolower( (string) $key );
			$hide     = false;
			foreach ( $sensitive as $needle ) {
				if ( false !== strpos( $key_text, $needle ) ) {
					$hide = true;
					break;
				}
			}
			if ( $hide ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				$value = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $value );
				$out[ $key ] = preg_replace( '/(?<!\d)(?:\+?98|0098|0)?9\d{9}(?!\d)/', '[redacted-phone]', $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = '[object]';
			}
		}
		return $out;
	}
}
