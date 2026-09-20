<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Replay_Store {

	const SCHEMA_VERSION = '1';
	const OPTION_VERSION = 'company_order_sync_replay_schema';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'company_sync_requests';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id varchar(64) NOT NULL,
			request_id varchar(64) NOT NULL,
			body_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_connection (connection_id, request_id),
			KEY expires_at (expires_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function maybe_install() {
		if ( self::SCHEMA_VERSION !== get_option( self::OPTION_VERSION ) ) {
			self::install();
		}
	}

	public static function claim( $connection_id, $request_id, $body_hash ) {
		global $wpdb;

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$result  = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table_name() . ' (connection_id, request_id, body_hash, created_at, expires_at) VALUES (%s, %s, %s, %s, %s)',
				$connection_id,
				$request_id,
				$body_hash,
				$now,
				$expires
			)
		);

		return 1 === $result;
	}

	public static function cleanup() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name() . ' WHERE expires_at < %s LIMIT 1000',
				current_time( 'mysql', true )
			)
		);
	}
}
