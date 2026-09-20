<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Order_Mapper {
	private $primed_orders = array();

	public function archive_for_rebuild( WC_Order $order, $run ) {
		$store = $order->get_meta( '_company_source_store', true );
		$source_id = absint( $order->get_meta( '_company_source_order_id', true ) );
		if ( ! Company_Order_Sync_Central_Rebuild::active() || ! $store || ! $source_id ) {
			return new WP_Error( 'rebuild_not_active', 'بازسازی معتبر فعال نیست.' );
		}
		$lock = $this->acquire_lock( $store, $source_id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			return Company_Order_Sync_Context::run_inbound( static function() use ( $order, $store, $source_id, $run ) {
				$order->update_meta_data( '_company_rebuild_archive', array( 'store'=>$store, 'source_id'=>$source_id, 'status'=>$order->get_status(), 'run'=>$run ) );
				$order->delete_meta_data( '_company_source_store' );
				$order->delete_meta_data( '_company_source_order_id' );
				$order->delete_meta_data( '_company_pending_outbound_status' );
					$order->save_meta_data();
					$order->delete( false );
					do_action( 'company_order_sync_central_order_changed', $order->get_id(), $store, $source_id );
				return true;
			} );
		} finally { $this->release_lock( $lock ); }
	}

	public function upsert( $store_id, array $payload, $request_id, $skip_unchanged = false ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) {
			return new WP_Error( 'sync_busy', 'Central rebuild is active.', array( 'status'=>503 ) );
		}
		if ( empty( $payload['id'] ) || empty( $payload['status'] ) ) {
			return new WP_Error( 'invalid_payload', 'Order ID and status are required.', array( 'status' => 400 ) );
		}

		$status         = sanitize_key( $payload['status'] );
		$valid_statuses = array_map(
			static function( $registered_status ) {
				return str_replace( 'wc-', '', $registered_status );
			},
			array_keys( wc_get_order_statuses() )
		);
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return new WP_Error( 'unsupported_status', 'Order status is not registered on Central.', array( 'status' => 422 ) );
		}
		if ( ! Company_Order_Sync_Settings::payload_is_in_scope( $store_id, $payload ) ) {
			return new WP_Error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', array( 'status' => 422 ) );
		}

		$source_order_id = absint( $payload['id'] );
		if ( $skip_unchanged ) {
			$existing = $this->find( $store_id, $source_order_id );
			if ( $this->can_skip_unchanged( $existing, $payload ) ) {
				return array( 'order_id'=>$existing->get_id(), 'created'=>false, 'status'=>$existing->get_status(), 'previous_status'=>$existing->get_status(), 'skipped'=>true );
			}
		}
		$lock            = $this->acquire_lock( $store_id, $source_order_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return Company_Order_Sync_Context::run_inbound(
				function() use ( $store_id, $source_order_id, $payload, $request_id, $skip_unchanged ) {
					if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return new WP_Error( 'sync_busy', 'Central rebuild superseded this request.', array( 'status'=>503 ) ); }
					$order   = $this->find( $store_id, $source_order_id );
					$created = false;
					$previous_status = $order instanceof WC_Order ? $order->get_status() : '';

					if ( ! $order ) {
						$order = wc_create_order( array( 'status' => 'pending' ) );
						if ( is_wp_error( $order ) ) {
							return $order;
						}
						$created = true;
					}

					$incoming_revision = (int) ( $payload['sync_revision'] ?? 0 );
					$current_revision  = (int) $order->get_meta( '_company_source_sync_revision', true );
					if ( ! $created && $skip_unchanged && $this->can_skip_unchanged( $order, $payload ) ) {
						return array( 'order_id'=>$order->get_id(), 'created'=>false, 'status'=>$order->get_status(), 'previous_status'=>$previous_status, 'skipped'=>true );
					}
					if ( ! $created && $incoming_revision && $current_revision && $incoming_revision < $current_revision ) {
						Company_Order_Sync_Logger::log(
							'info',
							'Stale source order update was ignored.',
							array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id, 'incoming_revision'=>$incoming_revision, 'current_revision'=>$current_revision, 'request_id'=>$request_id )
						);
						return array( 'order_id'=>$order->get_id(), 'created'=>false, 'status'=>$order->get_status(), 'previous_status'=>$previous_status, 'stale'=>true );
					}

					$this->map_order( $order, $store_id, $source_order_id, $payload, $request_id );
					$order->save();
					$this->primed_orders[ $this->mapping_key( $store_id, $source_order_id ) ] = $order;
					$this->sync_notes( $order, $payload );
					do_action( 'company_order_sync_central_order_changed', $order->get_id(), $store_id, $source_order_id );

					return array(
						'order_id' => $order->get_id(),
						'created'  => $created,
						'status'   => $order->get_status(),
						'previous_status' => $previous_status,
					);
				}
			);
		} catch ( Throwable $error ) {
			Company_Order_Sync_Logger::log(
				'error',
				'Central order mapping failed.',
				array(
					'store_id'       => $store_id,
					'source_order_id' => $source_order_id,
					'request_id'      => $request_id,
					'error'           => $error->getMessage(),
				)
			);

			return new WP_Error( 'order_mapping_failed', 'Order could not be saved.', array( 'status' => 500 ) );
		} finally {
			$this->release_lock( $lock );
		}
	}

	public function prime_existing_orders( $store_id, array $source_order_ids ) {
		$store_id        = sanitize_key( $store_id );
		$source_order_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_order_ids ) ) ) );
		$this->primed_orders = array();
		if ( ! $store_id || ! $source_order_ids ) {
			return;
		}
		foreach ( $source_order_ids as $source_order_id ) {
			$this->primed_orders[ $this->mapping_key( $store_id, $source_order_id ) ] = null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => max( count( $source_order_ids ) * 2, 20 ),
				'return'     => 'objects',
				'type'       => 'shop_order',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key'=>'_company_source_store', 'value'=>$store_id, 'compare'=>'=' ),
					array( 'key'=>'_company_source_order_id', 'value'=>array_map( 'strval', $source_order_ids ), 'compare'=>'IN' ),
				),
			)
		);
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$source_order_id = absint( $order->get_meta( '_company_source_order_id', true ) );
			$key = $this->mapping_key( $store_id, $source_order_id );
			if ( isset( $this->primed_orders[ $key ] ) ) {
				Company_Order_Sync_Logger::log( 'critical', 'Duplicate source mapping detected.', array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id ) );
				continue;
			}
			$this->primed_orders[ $key ] = $order;
		}
	}

	public function existing_order( $store_id, $source_order_id ) {
		return $this->find( $store_id, $source_order_id );
	}

	public function primed_existing_order( $store_id, $source_order_id ) {
		$key = $this->mapping_key( $store_id, $source_order_id );
		return isset( $this->primed_orders[ $key ] ) && $this->primed_orders[ $key ] instanceof WC_Order
			? $this->primed_orders[ $key ]
			: null;
	}

	private function can_skip_unchanged( $order, array $payload ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$incoming_revision = max( 0, (int) ( $payload['sync_revision'] ?? 0 ) );
		$current_revision  = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
		$source_lines = array_values( array_filter( (array) ( $payload['line_items'] ?? array() ), 'is_array' ) );
		$central_lines = $order->get_items( 'line_item' );
		$central_source_ids = array_map(
			static function( $item ) { return absint( $item->get_meta( '_company_source_line_item_id', true ) ); },
			array_values( $central_lines )
		);
		$source_ids = array_map( static function( $item ) { return absint( $item['id'] ?? 0 ); }, $source_lines );
		$items_match = count( $source_lines ) === count( $central_lines )
			&& count( array_unique( array_filter( $central_source_ids ) ) ) === count( $central_source_ids )
			&& $source_ids === $central_source_ids;
		return $incoming_revision > 0
			&& $incoming_revision === $current_revision
			&& ! sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) )
			&& $order->get_status() === sanitize_key( $payload['status'] ?? '' )
			&& $items_match;
	}

	public function sync_source_status( $store_id, array $payload, $request_id, $force = false ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) {
			return new WP_Error( 'sync_busy', 'Central rebuild is active.', array( 'status'=>503 ) );
		}
		$source_order_id = absint( $payload['id'] ?? 0 );
		$status          = sanitize_key( $payload['status'] ?? '' );
		if ( ! $source_order_id || ! $status ) {
			return new WP_Error( 'invalid_status_payload', 'Order ID and status are required.', array( 'status'=>400 ) );
		}
		$valid_statuses = array_map(
			static function( $registered_status ) {
				return str_replace( 'wc-', '', $registered_status );
			},
			array_keys( wc_get_order_statuses() )
		);
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return new WP_Error( 'unsupported_status', 'Order status is not registered on Central.', array( 'status'=>422 ) );
		}
		if ( ! Company_Order_Sync_Settings::payload_is_in_scope( $store_id, $payload ) ) {
			return new WP_Error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', array( 'status'=>422 ) );
		}

		$lock = $this->acquire_lock( $store_id, $source_order_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return Company_Order_Sync_Context::run_inbound(
				function() use ( $store_id, $source_order_id, $status, $payload, $request_id, $force ) {
					if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return new WP_Error( 'sync_busy', 'Central rebuild superseded this request.', array( 'status'=>503 ) ); }
					$order = $this->find( $store_id, $source_order_id );
					if ( ! $order ) {
						return new WP_Error( 'order_not_found', 'Source order is not present on Central.', array( 'status'=>404 ) );
					}
					$incoming_revision = max( 0, (int) ( $payload['sync_revision'] ?? 0 ) );
					$current_revision  = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
					$is_repair         = (bool) $force;

					// Revision is the ordering authority for source snapshots, including Repair.
					// A Repair response may have been fetched before a newer Central -> Store
					// status was confirmed. It must never roll that newer confirmed revision back.
					if ( $incoming_revision && $current_revision && $incoming_revision < $current_revision ) {
						return array( 'order_id'=>$order->get_id(), 'changed'=>false, 'stale'=>true, 'status'=>$order->get_status() );
					}

					$pending = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
					// An explicit operator change in Central is authoritative until the source
					// confirms that exact status. Repair/live snapshots that still contain the
					// pre-change source status are observations of an older state, not a reason
					// to undo the Central action.
					if ( $pending && $pending === $status ) {
						$order->delete_meta_data( '_company_pending_outbound_status' );
						$order->delete_meta_data( '_company_pending_status_retry_at' );
						$order->delete_meta_data( '_company_pending_outbound_dispatch_id' );
						$order->delete_meta_data( '_company_pending_outbound_sequence' );
						$order->delete_meta_data( '_company_pending_outbound_changed_at_gmt' );
						$order->delete_meta_data( '_company_pending_status_recovery_reason' );
						$pending = '';
					}
					if ( $pending && $pending !== $status ) {
						$order->update_meta_data( '_company_sync_status', 'pending' );
						$order->update_meta_data(
							'_company_last_sync_error',
							sprintf( 'Source status "%s" is older/different while Central status "%s" is still pending confirmation.', $status, $pending )
						);
						$order->save_meta_data();
						do_action( 'company_order_sync_pending_status_conflict', $order->get_id() );
						return array(
							'order_id'          => $order->get_id(),
							'changed'           => false,
							'pending_protected' => true,
							'repair'            => $is_repair,
							'source_status'     => $status,
							'status'            => $order->get_status(),
						);
					}

					$changed = $order->get_status() !== $status;
					if ( $changed ) {
						$order->set_status( $status );
					}
					if ( $incoming_revision ) {
						$order->update_meta_data(
							'_company_source_sync_revision',
							(string) max( $incoming_revision, $current_revision )
						);
					}
					if ( $is_repair && ! empty( $payload['date_modified_gmt'] ) ) {
						$order->update_meta_data( '_company_source_status_modified_gmt', sanitize_text_field( $payload['date_modified_gmt'] ) );
					}
					$order->update_meta_data( '_company_sync_status', 'synced' );
					$order->update_meta_data( '_company_last_sync_at', gmdate( 'c' ) );
					$order->update_meta_data( '_company_last_sync_error', '' );
					$order->update_meta_data( '_company_last_sync_request_id', sanitize_text_field( $request_id ) );
					$order->save();
					do_action( 'company_order_sync_central_order_changed', $order->get_id(), $store_id, $source_order_id );
					return array( 'order_id'=>$order->get_id(), 'changed'=>$changed, 'status'=>$order->get_status() );
				}
			);
		} catch ( Throwable $error ) {
			Company_Order_Sync_Logger::log( 'error', 'Central source status mapping failed.', array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id, 'request_id'=>$request_id, 'error'=>$error->getMessage() ) );
			return new WP_Error( 'status_mapping_failed', 'Order status could not be saved.', array( 'status'=>500 ) );
		} finally {
			$this->release_lock( $lock );
		}
	}


	/**
	 * Apply a freshly point-read source status as authoritative during the manual
	 * final convergence pass. Unlike normal Repair, this method is allowed to
	 * rebase an inflated historical source revision downward, but only when the
	 * Central order has not changed since the point-read started and no Central
	 * outbound status is pending.
	 */
	public function sync_source_status_authoritative( $store_id, array $payload, $request_id, $expected_central_status = '', $expected_central_revision = null ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) {
			return new WP_Error( 'sync_busy', 'Central rebuild is active.', array( 'status'=>503 ) );
		}
		$source_order_id = absint( $payload['id'] ?? 0 );
		$status          = sanitize_key( $payload['status'] ?? '' );
		if ( ! $source_order_id || ! $status ) {
			return new WP_Error( 'invalid_status_payload', 'Order ID and status are required.', array( 'status'=>400 ) );
		}
		$valid_statuses = array_map(
			static function( $registered_status ) {
				return str_replace( 'wc-', '', $registered_status );
			},
			array_keys( wc_get_order_statuses() )
		);
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return new WP_Error( 'unsupported_status', 'Order status is not registered on Central.', array( 'status'=>422 ) );
		}
		if ( ! Company_Order_Sync_Settings::payload_is_in_scope( $store_id, $payload ) ) {
			return new WP_Error( 'out_of_sync_range', 'Order is older than the configured synchronization boundary.', array( 'status'=>422 ) );
		}

		$lock = $this->acquire_lock( $store_id, $source_order_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return Company_Order_Sync_Context::run_inbound(
				function() use ( $store_id, $source_order_id, $status, $payload, $request_id, $expected_central_status, $expected_central_revision ) {
					if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return new WP_Error( 'sync_busy', 'Central rebuild superseded this request.', array( 'status'=>503 ) ); }
					$order = $this->find( $store_id, $source_order_id );
					if ( ! $order ) {
						return new WP_Error( 'order_not_found', 'Source order is not present on Central.', array( 'status'=>404 ) );
					}

					// A real operator change always wins until the source confirms it.
					$pending = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
					if ( $pending ) {
						if ( $pending === $status ) {
							$order->delete_meta_data( '_company_pending_outbound_status' );
							$order->delete_meta_data( '_company_pending_status_retry_at' );
							$order->delete_meta_data( '_company_pending_outbound_dispatch_id' );
							$order->delete_meta_data( '_company_pending_outbound_sequence' );
							$order->delete_meta_data( '_company_pending_outbound_changed_at_gmt' );
							$order->delete_meta_data( '_company_pending_status_recovery_reason' );
							$pending = '';
						} else {
							return array(
								'order_id'          => $order->get_id(),
								'changed'           => false,
								'pending_protected' => true,
								'status'            => $order->get_status(),
							);
						}
					}

					$current_status   = sanitize_key( $order->get_status() );
					$current_revision = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
					$expected_status  = sanitize_key( $expected_central_status );
					$expected_revision = null === $expected_central_revision ? null : max( 0, (int) $expected_central_revision );

					// If Central changed after the source point-read began, do not apply the
					// just-fetched record. The convergence worker will refetch this page.
					if ( $expected_status && $current_status !== $expected_status ) {
						return array( 'order_id'=>$order->get_id(), 'changed'=>false, 'race_retry'=>true, 'status'=>$current_status );
					}
					if ( null !== $expected_revision && $current_revision !== $expected_revision ) {
						return array( 'order_id'=>$order->get_id(), 'changed'=>false, 'race_retry'=>true, 'status'=>$current_status );
					}

					$incoming_revision = max( 0, (int) ( $payload['sync_revision'] ?? 0 ) );
					$changed           = $current_status !== $status;
					$revision_rebased  = $incoming_revision && $current_revision && $incoming_revision < $current_revision;
					$revision_changed  = $incoming_revision && $incoming_revision !== $current_revision;

					if ( $changed ) {
						$order->set_status( $status );
					}
					if ( $incoming_revision ) {
						// This record came from a fresh point-read of the source. Rebasing is
						// intentional: it clears historical revision inflation that otherwise
						// makes every future Repair treat the real source as "stale" forever.
						$order->update_meta_data( '_company_source_sync_revision', (string) $incoming_revision );
					}
					if ( ! empty( $payload['date_modified_gmt'] ) ) {
						$order->update_meta_data( '_company_source_status_modified_gmt', sanitize_text_field( $payload['date_modified_gmt'] ) );
					}
					if ( $revision_rebased ) {
						$order->update_meta_data( '_company_source_revision_rebased_at_gmt', gmdate( 'c' ) );
					}
					$order->update_meta_data( '_company_sync_status', 'synced' );
					$order->update_meta_data( '_company_last_sync_at', gmdate( 'c' ) );
					$order->update_meta_data( '_company_last_sync_error', '' );
					$order->update_meta_data( '_company_last_sync_request_id', sanitize_text_field( $request_id ) );
					$order->save();
					$this->primed_orders[ $this->mapping_key( $store_id, $source_order_id ) ] = $order;
					do_action( 'company_order_sync_central_order_changed', $order->get_id(), $store_id, $source_order_id );

					return array(
						'order_id'          => $order->get_id(),
						'changed'           => $changed,
						'revision_changed'  => $revision_changed,
						'revision_rebased'  => $revision_rebased,
						'status'            => $order->get_status(),
					);
				}
			);
		} catch ( Throwable $error ) {
			Company_Order_Sync_Logger::log( 'error', 'Final source status convergence mapping failed.', array( 'store_id'=>$store_id, 'source_order_id'=>$source_order_id, 'request_id'=>$request_id, 'error'=>$error->getMessage() ) );
			return new WP_Error( 'status_mapping_failed', 'Order status could not be saved.', array( 'status'=>500 ) );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function find( $store_id, $source_order_id ) {
		$key = $this->mapping_key( $store_id, $source_order_id );
		if ( array_key_exists( $key, $this->primed_orders ) && null !== $this->primed_orders[ $key ] ) {
			return $this->primed_orders[ $key ] ?: null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 2,
				'return'     => 'objects',
				'type'       => 'shop_order',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => '_company_source_store',
						'value'   => $store_id,
						'compare' => '=',
					),
					array(
						'key'     => '_company_source_order_id',
						'value'   => (string) $source_order_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( count( $orders ) > 1 ) {
			Company_Order_Sync_Logger::log(
				'critical',
				'Duplicate source mapping detected.',
				array(
					'store_id'       => $store_id,
					'source_order_id' => $source_order_id,
				)
			);
		}

		$this->primed_orders[ $key ] = $orders ? reset( $orders ) : false;
		return $this->primed_orders[ $key ] ?: null;
	}

	private function mapping_key( $store_id, $source_order_id ) {
		return sanitize_key( $store_id ) . ':' . absint( $source_order_id );
	}

	private function map_order( WC_Order $order, $store_id, $source_order_id, array $data, $request_id ) {
		$status                  = sanitize_key( $data['status'] );
		$incoming_revision       = max( 0, (int) ( $data['sync_revision'] ?? 0 ) );
		$current_source_revision = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
		$pending_outbound_status = sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) );
		if ( $pending_outbound_status && $pending_outbound_status === $status ) {
			$order->delete_meta_data( '_company_pending_outbound_status' );
			$order->delete_meta_data( '_company_pending_status_retry_at' );
			$order->delete_meta_data( '_company_pending_outbound_dispatch_id' );
			$order->delete_meta_data( '_company_pending_outbound_sequence' );
			$order->delete_meta_data( '_company_pending_outbound_changed_at_gmt' );
			$pending_outbound_status = '';
		}
		$status_conflict         = $pending_outbound_status && $pending_outbound_status !== $status;

		$order->update_meta_data( '_company_source_store', $store_id );
		$order->update_meta_data( '_company_source_order_id', $source_order_id );
		$order->update_meta_data( '_company_source_order_number', sanitize_text_field( $data['number'] ?? (string) $source_order_id ) );
		$order->update_meta_data( '_company_source_url', esc_url_raw( $data['source_url'] ?? '' ) );
		if ( ! empty( $data['sync_revision'] ) ) {
			$order->update_meta_data( '_company_source_sync_revision', (string) max( 0, (int) $data['sync_revision'] ) );
		}
		$order->update_meta_data( '_company_sync_status', $status_conflict ? 'conflict' : ( $pending_outbound_status ? 'pending' : 'synced' ) );
		$order->update_meta_data( '_company_last_sync_at', gmdate( 'c' ) );
		$order->update_meta_data(
			'_company_last_sync_error',
			$status_conflict
				? sprintf( 'Incoming status "%s" was not applied because "%s" is pending outbound.', $status, $pending_outbound_status )
				: ''
		);
		$order->update_meta_data( '_company_last_sync_request_id', $request_id );
		$this->map_delivery( $order, (array) ( $data['delivery'] ?? array() ) );
		$this->map_address_extras( $order, (array) ( $data['address_extras'] ?? array() ) );

		$order->set_currency( sanitize_text_field( $data['currency'] ?? get_woocommerce_currency() ) );
		$order->set_prices_include_tax( ! empty( $data['prices_include_tax'] ) );
		$order->set_discount_total( wc_format_decimal( $data['discount_total'] ?? 0 ) );
		$order->set_discount_tax( wc_format_decimal( $data['discount_tax'] ?? 0 ) );
		$order->set_shipping_total( wc_format_decimal( $data['shipping_total'] ?? 0 ) );
		$order->set_shipping_tax( wc_format_decimal( $data['shipping_tax'] ?? 0 ) );
		$order->set_cart_tax( wc_format_decimal( $data['cart_tax'] ?? 0 ) );
		$order->set_total( wc_format_decimal( $data['total'] ?? 0 ) );
		$order->set_payment_method( sanitize_text_field( $data['payment_method'] ?? '' ) );
		$order->set_payment_method_title( sanitize_text_field( $data['payment_method_title'] ?? '' ) );
		$order->set_transaction_id( sanitize_text_field( $data['transaction_id'] ?? '' ) );
		$order->set_customer_note( sanitize_textarea_field( $data['customer_note'] ?? '' ) );

		if ( ! empty( $data['date_created_gmt'] ) ) {
			$order->set_date_created( wc_string_to_datetime( $data['date_created_gmt'] ) );
		}

		$this->map_address( $order, 'billing', $data['billing'] ?? array() );
		$this->map_address( $order, 'shipping', $data['shipping'] ?? array() );
		$this->replace_items( $order, $data );
		$shipping_titles = array();
		foreach ( (array) ( $data['shipping_lines'] ?? array() ) as $shipping_line ) {
			$title = sanitize_text_field( $shipping_line['method_title'] ?? '' );
			if ( $title ) $shipping_titles[] = $title;
		}
		$shipping_title = implode( '، ', array_unique( $shipping_titles ) );
		$order->update_meta_data( '_company_shipping_method_filter', $shipping_title );
		$order->update_meta_data( '_company_shipping_group', $this->shipping_group( $shipping_title ) );
		if ( ! $pending_outbound_status ) {
			$order->set_status( $status );
		} elseif ( $status_conflict ) {
			do_action( 'company_order_sync_pending_status_conflict', $order->get_id() );
		}
	}

	private function shipping_group( $title ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtr( $text, array( 'ي'=>'ی', 'ك'=>'ک' ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$groups = array(
			'group:tipax-mahex' => array( 'تیپاکس', 'tipax', 'ماهکس', 'mahex' ),
			'group:courier'     => array( 'پیک', 'موتوری', 'courier' ),
			'group:post'        => array( 'پست', 'پیشتاز', 'post', 'pishtaz' ),
		);
		foreach ( $groups as $value=>$needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return $value;
				}
			}
		}
		return '';
	}

	private function map_delivery( WC_Order $order, array $delivery ) {
		$order->update_meta_data( '_company_delivery_timestamp', absint( $delivery['timestamp'] ?? 0 ) );
		$order->update_meta_data( '_company_delivery_time_slot', sanitize_text_field( $delivery['time_slot'] ?? '' ) );
		$order->update_meta_data( '_company_delivery_display', sanitize_text_field( $delivery['display'] ?? '' ) );
		$order->update_meta_data( '_company_delivery_at', sanitize_text_field( $delivery['at_iso'] ?? '' ) );
	}

	private function map_address_extras( WC_Order $order, array $extras ) {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address = isset( $extras[ $type ] ) && is_array( $extras[ $type ] ) ? $extras[ $type ] : array();
			foreach ( array( 'plaque', 'unit', 'floor' ) as $field ) {
				$order->update_meta_data(
					'_company_' . $type . '_' . $field,
					sanitize_text_field( $address[ $field ] ?? '' )
				);
			}
		}
	}

	private function map_address( WC_Order $order, $type, array $address ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );

		foreach ( $fields as $field ) {
			$setter = 'set_' . $type . '_' . $field;
			if ( is_callable( array( $order, $setter ) ) ) {
				$value = isset( $address[ $field ] ) ? (string) $address[ $field ] : '';
				if ( 'email' === $field ) {
					$value = sanitize_email( $value );
				} else {
					$value = sanitize_text_field( $value );
				}
				$order->{$setter}( $value );
			}
		}
	}

	private function replace_items( WC_Order $order, array $data ) {
		$existing_lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$source_line_id = absint( $item->get_meta( '_company_source_line_item_id', true ) );
			if ( $source_line_id ) {
				if ( isset( $existing_lines[ $source_line_id ] ) ) {
					// A prior concurrent ingestion may have created the same source line
					// twice. Keep one canonical item and remove every duplicate.
					$order->remove_item( $item_id );
				} else {
					$existing_lines[ $source_line_id ] = $item;
				}
			} else {
				$order->remove_item( $item_id );
			}
		}
		foreach ( $order->get_items( array( 'shipping', 'fee', 'coupon' ) ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}

		foreach ( (array) ( $data['line_items'] ?? array() ) as $source ) {
			$source_line_id = absint( $source['id'] ?? 0 );
			$item           = $source_line_id && isset( $existing_lines[ $source_line_id ] ) ? $existing_lines[ $source_line_id ] : new WC_Order_Item_Product();
			unset( $existing_lines[ $source_line_id ] );
			$item->set_name( sanitize_text_field( $source['name'] ?? '' ) );
			$item->set_quantity( max( 0, wc_stock_amount( $source['quantity'] ?? 0 ) ) );
			$item->set_subtotal( wc_format_decimal( $source['subtotal'] ?? 0 ) );
			$item->set_subtotal_tax( wc_format_decimal( $source['subtotal_tax'] ?? 0 ) );
			$item->set_total( wc_format_decimal( $source['total'] ?? 0 ) );
			$item->set_total_tax( wc_format_decimal( $source['total_tax'] ?? 0 ) );
			if ( isset( $source['taxes'] ) && is_array( $source['taxes'] ) ) {
				$item->set_taxes( $this->sanitize_taxes( $source['taxes'] ) );
			}
			$item->update_meta_data( '_company_source_line_item_id', $source_line_id );
			$item->update_meta_data( '_company_source_product_id', absint( $source['product_id'] ?? 0 ) );
			$item->update_meta_data( '_company_source_variation_id', absint( $source['variation_id'] ?? 0 ) );
			$item->update_meta_data( '_company_source_sku', sanitize_text_field( $source['sku'] ?? '' ) );
			$seller_id   = absint( $source['assigned_seller_id'] ?? 0 );
			$seller_name = sanitize_text_field( $source['assigned_seller_name'] ?? '' );
			$seller_login = sanitize_user( $source['assigned_seller_login'] ?? '', false );
			if ( $seller_name ) {
				$item->update_meta_data( Company_Order_Sync_Product_Seller::ITEM_ID_META, $seller_id );
				$item->update_meta_data( Company_Order_Sync_Product_Seller::ITEM_NAME_META, $seller_name );
				$item->update_meta_data( Company_Order_Sync_Product_Seller::ITEM_LOGIN_META, $seller_login );
			} else {
				$item->delete_meta_data( Company_Order_Sync_Product_Seller::ITEM_ID_META );
				$item->delete_meta_data( Company_Order_Sync_Product_Seller::ITEM_NAME_META );
				$item->delete_meta_data( Company_Order_Sync_Product_Seller::ITEM_LOGIN_META );
			}
			$order->add_item( $item );
		}
		foreach ( $existing_lines as $item ) {
			$order->remove_item( $item->get_id() );
		}

		foreach ( (array) ( $data['shipping_lines'] ?? array() ) as $source ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( sanitize_text_field( $source['method_title'] ?? '' ) );
			$item->set_method_id( sanitize_key( $source['method_id'] ?? '' ) );
			$item->set_instance_id( absint( $source['instance_id'] ?? 0 ) );
			$item->set_total( wc_format_decimal( $source['total'] ?? 0 ) );
			if ( isset( $source['taxes'] ) && is_array( $source['taxes'] ) ) {
				$item->set_taxes( $this->sanitize_taxes( $source['taxes'] ) );
			}
			$order->add_item( $item );
		}

		foreach ( (array) ( $data['fee_lines'] ?? array() ) as $source ) {
			$item = new WC_Order_Item_Fee();
			$item->set_name( sanitize_text_field( $source['name'] ?? '' ) );
			$tax_class = sanitize_text_field( $source['tax_class'] ?? '' );
			if ( $tax_class && ! in_array( $tax_class, WC_Tax::get_tax_class_slugs(), true ) ) {
				$tax_class = '';
			}
			$item->set_tax_class( $tax_class );
			$item->set_tax_status( sanitize_key( $source['tax_status'] ?? 'none' ) );
			$item->set_amount( wc_format_decimal( $source['amount'] ?? 0 ) );
			$item->set_total( wc_format_decimal( $source['total'] ?? 0 ) );
			$item->set_total_tax( wc_format_decimal( $source['total_tax'] ?? 0 ) );
			if ( isset( $source['taxes'] ) && is_array( $source['taxes'] ) ) {
				$item->set_taxes( $this->sanitize_taxes( $source['taxes'] ) );
			}
			$order->add_item( $item );
		}

		foreach ( (array) ( $data['coupon_lines'] ?? array() ) as $source ) {
			$item = new WC_Order_Item_Coupon();
			$item->set_code( wc_format_coupon_code( $source['code'] ?? '' ) );
			$item->set_discount( wc_format_decimal( $source['discount'] ?? 0 ) );
			$item->set_discount_tax( wc_format_decimal( $source['discount_tax'] ?? 0 ) );
			$order->add_item( $item );
		}
	}

	private function sync_notes( WC_Order $order, array $data ) {
		$seen = (array) $order->get_meta( '_company_source_note_ids', true );
		$seen = array_map( 'absint', $seen );

		foreach ( (array) ( $data['notes'] ?? array() ) as $note ) {
			$note_id = absint( $note['id'] ?? 0 );
			if ( ! $note_id || in_array( $note_id, $seen, true ) ) {
				continue;
			}

			$content = wp_kses_post( $note['content'] ?? '' );
			if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
				$order->add_order_note( $content, ! empty( $note['customer_note'] ), false );
			}
			$seen[] = $note_id;
		}

		$order->update_meta_data( '_company_source_note_ids', array_values( array_unique( $seen ) ) );
		$order->save_meta_data();
	}

	private function sanitize_taxes( array $taxes ) {
		$clean = array( 'total'=>array(), 'subtotal'=>array() );
		foreach ( array( 'total', 'subtotal' ) as $bucket ) {
			foreach ( (array) ( $taxes[ $bucket ] ?? array() ) as $rate_id => $amount ) {
				$rate_id = absint( $rate_id );
				if ( $rate_id ) {
					$clean[ $bucket ][ $rate_id ] = wc_format_decimal( $amount );
				}
			}
		}
		return $clean;
	}

	private function acquire_lock( $store_id, $source_order_id ) {
		$key = 'company_sync_lock_' . md5( $store_id . ':' . $source_order_id );
		$now = time();
		$token = $now . ':' . wp_generate_uuid4();

		if ( add_option( $key, $token, '', false ) ) {
			return array( 'key'=>$key, 'token'=>$token );
		}

		$existing = (string) get_option( $key, '' );
		$created  = absint( strtok( $existing, ':' ) );
		if ( $created && ( $now - $created ) > 120 ) {
			$this->delete_lock_value( $key, $existing );
			if ( add_option( $key, $token, '', false ) ) {
				return array( 'key'=>$key, 'token'=>$token );
			}
		}

		return new WP_Error( 'sync_busy', 'This order is already being synchronized.', array( 'status' => 409 ) );
	}

	private function release_lock( $lock ) {
		if ( is_array( $lock ) && ! empty( $lock['key'] ) && isset( $lock['token'] ) ) {
			$this->delete_lock_value( $lock['key'], (string) $lock['token'] );
		}
	}

	private function delete_lock_value( $key, $token ) {
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
}
