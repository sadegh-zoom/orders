<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Retention_Maintenance {

	const RECENT_TRIGGER_HOOK = 'company_order_sync_recent_snapshot_trigger';
	const FULL_TRIGGER_HOOK   = 'company_order_sync_full_snapshot_trigger';
	const PAGE_HOOK           = 'company_order_sync_automatic_snapshot_page';
	const CLEANUP_HOOK        = 'company_order_sync_retention_cleanup';
	const RECENT_SCHEDULE     = 'company_order_sync_fifteen_minutes';
	const FULL_SCHEDULE       = 'company_order_sync_twice_weekly';
	const RECENT_INTERVAL     = 900;
	const FULL_INTERVAL       = 302400;
	const RECENT_DAYS         = 7;
	const STATE_OPTION        = 'company_order_sync_retention_state';
	const RUNS_OPTION         = 'company_order_sync_automatic_snapshot_runs';
	const PENDING_RECENT_OPTION = 'company_order_sync_pending_recent_snapshots';
	const PENDING_FULL_OPTION = 'company_order_sync_pending_full_snapshots';
	const MIGRATION_OPTION    = 'company_order_sync_retention_maintenance_v1';
	const STORE_CLEANUP_OPTION= 'company_order_sync_retention_store_cleanup';
	const MAX_ATTEMPTS        = 4;
	const STALE_AFTER         = 1800;
	const DELETE_BATCH        = 100;
	const INLINE_BUDGET       = 18;
	const INLINE_PAGES        = 5;

	private $snapshot;

	public function __construct() {
		$this->snapshot = new Company_Order_Sync_Snapshot_Sync();
	}

	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_schedules' ), 38 );
		add_action( self::RECENT_TRIGGER_HOOK, array( $this, 'run_recent' ), 10, 1 );
		add_action( self::FULL_TRIGGER_HOOK, array( $this, 'run_full' ), 10, 1 );
		add_action( self::PAGE_HOOK, array( $this, 'process_page' ), 10, 6 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired_orders' ) );
		add_action( 'update_option_' . Company_Order_Sync_Settings::OPTION, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'company_order_sync_pending_status_queue_drained', array( $this, 'resume_after_pending_drain' ), 10, 1 );
		add_action( 'company_order_sync_final_convergence_completed', array( $this, 'resume_after_final_convergence' ), 10, 1 );
		add_action( 'init', array( $this, 'recover_waiting_runs' ), 50 );
	}

	public function cron_schedules( $schedules ) {
		$schedules[ self::RECENT_SCHEDULE ] = array(
			'interval' => self::RECENT_INTERVAL,
			'display'  => 'هر ۱۵ دقیقه برای سفارش‌های هفته اخیر',
		);
		$schedules[ self::FULL_SCHEDULE ] = array(
			'interval' => self::FULL_INTERVAL,
			'display'  => 'هفته‌ای دو بار برای کل بازه سفارش‌ها',
		);
		return $schedules;
	}

	public function ensure_schedules() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			if ( COMPANY_ORDER_SYNC_VERSION !== (string) get_option( self::STORE_CLEANUP_OPTION, '' ) ) {
				self::unschedule_all();
				update_option( self::STORE_CLEANUP_OPTION, COMPANY_ORDER_SYNC_VERSION, false );
			}
			return;
		}
		if ( get_option( self::STORE_CLEANUP_OPTION, false ) ) {
			delete_option( self::STORE_CLEANUP_OPTION );
		}

		if ( ! get_option( self::MIGRATION_OPTION, false ) ) {
			Company_Order_Sync_Status_Repair::unschedule_auto();
			update_option( self::MIGRATION_OPTION, COMPANY_ORDER_SYNC_VERSION, false );
		}

		$enabled = array_keys( (array) Company_Order_Sync_Settings::enabled_central_stores() );
		foreach ( array( 'site1', 'site2' ) as $store_id ) {
			if ( ! in_array( $store_id, $enabled, true ) ) {
				$this->unschedule_store( $store_id );
			}
		}

		foreach ( array_values( $enabled ) as $index => $store_id ) {
			$store_id = sanitize_key( $store_id );
			if ( ! $store_id ) {
				continue;
			}
			Company_Order_Sync_Settings::set_central_min_date( $store_id, Company_Order_Sync_Settings::retention_start_date() );
			$this->ensure_recurring( self::RECENT_TRIGGER_HOOK, self::RECENT_INTERVAL, self::RECENT_SCHEDULE, array( $store_id ), 60 + ( $index * 90 ), 7 );
			$this->ensure_recurring( self::FULL_TRIGGER_HOOK, self::FULL_INTERVAL, self::FULL_SCHEDULE, array( $store_id ), 300 + ( $index * 180 ), 9 );
		}

		$this->ensure_recurring( self::CLEANUP_HOOK, DAY_IN_SECONDS, 'daily', array(), 600, 10 );
	}

	private function ensure_recurring( $hook, $interval, $schedule, array $args, $delay, $priority ) {
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			wp_clear_scheduled_hook( $hook, $args );
			if ( ! as_next_scheduled_action( $hook, $args, Company_Order_Sync_Queue::GROUP ) ) {
				as_schedule_recurring_action( time() + absint( $delay ), absint( $interval ), $hook, $args, Company_Order_Sync_Queue::GROUP, true, absint( $priority ) );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_event( time() + absint( $delay ), $schedule, $hook, $args );
		}
	}

	public function settings_updated( $old_value, $value ) {
		unset( $old_value, $value );
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		foreach ( array_keys( (array) Company_Order_Sync_Settings::enabled_central_stores() ) as $store_id ) {
			Company_Order_Sync_Settings::set_central_min_date( $store_id, Company_Order_Sync_Settings::retention_start_date() );
		}
		$this->schedule_once( self::CLEANUP_HOOK, array(), time() + 5, 10 );
	}

	public function run_recent( $store_id ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$store_id = sanitize_key( $store_id );
		if ( ! $store_id ) {
			return;
		}
		if ( Company_Order_Sync_Status_Repair::final_convergence_active( $store_id ) ) {
			$this->defer_run( $store_id, 'recent', 'final' );
			return;
		}

		// Never replace/reset an already-paused run on the next 15-minute tick.
		// If no run is active yet, remember one deferred trigger per store and
		// start it as soon as the Central -> source status queue reaches zero.
		if ( $this->store_is_busy( $store_id ) ) {
			return;
		}
		if ( Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			$this->defer_run( $store_id, 'recent' );
			return;
		}

		$this->begin_run( $store_id, 'recent' );
	}

	public function run_full( $store_id ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$store_id = sanitize_key( $store_id );
		if ( Company_Order_Sync_Status_Repair::final_convergence_active( $store_id ) ) {
			$this->defer_run( $store_id, 'full', 'final' );
			return;
		}
		if ( Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			$this->defer_run( $store_id, 'full' );
			return;
		}
		if ( $this->store_is_busy( $store_id ) ) {
			$this->defer_run( $store_id, 'full' );
			return;
		}
		$this->begin_run( $store_id, 'full' );
	}

	private function begin_run( $store_id, $scope ) {
		$scope = 'full' === $scope ? 'full' : 'recent';
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		if ( 'central' !== Company_Order_Sync_Settings::mode() || ! $store_id || ! $store || empty( $store['enabled'] ) ) {
			return false;
		}
		if ( Company_Order_Sync_Status_Repair::final_convergence_active( $store_id ) ) {
			$this->defer_run( $store_id, $scope, 'final' );
			return false;
		}
		if ( Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			$this->defer_run( $store_id, $scope );
			return false;
		}
		if ( $this->store_is_busy( $store_id ) ) {
			return false;
		}

		$retention_from = Company_Order_Sync_Settings::retention_start_date();
		$date_from      = 'recent' === $scope
			? Company_Order_Sync_Settings::retention_start_date( self::RECENT_DAYS )
			: $retention_from;
		Company_Order_Sync_Settings::set_central_min_date( $store_id, $retention_from );
		$run_id  = wp_generate_uuid4();
		$date_to = current_time( 'Y-m-d H:i:s' );
		$key     = $this->run_key( $store_id, $scope );
		$runs    = $this->runs();
		$previous = (array) ( $runs[ $key ] ?? array() );
		$modified_from = '';
		if ( 'recent' === $scope ) {
			$previous_to = sanitize_text_field( $previous['date_to'] ?? '' );
			if ( 'completed' === ( $previous['status'] ?? '' ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $previous_to ) ) {
				try {
					$minimum_timestamp  = ( new DateTimeImmutable( $date_from . ' 00:00:00', wp_timezone() ) )->getTimestamp();
					$previous_timestamp = ( new DateTimeImmutable( $previous_to, wp_timezone() ) )->getTimestamp();
					$modified_from = wp_date( 'Y-m-d H:i:s', max( $minimum_timestamp, $previous_timestamp - 5 * MINUTE_IN_SECONDS ), wp_timezone() );
				} catch ( Exception $error ) {
					$modified_from = $date_from . ' 00:00:00';
				}
			} else {
				$modified_from = $date_from . ' 00:00:00';
			}
		}
		$runs[ $key ] = array(
			'run_id'       => $run_id,
			'store_id'     => $store_id,
			'scope'        => $scope,
			'date_from'    => $date_from,
			'boundary_date'=> $retention_from,
			'date_to'      => $date_to,
			'modified_from'=> $modified_from,
			'page'         => 0,
			'total_pages'  => 0,
			'total'        => 0,
			'processed'    => 0,
			'created'      => 0,
			'failed_ids'   => array(),
			'attempt'      => 0,
			'status'       => 'queued',
			'updated_at'   => current_time( 'mysql' ),
		);
		$this->save_runs( $runs );
		$this->update_summary( sprintf( '%s فروشگاه %s در صف قرار گرفت', 'recent' === $scope ? 'بازبینی ۷ روز اخیر' : 'بازبینی کامل', $store_id ) );
		$this->schedule_once( self::PAGE_HOOK, array( $store_id, $scope, $date_from, $date_to, 1, $run_id ), time() + 1, 'full' === $scope ? 6 : 5 );
		return true;
	}

	public function process_page( $store_id, $scope, $date_from, $date_to, $page, $run_id, $batch_started = 0, $batch_pages = 1 ) {
		if ( Company_Order_Sync_Central_Rebuild::run_blocked( $run_id ) ) { return; }
		$store_id = sanitize_key( $store_id );
		$scope    = 'full' === $scope ? 'full' : 'recent';
		$page     = max( 1, absint( $page ) );
		$key      = $this->run_key( $store_id, $scope );
		$runs     = $this->runs();
		$state    = $runs[ $key ] ?? array();
		if ( ! $state || empty( $state['run_id'] ) || ! hash_equals( (string) $state['run_id'], (string) $run_id ) ) {
			return;
		}

		if ( Company_Order_Sync_Status_Repair::final_convergence_active( $store_id ) ) {
			// Freeze the already-running 15-minute/full maintenance cursor while final
			// convergence owns this store. It resumes this exact page afterwards.
			$state['status']     = 'waiting_final';
			$state['updated_at'] = current_time( 'mysql' );
			$runs[ $key ]        = $state;
			$this->save_runs( $runs );
			$this->update_summary( sprintf( 'بازبینی خودکار %s در بخش %d تا پایان همسان‌سازی نهایی متوقف شد و از همین بخش ادامه می‌دهد.', $store_id, $page ) );
			$this->schedule_once( self::PAGE_HOOK, array( $store_id, $scope, $date_from, $date_to, $page, $run_id ), time() + 60, 'full' === $scope ? 6 : 5 );
			return;
		}

		$pending_count = Company_Order_Sync_Queue::pending_central_status_count();
		if ( $pending_count > 0 ) {
			// Preserve the existing run_id, date_to and page cursor. The next 15-minute
			// trigger will see this run as busy and will not start over from page 1.
			$state['status']                = 'waiting_pending';
			$state['waiting_pending_count'] = $pending_count;
			$state['updated_at']            = current_time( 'mysql' );
			$runs[ $key ]                   = $state;
			$this->save_runs( $runs );
			$this->update_summary(
				sprintf(
					'بازبینی خودکار %s در بخش %d موقتاً متوقف شد تا %d تغییر وضعیت Central توسط مبدأ تأیید شود؛ سپس از همین بخش ادامه می‌دهد.',
					$store_id,
					$page,
					$pending_count
				)
			);
			return;
		}

		$state['status']     = 'running';
		$state['waiting_pending_count'] = 0;
		$state['updated_at'] = current_time( 'mysql' );
		$runs[ $key ]        = $state;
		$this->save_runs( $runs );
		$result = $this->snapshot->automatic_sync_page(
			$store_id,
			$date_from,
			$date_to,
			$page,
			'full' === $scope,
			(string) ( $state['boundary_date'] ?? Company_Order_Sync_Settings::retention_start_date() ),
			(string) ( $state['modified_from'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$this->retry_or_fail( $state, $result->get_error_message() );
			return;
		}

		$runs  = $this->runs();
		$state = $runs[ $key ] ?? $state;
		$state['page']        = $page;
		$state['total_pages'] = absint( $result['total_pages'] ?? 1 );
		$state['total']       = absint( $result['total'] ?? 0 );
		$state['processed']   = absint( $state['processed'] ?? 0 ) + absint( $result['processed'] ?? 0 );
		$state['created']     = absint( $state['created'] ?? 0 ) + absint( $result['created'] ?? 0 );
		$state['failed_ids']  = array_values( array_unique( array_filter( array_merge( (array) ( $state['failed_ids'] ?? array() ), (array) ( $result['failed_ids'] ?? array() ) ) ) ) );
		$state['attempt']     = 0;
		$state['updated_at']  = current_time( 'mysql' );
		$is_last = $page >= max( 1, $state['total_pages'] );
		$state['status'] = $is_last ? ( $state['failed_ids'] ? 'completed_with_errors' : 'completed' ) : 'running';
		$runs[ $key ] = $state;
		$this->save_runs( $runs );

		if ( ! $is_last ) {
			$batch_started = $batch_started ?: microtime( true );
			if ( $batch_pages < self::INLINE_PAGES && microtime( true ) - $batch_started < self::INLINE_BUDGET ) {
				$this->process_page( $store_id, $scope, $date_from, $date_to, $page + 1, $run_id, $batch_started, $batch_pages + 1 );
				return;
			}
			$this->schedule_once( self::PAGE_HOOK, array( $store_id, $scope, $date_from, $date_to, $page + 1, $run_id ), time() + 1, 'full' === $scope ? 6 : 5 );
			return;
		}

		$this->update_summary(
			sprintf(
				'%s %s کامل شد: %d از %d سفارش، %d ایجاد، %d خطا',
				'recent' === $scope ? 'بازبینی ۷ روز اخیر' : 'بازبینی کامل',
				$store_id,
				absint( $state['processed'] ),
				absint( $state['total'] ),
				absint( $state['created'] ),
				count( $state['failed_ids'] )
			)
		);
		$this->start_pending_full( $store_id );
	}

	private function retry_or_fail( array $state, $message ) {
		$attempt = absint( $state['attempt'] ?? 0 ) + 1;
		$key     = $this->run_key( $state['store_id'], $state['scope'] );
		$runs    = $this->runs();
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$state['status'] = 'failed';
			$state['attempt'] = $attempt;
			$state['error'] = sanitize_text_field( $message );
			$state['updated_at'] = current_time( 'mysql' );
			$runs[ $key ] = $state;
			$this->save_runs( $runs );
			$this->update_summary( sprintf( 'بازبینی خودکار %s متوقف شد: %s', $state['store_id'], sanitize_text_field( $message ) ) );
			$this->start_pending_full( $state['store_id'] );
			return;
		}

		$state['status'] = 'retrying';
		$state['attempt'] = $attempt;
		$state['error'] = sanitize_text_field( $message );
		$state['updated_at'] = current_time( 'mysql' );
		$runs[ $key ] = $state;
		$this->save_runs( $runs );
		$delays = array( 60, 300, 900 );
		$this->schedule_once(
			self::PAGE_HOOK,
			array( $state['store_id'], $state['scope'], $state['date_from'], $state['date_to'], max( 1, absint( $state['page'] ) + 1 ), $state['run_id'] ),
			time() + $delays[ min( $attempt - 1, count( $delays ) - 1 ) ],
			4
		);
	}

	private function store_is_busy( $store_id ) {
		foreach ( $this->runs() as $state ) {
			if ( sanitize_key( $state['store_id'] ?? '' ) !== $store_id || ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending', 'waiting_final' ), true ) ) {
				continue;
			}
			if ( 'waiting_pending' === ( $state['status'] ?? '' ) ) {
				return true;
			}
			$updated = strtotime( (string) ( $state['updated_at'] ?? '' ) );
			if ( ! $updated || $updated > time() - self::STALE_AFTER ) {
				return true;
			}
		}
		return false;
	}

	private function start_pending_full( $store_id ) {
		if ( Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			return;
		}
		$pending = (array) get_option( self::PENDING_FULL_OPTION, array() );
		if ( empty( $pending[ $store_id ] ) ) {
			return;
		}
		unset( $pending[ $store_id ] );
		update_option( self::PENDING_FULL_OPTION, $pending, false );
		$this->begin_run( $store_id, 'full' );
	}

	private function defer_run( $store_id, $scope, $reason = 'pending' ) {
		$store_id = sanitize_key( $store_id );
		$scope    = 'full' === $scope ? 'full' : 'recent';
		$reason   = 'final' === $reason ? 'final' : 'pending';
		if ( ! $store_id ) {
			return;
		}
		$option  = 'full' === $scope ? self::PENDING_FULL_OPTION : self::PENDING_RECENT_OPTION;
		$pending = (array) get_option( $option, array() );
		// Keep the first timestamp: repeated 15-minute triggers must not create a
		// new logical job or make the operator's progress look as if it restarted.
		if ( empty( $pending[ $store_id ] ) ) {
			$pending[ $store_id ] = time();
			update_option( $option, $pending, false );
		}
		$this->update_summary(
			sprintf(
				'final' === $reason
					? '%s فروشگاه %s تا پایان همسان‌سازی نهایی در انتظار مانده است؛ اجرای بعدی از ابتدا ساخته نمی‌شود.'
					: '%s فروشگاه %s تا صفر شدن صف تغییر وضعیت Central در انتظار مانده است؛ اجرای بعدی از ابتدا ساخته نمی‌شود.',
				'recent' === $scope ? 'بازبینی ۷ روز اخیر' : 'بازبینی کامل',
				$store_id
			)
		);
	}

	public function resume_after_pending_drain( $progress = array() ) {
		unset( $progress );
		if ( 'central' !== Company_Order_Sync_Settings::mode() || Company_Order_Sync_Queue::pending_central_status_count() > 0 ) {
			return;
		}

		$runs    = $this->runs();
		$resumed = 0;
		foreach ( $runs as $key => $state ) {
			if ( 'waiting_pending' !== ( $state['status'] ?? '' ) || empty( $state['run_id'] ) ) {
				continue;
			}
			$store_id = sanitize_key( $state['store_id'] ?? '' );
			$scope    = 'full' === ( $state['scope'] ?? '' ) ? 'full' : 'recent';
			if ( ! $store_id ) {
				continue;
			}
			$state['status']                = 'queued';
			$state['waiting_pending_count'] = 0;
			$state['updated_at']            = current_time( 'mysql' );
			$runs[ $key ]                   = $state;
			$this->schedule_once(
				self::PAGE_HOOK,
				array(
					$store_id,
					$scope,
					(string) $state['date_from'],
					(string) $state['date_to'],
					max( 1, absint( $state['page'] ?? 0 ) + 1 ),
					(string) $state['run_id'],
				),
				time() + 1,
				'full' === $scope ? 6 : 5
			);
			++$resumed;
		}
		if ( $resumed ) {
			$this->save_runs( $runs );
			$this->update_summary( sprintf( '%d بازبینی خودکار از همان بخش قبلی بعد از صفر شدن صف ادامه یافت.', $resumed ) );
		}

		// Start at most one deferred 15-minute job per store. Repeated cron ticks
		// while the queue was non-zero only set one flag, so there is no backlog of
		// duplicate runs waiting to restart from page 1.
		$pending_recent = (array) get_option( self::PENDING_RECENT_OPTION, array() );
		foreach ( array_keys( $pending_recent ) as $store_id ) {
			$store_id = sanitize_key( $store_id );
			if ( ! $store_id || $this->store_is_busy( $store_id ) ) {
				continue;
			}
			if ( $this->begin_run( $store_id, 'recent' ) ) {
				unset( $pending_recent[ $store_id ] );
			}
		}
		update_option( self::PENDING_RECENT_OPTION, $pending_recent, false );

		$pending_full = (array) get_option( self::PENDING_FULL_OPTION, array() );
		foreach ( array_keys( $pending_full ) as $store_id ) {
			$store_id = sanitize_key( $store_id );
			if ( ! $store_id || $this->store_is_busy( $store_id ) ) {
				continue;
			}
			if ( $this->begin_run( $store_id, 'full' ) ) {
				unset( $pending_full[ $store_id ] );
			}
		}
		update_option( self::PENDING_FULL_OPTION, $pending_full, false );
	}

	public function resume_after_final_convergence( $store_id = '' ) {
		$store_id = sanitize_key( $store_id );
		if ( 'central' !== Company_Order_Sync_Settings::mode() || ( $store_id && Company_Order_Sync_Status_Repair::final_convergence_active( $store_id ) ) ) {
			return;
		}

		$runs = $this->runs();
		$resumed = 0;
		foreach ( $runs as $key => $state ) {
			if ( 'waiting_final' !== ( $state['status'] ?? '' ) || empty( $state['run_id'] ) ) {
				continue;
			}
			$run_store = sanitize_key( $state['store_id'] ?? '' );
			if ( ! $run_store || ( $store_id && $run_store !== $store_id ) || Company_Order_Sync_Status_Repair::final_convergence_active( $run_store ) ) {
				continue;
			}
			$scope = 'full' === ( $state['scope'] ?? '' ) ? 'full' : 'recent';
			$state['status']     = 'queued';
			$state['updated_at'] = current_time( 'mysql' );
			$runs[ $key ]        = $state;
			$this->schedule_once(
				self::PAGE_HOOK,
				array( $run_store, $scope, (string) $state['date_from'], (string) $state['date_to'], max( 1, absint( $state['page'] ?? 0 ) + 1 ), (string) $state['run_id'] ),
				time() + 2,
				'full' === $scope ? 6 : 5
			);
			++$resumed;
		}
		if ( $resumed ) {
			$this->save_runs( $runs );
			$this->update_summary( sprintf( '%d بازبینی خودکار بعد از پایان همسان‌سازی نهایی از همان بخش قبلی ادامه یافت.', $resumed ) );
		}

		// Deferred trigger flags are intentionally not duplicated. Start them only
		// when no preserved run for the same store is still busy.
		if ( 0 === Company_Order_Sync_Queue::pending_central_status_count() ) {
			$this->resume_after_pending_drain();
		}
	}

	public function recover_waiting_runs() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$has_waiting_pending = false;
		$has_waiting_final   = false;
		foreach ( $this->runs() as $state ) {
			if ( 'waiting_pending' === ( $state['status'] ?? '' ) ) {
				$has_waiting_pending = true;
			}
			if ( 'waiting_final' === ( $state['status'] ?? '' ) ) {
				$run_store = sanitize_key( $state['store_id'] ?? '' );
				if ( ! Company_Order_Sync_Status_Repair::final_convergence_active( $run_store ) ) {
					$has_waiting_final = true;
				}
			}
		}
		if ( $has_waiting_final ) {
			$this->resume_after_final_convergence();
		}
		if ( 0 === Company_Order_Sync_Queue::pending_central_status_count() && ( $has_waiting_pending || get_option( self::PENDING_RECENT_OPTION, array() ) || get_option( self::PENDING_FULL_OPTION, array() ) ) ) {
			$this->resume_after_pending_drain();
		}
	}

	public function cleanup_expired_orders() {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$date_from = Company_Order_Sync_Settings::retention_start_date();
		try {
			$before = ( new DateTimeImmutable( $date_from . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		} catch ( Exception $error ) {
			return;
		}
		foreach ( array_keys( (array) Company_Order_Sync_Settings::enabled_central_stores() ) as $store_id ) {
			Company_Order_Sync_Settings::set_central_min_date( $store_id, $date_from );
		}

		$order_ids = wc_get_orders(
			array(
				'limit'      => self::DELETE_BATCH,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_map( static function( $status ) { return str_replace( 'wc-', '', $status ); }, array_keys( wc_get_order_statuses() ) ),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'date_created' => '<' . $before,
				'meta_query' => array(
					array( 'key'=>'_company_source_store', 'compare'=>'EXISTS' ),
				),
			)
		);
		$deleted = 0;
		Company_Order_Sync_Context::run_inbound(
			function() use ( $order_ids, &$deleted ) {
				foreach ( (array) $order_ids as $order_id ) {
					if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
					$order = wc_get_order( absint( $order_id ) );
					if ( $order instanceof WC_Order && $order->get_meta( '_company_source_store', true ) ) {
						$order->delete( true );
						++$deleted;
					}
				}
			}
		);
		$summary = (array) get_option( self::STATE_OPTION, array() );
		$total_deleted = absint( $summary['deleted'] ?? 0 ) + $deleted;
		$this->update_summary( sprintf( '%d سفارش قدیمی‌تر از %s فقط از Central حذف شد', $deleted, $date_from ), array( 'deleted'=>$total_deleted, 'retention_from'=>$date_from ) );
		if ( count( (array) $order_ids ) >= self::DELETE_BATCH ) {
			$this->schedule_once( self::CLEANUP_HOOK, array(), time() + 10, 10 );
		}
	}

	private function schedule_once( $hook, array $args, $timestamp, $priority ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, Company_Order_Sync_Queue::GROUP, true, absint( $priority ) );
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	private function runs() {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	private function save_runs( array $runs ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		update_option( self::RUNS_OPTION, $runs, false );
	}

	private function run_key( $store_id, $scope ) {
		return sanitize_key( $store_id ) . ':' . ( 'full' === $scope ? 'full' : 'recent' );
	}

	private function update_summary( $message, array $extra = array() ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		update_option(
			self::STATE_OPTION,
			array_merge( $state, $extra, array( 'message'=>sanitize_text_field( $message ), 'updated_at'=>current_time( 'mysql' ) ) ),
			false
		);
	}

	private function unschedule_store( $store_id ) {
		foreach ( array( self::RECENT_TRIGGER_HOOK, self::FULL_TRIGGER_HOOK ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array( $store_id ), Company_Order_Sync_Queue::GROUP );
			}
			wp_clear_scheduled_hook( $hook, array( $store_id ) );
		}
		foreach ( array( self::PENDING_RECENT_OPTION, self::PENDING_FULL_OPTION ) as $option ) {
			$pending = (array) get_option( $option, array() );
			if ( isset( $pending[ $store_id ] ) ) {
				unset( $pending[ $store_id ] );
				update_option( $option, $pending, false );
			}
		}
	}

	public static function unschedule_all() {
		foreach ( array( self::RECENT_TRIGGER_HOOK, self::FULL_TRIGGER_HOOK, self::PAGE_HOOK, self::CLEANUP_HOOK ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, Company_Order_Sync_Queue::GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}
}
