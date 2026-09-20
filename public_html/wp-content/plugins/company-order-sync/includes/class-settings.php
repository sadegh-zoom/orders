<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Company_Order_Sync_Settings {

	const OPTION = 'company_order_sync_settings';
	const SOURCE_MIN_DATE_OPTION = 'company_order_sync_source_min_date';
	const CENTRAL_MIN_DATES_OPTION = 'company_order_sync_central_min_dates';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_company_order_sync_live_status', array( $this, 'ajax_live_status' ) );
	}

	public function ajax_live_status() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message'=>'دسترسی غیرمجاز.' ), 403 );
		}
		check_ajax_referer( 'company_order_sync_live_status', 'nonce' );
		if ( 'central' !== self::mode() ) {
			wp_send_json_error( array( 'message'=>'نمایش زنده فقط در حالت Central فعال است.' ), 409 );
		}

		$progress    = Company_Order_Sync_Queue::pending_central_status_progress( false );
		$repair      = get_option( Company_Order_Sync_Status_Repair::STATE_OPTION, array() );
		$maintenance = get_option( Company_Order_Sync_Retention_Maintenance::STATE_OPTION, array() );
		wp_send_json_success(
			array(
				'pending_count'    => absint( $progress['current_count'] ?? 0 ),
				'peak_count'       => absint( $progress['peak_count'] ?? 0 ),
				'confirmed_count'  => absint( $progress['confirmed_count'] ?? 0 ),
				'percent'          => absint( $progress['percent'] ?? 0 ),
				'started_at'       => sanitize_text_field( $progress['started_at'] ?? '' ),
				'last_change_at'   => sanitize_text_field( $progress['last_change_at'] ?? '' ),
				'completed_at'     => sanitize_text_field( $progress['completed_at'] ?? '' ),
				'repair_status'    => sanitize_key( is_array( $repair ) ? ( $repair['status'] ?? '' ) : '' ),
				'repair_message'   => sanitize_text_field( is_array( $repair ) ? ( $repair['message'] ?? '' ) : '' ),
				'repair_checked'   => absint( is_array( $repair ) ? ( $repair['checked'] ?? 0 ) : 0 ),
				'repair_total'     => absint( is_array( $repair ) ? ( $repair['source_total'] ?? 0 ) : 0 ),
				'repair_matched'   => absint( is_array( $repair ) ? ( $repair['matched'] ?? 0 ) : 0 ),
				'repair_pending_protected' => absint( is_array( $repair ) ? ( $repair['pending_protected'] ?? 0 ) : 0 ),
				'repair_stale_skipped' => absint( is_array( $repair ) ? ( $repair['stale_skipped'] ?? 0 ) : 0 ),
				'repair_corrected' => absint( is_array( $repair ) ? ( $repair['corrected'] ?? 0 ) : 0 ),
				'repair_created'   => absint( is_array( $repair ) ? ( $repair['created'] ?? 0 ) : 0 ),
				'repair_mode'      => sanitize_key( is_array( $repair ) ? ( $repair['mode'] ?? 'repair' ) : 'repair' ),
				'repair_revision_rebased' => absint( is_array( $repair ) ? ( $repair['revision_rebased'] ?? 0 ) : 0 ),
				'repair_central_reasserted' => absint( is_array( $repair ) ? ( $repair['central_reasserted'] ?? 0 ) : 0 ),
				'repair_remaining_delta' => absint( is_array( $repair ) ? ( $repair['remaining_status_delta'] ?? 0 ) : 0 ),
				'repair_failed'    => is_array( $repair ) ? count( (array) ( $repair['failed_order_ids'] ?? array() ) ) : 0,
				'maintenance_message' => sanitize_text_field( is_array( $maintenance ) ? ( $maintenance['message'] ?? '' ) : '' ),
				'checked_at'       => current_time( 'mysql' ),
			)
		);
	}

	public static function defaults() {
		return array(
			'mode'                    => 'store',
			'store_id'                => 'site1',
			'central_url'             => '',
			'store_outbound_secret'   => '',
			'store_inbound_secret'    => '',
			'signature_ttl'           => 300,
			'retention_days'           => 45,
			'auto_status_repair'      => '0',
			'auto_status_repair_days' => 7,
			'central_stores'          => array(
				'site1' => self::store_defaults( 'site1', 'فروشگاه ۱' ),
				'site2' => self::store_defaults( 'site2', 'فروشگاه ۲' ),
			),
		);
	}

	private static function store_defaults( $store_id, $label ) {
		return array(
			'enabled'         => '0',
			'label'           => $label,
			'url'             => '',
			'inbound_secret'  => '',
			'outbound_secret' => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$data  = wp_parse_args( $saved, self::defaults() );

		$data['central_stores'] = isset( $saved['central_stores'] ) && is_array( $saved['central_stores'] )
			? array_replace_recursive( self::defaults()['central_stores'], $saved['central_stores'] )
			: self::defaults()['central_stores'];

		return $data;
	}

	public static function get( $key, $default = null ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public static function mode() {
		return self::get( 'mode', 'store' );
	}

	public static function central_store( $store_id ) {
		$stores = self::get( 'central_stores', array() );
		return isset( $stores[ $store_id ] ) && is_array( $stores[ $store_id ] ) ? $stores[ $store_id ] : null;
	}

	public static function enabled_central_stores() {
		return array_filter(
			self::get( 'central_stores', array() ),
			static function( $store ) {
				return is_array( $store ) && ! empty( $store['enabled'] );
			}
		);
	}

	public static function auto_status_repair_enabled() {
		// Automatic full-data maintenance supersedes the legacy status-only job.
		return false;
	}

	public static function auto_status_repair_days() {
		return min( 7, self::retention_days() );
	}

	public static function retention_days() {
		return min( 365, max( 1, absint( self::get( 'retention_days', 45 ) ) ) );
	}

	public static function retention_start_date( $days = 0 ) {
		$days = $days ? absint( $days ) : self::retention_days();
		$days = min( self::retention_days(), max( 1, $days ) );
		return wp_date( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
	}

	public static function source_min_date() {
		return self::sanitize_iso_date( get_option( self::SOURCE_MIN_DATE_OPTION, '' ) );
	}

	public static function set_source_min_date( $date ) {
		$date = self::sanitize_iso_date( $date );
		if ( $date ) {
			update_option( self::SOURCE_MIN_DATE_OPTION, $date, false );
		}
		return $date;
	}

	public static function central_min_date( $store_id ) {
		$dates = get_option( self::CENTRAL_MIN_DATES_OPTION, array() );
		$date  = self::sanitize_iso_date( is_array( $dates ) ? ( $dates[ sanitize_key( $store_id ) ] ?? '' ) : '' );
		if ( $date ) {
			return $date;
		}
		$state = get_option( 'company_order_sync_snapshot_state', array() );
		if ( is_array( $state ) && sanitize_key( $state['store_id'] ?? '' ) === sanitize_key( $store_id ) ) {
			$date = self::sanitize_iso_date( $state['date_from'] ?? '' );
			if ( $date ) {
				self::set_central_min_date( $store_id, $date );
			}
		}
		return $date;
	}

	public static function set_central_min_date( $store_id, $date ) {
		$store_id = sanitize_key( $store_id );
		$date     = self::sanitize_iso_date( $date );
		if ( ! $store_id || ! $date ) {
			return '';
		}
		$dates = get_option( self::CENTRAL_MIN_DATES_OPTION, array() );
		$dates = is_array( $dates ) ? $dates : array();
		$dates[ $store_id ] = $date;
		update_option( self::CENTRAL_MIN_DATES_OPTION, $dates, false );
		return $date;
	}

	public static function order_is_in_scope( WC_Order $order, $date = '' ) {
		$date = self::sanitize_iso_date( $date ?: self::source_min_date() );
		if ( ! $date ) {
			return true;
		}
		$created = $order->get_date_created();
		if ( ! $created instanceof WC_DateTime ) {
			return false;
		}
		try {
			$minimum = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
			return $created->getTimestamp() >= $minimum->getTimestamp();
		} catch ( Exception $error ) {
			return false;
		}
	}

	public static function payload_is_in_scope( $store_id, array $payload ) {
		$date = self::central_min_date( $store_id );
		if ( ! $date ) {
			return true;
		}
		$created = isset( $payload['date_created_gmt'] ) ? strtotime( (string) $payload['date_created_gmt'] ) : false;
		if ( false === $created ) {
			return false;
		}
		try {
			$minimum = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
			return $created >= $minimum->getTimestamp();
		} catch ( Exception $error ) {
			return false;
		}
	}

	private static function sanitize_iso_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$parts = array_map( 'absint', explode( '-', $value ) );
		return 3 === count( $parts ) && checkdate( $parts[1], $parts[2], $parts[0] ) ? $value : '';
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			'همگام‌سازی سفارشات شرکت',
			'همگام‌سازی شرکت',
			'manage_woocommerce',
			'company-order-sync',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'company_order_sync',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = self::all();
		$out   = self::defaults();

		$out['mode']                  = isset( $input['mode'] ) && in_array( $input['mode'], array( 'central', 'store' ), true ) ? $input['mode'] : 'store';
		$out['store_id']              = isset( $input['store_id'] ) ? sanitize_key( $input['store_id'] ) : 'site1';
		$out['central_url']           = isset( $input['central_url'] ) ? untrailingslashit( esc_url_raw( $input['central_url'] ) ) : '';
		$out['signature_ttl']         = isset( $input['signature_ttl'] ) ? min( 900, max( 60, absint( $input['signature_ttl'] ) ) ) : 300;
		$out['retention_days']         = isset( $input['retention_days'] ) ? min( 365, max( 1, absint( $input['retention_days'] ) ) ) : 45;
		$out['auto_status_repair']    = '0';
		$out['auto_status_repair_days'] = 7;
		$out['store_outbound_secret'] = $this->secret_or_existing( $input, 'store_outbound_secret', $old );
		$out['store_inbound_secret']  = $this->secret_or_existing( $input, 'store_inbound_secret', $old );

		foreach ( array( 'site1', 'site2' ) as $store_id ) {
			$source   = isset( $input['central_stores'][ $store_id ] ) && is_array( $input['central_stores'][ $store_id ] ) ? $input['central_stores'][ $store_id ] : array();
			$old_store = isset( $old['central_stores'][ $store_id ] ) ? $old['central_stores'][ $store_id ] : array();
			$out['central_stores'][ $store_id ] = array(
				'enabled'         => empty( $source['enabled'] ) ? '0' : '1',
				'label'           => isset( $source['label'] ) ? sanitize_text_field( $source['label'] ) : $store_id,
				'url'             => isset( $source['url'] ) ? untrailingslashit( esc_url_raw( $source['url'] ) ) : '',
				'inbound_secret'  => $this->nested_secret_or_existing( $source, 'inbound_secret', $old_store ),
				'outbound_secret' => $this->nested_secret_or_existing( $source, 'outbound_secret', $old_store ),
			);
		}

		return $out;
	}

	private function secret_or_existing( array $input, $key, array $old ) {
		if ( ! empty( $input[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $input[ $key ] ) );
		}

		return isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
	}

	private function nested_secret_or_existing( array $input, $key, array $old ) {
		if ( ! empty( $input[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $input[ $key ] ) );
		}

		return isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'company-order-sync' ) );
		}

		$settings = self::all();
		?>
		<div class="wrap">
			<h1>همگام‌سازی سفارشات شرکت</h1>
			<p>همین افزونه را روی Central و فروشگاه‌ها نصب کنید و Mode هر نصب را مشخص کنید.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'company_order_sync' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="cos-mode">Mode</label></th>
						<td><select id="cos-mode" name="<?php echo esc_attr( self::OPTION ); ?>[mode]">
							<option value="store" <?php selected( $settings['mode'], 'store' ); ?>>Store Connector</option>
							<option value="central" <?php selected( $settings['mode'], 'central' ); ?>>Central</option>
						</select></td>
					</tr>
					<tr><th colspan="2"><h2>تنظیمات Store Connector</h2></th></tr>
					<?php $this->text_row( 'شناسه فروشگاه', 'store_id', $settings['store_id'], 'site1' ); ?>
					<?php $this->text_row( 'آدرس Central', 'central_url', $settings['central_url'], 'https://central.example.com', 'url' ); ?>
					<?php $this->secret_row( 'Secret ارسال Store → Central', 'store_outbound_secret' ); ?>
					<?php $this->secret_row( 'Secret دریافت Central → Store', 'store_inbound_secret' ); ?>
					<tr><th><label for="cos-ttl">اعتبار Timestamp</label></th><td><input id="cos-ttl" type="number" min="60" max="900" name="<?php echo esc_attr( self::OPTION ); ?>[signature_ttl]" value="<?php echo esc_attr( $settings['signature_ttl'] ); ?>"> ثانیه</td></tr>
					<tr><th colspan="2"><h2>تنظیمات Central</h2></th></tr>
					<tr>
						<th><label for="cos-retention-days">بازه همگام‌سازی و نگهداری</label></th>
						<td>
							<input id="cos-retention-days" type="number" min="1" max="365" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( self::retention_days() ); ?>"> روز اخیر
							<p class="description">پیش‌فرض ۴۵ روز است. کل این بازه هفته‌ای دو بار و ۷ روز اخیر هر ۱۵ دقیقه با سایت‌های مبدأ به‌روزرسانی می‌شود. سفارش‌های قدیمی‌تر فقط از Central حذف می‌شوند.</p>
						</td>
					</tr>
				</table>

				<h2>Registry فروشگاه‌ها در Central</h2>
				<p>Inbound Secret برای Store → Central و Outbound Secret برای Central → Store است.</p>
				<table class="widefat striped" style="max-width:1200px">
					<thead><tr><th>فعال</th><th>ID</th><th>عنوان</th><th>URL</th><th>Inbound Secret</th><th>Outbound Secret</th></tr></thead>
					<tbody>
					<?php foreach ( $settings['central_stores'] as $store_id => $store ) : ?>
						<tr>
							<td><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[central_stores][<?php echo esc_attr( $store_id ); ?>][enabled]" value="1" <?php checked( ! empty( $store['enabled'] ) ); ?>></td>
							<td><code><?php echo esc_html( $store_id ); ?></code></td>
							<td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[central_stores][<?php echo esc_attr( $store_id ); ?>][label]" value="<?php echo esc_attr( $store['label'] ); ?>"></td>
							<td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[central_stores][<?php echo esc_attr( $store_id ); ?>][url]" value="<?php echo esc_attr( $store['url'] ); ?>"></td>
							<td><input type="password" autocomplete="new-password" name="<?php echo esc_attr( self::OPTION ); ?>[central_stores][<?php echo esc_attr( $store_id ); ?>][inbound_secret]" placeholder="برای حفظ مقدار، خالی بگذارید"></td>
							<td><input type="password" autocomplete="new-password" name="<?php echo esc_attr( self::OPTION ); ?>[central_stores][<?php echo esc_attr( $store_id ); ?>][outbound_secret]" placeholder="برای حفظ مقدار، خالی بگذارید"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( 'central' === $settings['mode'] ) : ?>
				<hr style="margin:30px 0">
				<h2>بازبینی و تکمیل سفارش‌های تاریخی</h2>
				<p>مرز شروع به‌صورت خودکار از تعداد روز نگهداری محاسبه می‌شود. نیازی به انتخاب تاریخ نیست و سفارش قدیمی‌تر از این مرز همگام نخواهد شد.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:18px">
					<input type="hidden" name="action" value="company_order_sync_snapshot">
					<?php wp_nonce_field( 'company_order_sync_snapshot', '_cos_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="cos-snapshot-store">فروشگاه مبدأ</label></th><td><select id="cos-snapshot-store" name="store_id"><?php foreach ( self::enabled_central_stores() as $store_id=>$store ) : ?><option value="<?php echo esc_attr( $store_id ); ?>"><?php echo esc_html( $store['label'] ?: $store_id ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th>بازه خودکار</th><td><strong><?php echo esc_html( number_format_i18n( self::retention_days() ) ); ?> روز اخیر</strong> — شروع فعلی: <code><?php echo esc_html( self::retention_start_date() ); ?></code></td></tr>
						<tr><th>نقش‌ها و کاربران</th><td><label><input type="checkbox" name="sync_users" value="1" checked> نقش‌های منتخب، Capabilityها و کاربرانشان نیز همگام شوند</label><p class="description">رمز عبور و Session کاربران منتقل نمی‌شود؛ کاربران جدید باید در Central رمز مستقل تعیین کنند.</p></td></tr>
					</table>
					<?php submit_button( 'شروع بازبینی و تکمیل', 'primary', 'submit', false ); ?>
				</form>
				<?php $snapshot_state = get_option( Company_Order_Sync_Snapshot_Sync::STATE_OPTION, array() ); ?>
				<?php if ( $snapshot_state ) : ?>
					<?php $worker_id = absint( $snapshot_state['worker_action_id'] ?? 0 ); ?>
					<div class="notice notice-info inline" style="max-width:900px;margin-top:15px"><p><strong>وضعیت آخرین اجرا:</strong> <?php echo esc_html( $snapshot_state['message'] ?? $snapshot_state['status'] ?? '—' ); ?> — بازه مجاز از: <?php echo esc_html( $snapshot_state['date_from'] ?? '—' ); ?> — بخش: <?php echo esc_html( absint( $snapshot_state['page'] ?? 0 ) . ' از ' . absint( $snapshot_state['total_pages'] ?? 0 ) ); ?> — Action ID: <?php echo esc_html( $worker_id ? (string) $worker_id : 'WP-Cron' ); ?><br>ثبت موفق: <?php echo esc_html( number_format_i18n( absint( $snapshot_state['processed'] ?? 0 ) ) ); ?> از <?php echo esc_html( number_format_i18n( absint( $snapshot_state['source_total'] ?? 0 ) ) ); ?> — ایجادشده: <?php echo esc_html( number_format_i18n( absint( $snapshot_state['created'] ?? 0 ) ) ); ?> — وضعیت اصلاح‌شده: <?php echo esc_html( number_format_i18n( absint( $snapshot_state['status_corrected'] ?? 0 ) ) ); ?> — ناموفق: <?php echo esc_html( number_format_i18n( absint( $snapshot_state['failed_count'] ?? 0 ) ) ); ?> — کاربر: <?php echo esc_html( number_format_i18n( absint( $snapshot_state['users'] ?? 0 ) ) ); ?> — آخرین بروزرسانی: <?php echo esc_html( $snapshot_state['updated_at'] ?? '—' ); ?></p><?php if ( ! empty( $snapshot_state['failed_order_ids'] ) ) : ?><p><strong>شناسه سفارش‌های ناموفق:</strong> <?php echo esc_html( implode( '، ', array_map( 'absint', (array) $snapshot_state['failed_order_ids'] ) ) ); ?></p><?php endif; ?></div>
					<?php if ( in_array( $snapshot_state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><input type="hidden" name="action" value="company_order_sync_snapshot_stop"><?php wp_nonce_field( 'company_order_sync_snapshot_stop', '_cos_nonce' ); ?><?php submit_button( 'توقف همگام‌سازی', 'secondary', 'submit', false ); ?></form><?php endif; ?>
				<?php endif; ?>

				<hr style="margin:30px 0">
				<h2>مقایسه و ترمیم سریع وضعیت‌ها</h2>
				<p>این ابزار سفارش‌های داخل بازه نگهداری را با مبدأ مقایسه می‌کند؛ وضعیت متفاوت اصلاح و سفارش مفقود داخل بازه با اطلاعات کامل دریافت می‌شود.</p>
				<?php
				$maintenance_state = get_option( Company_Order_Sync_Retention_Maintenance::STATE_OPTION, array() );
				?>
				<p><strong>اجرای خودکار:</strong> فعال — اطلاعات کامل ۷ روز اخیر هر ۱۵ دقیقه و کل <?php echo esc_html( number_format_i18n( self::retention_days() ) ); ?> روز، هفته‌ای دو بار بازبینی می‌شود. اگر صف تغییر وضعیت Central خالی نباشد، اجرای خودکار Run تازه نمی‌سازد؛ Run موجود در همان مرحله می‌ایستد و بعد از صفر شدن صف از همان نقطه ادامه می‌دهد.</p>
				<?php
				$pending_progress     = Company_Order_Sync_Queue::pending_central_status_progress( false );
				$pending_status_count = absint( $pending_progress['current_count'] ?? 0 );
				$pending_peak_count   = max( $pending_status_count, absint( $pending_progress['peak_count'] ?? 0 ) );
				$pending_done_count   = absint( $pending_progress['confirmed_count'] ?? 0 );
				$pending_percent      = absint( $pending_progress['percent'] ?? 0 );
				$live_nonce           = wp_create_nonce( 'company_order_sync_live_status' );
				$repair_state          = get_option( Company_Order_Sync_Status_Repair::STATE_OPTION, array() );
				$repair_run_active     = is_array( $repair_state ) && in_array( $repair_state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending' ), true );
				?>
				<div id="cos-live-pending" class="notice <?php echo $pending_status_count ? 'notice-warning' : 'notice-success'; ?> inline" style="max-width:900px;margin:10px 0;padding-bottom:10px" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $live_nonce ); ?>">
					<p><strong>وضعیت زنده صف Central → مبدأ:</strong> <span id="cos-live-pending-count"><?php echo esc_html( number_format_i18n( $pending_status_count ) ); ?></span> سفارش در انتظار تأیید مبدأ.</p>
					<div style="height:10px;max-width:620px;background:#dcdcde;border-radius:8px;overflow:hidden" aria-hidden="true"><div id="cos-live-pending-bar" style="height:100%;width:<?php echo esc_attr( min( 100, $pending_percent ) ); ?>%;background:#2271b1;transition:width .25s ease"></div></div>
					<p class="description" style="margin-top:7px">پیشرفت همین نوبت: <strong><span id="cos-live-pending-done"><?php echo esc_html( number_format_i18n( $pending_done_count ) ); ?></span></strong> تأیید از بیشترین <strong><span id="cos-live-pending-peak"><?php echo esc_html( number_format_i18n( $pending_peak_count ) ); ?></span></strong> مورد مشاهده‌شده — <span id="cos-live-pending-percent"><?php echo esc_html( number_format_i18n( $pending_percent ) ); ?></span>٪. <span id="cos-live-pending-checked">در حال بروزرسانی زنده…</span></p>
					<p id="cos-live-pending-message" style="margin-bottom:0"><?php echo $pending_status_count ? esc_html( 'Worker هر دقیقه Pendingها را دوباره ارسال می‌کند. Repair و بازبینی ۱۵ دقیقه‌ای این صف را از نو ایجاد نمی‌کنند و تا صفر شدن آن از همان Run/مرحله قبلی ادامه خواهند داد.' ) : esc_html( 'همه تغییر وضعیت‌های Central توسط مبدأ تأیید شده‌اند. Runهای متوقف‌شده می‌توانند از همان نقطه ادامه پیدا کنند.' ); ?></p>
				</div>
				<?php if ( ! empty( $maintenance_state ) ) : ?><p class="description">آخرین وضعیت نگهداری: <?php echo esc_html( $maintenance_state['message'] ?? '—' ); ?> — آخرین بروزرسانی: <?php echo esc_html( $maintenance_state['updated_at'] ?? '—' ); ?> — حذف‌شده از Central: <?php echo esc_html( number_format_i18n( absint( $maintenance_state['deleted'] ?? 0 ) ) ); ?></p><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:18px">
					<input type="hidden" name="action" value="company_order_sync_status_repair">
					<?php wp_nonce_field( 'company_order_sync_status_repair', '_cos_nonce' ); ?>
					<label for="cos-status-repair-store"><strong>فروشگاه:</strong></label>
					<select id="cos-status-repair-store" name="store_id"><?php foreach ( self::enabled_central_stores() as $store_id=>$store ) : ?><option value="<?php echo esc_attr( $store_id ); ?>"><?php echo esc_html( ( $store['label'] ?: $store_id ) . ' — از ' . ( self::central_min_date( $store_id ) ?: 'تعیین نشده' ) ); ?></option><?php endforeach; ?></select>
					<input id="cos-status-repair-submit" type="submit" class="button button-secondary" value="مقایسه و ترمیم وضعیت‌ها" <?php disabled( $pending_status_count > 0 || $repair_run_active ); ?>>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;background:#f6f7f7;border:1px solid #c3c4c7;border-right:4px solid #2271b1;padding:18px;margin-top:12px">
					<input type="hidden" name="action" value="company_order_sync_status_converge">
					<?php wp_nonce_field( 'company_order_sync_status_converge', '_cos_nonce' ); ?>
					<p style="margin-top:0"><strong>همسان‌سازی نهایی (صفر کردن اختلاف وضعیت‌ها)</strong></p>
					<p class="description">این مرحله فقط وقتی صف Central → مبدأ صفر است اجرا می‌شود. برای رکوردهای اختلاف‌دار/Revision قدیمی، وضعیت همان لحظه را مستقیم از مبدأ دوباره می‌خواند و Central را با آن همسان می‌کند. اگر یک تغییر صریح Central از وضعیت فعلی مبدأ جدیدتر باشد، ابتدا همان تغییر دوباره به مبدأ فرستاده می‌شود و این Run از همان بخش ادامه می‌دهد.</p>
					<label for="cos-status-converge-store"><strong>فروشگاه:</strong></label>
					<select id="cos-status-converge-store" name="store_id"><?php foreach ( self::enabled_central_stores() as $store_id=>$store ) : ?><option value="<?php echo esc_attr( $store_id ); ?>"><?php echo esc_html( ( $store['label'] ?: $store_id ) . ' — از ' . ( self::central_min_date( $store_id ) ?: 'تعیین نشده' ) ); ?></option><?php endforeach; ?></select>
					<input id="cos-status-converge-submit" type="submit" class="button button-primary" value="شروع همسان‌سازی نهایی" <?php disabled( $pending_status_count > 0 || $repair_run_active ); ?>>
				</form>
				<?php /* $repair_state was loaded above so both start buttons can respect the active Run. */ ?>
				<?php if ( $repair_state ) : ?>
					<?php $repair_has_split_counters = array_key_exists( 'pending_protected', $repair_state ) || array_key_exists( 'stale_skipped', $repair_state ); ?>
					<?php $repair_is_final = 'final' === sanitize_key( $repair_state['mode'] ?? '' ); ?>
					<div class="notice notice-info inline" style="max-width:900px;margin-top:15px"><p><strong>وضعیت <span id="cos-live-repair-kind"><?php echo $repair_is_final ? 'همسان‌سازی نهایی' : 'Repair'; ?></span>:</strong> <span id="cos-live-repair-message"><?php echo esc_html( $repair_state['message'] ?? $repair_state['status'] ?? '—' ); ?></span> — نوع اجرا: <?php echo esc_html( 'auto' === ( $repair_state['trigger'] ?? '' ) ? 'خودکار' : 'دستی' ); ?> — بازه: <?php echo esc_html( ( $repair_state['window_from'] ?? $repair_state['date_from'] ?? '—' ) . ' تا ' . ( $repair_state['date_to'] ?? '—' ) ); ?> — بررسی: <span id="cos-live-repair-checked"><?php echo esc_html( number_format_i18n( absint( $repair_state['checked'] ?? 0 ) ) ); ?></span> از <span id="cos-live-repair-total"><?php echo esc_html( number_format_i18n( absint( $repair_state['source_total'] ?? 0 ) ) ); ?></span> — یکسان: <span id="cos-live-repair-matched"><?php echo esc_html( number_format_i18n( absint( $repair_state['matched'] ?? 0 ) ) ); ?></span> — <strong>تغییر Central واقعاً محافظت‌شده:</strong> <span id="cos-live-repair-pending-protected"><?php echo esc_html( number_format_i18n( absint( $repair_state['pending_protected'] ?? 0 ) ) ); ?></span> — Snapshot قدیمی نادیده‌گرفته‌شده: <span id="cos-live-repair-stale"><?php echo esc_html( number_format_i18n( absint( $repair_state['stale_skipped'] ?? 0 ) ) ); ?></span> — اصلاح وضعیت: <span id="cos-live-repair-corrected"><?php echo esc_html( number_format_i18n( absint( $repair_state['corrected'] ?? 0 ) ) ); ?></span> — ایجاد مفقود: <span id="cos-live-repair-created"><?php echo esc_html( number_format_i18n( absint( $repair_state['created'] ?? 0 ) ) ); ?></span> — ناموفق: <span id="cos-live-repair-failed"><?php echo esc_html( number_format_i18n( count( (array) ( $repair_state['failed_order_ids'] ?? array() ) ) ) ); ?></span><?php if ( $repair_is_final ) : ?> — Revision بازنشانی‌شده: <span id="cos-live-repair-rebased"><?php echo esc_html( number_format_i18n( absint( $repair_state['revision_rebased'] ?? 0 ) ) ); ?></span> — تغییر Central باز-ارسال‌شده: <span id="cos-live-repair-reasserted"><?php echo esc_html( number_format_i18n( absint( $repair_state['central_reasserted'] ?? 0 ) ) ); ?></span> — اختلاف شمارشی باقی‌مانده: <span id="cos-live-repair-remaining"><?php echo esc_html( number_format_i18n( absint( $repair_state['remaining_status_delta'] ?? 0 ) ) ); ?></span><?php endif; ?><?php if ( ! $repair_has_split_counters && absint( $repair_state['protected'] ?? 0 ) ) : ?> — <em>عدد ترکیبی نسخه قبلی: <?php echo esc_html( number_format_i18n( absint( $repair_state['protected'] ?? 0 ) ) ); ?></em><?php endif; ?></p><p class="description" style="margin-bottom:0"><?php echo $repair_is_final ? esc_html( 'در همسان‌سازی نهایی، Snapshot قدیمی مستقیماً اعمال نمی‌شود؛ رکورد اختلاف‌دار یک بار دیگر به‌صورت Point-read از مبدأ خوانده می‌شود. اگر تغییر صریح Central جدیدتر باشد اول به مبدأ می‌رسد، و در غیر این صورت وضعیت زنده مبدأ مرجع نهایی Central است.' ) : esc_html( '«تغییر Central واقعاً محافظت‌شده» فقط وقتی زیاد می‌شود که هنگام Repair یک تغییر واقعی اپراتور هنوز منتظر تأیید مبدأ باشد. «Snapshot قدیمی» Pending نیست و فقط برای جلوگیری از بازگرداندن Revision جدیدتر نادیده گرفته می‌شود.' ); ?></p></div>
					<?php if ( ! empty( $repair_state['source_status_counts'] ) || ! empty( $repair_state['central_status_counts'] ) ) : ?>
						<table class="widefat striped" style="max-width:760px;margin-top:10px"><thead><tr><th>وضعیت</th><th>مبدأ داخل بازه</th><th>Central داخل بازه</th><th>اختلاف پس از اجرا</th></tr></thead><tbody>
						<?php $repair_statuses = array_values( array_unique( array_merge( array_keys( (array) ( $repair_state['source_status_counts'] ?? array() ) ), array_keys( (array) ( $repair_state['central_status_counts'] ?? array() ) ) ) ) ); ?>
						<?php foreach ( $repair_statuses as $slug ) : $source_count = absint( $repair_state['source_status_counts'][ $slug ] ?? 0 ); $central_count = absint( $repair_state['central_status_counts'][ $slug ] ?? 0 ); ?><tr><td><?php echo esc_html( wc_get_order_status_name( $slug ) ?: $slug ); ?></td><td><?php echo esc_html( number_format_i18n( $source_count ) ); ?></td><td><?php echo esc_html( number_format_i18n( $central_count ) ); ?></td><td><?php echo esc_html( number_format_i18n( abs( $source_count - $central_count ) ) ); ?></td></tr><?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
					<?php $this->render_repair_differences( (array) ( $repair_state['differences'] ?? array() ) ); ?>
					<?php if ( in_array( $repair_state['status'] ?? '', array( 'queued', 'running', 'retrying', 'waiting_pending' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><input type="hidden" name="action" value="company_order_sync_status_repair_stop"><?php wp_nonce_field( 'company_order_sync_status_repair_stop', '_cos_nonce' ); ?><?php submit_button( 'توقف Repair', 'secondary', 'submit', false ); ?></form><?php endif; ?>
				<?php endif; ?>

				<hr style="margin:30px 0">
				<h2>Audit وضعیت‌ها — فقط خواندنی</h2>
				<p>این ابزار هیچ وضعیت یا سفارش را تغییر نمی‌دهد. وضعیت فعلی مبدأ و Central، Pendingها، Revisionها و تاریخچه رفت‌وبرگشت وضعیت را بررسی می‌کند تا سفارش‌های مشکوک نسخه‌های قبلی مشخص شوند.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:18px">
					<input type="hidden" name="action" value="company_order_sync_status_audit">
					<?php wp_nonce_field( 'company_order_sync_status_audit', '_cos_nonce' ); ?>
					<label for="cos-status-audit-store"><strong>فروشگاه:</strong></label>
					<select id="cos-status-audit-store" name="store_id"><?php foreach ( self::enabled_central_stores() as $store_id=>$store ) : ?><option value="<?php echo esc_attr( $store_id ); ?>"><?php echo esc_html( $store['label'] ?: $store_id ); ?></option><?php endforeach; ?></select>
					<?php submit_button( 'اجرای Audit بدون تغییر', 'primary', 'submit', false ); ?>
				</form>
				<?php $audit_state = get_option( Company_Order_Sync_Status_Audit::STATE_OPTION, array() ); ?>
				<?php if ( $audit_state ) : ?>
					<div class="notice notice-info inline" style="max-width:1000px;margin-top:15px"><p>
						<strong>وضعیت Audit:</strong> <?php echo esc_html( $audit_state['message'] ?? $audit_state['status'] ?? '—' ); ?>
						— بازه: <?php echo esc_html( ( $audit_state['date_from'] ?? '—' ) . ' تا ' . ( $audit_state['date_to'] ?? '—' ) ); ?>
						— بررسی: <?php echo esc_html( number_format_i18n( absint( $audit_state['checked'] ?? 0 ) ) . ' از ' . number_format_i18n( absint( $audit_state['source_total'] ?? 0 ) ) ); ?>
						— بدون ایراد: <?php echo esc_html( number_format_i18n( absint( $audit_state['matched'] ?? 0 ) ) ); ?>
						— در انتظار تأیید مبدأ: <?php echo esc_html( number_format_i18n( absint( $audit_state['pending_waiting'] ?? 0 ) ) ); ?>
						— مبدأ تأیید کرده ولی Pending پاک نشده: <?php echo esc_html( number_format_i18n( absint( $audit_state['pending_confirmed'] ?? 0 ) ) ); ?>
						— اختلاف بدون Pending: <?php echo esc_html( number_format_i18n( absint( $audit_state['status_mismatch'] ?? 0 ) ) ); ?>
						— مفقود در Central: <?php echo esc_html( number_format_i18n( absint( $audit_state['missing_in_central'] ?? 0 ) ) ); ?>
						— Revision مشکوک: <?php echo esc_html( number_format_i18n( absint( $audit_state['source_revision_behind'] ?? 0 ) ) ); ?>
						— رفت‌وبرگشت تاریخی: <?php echo esc_html( number_format_i18n( absint( $audit_state['history_oscillation'] ?? 0 ) ) ); ?>
					</p></div>
					<?php if ( ! empty( $audit_state['issues_truncated'] ) ) : ?><div class="notice notice-warning inline" style="max-width:1000px"><p>تعداد موارد زیاد است؛ فقط اولین <?php echo esc_html( number_format_i18n( Company_Order_Sync_Status_Audit::MAX_ISSUES ) ); ?> مورد در گزارش نگه‌داری شده است.</p></div><?php endif; ?>
					<?php $this->render_status_audit_issues( (array) ( $audit_state['issues'] ?? array() ) ); ?>
					<?php if ( in_array( $audit_state['status'] ?? '', array( 'queued', 'running', 'retrying' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><input type="hidden" name="action" value="company_order_sync_status_audit_stop"><?php wp_nonce_field( 'company_order_sync_status_audit_stop', '_cos_nonce' ); ?><?php submit_button( 'توقف Audit', 'secondary', 'submit', false ); ?></form><?php endif; ?>
				<?php endif; ?>
				<script>
				(function () {
					const box = document.getElementById('cos-live-pending');
					if (!box) return;
					const countEl = document.getElementById('cos-live-pending-count');
					const doneEl = document.getElementById('cos-live-pending-done');
					const peakEl = document.getElementById('cos-live-pending-peak');
					const percentEl = document.getElementById('cos-live-pending-percent');
					const checkedEl = document.getElementById('cos-live-pending-checked');
					const messageEl = document.getElementById('cos-live-pending-message');
					const barEl = document.getElementById('cos-live-pending-bar');
					const repairButton = document.getElementById('cos-status-repair-submit');
					const convergeButton = document.getElementById('cos-status-converge-submit');
					const repairMessage = document.getElementById('cos-live-repair-message');
					const repairChecked = document.getElementById('cos-live-repair-checked');
					const repairTotal = document.getElementById('cos-live-repair-total');
					const repairMatched = document.getElementById('cos-live-repair-matched');
					const repairPendingProtected = document.getElementById('cos-live-repair-pending-protected');
					const repairStale = document.getElementById('cos-live-repair-stale');
					const repairCorrected = document.getElementById('cos-live-repair-corrected');
					const repairCreated = document.getElementById('cos-live-repair-created');
					const repairFailed = document.getElementById('cos-live-repair-failed');
					const repairKind = document.getElementById('cos-live-repair-kind');
					const repairRebased = document.getElementById('cos-live-repair-rebased');
					const repairReasserted = document.getElementById('cos-live-repair-reasserted');
					const repairRemaining = document.getElementById('cos-live-repair-remaining');
					const numberFormat = new Intl.NumberFormat('fa-IR');
					let timer = null;

					function schedule() {
						window.clearTimeout(timer);
						timer = window.setTimeout(poll, 5000);
					}

					async function poll() {
						try {
							const body = new URLSearchParams();
							body.set('action', 'company_order_sync_live_status');
							body.set('nonce', box.dataset.nonce || '');
							const response = await fetch(box.dataset.url, {
								method: 'POST',
								credentials: 'same-origin',
								headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
								body: body.toString()
							});
							const payload = await response.json();
							if (!payload || !payload.success || !payload.data) throw new Error('invalid-live-response');
							const data = payload.data;
							const count = Number(data.pending_count || 0);
							const peak = Number(data.peak_count || 0);
							const done = Number(data.confirmed_count || 0);
							const percent = Math.max(0, Math.min(100, Number(data.percent || 0)));
							countEl.textContent = numberFormat.format(count);
							doneEl.textContent = numberFormat.format(done);
							peakEl.textContent = numberFormat.format(peak);
							percentEl.textContent = numberFormat.format(percent);
							barEl.style.width = percent + '%';
							checkedEl.textContent = 'آخرین بررسی: ' + (data.checked_at || '—');
							box.classList.toggle('notice-warning', count > 0);
							box.classList.toggle('notice-success', count === 0);
							const activeRun = ['queued', 'running', 'retrying', 'waiting_pending'].includes(String(data.repair_status || ''));
							if (repairButton) repairButton.disabled = count > 0 || activeRun;
							if (convergeButton) convergeButton.disabled = count > 0 || activeRun;
							if (repairMessage && data.repair_message) repairMessage.textContent = data.repair_message;
							if (repairChecked) repairChecked.textContent = numberFormat.format(Number(data.repair_checked || 0));
							if (repairTotal) repairTotal.textContent = numberFormat.format(Number(data.repair_total || 0));
							if (repairMatched) repairMatched.textContent = numberFormat.format(Number(data.repair_matched || 0));
							if (repairPendingProtected) repairPendingProtected.textContent = numberFormat.format(Number(data.repair_pending_protected || 0));
							if (repairStale) repairStale.textContent = numberFormat.format(Number(data.repair_stale_skipped || 0));
							if (repairCorrected) repairCorrected.textContent = numberFormat.format(Number(data.repair_corrected || 0));
							if (repairCreated) repairCreated.textContent = numberFormat.format(Number(data.repair_created || 0));
							if (repairFailed) repairFailed.textContent = numberFormat.format(Number(data.repair_failed || 0));
							if (repairKind) repairKind.textContent = data.repair_mode === 'final' ? 'همسان‌سازی نهایی' : 'Repair';
							if (repairRebased) repairRebased.textContent = numberFormat.format(Number(data.repair_revision_rebased || 0));
							if (repairReasserted) repairReasserted.textContent = numberFormat.format(Number(data.repair_central_reasserted || 0));
							if (repairRemaining) repairRemaining.textContent = numberFormat.format(Number(data.repair_remaining_delta || 0));
							messageEl.textContent = count > 0
								? 'صف در حال تخلیه است. Repair و اجرای خودکار ۱۵ دقیقه‌ای Run تازه‌ای از اول نمی‌سازند؛ فقط تغییر وضعیت جدید واقعی در Central می‌تواند این عدد را افزایش دهد.'
								: 'صف به صفر رسید. Runهای متوقف‌شده به‌صورت خودکار از همان مرحله قبلی ادامه داده می‌شوند.';
						} catch (error) {
							checkedEl.textContent = 'ارتباط زنده موقتاً برقرار نشد؛ تلاش مجدد انجام می‌شود.';
						} finally {
							schedule();
						}
					}

					window.setTimeout(poll, 1500);
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_repair_differences( array $groups ) {
		$groups = array_filter( $groups, 'is_array' );
		if ( ! $groups ) {
			return;
		}
		$count = 0;
		foreach ( $groups as $records ) {
			$count += count( $records );
		}
		?>
		<div style="max-width:900px;margin-top:14px;background:#fff;border:1px solid #dcdcde;padding:14px">
			<h3 style="margin-top:0">سفارش‌های دارای اختلاف در این اجرا (<?php echo esc_html( number_format_i18n( $count ) ); ?>)</h3>
			<p class="description">هر مورد به صفحه سفارش در مبدأ و Central لینک شده است. موارد «فقط در Central» طبیعتاً لینک مبدأ ندارند.</p>
			<?php foreach ( $groups as $status => $records ) : if ( ! $records ) { continue; } ?>
				<details style="margin:8px 0;border:1px solid #e2e4e7;border-radius:4px;padding:9px" open>
					<summary><strong><?php echo esc_html( wc_get_order_status_name( $status ) ?: $status ); ?></strong> — <?php echo esc_html( number_format_i18n( count( $records ) ) ); ?> سفارش</summary>
					<ul style="margin:10px 22px 0 0;list-style:disc">
					<?php foreach ( $records as $record ) :
						$type = sanitize_key( $record['type'] ?? '' );
						$type_label = 'status_corrected' === $type ? 'وضعیت اصلاح شد' : ( 'missing_in_central' === $type ? 'در Central مفقود بود و دریافت شد' : 'فقط در Central وجود دارد' );
						$number = sanitize_text_field( $record['source_number'] ?? ( $record['source_order_id'] ?? '—' ) );
						$before = sanitize_key( $record['central_status'] ?? '' );
						$after  = sanitize_key( $record['source_status'] ?? '' );
					?>
						<li style="margin:6px 0">
							<strong>#<?php echo esc_html( $number ); ?></strong> — <?php echo esc_html( $type_label ); ?>
							<?php if ( 'status_corrected' === $type && $before && $after ) : ?> (<?php echo esc_html( ( wc_get_order_status_name( $before ) ?: $before ) . ' ← ' . ( wc_get_order_status_name( $after ) ?: $after ) ); ?>)<?php endif; ?>
							<?php if ( ! empty( $record['source_url'] ) ) : ?> — <a href="<?php echo esc_url( $record['source_url'] ); ?>" target="_blank" rel="noopener">سفارش مبدأ</a><?php endif; ?>
							<?php if ( ! empty( $record['central_url'] ) ) : ?> — <a href="<?php echo esc_url( $record['central_url'] ); ?>" target="_blank" rel="noopener">سفارش Central</a><?php endif; ?>
						</li>
					<?php endforeach; ?>
					</ul>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_status_audit_issues( array $issues ) {
		$issues = array_values( array_filter( $issues, 'is_array' ) );
		if ( ! $issues ) {
			?>
			<div class="notice notice-success inline" style="max-width:1000px;margin-top:12px"><p>در آخرین Audit مورد مشکوکی ثبت نشده است.</p></div>
			<?php
			return;
		}

		$flag_labels = array(
			'pending_waiting'        => 'در انتظار تأیید مبدأ',
			'pending_confirmed'      => 'مبدأ تأیید کرده ولی Pending پاک نشده',
			'status_mismatch'        => 'اختلاف وضعیت بدون Pending',
			'missing_in_central'     => 'در Central مفقود است',
			'source_revision_behind' => 'Revision مبدأ عقب‌تر است',
			'history_oscillation'    => 'رفت‌وبرگشت تاریخی وضعیت',
		);
		$status_label = static function( $slug ) {
			$slug = sanitize_key( $slug );
			return $slug ? ( wc_get_order_status_name( $slug ) ?: $slug ) : '—';
		};
		?>
		<div style="max-width:1100px;margin-top:14px;background:#fff;border:1px solid #dcdcde;padding:14px">
			<h3 style="margin-top:0">موارد نیازمند بررسی (<?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?>)</h3>
			<p class="description">این گزارش فقط خواندنی است. «رفت‌وبرگشت تاریخی» یعنی در یادداشت‌های وضعیت، برگشت مستقیم A→B و سپس B→A پیدا شده است؛ این مورد برای بررسی انسانی علامت‌گذاری می‌شود و هیچ اصلاح خودکاری انجام نمی‌شود.</p>
			<div style="overflow:auto;margin-top:10px">
			<table class="widefat striped" style="min-width:1050px">
				<thead><tr>
					<th>سفارش</th><th>دلیل</th><th>مبدأ</th><th>Central</th><th>Pending</th><th>Revision</th><th>آخرین تغییر Central</th><th>تاریخچه</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $issues as $issue ) :
					$flags = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $issue['flags'] ?? array( $issue['type'] ?? '' ) ) ) ) ) );
					$flag_text = array();
					foreach ( $flags as $flag ) {
						$flag_text[] = $flag_labels[ $flag ] ?? $flag;
					}
					$number = sanitize_text_field( $issue['source_number'] ?? ( $issue['source_order_id'] ?? '—' ) );
					$central_change = is_array( $issue['central_last_change'] ?? null ) ? $issue['central_last_change'] : array();
					$source_change  = is_array( $issue['source_last_central_change'] ?? null ) ? $issue['source_last_central_change'] : array();
					$changed_by     = is_array( $issue['central_changed_by'] ?? null ) ? $issue['central_changed_by'] : array();
					$actor = sanitize_text_field( $central_change['actor'] ?? ( $changed_by['display_name'] ?? '' ) );
					$change_status = sanitize_key( $central_change['status'] ?? '' );
					$change_time = sanitize_text_field( $central_change['changed_at_gmt'] ?? '' );
					$source_note = sanitize_text_field( $source_change['note'] ?? '' );
					$source_change_time = sanitize_text_field( $source_change['changed_at_gmt'] ?? '' );
					$central_notes = array_values( array_filter( (array) ( $issue['central_recent_status_notes'] ?? array() ), 'is_array' ) );
					$source_notes  = array_values( array_filter( (array) ( $issue['source_recent_status_notes'] ?? array() ), 'is_array' ) );
				?>
				<tr>
					<td><strong>#<?php echo esc_html( $number ); ?></strong><br>
						<?php if ( ! empty( $issue['source_url'] ) ) : ?><a href="<?php echo esc_url( $issue['source_url'] ); ?>" target="_blank" rel="noopener">مبدأ</a><?php endif; ?>
						<?php if ( ! empty( $issue['central_url'] ) ) : ?><?php if ( ! empty( $issue['source_url'] ) ) : ?> | <?php endif; ?><a href="<?php echo esc_url( $issue['central_url'] ); ?>" target="_blank" rel="noopener">Central</a><?php endif; ?>
					</td>
					<td><?php echo esc_html( implode( '، ', $flag_text ) ); ?><?php if ( ! empty( $issue['central_sync_error'] ) ) : ?><br><small><?php echo esc_html( $issue['central_sync_error'] ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( $status_label( $issue['source_status'] ?? '' ) ); ?><br><small><?php echo esc_html( $issue['source_modified_gmt'] ?? '' ); ?></small></td>
					<td><?php echo esc_html( $status_label( $issue['central_status'] ?? '' ) ); ?><br><small><?php echo esc_html( sanitize_key( $issue['central_sync_status'] ?? '' ) ?: '—' ); ?></small></td>
					<td><?php echo esc_html( $status_label( $issue['pending_status'] ?? '' ) ); ?><?php if ( ! empty( $issue['pending_changed_at_gmt'] ) ) : ?><br><small><?php echo esc_html( $issue['pending_changed_at_gmt'] ); ?></small><?php endif; ?></td>
					<td><small>مبدأ: <?php echo esc_html( (string) ( $issue['source_revision'] ?? '—' ) ); ?><br>Central: <?php echo esc_html( (string) ( $issue['central_revision'] ?? '—' ) ); ?></small></td>
					<td>
						<?php if ( $actor || $change_status || $change_time ) : ?>
							<?php echo esc_html( $actor ?: 'Central' ); ?><?php if ( $change_status ) : ?> → <?php echo esc_html( $status_label( $change_status ) ); ?><?php endif; ?><?php if ( $change_time ) : ?><br><small><?php echo esc_html( $change_time ); ?></small><?php endif; ?>
						<?php elseif ( $source_note || $source_change_time ) : ?>
							<?php echo esc_html( $source_note ?: 'ثبت‌شده روی مبدأ' ); ?><?php if ( $source_change_time ) : ?><br><small><?php echo esc_html( $source_change_time ); ?></small><?php endif; ?>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<?php if ( $central_notes || $source_notes ) : ?>
						<details><summary>نمایش یادداشت‌ها</summary>
							<?php if ( $central_notes ) : ?><strong>Central</strong><ul style="margin:4px 16px 8px 0"><?php foreach ( $central_notes as $note ) : ?><li><?php echo esc_html( sanitize_text_field( $note['content'] ?? '' ) ); ?> <small><?php echo esc_html( sanitize_text_field( $note['date_gmt'] ?? '' ) ); ?></small></li><?php endforeach; ?></ul><?php endif; ?>
							<?php if ( $source_notes ) : ?><strong>مبدأ</strong><ul style="margin:4px 16px 0 0"><?php foreach ( $source_notes as $note ) : ?><li><?php echo esc_html( sanitize_text_field( $note['content'] ?? '' ) ); ?> <small><?php echo esc_html( sanitize_text_field( $note['date_gmt'] ?? '' ) ); ?></small></li><?php endforeach; ?></ul><?php endif; ?>
						</details>
						<?php else : ?>—<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private function text_row( $label, $key, $value, $placeholder = '', $type = 'text' ) {
		printf(
			'<tr><th><label for="cos-%1$s">%2$s</label></th><td><input id="cos-%1$s" type="%5$s" class="regular-text" name="%3$s[%1$s]" value="%4$s" placeholder="%6$s"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_attr( $type ),
			esc_attr( $placeholder )
		);
	}

	private function secret_row( $label, $key ) {
		printf(
			'<tr><th><label for="cos-%1$s">%2$s</label></th><td><input id="cos-%1$s" type="password" class="regular-text" autocomplete="new-password" name="%3$s[%1$s]" placeholder="برای حفظ مقدار، خالی بگذارید"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION )
		);
	}
}
