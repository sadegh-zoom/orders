<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exclusive, checkpointed background reconstruction. */
final class Company_Order_Sync_Central_Rebuild {
	const OPTION = 'company_sync_central_rebuild';
	const CONTROL = 'company_sync_rebuild_control';
	const WORKER = 'company_sync_rebuild_worker';
	const WATCHDOG = 'company_sync_rebuild_watchdog';
	const ACTIVE_PHASES = array( 'preflight', 'archive', 'import', 'verify', 'failed' );
	const BUDGET = 18;
	const PAGE_SIZE = 50;
	private static $ingesting = false;
	private static $request_run = null;

	public static function bind_request() {
		$state = get_option( self::OPTION, array() );
		self::$request_run = (string) ( $state['run'] ?? '' );
	}

	public static function active() {
		$state = get_option( self::OPTION, array() );
		return 'central' === Company_Order_Sync_Settings::mode() && in_array( $state['phase'] ?? '', self::ACTIVE_PHASES, true );
	}
	public static function blocked() {
		if ( self::$ingesting || 'central' !== Company_Order_Sync_Settings::mode() ) { return false; }
		// Long-running workers must see a rebuild started by another request.
		global $wpdb;
		$state = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) ) );
		return is_array( $state ) && (
			in_array( $state['phase'] ?? '', self::ACTIVE_PHASES, true )
			|| ( null !== self::$request_run && self::$request_run !== (string) ( $state['run'] ?? '' ) )
		);
	}
	public static function run_blocked( $run_id ) {
		if ( self::blocked() ) { return true; }
		$state = get_option( self::OPTION, array() );
		return in_array( (string) $run_id, (array) ( $state['retired_runs'] ?? array() ), true );
	}

	private function retire_background_runs( array $state ) {
		$retired = (array) ( $state['retired_runs'] ?? array() );
		foreach ( array( Company_Order_Sync_Snapshot_Sync::STATE_OPTION, Company_Order_Sync_Status_Repair::STATE_OPTION, Company_Order_Sync_Status_Audit::STATE_OPTION ) as $option ) {
			$run = get_option( $option, array() );
			if ( ! empty( $run['run_id'] ) ) {
				$retired[] = (string) $run['run_id'];
				$run['status'] = 'superseded';
				$run['message'] = 'اجرای قبلی به‌دلیل بازسازی Central باطل شد.';
				update_option( $option, $run, false );
			}
		}
		$runs = (array) get_option( Company_Order_Sync_Retention_Maintenance::RUNS_OPTION, array() );
		foreach ( $runs as &$run ) {
			if ( ! empty( $run['run_id'] ) ) { $retired[] = (string) $run['run_id']; }
			$run['status'] = 'superseded';
		}
		unset( $run );
		update_option( Company_Order_Sync_Retention_Maintenance::RUNS_OPTION, $runs, false );
		update_option( Company_Order_Sync_Retention_Maintenance::PENDING_RECENT_OPTION, array(), false );
		update_option( Company_Order_Sync_Retention_Maintenance::PENDING_FULL_OPTION, array(), false );
		foreach ( array( Company_Order_Sync_Snapshot_Sync::HOOK, Company_Order_Sync_Status_Repair::HOOK, Company_Order_Sync_Status_Audit::HOOK, Company_Order_Sync_Retention_Maintenance::PAGE_HOOK ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( $hook, null, Company_Order_Sync_Queue::GROUP ); }
			wp_clear_scheduled_hook( $hook );
		}
		$state['retired_runs'] = array_values( array_unique( $retired ) );
		return $state;
	}
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'wp_ajax_company_sync_rebuild', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_company_sync_rebuild', static function() { wp_send_json_error( array( 'message'=>'نشست ورود منقضی شده؛ دوباره وارد مدیریت شوید.' ), 403 ); } );
		add_filter( 'rest_pre_dispatch', array( $this, 'hold_writes' ), 5, 3 );
		add_action( 'admin_init', array( $this, 'hold_legacy_writes' ), 1 );
		add_action( 'wp_ajax_company_sync_rebuild_worker', array( $this, 'http_worker' ) );
		add_action( 'wp_ajax_nopriv_company_sync_rebuild_worker', array( $this, 'http_worker' ) );
		add_action( self::WORKER, array( $this, 'worker' ), 10, 1 );
		add_action( self::WATCHDOG, array( $this, 'worker' ) );
		add_filter( 'cron_schedules', static function( $schedules ) { $schedules['company_rebuild_minute'] = array( 'interval'=>60, 'display'=>'بازسازی سفارش‌ها' ); return $schedules; } );
		add_action( 'init', array( $this, 'ensure_watchdog' ), 6 );
	}
	public function hold_legacy_writes() {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( self::active() && in_array( $action, array( 'company_orders_update_status', 'company_orders_retry_sync', 'company_orders_submit_tracking', 'company_orders_add_note' ), true ) ) {
			wp_die( 'بازسازی Central فعال است؛ تا پایان بازسازی عملیات سفارش متوقف است.', 503 );
		}
	}
	public function hold_writes( $result, $server, $request ) {
		if ( self::active() && 'GET' !== $request->get_method() && preg_match( '#^/(company-sync|company-orders|company-central|company-central-orders)/#', $request->get_route() ) ) {
			return new WP_Error( 'sync_busy', 'Central در حال بازسازی است؛ ارسال را بعداً تکرار کنید.', array( 'status'=>503 ) );
		}
		return $result;
	}
	public function menu() {
		if ( 'central' === Company_Order_Sync_Settings::mode() ) {
			add_submenu_page( 'woocommerce', 'بازسازی سفارش‌های Central', 'بازسازی سفارش‌های Central', 'manage_options', 'company-sync-rebuild', array( $this, 'render' ) );
		}
	}
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?><div class="wrap"><h1>بازسازی سفارش‌های Central از مبدأ</h1>
		<p>نسخه‌های فعلی فروشگاه انتخاب‌شده از پنل خارج و در زباله‌دان Central آرشیو می‌شوند. سپس سفارش‌های بازه نگهداری تنظیم‌شده با وضعیت تازه مبدأ دریافت و یک بار دیگر بازبینی می‌شوند. شناسه داخلی Central عوض می‌شود. یادداشت‌های محلی در نسخه آرشیوی باقی می‌مانند. این عملیات سفارشی در مبدأ حذف یا ویرایش نمی‌کند.</p>
		<p>بازسازی روی سرور با اولویت بالا ادامه می‌یابد و می‌توانید صفحه را ببندید. همه کارهای سینک و ترمیم Central تا پایان متوقف‌اند. «توقف همه» بازسازی را نیز متوقف می‌کند و قفل ترمیم را نگه می‌دارد. برای هر فروشگاه جدا اجرا کنید.</p>
		<select id="cos-rebuild-store"><?php foreach ( Company_Order_Sync_Settings::enabled_central_stores() as $id=>$store ) { ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $store['label'] ?: $id ); ?></option><?php } ?></select>
		<button class="button button-primary" id="cos-rebuild-start">بازسازی پس‌زمینه با اولویت بالا</button> <button class="button" id="cos-rebuild-resume">ادامه / تلاش مجدد</button> <button class="button" id="cos-rebuild-stop">توقف همه و حفظ قفل</button> <button class="button" id="cos-rebuild-release">لغو بازسازی و بازکردن پنل</button>
		<pre id="cos-rebuild-result" style="white-space:pre-wrap"></pre></div>
		<script>
		(()=>{let busy=false;const out=document.getElementById('cos-rebuild-result');
		const call=async(command)=>{const body=new URLSearchParams({action:'company_sync_rebuild',nonce:<?php echo wp_json_encode( wp_create_nonce( 'company_sync_rebuild' ) ); ?>,command,store:document.getElementById('cos-rebuild-store').value});const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),12000);try{const response=await fetch(ajaxurl,{method:'POST',body,credentials:'same-origin',signal:controller.signal});const raw=await response.text();let data;try{data=JSON.parse(raw);}catch(e){throw Error(`پاسخ پنل JSON نیست (HTTP ${response.status}). احتمال انتقال به ورود، خطای PHP یا محدودیت هاست وجود دارد. اجرای پس‌زمینه مستقل است؛ وضعیت را پس از تازه‌سازی بررسی کنید.`);}if(!response.ok||!data.success)throw Error(data.data?.message||`خطای HTTP ${response.status}`);out.textContent=data.data.message;return data.data;}finally{clearTimeout(timer);}};
		const command=async(value)=>{if(busy)return;busy=true;try{await call(value);}catch(e){out.textContent=e.name==='AbortError'?'نمایش وضعیت بیش از حد طول کشید؛ این خطا به‌معنای توقف کار سرور نیست.':e.message;}finally{busy=false;}};
		document.getElementById('cos-rebuild-start').onclick=()=>{if(confirm('سینک و ترمیم Central متوقف، نسخه‌های فعلی آرشیو و نسخه تازه مبدأ دریافت شوند؟'))command('start');};
		document.getElementById('cos-rebuild-resume').onclick=()=>command('resume');
		document.getElementById('cos-rebuild-stop').onclick=()=>command('pause');
		document.getElementById('cos-rebuild-release').onclick=()=>{if(confirm('ممکن است فهرست هنوز ناقص باشد. بازسازی لغو و عملیات عادی آزاد شود؟'))command('release');};
		command('status');setInterval(()=>{if(!document.hidden)command('status');},5000);
		})();</script><?php
	}
	public function ajax() {
		if ( ! check_ajax_referer( 'company_sync_rebuild', 'nonce', false ) ) { wp_send_json_error( array( 'message'=>'نشست یا مجوز صفحه منقضی شده؛ دوباره وارد شوید و صفحه را تازه‌سازی کنید.' ), 403 ); }
		if ( ! current_user_can( 'manage_options' ) || 'central' !== Company_Order_Sync_Settings::mode() ) { wp_send_json_error( array( 'message'=>'دسترسی غیرمجاز.' ), 403 ); }
		$command = sanitize_key( $_POST['command'] ?? 'status' );
		$state = $this->read_state();
		if ( 'status' === $command ) { wp_send_json_success( $this->public_state( $state ) ); }
		if ( in_array( $command, array( 'pause', 'stop', 'release' ), true ) ) {
			if ( ! empty( $state['run'] ) && self::active() ) {
				update_option( self::CONTROL, array( 'run'=>$state['run'], 'action'=>'release' === $command ? 'release' : 'pause' ), false );
				$this->schedule_worker( $state['run'], 1 );
				$this->kick( $state );
			}
			wp_send_json_success( array( 'active'=>self::active(), 'message'=>'درخواست توقف ثبت شد؛ کار جاری در اولین نقطه ذخیره متوقف می‌شود.' ) );
		}
		global $wpdb;
		$lock = 'cos-rebuild-' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { wp_send_json_error( array( 'message'=>'مرحله قبلی هنوز در حال اجرا است.' ), 409 ); }
		$error = null;
		try {
			$state = $this->read_state();
			if ( 'start' === $command ) {
				if ( self::active() ) { throw new RuntimeException( 'بازسازی قبلی هنوز فعال است؛ ادامه را بزنید.' ); }
				if ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS <= 0 ) { throw new RuntimeException( 'زباله‌دان وردپرس غیرفعال است؛ برای حفظ نسخه قابل‌بازیابی ابتدا EMPTY_TRASH_DAYS را بزرگ‌تر از صفر تنظیم کنید.' ); }
				$store = sanitize_key( $_POST['store'] ?? '' );
				if ( ! isset( Company_Order_Sync_Settings::enabled_central_stores()[ $store ] ) ) { throw new RuntimeException( 'فروشگاه فعال معتبر انتخاب کنید.' ); }
				$state = array( 'phase'=>'preflight', 'store'=>$store, 'from'=>max( Company_Order_Sync_Settings::retention_start_date(), Company_Order_Sync_Settings::central_min_date( $store ) ), 'to'=>current_time( 'mysql' ), 'page'=>1, 'cursor'=>0, 'run'=>wp_generate_uuid4(), 'worker_key'=>wp_generate_password( 64, true, true ), 'retired_runs'=>(array) ( $state['retired_runs'] ?? array() ), 'processed'=>0, 'attempt'=>0 );
				if ( ! $state['from'] ) { throw new RuntimeException( 'ابتدا بازه نگهداری سفارش‌ها را تنظیم کنید.' ); }
				$state['message'] = 'بازسازی پس‌زمینه ثبت شد؛ سینک و ترمیم تا پایان قفل هستند. می‌توانید صفحه را ببندید.';
				$this->save_state( $state );
			} elseif ( in_array( $command, array( 'step', 'resume' ), true ) && self::active() ) {
				$state['worker_key'] = $state['worker_key'] ?? wp_generate_password( 64, true, true );
				if ( 'failed' === $state['phase'] ) { $state['phase'] = $state['failed_phase'] ?? 'preflight'; }
				$state['attempt'] = 0;
				$state['next_at'] = 0;
				$state['paused'] = false;
				$state['message'] = 'ادامه بازسازی در پس‌زمینه درخواست شد.';
				$this->save_state( $state );
			}
			update_option( self::CONTROL, array(), false );
		} catch ( Throwable $e ) { $error = $e->getMessage(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		if ( $error ) { wp_send_json_error( array( 'message'=>$error ), 409 ); }
		if ( self::active() ) { $this->ensure_watchdog(); $this->schedule_worker( $state['run'], 1 ); $this->kick( $state ); }
		wp_send_json_success( $this->public_state( $state ) );
	}
	private function read_state( $option = self::OPTION ) {
		global $wpdb;
		$state = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $option ) ) );
		return is_array( $state ) ? $state : array();
	}
	private function save_state( array $state ) {
		$state['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION, $state, false );
	}
	private function public_state( array $state ) {
		$message = $state['message'] ?? 'بازسازی آغاز نشده است.';
		if ( ! empty( $state['updated_at'] ) ) { $message .= '\nآخرین پیشرفت: ' . $state['updated_at']; }
		if ( ! empty( $state['error'] ) ) { $message .= '\nخطا: ' . $state['error']; }
		return array( 'active'=>self::active(), 'phase'=>$state['phase'] ?? '', 'message'=>str_replace( '\n', "\n", $message ) );
	}
	public function ensure_watchdog() {
		if ( ! self::active() ) { wp_clear_scheduled_hook( self::WATCHDOG ); return; }
		if ( ! wp_next_scheduled( self::WATCHDOG ) ) { wp_schedule_event( time() + 60, 'company_rebuild_minute', self::WATCHDOG ); }
	}
	private function schedule_worker( $run, $delay ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), self::WORKER, array( $run ), 'company-rebuild-priority', true, 0 );
		}
		$this->ensure_watchdog();
	}
	private function kick( array $state ) {
		if ( empty( $state['worker_key'] ) || ! self::active() ) { return; }
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $state['run'] . ':' . $timestamp, $state['worker_key'] );
		// Authenticated loopback starts the next chunk without browser or cron latency.
		wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'blocking'=>false, 'timeout'=>0.1, 'redirection'=>0, 'body'=>array( 'action'=>'company_sync_rebuild_worker', 'run'=>$state['run'], 'timestamp'=>$timestamp, 'signature'=>$signature ) ) );
	}
	public static function valid_signature( array $state, $run, $timestamp, $signature ) {
		return ! empty( $state['worker_key'] ) && (string) ( $state['run'] ?? '' ) === $run && abs( time() - (int) $timestamp ) <= 120
			&& hash_equals( hash_hmac( 'sha256', $run . ':' . $timestamp, $state['worker_key'] ), $signature );
	}
	public function http_worker() {
		$run = sanitize_text_field( wp_unslash( $_POST['run'] ?? '' ) );
		$timestamp = sanitize_text_field( wp_unslash( $_POST['timestamp'] ?? '' ) );
		$signature = sanitize_text_field( wp_unslash( $_POST['signature'] ?? '' ) );
		if ( ! self::valid_signature( $this->read_state(), $run, $timestamp, $signature ) ) { status_header( 403 ); exit; }
		$this->worker( $run );
		status_header( 204 );
		exit;
	}
	public function worker( $run = '' ) {
		if ( ! self::active() ) { return; }
		global $wpdb;
		$lock = 'cos-rebuild-' . md5( $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return; }
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 75 ); }
		$started = microtime( true );
		$next = false;
		$state = $this->read_state();
		try {
			if ( $run && ( $state['run'] ?? '' ) !== $run ) { return; }
			// Upgrade an already-running browser-driven rebuild without losing its cursor.
			$state['worker_key'] = $state['worker_key'] ?? wp_generate_password( 64, true, true );
			if ( empty( $state['background_ready'] ) ) {
				$state = $this->retire_background_runs( $state );
				$state['background_ready'] = true;
				$this->save_state( $state );
			}
			while ( microtime( true ) - $started < self::BUDGET ) {
				$control = $this->read_state( self::CONTROL );
				if ( ( $control['run'] ?? '' ) === $state['run'] ) {
					$state['paused'] = true;
					$state['message'] = 'همه عملیات سینک و ترمیم متوقف‌اند؛ بازسازی هم در محل ذخیره متوقف است. برای ادامه دکمه ادامه را بزنید.';
					if ( 'release' === ( $control['action'] ?? '' ) ) {
						$state['phase'] = 'stopped';
						$state['message'] = 'بازسازی لغو و پنل آزاد شد؛ ممکن است سفارش‌های Central هنوز کامل نباشند.';
					}
					$this->save_state( $state );
					break;
				}
				if ( ! empty( $state['paused'] ) || 'failed' === $state['phase'] || ! in_array( $state['phase'], self::ACTIVE_PHASES, true ) || ( $state['next_at'] ?? 0 ) > time() ) { break; }
				$state = $this->step( $state );
				$state['attempt'] = 0;
				$state['error'] = '';
				$state['next_at'] = 0;
				$this->save_state( $state );
			}
			$next = in_array( $state['phase'], array( 'preflight', 'archive', 'import', 'verify' ), true ) && empty( $state['paused'] ) && ( $state['next_at'] ?? 0 ) <= time();
		} catch ( Throwable $e ) {
			$state['attempt'] = absint( $state['attempt'] ?? 0 ) + 1;
			$state['error'] = sanitize_text_field( $e->getMessage() );
			$state['next_at'] = time() + min( 300, 15 * ( 2 ** min( 4, $state['attempt'] - 1 ) ) );
			$state['message'] = 'خطا در بازسازی؛ تلاش مجدد خودکار از همین نقطه انجام می‌شود. قفل ترمیم برقرار است.';
			if ( $state['attempt'] >= 8 ) {
				$state['failed_phase'] = $state['phase'];
				$state['phase'] = 'failed';
				$state['message'] = 'پس از ۸ تلاش ناموفق بازسازی متوقف شد؛ قفل سینک و ترمیم حفظ شده است. خطا را رفع و ادامه را بزنید.';
			}
			$this->save_state( $state );
		} finally {
			self::$ingesting = false;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		if ( self::active() ) {
			$this->schedule_worker( $state['run'], max( 1, ( $state['next_at'] ?? 0 ) - time() ) );
			if ( $next ) { $this->kick( $state ); }
		} else { wp_clear_scheduled_hook( self::WATCHDOG ); }
	}
	private function fetch_page( array $state ) {
		$store = Company_Order_Sync_Settings::central_store( $state['store'] );
		if ( ! $store || empty( $store['url'] ) || empty( $store['outbound_secret'] ) ) { return new WP_Error( 'rebuild_connection', 'تنظیمات اتصال مبدأ کامل نیست.' ); }
		$body = wp_json_encode( array( 'store_id'=>$state['store'], 'date_from'=>$state['from'], 'boundary_date'=>$state['from'], 'date_to'=>$state['to'], 'page'=>$state['page'], 'per_page'=>$state['page_size'] ?? 25, 'include_directory'=>false, 'enforce_boundary'=>true ) );
		$response = wp_safe_remote_post( untrailingslashit( $store['url'] ) . '/wp-json/company-sync/v1/snapshot', array( 'timeout'=>30, 'redirection'=>0, 'body'=>$body, 'headers'=>Company_Order_Sync_Security::headers( $state['store'], $store['outbound_secret'], $body ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['success'] ) || ! isset( $data['orders'], $data['total_pages'] ) || ! is_array( $data['orders'] ) ) {
			$message = is_array( $data ) ? sanitize_text_field( $data['message'] ?? '' ) : 'پاسخ HTML یا غیر JSON؛ صفحه ورود، محدودیت امنیتی یا خطای PHP/هاست را بررسی کنید.';
			return new WP_Error( 'rebuild_source', 'مبدأ ' . $state['store'] . ' — HTTP ' . wp_remote_retrieve_response_code( $response ) . ': ' . $message );
		}
		return $data;
	}
	private function step( array $state ) {
		if ( 'preflight' === $state['phase'] ) {
			$state['page_size'] = self::PAGE_SIZE;
			$probe = $this->fetch_page( $state );
			if ( is_wp_error( $probe ) ) { throw new RuntimeException( $probe->get_error_message() ); }
			$query = wc_get_orders( array( 'type'=>'shop_order', 'limit'=>1, 'paginate'=>true, 'return'=>'ids', 'meta_query'=>array( array( 'key'=>'_company_source_store', 'value'=>$state['store'] ) ) ) );
			$state['archive_total'] = absint( $query->total ?? 0 );
			$state['phase'] = 'archive';
			$state['message'] = 'اتصال مبدأ بررسی شد؛ آرشیو سفارش‌های قبلی آغاز می‌شود.';
			return $state;
		}
		if ( 'archive' === $state['phase'] ) {
			// Keep only a small checkpoint in the options table even for large stores.
			// Removing source mapping naturally removes archived orders from this query.
			if ( isset( $state['ids'] ) ) { $state['archive_total'] = count( $state['ids'] ); unset( $state['ids'] ); }
			if ( empty( $state['archive_batch'] ) ) {
				$state['archive_batch'] = wc_get_orders( array( 'type'=>'shop_order', 'limit'=>50, 'orderby'=>'ID', 'order'=>'ASC', 'return'=>'ids', 'meta_query'=>array( array( 'key'=>'_company_source_store', 'value'=>$state['store'] ) ) ) );
				if ( ! $state['archive_batch'] ) { $state['phase']='import'; unset( $state['archive_batch'] ); return $state; }
				return $state;
			}
			foreach ( array_slice( $state['archive_batch'], 0, 1 ) as $id ) {
				$order = wc_get_order( $id );
				if ( $order && $order->get_meta( '_company_source_store', true ) === $state['store'] ) {
					$result = ( new Company_Order_Sync_Order_Mapper() )->archive_for_rebuild( $order, $state['run'] );
					if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
				}
				++$state['cursor'];
				array_shift( $state['archive_batch'] );
			}
			$state['message'] = sprintf( 'آرشیو Central: %d از %d', $state['cursor'], $state['archive_total'] ?? $state['cursor'] );
			return $state;
		}
		if ( ! array_key_exists( 'batch', $state ) ) {
			$data = $this->fetch_page( $state );
			if ( is_wp_error( $data ) ) { throw new RuntimeException( $data->get_error_message() ); }
			if ( isset( $data['statuses'] ) ) { ( new Company_Order_Sync_Snapshot_Sync() )->apply_statuses( $state['store'], (array) $data['statuses'] ); }
			$state['batch'] = array_values( $data['orders'] );
			$state['total_pages'] = max( 1, absint( $data['total_pages'] ) );
			$state['total'] = absint( $data['total'] ?? 0 );
			// Persist fetched payload before applying items; resume does not refetch a
			// shifting page after a timeout partway through mapping.
			return $state;
		}
		if ( $state['batch'] ) {
			$payload = reset( $state['batch'] );
			if ( ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['status'] ) ) { throw new RuntimeException( 'رکورد نامعتبر سفارش از مبدأ دریافت شد.' ); }
			$mapper = new Company_Order_Sync_Order_Mapper();
			self::$ingesting = true;
			try {
				$result = $mapper->upsert( $state['store'], $payload, 'rebuild-' . $state['run'], false );
				if ( is_wp_error( $result ) ) { throw new RuntimeException( 'سفارش ' . absint( $payload['id'] ?? 0 ) . ': ' . $result->get_error_message() ); }
			} finally { self::$ingesting = false; }
			array_shift( $state['batch'] );
			$state['processed'] = absint( $state['processed'] ?? 0 ) + 1;
			$state['message'] = sprintf( '%s: %d از %d سفارش — بخش %d از %d', 'verify' === $state['phase'] ? 'بازخوانی نهایی وضعیت‌ها' : 'دریافت تازه', $state['processed'], $state['total'], $state['page'], $state['total_pages'] );
			return $state;
		}
		unset( $state['batch'] );
		if ( $state['page'] >= $state['total_pages'] ) {
			if ( 'import' === $state['phase'] ) { $state['phase']='verify'; $state['page']=1; $state['to']=current_time( 'mysql' ); $state['processed']=0; }
			else { $state['phase']='completed'; $state['message']='بازسازی و بازخوانی نهایی مبدأ کامل شد. عملیات پنل دوباره فعال است.'; }
		} else { ++$state['page']; }
		return $state;
	}
}
