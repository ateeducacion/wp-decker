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
	 * AJAX handler to load tasks by date
	 */
	public function load_tasks_by_date() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'load_tasks_by_date_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
		}

		// Get parameters
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();

		// Validate date format
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( 'Invalid date format' );
		}

		// Verify user permissions
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		// Get tasks for the specified date
		$task_manager = new TaskManager();
		$date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
		
		if ( ! $date_obj ) {
			wp_send_json_error( 'Invalid date' );
		}

		$tasks = $task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$user_id,
			0,
			false,
			$date_obj
		);

		ob_start();
		if ( ! empty( $tasks ) ) {
			foreach ( $tasks as $task ) {
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
		} else {
			?>
			<tr>
				<td colspan="4"><?php esc_html_e( 'No tasks found for this date.', 'decker' ); ?></td>
			</tr>
			<?php
		}
		$html = ob_get_clean();

		wp_send_json_success( $html );
	}
}

// Initialize the class
new Decker_Ajax_Handlers();
