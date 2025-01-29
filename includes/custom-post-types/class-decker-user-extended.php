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
	}

	/**
	 * Add custom fields to user profile.
	 *
	 * @param WP_User $user The user object.
	 */
	public function add_custom_user_profile_fields( $user ) {
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );
		?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('#generate-calendar-token').on('click', function() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'generate_calendar_token',
							user_id: <?php echo esc_js($user->ID); ?>,
							nonce: '<?php echo wp_create_nonce('generate_calendar_token'); ?>',
							action: 'generate_calendar_token'
						},
						success: function(response) {
							if (response.success) {
								$('#decker_calendar_token').val(response.data.token);
								// Update calendar URL in description
								const calendarUrl = '<?php echo home_url('decker-calendar'); ?>?token=' + response.data.token;
								$('#decker_calendar_token').closest('td').find('.description code').text(calendarUrl);
							}
						}
					});
				});
			});
		</script>
		<?php

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
		<h3><?php esc_html_e( 'Decker Settings', 'decker' ); ?></h3>

		<table class="form-table">
			<!-- Calendar Token Field -->
			<tr>
				<th><label for="decker_calendar_token"><?php esc_html_e( 'Calendar Token', 'decker' ); ?></label></th>
				<td>
					<?php 
					$calendar_token = get_user_meta($user->ID, 'decker_calendar_token', true);
					if (empty($calendar_token)) {
						$calendar_token = wp_generate_uuid4();
					}
					?>
					<input type="text" name="decker_calendar_token" id="decker_calendar_token" 
						value="<?php echo esc_attr($calendar_token); ?>" class="regular-text" readonly />
					<button type="button" class="button" id="generate-calendar-token">
						<?php esc_html_e('Generate New Token', 'decker'); ?>
					</button>
					<br />
					<span class="description">
						<?php 
						$calendar_url = add_query_arg('token', $calendar_token, home_url('decker-calendar'));
						$webcal_url = str_replace('http://', 'webcal://', $calendar_url);
						$webcal_url = str_replace('https://', 'webcal://', $webcal_url);
						
						echo '<div class="calendar-links" style="margin-top: 10px;">';
						echo '<p style="margin-bottom: 5px;">' . esc_html__('Subscribe to Decker calendar:', 'decker') . '</p>';
						echo '<a href="' . esc_url('https://www.google.com/calendar/render?cid=' . urlencode($webcal_url)) . '" 
								class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-google" style="vertical-align: middle;"></span> ' . 
								esc_html__('Google Calendar', 'decker') . '</a> ';
						
						echo '<a href="' . esc_url($webcal_url) . '" 
								class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-calendar-alt" style="vertical-align: middle;"></span> ' . 
								esc_html__('iCalendar', 'decker') . '</a> ';
						
						echo '<a href="' . esc_url('https://outlook.office.com/owa?path=/calendar/action/compose&rru=addsubscription&url=' . 
								urlencode($webcal_url) . '&name=' . urlencode(get_bloginfo('name') . ' - ' . __('Calendar', 'decker'))) . '" 
								class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-calendar" style="vertical-align: middle;"></span> ' . 
								esc_html__('Outlook 365', 'decker') . '</a> ';
						
						echo '<a href="' . esc_url(add_query_arg('export', '1', $calendar_url)) . '" 
								class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> ' . 
								esc_html__('Export .ics file', 'decker') . '</a>';
						echo '</div>';
						?>
					</span>
				</td>
			</tr>

			<!-- Color Picker Field -->
			<tr>
				<th><label for="decker_color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
				<td>
					<input type="text" name="decker_color" id="decker_color" value="<?php echo esc_attr( get_the_author_meta( 'decker_color', $user->ID ) ); ?>" class="regular-text" />
					<br />
					<span class="description"><?php esc_html_e( 'Select your favorite color.', 'decker' ); ?></span>
				</td>
			</tr>
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
				// Check if email notifications are enabled globally.
				$global_settings = get_option( 'decker_settings', array() );
				$allow_email_notifications = isset( $global_settings['allow_email_notifications'] ) && '1' === $global_settings['allow_email_notifications'];

			if ( $allow_email_notifications ) {

				// Retrieve user-specific email settings or default values.
				$email_notifications = get_user_meta( $user->ID, 'decker_email_notifications', true );
				$default_settings = array(
					'task_assigned'   => '1',
					'task_completed'  => '1',
					'task_commented'  => '1',
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
		check_ajax_referer('generate_calendar_token', 'nonce');
		
		$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
		
		if (!current_user_can('edit_user', $user_id)) {
			wp_send_json_error();
		}

		$new_token = wp_generate_uuid4();
		update_user_meta($user_id, 'decker_calendar_token', $new_token);
		
		wp_send_json_success(array('token' => $new_token));
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_User_Extended' ) ) {
	new Decker_User_Extended();
}
