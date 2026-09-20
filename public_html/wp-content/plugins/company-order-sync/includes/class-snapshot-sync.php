<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Snapshot_Sync {

	const HOOK         = 'company_order_sync_pull_snapshot';
	const STATUS_OPTION = 'company_order_sync_remote_statuses';
	const ROLE_OPTION   = 'company_order_sync_remote_roles';
	const STATE_OPTION  = 'company_order_sync_snapshot_state';
	const TARGET_ROLES  = array( 'acc_manager', 'administrator', 'customer_support', 'digikala_admin', 'logistics', 'seller', 'shop_manager' );
	const MAX_ATTEMPTS  = 5;
	const PER_PAGE      = 100;
	const INDEX_PER_PAGE= 500;
	const ORPHAN_AFTER  = 180;
	const QUERY_MODE    = 'date_created_manifest_v3_500';
	const INLINE_BUDGET = 18;
	const INLINE_PAGES  = 5;

	private $serializer;
	private $mapper;

	public function __construct() {
		$this->serializer = new Company_Order_Sync_Order_Serializer();
		$this->mapper     = new Company_Order_Sync_Order_Mapper();
	}

	public function hooks() {
		add_action( 'init', array( $this, 'register_remote_statuses' ), 5 );
		add_filter( 'wc_order_statuses', array( $this, 'merge_remote_statuses' ), 20 );
		add_action( 'admin_post_company_order_sync_snapshot', array( $this, 'start' ) );
		add_action( 'admin_post_company_order_sync_snapshot_stop', array( $this, 'stop' ) );
		add_action( self::HOOK, array( $this, 'process' ), 10, 7 );
		add_action( 'init', array( $this, 'recover_orphaned_run' ), 40 );
	}

	public function merge_remote_statuses( $statuses ) {
		foreach ( (array) get_option( self::STATUS_OPTION, array() ) as $remote_statuses ) {
			foreach ( (array) $remote_statuses as $status ) {
				$slug = sanitize_key( $status['slug'] ?? '' );
				if ( $slug ) {
					$statuses[ 'wc-' . $slug ] = sanitize_text_field( $status['label'] ?? $slug );
				}
			}
		}
		return $statuses;
	}

	public function start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_snapshot', '_cos_nonce' );
		$store_id  = isset( $_POST['store_id'] ) ? sanitize_key( wp_unslash( $_POST['store_id'] ) ) : 'site1';
		$date_from = Company_Order_Sync_Settings::retention_start_date();
		$users     = ! empty( $_POST['sync_users'] );
		if ( ! $date_from || ! Company_Order_Sync_Settings::central_store( $store_id ) ) {
			wp_die( 'فروشگاه یا بازه نگهداری معتبر نیست.', 422 );
		}
		Company_Order_Sync_Settings::set_central_min_date( $store_id, $date_from );
		$run_id = wp_generate_uuid4();
		$date_to = current_time( 'Y-m-d H:i:s' );
		update_option(
			self::STATE_OPTION,
			array( 'run_id'=>$run_id, 'store_id'=>$store_id, 'date_from'=>$date_from, 'date_to'=>$date_to, 'query_mode'=>self::QUERY_MODE, 'page'=>0, 'total_pages'=>0, 'processed'=>0, 'source_total'=>0, 'created'=>0, 'status_corrected'=>0, 'failed_count'=>0, 'failed_order_ids'=>array(), 'users'=>0, 'sync_users_requested'=>$users ? 1 : 0, 'attempt'=>0, 'status'=>'queued', 'worker_action_id'=>0, 'message'=>'در صف بازبینی و تکمیل؛ منتظر اجرای Worker', 'updated_at'=>current_time( 'mysql' ) ),
			false
		);
		$this->schedule( array( $store_id, $date_from, 1, $users ? 1 : 0, $run_id, 0, $date_to ) );
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync', 'snapshot_started'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function recover_orphaned_run() {
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'retrying' ), true ) || empty( $state['run_id'] ) || empty( $state['store_id'] ) || empty( $state['date_from'] ) ) {
			return;
		}
		if ( self::QUERY_MODE !== ( $state['query_mode'] ?? '' ) ) {
			$this->restart_with_strict_created_date( $state );
			return;
		}
		$updated_at = strtotime( (string) ( $state['updated_at'] ?? '' ) );
		if ( $updated_at && $updated_at > time() - self::ORPHAN_AFTER ) {
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		$page       = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		$sync_users = 1 === $page && ! empty( $state['sync_users_requested'] ) ? 1 : 0;
		$date_to    = $this->sanitize_datetime( $state['date_to'] ?? '' ) ?: current_time( 'Y-m-d H:i:s' );
		$this->schedule( array( sanitize_key( $state['store_id'] ), $this->sanitize_date( $state['date_from'] ), $page, $sync_users, (string) $state['run_id'], 0, $date_to ) );
		$this->update_state( array( 'status'=>'queued', 'message'=>'ادامه همگام‌سازی متوقف‌شده به‌صورت خودکار بازیابی شد' ) );
	}

	private function restart_with_strict_created_date( array $state ) {
		$store_id = sanitize_key( $state['store_id'] ?? '' );
		$date_from = Company_Order_Sync_Settings::retention_start_date();
		if ( ! $store_id || ! $date_from || ! Company_Order_Sync_Settings::central_store( $store_id ) ) {
			$this->update_state( array( 'status'=>'failed', 'message'=>'اجرای قدیمی متوقف شد؛ فروشگاه یا تاریخ شروع معتبر نیست.' ) );
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		Company_Order_Sync_Settings::set_central_min_date( $store_id, $date_from );
		$run_id    = wp_generate_uuid4();
		$date_to   = current_time( 'Y-m-d H:i:s' );
		$sync_users = ! empty( $state['sync_users_requested'] ) ? 1 : 0;
		update_option(
			self::STATE_OPTION,
			array(
				'run_id'                => $run_id,
				'store_id'              => $store_id,
				'date_from'             => $date_from,
				'date_to'               => $date_to,
				'query_mode'             => self::QUERY_MODE,
				'page'                   => 0,
				'total_pages'            => 0,
				'processed'              => 0,
				'source_total'           => 0,
				'created'                => 0,
				'status_corrected'       => 0,
				'failed_count'           => 0,
				'failed_order_ids'       => array(),
				'users'                  => 0,
				'sync_users_requested'   => $sync_users,
				'attempt'                => 0,
				'status'                 => 'queued',
				'worker_action_id'       => 0,
				'message'                => 'اجرای قبلی با فهرست سبک ۵۰۰تایی از ابتدا بازآغاز شد',
				'updated_at'             => current_time( 'mysql' ),
			),
			false
		);
		$this->schedule( array( $store_id, $date_from, 1, $sync_users, $run_id, 0, $date_to ) );
	}

	public function stop() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی غیرمجاز.', 403 );
		}
		check_admin_referer( 'company_order_sync_snapshot_stop', '_cos_nonce' );
		$this->update_state( array( 'status'=>'stopped', 'message'=>'همگام‌سازی توسط مدیر متوقف شد' ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_safe_redirect( add_query_arg( array( 'page'=>'company-order-sync', 'snapshot_stopped'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function process( $store_id, $date_from, $page, $sync_users, $run_id, $attempt = 0, $date_to = '' ) {
		if ( Company_Order_Sync_Central_Rebuild::run_blocked( $run_id ) ) { return; }
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$page  = max( 1, absint( $page ) );
		$state = get_option( self::STATE_OPTION, array() );
		if ( empty( $state['run_id'] ) || ! hash_equals( (string) $state['run_id'], (string) $run_id ) || 'stopped' === ( $state['status'] ?? '' ) ) {
			return;
		}
		$expected_page = max( 1, absint( $state['page'] ?? 0 ) + 1 );
		if ( $page < $expected_page ) {
			return;
		}
		if ( $page > $expected_page ) {
			$this->schedule( array( $store_id, $date_from, $expected_page, 0, $run_id, 0, $date_to ) );
			return;
		}
		$args = array( $store_id, $date_from, $page, absint( $sync_users ), $run_id, absint( $attempt ), $date_to );
		wp_clear_scheduled_hook( self::HOOK, $args );
		$lock = $this->acquire_page_lock( $run_id, $page );
		if ( ! $lock ) {
			return;
		}
		try {
			$this->process_page( $store_id, $date_from, $page, $sync_users, $run_id, $attempt, $date_to, microtime( true ), 1 );
		} finally {
			$this->release_page_lock( $lock );
		}
	}

	private function process_page( $store_id, $date_from, $page, $sync_users, $run_id, $attempt = 0, $date_to = '', $batch_started = 0, $batch_pages = 1 ) {
		if ( Company_Order_Sync_Central_Rebuild::run_blocked( $run_id ) ) { return; }
		if ( 'central' !== Company_Order_Sync_Settings::mode() ) {
			return;
		}
		$date_to  = $this->sanitize_datetime( $date_to ) ?: current_time( 'Y-m-d H:i:s' );
		$page     = max( 1, absint( $page ) );
		$state = get_option( self::STATE_OPTION, array() );
		if ( empty( $state['run_id'] ) || ! hash_equals( (string) $state['run_id'], (string) $run_id ) || 'stopped' === ( $state['status'] ?? '' ) ) {
			return;
		}
		$this->update_state(
			array(
				'status'  => 'running',
				'attempt' => absint( $attempt ),
				'message' => sprintf( 'در حال دریافت و ثبت بخش %d از فروشگاه مبدأ', $page ),
			)
		);
		$result = $this->request_index_snapshot( $store_id, $date_from, $date_to, $page, ! empty( $sync_users ) && 1 === $page, $date_from );
		if ( is_wp_error( $result ) ) {
			if ( $this->schedule_retry( $store_id, $date_from, $date_to, $page, $sync_users, $run_id, $attempt, $result->get_error_message() ) ) {
				return;
			}
			$this->update_state( array( 'status'=>'failed', 'attempt'=>absint( $attempt ) + 1, 'message'=>'همگام‌سازی پس از چند تلاش متوقف شد: ' . $result->get_error_message() ) );
			return;
		}
		if ( 1 === absint( $page ) && isset( $result['statuses'] ) ) {
			$this->apply_statuses( $store_id, (array) ( $result['statuses'] ?? array() ) );
			if ( ! empty( $sync_users ) && isset( $result['roles'], $result['users'] ) ) {
				$count = $this->apply_directory( $store_id, (array) ( $result['roles'] ?? array() ), (array) ( $result['users'] ?? array() ) );
				$this->update_state( array( 'users'=>$count ) );
			}
		}
		$processed = absint( $result['skipped'] ?? 0 );
		$created_count = 0;
		$status_corrected = 0;
		$map_failures = array_fill_keys( array_map( 'absint', (array) ( $result['failed_ids'] ?? array() ) ), 'اطلاعات کامل سفارش از مبدأ دریافت نشد.' );
		$orders = array_values( array_filter( (array) ( $result['orders'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $orders, 'id' ) );
		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$mapped = $this->mapper->upsert( $store_id, $order, 'snapshot-' . $run_id . '-' . absint( $page ), true );
			if ( ! is_wp_error( $mapped ) ) {
				++$processed;
				if ( ! empty( $mapped['created'] ) ) {
					++$created_count;
				} elseif ( ! empty( $mapped['previous_status'] ) && $mapped['previous_status'] !== sanitize_key( $order['status'] ?? '' ) ) {
					++$status_corrected;
				}
			} else {
				$map_failures[ absint( $order['id'] ?? 0 ) ] = $mapped->get_error_message();
				Company_Order_Sync_Logger::log( 'error', 'Snapshot order mapping failed.', array( 'store_id'=>$store_id, 'source_order_id'=>absint( $order['id'] ?? 0 ), 'page'=>absint( $page ), 'attempt'=>absint( $attempt ), 'error'=>$mapped->get_error_message() ) );
			}
		}
		if ( $map_failures && $this->schedule_retry( $store_id, $date_from, $date_to, $page, $sync_users, $run_id, $attempt, 'خطا در ثبت سفارش‌های ' . implode( '، ', array_keys( $map_failures ) ) ) ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		$total_processed = absint( $state['processed'] ?? 0 ) + $processed;
		$total_created   = absint( $state['created'] ?? 0 ) + $created_count;
		$total_corrected = absint( $state['status_corrected'] ?? 0 ) + $status_corrected;
		$total_pages     = max( 1, absint( $result['total_pages'] ?? 1 ) );
		$failed_ids      = array_values( array_unique( array_filter( array_merge( (array) ( $state['failed_order_ids'] ?? array() ), array_keys( $map_failures ) ) ) ) );
		$is_last_page    = absint( $page ) >= $total_pages;
		$complete        = $is_last_page && ! $failed_ids && $total_processed >= absint( $result['total'] ?? 0 );
		$this->update_state(
			array(
				'page'             => absint( $page ),
				'processed'        => $total_processed,
				'created'          => $total_created,
				'status_corrected' => $total_corrected,
				'source_total'     => absint( $result['total'] ?? 0 ),
				'total_pages'      => $total_pages,
				'failed_count'     => count( $failed_ids ),
				'failed_order_ids' => $failed_ids,
				'attempt'          => 0,
				'status'           => $is_last_page ? ( $complete ? 'completed' : 'completed_with_errors' ) : 'running',
				'message'          => $is_last_page ? ( $complete ? 'بازبینی کامل شد؛ همه سفارش‌های مبدأ ثبت شدند' : 'بازبینی تمام شد اما چند سفارش نیازمند بررسی است' ) : 'در حال بازبینی سفارش‌ها',
			)
		);
		if ( $is_last_page ) {
			return;
		}
		$batch_started = $batch_started ?: microtime( true );
		if ( $batch_pages < self::INLINE_PAGES && microtime( true ) - $batch_started < self::INLINE_BUDGET ) {
			unset( $result, $orders );
			$this->process_page( $store_id, $date_from, $page + 1, 0, $run_id, 0, $date_to, $batch_started, $batch_pages + 1 );
			return;
		}
		$this->schedule( array( $store_id, $date_from, $page + 1, 0, $run_id, 0, $date_to ) );
	}

	public function register_remote_statuses() {
		$stores = get_option( self::STATUS_OPTION, array() );
		foreach ( (array) $stores as $statuses ) {
			foreach ( (array) $statuses as $status ) {
				$slug  = sanitize_key( $status['slug'] ?? '' );
				$label = sanitize_text_field( $status['label'] ?? $slug );
				if ( ! $slug || get_post_status_object( 'wc-' . $slug ) ) {
					continue;
				}
				register_post_status( 'wc-' . $slug, array( 'label'=>$label, 'public'=>true, 'exclude_from_search'=>false, 'show_in_admin_all_list'=>true, 'show_in_admin_status_list'=>true, 'label_count'=>_n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ) ) );
			}
		}
	}

	public function export_snapshot( WP_REST_Request $request, array $auth ) {
		$params    = $request->get_json_params();
		$date_from = $this->sanitize_date( $params['date_from'] ?? '' );
		$boundary_date = $this->sanitize_date( $params['boundary_date'] ?? '' ) ?: $date_from;
		$date_to   = $this->sanitize_datetime( $params['date_to'] ?? '' );
		$modified_from = $this->sanitize_datetime( $params['modified_from'] ?? '' );
		$page      = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page  = min( self::PER_PAGE, max( 10, absint( $params['per_page'] ?? self::PER_PAGE ) ) );
		if ( ! $date_from || ! $date_to ) {
			return new WP_Error( 'invalid_date', 'Snapshot start date is invalid.', array( 'status'=>422 ) );
		}
		Company_Order_Sync_Settings::set_source_min_date( $boundary_date );
		try {
			$start_timestamp = ( new DateTimeImmutable( $date_from . ' 00:00:00', wp_timezone() ) )->getTimestamp();
			$end_timestamp   = ( new DateTimeImmutable( $date_to, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $error ) {
			return new WP_Error( 'invalid_date', 'Snapshot start date is invalid.', array( 'status'=>422 ) );
		}
		$statuses = array_map( static function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) );
		$range  = $start_timestamp . '...' . $end_timestamp;
		$modified_range = '';
		if ( $modified_from ) {
			try {
				$modified_start = ( new DateTimeImmutable( $modified_from, wp_timezone() ) )->getTimestamp();
				$modified_range = $modified_start . '...' . $end_timestamp;
			} catch ( Exception $error ) {
				$modified_range = '';
			}
		}
		$query  = $this->query_snapshot_orders( $statuses, $range, $page, $per_page, $modified_range );
		$orders = array();
		foreach ( $query['orders'] as $order ) {
			$orders[] = $this->serializer->serialize( $order );
			$orders[ count( $orders ) - 1 ]['source_url'] = home_url( '/' );
		}
		$response = array( 'success'=>true, 'request_id'=>$auth['request_id'], 'orders'=>$orders, 'total'=>$query['total'], 'total_pages'=>$query['total_pages'], 'page'=>$page );
		if ( 1 === $page ) {
			$response['statuses'] = $this->export_statuses();
			if ( ! empty( $params['include_directory'] ) ) {
				$response['roles'] = $this->export_roles();
				$response['users'] = $this->export_users();
			}
		}
		return $response;
	}

	private function query_snapshot_orders( array $statuses, $range, $page, $per_page, $modified_range = '' ) {
		$base_args = array(
			'limit'   => $per_page,
			'paged'   => $page,
			'paginate'=> true,
			'orderby' => 'date',
			'order'   => 'ASC',
			'type'    => 'shop_order',
			'status'  => $statuses,
		);
		$base_args['date_created'] = $range;
		if ( $modified_range ) {
			$base_args['date_modified'] = $modified_range;
		}
		$query = wc_get_orders( $base_args );
		return array( 'orders'=>(array) $query->orders, 'total'=>(int) $query->total, 'total_pages'=>(int) $query->max_num_pages );
	}

	public function automatic_sync_page( $store_id, $date_from, $date_to, $page, $include_directory = false, $boundary_date = '', $modified_from = '' ) {
		$store_id  = sanitize_key( $store_id );
		$date_from = $this->sanitize_date( $date_from );
		$boundary_date = $this->sanitize_date( $boundary_date ) ?: $date_from;
		$date_to   = $this->sanitize_datetime( $date_to );
		$modified_from = $this->sanitize_datetime( $modified_from );
		$page      = max( 1, absint( $page ) );
		if ( ! $store_id || ! $date_from || ! $date_to ) {
			return new WP_Error( 'invalid_automatic_snapshot', 'بازه همگام‌سازی خودکار معتبر نیست.' );
		}

		$result = $this->request_index_snapshot( $store_id, $date_from, $date_to, $page, $include_directory && 1 === $page, $boundary_date, $modified_from );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 1 === $page && isset( $result['statuses'] ) ) {
			$this->apply_statuses( $store_id, (array) $result['statuses'] );
		}
		$users = 0;
		if ( $include_directory && 1 === $page && isset( $result['roles'], $result['users'] ) ) {
			$users = $this->apply_directory( $store_id, (array) $result['roles'], (array) $result['users'] );
		}

		$processed = absint( $result['skipped'] ?? 0 );
		$created   = 0;
		$failed    = array_map( 'absint', (array) ( $result['failed_ids'] ?? array() ) );
		$orders = array_values( array_filter( (array) ( $result['orders'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $orders, 'id' ) );
		foreach ( $orders as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$mapped = $this->mapper->upsert( $store_id, $payload, 'automatic-snapshot-' . $store_id . '-' . $page, true );
			if ( is_wp_error( $mapped ) ) {
				$failed[] = absint( $payload['id'] ?? 0 );
				continue;
			}
			++$processed;
			if ( ! empty( $mapped['created'] ) ) {
				++$created;
			}
		}

		return array(
			'page'        => $page,
			'total'       => absint( $result['total'] ?? 0 ),
			'total_pages' => max( 1, absint( $result['total_pages'] ?? 1 ) ),
			'processed'   => $processed,
			'created'     => $created,
			'failed_ids'  => array_values( array_unique( array_filter( $failed ) ) ),
			'users'       => $users,
		);
	}

	private function request_index_snapshot( $store_id, $date_from, $date_to, $page, $include_directory, $boundary_date = '', $modified_from = '' ) {
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) {
			return new WP_Error( 'store_unavailable', 'اتصال فروشگاه فعال نیست.' );
		}
		$boundary_date = $this->sanitize_date( $boundary_date ) ?: $date_from;
		$modified_from = $this->sanitize_datetime( $modified_from );
		$payload = array(
			'store_id'         => $store_id,
			'date_from'        => $date_from,
			'boundary_date'    => $boundary_date,
			'date_to'          => $date_to,
			'modified_from'    => $modified_from,
			'page'             => max( 1, absint( $page ) ),
			'per_page'         => self::INDEX_PER_PAGE,
			'include_directory'=> ! empty( $include_directory ),
		);
		$index = $this->request_remote_json( $store_id, $store, '/wp-json/company-sync/v1/status-snapshot', $payload, 45, 'فهرست سبک سفارش‌ها' );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		$records = array_values( array_filter( (array) ( $index['statuses'] ?? array() ), 'is_array' ) );
		$this->mapper->prime_existing_orders( $store_id, wp_list_pluck( $records, 'id' ) );
		$needed_ids = array();
		foreach ( $records as $record ) {
			$source_id = absint( $record['id'] ?? 0 );
			if ( ! $source_id ) {
				continue;
			}
			$order = $this->mapper->primed_existing_order( $store_id, $source_id );
			$source_revision = max( 0, (int) ( $record['sync_revision'] ?? 0 ) );
			$central_revision = $order instanceof WC_Order ? max( 0, (int) $order->get_meta( '_company_source_sync_revision', true ) ) : 0;
			$status_differs = $order instanceof WC_Order && sanitize_key( $order->get_status() ) !== sanitize_key( $record['status'] ?? '' );
			$item_count_differs = $order instanceof WC_Order && isset( $record['line_item_count'] )
				&& count( $order->get_items( 'line_item' ) ) !== absint( $record['line_item_count'] );
			if ( ! $order || ! $source_revision || ! $central_revision || $source_revision > $central_revision || $status_differs || $item_count_differs ) {
				$needed_ids[] = $source_id;
			}
		}
		$needed_ids = array_values( array_unique( $needed_ids ) );
		$orders      = array();
		$received    = array();
		foreach ( array_chunk( $needed_ids, 100 ) as $ids ) {
			$full = $this->request_remote_json(
				$store_id,
				$store,
				'/wp-json/company-sync/v1/orders/by-ids',
				array( 'store_id'=>$store_id, 'date_from'=>$boundary_date, 'order_ids'=>$ids ),
				45,
				'جزئیات سفارش‌های تغییرکرده'
			);
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			foreach ( (array) ( $full['orders'] ?? array() ) as $order ) {
				if ( ! is_array( $order ) || ! absint( $order['id'] ?? 0 ) ) {
					continue;
				}
				$orders[]   = $order;
				$received[] = absint( $order['id'] );
			}
		}
		$response = array(
			'orders'       => $orders,
			'skipped'      => max( 0, count( $records ) - count( $needed_ids ) ),
			'failed_ids'   => array_values( array_diff( $needed_ids, array_unique( $received ) ) ),
			'total'        => absint( $index['total'] ?? 0 ),
			'total_pages'  => max( 1, absint( $index['total_pages'] ?? 1 ) ),
			'page'         => max( 1, absint( $index['page'] ?? $page ) ),
		);
		if ( 1 === max( 1, absint( $page ) ) ) {
			$response['statuses'] = (array) ( $index['status_definitions'] ?? array() );
			if ( $include_directory ) {
				$response['roles'] = (array) ( $index['roles'] ?? array() );
				$response['users'] = (array) ( $index['users'] ?? array() );
			}
		}
		return $response;
	}

	private function request_remote_json( $store_id, array $store, $path, array $payload, $timeout, $label ) {
		$body    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$headers = Company_Order_Sync_Security::headers( $store_id, (string) $store['outbound_secret'], $body );
		$url     = untrailingslashit( $store['url'] ) . $path;
		$response = wp_safe_remote_post( $url, array( 'timeout'=>$timeout, 'redirection'=>0, 'headers'=>$headers, 'body'=>$body, 'data_format'=>'body' ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'snapshot_transport_failed', $label . ': ' . sanitize_text_field( $response->get_error_message() ) );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $http < 200 || $http >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$message = is_array( $data ) ? sanitize_text_field( $data['message'] ?? '' ) : '';
			return new WP_Error( 'snapshot_index_failed', sprintf( '%s — HTTP %d: %s', $label, $http, $message ?: 'پاسخ نامعتبر فروشگاه' ) );
		}
		return $data;
	}

	private function export_statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $key=>$label ) {
			$out[] = array( 'slug'=>str_replace( 'wc-', '', sanitize_key( $key ) ), 'label'=>wp_strip_all_tags( $label ) );
		}
		return $out;
	}

	public function export_roles() {
		$out = array();
		foreach ( self::TARGET_ROLES as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				$out[ $slug ] = array( 'name'=>translate_user_role( wp_roles()->roles[ $slug ]['name'] ?? $slug ), 'capabilities'=>array_keys( array_filter( $role->capabilities ) ) );
			}
		}
		return $out;
	}

	public function export_users() {
		$out = array();
		$users = get_users( array( 'role__in'=>self::TARGET_ROLES, 'orderby'=>'ID', 'order'=>'ASC' ) );
		foreach ( $users as $user ) {
			$roles = array_values( array_intersect( self::TARGET_ROLES, (array) $user->roles ) );
			if ( ! $roles ) {
				continue;
			}
			$out[] = array( 'source_id'=>(int) $user->ID, 'login'=>$user->user_login, 'email'=>$user->user_email, 'display_name'=>$user->display_name, 'first_name'=>$user->first_name, 'last_name'=>$user->last_name, 'roles'=>$roles );
		}
		return $out;
	}

	public function apply_statuses( $store_id, array $statuses ) {
		$all = get_option( self::STATUS_OPTION, array() );
		$all[ $store_id ] = array_values( array_filter( array_map( function( $status ) { $slug=sanitize_key( $status['slug'] ?? '' ); return $slug ? array( 'slug'=>$slug, 'label'=>sanitize_text_field( $status['label'] ?? $slug ) ) : null; }, $statuses ) ) );
		update_option( self::STATUS_OPTION, $all, false );
		$this->register_remote_statuses();
	}

	private function apply_directory( $store_id, array $roles, array $users ) {
		$stored_roles = get_option( self::ROLE_OPTION, array() );
		$stored_roles[ $store_id ] = $roles;
		update_option( self::ROLE_OPTION, $stored_roles, false );
		foreach ( $roles as $slug=>$data ) {
			if ( ! in_array( $slug, self::TARGET_ROLES, true ) ) continue;
			$role = get_role( $slug );
			if ( ! $role ) $role = add_role( $slug, sanitize_text_field( $data['name'] ?? $slug ), array( 'read'=>true ) );
			if ( $role ) {
				foreach ( (array) ( $data['capabilities'] ?? array() ) as $cap ) $role->add_cap( sanitize_key( $cap ) );
				$role->add_cap( 'company_manage_orders' );
			}
		}
		$count = 0;
		foreach ( $users as $data ) {
			$email = sanitize_email( $data['email'] ?? '' );
			$login = sanitize_user( $data['login'] ?? '', false );
			$user  = $email ? get_user_by( 'email', $email ) : false;
			if ( ! $user && $login ) $user = get_user_by( 'login', $login );
			if ( ! $user && $login && $email ) {
				$id = wp_insert_user( array( 'user_login'=>$login, 'user_email'=>$email, 'user_pass'=>wp_generate_password( 40, true, true ), 'display_name'=>sanitize_text_field( $data['display_name'] ?? $login ), 'first_name'=>sanitize_text_field( $data['first_name'] ?? '' ), 'last_name'=>sanitize_text_field( $data['last_name'] ?? '' ), 'role'=>'' ) );
				$user = is_wp_error( $id ) ? false : get_user_by( 'id', $id );
			}
			if ( ! $user ) continue;
			wp_update_user( array( 'ID'=>$user->ID, 'display_name'=>sanitize_text_field( $data['display_name'] ?? $user->display_name ), 'first_name'=>sanitize_text_field( $data['first_name'] ?? '' ), 'last_name'=>sanitize_text_field( $data['last_name'] ?? '' ) ) );
			$target_roles = array_values( array_intersect( self::TARGET_ROLES, (array) ( $data['roles'] ?? array() ) ) );
			if ( $target_roles ) {
				$user->set_role( reset( $target_roles ) );
				foreach ( array_slice( $target_roles, 1 ) as $role ) $user->add_role( $role );
			}
			update_user_meta( $user->ID, '_company_source_store', $store_id );
			update_user_meta( $user->ID, '_company_source_user_id', absint( $data['source_id'] ?? 0 ) );
			++$count;
		}
		return $count;
	}

	private function request_snapshot( $store_id, $date_from, $date_to, $page, $include_directory, $boundary_date = '', $modified_from = '' ) {
		$store = Company_Order_Sync_Settings::central_store( $store_id );
		if ( ! $store || empty( $store['enabled'] ) ) return new WP_Error( 'store_unavailable', 'اتصال فروشگاه فعال نیست.' );
		$boundary_date = $this->sanitize_date( $boundary_date ) ?: $date_from;
		$modified_from = $this->sanitize_datetime( $modified_from );
		$body = wp_json_encode( array( 'store_id'=>$store_id, 'date_from'=>$date_from, 'boundary_date'=>$boundary_date, 'date_to'=>$date_to, 'modified_from'=>$modified_from, 'page'=>$page, 'per_page'=>self::PER_PAGE, 'include_directory'=>$include_directory, 'enforce_boundary'=>true ) );
		$headers = Company_Order_Sync_Security::headers( $store_id, (string) $store['outbound_secret'], $body );
		$url = untrailingslashit( $store['url'] ) . '/wp-json/company-sync/v1/snapshot';
		$response = wp_safe_remote_post( $url, array( 'timeout'=>45, 'redirection'=>0, 'headers'=>$headers, 'body'=>$body, 'data_format'=>'body' ) );
		if ( is_wp_error( $response ) ) {
			Company_Order_Sync_Logger::log( 'error', 'Snapshot request transport failed.', array( 'store_id'=>$store_id, 'url'=>$url, 'page'=>$page, 'error'=>$response->get_error_message() ) );
			return new WP_Error( 'snapshot_transport_failed', 'ارتباط با فروشگاه برقرار نشد: ' . sanitize_text_field( $response->get_error_message() ) );
		}
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw_body, true );
		if ( $http_code < 200 || $http_code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$remote_message = is_array( $data ) ? sanitize_text_field( $data['message'] ?? '' ) : '';
			$remote_code    = is_array( $data ) ? sanitize_key( $data['code'] ?? '' ) : '';
			$location       = esc_url_raw( wp_remote_retrieve_header( $response, 'location' ) );
			if ( ! $remote_message ) {
				$remote_message = $raw_body ? sanitize_text_field( wp_html_excerpt( wp_strip_all_tags( $raw_body ), 180, '…' ) ) : 'پاسخ خالی از فروشگاه دریافت شد.';
			}
			$details = sprintf( 'HTTP %d%s: %s%s', $http_code, $remote_code ? ' / ' . $remote_code : '', $remote_message, $location ? ' — انتقال به: ' . $location : '' );
			Company_Order_Sync_Logger::log( 'error', 'Snapshot request rejected by source store.', array( 'store_id'=>$store_id, 'url'=>$url, 'page'=>$page, 'http_code'=>$http_code, 'remote_code'=>$remote_code, 'redirect_location'=>$location, 'response_excerpt'=>sanitize_text_field( wp_html_excerpt( wp_strip_all_tags( $raw_body ), 500, '…' ) ) ) );
			return new WP_Error( 'snapshot_failed', $details );
		}
		return $data;
	}

	private function update_state( array $changes ) {
		if ( Company_Order_Sync_Central_Rebuild::blocked() ) { return; }
		$state = get_option( self::STATE_OPTION, array() );
		update_option( self::STATE_OPTION, array_merge( $state, $changes, array( 'updated_at'=>current_time( 'mysql' ) ) ), false );
	}

	private function schedule( array $args ) {
		$action_id = 0;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = absint( as_enqueue_async_action( self::HOOK, $args, Company_Order_Sync_Queue::GROUP, true, 5 ) );
		}
		if ( ! $action_id && ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK, $args );
		}
		$this->update_state( array( 'worker_action_id'=>$action_id ) );
	}

	private function has_scheduled_worker() {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return false !== as_next_scheduled_action( self::HOOK, null, Company_Order_Sync_Queue::GROUP );
		}
		return (bool) wp_next_scheduled( self::HOOK );
	}

	private function acquire_page_lock( $run_id, $page ) {
		$key   = 'company_snapshot_page_' . md5( (string) $run_id . ':' . absint( $page ) );
		$token = time() . ':' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) {
			return array( 'key'=>$key, 'token'=>$token );
		}
		$existing = (string) get_option( $key, '' );
		$created  = absint( strtok( $existing, ':' ) );
		if ( $created && time() - $created > self::ORPHAN_AFTER ) {
			$this->delete_lock_value( $key, $existing );
			if ( add_option( $key, $token, '', false ) ) {
				return array( 'key'=>$key, 'token'=>$token );
			}
		}
		return false;
	}

	private function release_page_lock( $lock ) {
		if ( is_array( $lock ) && ! empty( $lock['key'] ) && isset( $lock['token'] ) ) {
			$this->delete_lock_value( $lock['key'], (string) $lock['token'] );
		}
	}

	private function delete_lock_value( $key, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $token ) ) );
		wp_cache_delete( $key, 'options' );
	}

	private function schedule_retry( $store_id, $date_from, $date_to, $page, $sync_users, $run_id, $attempt, $message ) {
		$next_attempt = absint( $attempt ) + 1;
		if ( $next_attempt >= self::MAX_ATTEMPTS ) {
			return false;
		}
		$delays = array( 60, 300, 900, 1800 );
		$this->update_state( array( 'status'=>'retrying', 'attempt'=>$next_attempt, 'message'=>sprintf( 'تلاش مجدد %1$d از %2$d برای بخش %3$d: %4$s', $next_attempt, self::MAX_ATTEMPTS - 1, absint( $page ), sanitize_text_field( $message ) ) ) );
		$args = array( $store_id, $date_from, absint( $page ), absint( $sync_users ), $run_id, $next_attempt, $date_to );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delays[ min( absint( $attempt ), count( $delays ) - 1 ) ], self::HOOK, $args, Company_Order_Sync_Queue::GROUP, true );
		} else {
			wp_schedule_single_event( time() + $delays[ min( absint( $attempt ), count( $delays ) - 1 ) ], self::HOOK, $args );
		}
		return true;
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
