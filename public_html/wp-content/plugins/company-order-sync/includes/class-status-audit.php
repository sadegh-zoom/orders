<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only status audit.
 *
 * This deliberately never calls the mapper and never changes an order status.
 * It compares the current source snapshot with Central and records diagnostics
 * so historical Repair/retry problems can be reviewed safely.
 */
final class Company_Order_Sync_Status_Audit {

	const HOOK         = 'company_order_sync_audit_statuses';
	const STATE_OPTION = 'company_order_sync_status_audit_state';
	const PER_PAGE     = 100;
	const MAX_ATTEMPTS = 4;
	const LOCK_TTL     = 180;
	const MAX_ISSUES   = 1000;

	private $mapper;

	public function __construct() {
		$this->mapper = new Company_Order_Sync_Order_Mapper();
	}

	public function hooks() {
		add_action( 'admin_post_company_order_sync_status_audit', array( $this, 'start' ) );
		add_action( 'admin_post_company_order_sync_status_audit_stop', array( $this, 'stop' ) );
		add_action( self::HOOK, array( $this, 'process' ), 10, 6 );
		add_action( 'init', array( $this, 'recover' ), 46 );
	}

	public function start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_status_audit', '_cos_nonce' );

		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			wp_die( 'Audit وضعیت فقط در حالت Central قابل اجرا است.', 422 );
		}

		$store_id = sanitize_key( wp_unslash( $_POST['store_id'] ?? '' ) );
		$store    = Company_Order_Sync_Settings::central_store( $store_id );
		$date_from = Company_Order_Sync_Settings::retention_start_date();
		if ( ! $store_id || ! $store || empty( $store['enabled'] ) || ! $date_from ) {
			wp_die( 'فروشگاه انتخاب‌شده فعال یا معتبر نیست.', 422 );
		}

		$run_id  = wp_generate_uuid4();
		$date_to = current_time( 'Y-m-d H:i:s' );
		update_option(
			self::STATE_OPTION,
			array(
				'run_id'                 => $run_id,
				'store_id'               => $store_id,
				'date_from'              => $date_from,
				'date_to'                => $date_to,
				'page'                   => 0,
				'total_pages'            => 0,
				'source_total'           => 0,
				'checked'                => 0,
				'matched'                => 0,
				'pending_waiting'        => 0,
				'pending_confirmed'      => 0,
				'status_mismatch'        => 0,
				'missing_in_central'     => 0,
				'source_revision_behind' => 0,
				'history_oscillation'     => 0,
				'issues_total'           => 0,
				'issues_truncated'       => false,
				'issues'                 => array(),
				'failed_order_ids'       => array(),
				'attempt'                => 0,
				'status'                 => 'queued',
				'message'                => 'Audit بدون تغییر در صف اجرا قرار گرفت',
				'updated_at'             => current_time( 'mysql' ),
			),
			false
		);

		$this->schedule( array( $store_id, $date_from, $date_to, 1, $run_id, 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync', 'status_audit_started'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function stop() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_status_audit_stop', '_cos_nonce' );
		$this->update_state( array( 'status'=>'stopped', 'message'=>'Audit وضعیت‌ها متوقف شد' ) );
		$this->unschedule();
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function recover() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying' ), true ) || empty( $state['run_id'] ) ) {
			return;
		}
		$updated = strtotime( (string) ( $state['updated_at'] ?? '' ) );
		if ( $updated && $updated > time() - self::LOCK_TTL ) {
			return;
		}
		$this->unschedule();
		$page = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		$this->schedule(
			array(
				sanitize_key( $state['store_id'] ?? '' ),
				(string) ( $state['date_from'] ?? '' ),
				(string) ( $state['date_to'] ?? '' ),
				$page,
				(string) $state['run_id'],
				0,
			)
		);
		$this->update_state( array( 'status'=>'queued', 'message'=>'Worker Audit بازیابی شد' ) );
	}

	public function process( $store_id, $date_from, $date_to, $page, $run_id, $attempt = 0 ) {
		if ( Company_Order_Sync_Central_Rebuild::run_blocked( $run_id ) ) { return; }
		$state = get_option( self::STATE_OPTION, array() );
		$page  = max( 1, absint( $page ) );
		if ( 'central' !== Company_Order_Sync_Settings::mode() || empty( $state['run_id'] ) || ! hash_equals( (string) $state['run_id'], (string) $run_id ) || 'stopped' === ( $state['status'] ?? '' ) ) {
			return;
		}
		$expected = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		if ( $page !== $expected ) {
			return;
		}

		$args = array( $store_id, $date_from, $date_to, $page, $run_id, absint( $attempt ) );
		wp_clear_scheduled_hook( self::HOOK, $args );
		$lock = $this->acquire_lock( $run_id, $page );
		if ( ! $lock ) {
			return;
		}
		try {
			$this->process_page( sanitize_key( $store_id ), (string) $date_from, (string) $date_to, $page, (string) $run_id, absint( $attempt ) );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function process_page( $store_id, $date_from, $date_to, $page, $run_id, $attempt ) {
		$this->update_state( array( 'status'=>'running', 'attempt'=>$attempt, 'message'=>sprintf( 'در حال Audit بدون تغییر — بخش %d', $page ) ) );
		$result = $this->request(
			$store_id,
			'/wp-json/company-sync/v1/status-snapshot',
			array(
				'store_id'   => $store_id,
				'date_from'  => $date_from,
				'date_to'    => $date_to,
				'page'       => $page,
				'per_page'   => self::PER_PAGE,
				'audit_only' => true,
			),
			30
		);
		if ( is_wp_error( $result ) ) {
			$this->retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $result->get_error_message() );
			return;
		}

		$records = array_values( array_filter( (array) ( $result['statuses'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $records, 'id' ) );

		$counts = array(
			'checked'                => 0,
			'matched'                => 0,
			'pending_waiting'        => 0,
			'pending_confirmed'      => 0,
			'status_mismatch'        => 0,
			'missing_in_central'     => 0,
			'source_revision_behind' => 0,
			'history_oscillation'     => 0,
		);
		$page_issues = array();
		$detail_ids  = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $records, 'id' ) ) ) ) );
		$central_orders_by_source = array();
		$central_order_ids = array();

		foreach ( $records as $record ) {
			$source_id = absint( $record['id'] ?? 0 );
			if ( ! $source_id ) {
				continue;
			}
			$central_order = $this->mapper->existing_order( $store_id, $source_id );
			if ( $central_order instanceof WC_Order ) {
				$central_orders_by_source[ $source_id ] = $central_order;
				$central_order_ids[] = $central_order->get_id();
			}
		}

		// Fetch read-only history data before classifying. This lets the audit flag
		// old Repair/retry oscillations even when source and Central currently match.
		$details = $this->fetch_source_details( $store_id, $detail_ids );
		$central_histories = $this->central_status_histories( $central_order_ids );
		$issue_index = array();

		foreach ( $records as $record ) {
			$source_id = absint( $record['id'] ?? 0 );
			if ( ! $source_id ) {
				continue;
			}
			++$counts['checked'];

			$source_status   = sanitize_key( $record['status'] ?? '' );
			$source_revision = max( 0, (int) ( $record['sync_revision'] ?? 0 ) );
			$central_order   = $central_orders_by_source[ $source_id ] ?? null;
			$source_detail   = $details[ $source_id ] ?? array();
			$source_oscillated = ! empty( $source_detail['status_oscillation'] );

			if ( ! $central_order instanceof WC_Order ) {
				++$counts['missing_in_central'];
				$flags = array( 'missing_in_central' );
				if ( $source_oscillated ) {
					++$counts['history_oscillation'];
					$flags[] = 'history_oscillation';
				}
				$issue = $this->issue_record( $flags[0], $store_id, $record, null, $flags );
				$issue['source_last_central_change'] = $source_detail['last_central_change'] ?? array();
				$issue['source_recent_status_notes'] = $source_detail['recent_status_notes'] ?? array();
				$issue['source_central_change_count'] = absint( $source_detail['central_change_count'] ?? 0 );
				$page_issues[] = $issue;
				$issue_index[ $source_id ] = count( $page_issues ) - 1;
				continue;
			}

			$central_status   = sanitize_key( $central_order->get_status() );
			$pending_status   = sanitize_key( $central_order->get_meta( '_company_pending_outbound_status', true ) );
			$central_revision = max( 0, (int) $central_order->get_meta( '_company_source_sync_revision', true ) );
			$central_history  = $central_histories[ $central_order->get_id() ] ?? array();
			$central_oscillated = ! empty( $central_history['status_oscillation'] );
			$flags            = array();

			if ( $pending_status ) {
				if ( $pending_status === $source_status ) {
					++$counts['pending_confirmed'];
					$flags[] = 'pending_confirmed';
				} else {
					++$counts['pending_waiting'];
					$flags[] = 'pending_waiting';
				}
			} elseif ( $central_status !== $source_status ) {
				++$counts['status_mismatch'];
				$flags[] = 'status_mismatch';
			}

			if ( $source_revision && $central_revision && $source_revision < $central_revision ) {
				++$counts['source_revision_behind'];
				$flags[] = 'source_revision_behind';
			}

			if ( $source_oscillated || $central_oscillated ) {
				++$counts['history_oscillation'];
				$flags[] = 'history_oscillation';
			}

			if ( ! $flags ) {
				++$counts['matched'];
				continue;
			}

			$issue = $this->issue_record( $flags[0], $store_id, $record, $central_order, $flags );
			$issue['source_last_central_change'] = $source_detail['last_central_change'] ?? array();
			$issue['source_recent_status_notes'] = $source_detail['recent_status_notes'] ?? array();
			$issue['source_central_change_count'] = absint( $source_detail['central_change_count'] ?? 0 );
			$issue['central_recent_status_notes'] = $central_history['recent_status_notes'] ?? array();
			$page_issues[] = $issue;
			$issue_index[ $source_id ] = count( $page_issues ) - 1;
		}

		$state = get_option( self::STATE_OPTION, array() );
		$issues = array_values( array_filter( (array) ( $state['issues'] ?? array() ), 'is_array' ) );
		$issues_total = absint( $state['issues_total'] ?? 0 ) + count( $page_issues );
		$room = max( 0, self::MAX_ISSUES - count( $issues ) );
		if ( $room ) {
			$issues = array_merge( $issues, array_slice( $page_issues, 0, $room ) );
		}
		$failed_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $state['failed_order_ids'] ?? array() ) ) ) ) );

		$total_pages = max( 1, absint( $result['total_pages'] ?? 1 ) );
		$is_last     = $page >= $total_pages;
		$changes = array(
			'page'                   => $page,
			'total_pages'            => $total_pages,
			'source_total'           => absint( $result['total'] ?? 0 ),
			'checked'                => absint( $state['checked'] ?? 0 ) + $counts['checked'],
			'matched'                => absint( $state['matched'] ?? 0 ) + $counts['matched'],
			'pending_waiting'        => absint( $state['pending_waiting'] ?? 0 ) + $counts['pending_waiting'],
			'pending_confirmed'      => absint( $state['pending_confirmed'] ?? 0 ) + $counts['pending_confirmed'],
			'status_mismatch'        => absint( $state['status_mismatch'] ?? 0 ) + $counts['status_mismatch'],
			'missing_in_central'     => absint( $state['missing_in_central'] ?? 0 ) + $counts['missing_in_central'],
			'source_revision_behind' => absint( $state['source_revision_behind'] ?? 0 ) + $counts['source_revision_behind'],
			'history_oscillation'     => absint( $state['history_oscillation'] ?? 0 ) + $counts['history_oscillation'],
			'issues_total'           => $issues_total,
			'issues_truncated'       => ! empty( $state['issues_truncated'] ) || $issues_total > self::MAX_ISSUES,
			'issues'                 => $issues,
			'failed_order_ids'       => $failed_ids,
			'attempt'                => 0,
			'status'                 => $is_last ? 'completed' : 'running',
			'message'                => $is_last ? 'Audit بدون تغییر کامل شد؛ هیچ وضعیت سفارشی تغییر داده نشد' : 'در حال Audit بدون تغییر',
		);
		$this->update_state( $changes );

		if ( ! $is_last ) {
			$this->schedule( array( $store_id, $date_from, $date_to, $page + 1, $run_id, 0 ) );
		}
	}

	private function issue_record( $type, $store_id, array $record, $central_order = null, array $flags = array() ) {
		$source_id = absint( $record['id'] ?? 0 );
		$out = array(
			'type'                  => sanitize_key( $type ),
			'flags'                 => array_values( array_unique( array_filter( array_map( 'sanitize_key', $flags ?: array( $type ) ) ) ) ),
			'store_id'              => sanitize_key( $store_id ),
			'source_order_id'       => $source_id,
			'source_number'         => sanitize_text_field( $record['number'] ?? $source_id ),
			'source_status'         => sanitize_key( $record['status'] ?? '' ),
			'source_revision'       => (string) max( 0, (int) ( $record['sync_revision'] ?? 0 ) ),
			'source_modified_gmt'   => sanitize_text_field( $record['date_modified_gmt'] ?? '' ),
			'source_url'            => esc_url_raw( $record['edit_url'] ?? '' ),
			'central_order_id'      => 0,
			'central_status'        => '',
			'pending_status'        => '',
			'pending_changed_at_gmt'=> '',
			'pending_sequence'      => '',
			'central_revision'      => '',
			'central_sync_status'   => '',
			'central_sync_error'    => '',
			'central_changed_by'    => array(),
			'central_last_change'   => array(),
			'central_url'           => '',
			'source_last_central_change' => array(),
		);
		if ( $central_order instanceof WC_Order ) {
			$changed_by = $central_order->get_meta( '_company_sync_changed_by', true );
			$last_change = $central_order->get_meta( '_company_last_central_status_change', true );
			$out['central_order_id']       = $central_order->get_id();
			$out['central_status']         = sanitize_key( $central_order->get_status() );
			$out['pending_status']         = sanitize_key( $central_order->get_meta( '_company_pending_outbound_status', true ) );
			$out['pending_changed_at_gmt'] = sanitize_text_field( $central_order->get_meta( '_company_pending_outbound_changed_at_gmt', true ) );
			$out['pending_sequence']       = (string) max( 0, (int) $central_order->get_meta( '_company_pending_outbound_sequence', true ) );
			$out['central_revision']       = (string) max( 0, (int) $central_order->get_meta( '_company_source_sync_revision', true ) );
			$out['central_sync_status']    = sanitize_key( $central_order->get_meta( '_company_sync_status', true ) );
			$out['central_sync_error']     = sanitize_text_field( $central_order->get_meta( '_company_last_sync_error', true ) );
			$out['central_changed_by']     = is_array( $changed_by ) ? array(
				'user_id'      => absint( $changed_by['user_id'] ?? 0 ),
				'display_name' => sanitize_text_field( $changed_by['display_name'] ?? '' ),
			) : array();
			$out['central_last_change']    = is_array( $last_change ) ? $this->sanitize_change_record( $last_change ) : array();
			$out['central_url']            = esc_url_raw( $central_order->get_edit_order_url() );
		}
		return $out;
	}

	private function fetch_source_details( $store_id, array $source_ids ) {
		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );
		if ( ! $source_ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $source_ids, 100 ) as $ids ) {
			$result = $this->request(
				$store_id,
				'/wp-json/company-sync/v1/status-audit-details',
				array( 'store_id'=>$store_id, 'order_ids'=>$ids ),
				30
			);
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( (array) ( $result['orders'] ?? array() ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$id = absint( $record['id'] ?? 0 );
				if ( $id ) {
					$out[ $id ] = $record;
				}
			}
		}
		return $out;
	}

	/**
	 * Source-side read-only detail endpoint used only by the audit worker.
	 */
	public function export_source_details( WP_REST_Request $request, array $auth ) {
		$params = $request->get_json_params();
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['order_ids'] ?? array() ) ) ) ) ), 0, self::PER_PAGE );
		if ( ! $ids ) {
			return new WP_Error( 'invalid_audit_ids', 'Order IDs are required.', array( 'status'=>422 ) );
		}

		$histories = $this->status_histories( $ids );
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			$modified = $order->get_date_modified();
			$history = $histories[ $id ] ?? array();
			$stored_change = $order->get_meta( '_company_last_central_status_change', true );
			$last_change = is_array( $stored_change ) ? $this->sanitize_change_record( $stored_change ) : array();
			if ( ! $last_change && ! empty( $history['last_central_note'] ) ) {
				$last_change = array(
					'changed_at_gmt' => sanitize_text_field( $history['last_central_note']['date_gmt'] ?? '' ),
					'note'           => sanitize_text_field( $history['last_central_note']['content'] ?? '' ),
				);
			}
			$recent_status_notes = array_values( (array) ( $history['recent_status_notes'] ?? array() ) );
			$last_status_change_gmt = sanitize_text_field( $recent_status_notes[0]['date_gmt'] ?? '' );
			$orders[] = array(
				'id'                    => $id,
				'status'                => sanitize_key( $order->get_status() ),
				'sync_revision'         => (string) max( (int) $order->get_meta( '_company_sync_revision', true ), $modified instanceof WC_DateTime ? $modified->getTimestamp() * 1000000 : 0 ),
				'date_modified_gmt'     => $modified instanceof WC_DateTime ? gmdate( 'c', $modified->getTimestamp() ) : '',
				'last_status_change_gmt'=> $last_status_change_gmt,
				'last_central_change'   => $last_change,
				'central_change_count'  => absint( $history['central_change_count'] ?? 0 ),
				'recent_status_notes'   => $recent_status_notes,
				'status_oscillation'    => ! empty( $history['status_oscillation'] ),
			);
		}
		return array( 'success'=>true, 'request_id'=>$auth['request_id'], 'orders'=>$orders );
	}

	private function central_status_histories( array $order_ids ) {
		return $this->status_histories( $order_ids );
	}

	private function status_histories( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$central_like = '%' . $wpdb->esc_like( 'وضعیت از Central توسط' ) . '%';
		$fa_like      = '%' . $wpdb->esc_like( 'وضعیت سفارش از' ) . '%';
		$en_like      = '%' . $wpdb->esc_like( 'Order status changed from' ) . '%';
		$args = array_merge( $ids, array( $central_like, $fa_like, $en_like ) );
		$sql = "SELECT comment_post_ID, comment_content, comment_date_gmt, comment_ID
			FROM {$wpdb->comments}
			WHERE comment_type = 'order_note'
			AND comment_post_ID IN ({$placeholders})
			AND ( comment_content LIKE %s OR comment_content LIKE %s OR comment_content LIKE %s )
			ORDER BY comment_date_gmt DESC, comment_ID DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		$transitions = array();
		foreach ( (array) $rows as $row ) {
			$id = absint( $row['comment_post_ID'] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'last_central_note'  => array(),
					'central_change_count'=> 0,
					'recent_status_notes' => array(),
					'status_oscillation'  => false,
				);
			}
			$content = trim( wp_strip_all_tags( html_entity_decode( (string) ( $row['comment_content'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) );
			$date_gmt = sanitize_text_field( $row['comment_date_gmt'] ?? '' );
			if ( false !== strpos( $content, 'وضعیت از Central توسط' ) ) {
				++$out[ $id ]['central_change_count'];
				if ( ! $out[ $id ]['last_central_note'] ) {
					$out[ $id ]['last_central_note'] = array( 'content'=>$content, 'date_gmt'=>$date_gmt );
				}
			}
			$transition = $this->parse_status_transition( $content );
			if ( ! $transition ) {
				continue;
			}
			$transition['date_gmt'] = $date_gmt;
			$transitions[ $id ][] = $transition;
			if ( count( $out[ $id ]['recent_status_notes'] ) < 8 ) {
				$out[ $id ]['recent_status_notes'][] = array(
					'content'  => $content,
					'date_gmt' => $date_gmt,
					'from'     => $transition['from'],
					'to'       => $transition['to'],
				);
			}
		}

		foreach ( $transitions as $id => $items ) {
			$items = array_reverse( array_slice( $items, 0, 20 ) );
			$seen = array();
			foreach ( $items as $transition ) {
				$from = $this->normalize_status_label( $transition['from'] ?? '' );
				$to   = $this->normalize_status_label( $transition['to'] ?? '' );
				if ( ! $from || ! $to || $from === $to ) {
					continue;
				}
				$reverse = $to . '>' . $from;
				if ( isset( $seen[ $reverse ] ) ) {
					$out[ $id ]['status_oscillation'] = true;
					break;
				}
				$seen[ $from . '>' . $to ] = true;
			}
		}
		return $out;
	}

	private function parse_status_transition( $content ) {
		$content = preg_replace( '/\s+/u', ' ', trim( (string) $content ) );
		if ( preg_match( '/وضعیت سفارش از\s+(.+?)\s+به\s+(.+?)\s+تغییر کرد/u', $content, $m ) ) {
			return array( 'from'=>sanitize_text_field( $m[1] ), 'to'=>sanitize_text_field( $m[2] ) );
		}
		if ( preg_match( '/Order status changed from\s+(.+?)\s+to\s+(.+?)(?:\.|$)/iu', $content, $m ) ) {
			return array( 'from'=>sanitize_text_field( $m[1] ), 'to'=>sanitize_text_field( $m[2] ) );
		}
		return array();
	}

	private function normalize_status_label( $value ) {
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private function sanitize_change_record( array $record ) {
		return array_filter(
			array(
				'previous_status' => sanitize_key( $record['previous_status'] ?? '' ),
				'status'          => sanitize_key( $record['status'] ?? '' ),
				'actor'           => sanitize_text_field( $record['actor'] ?? '' ),
				'changed_at_gmt'  => sanitize_text_field( $record['changed_at_gmt'] ?? '' ),
				'request_id'      => sanitize_text_field( $record['request_id'] ?? '' ),
				'dispatch_id'     => sanitize_text_field( $record['dispatch_id'] ?? '' ),
				'sequence'        => (string) max( 0, (int) ( $record['sequence'] ?? 0 ) ),
				'received_at_gmt' => sanitize_text_field( $record['received_at_gmt'] ?? '' ),
				'event_id'        => sanitize_text_field( $record['event_id'] ?? '' ),
				'note'            => sanitize_text_field( $record['note'] ?? '' ),
			),
			static function( $value ) { return '' !== $value && null !== $value; }
		);
	}

	private function request( $store_id, $path, array $payload, $timeout ) {
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) {
			return new WP_Error( 'store_unavailable', 'اتصال فروشگاه فعال نیست.' );
		}
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$headers  = Company_Order_Sync_Security::headers( $store_id, (string) $store['outbound_secret'], $body );
		$response = wp_safe_remote_post(
			untrailingslashit( $store['url'] ) . $path,
			array( 'timeout'=>$timeout, 'redirection'=>0, 'headers'=>$headers, 'body'=>$body, 'data_format'=>'body' )
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'audit_transport_failed', 'ارتباط با فروشگاه برقرار نشد: ' . sanitize_text_field( $response->get_error_message() ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $http < 200 || $http >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$message = is_array( $data ) ? sanitize_text_field( $data['message'] ?? '' ) : '';
			return new WP_Error( 'audit_request_failed', sprintf( 'HTTP %d: %s', $http, $message ?: 'پاسخ نامعتبر فروشگاه' ) );
		}
		return $data;
	}

	private function retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $message ) {
		$next = $attempt + 1;
		if ( $next >= self::MAX_ATTEMPTS ) {
			$this->update_state( array( 'status'=>'failed', 'attempt'=>$next, 'message'=>'Audit متوقف شد: ' . sanitize_text_field( $message ) ) );
			return;
		}
		$delays = array( 30, 120, 300 );
		$this->update_state( array( 'status'=>'retrying', 'attempt'=>$next, 'message'=>sprintf( 'تلاش مجدد %d برای Audit بخش %d: %s', $next, $page, sanitize_text_field( $message ) ) ) );
		$args = array( $store_id, $date_from, $date_to, $page, $run_id, $next );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delays[ min( $attempt, count( $delays ) - 1 ) ], self::HOOK, $args, Company_Order_Sync_Queue::GROUP, true, 1 );
		} else {
			wp_schedule_single_event( time() + $delays[ min( $attempt, count( $delays ) - 1 ) ], self::HOOK, $args );
		}
	}

	private function schedule( array $args ) {
		$action_id = 0;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = absint( as_enqueue_async_action( self::HOOK, $args, Company_Order_Sync_Queue::GROUP, true, 1 ) );
		}
		if ( ! $action_id && ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, $args );
		}
	}

	private function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	private function update_state( array $changes ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$state = get_option( self::STATE_OPTION, array() );
		update_option( self::STATE_OPTION, array_merge( is_array( $state ) ? $state : array(), $changes, array( 'updated_at'=>current_time( 'mysql' ) ) ), false );
	}

	private function acquire_lock( $run_id, $page ) {
		$key   = 'company_status_audit_' . md5( (string) $run_id . ':' . absint( $page ) );
		$token = time() . ':' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) {
			return array( 'key'=>$key, 'token'=>$token );
		}
		$existing = (string) get_option( $key, '' );
		$created  = absint( strtok( $existing, ':' ) );
		if ( $created && time() - $created > self::LOCK_TTL ) {
			$this->delete_lock_value( $key, $existing );
			if ( add_option( $key, $token, '', false ) ) {
				return array( 'key'=>$key, 'token'=>$token );
			}
		}
		return false;
	}

	private function release_lock( $lock ) {
		if ( ! is_array( $lock ) || empty( $lock['key'] ) || ! isset( $lock['token'] ) ) {
			return;
		}
		$this->delete_lock_value( $lock['key'], (string) $lock['token'] );
	}

	private function delete_lock_value( $key, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $token ) ) );
		wp_cache_delete( $key, 'options' );
	}
}
