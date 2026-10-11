<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fill missing Central gateway types from the confirmed historical gateway IDs. */
final class Company_Order_Sync_Payment_Gateway_Backfill {
	const HOOK = 'company_order_sync_backfill_payment_gateway_type_v2';
	const STATE_OPTION = 'company_order_sync_payment_gateway_backfill_v2';
	const BATCH_SIZE = 100;

	public function hooks() {
		add_action( 'init', array( $this, 'maybe_schedule' ), 50 );
		add_action( self::HOOK, array( $this, 'process' ) );
		add_action( 'company_order_sync_central_order_changed', array( $this, 'fill_changed_order' ), 10, 1 );
	}

	public static function type_for_method( $method ) {
		if ( ! is_scalar( $method ) ) {
			return '';
		}
		$types = array(
			'aghsat_market_gateway' => 'installment',
			'bwdk' => 'installment',
			'melli_pay' => 'cash',
			'tara360' => 'installment',
			'WC_AsanPardakht' => 'cash',
			'WC_Gateway_SnappPay' => 'installment',
			'WC_Gateway_TorobPay' => 'installment',
			'WC_ZarinPlus' => 'installment',
			'WC_ZarinPlus_ewano' => 'installment',
			'WC_ZarinPlus_vibe' => 'installment',
			'WC_ZPal' => 'cash',
			'WCDigiPay' => 'installment',
		);
		return $types[ (string) $method ] ?? '';
	}

	public function fill_changed_order( $order_id ) {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			$this->fill_order( $order );
		}
	}

	private function fill_order( WC_Order $order ) {
		if ( ! in_array( $order->get_meta( '_company_source_store', true ), array( 'site1', 'site2' ), true ) ) {
			return 'unrelated';
		}
		if ( in_array( $order->get_meta( '_zb_payment_gateway_type', true ), array( 'cash', 'installment' ), true ) ) {
			return 'present';
		}
		$type = self::type_for_method( $order->get_payment_method() );
		if ( ! $type ) {
			return 'unmapped';
		}
		$order->update_meta_data( '_zb_payment_gateway_type', $type );
		$order->save_meta_data();
		return 'updated';
	}

	public function maybe_schedule() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Central_Rebuild::blocked() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! empty( $state['complete'] ) ) {
			return;
		}
		$this->schedule();
	}

	private function schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), Company_Order_Sync_Queue::GROUP ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$this->enqueue_next();
	}

	private function enqueue_next() {
		if ( function_exists( 'as_enqueue_async_action' ) && as_enqueue_async_action( self::HOOK, array(), Company_Order_Sync_Queue::GROUP ) ) {
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}

	public function process() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Central_Rebuild::blocked() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ! empty( $state['complete'] ) ) {
			return;
		}
		$page = max( 1, absint( $state['page'] ?? 1 ) );
		$query = wc_get_orders( array(
			'limit' => self::BATCH_SIZE,
			'paged' => $page,
			'paginate' => true,
			'return' => 'ids',
			'type' => 'shop_order',
			'status' => array_keys( wc_get_order_statuses() ),
			'orderby' => 'ID',
			'order' => 'ASC',
		) );
		$updated = absint( $state['updated'] ?? 0 );
		$unmapped = (array) ( $state['unmapped_methods'] ?? array() );
		foreach ( (array) ( $query->orders ?? array() ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$result = $this->fill_order( $order );
			if ( 'unmapped' === $result ) {
				$method = $order->get_payment_method();
				$unmapped[ $method ?: '(empty)' ] = absint( $unmapped[ $method ?: '(empty)' ] ?? 0 ) + 1;
			} elseif ( 'updated' === $result ) {
				++$updated;
			}
		}
		$last_page = max( 1, absint( $query->max_num_pages ?? 1 ) );
		$complete = $page >= $last_page;
		update_option( self::STATE_OPTION, array(
			'page' => $complete ? $page : $page + 1,
			'updated' => $updated,
			'unmapped_methods' => $unmapped,
			'complete' => $complete,
			'finished_at' => $complete ? gmdate( 'c' ) : '',
		), false );
		if ( ! $complete ) {
			// The current Action Scheduler job is still marked running here, so the
			// normal duplicate check would suppress the next batch.
			$this->enqueue_next();
		}
	}
}
