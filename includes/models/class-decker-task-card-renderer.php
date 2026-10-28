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
	 * The task's subtask items, or null until they are first needed.
	 *
	 * @var array<int, array>|null
	 */
	private $subtasks = null;

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
		$task_url             = $this->get_task_url();
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
						<b><?php echo esc_html( count( get_attached_media( '', $this->task->ID ) ) + count( get_post_meta( $this->task->ID, '_decker_attachment_link' ) ) ); ?></b>
					</span>
					<?php
					$this->render_card_labels_counter();
					$this->render_card_subtasks_progress();
					?>
				</p>

				<?php $this->render_card_subtask_chips(); ?>

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
	 * The task's subtask items, loaded once per card.
	 *
	 * Empty when the feature is off, so every caller can treat "no subtasks"
	 * and "subtasks disabled" the same way.
	 *
	 * @return array<int, array> The subtask items.
	 */
	private function get_subtasks(): array {
		if ( null === $this->subtasks ) {
			$this->subtasks = Decker_Subtasks::is_enabled()
				? Decker_Subtasks::get( $this->task->ID )
				: array();
		}

		return $this->subtasks;
	}

	/**
	 * Echoes the row of subtask chips under the card's counters.
	 *
	 * Only a few open items get a chip — see
	 * Decker_Subtasks::select_card_chips() for which — so the card grows by at
	 * most two lines whether the task was split into three steps or sixty.
	 *
	 * @return void
	 */
	public function render_card_subtask_chips(): void {
		$items = $this->get_subtasks();
		if ( empty( $items ) ) {
			return;
		}

		$viewer    = get_current_user_id();
		$selection = Decker_Subtasks::select_card_chips( $items, $viewer );
		if ( empty( $selection['chips'] ) ) {
			return;
		}

		$task_url = add_query_arg( 'decker_tab', 'subtasks', $this->get_task_url() );
		?>
		<div class="decker-subtask-chips">
			<?php foreach ( $selection['chips'] as $item ) : ?>
				<?php $mine = $viewer > 0 && $viewer === (int) $item['assignee']; ?>
				<a href="<?php echo esc_url( $task_url ); ?>"
					class="decker-subtask-chip<?php echo $mine ? ' decker-subtask-chip-mine' : ''; ?>"
					data-bs-toggle="modal"
					data-bs-target="#task-modal"
					data-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
					data-decker-open-tab="subtasks"
					title="<?php echo esc_attr( $this->get_subtask_chip_title( $item ) ); ?>">
					<span class="decker-subtask-dot decker-subtask-dot-<?php echo esc_attr( $item['status'] ); ?>"></span>
					<span class="decker-subtask-chip-label"><?php echo esc_html( $item['title'] ); ?></span>
				</a>
			<?php endforeach; ?>

			<?php if ( $selection['remaining'] > 0 ) : ?>
				<a href="<?php echo esc_url( $task_url ); ?>"
					class="decker-subtask-chip decker-subtask-chip-more"
					data-bs-toggle="modal"
					data-bs-target="#task-modal"
					data-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
					data-decker-open-tab="subtasks"
					title="
					<?php
					echo esc_attr(
						sprintf(
							/* translators: %d is the number of open subtasks the card has no room to show. */
							_n( '%d more open subtask', '%d more open subtasks', $selection['remaining'], 'decker' ),
							$selection['remaining']
						)
					);
					?>
					">+<?php echo esc_html( $selection['remaining'] ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The tooltip of one subtask chip: its full title, owner and due date.
	 *
	 * The chip itself truncates to one line, so the untruncated title has to be
	 * reachable without opening the card.
	 *
	 * @param array $item The subtask item.
	 * @return string The tooltip text.
	 */
	private function get_subtask_chip_title( array $item ): string {
		$parts = array( $item['title'] );

		if ( $item['assignee'] > 0 ) {
			$user = get_userdata( $item['assignee'] );
			if ( $user instanceof WP_User ) {
				$parts[] = $user->display_name;
			}
		}

		if ( '' !== $item['duedate'] ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $item['duedate'] );
			if ( $date instanceof DateTime ) {
				$parts[] = wp_date( get_option( 'date_format' ), $date->getTimestamp() );
			}
		}

		$parts[] = Decker_Tasks::get_stack_label( $item['status'] );

		return implode( ' · ', $parts );
	}

	/**
	 * The frontend URL of the task this card points at.
	 *
	 * @return string The task URL.
	 */
	private function get_task_url(): string {
		return add_query_arg(
			array(
				'decker_page' => 'task',
				'id'          => $this->task->ID,
			),
			home_url( '/' )
		);
	}

	/**
	 * Echoes the subtask progress counter, with a hover list of the items.
	 *
	 * Renders nothing when the feature is off or the task has no subtasks, so
	 * every card that never got any stays exactly as it was.
	 *
	 * The counter shows `done/total` plus a three-segment bar in the stack
	 * colors, instead of one chip per item: a task split into dozens of steps
	 * (the case this feature was built for) would otherwise bury the card. The
	 * individual items are in the hover list and in the card's Subtasks tab,
	 * which this counter links to.
	 *
	 * The hover list reuses the card popover plumbing added for labels — the
	 * `decker-labels-popover` class and `data-decker-labels-content`, both read
	 * by task-labels-popover.js. Renaming that hook to something neutral is the
	 * follow-up; it is not a reason to ship a second popover implementation.
	 *
	 * @return void
	 */
	public function render_card_subtasks_progress(): void {
		$items = $this->get_subtasks();
		if ( empty( $items ) ) {
			return;
		}

		$progress = Decker_Subtasks::progress( $items );

		/* translators: 1: number of completed subtasks, 2: total number of subtasks. */
		$summary = sprintf( __( '%1$d of %2$d subtasks done', 'decker' ), $progress['done'], $progress['total'] );
		?>
		<a href="<?php echo esc_url( add_query_arg( 'decker_tab', 'subtasks', $this->get_task_url() ) ); ?>"
			class="ps-2 text-nowrap mb-2 d-inline-flex align-items-center text-reset text-decoration-none decker-labels-popover"
			data-bs-toggle="modal"
			data-bs-target="#task-modal"
			data-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
			data-decker-open-tab="subtasks"
			data-decker-labels-content="<?php echo esc_attr( $this->build_subtasks_popover_content( $items ) ); ?>"
			aria-label="<?php echo esc_attr( $summary ); ?>">
			<i class="ri-list-check-2 text-muted me-1"></i>
			<b><?php echo esc_html( $progress['done'] . '/' . $progress['total'] ); ?></b>
			<?php $this->render_subtasks_bar( $progress ); ?>
		</a>
		<?php
	}

	/**
	 * Echoes the three-segment progress bar for a subtask list.
	 *
	 * @param array $progress The summary from Decker_Subtasks::progress().
	 * @return void
	 */
	private function render_subtasks_bar( array $progress ): void {
		$total = max( 1, (int) $progress['total'] );

		// Width of the first two segments is rounded; the trailing to-do segment
		// absorbs the remainder so the three always add up to exactly 100%.
		$widths = array(
			'done'        => (int) round( $progress['counts']['done'] * 100 / $total ),
			'in-progress' => (int) round( $progress['counts']['in-progress'] * 100 / $total ),
		);
		$widths['to-do'] = max( 0, 100 - $widths['done'] - $widths['in-progress'] );

		$segment_classes = array(
			'done'        => 'bg-success',
			'in-progress' => 'bg-warning',
			'to-do'       => 'bg-secondary',
		);
		?>
		<span class="progress ms-1 decker-subtasks-bar" role="presentation">
			<?php foreach ( $widths as $status => $width ) : ?>
				<?php if ( $width > 0 ) : ?>
					<span class="progress-bar <?php echo esc_attr( $segment_classes[ $status ] ); ?>" style="width: <?php echo esc_attr( $width ); ?>%"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</span>
		<?php
	}

	/**
	 * Builds the hover-list markup for the subtask popover.
	 *
	 * Long lists are truncated: the popover is a preview, and the Subtasks tab
	 * holds the editable list in full.
	 *
	 * @param array $items The subtask items.
	 * @return string The popover content markup.
	 */
	private function build_subtasks_popover_content( array $items ): string {
		$preview_limit = 12;
		$shown         = array_slice( $items, 0, $preview_limit );
		$remaining     = count( $items ) - count( $shown );

		$content  = '<button type="button" class="btn-close decker-labels-popover-close" aria-label="' . esc_attr__( 'Close', 'decker' ) . '"></button>';
		$content .= '<div class="decker-labels-popover-list decker-subtasks-popover-list">';

		foreach ( $shown as $item ) {
			$content .= '<span class="decker-subtask-chip">'
				. '<i class="' . esc_attr( Decker_Tasks::get_stack_icon_classes( $item['status'] ) ) . '"></i> '
				. esc_html( $item['title'] )
				. '</span>';
		}

		if ( $remaining > 0 ) {
			/* translators: %d is the number of subtasks not shown in the preview. */
			$more = sprintf( _n( 'and %d more subtask', 'and %d more subtasks', $remaining, 'decker' ), $remaining );

			$content .= '<span class="decker-subtask-chip text-muted">' . esc_html( $more ) . '</span>';
		}

		$content .= '</div>';

		return $content;
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
