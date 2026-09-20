<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Context {

	private static $depth = 0;

	public static function is_inbound() {
		return self::$depth > 0;
	}

	public static function run_inbound( callable $callback ) {
		self::$depth++;

		try {
			return $callback();
		} finally {
			self::$depth--;
		}
	}
}
