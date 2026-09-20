<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_REST_Controller extends WP_REST_Controller {

	protected $namespace = 'company-sync/v1';
	private $mapper;

	public function __construct() {
		$this->mapper = new Company_Order_Sync_Order_Mapper();
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		if ( 'central' === Company_Order_Sync_Settings::mode() ) {
			register_rest_route(
				$this->namespace,
				'/orders',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_order' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/batch',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_orders_batch' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/statuses/batch',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_order_statuses_batch' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		if ( 'store' === Company_Order_Sync_Settings::mode() ) {
			register_rest_route(
				$this->namespace,
				'/snapshot',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_snapshot' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/status-snapshot',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_status_snapshot' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/status-audit-details',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_status_audit_details' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/by-ids',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_orders_by_ids' ),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/(?P<id>[0-9]+)/status',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_status' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/(?P<id>[0-9]+)/tracking',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_tracking' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
			register_rest_route(
				$this->namespace,
				'/orders/(?P<id>[0-9]+)/notes',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_note' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array( 'required'=>true, 'sanitize_callback'=>'absint' ),
					),
				)
			);
		}
	}

	public function receive_snapshot( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store-snapshot:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || sanitize_key( $params['store_id'] ?? '' ) !== $store_id ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Snapshot payload does not match this store.', 400, $auth['request_id'] ) );
		}
		$snapshot = new Company_Order_Sync_Snapshot_Sync();
		$result   = $snapshot->export_snapshot( $request, $auth );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result, $auth['request_id'] );
		}
		return new WP_REST_Response( $result, 200 );
	}

	public function receive_status_snapshot( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store-status-audit:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || sanitize_key( $params['store_id'] ?? '' ) !== $store_id ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Status snapshot payload does not match this store.', 400, $auth['request_id'] ) );
		}
		$result = ( new Company_Order_Sync_Status_Repair() )->export_status_snapshot( $request, $auth );
		return is_wp_error( $result ) ? $this->error_response( $result, $auth['request_id'] ) : new WP_REST_Response( $result, 200 );
	}

	public function receive_status_audit_details( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store-status-audit-details:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || sanitize_key( $params['store_id'] ?? '' ) !== $store_id ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Status audit detail payload does not match this store.', 400, $auth['request_id'] ) );
		}
		$result = ( new Company_Order_Sync_Status_Audit() )->export_source_details( $request, $auth );
		return is_wp_error( $result ) ? $this->error_response( $result, $auth['request_id'] ) : new WP_REST_Response( $result, 200 );
	}

	public function receive_orders_by_ids( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store-order-pull:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || sanitize_key( $params['store_id'] ?? '' ) !== $store_id ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Order pull payload does not match this store.', 400, $auth['request_id'] ) );
		}
		$result = ( new Company_Order_Sync_Status_Repair() )->export_orders_by_ids( $request, $auth );
		return is_wp_error( $result ) ? $this->error_response( $result, $auth['request_id'] ) : new WP_REST_Response( $result, 200 );
	}

	public function receive_order( WP_REST_Request $request ) {
		$store_id = sanitize_key( $request->get_header( 'x-company-store' ) );
		$store    = Company_Order_Sync_Settings::central_store( $store_id );

		if ( ! $store || empty( $store['enabled'] ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'unknown_store', 'Store is not registered or enabled.', 403, $request->get_header( 'x-company-request-id' ) ) );
		}

		$auth = Company_Order_Sync_Security::verify( $request, $store_id, (string) $store['inbound_secret'], 'store-to-central:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! isset( $payload['order'] ) || ! is_array( $payload['order'] ) || sanitize_key( $payload['store_id'] ?? '' ) !== $store_id ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Payload store or order data is invalid.', 400, $auth['request_id'] ) );
		}

		$result = $this->mapper->upsert( $store_id, $payload['order'], $auth['request_id'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result, $auth['request_id'] );
		}

		Company_Order_Sync_Logger::log(
			'info',
			'Central order received successfully.',
			array(
				'store_id'        => $store_id,
				'source_order_id' => absint( $payload['order']['id'] ),
				'central_order_id' => $result['order_id'],
				'request_id'      => $auth['request_id'],
				'event'           => sanitize_key( $payload['event'] ?? 'order.updated' ),
			)
		);

		return new WP_REST_Response(
			array(
				'success'    => true,
				'code'       => $result['created'] ? 'order_created' : 'order_updated',
				'order_id'   => $result['order_id'],
				'created'    => $result['created'],
				'request_id' => $auth['request_id'],
			),
			$result['created'] ? 201 : 200
		);
	}

	public function receive_orders_batch( WP_REST_Request $request ) {
		$store_id = sanitize_key( $request->get_header( 'x-company-store' ) );
		$store    = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'unknown_store', 'Store is not registered or enabled.', 403, $request->get_header( 'x-company-request-id' ) ) );
		}
		$auth = Company_Order_Sync_Security::verify( $request, $store_id, (string) $store['inbound_secret'], 'store-to-central:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$payload = $request->get_json_params();
		$orders  = is_array( $payload ) && isset( $payload['orders'] ) && is_array( $payload['orders'] ) ? array_values( $payload['orders'] ) : array();
		if ( sanitize_key( is_array( $payload ) ? ( $payload['store_id'] ?? '' ) : '' ) !== $store_id || ! $orders || count( $orders ) > Company_Order_Sync_Queue::STORE_BATCH_SIZE ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_batch_payload', 'Batch payload must contain 1 to 20 orders for the authenticated store.', 400, $auth['request_id'] ) );
		}

		$source_ids = array();
		$failed_ids = array();
		$excluded_ids = array();
		$failures   = array();
		foreach ( $orders as $order ) {
			$source_order_id = is_array( $order ) ? absint( $order['id'] ?? 0 ) : 0;
			if ( ! is_array( $order ) || ! $source_order_id ) {
				$failed_ids[] = $source_order_id;
				continue;
			}
			$result = $this->mapper->upsert( $store_id, $order, $auth['request_id'] . ':' . $source_order_id );
			if ( is_wp_error( $result ) ) {
				if ( 'out_of_sync_range' === $result->get_error_code() ) {
					$excluded_ids[] = $source_order_id;
					continue;
				}
				$failed_ids[] = $source_order_id;
				$failures[]   = array( 'source_order_id'=>$source_order_id, 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message() );
				continue;
			}
			$source_ids[] = $source_order_id;
		}

		Company_Order_Sync_Logger::log( $failed_ids ? 'warning' : 'info', 'Central bulk source orders received.', array( 'store_id'=>$store_id, 'received'=>count( $orders ), 'succeeded'=>count( $source_ids ), 'failed'=>count( $failed_ids ), 'request_id'=>$auth['request_id'] ) );
		return new WP_REST_Response(
			array(
				'success'          => true,
				'code'             => $failed_ids ? 'batch_partially_updated' : 'batch_updated',
				'source_order_ids' => $source_ids,
				'failed_order_ids' => array_values( array_filter( $failed_ids ) ),
				'excluded_order_ids' => array_values( array_filter( $excluded_ids ) ),
				'failures'         => $failures,
				'request_id'       => $auth['request_id'],
			),
			200
		);
	}

	public function receive_order_statuses_batch( WP_REST_Request $request ) {
		$store_id = sanitize_key( $request->get_header( 'x-company-store' ) );
		$store    = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'unknown_store', 'Store is not registered or enabled.', 403, $request->get_header( 'x-company-request-id' ) ) );
		}
		$auth = Company_Order_Sync_Security::verify( $request, $store_id, (string) $store['inbound_secret'], 'store-to-central:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}
		$payload  = $request->get_json_params();
		$statuses = is_array( $payload ) && isset( $payload['statuses'] ) && is_array( $payload['statuses'] ) ? array_values( $payload['statuses'] ) : array();
		if ( sanitize_key( is_array( $payload ) ? ( $payload['store_id'] ?? '' ) : '' ) !== $store_id || ! $statuses || count( $statuses ) > Company_Order_Sync_Queue::STORE_STATUS_BATCH_SIZE ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_status_batch', 'Status batch must contain 1 to 50 orders for the authenticated store.', 400, $auth['request_id'] ) );
		}
		if ( ! empty( $payload['status_definitions'] ) && is_array( $payload['status_definitions'] ) ) {
			( new Company_Order_Sync_Snapshot_Sync() )->apply_statuses( $store_id, $payload['status_definitions'] );
		}

		$successful = array();
		$missing    = array();
		$failed     = array();
		$excluded   = array();
		foreach ( $statuses as $record ) {
			$source_order_id = is_array( $record ) ? absint( $record['id'] ?? 0 ) : 0;
			if ( ! $source_order_id ) {
				continue;
			}
			$result = $this->mapper->sync_source_status( $store_id, $record, $auth['request_id'] . ':' . $source_order_id );
			if ( is_wp_error( $result ) ) {
				if ( 'order_not_found' === $result->get_error_code() ) {
					$missing[] = $source_order_id;
				} elseif ( 'out_of_sync_range' === $result->get_error_code() ) {
					$excluded[] = $source_order_id;
				} else {
					$failed[] = $source_order_id;
				}
				continue;
			}
			$successful[] = $source_order_id;
		}
		Company_Order_Sync_Logger::log( $failed ? 'warning' : 'info', 'Central bulk source statuses received.', array( 'store_id'=>$store_id, 'received'=>count( $statuses ), 'succeeded'=>count( $successful ), 'missing'=>count( $missing ), 'failed'=>count( $failed ), 'request_id'=>$auth['request_id'] ) );
		return new WP_REST_Response(
			array(
				'success'          => true,
				'code'             => $failed || $missing ? 'status_batch_partially_updated' : 'status_batch_updated',
				'source_order_ids' => $successful,
				'missing_order_ids'=> $missing,
				'failed_order_ids' => $failed,
				'excluded_order_ids'=> $excluded,
				'request_id'       => $auth['request_id'],
			),
			200
		);
	}

	public function receive_status( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store:' . $store_id );

		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}

		$order_id = absint( $request['id'] );
		$payload  = $request->get_json_params();
		$status   = is_array( $payload ) ? sanitize_key( $payload['status'] ?? '' ) : '';

		if (
			! is_array( $payload )
			|| sanitize_key( $payload['store_id'] ?? '' ) !== $store_id
			|| absint( $payload['source_order_id'] ?? 0 ) !== $order_id
		) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Payload does not match the requested store order.', 400, $auth['request_id'] ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'order_not_found', 'Order was not found.', 404, $auth['request_id'] ) );
		}
		if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', 422, $auth['request_id'] ) );
		}

		$valid_statuses = array_map(
			static function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( wc_get_order_statuses() )
		);
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'unsupported_status', 'Order status is not supported.', 422, $auth['request_id'] ) );
		}

		$lock = $this->acquire_status_command_lock( $store_id, $order_id, $auth['request_id'] ?? '' );
		if ( is_wp_error( $lock ) ) {
			return $this->error_response( $lock );
		}

		try {
			// Re-read after the lock. Two Central HTTP requests can arrive out of
			// order, and without this lock both could observe the same old sequence.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return $this->error_response( Company_Order_Sync_Security::error( 'order_not_found', 'Order was not found.', 404, $auth['request_id'] ) );
			}

			$incoming_sequence = max( 0, (int) ( $payload['central_status_sequence'] ?? 0 ) );
			$accepted_sequence = max( 0, (int) $order->get_meta( '_company_last_central_status_sequence', true ) );
			$accepted_status   = sanitize_key( $order->get_meta( '_company_last_central_status_target', true ) );
			$accepted_revision = max( 0, (int) $order->get_meta( '_company_last_central_status_revision', true ) );
			$accepted_status   = $accepted_status ?: sanitize_key( $order->get_status() );
			$accepted_revision = $accepted_revision ?: max( 0, (int) $order->get_meta( '_company_sync_revision', true ) );

			// 0.13.11 requires ordering metadata for Central -> Store status writes.
			// Failing closed here is safer during a rolling upgrade: an older Central
			// may retry after it is upgraded, but an unsequenced late request can never
			// overwrite a newer status on the source.
			if ( ! $incoming_sequence ) {
				return $this->error_response(
					Company_Order_Sync_Security::error(
						'status_sequence_required',
						'Central status command is missing ordering metadata. Upgrade Central to the same plugin version and retry.',
						409,
						$auth['request_id']
					)
				);
			}

			// Once sequenced commands have started, a legacy/unsequenced request or
			// any lower sequence is stale and is never allowed to mutate the source.
			if ( $accepted_sequence && ( ! $incoming_sequence || $incoming_sequence < $accepted_sequence ) ) {
				Company_Order_Sync_Logger::log(
					'info',
					'Stale Central status command ignored on source store.',
					array(
						'store_id'          => $store_id,
						'source_order_id'   => $order_id,
						'incoming_sequence' => $incoming_sequence,
						'accepted_sequence' => $accepted_sequence,
						'request_id'        => $auth['request_id'],
					)
				);
				return new WP_REST_Response(
					array(
						'success'                 => true,
						'code'                    => 'status_superseded',
						'order_id'                => $order_id,
						'status'                  => $accepted_status,
						'sync_revision'           => (string) $accepted_revision,
						'central_status_sequence' => (string) $accepted_sequence,
						'superseded'              => true,
						'request_id'              => $auth['request_id'],
					),
					200
				);
			}

			// A retry of the same logical Central change is idempotent. Return the
			// acknowledgement of the first accepted request instead of re-applying
			// it after a newer manual source-side change.
			if ( $incoming_sequence && $accepted_sequence && $incoming_sequence === $accepted_sequence ) {
				return new WP_REST_Response(
					array(
						'success'                 => true,
						'code'                    => 'status_already_applied',
						'order_id'                => $order_id,
						'status'                  => $accepted_status,
						'sync_revision'           => (string) $accepted_revision,
						'central_status_sequence' => (string) $accepted_sequence,
						'replayed'                => true,
						'request_id'              => $auth['request_id'],
					),
					200
				);
			}

			$changed_by = isset( $payload['changed_by']['display_name'] ) ? sanitize_text_field( $payload['changed_by']['display_name'] ) : '';
			$changed_by = $changed_by ?: 'Central';
			$dispatch_id = sanitize_text_field( $payload['dispatch_id'] ?? '' );
			$central_changed_at = sanitize_text_field( $payload['central_changed_at_gmt'] ?? '' );
			$note = sprintf( 'وضعیت از Central توسط %s تغییر کرد.', $changed_by );
			$change_record = array(
				'previous_status'      => sanitize_key( $order->get_status() ),
				'status'               => $status,
				'actor'                => $changed_by,
				'changed_at_gmt'       => $central_changed_at ?: gmdate( 'c' ),
				'received_at_gmt'      => gmdate( 'c' ),
				'request_id'           => sanitize_text_field( $auth['request_id'] ?? '' ),
				'event_id'             => sanitize_text_field( $payload['event_id'] ?? '' ),
				'dispatch_id'          => $dispatch_id,
				'sequence'             => (string) $incoming_sequence,
				'central_order_id'     => absint( $payload['central_order_id'] ?? 0 ),
			);

			Company_Order_Sync_Context::run_inbound(
				function() use ( $order, $status, $note, $change_record, $incoming_sequence, $dispatch_id ) {
					if ( $order->get_status() !== $status ) {
						$order->update_status( $status, $note, true );
					}
					$revision = max( (int) $order->get_meta( '_company_sync_revision', true ) + 1, (int) floor( microtime( true ) * 1000000 ) );
					$order->update_meta_data( '_company_sync_revision', (string) $revision );
					$order->update_meta_data( '_company_last_central_status_change', $change_record );
					if ( $incoming_sequence ) {
						$order->update_meta_data( '_company_last_central_status_sequence', (string) $incoming_sequence );
						$order->update_meta_data( '_company_last_central_status_dispatch_id', $dispatch_id );
						$order->update_meta_data( '_company_last_central_status_target', $status );
						$order->update_meta_data( '_company_last_central_status_revision', (string) $revision );
					}
					$order->save_meta_data();
				}
			);

			$order = wc_get_order( $order_id );
			if ( ! $order || $order->get_status() !== $status ) {
				return $this->error_response( Company_Order_Sync_Security::error( 'status_not_persisted', 'The requested status was not persisted on the source order.', 409, $auth['request_id'] ) );
			}

			Company_Order_Sync_Logger::log(
				'info',
				'Store order status updated from Central.',
				array(
					'store_id'                => $store_id,
					'source_order_id'         => $order_id,
					'request_id'              => $auth['request_id'],
					'status'                  => $status,
					'central_status_sequence' => $incoming_sequence,
				)
			);

			return new WP_REST_Response(
				array(
					'success'                 => true,
					'code'                    => 'status_updated',
					'order_id'                => $order_id,
					'status'                  => $order->get_status(),
					'sync_revision'           => (string) $order->get_meta( '_company_sync_revision', true ),
					'central_status_sequence' => (string) $incoming_sequence,
					'request_id'              => $auth['request_id'],
				),
				200
			);
		} finally {
			$this->release_status_command_lock( $lock );
		}
	}

	public function receive_tracking( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store:' . $store_id );

		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}

		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		$payload  = $request->get_json_params();
		$code     = is_array( $payload ) ? trim( sanitize_text_field( $payload['tracking_code'] ?? '' ) ) : '';
		$provider = is_array( $payload ) ? esc_url_raw( $payload['provider_url'] ?? '' ) : '';

		if (
			! is_array( $payload )
			|| sanitize_key( $payload['store_id'] ?? '' ) !== $store_id
			|| absint( $payload['source_order_id'] ?? 0 ) !== $order_id
		) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Payload does not match the requested store order.', 400, $auth['request_id'] ) );
		}
		if ( ! $order ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'order_not_found', 'Order was not found.', 404, $auth['request_id'] ) );
		}
		if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', 422, $auth['request_id'] ) );
		}
		if ( '' === $code || strlen( $code ) > 200 || ! in_array( $provider, $this->tracking_providers(), true ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_tracking_data', 'Tracking code or provider is invalid.', 422, $auth['request_id'] ) );
		}

		$tracking_hash = hash( 'sha256', $code . '|' . $provider );
		$already_sent  = hash_equals( (string) $order->get_meta( '_company_tracking_sms_sent_hash', true ), $tracking_hash );
		$result        = true;

		if ( ! $already_sent ) {
			$result = Company_Order_Sync_Context::run_inbound(
				function() use ( $order, $code, $provider ) {
					return $this->send_tracking_sms( $order, $code, $provider );
				}
			);
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $result, $auth['request_id'] );
			}
		}

		Company_Order_Sync_Context::run_inbound(
			function() use ( $order, $code, $provider, $tracking_hash, $already_sent ) {
				$order->update_meta_data( '_company_tracking_code', $code );
				$order->update_meta_data( '_company_tracking_provider_url', $provider );
				$order->update_meta_data( '_company_tracking_sms_sent_hash', $tracking_hash );
				$order->update_meta_data( '_company_tracking_sent_at', gmdate( 'c' ) );
				$order->save();
				if ( ! $already_sent ) {
					$order->add_order_note( sprintf( 'کد رهگیری %s از Central ثبت و پیامک آن برای مشتری ارسال شد.', $code ) );
				}
			}
		);

		Company_Order_Sync_Logger::log(
			'info',
			$already_sent ? 'Tracking SMS was already sent; duplicate delivery skipped.' : 'Tracking code saved and SMS sent on source store.',
			array(
				'store_id'        => $store_id,
				'source_order_id' => $order_id,
				'request_id'      => $auth['request_id'],
				'provider_url'    => $provider,
			)
		);

		return new WP_REST_Response(
			array(
				'success'      => true,
				'code'         => $already_sent ? 'tracking_already_sent' : 'tracking_sms_sent',
				'order_id'     => $order_id,
				'tracking_code' => $code,
				'sms_sent'     => true,
				'request_id'   => $auth['request_id'],
			),
			200
		);
	}

	public function receive_note( WP_REST_Request $request ) {
		$store_id = sanitize_key( Company_Order_Sync_Settings::get( 'store_id', '' ) );
		$secret   = (string) Company_Order_Sync_Settings::get( 'store_inbound_secret', '' );
		$auth     = Company_Order_Sync_Security::verify( $request, $store_id, $secret, 'central-to-store:' . $store_id );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}

		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		$payload  = $request->get_json_params();
		$note_id  = is_array( $payload ) ? absint( $payload['central_note_id'] ?? 0 ) : 0;
		$note     = is_array( $payload ) ? trim( sanitize_textarea_field( $payload['note'] ?? '' ) ) : '';
		if (
			! is_array( $payload )
			|| sanitize_key( $payload['store_id'] ?? '' ) !== $store_id
			|| absint( $payload['source_order_id'] ?? 0 ) !== $order_id
		) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_payload', 'Payload does not match the requested store order.', 400, $auth['request_id'] ) );
		}
		if ( ! $order ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'order_not_found', 'Order was not found.', 404, $auth['request_id'] ) );
		}
		if ( ! Company_Order_Sync_Settings::order_is_in_scope( $order ) ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', 422, $auth['request_id'] ) );
		}
		if ( ! $note_id || '' === $note || function_exists( 'mb_strlen' ) && mb_strlen( $note ) > 2000 ) {
			return $this->error_response( Company_Order_Sync_Security::error( 'invalid_note', 'Order note is invalid.', 422, $auth['request_id'] ) );
		}

		$seen      = array_map( 'absint', (array) $order->get_meta( '_company_central_note_ids', true ) );
		$duplicate = in_array( $note_id, $seen, true );
		if ( ! $duplicate ) {
			Company_Order_Sync_Context::run_inbound(
				function() use ( $order, $note, $note_id, $seen ) {
					$order->add_order_note( $note, false, false );
					$seen[] = $note_id;
					$order->update_meta_data( '_company_central_note_ids', array_values( array_unique( $seen ) ) );
					$order->save_meta_data();
				}
			);
		}

		Company_Order_Sync_Logger::log( 'info', $duplicate ? 'Central note duplicate skipped on source store.' : 'Central note added to source store.', array( 'store_id'=>$store_id, 'source_order_id'=>$order_id, 'central_note_id'=>$note_id, 'request_id'=>$auth['request_id'] ) );
		return new WP_REST_Response( array( 'success'=>true, 'code'=>$duplicate ? 'note_already_exists' : 'note_added', 'order_id'=>$order_id, 'central_note_id'=>$note_id, 'request_id'=>$auth['request_id'] ), 200 );
	}

	private function acquire_status_command_lock( $store_id, $order_id, $request_id = '' ) {
		$key   = 'company_sync_status_command_' . md5( sanitize_key( $store_id ) . ':' . absint( $order_id ) );
		$token = time() . ':' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) {
			return array( 'key'=>$key, 'token'=>$token );
		}

		$existing = (string) get_option( $key, '' );
		$created  = absint( strtok( $existing, ':' ) );
		if ( $created && time() - $created > 90 ) {
			$this->delete_status_command_lock_value( $key, $existing );
			if ( add_option( $key, $token, '', false ) ) {
				return array( 'key'=>$key, 'token'=>$token );
			}
		}

		return Company_Order_Sync_Security::error(
			'sync_busy',
			'Another status command for this order is still being processed.',
			409,
			sanitize_text_field( $request_id )
		);
	}

	private function release_status_command_lock( $lock ) {
		if ( is_array( $lock ) && ! empty( $lock['key'] ) && isset( $lock['token'] ) ) {
			$this->delete_status_command_lock_value( $lock['key'], (string) $lock['token'] );
		}
	}

	private function delete_status_command_lock_value( $key, $token ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $token )
			)
		);
		wp_cache_delete( $key, 'options' );
	}

	private function send_tracking_sms( WC_Order $order, $code, $provider ) {
		if ( ! function_exists( 'PWSMS' ) || ! is_object( PWSMS() ) ) {
			return Company_Order_Sync_Security::error( 'pwoosms_unavailable', 'Persian WooCommerce SMS is not active on the source store.', 424 );
		}

		$pwoosms = PWSMS();
		$mobile  = $pwoosms->buyer_mobile( $order->get_id() );
		if ( ! $mobile || ! $pwoosms->validate_mobile( $mobile ) ) {
			return Company_Order_Sync_Security::error( 'invalid_customer_mobile', 'Customer mobile number is missing or invalid.', 422 );
		}

		$template = (string) $pwoosms->get_option( 'sms_body_set-post-tracking-code' );
		if ( '' === trim( $template ) ) {
			return Company_Order_Sync_Security::error( 'tracking_sms_template_missing', 'Tracking SMS template is empty in Persian WooCommerce SMS settings.', 422 );
		}
		$message  = $pwoosms->replace_short_codes(
			$template,
			'set-post-tracking-code',
			$order,
			array(
				'post_tracking_code' => $code,
				'post_tracking_url'  => $provider,
			)
		);
		$result = $pwoosms->send_sms(
			array(
				'post_id' => $order->get_id(),
				'type'    => 3,
				'mobile'  => $mobile,
				'message' => $message,
			)
		);

		if ( true !== $result ) {
			$result_text = is_wp_error( $result ) ? $result->get_error_message() : ( is_scalar( $result ) ? (string) $result : wp_json_encode( $result ) );
			$order->add_order_note( sprintf( 'ارسال پیامک کد رهگیری %s ناموفق بود. پاسخ وب‌سرویس: %s', $code, sanitize_text_field( $result_text ) ) );
			return Company_Order_Sync_Security::error( 'tracking_sms_failed', 'Persian WooCommerce SMS could not send the tracking message.', 502 );
		}

		return true;
	}

	private function tracking_providers() {
		return array(
			'https://tracking.post.ir/',
			'https://tipaxco.com/tracking',
			'https://mahex.com/tracking',
		);
	}

	private function error_response( WP_Error $error, $request_id = '' ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 500;
		if ( ! $request_id && is_array( $data ) && isset( $data['request_id'] ) ) {
			$request_id = $data['request_id'];
		}

		return new WP_REST_Response(
			array(
				'success'    => false,
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'request_id' => sanitize_text_field( $request_id ),
			),
			$status
		);
	}
}
