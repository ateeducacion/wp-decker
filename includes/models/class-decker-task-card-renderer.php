<?php
/**
 * Kanban card renderer for tasks.
 *
 * Owns the task card partial: the card markup, its background/due-date
 * styling helpers, the board name/comments/labels counters, the people
 * avatar group, and the relative-time/formatted-date/pastelize-color
 * display formatters. Delegates the embedded contextual menu to
 * Decker_Task_Menu_Renderer and people resolution to
 * Decker_Task_People_View.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Card_Renderer
 */
class Decker_Task_Card_Renderer {

	/**
	 * The task being rendered.
	 *
	 * @var Task
	 */
	private $task;

	/**
	 * Store the task the card is rendered for.
	 *
	 * @param Task $task The task being rendered.
	 */
	public function __construct( Task $task ) {
		$this->task = $task;
	}

	/**
	 * Render the current task card for Kanban.
	 *
	 * @param bool $draw_background_color Whether to include background color styling. Defaults to false.
	 */
	public function render_task_card( bool $draw_background_color = false ) {
		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id'          => esc_attr( $this->task->ID ),
			),
			home_url( '/' )
		);
		$priority_badge_class = $this->task->max_priority ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary';
		$priority_label      = $this->task->max_priority ? __( '🔥', 'decker' ) : __( 'Normal', 'decker' );

		$formatted_duedate = $this->get_formatted_date();
		$relative_time = '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . __( 'Undefined date', 'decker' ) . '</span>';

		if ( $this->task->duedate instanceof DateTime ) {
			$relative_time = esc_html( $this->get_relative_time() );
		}

		$card_background_color = $this->get_card_background_style( $draw_background_color );

		?>
		<div class="task card mb-0" data-task-id="<?php echo esc_attr( $this->task->ID ); ?>" <?php echo wp_kses_post( $card_background_color ); ?>>
			<div class="card-body p-3">
				<span class="float-end badge <?php echo esc_attr( $priority_badge_class ); ?>">
					<span class="label-to-hide"><?php echo esc_html( $priority_label ); ?></span>
					<span class="menu-order label-to-show" style="display: none;"><?php esc_html_e( 'Order:', 'decker' ); ?> <?php echo esc_html( $this->task->order ); ?></span>
				</span>

				<small class="text-muted relative-time-badge" title="<?php echo esc_attr( $formatted_duedate ); ?>">
					<span class="task-id label-to-hide 
						<?php
						echo esc_attr( $this->get_due_css_class() );
						?>
						">
						<?php echo esc_html( $this->get_relative_time() ); ?>
					</span>
					<span class="task-id label-to-show" style="display: none;">#<?php echo esc_html( $this->task->ID ); ?></span>
				</small>

				<h5 class="my-2 fs-16" id="task-<?php echo esc_attr( $this->task->ID ); ?>">
					<a href="<?php echo esc_url( $task_url ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="text-body" data-task-id="<?php echo esc_attr( $this->task->ID ); ?>">
						<?php echo esc_html( $this->task->title ); ?>
					</a>
				</h5>

				<p class="mb-0">
					<span class="pe-2 text-nowrap mb-2 d-inline-block">
						<i class="ri-briefcase-2-line text-muted"></i>                       
						<?php

						$this->render_card_board_name( $draw_background_color );
						?>
					</span>
					<?php
					$this->render_card_comments_counter();
					?>
					<span class="text-nowrap mb-2 d-inline-block">
						<i class="ri-attachment-2 text-muted"></i>
						<b><?php echo esc_html( count( get_attached_media( '', $this->task->ID ) ) ); ?></b>
					</span>
					<?php
					$this->render_card_labels_counter();
					?>
				</p>

				<?php ( new Decker_Task_Menu_Renderer( $this->task ) )->render_task_menu(); ?>

				<div class="mt-2">
					<?php $this->render_people_avatars(); ?>
				</div>
			</div> <!-- end card-body -->
		</div>
		<?php
	}

	/**
	 * Builds the card background style attribute.
	 *
	 * @param bool $draw_background_color Whether to include background color styling.
	 * @return string The style="..." attribute, or '' when no background is needed.
	 */
	private function get_card_background_style( bool $draw_background_color ): string {
		if ( $draw_background_color && $this->task->board && $this->task->board->color ) {
			$board_color = self::pastelize_color( $this->task->board->color );

			if ( $this->task->hidden ) {
				return 'style="background-color: gainsboro; opacity: 1; background-image: repeating-linear-gradient(45deg,' . $board_color . ' 25%, transparent 25%, transparent 75%,' . $board_color . ' 75%,' . $board_color . '), repeating-linear-gradient(45deg,' . $board_color . ' 25%, gainsboro 25%, gainsboro 75%,' . $board_color . ' 75%,' . $board_color . '); background-position: 0 0, 10px 10px; background-size: 20px 20px;"';
			}

			return 'style="background-color: ' . esc_attr( $board_color ) . ';"';
		}

		if ( $this->task->hidden ) {
			// For hidden tasks, we set a light gray color.
			return 'style="background-color: gainsboro;"';
		}

		return '';
	}

	/**
	 * Returns the due-date css class for the relative-time badge.
	 *
	 * @return string 'due-today', 'due-past', or '' when no due date applies.
	 */
	private function get_due_css_class(): string {
		if ( $this->task->duedate instanceof DateTime ) {
			$today_midnight = new DateTime( 'today' );
			$due_midnight = clone $this->task->duedate;
			$due_midnight->setTime( 0, 0, 0 );

			if ( $due_midnight == $today_midnight ) {
				return 'due-today';
			} elseif ( $due_midnight < $today_midnight ) {
				return 'due-past';
			}
		}

		return '';
	}

	/**
	 * Echoes the board name inside the card when the background color is drawn.
	 *
	 * @param bool $draw_background_color Whether to include background color styling.
	 * @return void
	 */
	private function render_card_board_name( bool $draw_background_color ): void {
		if ( $draw_background_color && $this->task->board && $this->task->board->color ) {
			echo esc_html( $this->task->board->name );
		}
	}

	/**
	 * Echoes the comments counter, with a popover trigger when comments exist.
	 *
	 * @return void
	 */
	private function render_card_comments_counter(): void {
		$comments_count = (int) get_comments_number( $this->task->ID );
		if ( $comments_count > 0 ) :
			/* translators: %d is the number of comments on the task. */
			$comments_title = sprintf( _n( '%d comment', '%d comments', $comments_count, 'decker' ), $comments_count );
			?>
			<span class="text-nowrap mb-2 d-inline-flex align-items-center decker-comments-popover"
				role="button"
				tabindex="0"
				data-bs-toggle="popover"
				data-bs-trigger="hover focus"
				data-bs-html="true"
				data-bs-placement="right"
				data-bs-fallback-placements='["left","top","bottom"]'
				data-bs-custom-class="decker-comments-popover-pop"
				data-decker-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
				data-decker-comments-count="<?php echo esc_attr( $comments_count ); ?>"
				title="<?php echo esc_attr( $comments_title ); ?>"
				data-bs-content="<?php echo esc_attr__( 'Loading comments…', 'decker' ); ?>">
				<i class="ri-discuss-line text-muted me-1"></i>
				<b><?php echo esc_html( $comments_count ); ?></b>
			</span>
			<?php
		else :
			?>
			<span class="text-nowrap mb-2 d-inline-block">
				<i class="ri-discuss-line text-muted"></i>
				<b>0</b>
			</span>
			<?php
		endif;
	}

	/**
	 * Echoes the labels counter, with a popover trigger when labels exist.
	 *
	 * @return void
	 */
	private function render_card_labels_counter(): void {
		$labels_count = count( $this->task->labels );
		if ( $labels_count > 0 ) :
			/* translators: %d is the number of labels on the task. */
			$labels_screen_reader = sprintf( _n( '%d label', '%d labels', $labels_count, 'decker' ), $labels_count );
			$close_aria_label     = __( 'Close', 'decker' );

			$labels_list  = '<button type="button" class="btn-close decker-labels-popover-close" aria-label="' . esc_attr( $close_aria_label ) . '"></button>';
			$labels_list .= '<div class="decker-labels-popover-list">';
			foreach ( $this->task->labels as $label ) {
				$labels_list .= '<span class="badge" style="background-color: ' . esc_attr( $label->color ) . ';">' . esc_html( $label->name ) . '</span>';
			}
			$labels_list .= '</div>';
			?>
			<span class="ps-2 text-nowrap mb-2 d-inline-flex align-items-center decker-labels-popover"
				role="button"
				tabindex="0"
				data-decker-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
				data-decker-labels-count="<?php echo esc_attr( $labels_count ); ?>"
				data-decker-labels-content="<?php echo esc_attr( $labels_list ); ?>"
				aria-label="<?php echo esc_attr( $labels_screen_reader ); ?>">
				<i class="ri-price-tag-3-line text-muted me-1"></i>
				<b><?php echo esc_html( $labels_count ); ?></b>
			</span>
			<?php
		else :
			?>
			<span class="ps-2 text-nowrap mb-2 d-inline-block">
				<i class="ri-price-tag-3-line text-muted me-1"></i>
				<b>0</b>
			</span>
			<?php
		endif;
	}

	/**
	 * Renders the people avatar group for the task.
	 *
	 * @return void
	 */
	public function render_people_avatars(): void {
		echo '<div class="avatar-group">';

		foreach ( ( new Decker_Task_People_View( $this->task ) )->get_people_users() as $user ) {
			$is_responsable = $this->task->responsable instanceof WP_User
				&& $this->task->responsable->ID === $user->ID;
			$classes        = 'avatar-group-item position-relative';

			if ( ! empty( $user->today ) ) {
				$classes .= ' today';
			}

			if ( $is_responsable ) {
				$classes .= ' avatar-group-item-responsable';
			}

			echo '<a href="javascript: void(0);" class="' . esc_attr( $classes ) . '"';
			echo ' data-bs-toggle="tooltip" data-bs-placement="top"';
			echo ' aria-label="' . esc_attr( $user->display_name ) . '"';
			echo ' data-bs-original-title="' . esc_attr( $user->display_name ) . '"';
			echo ' title="' . esc_attr( $user->display_name ) . '">';
			echo '<span class="d-none">' . esc_html( $user->display_name ) . '</span>';

			if ( $is_responsable ) {
				echo '<span class="badge badge_avatar"><i class="ri-star-s-fill"></i></span>';
			}

			echo '<img src="' . esc_url( get_avatar_url( $user->ID ) ) . '"';
			echo ' alt="' . esc_attr( $user->display_name ) . '"';
			echo ' class="rounded-circle avatar-xs">';
			echo '</a>';
		}

		echo '</div>';
	}

	/**
	 * Retrieves the relative time for the task's due date.
	 *
	 * @return string The relative time as a human-readable string.
	 */
	public function get_relative_time(): string {
		if ( ! $this->task->duedate instanceof DateTime ) {
			return __( 'No due date', 'decker' );
		}

		$due_date = clone $this->task->duedate;
		$due_date->setTime( 0, 0, 0 ); // Ignore time.

		$today = new DateTime( 'today' );
		$yesterday = ( clone $today )->modify( '-1 day' );
		$tomorrow = ( clone $today )->modify( '+1 day' );

		if ( $due_date == $today ) {
			return __( 'Today', 'decker' );
		} elseif ( $due_date == $yesterday ) {
			return __( 'Yesterday', 'decker' );
		} elseif ( $due_date == $tomorrow ) {
			return __( 'Tomorrow', 'decker' );
		} else {
			$now = current_time( 'timestamp' ); // Wordpress current time.
			$due_timestamp = $due_date->getTimestamp();
			$diff_days = $today->diff( $due_date )->days;

			// Use human_time_diff.
			if ( $due_date < $today ) {
				// Translators: %s is the time elapsed (e.g., "2 hours", "3 days").
				return sprintf( __( '%s ago', 'decker' ), human_time_diff( $due_timestamp, $now ) );

			} else {
				// Translators: %s is the time remaining until the due date (e.g., "in 2 hours", "in 3 days").
				return sprintf( __( 'in %s', 'decker' ), human_time_diff( $now, $due_timestamp ) );
			}
		}
	}

	/**
	 * Get the raw formatted date for sorting
	 *
	 * Checks if the 'duedate' property is a DateTime object or a string
	 * and formats it as 'Y-m-d'. Returns an empty string if 'duedate' is not set.
	 *
	 * @return string Date in Y-m-d format
	 */
	public function get_formatted_date(): string {
		return $this->task->duedate ? $this->task->duedate->format( 'Y-m-d' ) : '';
	}

	/**
	 * Get a "pastelized" version of a color, making it softer for background usage.
	 *
	 * @param string $color An HTML hex color (e.g., '#ff0000').
	 *
	 * @return string HTML value of the pastelized color in hex format (e.g., '#ffcccc').
	 */
	public static function pastelize_color( ?string $color ): string {
		// Remove '#' if present.
		$color = ltrim( $color, '#' );

		// Ensure it's a valid 6-character hex color.
		if ( 6 !== strlen( $color ) ) {
			return '#cccccc'; // Default fallback to light gray if input is invalid.
		}

		// Convert hex color to RGB values.
		$r = hexdec( substr( $color, 0, 2 ) );
		$g = hexdec( substr( $color, 2, 2 ) );
		$b = hexdec( substr( $color, 4, 2 ) );

		// Pastelize by averaging with white (255, 255, 255).
		$r = round( ( $r + 255 ) / 2 );
		$g = round( ( $g + 255 ) / 2 );
		$b = round( ( $b + 255 ) / 2 );

		// Convert back to hex.
		$pastel_color = sprintf( '#%02x%02x%02x', $r, $g, $b );

		return $pastel_color;
	}
}
