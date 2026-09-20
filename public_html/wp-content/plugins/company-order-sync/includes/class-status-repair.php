<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Status_Repair {

	const HOOK         = 'company_order_sync_repair_statuses';
	const STATE_OPTION = 'company_order_sync_status_repair_state';
	const PER_PAGE     = 100;
	const MAX_ATTEMPTS = 4;
	const LOCK_TTL     = 180;
	const AUTO_HOOK    = 'company_order_sync_auto_status_repair';
	const AUTO_CURSOR_OPTION = 'company_order_sync_auto_repair_cursor';
	const AUTO_SCHEDULE = 'company_order_sync_fifteen_minutes';
	const AUTO_INTERVAL = 900;

	private $serializer;
	private $mapper;

	public function __construct() {
		$this->serializer = new Company_Order_Sync_Order_Serializer();
		$this->mapper     = new Company_Order_Sync_Order_Mapper();
	}

	public function hooks() {
		add_action( 'admin_post_company_order_sync_status_repair', array( $this, 'start' ) );
		add_action( 'admin_post_company_order_sync_status_converge', array( $this, 'start_converge' ) );
		add_action( 'admin_post_company_order_sync_status_repair_stop', array( $this, 'stop' ) );
		add_action( self::HOOK, array( $this, 'process' ), 10, 7 );
		add_action( 'init', array( $this, 'recover' ), 45 );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_auto_schedule' ), 35 );
		add_action( self::AUTO_HOOK, array( $this, 'run_auto_repair' ) );
		add_action( 'company_order_sync_pending_status_queue_drained', array( $this, 'resume_after_pending_drain' ), 20, 1 );
	}

	public function cron_schedules( $schedules ) {
		$schedules[ self::AUTO_SCHEDULE ] = array( 'interval'=>self::AUTO_INTERVAL, 'display'=>'هر ۱۵ دقیقه برای ترمیم خودکار وضعیت‌ها' );
		return $schedules;
	}

	public function ensure_auto_schedule() {
		if ( get_option( Company_Order_Sync_Retention_Maintenance::MIGRATION_OPTION, false ) ) {
			return;
		}
		if ( 'central' !== Company_Order_Sync_Settings::mode() || ! Company_Order_Sync_Settings::auto_status_repair_enabled() ) {
			self::unschedule_auto();
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			wp_clear_scheduled_hook( self::AUTO_HOOK );
			if ( ! as_next_scheduled_action( self::AUTO_HOOK, array(), Company_Order_Sync_Queue::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, self::AUTO_INTERVAL, self::AUTO_HOOK, array(), Company_Order_Sync_Queue::GROUP, true, 8 );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::AUTO_HOOK ) ) {
			wp_schedule_event( time() + 60, self::AUTO_SCHEDULE, self::AUTO_HOOK );
		}
	}

	public static function unschedule_auto() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AUTO_HOOK, array(), Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( self::AUTO_HOOK );
	}

	/**
	 * Whether a manual final-convergence run is actively owning a store.
	 *
	 * The 15-minute/full snapshot maintenance uses this as a soft mutex so it
	 * cannot change Central underneath an authoritative point-read pass.
	 */
	public static function final_convergence_active( $store_id = '' ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || 'final' !== sanitize_key( $state['mode'] ?? '' ) ) {
			return false;
		}
		if ( ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending' ), true ) ) {
			return false;
		}
		$store_id = sanitize_key( $store_id );
		return ! $store_id || $store_id === sanitize_key( $state['store_id'] ?? '' );
	}

	public static function run_active() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending' ), true );
	}

	public function run_auto_repair() {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		if ( 'central' !== Company_Order_Sync_Settings::mode() || ! Company_Order_Sync_Settings::auto_status_repair_enabled() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		// Central operator changes must reach their source stores before any repair
		// snapshot is allowed to reconcile other status differences. This gives the
		// system a deterministic order: drain intent queue first, repair second.
		$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
		if ( $pending_count > 0 ) {
			if ( 'waiting_pending' === ( $state['status'] ?? '' ) ) {
				$this->update_state(
					array(
						'waiting_pending_count' => $pending_count,
						'message' => sprintf( 'Repair در همان نقطه قبلی منتظر تأیید %s تغییر وضعیت Central است.', number_format_i18n( $pending_count ) ),
					)
				);
			}
			return;
		}

		if ( 'waiting_pending' === ( $state['status'] ?? '' ) ) {
			$this->resume_waiting_pending();
			return;
		}
		if ( in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying' ), true ) ) {
			return;
		}

		$stopped_at = strtotime( (string) ( $state['updated_at'] ?? '' ) );
		if ( 'stopped' === ( $state['status'] ?? '' ) && 'auto' !== ( $state['trigger'] ?? '' ) && $stopped_at && $stopped_at > time() - HOUR_IN_SECONDS ) {
			return;
		}

		$store_id = $this->next_auto_store();
		if ( ! $store_id ) {
			return;
		}
		$this->begin_run( $store_id, $this->auto_window_from( $store_id ), 'auto' );
	}

	private function next_auto_store() {
		$stores = array();
		foreach ( array_keys( (array) Company_Order_Sync_Settings::enabled_central_stores() ) as $store_id ) {
			$store_id = sanitize_key( $store_id );
			if ( $store_id && Company_Order_Sync_Settings::central_min_date( $store_id ) ) {
				$stores[] = $store_id;
			}
		}
		if ( ! $stores ) {
			return '';
		}

		$last  = sanitize_key( (string) get_option( self::AUTO_CURSOR_OPTION, '' ) );
		$index = array_search( $last, $stores, true );
		$next  = false === $index || ! isset( $stores[ $index + 1 ] ) ? $stores[0] : $stores[ $index + 1 ];
		update_option( self::AUTO_CURSOR_OPTION, $next, false );
		return $next;
	}

	private function auto_window_from( $store_id ) {
		$days      = max( 1, absint( apply_filters( 'company_order_sync_auto_repair_window_days', Company_Order_Sync_Settings::auto_status_repair_days(), $store_id ) ) );
		$window    = wp_date( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$min_date  = Company_Order_Sync_Settings::central_min_date( $store_id );
		return ( $min_date && $window < $min_date ) ? $min_date : $window;
	}

	public function start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_status_repair', '_cos_nonce' );
		if ( self::run_active() ) {
			wp_die( 'یک Repair/همسان‌سازی نهایی در حال اجراست. همان Run باید تا پایان یا توقف ادامه پیدا کند؛ اجرای تازه از ابتدا ساخته نمی‌شود.', 'اجرای قبلی هنوز فعال است', array( 'response'=>409, 'back_link'=>true ) );
		}
		$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
		if ( $pending_count > 0 ) {
			wp_die(
				sprintf(
					'فعلاً Repair اجرا نشد؛ %s تغییر وضعیت Central هنوز در انتظار تأیید مبدأ است. بعد از صفر شدن صف دوباره Repair را اجرا کنید.',
					number_format_i18n( $pending_count )
				),
				'Repair موقتاً متوقف است',
				array( 'response'=>409, 'back_link'=>true )
			);
		}
		$store_id = sanitize_key( wp_unslash( $_POST['store_id'] ?? '' ) );
		if ( ! $this->begin_run( $store_id, '', 'manual' ) ) {
			wp_die( 'فروشگاه انتخاب‌شده فعال یا معتبر نیست.', 422 );
		}
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync', 'status_repair_started'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}


	public function start_converge() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_status_converge', '_cos_nonce' );
		if ( self::run_active() ) {
			wp_die( 'یک Repair/همسان‌سازی نهایی در حال اجراست. همان Run باید تا پایان یا توقف ادامه پیدا کند؛ اجرای تازه از ابتدا ساخته نمی‌شود.', 'اجرای قبلی هنوز فعال است', array( 'response'=>409, 'back_link'=>true ) );
		}
		$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
		if ( $pending_count > 0 ) {
			wp_die(
				sprintf(
					'همسان‌سازی نهایی هنوز شروع نشد؛ %s تغییر وضعیت Central در انتظار تأیید مبدأ است. این صف باید اول صفر شود.',
					number_format_i18n( $pending_count )
				),
				'همسان‌سازی نهایی موقتاً متوقف است',
				array( 'response'=>409, 'back_link'=>true )
			);
		}
		$store_id = sanitize_key( wp_unslash( $_POST['store_id'] ?? '' ) );
		if ( ! $this->begin_run( $store_id, '', 'manual', 'final' ) ) {
			wp_die( 'فروشگاه انتخاب‌شده فعال یا معتبر نیست.', 422 );
		}
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync', 'status_converge_started'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function begin_run( $store_id, $window_from, $trigger, $mode = 'repair' ) {
		$store_id  = sanitize_key( $store_id );
		$date_from = Company_Order_Sync_Settings::retention_start_date();
		if ( ! $store_id || ! $date_from || ! Company_Order_Sync_Settings::central_store( $store_id ) ) {
			return false;
		}
		Company_Order_Sync_Settings::set_central_min_date( $store_id, $date_from );
		$window_from = $this->sanitize_date( $window_from );
		if ( ! $window_from || $window_from < $date_from ) {
			$window_from = $date_from;
		}
		$trigger = 'auto' === $trigger ? 'auto' : 'manual';
		$mode    = 'final' === $mode ? 'final' : 'repair';
		if ( self::run_active() ) {
			return false;
		}
		$run_id  = wp_generate_uuid4();
		$date_to = current_time( 'Y-m-d H:i:s' );
		update_option(
			self::STATE_OPTION,
			array(
				'run_id'               => $run_id,
				'store_id'             => $store_id,
				'date_from'            => $date_from,
				'window_from'          => $window_from,
				'trigger'              => $trigger,
				'mode'                 => $mode,
				'date_to'              => $date_to,
				'page'                 => 0,
				'total_pages'          => 0,
				'source_total'         => 0,
				'checked'              => 0,
				'matched'              => 0,
				'protected'            => 0, // legacy combined counter
				'pending_protected'    => 0,
				'stale_skipped'        => 0,
				'corrected'            => 0,
				'created'              => 0,
				'revision_rebased'      => 0,
				'central_reasserted'    => 0,
				'race_retries'          => 0,
				'failed_order_ids'     => array(),
				'source_order_ids'     => array(),
				'differences'          => array(),
				'source_status_counts' => array(),
				'central_status_counts'=> array(),
				'attempt'              => 0,
				'status'               => 'queued',
				'message'              => 'final' === $mode
					? 'همسان‌سازی نهایی وضعیت‌ها در صف اجرا قرار گرفت؛ مبدأ زنده پس از تخلیه تغییرات Central مرجع خواهد بود'
					: ( 'auto' === $trigger
						? sprintf( 'اجرای خودکار مقایسه وضعیت‌ها از %s در صف قرار گرفت', $window_from )
						: 'مقایسه وضعیت‌ها در صف اجرا قرار گرفت' ),
				'updated_at'           => current_time( 'mysql' ),
			),
			false
		);
		$this->schedule( array( $store_id, $date_from, $date_to, 1, $run_id, 0, $window_from ) );
		return true;
	}

	public function stop() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_status_repair_stop', '_cos_nonce' );
		$state = get_option( self::STATE_OPTION, array() );
		$is_final = is_array( $state ) && 'final' === sanitize_key( $state['mode'] ?? '' );
		$store_id = is_array( $state ) ? sanitize_key( $state['store_id'] ?? '' ) : '';
		$this->update_state( array( 'status'=>'stopped', 'message'=>$is_final ? 'همسان‌سازی نهایی متوقف شد' : 'مقایسه و ترمیم وضعیت‌ها متوقف شد' ) );
		$this->unschedule();
		if ( $is_final && $store_id ) {
			do_action( 'company_order_sync_final_convergence_completed', $store_id );
		}
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function recover() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( 'waiting_pending' === ( $state['status'] ?? '' ) && ! empty( $state['run_id'] ) ) {
			if ( 0 === Company_Order_Sync_Queue::pending_central_status_count() ) {
				$this->resume_waiting_pending();
			}
			return;
		}
		if ( ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying' ), true ) || empty( $state['run_id'] ) ) {
			return;
		}
		$updated = strtotime( (string) ( $state['updated_at'] ?? '' ) );
		if ( $updated && $updated > time() - self::LOCK_TTL ) {
			return;
		}
		$this->unschedule();
		$page = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		$this->schedule( array( sanitize_key( $state['store_id'] ), (string) $state['date_from'], (string) $state['date_to'], $page, (string) $state['run_id'], 0, (string) ( $state['window_from'] ?? '' ) ) );
		$this->update_state( array( 'status'=>'queued', 'message'=>'Worker ترمیم وضعیت بازیابی شد' ) );
	}

	public function process( $store_id, $date_from, $date_to, $page, $run_id, $attempt = 0, $window_from = '' ) {
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
		$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
		if ( $pending_count > 0 ) {
			$this->unschedule();
			$is_final = 'final' === sanitize_key( $state['mode'] ?? '' );
			$this->update_state(
				array(
					'status'                => 'waiting_pending',
					'waiting_pending_count' => $pending_count,
					'message'               => sprintf( '%1$s در همین مرحله متوقف ماند تا %2$s تغییر وضعیت Central توسط مبدأ تأیید شود؛ سپس از همین صفحه ادامه می‌دهد.', $is_final ? 'همسان‌سازی نهایی' : 'Repair', number_format_i18n( $pending_count ) ),
				)
			);
			return;
		}
		$window_from = $this->sanitize_date( $window_from ) ?: $this->sanitize_date( $state['window_from'] ?? '' );
		$args = array( $store_id, $date_from, $date_to, $page, $run_id, absint( $attempt ), $window_from );
		wp_clear_scheduled_hook( self::HOOK, $args );
		wp_clear_scheduled_hook( self::HOOK, array( $store_id, $date_from, $date_to, $page, $run_id, absint( $attempt ) ) );
		$lock = $this->acquire_lock( $run_id, $page );
		if ( ! $lock ) {
			return;
		}
		try {
			$this->process_page( $store_id, $date_from, $date_to, $page, $run_id, absint( $attempt ), $window_from );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function process_page( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $window_from = '' ) {
		if ( Company_Order_Sync_Central_Rebuild::run_blocked( $run_id ) ) { return; }
		$window_from = $this->sanitize_date( $window_from );
		if ( ! $window_from || $window_from < $date_from ) {
			$window_from = $date_from;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( 'final' === sanitize_key( $state['mode'] ?? '' ) ) {
			$this->process_final_page( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $window_from );
			return;
		}
		$this->update_state( array( 'status'=>'running', 'attempt'=>$attempt, 'message'=>sprintf( 'در حال مقایسه بخش %1$d وضعیت‌ها (بسته‌های %2$d سفارشی)', $page, self::PER_PAGE ) ) );
		$params = array( 'store_id'=>$store_id, 'date_from'=>$date_from, 'date_to'=>$date_to, 'page'=>$page, 'per_page'=>self::PER_PAGE );
		if ( $window_from !== $date_from ) {
			$params['window_from'] = $window_from;
		}
		$result = $this->request( $store_id, '/wp-json/company-sync/v1/status-snapshot', $params, 45 );
		if ( is_wp_error( $result ) ) {
			$this->retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $result->get_error_message(), $window_from );
			return;
		}

		if ( 1 === $page && ! empty( $result['status_definitions'] ) && is_array( $result['status_definitions'] ) ) {
			( new Company_Order_Sync_Snapshot_Sync() )->apply_statuses( $store_id, $result['status_definitions'] );
		}
		$checked = 0;
		$matched = 0;
		$protected = 0; // legacy combined counter
		$pending_protected = 0;
		$stale_skipped = 0;
		$corrected = 0;
		$created = 0;
		$missing = array();
		$failed  = array();
		$page_source_ids = array();
		$page_differences = array();
		$source_records = array();
		$page_status_counts = array();
		$status_records = array_values( array_filter( (array) ( $result['statuses'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $status_records, 'id' ) );
		foreach ( $status_records as $record ) {
			$source_id = is_array( $record ) ? absint( $record['id'] ?? 0 ) : 0;
			if ( ! $source_id ) {
				continue;
			}
			$page_source_ids[] = $source_id;
			$source_records[ $source_id ] = $record;
			++$checked;
			$source_status = sanitize_key( $record['status'] ?? '' );
			if ( $source_status ) {
				$page_status_counts[ $source_status ] = absint( $page_status_counts[ $source_status ] ?? 0 ) + 1;
			}
			$central_order = $this->mapper->existing_order( $store_id, $source_id );
			$central_status_before = $central_order instanceof WC_Order ? sanitize_key( $central_order->get_status() ) : '';
			$mapped = $this->mapper->sync_source_status( $store_id, $record, 'status-repair-' . $run_id . '-' . $page, true );
			if ( is_wp_error( $mapped ) ) {
				if ( 'order_not_found' === $mapped->get_error_code() ) {
					$missing[] = $source_id;
				} elseif ( 'out_of_sync_range' !== $mapped->get_error_code() ) {
					$failed[] = $source_id;
				}
				continue;
			}
			if ( ! empty( $mapped['changed'] ) ) {
				++$corrected;
				$this->append_difference(
					$page_differences,
					$source_status,
					$this->difference_record( $store_id, $record, absint( $mapped['order_id'] ?? 0 ), $central_status_before, 'status_corrected' )
				);
			} elseif ( ! empty( $mapped['pending_protected'] ) ) {
				// A real Central -> source status intent is still waiting for source confirmation.
				// This is different from a stale snapshot and is shown separately in the UI.
				++$pending_protected;
				++$protected;
			} elseif ( ! empty( $mapped['stale'] ) ) {
				// The fetched source snapshot became older than a newer source revision already
				// accepted by Central. Skipping it is expected and must not be presented as
				// "waiting for source confirmation".
				++$stale_skipped;
				++$protected;
			} else {
				++$matched;
			}
		}

		foreach ( array_chunk( $missing, 20 ) as $ids ) {
			$pulled = $this->request( $store_id, '/wp-json/company-sync/v1/orders/by-ids', array( 'store_id'=>$store_id, 'date_from'=>$date_from, 'order_ids'=>$ids ), 45 );
			if ( is_wp_error( $pulled ) ) {
				$failed = array_merge( $failed, $ids );
				continue;
			}
			$received = array();
			foreach ( (array) ( $pulled['orders'] ?? array() ) as $order ) {
				if ( ! is_array( $order ) ) {
					continue;
				}
				$id = absint( $order['id'] ?? 0 );
				$mapped = $this->mapper->upsert( $store_id, $order, 'status-repair-pull-' . $run_id . '-' . $id );
				if ( is_wp_error( $mapped ) ) {
					$failed[] = $id;
				} else {
					$received[] = $id;
					$source_record = $source_records[ $id ] ?? array(
						'id'       => $id,
						'number'   => $order['number'] ?? $id,
						'status'   => $order['status'] ?? '',
						'edit_url' => $order['edit_url'] ?? '',
					);
					$this->append_difference(
						$page_differences,
						sanitize_key( $source_record['status'] ?? '' ) ?: 'unknown',
						$this->difference_record( $store_id, $source_record, absint( $mapped['order_id'] ?? 0 ), '', 'missing_in_central' )
					);
					if ( ! empty( $mapped['created'] ) ) {
						++$created;
					}
				}
			}
			$failed = array_merge( $failed, array_diff( $ids, $received ) );
		}

		$state = get_option( self::STATE_OPTION, array() );
		$failed_ids = array_values( array_unique( array_filter( array_merge( (array) ( $state['failed_order_ids'] ?? array() ), $failed ) ) ) );
		$source_status_counts = (array) ( $state['source_status_counts'] ?? array() );
		$source_order_ids = array_values( array_unique( array_filter( array_merge( (array) ( $state['source_order_ids'] ?? array() ), $page_source_ids ) ) ) );
		$differences = $this->merge_differences( (array) ( $state['differences'] ?? array() ), $page_differences );
		foreach ( $page_status_counts as $slug=>$count ) {
			$source_status_counts[ $slug ] = absint( $source_status_counts[ $slug ] ?? 0 ) + absint( $count );
		}
		$total_pages = max( 1, absint( $result['total_pages'] ?? 1 ) );
		$is_last = $page >= $total_pages;
		$changes = array(
			'page'             => $page,
			'total_pages'      => $total_pages,
			'source_total'     => absint( $result['total'] ?? 0 ),
			'checked'          => absint( $state['checked'] ?? 0 ) + $checked,
			'matched'          => absint( $state['matched'] ?? 0 ) + $matched,
			'protected'         => absint( $state['protected'] ?? 0 ) + $protected,
			'pending_protected' => absint( $state['pending_protected'] ?? 0 ) + $pending_protected,
			'stale_skipped'     => absint( $state['stale_skipped'] ?? 0 ) + $stale_skipped,
			'corrected'         => absint( $state['corrected'] ?? 0 ) + $corrected,
			'created'          => absint( $state['created'] ?? 0 ) + $created,
			'failed_order_ids' => $failed_ids,
			'source_order_ids' => $source_order_ids,
			'differences'      => $differences,
			'source_status_counts' => $source_status_counts,
			'attempt'          => 0,
			'status'           => $is_last ? ( $failed_ids ? 'completed_with_errors' : 'completed' ) : 'running',
			'message'          => $is_last ? ( $failed_ids ? 'مقایسه تمام شد؛ چند سفارش نیازمند بررسی است' : 'مقایسه و ترمیم وضعیت‌ها کامل شد' ) : 'در حال مقایسه و ترمیم وضعیت‌ها',
		);
		if ( $is_last ) {
			$changes['central_status_counts'] = $this->central_status_counts( $store_id, $window_from, $date_to );
			$changes['differences'] = $this->merge_differences(
				$differences,
				$this->central_only_differences( $store_id, $source_order_ids, $window_from, $date_to )
			);
		}
		$this->update_state( $changes );
		if ( ! $is_last ) {
			$this->schedule( array( $store_id, $date_from, $date_to, $page + 1, $run_id, 0, $window_from ) );
		}
	}


	/**
	 * Manual final convergence pass.
	 *
	 * Normal Repair intentionally refuses to apply a source record whose revision
	 * looks older than Central. Historical versions could leave Central with an
	 * inflated source revision, so that safety rule can preserve a real status
	 * difference forever. Final convergence fixes only that terminal condition:
	 * it point-reads every mismatch from the source, pauses for any newer Central
	 * operator intent, and then makes Central equal to the freshly observed source.
	 */
	private function process_final_page( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $window_from ) {
		$this->update_state(
			array(
				'status'  => 'running',
				'attempt' => $attempt,
				'message' => sprintf( 'همسان‌سازی نهایی — در حال بررسی بخش %1$d (هر بخش %2$d سفارش)', $page, self::PER_PAGE ),
			)
		);

		$result = $this->request(
			$store_id,
			'/wp-json/company-sync/v1/status-snapshot',
			array(
				'store_id'    => $store_id,
				'date_from'   => $date_from,
				'window_from' => $window_from,
				'date_to'     => $date_to,
				'page'        => $page,
				'per_page'    => self::PER_PAGE,
				'audit_only'  => true,
			),
			45
		);
		if ( is_wp_error( $result ) ) {
			$this->retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $result->get_error_message(), $window_from );
			return;
		}

		if ( 1 === $page && ! empty( $result['status_definitions'] ) && is_array( $result['status_definitions'] ) ) {
			( new Company_Order_Sync_Snapshot_Sync() )->apply_statuses( $store_id, $result['status_definitions'] );
		}

		$records = array_values( array_filter( (array) ( $result['statuses'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $records, 'id' ) );

		// Capture Central's exact state before the point-read. The authoritative
		// mapper will refuse to apply if status/revision changes after this point.
		$central_before = array();
		$refresh_ids    = array();
		$page_source_ids = array();
		$page_status_counts = array();
		$source_records = array();
		$missing = array();

		foreach ( $records as $record ) {
			$source_id = absint( $record['id'] ?? 0 );
			if ( ! $source_id ) {
				continue;
			}
			$page_source_ids[] = $source_id;
			$source_records[ $source_id ] = $record;
			$source_status = sanitize_key( $record['status'] ?? '' );
			if ( $source_status ) {
				$page_status_counts[ $source_status ] = absint( $page_status_counts[ $source_status ] ?? 0 ) + 1;
			}

			$order = $this->mapper->existing_order( $store_id, $source_id );
			if ( ! $order instanceof WC_Order ) {
				$missing[] = $source_id;
				continue;
			}
			$before_status   = sanitize_key( $order->get_status() );
			$before_revision = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
			$central_before[ $source_id ] = array(
				'order_id'  => $order->get_id(),
				'status'    => $before_status,
				'revision'  => $before_revision,
			);
			$source_revision = max( 0, (int) ( $record['sync_revision'] ?? 0 ) );
			if ( $before_status !== $source_status || ( $source_revision && $before_revision !== $source_revision ) ) {
				$refresh_ids[] = $source_id;
			}
		}

		// All potentially different/revision-stale records are point-read again.
		// This is deliberately separate from the paged snapshot so a long Repair
		// cannot roll Central back using a page that became stale while processing.
		$fresh = array();
		if ( $refresh_ids ) {
			$detail_result = $this->request(
				$store_id,
				'/wp-json/company-sync/v1/status-audit-details',
				array( 'store_id'=>$store_id, 'order_ids'=>array_values( array_unique( $refresh_ids ) ) ),
				45
			);
			if ( is_wp_error( $detail_result ) ) {
				$this->retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $detail_result->get_error_message(), $window_from );
				return;
			}
			foreach ( (array) ( $detail_result['orders'] ?? array() ) as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}
				$id = absint( $detail['id'] ?? 0 );
				if ( $id ) {
					$fresh[ $id ] = $detail;
				}
			}
		}

		$refresh_lookup = array_fill_keys( array_map( 'strval', array_map( 'absint', $refresh_ids ) ), true );
		$page_status_counts = array();
		foreach ( $records as $count_record ) {
			$count_id = absint( $count_record['id'] ?? 0 );
			if ( $count_id && isset( $fresh[ $count_id ] ) ) {
				$count_record = array_merge( $count_record, $fresh[ $count_id ] );
			}
			$count_status = sanitize_key( $count_record['status'] ?? '' );
			if ( $count_status ) {
				$page_status_counts[ $count_status ] = absint( $page_status_counts[ $count_status ] ?? 0 ) + 1;
			}
		}

		$checked = 0;
		$matched = 0;
		$corrected = 0;
		$created = 0;
		$revision_rebased = 0;
		$central_reasserted = 0;
		$failed = array();
		$page_differences = array();
		$needs_same_page_retry = false;
		$queue = new Company_Order_Sync_Queue();

		foreach ( $records as $snapshot_record ) {
			$source_id = absint( $snapshot_record['id'] ?? 0 );
			if ( ! $source_id ) {
				continue;
			}
			++$checked;

			if ( in_array( $source_id, $missing, true ) ) {
				continue;
			}

			$before = $central_before[ $source_id ] ?? null;
			if ( ! is_array( $before ) ) {
				continue;
			}

			if ( isset( $refresh_lookup[ (string) $source_id ] ) && ! isset( $fresh[ $source_id ] ) ) {
				// The order disappeared or could not be point-read after the page snapshot.
				// Never fall back to the older paged value in final convergence.
				$failed[] = $source_id;
				continue;
			}

			$record = $snapshot_record;
			if ( isset( $fresh[ $source_id ] ) ) {
				$record = array_merge( $snapshot_record, $fresh[ $source_id ] );
			}
			$source_status = sanitize_key( $record['status'] ?? '' );
			if ( ! $source_status ) {
				$failed[] = $source_id;
				continue;
			}

			$order = wc_get_order( absint( $before['order_id'] ?? 0 ) );
			if ( ! $order instanceof WC_Order ) {
				$failed[] = $source_id;
				continue;
			}

			// If an operator changed Central while this page was being point-read,
			// pause immediately. The pending queue will deliver it first and this
			// exact page will be fetched again after confirmation.
			if ( sanitize_key( $order->get_meta( '_company_pending_outbound_status', true ) ) ) {
				$needs_same_page_retry = true;
				break;
			}

			$current_status = sanitize_key( $order->get_status() );
			$current_revision = max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) );
			$source_revision = max( 0, (int) ( $record['sync_revision'] ?? 0 ) );

			if ( $current_status === $source_status && ( ! $source_revision || $current_revision === $source_revision ) ) {
				++$matched;
				continue;
			}

			// A fresh source point-read is normally authoritative once Pending is 0.
			// Exception: the current Central status is itself the last explicit
			// operator intent and that intent is chronologically newer than the
			// source's current modification. In that case re-send Central first.
			if ( $this->central_intent_is_newer_than_source( $order, $record ) ) {
				$reasserted = $queue->reassert_central_status_intent( $order->get_id(), 'final_convergence' );
				if ( $reasserted ) {
					++$central_reasserted;
					$needs_same_page_retry = true;
					break;
				}
			}

			$mapped = $this->mapper->sync_source_status_authoritative(
				$store_id,
				$record,
				'status-final-' . $run_id . '-' . $page . '-' . $source_id,
				(string) ( $before['status'] ?? '' ),
				(int) ( $before['revision'] ?? 0 )
			);
			if ( is_wp_error( $mapped ) ) {
				if ( 'order_not_found' === $mapped->get_error_code() ) {
					$missing[] = $source_id;
				} elseif ( 'out_of_sync_range' !== $mapped->get_error_code() ) {
					$failed[] = $source_id;
				}
				continue;
			}
			if ( ! empty( $mapped['pending_protected'] ) || ! empty( $mapped['race_retry'] ) ) {
				$needs_same_page_retry = true;
				break;
			}
			if ( ! empty( $mapped['revision_rebased'] ) ) {
				++$revision_rebased;
			}
			if ( ! empty( $mapped['changed'] ) ) {
				++$corrected;
				$this->append_difference(
					$page_differences,
					$source_status,
					$this->difference_record( $store_id, $record, $order->get_id(), $current_status, 'status_corrected' )
				);
			} else {
				++$matched;
			}
		}

		if ( $needs_same_page_retry ) {
			$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
			$state = get_option( self::STATE_OPTION, array() );
			$this->update_state(
				array(
					'status'             => $pending_count > 0 ? 'waiting_pending' : 'queued',
					'waiting_pending_count' => $pending_count,
					'central_reasserted' => absint( $state['central_reasserted'] ?? 0 ) + $central_reasserted,
					'race_retries'       => absint( $state['race_retries'] ?? 0 ) + ( $pending_count > 0 ? 0 : 1 ),
					'message'            => $pending_count > 0
						? sprintf( 'همسان‌سازی نهایی روی همین بخش متوقف شد تا %s تغییر Central به مبدأ برسد؛ سپس همین بخش تازه‌خوانی می‌شود.', number_format_i18n( $pending_count ) )
						: 'در حین همسان‌سازی داده تازه‌تری رسید؛ همان بخش بدون افزایش شمارنده‌ها دوباره از مبدأ خوانده می‌شود.',
				)
			);
			if ( 0 === $pending_count ) {
				$this->schedule( array( $store_id, $date_from, $date_to, $page, $run_id, 0, $window_from ) );
			}
			return;
		}

		// Pull orders that exist on source but are absent from Central.
		$missing = array_values( array_unique( array_filter( array_map( 'absint', $missing ) ) ) );
		foreach ( array_chunk( $missing, 20 ) as $ids ) {
			$pulled = $this->request( $store_id, '/wp-json/company-sync/v1/orders/by-ids', array( 'store_id'=>$store_id, 'date_from'=>$date_from, 'order_ids'=>$ids ), 45 );
			if ( is_wp_error( $pulled ) ) {
				$failed = array_merge( $failed, $ids );
				continue;
			}
			$received = array();
			foreach ( (array) ( $pulled['orders'] ?? array() ) as $order_payload ) {
				if ( ! is_array( $order_payload ) ) {
					continue;
				}
				$id = absint( $order_payload['id'] ?? 0 );
				$mapped = $this->mapper->upsert( $store_id, $order_payload, 'status-final-pull-' . $run_id . '-' . $id );
				if ( is_wp_error( $mapped ) ) {
					$failed[] = $id;
					continue;
				}
				$received[] = $id;
				if ( ! empty( $mapped['created'] ) ) {
					++$created;
				}
			}
			$failed = array_merge( $failed, array_diff( $ids, $received ) );
		}

		$state = get_option( self::STATE_OPTION, array() );
		$failed_ids = array_values( array_unique( array_filter( array_merge( (array) ( $state['failed_order_ids'] ?? array() ), $failed ) ) ) );
		$source_status_counts = (array) ( $state['source_status_counts'] ?? array() );
		foreach ( $page_status_counts as $slug=>$count ) {
			$source_status_counts[ $slug ] = absint( $source_status_counts[ $slug ] ?? 0 ) + absint( $count );
		}
		$source_order_ids = array_values( array_unique( array_filter( array_merge( (array) ( $state['source_order_ids'] ?? array() ), $page_source_ids ) ) ) );
		$differences = $this->merge_differences( (array) ( $state['differences'] ?? array() ), $page_differences );
		$total_pages = max( 1, absint( $result['total_pages'] ?? 1 ) );
		$is_last = $page >= $total_pages;

		$changes = array(
			'page'                  => $page,
			'total_pages'           => $total_pages,
			'source_total'          => absint( $result['total'] ?? 0 ),
			'checked'               => absint( $state['checked'] ?? 0 ) + $checked,
			'matched'               => absint( $state['matched'] ?? 0 ) + $matched,
			'corrected'             => absint( $state['corrected'] ?? 0 ) + $corrected,
			'created'               => absint( $state['created'] ?? 0 ) + $created,
			'revision_rebased'      => absint( $state['revision_rebased'] ?? 0 ) + $revision_rebased,
			'central_reasserted'    => absint( $state['central_reasserted'] ?? 0 ) + $central_reasserted,
			'failed_order_ids'      => $failed_ids,
			'source_order_ids'      => $source_order_ids,
			'differences'           => $differences,
			'source_status_counts'  => $source_status_counts,
			'attempt'               => 0,
			'status'                => $is_last ? ( $failed_ids ? 'completed_with_errors' : 'completed' ) : 'running',
			'message'               => $is_last
				? ( $failed_ids ? 'همسان‌سازی نهایی تمام شد؛ چند سفارش خطا دارد' : 'همسان‌سازی نهایی کامل شد؛ وضعیت زنده مبدأ روی Central تثبیت شد' )
				: 'در حال همسان‌سازی نهایی وضعیت‌ها',
		);

		if ( $is_last ) {
			$central_counts = $this->central_status_counts( $store_id, $window_from, $date_to );
			$changes['central_status_counts'] = $central_counts;
			$changes['differences'] = $this->merge_differences(
				$differences,
				$this->central_only_differences( $store_id, $source_order_ids, $window_from, $date_to )
			);
			$remaining = 0;
			foreach ( array_values( array_unique( array_merge( array_keys( $source_status_counts ), array_keys( $central_counts ) ) ) ) as $slug ) {
				$remaining += abs( absint( $source_status_counts[ $slug ] ?? 0 ) - absint( $central_counts[ $slug ] ?? 0 ) );
			}
			$changes['remaining_status_delta'] = $remaining;
			if ( ! $failed_ids && 0 === $remaining ) {
				$changes['message'] = 'همسان‌سازی نهایی کامل شد؛ اختلاف شمارش وضعیت‌ها بین مبدأ و Central صفر است.';
			} elseif ( ! $failed_ids ) {
				$changes['message'] = sprintf( 'همسان‌سازی نهایی تمام شد ولی هنوز %s اختلاف شمارشی باقی مانده؛ موارد ساختاری/همزمان باید بررسی شوند.', number_format_i18n( $remaining ) );
			}
		}

		$this->update_state( $changes );
		if ( ! $is_last ) {
			$this->schedule( array( $store_id, $date_from, $date_to, $page + 1, $run_id, 0, $window_from ) );
		} else {
			do_action( 'company_order_sync_final_convergence_completed', sanitize_key( $store_id ) );
		}
	}

	private function central_intent_is_newer_than_source( WC_Order $order, array $source_record ) {
		$current_status = sanitize_key( $order->get_status() );
		$source_status  = sanitize_key( $source_record['status'] ?? '' );
		if ( ! $current_status || ! $source_status || $current_status === $source_status ) {
			return false;
		}

		$last_change = $order->get_meta( '_company_last_central_status_change', true );
		$last_change = is_array( $last_change ) ? $last_change : array();
		$intent_status = sanitize_key( $last_change['status'] ?? '' );
		if ( ! $intent_status || $intent_status !== $current_status ) {
			// Central's current value is not the last explicit operator choice. It may
			// itself be residue from an old Repair, so never push it back to source.
			return false;
		}

		$central_changed = strtotime( (string) ( $last_change['changed_at_gmt'] ?? '' ) );
		$source_status_changed = strtotime( (string) ( $source_record['last_status_change_gmt'] ?? '' ) );
		$source_modified = strtotime( (string) ( $source_record['date_modified_gmt'] ?? '' ) );
		$central_sequence = max(
			0,
			(int) ( $last_change['sequence'] ?? 0 ),
			(int) $order->get_meta( '_company_outbound_status_sequence', true )
		);
		$source_last = is_array( $source_record['last_central_change'] ?? null ) ? $source_record['last_central_change'] : array();
		$source_sequence = max( 0, (int) ( $source_last['sequence'] ?? 0 ) );

		// Compare actual status-change times first. date_modified can move because of
		// unrelated edits, while the latest WooCommerce status note represents the
		// ordering the operator cares about. A later source-side status change wins;
		// a later Central operator change is re-issued before convergence continues.
		if ( $central_changed && $source_status_changed ) {
			if ( $central_changed > ( $source_status_changed + 2 ) ) {
				return true;
			}
			if ( $source_status_changed > ( $central_changed + 2 ) ) {
				return false;
			}
		}

		// Sequence resolves same-second events and records for which old notes are
		// incomplete. Equal/newer source sequence proves that source already accepted
		// the Central intent; if its status differs now, source changed afterwards.
		if ( $central_sequence && $source_sequence ) {
			return $central_sequence > $source_sequence;
		}

		// Legacy fallback: only use generic modification time when no explicit source
		// status timestamp is available. This intentionally favours source on ties.
		if ( $central_changed && ! $source_status_changed && $source_modified ) {
			return $central_changed > ( $source_modified + 2 );
		}

		return $central_sequence > 0 && $source_sequence > 0 && $central_sequence > $source_sequence;
	}

	public function resume_after_pending_drain( $progress = array() ) {
		unset( $progress );
		$this->resume_waiting_pending();
	}

	private function resume_waiting_pending() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			return false;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( 'waiting_pending' !== ( $state['status'] ?? '' ) || empty( $state['run_id'] ) ) {
			return false;
		}

		$page = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		$is_final = 'final' === sanitize_key( $state['mode'] ?? '' );
		$this->update_state(
			array(
				'status'                => 'queued',
				'waiting_pending_count' => 0,
				'message'               => sprintf( 'صف تغییر وضعیت Central صفر شد؛ %1$s از بخش %2$d قبلی ادامه یافت.', $is_final ? 'همسان‌سازی نهایی' : 'Repair', $page ),
			)
		);
		$this->schedule(
			array(
				sanitize_key( $state['store_id'] ?? '' ),
				(string) ( $state['date_from'] ?? '' ),
				(string) ( $state['date_to'] ?? '' ),
				$page,
				(string) $state['run_id'],
				0,
				(string) ( $state['window_from'] ?? '' ),
			)
		);
		return true;
	}

	public function export_status_snapshot( WP_REST_Request $request, array $auth ) {
		$params      = $request->get_json_params();
		$date_from   = $this->sanitize_date( $params['date_from'] ?? '' );
		$boundary_date = $this->sanitize_date( $params['boundary_date'] ?? '' ) ?: $date_from;
		$window_from = $this->sanitize_date( $params['window_from'] ?? '' );
		$modified_from = $this->sanitize_datetime( $params['modified_from'] ?? '' );
		$date_to     = $this->sanitize_datetime( $params['date_to'] ?? '' );
		$page        = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page    = min( self::PER_PAGE, max( 10, absint( $params['per_page'] ?? self::PER_PAGE ) ) );
		if ( ! $date_from || ! $date_to ) {
			return new WP_Error( 'invalid_date', 'Status snapshot date range is invalid.', array( 'status'=>422 ) );
		}
		if ( empty( $params['audit_only'] ) ) {
			Company_Order_Sync_Settings::set_source_min_date( $boundary_date );
		}
		$range = $this->timestamp_range( $window_from && $window_from > $date_from ? $window_from : $date_from, $date_to );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$statuses = $this->status_slugs();
		$query_args = array( 'limit'=>$per_page, 'paged'=>$page, 'paginate'=>true, 'orderby'=>'date', 'order'=>'ASC', 'type'=>'shop_order', 'status'=>$statuses, 'date_created'=>$range );
		if ( $modified_from ) {
			try {
				$modified_start = ( new DateTimeImmutable( $modified_from, wp_timezone() ) )->getTimestamp();
				$modified_end   = ( new DateTimeImmutable( $date_to, wp_timezone() ) )->getTimestamp();
				$query_args['date_modified'] = $modified_start . '...' . $modified_end;
			} catch ( Exception $error ) {
				unset( $query_args['date_modified'] );
			}
		}
		$query = wc_get_orders( $query_args );
		$records = array();
		foreach ( (array) $query->orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$created  = $order->get_date_created();
			$modified = $order->get_date_modified();
			$records[] = array(
				'id'                => $order->get_id(),
				'number'            => $order->get_order_number(),
				'edit_url'          => $order->get_edit_order_url(),
				'status'            => $order->get_status(),
				'line_item_count'   => count( $order->get_items( 'line_item' ) ),
				'sync_revision'     => (string) max( (int) $order->get_meta( '_company_sync_revision', true ), $modified instanceof WC_DateTime ? $modified->getTimestamp() * 1000000 : 0 ),
				'date_created_gmt'  => $created instanceof WC_DateTime ? gmdate( 'c', $created->getTimestamp() ) : '',
				'date_modified_gmt' => $modified instanceof WC_DateTime ? gmdate( 'c', $modified->getTimestamp() ) : '',
			);
		}
		$response = array( 'success'=>true, 'request_id'=>$auth['request_id'], 'statuses'=>$records, 'total'=>(int) $query->total, 'total_pages'=>(int) $query->max_num_pages, 'page'=>$page );
		if ( 1 === $page ) {
			$response['status_definitions'] = $this->status_definitions();
			if ( ! empty( $params['include_directory'] ) ) {
				$snapshot = new Company_Order_Sync_Snapshot_Sync();
				$response['roles'] = $snapshot->export_roles();
				$response['users'] = $snapshot->export_users();
			}
		}
		return $response;
	}

	public function export_orders_by_ids( WP_REST_Request $request, array $auth ) {
		$params = $request->get_json_params();
		$date_from = $this->sanitize_date( $params['date_from'] ?? '' );
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['order_ids'] ?? array() ) ) ) ) ), 0, 100 );
		if ( ! $date_from || ! $ids ) {
			return new WP_Error( 'invalid_order_pull', 'Order IDs and synchronization boundary are required.', array( 'status'=>422 ) );
		}
		Company_Order_Sync_Settings::set_source_min_date( $date_from );
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! Company_Order_Sync_Settings::order_is_in_scope( $order, $date_from ) ) {
				continue;
			}
			$data = $this->serializer->serialize( $order );
			$data['source_url'] = home_url( '/' );
			$orders[] = $data;
		}
		return array( 'success'=>true, 'request_id'=>$auth['request_id'], 'orders'=>$orders );
	}

	private function status_definitions() {
		$out = array();
		foreach ( wc_get_order_statuses() as $key=>$label ) {
			$out[] = array( 'slug'=>str_replace( 'wc-', '', sanitize_key( $key ) ), 'label'=>wp_strip_all_tags( $label ) );
		}
		return $out;
	}

	private function difference_record( $store_id, array $record, $central_order_id, $central_status, $type ) {
		$central_order = $central_order_id ? wc_get_order( $central_order_id ) : false;
		return array(
			'type'             => sanitize_key( $type ),
			'store_id'         => sanitize_key( $store_id ),
			'source_order_id'  => absint( $record['id'] ?? 0 ),
			'source_number'    => sanitize_text_field( $record['number'] ?? ( $record['id'] ?? '' ) ),
			'source_status'    => sanitize_key( $record['status'] ?? '' ),
			'central_order_id' => absint( $central_order_id ),
			'central_status'   => sanitize_key( $central_status ),
			'source_url'       => esc_url_raw( $record['edit_url'] ?? '' ),
			'central_url'      => $central_order instanceof WC_Order ? esc_url_raw( $central_order->get_edit_order_url() ) : '',
		);
	}

	private function append_difference( array &$groups, $status, array $record ) {
		$status = sanitize_key( $status ) ?: 'unknown';
		if ( ! isset( $groups[ $status ] ) || ! is_array( $groups[ $status ] ) ) {
			$groups[ $status ] = array();
		}
		$key = sanitize_key( $record['type'] ?? '' ) . ':' . absint( $record['source_order_id'] ?? 0 ) . ':' . absint( $record['central_order_id'] ?? 0 );
		foreach ( $groups[ $status ] as $existing ) {
			$existing_key = sanitize_key( $existing['type'] ?? '' ) . ':' . absint( $existing['source_order_id'] ?? 0 ) . ':' . absint( $existing['central_order_id'] ?? 0 );
			if ( $existing_key === $key ) {
				return;
			}
		}
		$groups[ $status ][] = $record;
	}

	private function merge_differences( array $existing, array $incoming ) {
		foreach ( $incoming as $status => $records ) {
			foreach ( (array) $records as $record ) {
				if ( is_array( $record ) ) {
					$this->append_difference( $existing, $status, $record );
				}
			}
		}
		return $existing;
	}

	private function central_only_differences( $store_id, array $source_order_ids, $date_from, $date_to ) {
		$range = $this->timestamp_range( $date_from, $date_to );
		if ( is_wp_error( $range ) ) {
			return array();
		}
		$source_lookup = array_fill_keys( array_map( 'strval', array_map( 'absint', $source_order_ids ) ), true );
		$order_ids = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'type'         => 'shop_order',
				'status'       => $this->status_slugs(),
				'date_created' => $range,
				'meta_query'   => array(
					array( 'key'=>'_company_source_store', 'value'=>sanitize_key( $store_id ), 'compare'=>'=' ),
				),
			)
		);
		$groups = array();
		foreach ( (array) $order_ids as $central_order_id ) {
			$order = wc_get_order( absint( $central_order_id ) );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$source_id = absint( $order->get_meta( '_company_source_order_id', true ) );
			if ( $source_id && isset( $source_lookup[ (string) $source_id ] ) ) {
				continue;
			}
			$status = sanitize_key( $order->get_status() ) ?: 'unknown';
			$this->append_difference(
				$groups,
				$status,
				array(
					'type'             => 'missing_in_source',
					'store_id'         => sanitize_key( $store_id ),
					'source_order_id'  => $source_id,
					'source_number'    => sanitize_text_field( $order->get_meta( '_company_source_order_number', true ) ) ?: $source_id,
					'source_status'    => '',
					'central_order_id' => $order->get_id(),
					'central_status'   => $status,
					'source_url'       => '',
					'central_url'      => esc_url_raw( $order->get_edit_order_url() ),
				)
			);
		}
		return $groups;
	}

	private function central_status_counts( $store_id, $date_from, $date_to ) {
		$range = $this->timestamp_range( $date_from, $date_to );
		if ( is_wp_error( $range ) ) {
			return array();
		}
		$counts = array();
		foreach ( $this->status_slugs() as $status ) {
			$query = wc_get_orders( array( 'limit'=>1, 'paginate'=>true, 'return'=>'ids', 'type'=>'shop_order', 'status'=>$status, 'date_created'=>$range, 'meta_query'=>array( array( 'key'=>'_company_source_store', 'value'=>$store_id, 'compare'=>'=' ) ) ) );
			$counts[ $status ] = (int) $query->total;
		}
		return $counts;
	}

	private function status_slugs() {
		return array_map( static function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) );
	}

	private function timestamp_range( $date_from, $date_to ) {
		try {
			$start = ( new DateTimeImmutable( $date_from . ' 00:00:00', wp_timezone() ) )->getTimestamp();
			$end   = ( new DateTimeImmutable( $date_to, wp_timezone() ) )->getTimestamp();
			return $start . '...' . $end;
		} catch ( Exception $error ) {
			return new WP_Error( 'invalid_date', 'Date range is invalid.', array( 'status'=>422 ) );
		}
	}

	private function request( $store_id, $path, array $payload, $timeout ) {
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) {
			return new WP_Error( 'store_unavailable', 'اتصال فروشگاه فعال نیست.' );
		}
		$body    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$headers = Company_Order_Sync_Security::headers( $store_id, (string) $store['outbound_secret'], $body );
		$response = wp_safe_remote_post( untrailingslashit( $store['url'] ) . $path, array( 'timeout'=>$timeout, 'redirection'=>0, 'headers'=>$headers, 'body'=>$body, 'data_format'=>'body' ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'repair_transport_failed', 'ارتباط با فروشگاه برقرار نشد: ' . sanitize_text_field( $response->get_error_message() ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $http < 200 || $http >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$message = is_array( $data ) ? sanitize_text_field( $data['message'] ?? '' ) : '';
			return new WP_Error( 'repair_request_failed', sprintf( 'HTTP %d: %s', $http, $message ?: 'پاسخ نامعتبر فروشگاه' ) );
		}
		return $data;
	}

	private function retry_or_fail( $store_id, $date_from, $date_to, $page, $run_id, $attempt, $message, $window_from = '' ) {
		$next = $attempt + 1;
		if ( $next >= self::MAX_ATTEMPTS ) {
			$state = get_option( self::STATE_OPTION, array() );
			$is_final = is_array( $state ) && 'final' === sanitize_key( $state['mode'] ?? '' );
			$this->update_state( array( 'status'=>'failed', 'attempt'=>$next, 'message'=>( $is_final ? 'همسان‌سازی نهایی متوقف شد: ' : 'ترمیم وضعیت متوقف شد: ' ) . sanitize_text_field( $message ) ) );
			if ( $is_final ) {
				do_action( 'company_order_sync_final_convergence_completed', sanitize_key( $store_id ) );
			}
			return;
		}
		$delays = array( 30, 120, 300 );
		$clean_message = sanitize_text_field( $message );
		$is_timeout = false !== stripos( $clean_message, 'timed out' ) || false !== stripos( $clean_message, 'cURL error 28' );
		$this->update_state(
			array(
				'status'  => 'retrying',
				'attempt' => $next,
				'message' => $is_timeout
					? sprintf( 'تلاش مجدد %1$d برای همان بخش %2$d پس از Timeout؛ Run و شمارنده‌ها از اول شروع نمی‌شوند. %3$s', $next, $page, $clean_message )
					: sprintf( 'تلاش مجدد %1$d برای بخش %2$d: %3$s', $next, $page, $clean_message ),
			)
		);
		$args = array( $store_id, $date_from, $date_to, $page, $run_id, $next, $window_from );
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
			wp_schedule_single_event( time() + 30, self::HOOK, $args );
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
		$key = 'company_status_repair_' . md5( (string) $run_id . ':' . absint( $page ) );
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
		global $wpdb;
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

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function sanitize_datetime( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
