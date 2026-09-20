<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Security {

	public static function sign( $secret, $timestamp, $request_id, $raw_body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $request_id . '.' . $raw_body, $secret );
	}

	public static function headers( $store_id, $secret, $raw_body ) {
		$timestamp = (string) time();
		$request_id = wp_generate_uuid4();

		return array(
			'Content-Type'          => 'application/json; charset=utf-8',
			'X-Company-Store'       => $store_id,
			'X-Company-Timestamp'   => $timestamp,
			'X-Company-Request-ID'  => $request_id,
			'X-Company-Signature'   => self::sign( $secret, $timestamp, $request_id, $raw_body ),
		);
	}

	public static function verify( WP_REST_Request $request, $expected_store_id, $secret, $connection_id ) {
		$store_id   = sanitize_key( $request->get_header( 'x-company-store' ) );
		$timestamp  = trim( (string) $request->get_header( 'x-company-timestamp' ) );
		$request_id = sanitize_text_field( $request->get_header( 'x-company-request-id' ) );
		$signature  = strtolower( trim( (string) $request->get_header( 'x-company-signature' ) ) );
		$raw_body   = (string) $request->get_body();

		if ( ! $store_id || ! $timestamp || ! $request_id || ! $signature ) {
			return self::error( 'missing_auth_headers', 'Authentication headers are incomplete.', 401, $request_id );
		}

		if ( ! hash_equals( (string) $expected_store_id, $store_id ) ) {
			return self::error( 'store_mismatch', 'Store identifier does not match this connection.', 403, $request_id );
		}

		if ( strlen( $secret ) < 32 ) {
			return self::error( 'connection_not_configured', 'Connection secret is not configured.', 503, $request_id );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return self::error( 'invalid_timestamp', 'Timestamp is invalid.', 401, $request_id );
		}

		$ttl = (int) Company_Order_Sync_Settings::get( 'signature_ttl', 300 );
		if ( abs( time() - (int) $timestamp ) > $ttl ) {
			return self::error( 'expired_request', 'Request timestamp is outside the allowed window.', 401, $request_id );
		}

		if ( ! preg_match( '/^[a-f0-9-]{20,64}$/i', $request_id ) ) {
			return self::error( 'invalid_request_id', 'Request ID is invalid.', 400, $request_id );
		}

		$expected = self::sign( $secret, $timestamp, $request_id, $raw_body );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) || ! hash_equals( $expected, $signature ) ) {
			return self::error( 'invalid_signature', 'Signature verification failed.', 401, $request_id );
		}

		if ( ! Company_Order_Sync_Replay_Store::claim( $connection_id, $request_id, hash( 'sha256', $raw_body ) ) ) {
			return self::error( 'duplicate_request', 'This request ID has already been used.', 409, $request_id );
		}

		return array(
			'store_id'   => $store_id,
			'request_id' => $request_id,
		);
	}

	public static function error( $code, $message, $status, $request_id = '' ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'     => $status,
				'success'    => false,
				'code'       => $code,
				'request_id' => $request_id,
			)
		);
	}
}
