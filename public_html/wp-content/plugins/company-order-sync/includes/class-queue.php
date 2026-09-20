<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Queue {

	const GROUP                = 'company-order-sync';
	const STORE_ORDER_HOOK     = 'company_order_sync_push_store_order';
	const STORE_BATCH_HOOK     = 'company_order_sync_push_store_orders_batch';
	const STORE_STATUS_BATCH_HOOK = 'company_order_sync_push_store_statuses_batch';
	const CENTRAL_STATUS_HOOK  = 'company_order_sync_push_central_status';
	const CENTRAL_TRACKING_HOOK = 'company_order_sync_push_central_tracking';
	const CENTRAL_NOTE_HOOK    = 'company_order_sync_push_central_note';
	const RECONCILE_HOOK       = 'company_order_sync_reconcile_orders_v2';
	const CENTRAL_PENDING_SWEEP_HOOK = 'company_order_sync_reconcile_pending_central_statuses';
	const LEGACY_RECONCILE_HOOK = 'company_order_sync_reconcile_recent_orders';
	const RECONCILE_CURSOR_OPTION = 'company_order_sync_reconcile_cursor_v2';
	const CENTRAL_PENDING_SWEEP_VERSION_OPTION = 'company_order_sync_pending_status_sweep_version';
	const CENTRAL_PENDING_PROGRESS_OPTION = 'company_order_sync_pending_status_progress_v2';
	const MAX_ATTEMPTS         = 6;
	const STORE_BATCH_SIZE     = 20;
	const STORE_STATUS_BATCH_SIZE = 50;

	private $serializer;
	private $pending_store_orders = array();
	private $pending_store_statuses = array();

	public function __construct() {
		$this->serializer = new Company_Order_Sync_Order_Serializer();
	}

	public function hooks() {
		add_action( 'woocommerce_new_order', array( $this, 'capture_store_order' ), 50, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'capture_store_order' ), 50, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_store_status' ), 45, 4 );
		add_action( 'woocommerce_order_note_added', array( $this, 'capture_store_note' ), 50, 2 );
		add_action( 'woocommerce_new_order_item', array( $this, 'capture_store_item' ), 50, 3 );
		add_action( 'woocommerce_update_order_item', array( $this, 'capture_store_item' ), 50, 3 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'capture_store_item' ), 50, 1 );
		add_action( 'added_post_meta', array( $this, 'capture_store_post_meta' ), 50, 4 );
		add_action( 'updated_post_meta', array( $this, 'capture_store_post_meta' ), 50, 4 );
		add_action( 'deleted_post_meta', array( $this, 'capture_store_post_meta' ), 50, 4 );
		add_action( 'shutdown', array( $this, 'flush_store_orders' ), PHP_INT_MAX );
		add_action( 'shutdown', array( $this, 'capture_requested_bulk_orders' ), 5 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_central_status' ), 50, 4 );
		add_action( self::STORE_ORDER_HOOK, array( $this, 'process_store_order' ), 10, 3 );
		add_action( self::STORE_BATCH_HOOK, array( $this, 'process_store_orders_batch' ), 10, 2 );
		add_action( self::STORE_STATUS_BATCH_HOOK, array( $this, 'process_store_statuses_batch' ), 10, 2 );
		add_action( self::CENTRAL_STATUS_HOOK, array( $this, 'process_central_status' ), 10, 3 );
		add_action( self::CENTRAL_TRACKING_HOOK, array( $this, 'process_central_tracking' ), 10, 2 );
		add_action( self::CENTRAL_NOTE_HOOK, array( $this, 'process_central_note' ), 10, 3 );
		add_action( self::RECONCILE_HOOK, array( $this, 'reconcile_recent_orders' ) );
		add_action( self::CENTRAL_PENDING_SWEEP_HOOK, array( $this, 'reconcile_pending_central_statuses' ) );
		add_action( 'company_order_sync_manual_retry', array( $this, 'manual_retry' ), 10, 1 );
		add_action( 'company_order_sync_queue_tracking', array( $this, 'capture_central_tracking' ), 10, 3 );
		add_action( 'company_order_sync_queue_note', array( $this, 'capture_central_note' ), 10, 2 );
		add_action( 'company_order_sync_product_seller_changed', array( $this, 'capture_product_seller_orders' ), 10, 1 );
		add_action( 'company_order_sync_pending_status_conflict', array( $this, 'retry_pending_central_status' ), 10, 1 );
		add_action( 'init', array( $this, 'ensure_reconciliation_schedule' ), 30 );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'capture_handled_bulk_action' ), 100, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'capture_handled_bulk_action' ), 100, 3 );
	}

	public function capture_store_order( $order_id ) {
		if ( 'store' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Context::is_inbound() ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id || isset( $this->pending_store_orders[ $order_id ] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}
		if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		// Hooks can fire several times, including saves with no business changes.
		// Inspect the final persisted payload at shutdown before issuing a revision.
		$this->pending_store_orders[ $order_id ] = (int) $order->get_meta( '_company_sync_revision', true );
	}

	public function capture_store_status( $order_id ) {
		$this->capture_store_order( $order_id );
	}

	public function capture_store_note( $note_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->capture_store_order( $order->get_id() );
	}

	public function capture_store_item( $item_id, $item = null, $order_id = 0 ) {
		if ( ! $order_id && $item instanceof WC_Order_Item ) {
			$order_id = $item->get_order_id();
		}
		if ( ! $order_id && function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
			$order_id = wc_get_order_id_by_order_item_id( absint( $item_id ) );
		}
		$this->capture_store_order( $order_id );
	}

	public function capture_product_seller_orders( $product_id ) {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}

		global $wpdb;
		$order_items = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$order_ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT oi.order_id
				FROM {$order_items} oi
				INNER JOIN {$item_meta} product_ref ON product_ref.order_item_id=oi.order_item_id
				WHERE oi.order_item_type='line_item'
				AND product_ref.meta_key IN ('_product_id','_variation_id')
				AND CAST(product_ref.meta_value AS UNSIGNED)=%d",
				$product_id
			)
		);
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $order_ids ) ) ) ) as $order_id ) {
			$this->capture_store_order( $order_id );
		}
	}

	public function capture_store_post_meta( $meta_id, $object_id, $meta_key ) {
		if (
			'store' !== Company_Order_Sync_Settings::mode()
			|| Company_Order_Sync_Context::is_inbound()
			|| 0 === strpos( (string) $meta_key, '_company_' )
		) {
			return;
		}
		if ( ! in_array( get_post_type( absint( $object_id ) ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			return;
		}

		$order = wc_get_order( absint( $object_id ) );
		if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
			$this->capture_store_order( $order->get_id() );
		}
	}

	public function flush_store_orders() {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$this->finalize_pending_revisions();

		// Normal source changes must reach Central during the same request. Action
		// Scheduler/WP-Cron is reserved for retrying temporary delivery failures.
		if ( $this->pending_store_statuses ) {
			$statuses = array_values( $this->pending_store_statuses );
			$this->pending_store_statuses = array();
			foreach ( array_chunk( $statuses, self::STORE_STATUS_BATCH_SIZE ) as $batch ) {
				$this->process_store_statuses_batch( $batch, 0 );
			}
		}
		if ( ! $this->pending_store_orders ) {
			return;
		}

		$pending = $this->pending_store_orders;
		$this->pending_store_orders = array();
		if ( 1 === count( $pending ) ) {
			$order_id = (int) array_key_first( $pending );
			$this->process_store_order( $order_id, 0, (string) $pending[ $order_id ] );
			return;
		}
		$entries = array();
		foreach ( $pending as $order_id => $revision ) {
			$entries[] = array(
				'order_id' => absint( $order_id ),
				'revision' => (string) $revision,
			);
		}
		foreach ( array_chunk( $entries, self::STORE_BATCH_SIZE ) as $batch ) {
			$this->process_store_orders_batch( $batch, 0 );
		}
	}

	public function capture_requested_bulk_orders() {
		if (
			'store' !== Company_Order_Sync_Settings::mode()
			|| ! is_admin()
			|| ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) )
		) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		if ( 0 !== strpos( $action, 'mark_' ) ) {
			return;
		}
		foreach ( array( 'post', 'id', 'ids', 'order_id' ) as $key ) {
			if ( ! isset( $_REQUEST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_REQUEST[ $key ] );
			$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$this->capture_bulk_status_orders( $ids );
			break;
		}
	}

	public function capture_handled_bulk_action( $redirect_url, $action, $order_ids ) {
		if ( 'store' === Company_Order_Sync_Settings::mode() && 0 === strpos( sanitize_text_field( (string) $action ), 'mark_' ) ) {
			$this->capture_bulk_status_orders( (array) $order_ids );
		}
		return $redirect_url;
	}

	private function capture_bulk_status_orders( $order_ids ) {
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $order_ids ) ) ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
				unset( $this->pending_store_orders[ $order_id ] );
				$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
				continue;
			}
			$revision = (int) $order->get_meta( '_company_sync_revision', true );
			unset( $this->pending_store_orders[ $order_id ] );
			$created = $order->get_date_created();
			$modified = $order->get_date_modified();
			$this->pending_store_statuses[ $order_id ] = array(
				'order_id'          => $order_id,
				'status'            => $order->get_status(),
				'revision'          => (string) $revision,
				'date_created_gmt'  => $created instanceof WC_DateTime ? gmdate( 'c', $created->getTimestamp() ) : '',
				'date_modified_gmt' => $modified instanceof WC_DateTime ? gmdate( 'c', $modified->getTimestamp() ) : '',
			);
		}
	}

	private function finalize_pending_revisions() {
		$ids = array_unique( array_merge( array_keys( $this->pending_store_orders ), array_keys( $this->pending_store_statuses ) ) );
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				unset( $this->pending_store_orders[ $id ], $this->pending_store_statuses[ $id ] );
				continue;
			}
			$hash = $this->payload_fingerprint( $this->serializer->serialize( $order ) );
			if ( hash_equals( (string) $order->get_meta( '_company_observed_payload_hash', true ), $hash ) ) {
				unset( $this->pending_store_orders[ $id ], $this->pending_store_statuses[ $id ] );
				continue;
			}
			$revision = max( (int) $order->get_meta( '_company_sync_revision', true ) + 1, (int) floor( microtime( true ) * 1000000 ) );
			$order->update_meta_data( '_company_sync_revision', (string) $revision );
			$order->update_meta_data( '_company_observed_payload_hash', $hash );
			$order->save_meta_data();
			if ( isset( $this->pending_store_statuses[ $id ] ) ) {
				$this->pending_store_statuses[ $id ]['revision'] = (string) $revision;
				$this->pending_store_statuses[ $id ]['status'] = $order->get_status();
			} else {
				$this->pending_store_orders[ $id ] = $revision;
			}
			$this->mark_order( $id, 'pending', '', '' );
		}
	}

	public function capture_central_status( $order_id, $old_status, $new_status, $order ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Context::is_inbound() ) {
			return;
		}

		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_company_source_store', true ) ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$user       = wp_get_current_user();
		$actor      = $user->display_name ?: ( $user->user_login ?: 'Central' );
		$changed_by = array(
			'user_id'      => (int) $user->ID,
			'display_name' => sanitize_text_field( $actor ),
		);

		$dispatch_id    = wp_generate_uuid4();
		$sequence       = $this->next_central_status_sequence( $order );
		$changed_at_gmt = gmdate( 'c' );
		$order->update_meta_data( '_company_sync_changed_by', $changed_by );
		$order->update_meta_data( '_company_pending_outbound_status', sanitize_key( $new_status ) );
		$order->update_meta_data( '_company_pending_outbound_dispatch_id', $dispatch_id );
		$order->update_meta_data( '_company_pending_outbound_sequence', (string) $sequence );
		$order->update_meta_data( '_company_pending_outbound_changed_at_gmt', $changed_at_gmt );
		$order->update_meta_data(
			'_company_last_central_status_change',
			array(
				'previous_status' => sanitize_key( $old_status ),
				'status'          => sanitize_key( $new_status ),
				'actor'           => sanitize_text_field( $actor ),
				'changed_at_gmt'  => $changed_at_gmt,
				'dispatch_id'     => $dispatch_id,
				'sequence'        => (string) $sequence,
			)
		);
		$order->save_meta_data();
		$this->mark_order( $order_id, 'pending', '', '' );

		$defer = (bool) apply_filters(
			'company_order_sync_defer_central_status_dispatch',
			false,
			absint( $order_id ),
			sanitize_key( $new_status ),
			$order
		);
		if ( $defer ) {
			// The Central panel deliberately defers transport so the operator's
			// request cannot be blocked by one HTTP call per order. The pending
			// outbound meta remains authoritative until the source confirms it.
			$this->schedule_async( self::CENTRAL_STATUS_HOOK, array( absint( $order_id ), 0, $dispatch_id ), 1 );
			return;
		}

		// Non-panel status changes keep the previous immediate-dispatch behavior.
		$this->process_central_status( absint( $order_id ), 0, $dispatch_id );
	}

	public function capture_central_tracking( $order_id, $tracking_code, $provider_url ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$order->update_meta_data( '_company_tracking_code', sanitize_text_field( $tracking_code ) );
		$order->update_meta_data( '_company_tracking_provider_url', esc_url_raw( $provider_url ) );
		$order->update_meta_data( '_company_tracking_sync_status', 'pending' );
		$order->delete_meta_data( '_company_tracking_last_error' );
		$order->save_meta_data();
		do_action( 'company_order_sync_central_order_changed', $order->get_id(), sanitize_key( $order->get_meta( '_company_source_store', true ) ), absint( $order->get_meta( '_company_source_order_id', true ) ) );
		// An operator action must not depend on Action Scheduler/WP-Cron. Send it in
		// this request and keep the queue only as a transport-failure retry path.
		$this->process_central_tracking( absint( $order_id ), 0 );
	}

	public function capture_central_note( $order_id, $note_id ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_company_source_store', true ) || ! absint( $note_id ) ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$order->update_meta_data( '_company_note_sync_status', 'pending' );
		$order->delete_meta_data( '_company_note_sync_error' );
		$order->save_meta_data();
		$this->schedule_async( self::CENTRAL_NOTE_HOOK, array( absint( $order_id ), absint( $note_id ), 0 ) );
	}

	public function manual_retry( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->order_is_in_active_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$this->mark_order( $order_id, 'pending', '', '' );
		if ( 'central' === Company_Order_Sync_Settings::mode() ) {
			$user = wp_get_current_user();
			$actor = $user->display_name ?: ( $user->user_login ?: 'Central' );
			$order->update_meta_data(
				'_company_sync_changed_by',
				array(
					'user_id'      => (int) $user->ID,
					'display_name' => sanitize_text_field( $actor ),
				)
			);
			$dispatch_id    = wp_generate_uuid4();
			$sequence       = $this->next_central_status_sequence( $order );
			$changed_at_gmt = gmdate( 'c' );
			$order->update_meta_data( '_company_pending_outbound_status', $order->get_status() );
			$order->update_meta_data( '_company_pending_outbound_dispatch_id', $dispatch_id );
			$order->update_meta_data( '_company_pending_outbound_sequence', (string) $sequence );
			$order->update_meta_data( '_company_pending_outbound_changed_at_gmt', $changed_at_gmt );
			$order->update_meta_data(
				'_company_last_central_status_change',
				array(
					'previous_status' => sanitize_key( $order->get_status() ),
					'status'          => sanitize_key( $order->get_status() ),
					'actor'           => sanitize_text_field( $actor ),
					'changed_at_gmt'  => $changed_at_gmt,
					'dispatch_id'     => $dispatch_id,
					'sequence'        => (string) $sequence,
				)
			);
			$order->save_meta_data();
			$this->schedule_async(
				self::CENTRAL_STATUS_HOOK,
				array( absint( $order_id ), 0, $dispatch_id )
			);
		} else {
			$this->capture_store_order( $order_id );
		}
	}

	public function process_store_order( $order_id, $attempt = 0, $revision = '' ) {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$url      = untrailingslashit( Company_Order_Sync_Settings::get( 'central_url', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_outbound_secret', '' );
		$serialized = $this->serializer->serialize( $order );
		$payload  = array(
			'event'    => 'order.updated',
			'event_id' => $this->event_id( $store_id, $order, $serialized ),
			'store_id' => $store_id,
			'order'    => $serialized,
		);
		$payload['order']['source_url'] = home_url( '/' );

		$result = $this->send( $url . '/wp-json/company-sync/v1/orders', $store_id, $secret, $payload );
		$this->handle_result( $result, $order, self::STORE_ORDER_HOOK, array( absint( $order_id ) ), (int) $attempt, array( (string) $revision ) );
		if ( ! empty( $result['success'] ) ) {
			$order->update_meta_data( '_company_last_payload_hash', $this->payload_fingerprint( $serialized ) );
			$order->save_meta_data();
		}
	}

	public function process_store_orders_batch( $entries, $attempt = 0 ) {
		if ( 'store' !== Company_Order_Sync_Settings::mode() || ! is_array( $entries ) || ! $entries ) {
			return;
		}
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$url      = untrailingslashit( Company_Order_Sync_Settings::get( 'central_url', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_outbound_secret', '' );
		$orders   = array();
		$local    = array();
		foreach ( array_slice( $entries, 0, self::STORE_BATCH_SIZE ) as $entry ) {
			$order_id = absint( $entry['order_id'] ?? 0 );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
				$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
				continue;
			}
			$serialized = $this->serializer->serialize( $order );
			$serialized['source_url'] = home_url( '/' );
			$orders[] = $serialized;
			$local[ $order_id ] = array(
				'order'    => $order,
				'snapshot' => $serialized,
				'revision' => (string) ( $entry['revision'] ?? '' ),
			);
		}
		if ( ! $orders ) {
			return;
		}
		$payload = array(
			'event'    => 'orders.bulk_updated',
			'event_id' => wp_generate_uuid4(),
			'store_id' => $store_id,
			'orders'   => $orders,
		);
		$result  = $this->send( $url . '/wp-json/company-sync/v1/orders/batch', $store_id, $secret, $payload );
		if ( ! empty( $result['success'] ) ) {
			$data       = is_array( $result['data'] ?? null ) ? $result['data'] : array();
			$successful = array_map( 'absint', (array) ( $data['source_order_ids'] ?? array() ) );
			$failed     = array_map( 'absint', (array) ( $data['failed_order_ids'] ?? array() ) );
			$excluded   = array_map( 'absint', (array) ( $data['excluded_order_ids'] ?? array() ) );
			foreach ( $local as $order_id => $item ) {
				if ( in_array( $order_id, $excluded, true ) ) {
					$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', $result['request_id'] ?? '' );
					continue;
				}
				if ( in_array( $order_id, $failed, true ) || ( $successful && ! in_array( $order_id, $successful, true ) ) ) {
					$this->schedule_async( self::STORE_ORDER_HOOK, array( $order_id, 0, $item['revision'] ) );
					continue;
				}
				$this->mark_order( $order_id, 'synced', '', $result['request_id'] ?? '' );
				$item['order']->update_meta_data( '_company_last_payload_hash', $this->payload_fingerprint( $item['snapshot'] ) );
				$item['order']->save_meta_data();
			}
			Company_Order_Sync_Logger::log(
				'info',
				'Bulk source orders synchronized.',
				array(
					'store_id'   => $store_id,
					'orders'     => count( $local ),
					'failed'     => count( $failed ),
					'request_id' => $result['request_id'] ?? '',
				)
			);
			return;
		}

		$next_attempt = absint( $attempt ) + 1;
		$can_retry    = ! empty( $result['retriable'] ) && $next_attempt < self::MAX_ATTEMPTS;
		foreach ( $local as $order_id => $item ) {
			$this->mark_order( $order_id, $can_retry ? 'pending' : 'failed', $result['error'] ?? 'Unknown bulk sync error.', $result['request_id'] ?? '' );
		}
		Company_Order_Sync_Logger::log(
			$can_retry ? 'warning' : 'error',
			'Bulk source order synchronization failed.',
			array(
				'store_id'   => $store_id,
				'orders'     => count( $local ),
				'attempt'    => absint( $attempt ),
				'error'      => $result['error'] ?? '',
				'request_id' => $result['request_id'] ?? '',
			)
		);
		if ( $can_retry ) {
			$delays = array( 60, 300, 900, 3600, 21600 );
			$this->schedule_at( time() + $delays[ absint( $attempt ) ], self::STORE_BATCH_HOOK, array( $entries, $next_attempt ) );
		}
	}

	public function process_store_statuses_batch( $entries, $attempt = 0 ) {
		if ( 'store' !== Company_Order_Sync_Settings::mode() || ! is_array( $entries ) || ! $entries ) {
			return;
		}
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$url      = untrailingslashit( Company_Order_Sync_Settings::get( 'central_url', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_outbound_secret', '' );
		$statuses = array();
		$local    = array();
		foreach ( array_slice( $entries, 0, self::STORE_STATUS_BATCH_SIZE ) as $entry ) {
			$order_id = absint( $entry['order_id'] ?? 0 );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
				$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
				continue;
			}
			$created  = $order->get_date_created();
			$modified = $order->get_date_modified();
			$revision = max( (int) ( $entry['revision'] ?? 0 ), (int) $order->get_meta( '_company_sync_revision', true ) );
			$record   = array(
				'id'                => $order_id,
				'status'            => $order->get_status(),
				'sync_revision'     => (string) $revision,
				'date_created_gmt'  => $created instanceof WC_DateTime ? gmdate( 'c', $created->getTimestamp() ) : '',
				'date_modified_gmt' => $modified instanceof WC_DateTime ? gmdate( 'c', $modified->getTimestamp() ) : '',
			);
			$statuses[] = $record;
			$local[ $order_id ] = array( 'order'=>$order, 'record'=>$record );
		}
		if ( ! $statuses ) {
			return;
		}
		$payload = array(
			'event'    => 'order.statuses_bulk_updated',
			'event_id' => wp_generate_uuid4(),
			'store_id' => $store_id,
			'statuses' => $statuses,
			'status_definitions' => $this->status_definitions( $statuses ),
		);
		$result = $this->send( $url . '/wp-json/company-sync/v1/orders/statuses/batch', $store_id, $secret, $payload );
		if ( ! empty( $result['success'] ) ) {
			$data       = is_array( $result['data'] ?? null ) ? $result['data'] : array();
			$successful = array_map( 'absint', (array) ( $data['source_order_ids'] ?? array() ) );
			$missing    = array_map( 'absint', (array) ( $data['missing_order_ids'] ?? array() ) );
			$failed     = array_map( 'absint', (array) ( $data['failed_order_ids'] ?? array() ) );
			$excluded   = array_map( 'absint', (array) ( $data['excluded_order_ids'] ?? array() ) );
			$full_sync  = array();
			foreach ( $local as $order_id => $item ) {
				if ( in_array( $order_id, $excluded, true ) ) {
					$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', $result['request_id'] ?? '' );
					continue;
				}
				if ( in_array( $order_id, $missing, true ) || in_array( $order_id, $failed, true ) || ( $successful && ! in_array( $order_id, $successful, true ) ) ) {
					$full_sync[] = array(
						'order_id' => $order_id,
						'revision' => (string) $item['record']['sync_revision'],
					);
					continue;
				}
				$this->mark_order( $order_id, 'synced', '', $result['request_id'] ?? '' );
				$item['order']->update_meta_data( '_company_last_payload_hash', $this->payload_fingerprint( $this->serializer->serialize( $item['order'] ) ) );
				$item['order']->save_meta_data();
			}
			foreach ( array_chunk( $full_sync, self::STORE_BATCH_SIZE ) as $batch ) {
				$this->process_store_orders_batch( $batch, 0 );
			}
			Company_Order_Sync_Logger::log( 'info', 'Bulk source statuses synchronized.', array( 'store_id'=>$store_id, 'orders'=>count( $local ), 'missing'=>count( $missing ), 'failed'=>count( $failed ), 'request_id'=>$result['request_id'] ?? '' ) );
			return;
		}

		$next_attempt = absint( $attempt ) + 1;
		$can_retry    = ! empty( $result['retriable'] ) && $next_attempt < self::MAX_ATTEMPTS;
		foreach ( $local as $order_id => $item ) {
			$this->mark_order( $order_id, $can_retry ? 'pending' : 'failed', $result['error'] ?? 'Unknown bulk status sync error.', $result['request_id'] ?? '' );
		}
		Company_Order_Sync_Logger::log( $can_retry ? 'warning' : 'error', 'Bulk source status synchronization failed.', array( 'store_id'=>$store_id, 'orders'=>count( $local ), 'attempt'=>absint( $attempt ), 'error'=>$result['error'] ?? '', 'request_id'=>$result['request_id'] ?? '' ) );
		if ( $can_retry ) {
			$delays = array( 30, 120, 300, 900, 3600 );
			$this->schedule_at( time() + $delays[ absint( $attempt ) ], self::STORE_STATUS_BATCH_HOOK, array( $entries, $next_attempt ), 1 );
		}
	}

	private function next_central_status_sequence( WC_Order $order ) {
		$current = max( 0, (int) $order->get_meta( '_company_outbound_status_sequence', true ) );
		$next    = max( $current + 1, (int) floor( microtime( true ) * 1000000 ) );
		$order->update_meta_data( '_company_outbound_status_sequence', (string) $next );
		return $next;
	}

	private function ensure_pending_status_identity( WC_Order $order ) {
		$dispatch_id = sanitize_text_field( $order->get_meta( '_company_pending_outbound_dispatch_id', true ) );
		$sequence    = max( 0, (int) $order->get_meta( '_company_pending_outbound_sequence', true ) );
		$changed_at  = sanitize_text_field( $order->get_meta( '_company_pending_outbound_changed_at_gmt', true ) );
		$dirty       = false;

		if ( ! $dispatch_id ) {
			$dispatch_id = wp_generate_uuid4();
			$order->update_meta_data( '_company_pending_outbound_dispatch_id', $dispatch_id );
			$dirty = true;
		}
		if ( ! $sequence ) {
			$sequence = $this->next_central_status_sequence( $order );
			$order->update_meta_data( '_company_pending_outbound_sequence', (string) $sequence );
			$dirty = true;
		}
		if ( ! $changed_at ) {
			$changed_at = gmdate( 'c' );
			$order->update_meta_data( '_company_pending_outbound_changed_at_gmt', $changed_at );
			$dirty = true;
		}
		if ( $dirty ) {
			$order->save_meta_data();
		}

		return array(
			'dispatch_id'   => $dispatch_id,
			'sequence'      => $sequence,
			'changed_at_gmt'=> $changed_at,
		);
	}

	private function current_central_status_intent_matches( WC_Order $order, $expected_status, $expected_dispatch_id, $expected_sequence ) {
		$current_pending  = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
		$current_dispatch = sanitize_text_field( $order->get_meta( '_company_pending_outbound_dispatch_id', true ) );
		$current_sequence = max( 0, (int) $order->get_meta( '_company_pending_outbound_sequence', true ) );

		if ( $current_pending !== sanitize_key( $expected_status ) ) {
			return false;
		}
		if ( $expected_dispatch_id && ( ! $current_dispatch || ! hash_equals( $current_dispatch, sanitize_text_field( $expected_dispatch_id ) ) ) ) {
			return false;
		}
		if ( $expected_sequence && $current_sequence !== (int) $expected_sequence ) {
			return false;
		}
		return true;
	}

	public function process_central_status( $order_id, $attempt = 0, $dispatch_id = '' ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$store_id        = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$source_order_id = absint( $order->get_meta( '_company_source_order_id', true ) );
		$store           = Company_Order_Sync_Settings::central_store( $store_id );
		$changed_by      = $order->get_meta( '_company_sync_changed_by', true );
		$changed_by      = is_array( $changed_by ) ? $changed_by : array();

		if ( ! $store || empty( $store['enabled'] ) || ! $source_order_id ) {
			$this->mark_order( $order_id, 'failed', 'Source store connection is unavailable.', '' );
			return;
		}

		$pending_status = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
		if ( ! $pending_status ) {
			return;
		}

		$identity = $this->ensure_pending_status_identity( $order );
		$current_dispatch_id = $identity['dispatch_id'];
		$pending_sequence    = $identity['sequence'];
		$changed_at_gmt      = $identity['changed_at_gmt'];
		$dispatch_id         = sanitize_text_field( $dispatch_id );

		// A scheduled/retried job is allowed to send only the exact Central intent
		// it was created for. This also blocks an old response/retry after A→B→A.
		if ( $dispatch_id && ( ! $current_dispatch_id || ! hash_equals( $current_dispatch_id, $dispatch_id ) ) ) {
			return;
		}
		$dispatch_id = $current_dispatch_id;

		$payload = array(
			'event'                   => 'order.status_changed',
			'event_id'                => $dispatch_id ?: wp_generate_uuid4(),
			'store_id'                => $store_id,
			'source_order_id'         => $source_order_id,
			'status'                  => $pending_status,
			'central_order_id'        => $order->get_id(),
			'changed_by'              => $changed_by,
			'dispatch_id'             => $dispatch_id,
			'central_status_sequence' => (string) $pending_sequence,
			'central_changed_at_gmt'  => $changed_at_gmt,
		);

		$endpoint = untrailingslashit( $store['url'] ) . '/wp-json/company-sync/v1/orders/' . $source_order_id . '/status';
		$result   = $this->send( $endpoint, $store_id, (string) $store['outbound_secret'], $payload );
		$this->handle_result(
			$result,
			$order,
			self::CENTRAL_STATUS_HOOK,
			array( absint( $order_id ) ),
			(int) $attempt,
			array( $dispatch_id ),
			$pending_status,
			$dispatch_id,
			$pending_sequence
		);
	}


	/**
	 * Re-issue the current Central status as the same logical operator intent.
	 *
	 * This is used only by the final convergence pass when the latest explicit
	 * Central change is newer than the source's current status but the historical
	 * pending marker is no longer present. A new sequence/dispatch is generated so
	 * the source can safely order the command, while the original actor/time are
	 * preserved in _company_last_central_status_change.
	 */
	public function reassert_central_status_intent( $order_id, $reason = 'final_convergence' ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return false;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_company_source_store', true ) ) {
			return false;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			return false;
		}

		$existing_pending = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
		if ( $existing_pending ) {
			return array(
				'pending'     => true,
				'status'      => $existing_pending,
				'dispatch_id' => sanitize_text_field( $order->get_meta( '_company_pending_outbound_dispatch_id', true ) ),
				'sequence'    => (string) max( 0, (int) $order->get_meta( '_company_pending_outbound_sequence', true ) ),
			);
		}

		$status      = sanitize_key( $order->get_status() );
		$last_change = $order->get_meta( '_company_last_central_status_change', true );
		$last_change = is_array( $last_change ) ? $last_change : array();
		$changed_by  = $order->get_meta( '_company_sync_changed_by', true );
		$changed_by  = is_array( $changed_by ) ? $changed_by : array();
		$actor       = sanitize_text_field( $last_change['actor'] ?? ( $changed_by['display_name'] ?? 'Central' ) );
		$actor       = $actor ?: 'Central';
		$changed_at  = sanitize_text_field( $last_change['changed_at_gmt'] ?? '' );
		$changed_at  = $changed_at ?: gmdate( 'c' );
		$dispatch_id = wp_generate_uuid4();
		$sequence    = $this->next_central_status_sequence( $order );

		$order->update_meta_data(
			'_company_sync_changed_by',
			array(
				'user_id'      => absint( $changed_by['user_id'] ?? 0 ),
				'display_name' => $actor,
			)
		);
		$order->update_meta_data( '_company_pending_outbound_status', $status );
		$order->update_meta_data( '_company_pending_outbound_dispatch_id', $dispatch_id );
		$order->update_meta_data( '_company_pending_outbound_sequence', (string) $sequence );
		$order->update_meta_data( '_company_pending_outbound_changed_at_gmt', $changed_at );
		$order->update_meta_data( '_company_pending_status_recovery_reason', sanitize_key( $reason ) );
		$order->update_meta_data( '_company_sync_status', 'pending' );
		$order->update_meta_data( '_company_last_sync_error', '' );

		if ( $last_change ) {
			// This is the same logical operator change, not a new user action. Keep
			// its original timestamp/actor but advance the transport sequence.
			$last_change['sequence']       = (string) $sequence;
			$last_change['dispatch_id']    = $dispatch_id;
			$last_change['reasserted_at_gmt'] = gmdate( 'c' );
			$order->update_meta_data( '_company_last_central_status_change', $last_change );
		}
		$order->save_meta_data();

		$this->schedule_async( self::CENTRAL_STATUS_HOOK, array( $order->get_id(), 0, $dispatch_id ), 1 );
		Company_Order_Sync_Logger::log(
			'info',
			'Central status intent reasserted during final convergence.',
			array(
				'central_order_id' => $order->get_id(),
				'store_id'         => sanitize_key( $order->get_meta( '_company_source_store', true ) ),
				'source_order_id'  => absint( $order->get_meta( '_company_source_order_id', true ) ),
				'status'           => $status,
				'sequence'         => (string) $sequence,
				'reason'           => sanitize_key( $reason ),
			)
		);

		return array(
			'pending'     => true,
			'status'      => $status,
			'dispatch_id' => $dispatch_id,
			'sequence'    => (string) $sequence,
		);
	}

	public function retry_pending_central_status( $order_id ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) ) ) {
			return;
		}
		$retry_at = absint( $order->get_meta( '_company_pending_status_retry_at', true ) );
		if ( $retry_at > time() - 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		$identity = $this->ensure_pending_status_identity( $order );
		$order->update_meta_data( '_company_pending_status_retry_at', (string) time() );
		$order->save_meta_data();
		$args = array( absint( $order_id ), 0, $identity['dispatch_id'] );
		$this->schedule_async( self::CENTRAL_STATUS_HOOK, $args, 1 );
	}

	public function process_central_tracking( $order_id, $attempt = 0 ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_tracking( $order, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$store_id        = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$source_order_id = absint( $order->get_meta( '_company_source_order_id', true ) );
		$tracking_code   = sanitize_text_field( $order->get_meta( '_company_tracking_code', true ) );
		$provider_url    = esc_url_raw( $order->get_meta( '_company_tracking_provider_url', true ) );
		$store           = Company_Order_Sync_Settings::central_store( $store_id );

		if ( ! $store || empty( $store['enabled'] ) || ! $source_order_id || ! $tracking_code || ! $provider_url ) {
			$this->mark_tracking( $order, 'failed', 'Source store connection or tracking data is unavailable.', '' );
			return;
		}

		$payload = array(
			'event'            => 'order.tracking_submitted',
			'event_id'         => wp_generate_uuid4(),
			'store_id'         => $store_id,
			'source_order_id'  => $source_order_id,
			'central_order_id' => $order->get_id(),
			'tracking_code'    => $tracking_code,
			'provider_url'     => $provider_url,
		);
		$endpoint = untrailingslashit( $store['url'] ) . '/wp-json/company-sync/v1/orders/' . $source_order_id . '/tracking';
		$result   = $this->send( $endpoint, $store_id, (string) $store['outbound_secret'], $payload );
		$this->handle_tracking_result( $result, $order, (int) $attempt );
	}

	public function process_central_note( $order_id, $note_id, $attempt = 0 ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		$note  = function_exists( 'wc_get_order_note' ) ? wc_get_order_note( absint( $note_id ) ) : null;
		if ( ! $order || ! $note ) {
			return;
		}
		if ( ! $this->central_order_is_in_scope( $order ) ) {
			$this->mark_note( $order, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
			return;
		}

		$store_id        = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		$source_order_id = absint( $order->get_meta( '_company_source_order_id', true ) );
		$store           = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) || ! $source_order_id ) {
			$this->mark_note( $order, 'failed', 'Source store connection is unavailable.', '' );
			return;
		}

		$payload = array(
			'event'            => 'order.note_added',
			'event_id'         => 'central-note:' . absint( $note_id ),
			'store_id'         => $store_id,
			'source_order_id'  => $source_order_id,
			'central_order_id' => $order->get_id(),
			'central_note_id'  => absint( $note_id ),
			'note'             => sanitize_textarea_field( wp_strip_all_tags( (string) $note->content ) ),
		);
		$endpoint = untrailingslashit( $store['url'] ) . '/wp-json/company-sync/v1/orders/' . $source_order_id . '/notes';
		$result   = $this->send( $endpoint, $store_id, (string) $store['outbound_secret'], $payload );

		if ( ! empty( $result['success'] ) ) {
			$this->mark_note( $order, 'synced', '', $result['request_id'] ?? '' );
			Company_Order_Sync_Logger::log( 'info', 'Central order note synchronized to source store.', array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id, 'central_order_id'=>$order->get_id(), 'note_id'=>absint( $note_id ), 'request_id'=>$result['request_id'] ?? '' ) );
			return;
		}

		$next_attempt = (int) $attempt + 1;
		$can_retry    = ! empty( $result['retriable'] ) && $next_attempt < self::MAX_ATTEMPTS;
		$this->mark_note( $order, $can_retry ? 'pending' : 'failed', $result['error'] ?? 'Unknown note sync error.', $result['request_id'] ?? '' );
		Company_Order_Sync_Logger::log( $can_retry ? 'warning' : 'error', 'Central order note synchronization failed.', array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id, 'central_order_id'=>$order->get_id(), 'note_id'=>absint( $note_id ), 'attempt'=>(int) $attempt, 'error'=>$result['error'] ?? '' ) );
		if ( $can_retry ) {
			$delays = array( 60, 300, 900, 3600, 21600 );
			$this->schedule_at( time() + $delays[ (int) $attempt ], self::CENTRAL_NOTE_HOOK, array( $order->get_id(), absint( $note_id ), $next_attempt ) );
		}
	}

	private function mark_note( WC_Order $order, $status, $error, $request_id ) {
		$order->update_meta_data( '_company_note_sync_status', sanitize_key( $status ) );
		$order->update_meta_data( '_company_note_sync_error', sanitize_text_field( $error ) );
		$order->update_meta_data( '_company_note_last_sync_at', gmdate( 'c' ) );
		if ( $request_id ) {
			$order->update_meta_data( '_company_note_last_request_id', sanitize_text_field( $request_id ) );
		}
		$order->save_meta_data();
	}

	public function ensure_reconciliation_schedule() {
		$mode = Company_Order_Sync_Settings::mode();

		if ( 'central' === $mode ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::RECONCILE_HOOK, array(), self::GROUP );
				as_unschedule_all_actions( self::LEGACY_RECONCILE_HOOK, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( self::RECONCILE_HOOK );
			wp_clear_scheduled_hook( self::LEGACY_RECONCILE_HOOK );

			if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
				if ( ! as_next_scheduled_action( self::CENTRAL_PENDING_SWEEP_HOOK, array(), self::GROUP ) ) {
					as_schedule_recurring_action( time() + 30, 60, self::CENTRAL_PENDING_SWEEP_HOOK, array(), self::GROUP, true, 5 );
				}
			} elseif ( ! wp_next_scheduled( self::CENTRAL_PENDING_SWEEP_HOOK ) ) {
				wp_schedule_event( time() + 30, 'company_order_sync_one_minute', self::CENTRAL_PENDING_SWEEP_HOOK );
			}

			if ( (string) get_option( self::CENTRAL_PENDING_SWEEP_VERSION_OPTION, '' ) !== COMPANY_ORDER_SYNC_VERSION ) {
				update_option( self::CENTRAL_PENDING_SWEEP_VERSION_OPTION, COMPANY_ORDER_SYNC_VERSION, false );
				$this->schedule_async( self::CENTRAL_PENDING_SWEEP_HOOK, array(), 1 );
			}
			return;
		}

		if ( 'store' !== $mode ) {
			return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CENTRAL_PENDING_SWEEP_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::LEGACY_RECONCILE_HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::CENTRAL_PENDING_SWEEP_HOOK );
		wp_clear_scheduled_hook( self::LEGACY_RECONCILE_HOOK );

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::RECONCILE_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, 60, self::RECONCILE_HOOK, array(), self::GROUP, true, 5 );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'company_order_sync_one_minute', self::RECONCILE_HOOK );
		}
	}

	public function cron_schedules( $schedules ) {
		$schedules['company_order_sync_one_minute'] = array( 'interval'=>60, 'display'=>'هر یک دقیقه برای تطبیق سفارش‌ها' );
		return $schedules;
	}

	public static function pending_central_status_count() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return 0;
		}

		$statuses = array_map(
			static function( $key ) { return str_replace( 'wc-', '', $key ); },
			array_keys( wc_get_order_statuses() )
		);
		$query = wc_get_orders(
			array(
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => $statuses,
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ),
					array( 'key'=>'_company_pending_outbound_status', 'value'=>'', 'compare'=>'!=' ),
				),
			)
		);

		return is_object( $query ) && isset( $query->total ) ? absint( $query->total ) : 0;
	}

	/**
	 * Return a durable view of the current Central -> source status drain cycle.
	 *
	 * The cycle is intentionally independent from Repair / automatic snapshot runs.
	 * A Repair can therefore pause/resume as often as necessary without resetting the
	 * number the operator is watching. A new cycle starts only after the previous one
	 * reached zero and a real Central status change creates pending work again.
	 */
	public static function pending_central_status_progress( $notify_drained = false ) {
		$count    = self::pending_central_status_count();
		$state    = get_option( self::CENTRAL_PENDING_PROGRESS_OPTION, array() );
		$state    = is_array( $state ) ? $state : array();
		$previous = absint( $state['current_count'] ?? 0 );
		$now      = current_time( 'mysql' );
		$drained  = false;

		$active_cycle = ! empty( $state['cycle_id'] ) && empty( $state['completed_at'] );
		if ( $count > 0 ) {
			if ( ! $active_cycle ) {
				$state = array(
					'cycle_id'         => wp_generate_uuid4(),
					'started_at'       => $now,
					'started_count'    => $count,
					'peak_count'       => $count,
					'current_count'    => $count,
					'last_change_at'   => $now,
					'updated_at'       => $now,
					'completed_at'     => '',
					'drained_notified' => 0,
				);
			} else {
				$state['current_count'] = $count;
				$state['peak_count']    = max( $count, absint( $state['peak_count'] ?? 0 ), absint( $state['started_count'] ?? 0 ) );
				$state['updated_at']    = $now;
				if ( $previous !== $count ) {
					$state['last_change_at'] = $now;
				}
				$state['completed_at']     = '';
				$state['drained_notified'] = 0;
			}
		} else {
			if ( ! $state ) {
				$state = array(
					'cycle_id'         => '',
					'started_at'       => '',
					'started_count'    => 0,
					'peak_count'       => 0,
					'current_count'    => 0,
					'last_change_at'   => $now,
					'updated_at'       => $now,
					'completed_at'     => '',
					'drained_notified' => 1,
				);
			} else {
				$state['current_count'] = 0;
				$state['updated_at']    = $now;
				if ( $previous > 0 || ( $active_cycle && empty( $state['completed_at'] ) ) ) {
					$state['last_change_at'] = $now;
					$state['completed_at']   = $now;
					$drained                 = true;
				}
			}
		}

		$peak = max( absint( $state['peak_count'] ?? 0 ), absint( $state['started_count'] ?? 0 ) );
		$done = max( 0, $peak - $count );
		$state['confirmed_count'] = $done;
		$state['percent']         = $peak > 0 ? min( 100, max( 0, (int) round( ( $done / $peak ) * 100 ) ) ) : ( 0 === $count ? 100 : 0 );

		$should_notify = $notify_drained
			&& 0 === $count
			&& ! empty( $state['cycle_id'] )
			&& ! empty( $state['completed_at'] )
			&& empty( $state['drained_notified'] );
		if ( $should_notify ) {
			$state['drained_notified'] = 1;
		}
		update_option( self::CENTRAL_PENDING_PROGRESS_OPTION, $state, false );

		if ( $should_notify ) {
			do_action( 'company_order_sync_pending_status_queue_drained', $state );
		}

		return $state;
	}

	public function reconcile_pending_central_statuses() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$statuses = array_map(
			static function( $key ) { return str_replace( 'wc-', '', $key ); },
			array_keys( wc_get_order_statuses() )
		);
		$order_ids = wc_get_orders(
			array(
				'limit'      => 500,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => $statuses,
				'orderby'    => 'modified',
				'order'      => 'ASC',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ),
					array( 'key'=>'_company_pending_outbound_status', 'value'=>'', 'compare'=>'!=' ),
				),
			)
		);

		foreach ( (array) $order_ids as $order_id ) {
			$order = wc_get_order( absint( $order_id ) );
			if ( ! $order instanceof WC_Order || ! sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) ) ) {
				continue;
			}
			if ( ! $this->central_order_is_in_scope( $order ) ) {
				continue;
			}
			$retry_at = absint( $order->get_meta( '_company_pending_status_retry_at', true ) );
			if ( $retry_at && $retry_at > time() - 45 ) {
				continue;
			}
			$identity = $this->ensure_pending_status_identity( $order );
			$order->update_meta_data( '_company_pending_status_retry_at', (string) time() );
			$order->save_meta_data();
			$this->schedule_async(
				self::CENTRAL_STATUS_HOOK,
				array( $order->get_id(), 0, $identity['dispatch_id'] ),
				1
			);
		}

		// This sweep is the authoritative heartbeat for the drain cycle. When the
		// last pending status is confirmed, paused Repair / 15-minute maintenance
		// runs are resumed exactly once from their existing cursor/page.
		self::pending_central_status_progress( true );
	}

	public function reconcile_recent_orders() {
		if ( 'store' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}

		$statuses = array_map( static function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) );
		$recent   = array_merge(
			$this->modified_order_ids_after_cursor( 250 ),
			$this->recent_modified_order_ids( 3 * MINUTE_IN_SECONDS, 500 )
		);
		$retry    = wc_get_orders( array( 'limit'=>100, 'return'=>'ids', 'type'=>'shop_order', 'status'=>$statuses, 'meta_query'=>array( array( 'key'=>'_company_sync_status', 'value'=>array( 'pending', 'failed' ), 'compare'=>'IN' ) ), 'orderby'=>'modified', 'order'=>'ASC' ) );
		foreach ( array_values( array_unique( array_map( 'absint', array_merge( (array) $recent, (array) $retry ) ) ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
				$this->mark_order( $order_id, 'excluded', 'Order is older than the configured synchronization boundary.', '' );
				continue;
			}
			$sync_status = sanitize_key( $order->get_meta( '_company_sync_status', true ) );
			$last_sync   = strtotime( (string) $order->get_meta( '_company_last_sync_at', true ) );
			$last_hash   = (string) $order->get_meta( '_company_last_payload_hash', true );
			$current_hash = $this->payload_fingerprint( $this->serializer->serialize( $order ) );
			if ( 'pending' === $sync_status && $last_sync && $last_sync > time() - 2 * MINUTE_IN_SECONDS ) {
				continue;
			}
			if ( 'synced' === $sync_status && $last_hash && hash_equals( $last_hash, $current_hash ) ) {
				continue;
			}
			$this->capture_store_order( $order_id );
		}
	}

	private function modified_order_ids_after_cursor( $limit ) {
		global $wpdb;
		$cursor = get_option( self::RECONCILE_CURSOR_OPTION, array() );
		$cursor = is_array( $cursor ) ? $cursor : array();
		$cursor_gmt = sanitize_text_field( $cursor['gmt'] ?? '' );
		$cursor_id  = absint( $cursor['id'] ?? 0 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursor_gmt ) ) {
			$cursor_gmt = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
			$cursor_id  = 0;
		}
		$minimum = Company_Order_Sync_Settings::source_min_date();
		try {
			$minimum_gmt = $minimum ? gmdate( 'Y-m-d H:i:s', ( new DateTimeImmutable( $minimum . ' 00:00:00', wp_timezone() ) )->getTimestamp() ) : '1970-01-01 00:00:00';
		} catch ( Exception $error ) {
			$minimum_gmt = '1970-01-01 00:00:00';
		}
		$is_hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $is_hpos ) {
			$table      = $wpdb->prefix . 'wc_orders';
			$sql        = "SELECT id, date_updated_gmt AS modified_gmt FROM {$table} WHERE type = 'shop_order' AND date_created_gmt >= %s AND (date_updated_gmt > %s OR (date_updated_gmt = %s AND id > %d)) ORDER BY date_updated_gmt ASC, id ASC LIMIT %d";
		} else {
			$table      = $wpdb->posts;
			$sql        = "SELECT ID AS id, post_modified_gmt AS modified_gmt FROM {$table} WHERE post_type = 'shop_order' AND post_date_gmt >= %s AND (post_modified_gmt > %s OR (post_modified_gmt = %s AND ID > %d)) ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d";
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $minimum_gmt, $cursor_gmt, $cursor_gmt, $cursor_id, max( 10, absint( $limit ) ) ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		$last = end( $rows );
		update_option( self::RECONCILE_CURSOR_OPTION, array( 'gmt'=>sanitize_text_field( $last['modified_gmt'] ), 'id'=>absint( $last['id'] ) ), false );
		return array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
	}

	private function recent_modified_order_ids( $seconds, $limit ) {
		global $wpdb;
		$minimum = Company_Order_Sync_Settings::source_min_date();
		try {
			$minimum_gmt = $minimum ? gmdate( 'Y-m-d H:i:s', ( new DateTimeImmutable( $minimum . ' 00:00:00', wp_timezone() ) )->getTimestamp() ) : '1970-01-01 00:00:00';
		} catch ( Exception $error ) {
			$minimum_gmt = '1970-01-01 00:00:00';
		}
		$modified_gmt = gmdate( 'Y-m-d H:i:s', time() - max( MINUTE_IN_SECONDS, absint( $seconds ) ) );
		$is_hpos      = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $is_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';
			$sql   = "SELECT id FROM {$table} WHERE type = 'shop_order' AND date_created_gmt >= %s AND date_updated_gmt >= %s ORDER BY date_updated_gmt DESC, id DESC LIMIT %d";
		} else {
			$table = $wpdb->posts;
			$sql   = "SELECT ID AS id FROM {$table} WHERE post_type = 'shop_order' AND post_date_gmt >= %s AND post_modified_gmt >= %s ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d";
		}
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $minimum_gmt, $modified_gmt, max( 10, absint( $limit ) ) ) );
		return array_values( array_filter( array_map( 'absint', (array) $rows ) ) );
	}

	private function status_definitions( array $records ) {
		$registered = wc_get_order_statuses();
		$definitions = array();
		foreach ( $records as $record ) {
			$slug = sanitize_key( $record['status'] ?? '' );
			if ( ! $slug || isset( $definitions[ $slug ] ) ) {
				continue;
			}
			$definitions[ $slug ] = array(
				'slug'  => $slug,
				'label' => wp_strip_all_tags( $registered[ 'wc-' . $slug ] ?? $slug ),
			);
		}
		return array_values( $definitions );
	}

	public static function unschedule_reconciliation() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECONCILE_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::CENTRAL_PENDING_SWEEP_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::LEGACY_RECONCILE_HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::RECONCILE_HOOK );
		wp_clear_scheduled_hook( self::CENTRAL_PENDING_SWEEP_HOOK );
		wp_clear_scheduled_hook( self::LEGACY_RECONCILE_HOOK );
	}

	private function handle_tracking_result( array $result, WC_Order $order, $attempt ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		if ( ! empty( $result['success'] ) ) {
			$this->mark_tracking( $order, 'synced', '', $result['request_id'] ?? '' );
			$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			if ( 'tracking_already_sent' !== ( $data['code'] ?? '' ) ) {
				$order->add_order_note( sprintf( 'کد رهگیری %s در سایت مبدأ ثبت و پیامک آن ارسال شد.', $order->get_meta( '_company_tracking_code', true ) ) );
			}
			Company_Order_Sync_Logger::log(
				'info',
				'Tracking code synchronization succeeded.',
				array(
					'store_id'         => sanitize_key( $order->get_meta( '_company_source_store', true ) ),
					'source_order_id'  => absint( $order->get_meta( '_company_source_order_id', true ) ),
					'central_order_id' => $order->get_id(),
					'request_id'       => $result['request_id'] ?? '',
				)
			);
			return;
		}

		$next_attempt = $attempt + 1;
		$can_retry    = ! empty( $result['retriable'] ) && $next_attempt < self::MAX_ATTEMPTS;
		$this->mark_tracking( $order, $can_retry ? 'pending' : 'failed', $result['error'] ?? 'Unknown tracking sync error.', $result['request_id'] ?? '' );
		Company_Order_Sync_Logger::log(
			$can_retry ? 'warning' : 'error',
			'Tracking code synchronization failed.',
			array(
				'store_id'         => sanitize_key( $order->get_meta( '_company_source_store', true ) ),
				'source_order_id'  => absint( $order->get_meta( '_company_source_order_id', true ) ),
				'central_order_id' => $order->get_id(),
				'attempt'          => $attempt,
				'retriable'        => $can_retry,
				'request_id'       => $result['request_id'] ?? '',
				'error'            => $result['error'] ?? '',
			)
		);

		if ( $can_retry ) {
			$delays = array( 60, 300, 900, 3600, 21600 );
			$this->schedule_at( time() + $delays[ $attempt ], self::CENTRAL_TRACKING_HOOK, array( $order->get_id(), $next_attempt ) );
		}
	}

	private function mark_tracking( WC_Order $order, $status, $error, $request_id ) {
		$order->update_meta_data( '_company_tracking_sync_status', sanitize_key( $status ) );
		$order->update_meta_data( '_company_tracking_last_error', sanitize_text_field( $error ) );
		$order->update_meta_data( '_company_tracking_last_sync_at', gmdate( 'c' ) );
		if ( $request_id ) {
			$order->update_meta_data( '_company_tracking_last_request_id', sanitize_text_field( $request_id ) );
		}
		$order->save_meta_data();
	}

	private function send( $url, $store_id, $secret, array $payload ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) {
			return array( 'success'=>false, 'retriable'=>true, 'error'=>'Central rebuild is active.', 'request_id'=>'' );
		}
		$secure_url = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
		$secure_url = $secure_url || (bool) apply_filters( 'company_order_sync_allow_insecure_http', false, $url );
		if ( ! $secure_url || ! wp_http_validate_url( $url ) || strlen( $secret ) < 32 || ! $store_id ) {
			return array(
				'success'   => false,
				'retriable' => false,
				'error'     => 'HTTPS URL, store ID, or secret is not configured correctly.',
				'request_id' => '',
			);
		}

		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return array( 'success' => false, 'retriable' => false, 'error' => 'Payload JSON encoding failed.', 'request_id' => '' );
		}

		$headers    = Company_Order_Sync_Security::headers( $store_id, $secret, $body );
		$request_id = $headers['X-Company-Request-ID'];
		$response   = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'retriable' => true, 'error' => $response->get_error_message(), 'request_id' => $request_id );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $data ) && ! empty( $data['success'] ) ) {
			return array( 'success' => true, 'request_id' => $request_id, 'data' => $data );
		}

		$code      = is_array( $data ) && isset( $data['code'] ) ? sanitize_key( $data['code'] ) : 'http_' . $status;
		$message   = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'Remote endpoint returned HTTP ' . $status . '.';
		$retriable = in_array( $status, array( 408, 425, 429 ), true ) || ( $status >= 500 && $status <= 599 ) || in_array( $code, array( 'sync_busy', 'status_not_persisted' ), true );

		return array( 'success' => false, 'retriable' => $retriable, 'error' => $code . ': ' . $message, 'request_id' => $request_id );
	}

	private function handle_result( array $result, WC_Order $order, $hook, array $base_args, $attempt, array $tail_args = array(), $expected_status = '', $expected_dispatch_id = '', $expected_sequence = 0 ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$store_id        = $order->get_meta( '_company_source_store', true ) ?: Company_Order_Sync_Settings::get( 'store_id', '' );
		$source_order_id = $order->get_meta( '_company_source_order_id', true ) ?: $order->get_id();
		$log_context     = array(
			'store_id'         => sanitize_key( $store_id ),
			'source_order_id'  => absint( $source_order_id ),
			'central_order_id' => $order->get_meta( '_company_source_store', true ) ? $order->get_id() : 0,
			'event'            => $hook,
		);

		if ( self::CENTRAL_STATUS_HOOK === $hook ) {
			$current = wc_get_order( $order->get_id() );
			if ( ! $current instanceof WC_Order || ! $this->current_central_status_intent_matches( $current, $expected_status, $expected_dispatch_id, $expected_sequence ) ) {
				Company_Order_Sync_Logger::log(
					'info',
					'Obsolete Central status result ignored because a newer intent exists.',
					array_merge(
						$log_context,
						array(
							'expected_status'   => sanitize_key( $expected_status ),
							'expected_dispatch' => sanitize_text_field( $expected_dispatch_id ),
							'expected_sequence' => (string) max( 0, (int) $expected_sequence ),
						)
					)
				);
				return;
			}

			if ( ! empty( $result['success'] ) ) {
				$confirmed_status = sanitize_key( $result['data']['status'] ?? '' );
				if ( ! $expected_status || $confirmed_status !== sanitize_key( $expected_status ) ) {
					$result = array(
						'success'    => false,
						'retriable'  => empty( $result['data']['superseded'] ),
						'error'      => ! empty( $result['data']['superseded'] )
							? 'Source rejected an older Central status command because a newer command was already accepted.'
							: 'Source did not confirm the requested status.',
						'request_id' => $result['request_id'] ?? '',
					);
				}
			}
		}

		if ( ! empty( $result['success'] ) ) {
			if ( self::CENTRAL_STATUS_HOOK === $hook ) {
				$current = wc_get_order( $order->get_id() );
				if ( ! $current instanceof WC_Order || ! $this->current_central_status_intent_matches( $current, $expected_status, $expected_dispatch_id, $expected_sequence ) ) {
					return;
				}
				$current->update_meta_data( '_company_sync_status', 'synced' );
				$current->update_meta_data( '_company_last_sync_at', gmdate( 'c' ) );
				$current->update_meta_data( '_company_last_sync_error', '' );
				if ( ! empty( $result['request_id'] ) ) {
					$current->update_meta_data( '_company_last_sync_request_id', sanitize_text_field( $result['request_id'] ) );
				}
				$current->delete_meta_data( '_company_pending_outbound_status' );
				$current->delete_meta_data( '_company_pending_status_retry_at' );
				$current->delete_meta_data( '_company_pending_outbound_dispatch_id' );
				$current->delete_meta_data( '_company_pending_outbound_sequence' );
				$current->delete_meta_data( '_company_pending_outbound_changed_at_gmt' );
				$current->delete_meta_data( '_company_pending_status_recovery_reason' );
				$confirmed_revision = max( 0, (int) ( $result['data']['sync_revision'] ?? 0 ) );
				if ( $confirmed_revision ) {
					$current->update_meta_data(
						'_company_source_sync_revision',
						(string) max( $confirmed_revision, (int) $current->get_meta( '_company_source_sync_revision', true ) )
					);
				}
				$current->save_meta_data();
			} else {
				$this->mark_order( $order->get_id(), 'synced', '', $result['request_id'] ?? '' );
			}

			$log_context['request_id'] = $result['request_id'] ?? '';
			Company_Order_Sync_Logger::log( 'info', 'Order synchronization succeeded.', $log_context );
			return;
		}

		if ( self::CENTRAL_STATUS_HOOK === $hook ) {
			$current = wc_get_order( $order->get_id() );
			if ( ! $current instanceof WC_Order || ! $this->current_central_status_intent_matches( $current, $expected_status, $expected_dispatch_id, $expected_sequence ) ) {
				return;
			}
		}

		$next_attempt = $attempt + 1;
		$can_retry    = ! empty( $result['retriable'] ) && $next_attempt < self::MAX_ATTEMPTS;
		$this->mark_order( $order->get_id(), $can_retry ? 'pending' : 'failed', $result['error'] ?? 'Unknown sync error.', $result['request_id'] ?? '' );

		Company_Order_Sync_Logger::log(
			$can_retry ? 'warning' : 'error',
			'Order synchronization failed.',
			array_merge(
				$log_context,
				array(
					'attempt'    => $attempt,
					'retriable'  => $can_retry,
					'request_id' => $result['request_id'] ?? '',
					'error'      => $result['error'] ?? '',
				)
			)
		);

		if ( $can_retry ) {
			$delays = array( 60, 300, 900, 3600, 21600 );
			$args   = array_merge( $base_args, array( $next_attempt ), $tail_args );
			$this->schedule_at( time() + $delays[ $attempt ], $hook, $args );
		}
	}

	private function mark_order( $order_id, $status, $error, $request_id ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_company_sync_status', sanitize_key( $status ) );
		$order->update_meta_data( '_company_last_sync_at', gmdate( 'c' ) );
		$order->update_meta_data( '_company_last_sync_error', sanitize_textarea_field( $error ) );
		if ( $request_id ) {
			$order->update_meta_data( '_company_last_sync_request_id', sanitize_text_field( $request_id ) );
		}
		$order->save_meta_data();
	}

	private function order_is_in_active_scope( WC_Order $order ) {
		return 'central' === Company_Order_Sync_Settings::mode()
			? $this->central_order_is_in_scope( $order )
			: Company_Order_Sync_Settings::order_is_in_scope( $order );
	}

	private function central_order_is_in_scope( WC_Order $order ) {
		$store_id = sanitize_key( $order->get_meta( '_company_source_store', true ) );
		if ( ! $store_id ) {
			return true;
		}
		$date = Company_Order_Sync_Settings::central_min_date( $store_id );
		return Company_Order_Sync_Settings::order_is_in_scope( $order, $date );
	}

	private function event_id( $store_id, WC_Order $order, array $snapshot ) {
		$encoded = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', $store_id . ':' . $order->get_id() . ':' . ( $encoded ?: microtime( true ) ) );
	}

	private function payload_fingerprint( array $snapshot ) {
		unset( $snapshot['sync_revision'], $snapshot['date_modified_gmt'], $snapshot['source_url'] );
		$encoded = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', $encoded ?: '' );
	}

	private function schedule_async( $hook, array $args, $priority = 10 ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP, true, max( 0, min( 255, absint( $priority ) ) ) );
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 1, $hook, $args );
		}
		Company_Order_Sync_Logger::log( 'warning', 'Action Scheduler is unavailable; WP-Cron fallback was used.', array( 'hook' => $hook ) );
	}

	private function schedule_at( $timestamp, $hook, array $args, $priority = 10 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, true, max( 0, min( 255, absint( $priority ) ) ) );
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
		Company_Order_Sync_Logger::log( 'warning', 'Action Scheduler is unavailable for retry; WP-Cron fallback was used.', array( 'hook' => $hook ) );
	}
}
