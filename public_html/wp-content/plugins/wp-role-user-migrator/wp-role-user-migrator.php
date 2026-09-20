<?php
/**
 * Plugin Name: WordPress & WooCommerce Selective Role + User Migrator
 * Description: Select roles to export, then migrate those roles, capabilities, matching users, role assignments, password hashes, and user metadata.
 * Version: 3.0.0
 * Author: OpenAI
 * License: GPL-2.0-or-later
 * Text Domain: wp-role-user-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPRUM_Selective_Role_User_Migrator {

	const VERSION   = '3.0.0';
	const PAGE_SLUG = 'wprum-role-user-migrator';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_wprum_export', array( $this, 'export_data' ) );
		add_action( 'admin_post_wprum_import', array( $this, 'import_data' ) );
	}

	public function register_admin_page() {
		add_management_page(
			'Role + User Migrator',
			'Role + User Migrator',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-role-user-migrator' ) );
		}
	}

	public function render_admin_page() {
		$this->require_admin();

		$wp_roles = wp_roles();
		$roles    = is_array( $wp_roles->roles ) ? $wp_roles->roles : array();

		$counts      = count_users();
		$role_counts = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		$total_users = isset( $counts['total_users'] ) ? absint( $counts['total_users'] ) : 0;

		$result = isset( $_GET['wprum_result'] ) ? sanitize_key( wp_unslash( $_GET['wprum_result'] ) ) : '';
		$error  = isset( $_GET['wprum_error'] ) ? sanitize_key( wp_unslash( $_GET['wprum_error'] ) ) : '';

		$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
		$failed  = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
		$roles_i = isset( $_GET['roles'] ) ? absint( $_GET['roles'] ) : 0;
		?>
		<div class="wrap">
			<h1>WordPress &amp; WooCommerce Selective Role + User Migrator</h1>

			<p style="max-width:1050px;">
				Select exactly which roles you want to migrate. The export will contain only the selected role definitions,
				their capabilities, and users who have at least one selected role.
			</p>

			<?php if ( 'success' === $result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								'Import completed. Roles: %d | New users: %d | Updated users: %d | Failed users: %d',
								$roles_i,
								$created,
								$updated,
								$failed
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $this->error_message( $error ) ); ?></p>
				</div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;max-width:1180px;margin-top:20px;">

				<div class="postbox" style="padding:20px;margin:0;">
					<h2 style="margin-top:0;">1. Export from source site</h2>

					<p>
						Choose the roles to transfer. Users are included when they have at least one selected role.
						If a user has several roles, only the selected roles are stored in this export.
					</p>

					<form id="wprum-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wprum_export">
						<?php wp_nonce_field( 'wprum_export', 'wprum_export_nonce' ); ?>

						<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<button type="button" class="button" id="wprum-select-all">Select All</button>
							<button type="button" class="button" id="wprum-select-none">Select None</button>
							<strong id="wprum-selected-count"><?php echo esc_html( count( $roles ) ); ?> selected</strong>
						</p>

						<div style="border:1px solid #dcdcde;max-height:420px;overflow:auto;background:#fff;">
							<table class="widefat striped" style="border:0;">
								<thead style="position:sticky;top:0;background:#fff;z-index:2;">
									<tr>
										<th style="width:45px;">Use</th>
										<th>Role</th>
										<th>Slug</th>
										<th>Users</th>
										<th>Caps</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $roles as $slug => $role_data ) : ?>
										<?php
										$name  = isset( $role_data['name'] ) ? $role_data['name'] : $slug;
										$caps  = isset( $role_data['capabilities'] ) && is_array( $role_data['capabilities'] ) ? $role_data['capabilities'] : array();
										$users = isset( $role_counts[ $slug ] ) ? absint( $role_counts[ $slug ] ) : 0;
										?>
										<tr>
											<td>
												<input
													type="checkbox"
													class="wprum-role-checkbox"
													name="selected_roles[]"
													value="<?php echo esc_attr( $slug ); ?>"
													checked
												>
											</td>
											<td><strong><?php echo esc_html( translate_user_role( $name ) ); ?></strong></td>
											<td><code><?php echo esc_html( $slug ); ?></code></td>
											<td><?php echo esc_html( $users ); ?></td>
											<td><?php echo esc_html( count( $caps ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<p>
							<strong>Total users on site:</strong> <?php echo esc_html( $total_users ); ?><br>
							<small>Users without any selected role are not exported.</small>
						</p>

						<?php submit_button( 'Export Selected Roles + Users', 'primary', 'submit', false, array( 'id' => 'wprum-export-submit' ) ); ?>
					</form>
				</div>

				<div class="postbox" style="padding:20px;margin:0;">
					<h2 style="margin-top:0;">2. Import into destination site</h2>

					<p>
						The destination imports only the roles contained in the JSON file.
						Unrelated destination roles are not removed from users.
					</p>

					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wprum_import">
						<?php wp_nonce_field( 'wprum_import', 'wprum_import_nonce' ); ?>

						<p>
							<label><strong>Export JSON file</strong></label><br>
							<input type="file" name="wprum_file" accept=".json,application/json" required>
						</p>

						<p>
							<label>
								<input type="checkbox" name="import_usermeta" value="1" checked>
								Import user metadata (recommended for WooCommerce)
							</label>
						</p>

						<p>
							<label>
								<input type="checkbox" name="overwrite_existing_passwords" value="1">
								Overwrite password hashes for users already existing on destination
							</label><br>
							<small>New users always keep the source password hash.</small>
						</p>

						<p>
							<label>
								<input type="checkbox" name="protect_current_admin" value="1" checked>
								Protect my currently logged-in administrator from role/password changes
							</label>
						</p>

						<?php submit_button( 'Import Selected Roles + Users', 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="notice notice-info inline" style="max-width:1140px;margin-top:20px;">
				<p>
					<strong>Selective role behavior:</strong> if you export only <code>customer</code> and <code>vip_customer</code>,
					only those role definitions and users assigned to at least one of them are transferred.
					For an existing destination user, roles unrelated to this import are preserved.
				</p>
			</div>

			<div class="notice notice-warning inline" style="max-width:1140px;margin-top:12px;">
				<p>
					<strong>Security:</strong> the JSON contains personal data and password hashes.
					Keep it private and delete it after migration. Session tokens and WordPress Application Passwords are excluded.
				</p>
			</div>
		</div>

		<script>
		(function() {
			const boxes = Array.from(document.querySelectorAll('.wprum-role-checkbox'));
			const count = document.getElementById('wprum-selected-count');
			const allBtn = document.getElementById('wprum-select-all');
			const noneBtn = document.getElementById('wprum-select-none');
			const form = document.getElementById('wprum-export-form');

			function updateCount() {
				const selected = boxes.filter(function(box) { return box.checked; }).length;
				count.textContent = selected + ' selected';
			}

			allBtn.addEventListener('click', function() {
				boxes.forEach(function(box) { box.checked = true; });
				updateCount();
			});

			noneBtn.addEventListener('click', function() {
				boxes.forEach(function(box) { box.checked = false; });
				updateCount();
			});

			boxes.forEach(function(box) {
				box.addEventListener('change', updateCount);
			});

			form.addEventListener('submit', function(event) {
				if (!boxes.some(function(box) { return box.checked; })) {
					event.preventDefault();
					window.alert('Please select at least one role to export.');
				}
			});

			updateCount();
		})();
		</script>
		<?php
	}

	public function export_data() {
		$this->require_admin();
		check_admin_referer( 'wprum_export', 'wprum_export_nonce' );

		global $wpdb;

		$requested_roles = isset( $_POST['selected_roles'] ) && is_array( $_POST['selected_roles'] )
			? wp_unslash( $_POST['selected_roles'] )
			: array();

		$requested_roles = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $requested_roles )
				)
			)
		);

		if ( empty( $requested_roles ) ) {
			$this->redirect_error( 'no_roles_selected' );
		}

		$wp_roles  = wp_roles();
		$all_roles = is_array( $wp_roles->roles ) ? $wp_roles->roles : array();

		$selected_roles = array();

		foreach ( $requested_roles as $role_slug ) {
			if ( isset( $all_roles[ $role_slug ] ) ) {
				$selected_roles[ $role_slug ] = $all_roles[ $role_slug ];
			}
		}

		if ( empty( $selected_roles ) ) {
			$this->redirect_error( 'no_valid_roles_selected' );
		}

		$selected_slugs = array_keys( $selected_roles );

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = $host ? sanitize_file_name( $host ) : 'wordpress-site';

		$filename = sprintf(
			'%s-selected-role-user-export-%s.json',
			$host,
			gmdate( 'Y-m-d-His' )
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$meta = array(
			'generator'              => 'wp-role-user-migrator',
			'version'                => self::VERSION,
			'exported_at'            => gmdate( 'c' ),
			'site_url'               => home_url( '/' ),
			'source_prefix'          => $wpdb->prefix,
			'selected_role_slugs'    => $selected_slugs,
			'selective_role_export'  => true,
		);

		echo "{\n";
		echo '"meta":' . wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ",\n";
		echo '"roles":' . wp_json_encode( $selected_roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ",\n";
		echo "\"users\":[\n";

		$user_ids = get_users(
			array(
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => -1,
			)
		);

		$first = true;

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			$user_selected_roles = array_values(
				array_intersect(
					array_values( (array) $user->roles ),
					$selected_slugs
				)
			);

			if ( empty( $user_selected_roles ) ) {
				continue;
			}

			$record = array(
				'source_id'       => (int) $user->ID,
				'user_login'      => (string) $user->user_login,
				'user_pass'       => (string) $user->user_pass,
				'user_nicename'   => (string) $user->user_nicename,
				'user_email'      => (string) $user->user_email,
				'user_url'        => (string) $user->user_url,
				'user_registered' => (string) $user->user_registered,
				'display_name'    => (string) $user->display_name,
				'roles'           => $user_selected_roles,
				'meta'            => $this->export_user_meta( $user->ID ),
			);

			if ( ! $first ) {
				echo ",\n";
			}

			echo wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$first = false;
		}

		echo "\n]}\n";
		exit;
	}

	private function export_user_meta( $user_id ) {
		global $wpdb;

		$all_meta = get_user_meta( $user_id );
		$result   = array();

		$excluded_exact = array(
			'session_tokens',
			'_application_passwords',
			$wpdb->prefix . 'capabilities',
			$wpdb->prefix . 'user_level',
		);

		foreach ( $all_meta as $key => $values ) {
			if ( in_array( $key, $excluded_exact, true ) ) {
				continue;
			}

			if ( preg_match( '/(^|_)capabilities$/', $key ) || preg_match( '/(^|_)user_level$/', $key ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				$values = array( $values );
			}

			$clean_values = array();

			foreach ( $values as $value ) {
				$clean_values[] = maybe_unserialize( $value );
			}

			$result[ $key ] = $clean_values;
		}

		return $result;
	}

	public function import_data() {
		$this->require_admin();
		check_admin_referer( 'wprum_import', 'wprum_import_nonce' );

		if ( ! isset( $_FILES['wprum_file'] ) || ! is_array( $_FILES['wprum_file'] ) ) {
			$this->redirect_error( 'missing_file' );
		}

		$file = $_FILES['wprum_file'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_error( 'upload_error' );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->redirect_error( 'invalid_upload' );
		}

		$extension = strtolower( pathinfo( isset( $file['name'] ) ? $file['name'] : '', PATHINFO_EXTENSION ) );

		if ( 'json' !== $extension ) {
			$this->redirect_error( 'invalid_extension' );
		}

		$json = file_get_contents( $file['tmp_name'] );

		if ( false === $json || '' === trim( $json ) ) {
			$this->redirect_error( 'empty_file' );
		}

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$this->redirect_error( 'invalid_json' );
		}

		if (
			! isset( $data['roles'] ) ||
			! is_array( $data['roles'] ) ||
			! isset( $data['users'] ) ||
			! is_array( $data['users'] )
		) {
			$this->redirect_error( 'wrong_format' );
		}

		$import_usermeta              = ! empty( $_POST['import_usermeta'] );
		$overwrite_existing_passwords = ! empty( $_POST['overwrite_existing_passwords'] );
		$protect_current_admin        = ! empty( $_POST['protect_current_admin'] );

		$imported_role_slugs = array_values(
			array_filter(
				array_map( 'sanitize_key', array_keys( $data['roles'] ) )
			)
		);

		if ( empty( $imported_role_slugs ) ) {
			$this->redirect_error( 'wrong_format' );
		}

		$role_count = $this->import_roles( $data['roles'] );

		$created = 0;
		$updated = 0;
		$failed  = 0;

		$current_user_id = get_current_user_id();

		foreach ( $data['users'] as $user_record ) {
			if ( ! is_array( $user_record ) ) {
				$failed++;
				continue;
			}

			$result = $this->import_one_user(
				$user_record,
				$imported_role_slugs,
				$import_usermeta,
				$overwrite_existing_passwords,
				$protect_current_admin,
				$current_user_id
			);

			if ( 'created' === $result ) {
				$created++;
			} elseif ( 'updated' === $result ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		$redirect = add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'wprum_result' => 'success',
				'roles'        => $role_count,
				'created'      => $created,
				'updated'      => $updated,
				'failed'       => $failed,
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function import_roles( $roles ) {
		$wp_roles = wp_roles();

		if ( empty( $wp_roles->role_key ) ) {
			return 0;
		}

		$current = is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
		$count   = 0;

		foreach ( $roles as $slug => $role_data ) {
			if (
				! is_string( $slug ) ||
				! is_array( $role_data ) ||
				! isset( $role_data['name'] ) ||
				! isset( $role_data['capabilities'] ) ||
				! is_array( $role_data['capabilities'] )
			) {
				continue;
			}

			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				continue;
			}

			$name = sanitize_text_field( (string) $role_data['name'] );
			if ( '' === $name ) {
				$name = $slug;
			}

			$caps = array();

			foreach ( $role_data['capabilities'] as $cap => $granted ) {
				if ( ! is_string( $cap ) || '' === trim( $cap ) ) {
					continue;
				}

				$cap = sanitize_key( $cap );

				if ( '' !== $cap ) {
					$caps[ $cap ] = (bool) $granted;
				}
			}

			$current[ $slug ] = array(
				'name'         => $name,
				'capabilities' => $caps,
			);

			$count++;
		}

		update_option( $wp_roles->role_key, $current );

		if ( method_exists( $wp_roles, 'for_site' ) ) {
			$wp_roles->for_site( get_current_blog_id() );
		}

		return $count;
	}

	private function import_one_user(
		$record,
		$imported_role_slugs,
		$import_usermeta,
		$overwrite_existing_passwords,
		$protect_current_admin,
		$current_user_id
	) {
		global $wpdb;

		$login = isset( $record['user_login'] ) ? sanitize_user( (string) $record['user_login'], true ) : '';
		$email = isset( $record['user_email'] ) ? sanitize_email( (string) $record['user_email'] ) : '';

		if ( '' === $login ) {
			return 'failed';
		}

		$existing = false;

		if ( '' !== $email ) {
			$existing = get_user_by( 'email', $email );
		}

		if ( ! $existing ) {
			$existing = get_user_by( 'login', $login );
		}

		$is_existing = (bool) $existing;
		$user_id     = $is_existing ? (int) $existing->ID : 0;

		$userdata = array(
			'user_nicename' => isset( $record['user_nicename'] )
				? sanitize_title( (string) $record['user_nicename'] )
				: sanitize_title( $login ),
			'user_url'      => isset( $record['user_url'] ) ? esc_url_raw( (string) $record['user_url'] ) : '',
			'display_name'  => isset( $record['display_name'] ) ? sanitize_text_field( (string) $record['display_name'] ) : $login,
		);

		if ( '' !== $email ) {
			$other_email_user = get_user_by( 'email', $email );

			if ( ! $other_email_user || ( $is_existing && (int) $other_email_user->ID === $user_id ) ) {
				$userdata['user_email'] = $email;
			}
		}

		if ( $is_existing ) {
			$userdata['ID'] = $user_id;
			$result = wp_update_user( $userdata );

			if ( is_wp_error( $result ) ) {
				return 'failed';
			}
		} else {
			$userdata['user_login'] = $login;
			$userdata['user_pass']  = wp_generate_password( 32, true, true );

			if ( isset( $record['user_registered'] ) && is_string( $record['user_registered'] ) ) {
				$registered = trim( $record['user_registered'] );

				if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $registered ) ) {
					$userdata['user_registered'] = $registered;
				}
			}

			$result = wp_insert_user( $userdata );

			if ( is_wp_error( $result ) ) {
				return 'failed';
			}

			$user_id = (int) $result;

			if ( ! empty( $record['user_pass'] ) && is_string( $record['user_pass'] ) ) {
				$wpdb->update(
					$wpdb->users,
					array( 'user_pass' => (string) $record['user_pass'] ),
					array( 'ID' => $user_id ),
					array( '%s' ),
					array( '%d' )
				);

				clean_user_cache( $user_id );
			}
		}

		$is_protected_current_admin = (
			$protect_current_admin &&
			$user_id === (int) $current_user_id &&
			user_can( $user_id, 'manage_options' )
		);

		if (
			$is_existing &&
			$overwrite_existing_passwords &&
			! $is_protected_current_admin &&
			! empty( $record['user_pass'] ) &&
			is_string( $record['user_pass'] )
		) {
			$wpdb->update(
				$wpdb->users,
				array( 'user_pass' => (string) $record['user_pass'] ),
				array( 'ID' => $user_id ),
				array( '%s' ),
				array( '%d' )
			);

			clean_user_cache( $user_id );
		}

		if ( ! $is_protected_current_admin ) {
			$user_obj = new WP_User( $user_id );

			$source_roles = isset( $record['roles'] ) && is_array( $record['roles'] )
				? array_values(
					array_intersect(
						array_values( array_unique( array_map( 'sanitize_key', $record['roles'] ) ) ),
						$imported_role_slugs
					)
				)
				: array();

			/*
			 * Synchronize ONLY the role universe included in this import:
			 * - remove imported roles the user should no longer have
			 * - add imported roles present in source
			 * - leave unrelated destination roles untouched
			 */
			foreach ( (array) $user_obj->roles as $current_role ) {
				if (
					in_array( $current_role, $imported_role_slugs, true ) &&
					! in_array( $current_role, $source_roles, true )
				) {
					$user_obj->remove_role( $current_role );
				}
			}

			foreach ( $source_roles as $role_slug ) {
				if ( get_role( $role_slug ) && ! in_array( $role_slug, (array) $user_obj->roles, true ) ) {
					$user_obj->add_role( $role_slug );
				}
			}
		}

		if ( $import_usermeta && isset( $record['meta'] ) && is_array( $record['meta'] ) ) {
			$this->import_user_meta( $user_id, $record['meta'] );
		}

		clean_user_cache( $user_id );

		return $is_existing ? 'updated' : 'created';
	}

	private function import_user_meta( $user_id, $meta ) {
		global $wpdb;

		$excluded_exact = array(
			'session_tokens',
			'_application_passwords',
			$wpdb->prefix . 'capabilities',
			$wpdb->prefix . 'user_level',
		);

		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( in_array( $key, $excluded_exact, true ) ) {
				continue;
			}

			if ( preg_match( '/(^|_)capabilities$/', $key ) || preg_match( '/(^|_)user_level$/', $key ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				$values = array( $values );
			}

			delete_user_meta( $user_id, $key );

			foreach ( $values as $value ) {
				add_user_meta( $user_id, $key, $value, false );
			}
		}
	}

	private function redirect_error( $code ) {
		$url = add_query_arg(
			array(
				'page'        => self::PAGE_SLUG,
				'wprum_error' => sanitize_key( $code ),
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function error_message( $code ) {
		$messages = array(
			'no_roles_selected'       => 'Please select at least one role to export.',
			'no_valid_roles_selected' => 'None of the selected roles are currently registered on this site.',
			'missing_file'            => 'No import file was selected.',
			'upload_error'            => 'The file upload failed. Check your PHP/WordPress upload limits.',
			'invalid_upload'          => 'The uploaded file is not valid.',
			'invalid_extension'       => 'Please upload the JSON file exported by this plugin.',
			'empty_file'              => 'The uploaded file is empty.',
			'invalid_json'            => 'The uploaded file does not contain valid JSON.',
			'wrong_format'            => 'This is not a valid selective Role + User Migrator export file.',
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : 'An unknown import error occurred.';
	}
}

new WPRUM_Selective_Role_User_Migrator();
