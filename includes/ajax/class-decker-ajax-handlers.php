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
		}
		
		list( $date_obj, $user_id ) = $validation_result;
		
		// Get tasks for the specified date.
		$task_manager = new TaskManager();
		$tasks = $task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$user_id,
			0,
			false,
			$date_obj
		);

		// Generate HTML for the tasks.
		$html = $this->generate_tasks_html( $tasks );
		
		wp_send_json_success( $html );
	}
	
	/**
	 * Validates the task date request parameters.
	 *
	 * @return WP_Error|array Error object or array with date object and user ID.
	 */
	private function validate_task_date_request() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'load_tasks_by_date_nonce' ) ) {
			return new WP_Error( 'invalid_nonce', 'Invalid security token' );
		}

		// Get parameters.
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();

		// Validate date format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'invalid_date_format', 'Invalid date format' );
		}

		// Verify user permissions.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
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
			?>
			<tr>
				<td colspan="4"><?php esc_html_e( 'No tasks found for this date.', 'decker' ); ?></td>
			</tr>
			<?php
		}
		return ob_get_clean();
	}
	
	/**
	 * Renders a single task row.
	 *
	 * @param Task $task Task object.
	 */
	private function render_task_row( $task ) {
		$board_color = 'red';
		$board_name = 'Unassigned';
		if ( $task->board ) {
			$board_color = $task->board->color;
			$board_name = $task->board->name;
		}
		?>
		<tr class="task-row" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
			<td><input type="checkbox" name="task_ids[]" class="task-checkbox" value="<?php echo esc_attr( $task->ID ); ?>"></td>
			<td>
				<span class="custom-badge overflow-visible" style="background-color: <?php echo esc_attr( $board_color ); ?>;">
					<?php echo esc_html( $board_name ); ?>
				</span>
			</td>
			<td><?php echo esc_html( $task->stack ); ?></td>
			<td><?php echo esc_html( $task->title ); ?></td>
		</tr>
		<?php
	}
}

// Initialize the class.
new Decker_Ajax_Handlers();
