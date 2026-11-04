<?php
/**
 * User Extended model for the Decker plugin.
 *
 * @package Decker
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_User_Extended
 *
 * Handles the Custom Post Type and its metaboxes for users in the Decker plugin.
 */
class Decker_User_Extended {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks
	 *
	 * Registers all the hooks related to the decker_label taxonomy.
	 */
	private function define_hooks() {
		add_action( 'show_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_custom_user_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'show_custom_user_column_content' ), 10, 3 );
		add_action( 'wp_ajax_generate_calendar_token', array( $this, 'generate_calendar_token' ) );
		add_action( 'wp_ajax_decker_caldav_profile', array( $this, 'download_caldav_profile' ) );
	}

	/**
	 * Add custom fields to user profile.
	 *
	 * @param WP_User $user The user object.
	 */
	public function add_custom_user_profile_fields( $user ) {
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );

		$this->render_calendar_token_script( $user );
		?>
		<h3><?php esc_html_e( 'Decker Settings', 'decker' ); ?></h3>

		<table class="form-table">
			<?php
			$this->render_calendar_token_row( $user );
			$this->render_color_row( $user );
			$this->render_default_board_row( $user );
			$this->render_email_notifications_row( $user );
			?>
		</table>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('#decker_color').wpColorPicker();
			});
		</script>
		<?php
	}

	/**
	 * Print the script that refreshes the calendar token over AJAX.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_calendar_token_script( $user ) {
		?>
		<script>
		jQuery(function($) {
			$('#generate-calendar-token, #revoke-calendar-token').on('click', function() {
				$.post(ajaxurl, {action: 'generate_calendar_token', user_id: <?php echo (int) $user->ID; ?>,
					nonce: '<?php echo esc_js( wp_create_nonce( 'generate_calendar_token' ) ); ?>',
					revoke: this.id === 'revoke-calendar-token' ? '1' : '0'}, function(response) {
					if (response.success) { window.location.reload(); }
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Print the calendar token field and the calendar subscription links.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_calendar_token_row( $user ) {
		$token = get_user_meta( $user->ID, 'decker_calendar_dav_enabled', true ) ? get_user_meta( $user->ID, 'decker_calendar_token', true ) : '';
		$read_token = get_user_meta( $user->ID, 'decker_calendar_read_token', true );
		?>
		<tr><th><?php esc_html_e( 'CalDAV synchronization', 'decker' ); ?></th><td>
		<p><?php esc_html_e( 'Server URL', 'decker' ); ?>: <code><?php echo esc_url( Decker_Dav::url() ); ?></code></p>
		<p><?php esc_html_e( 'Username', 'decker' ); ?>: <code><?php echo esc_html( $user->user_login ); ?></code></p>
		<label for="decker_calendar_token"><?php esc_html_e( 'Calendar token (password)', 'decker' ); ?></label>
		<input id="decker_calendar_token" type="text" readonly class="regular-text" value="<?php echo esc_attr( $token ); ?>">
		<button type="button" class="button" id="generate-calendar-token"><?php esc_html_e( 'Generate New Token', 'decker' ); ?></button>
		<button type="button" class="button" id="revoke-calendar-token"><?php esc_html_e( 'Revoke calendar access', 'decker' ); ?></button>
		<p><?php esc_html_e( 'Generate a new token to enable editing. Regeneration or revocation immediately disconnects existing clients and subscriptions. Signing out of CAS does not revoke this token.', 'decker' ); ?></p>
		<p><?php esc_html_e( 'iPhone: add a CalDAV account in Calendar settings. Android: add an account with URL and username in DAVx⁵. Use the token as password, enable SSL, and select the Decker calendar when creating events.', 'decker' ); ?></p>
		<?php
		if ( $token ) {
			$this->render_device_setup( $user );
		}
		?>
		<p><?php esc_html_e( 'ICS subscription (read only)', 'decker' ); ?>:
		<?php if ( $read_token ) : ?>
		<code><?php echo esc_url( add_query_arg( 'token', $read_token, home_url( '/decker-calendar/' ) ) ); ?></code>
		<?php endif; ?></p>
		</td></tr>
		<?php
	}

	/**
	 * Print the one-tap DAVx⁵ link with its QR code and the iPhone profile download.
	 *
	 * Neither carries the token: the user pastes it as the password.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_device_setup( $user ) {
		$davx5 = Decker_Dav::davx5_url( $user->user_login );
		$profile = add_query_arg(
			array(
				'action' => 'decker_caldav_profile',
				'user_id' => $user->ID,
				'nonce' => wp_create_nonce( 'decker_caldav_profile' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		wp_enqueue_script( 'decker-qrcode', 'https://cdn.jsdelivr.net/npm/qrcode-generator@2.0.4/dist/qrcode.js', array(), '2.0.4', true );
		wp_add_inline_script( 'decker-qrcode', "document.querySelectorAll('[data-decker-qr]').forEach(function (el) { var qr = qrcode(0, 'M'); qr.addData(el.dataset.deckerQr); qr.make(); el.innerHTML = qr.createSvgTag(4, 2); });" );
		?>
		<h4><?php esc_html_e( 'Android (DAVx⁵)', 'decker' ); ?></h4>
		<p><a class="button" href="<?php echo esc_url( $davx5, array( 'davx5' ) ); ?>"><?php esc_html_e( 'Set up in DAVx⁵', 'decker' ); ?></a></p>
		<div data-decker-qr="<?php echo esc_attr( $davx5 ); ?>"></div>
		<p><?php esc_html_e( 'On the phone, tap the button. From a computer, scan the QR code with a QR scanner app such as Binary Eye. DAVx⁵ opens with the server and username filled in; paste the token as the password.', 'decker' ); ?></p>
		<p><?php esc_html_e( 'To use Google Calendar or the phone\'s calendar app, enable the DAVx⁵ account in that app (in Google Calendar: Settings → Manage accounts). Exclude DAVx⁵ from battery optimization so changes made on the phone upload promptly.', 'decker' ); ?></p>
		<h4><?php esc_html_e( 'iPhone and iPad', 'decker' ); ?></h4>
		<p><a class="button" href="<?php echo esc_url( $profile ); ?>"><?php esc_html_e( 'Download configuration profile', 'decker' ); ?></a></p>
		<p><?php esc_html_e( 'Open this page in Safari on the device, download the profile and install it from Settings → Profile Downloaded. Enter the token when asked for the password. The profile is not signed, so iOS marks it as unverified.', 'decker' ); ?></p>
		<?php
	}

	/**
	 * Send the iPhone/iPad CalDAV configuration profile for a user with DAV enabled.
	 */
	public function download_caldav_profile() {
		$user = $this->caldav_profile_user();
		if ( ! $user ) {
			wp_die( '', '', 403 );
		}
		nocache_headers();
		header( 'Content-Type: application/x-apple-aspen-config' );
		header( 'Content-Disposition: attachment; filename="decker-calendar.mobileconfig"' );
		echo Decker_Dav::apple_profile( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped with esc_xml() while building the plist.
		wp_die();
	}

	/**
	 * Resolve the profile owner after the nonce, capability and DAV checks.
	 *
	 * @return WP_User|null
	 */
	public function caldav_profile_user() {
		check_ajax_referer( 'decker_caldav_profile', 'nonce' );
		$user = get_userdata( isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0 );
		if ( ! $user || ! current_user_can( 'edit_user', $user->ID ) || ! get_user_meta( $user->ID, 'decker_calendar_dav_enabled', true ) ) {
			return null;
		}
		return $user;
	}

	/**
	 * Print the colour picker row.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_color_row( $user ) {
		?>
			<!-- Color Picker Field -->
			<tr>
				<th><label for="decker_color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
				<td>
					<input type="text" name="decker_color" id="decker_color" value="<?php echo esc_attr( get_the_author_meta( 'decker_color', $user->ID ) ); ?>" class="regular-text" />
					<br />
					<span class="description"><?php esc_html_e( 'Select your favorite color.', 'decker' ); ?></span>
				</td>
			</tr>
		<?php
	}

	/**
	 * Print the default board selector.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_default_board_row( $user ) {
		// Retrieve all boards for the select box.
		$boards = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);

		// Retrieve the user's selected default board.
		$default_board = get_user_meta( $user->ID, 'decker_default_board', true );
		?>
			<!-- Default Board Select Box -->
			<tr>
				<th><label for="decker_default_board"><?php esc_html_e( 'Default Board', 'decker' ); ?></label></th>
				<td>
					<select name="decker_default_board" id="decker_default_board">
						<option value=""><?php esc_html_e( 'Select a board', 'decker' ); ?></option>
						<?php
						if ( ! empty( $boards ) && ! is_wp_error( $boards ) ) {
							foreach ( $boards as $board ) {
								printf(
									'<option value="%1$s" %2$s>%3$s</option>',
									esc_attr( $board->term_id ),
									selected( $default_board, $board->term_id, false ),
									esc_html( $board->name )
								);
							}
						}
						?>
					</select>
					<br />
					<span class="description"><?php esc_html_e( 'Select your default board.', 'decker' ); ?></span>
				</td>
			</tr>
		<?php
	}

	/**
	 * Print the e-mail notification checkboxes when the feature is enabled globally.
	 *
	 * @param WP_User $user The user object.
	 */
	private function render_email_notifications_row( $user ) {
		// Check if email notifications are enabled globally.
		$global_settings           = get_option( 'decker_settings', array() );
		$allow_email_notifications = isset( $global_settings['allow_email_notifications'] ) && '1' === $global_settings['allow_email_notifications'];

		if ( ! $allow_email_notifications ) {
			return;
		}

		// Retrieve user-specific email settings or default values.
		$email_notifications = get_user_meta( $user->ID, 'decker_email_notifications', true );
		$default_settings    = array(
			'task_assigned'  => '1',
			'task_completed' => '1',
			'task_commented' => '1',
		);
		$email_notifications = wp_parse_args( $email_notifications, $default_settings );
		?>
			<!-- Email Notifications -->
			<tr>
				<th><?php esc_html_e( 'Email Notifications', 'decker' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="decker_email_notifications[task_assigned]" value="1" <?php checked( $email_notifications['task_assigned'], '1' ); ?>>
					<?php esc_html_e( 'Notify me when a task is assigned to me.', 'decker' ); ?>
					</label>
					<br>
					<label>
						<input type="checkbox" name="decker_email_notifications[task_completed]" value="1" <?php checked( $email_notifications['task_completed'], '1' ); ?>>
					<?php esc_html_e( 'Notify me when a task assigned to me is completed.', 'decker' ); ?>
					</label>
					<br>
					<label>
						<input type="checkbox" name="decker_email_notifications[task_commented]" value="1" <?php checked( $email_notifications['task_commented'], '1' ); ?>>
					<?php esc_html_e( 'Notify me when someone comments on a task I am assigned to.', 'decker' ); ?>
					</label>
				</td>
			</tr>
		<?php
	}

	/**
	 * Save custom fields.
	 *
	 * @param int $user_id The user ID.
	 */
	public function save_custom_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		check_admin_referer( 'update-user_' . $user_id );

		/*
		// TODO: Verify nonce for security (optional but recommended).
		// Uncomment the following lines if you add a nonce field in the form.

		if ( ! isset( $_POST['decker_user_extended_nonce'] ) ||
			 ! wp_verify_nonce( $_POST['decker_user_extended_nonce'], 'decker_user_extended' ) ) {
			return false;
		}
		*/

		// Save the color meta if set.
		if ( isset( $_POST['decker_color'] ) ) {
			$decker_color = sanitize_hex_color( wp_unslash( $_POST['decker_color'] ) );
			update_user_meta( $user_id, 'decker_color', $decker_color );
		}

		// Save the default board meta if set.
		if ( isset( $_POST['decker_default_board'] ) ) {
			$decker_default_board = intval( $_POST['decker_default_board'] );
			update_user_meta( $user_id, 'decker_default_board', $decker_default_board );
		}

		// Save email notifications.
		if ( ! empty( $_POST['decker_email_notifications'] ) && is_array( $_POST['decker_email_notifications'] ) ) {

			// Default settings.
			$notifications = array(
				'task_assigned'  => '0',
				'task_completed' => '0',
				'task_commented' => '0',
			);

			$sanitized_input = array_map( 'sanitize_text_field', wp_unslash( $_POST['decker_email_notifications'] ) );

			foreach ( $notifications as $key => $default ) {
				$notifications[ $key ] =
					isset( $sanitized_input[ $key ] ) ? '1' : '0';
			}

			update_user_meta( $user_id, 'decker_email_notifications', $notifications );

		}
	}

	/**
	 * Add custom column to users list.
	 *
	 * @param array $columns The current columns.
	 * @return array The customized columns.
	 */
	public function add_custom_user_columns( $columns ) {
		$columns['decker_color'] = __( 'Color', 'decker' );
		return $columns;
	}

	/**
	 * Show custom column content in users list.
	 *
	 * @param string $value The current column content.
	 * @param string $column_name The column name.
	 * @param int    $user_id The user ID.
	 * @return string The customized column content.
	 */
	public function show_custom_user_column_content( $value, $column_name, $user_id ) {
		if ( 'decker_color' === $column_name ) {
			$color = get_user_meta( $user_id, 'decker_color', true );
			if ( $color ) {
				$value = '<span style="display:inline-block;width:20px;height:20px;background-color:' . esc_attr( $color ) . ';"></span>';
			} else {
				$value = esc_html( '—' );
			}
		}
		return $value;
	}

	/**
	 * Generate a new calendar token via AJAX
	 */
	public function generate_calendar_token() {
		check_ajax_referer( 'generate_calendar_token', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error();
		}

		$revoke = isset( $_POST['revoke'] ) && '1' === $_POST['revoke'];
		$new_token = $revoke ? '' : wp_generate_password( 48, false );
		update_user_meta( $user_id, 'decker_calendar_dav_enabled', false );
		if ( $revoke ) {
			delete_user_meta( $user_id, 'decker_calendar_token' );
			delete_user_meta( $user_id, 'decker_calendar_read_token' );
		} else {
			if ( ! update_user_meta( $user_id, 'decker_calendar_token', $new_token ) || ! update_user_meta( $user_id, 'decker_calendar_read_token', wp_generate_password( 48, false ) ) ) {
				wp_send_json_error();
			}
			update_user_meta( $user_id, 'decker_calendar_dav_enabled', true );
		}
		wp_send_json_success( array( 'token' => $new_token ) );
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_User_Extended' ) ) {
	new Decker_User_Extended();
}
