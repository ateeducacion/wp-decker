<?php
/**
 * File class-decker-ajax-handlers
 *
 * @package    Decker
 * @subpackage Decker/includes/ajax
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Ajax_Handlers
 *
 * Handles AJAX requests for the Decker plugin.
 */
class Decker_Ajax_Handlers {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define hooks for AJAX handlers
	 */
	private function define_hooks() {
		add_action( 'wp_ajax_load_tasks_by_date', array( $this, 'load_tasks_by_date' ) );
	}

	/**
	 * AJAX handler to load tasks by date.
	 */
	public function load_tasks_by_date() {
		// Validate request and get parameters.
		$validation_result = $this->validate_task_date_request();
		if ( is_wp_error( $validation_result ) ) {
			wp_send_json_error( $validation_result->get_error_message() );
			return;
		}

		list( $date_obj, $user_id ) = $validation_result;

		// Get tasks for the specified date.
		$tasks = $this->get_tasks_for_date( $date_obj, $user_id );

		// Generate HTML for the tasks.
		$html = $this->generate_tasks_html( $tasks );

		wp_send_json_success( $html );
	}

	/**
	 * Gets tasks for a specific date and user.
	 *
	 * @param DateTime $date_obj The date to get tasks for.
	 * @param int      $user_id  The user ID.
	 * @return array Array of Task objects.
	 */
	private function get_tasks_for_date( DateTime $date_obj, int $user_id ) {
		$task_manager = new TaskManager();
		return $task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$user_id,
			0,
			false,
			$date_obj
		);
	}

	/**
	 * Validates the task date request parameters.
	 *
	 * @return WP_Error|array Error object or array with date object and user ID.
	 */
	private function validate_task_date_request() {
		// Verify nonce first before processing any form data.
		if ( ! $this->verify_nonce() ) {
			return new WP_Error( 'invalid_nonce', 'Invalid security token' );
		}

		// Now that nonce is verified, we can safely get parameters.
		$date = $this->get_date_param();
		$user_id = $this->get_user_id_param();

		// Validate date format.
		if ( ! $this->is_valid_date_format( $date ) ) {
			return new WP_Error( 'invalid_date_format', 'Invalid date format' );
		}

		// Verify user permissions.
		if ( ! $this->user_has_permission( $user_id ) ) {
			return new WP_Error( 'permission_denied', 'Permission denied' );
		}

		// Create date object.
		$date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $date_obj ) {
			return new WP_Error( 'invalid_date', 'Invalid date' );
		}

		return array( $date_obj, $user_id );
	}

	/**
	 * Verifies the nonce for the AJAX request.
	 *
	 * @return bool Whether the nonce is valid.
	 */
	private function verify_nonce() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the verification function itself
		if ( ! isset( $_POST['nonce'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the verification function itself
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		return wp_verify_nonce( $nonce, 'load_tasks_by_date_nonce' );
	}

	/**
	 * Gets the date parameter from the request.
	 *
	 * @return string The date parameter.
	 */
	private function get_date_param() {
		// Nonce is already verified in validate_task_date_request before this method is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in validate_task_date_request
		if ( ! isset( $_POST['date'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in validate_task_date_request
		return sanitize_text_field( wp_unslash( $_POST['date'] ) );
	}

	/**
	 * Gets the user ID parameter from the request.
	 *
	 * @return int The user ID.
	 */
	private function get_user_id_param() {
		// Nonce is already verified in validate_task_date_request before this method is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in validate_task_date_request
		if ( ! isset( $_POST['user_id'] ) ) {
			return get_current_user_id();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in validate_task_date_request
		return intval( $_POST['user_id'] );
	}

	/**
	 * Checks if the date format is valid.
	 *
	 * @param string $date The date string to check.
	 * @return bool Whether the date format is valid.
	 */
	private function is_valid_date_format( $date ) {
		// Simple validation for YYYY-MM-DD format.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	/**
	 * Checks if the current user has permission to view tasks for the specified user.
	 *
	 * @param int $user_id The user ID to check permissions for.
	 * @return bool Whether the current user has permission.
	 */
	private function user_has_permission( $user_id ) {
		return get_current_user_id() === $user_id || current_user_can( 'edit_users' );
	}

	/**
	 * Generates HTML for the task list.
	 *
	 * @param array $tasks Array of Task objects.
	 * @return string HTML content.
	 */
	private function generate_tasks_html( $tasks ) {
		ob_start();
		if ( ! empty( $tasks ) ) {
			foreach ( $tasks as $task ) {
				$this->render_task_row( $task );
			}
		} else {
			$this->render_empty_row();
		}
		return ob_get_clean();
	}

	/**
	 * Renders an empty row when no tasks are found.
	 */
	private function render_empty_row() {
		?>
		<tr>
			<td colspan="4"><?php esc_html_e( 'No tasks found for this date.', 'decker' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Renders a single task row.
	 *
	 * @param Task $task Task object.
	 */
	private function render_task_row( $task ) {
		$board_info = $this->get_board_info( $task );
		$this->output_task_row_html( $task, $board_info['color'], $board_info['name'] );
	}

	/**
	 * Gets board information for a task.
	 *
	 * @param Task $task Task object.
	 * @return array Board information (color and name).
	 */
	private function get_board_info( $task ) {
		$board_color = 'red';
		$board_name = 'Unassigned';

		if ( $task->board ) {
			$board_color = $task->board->color;
			$board_name = $task->board->name;
		}

		return array(
			'color' => $board_color,
			'name' => $board_name,
		);
	}

	/**
	 * Outputs the HTML for a task row.
	 *
	 * @param Task   $task        Task object.
	 * @param string $board_color Board color.
	 * @param string $board_name  Board name.
	 */
	private function output_task_row_html( $task, $board_color, $board_name ) {
		?>
		<tr class="task-row" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
			<td><input type="checkbox" name="task_ids[]" class="task-checkbox" value="<?php echo esc_attr( $task->ID ); ?>"></td>
			<td>
				<span class="custom-badge overflow-visible" style="background-color: <?php echo esc_attr( $board_color ); ?>;">
					<?php echo esc_html( $board_name ); ?>
				</span>
			</td>
                       <td><?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ); ?></td>
			<td><?php echo esc_html( $task->title ); ?></td>
		</tr>
		<?php
	}
}

// Initialize the class.
new Decker_Ajax_Handlers();
