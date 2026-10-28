<?php
/**
 * Subtask rendering for a task card.
 *
 * Owns everything the board card and the task table show about a task's
 * subtasks: the done/total counter with its three-colour bar, the hover list,
 * and the row of chips. Kept out of Decker_Task_Card_Renderer the same way the
 * contextual menu and the people group are, so the card renderer stays the
 * card's own markup.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Subtask_Card_View
 */
class Decker_Subtask_Card_View {

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
	 * Store the task whose subtasks are rendered.
	 *
	 * @param Task $task The task being rendered.
	 */
	public function __construct( Task $task ) {
		$this->task = $task;
	}

	/**
	 * The frontend URL of the card, opened on its Subtasks tab.
	 *
	 * @return string The task URL.
	 */
	private function task_url(): string {
		return add_query_arg(
			array(
				'decker_page' => 'task',
				'id'          => $this->task->ID,
				'decker_tab'  => 'subtasks',
			),
			home_url( '/' )
		);
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
	public function render_chips(): void {
		$items = $this->get_subtasks();
		if ( empty( $items ) ) {
			return;
		}

		$viewer    = get_current_user_id();
		$selection = Decker_Subtasks::select_card_chips( $items, $viewer );
		if ( empty( $selection['chips'] ) ) {
			return;
		}

		$task_url = $this->task_url();
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
					title="<?php echo esc_attr( $this->get_chip_title( $item ) ); ?>">
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
	private function get_chip_title( array $item ): string {
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
	public function render_progress(): void {
		$items = $this->get_subtasks();
		if ( empty( $items ) ) {
			return;
		}

		$progress = Decker_Subtasks::progress( $items );

		/* translators: 1: number of completed subtasks, 2: total number of subtasks. */
		$summary = sprintf( __( '%1$d of %2$d subtasks done', 'decker' ), $progress['done'], $progress['total'] );
		?>
		<a href="<?php echo esc_url( $this->task_url() ); ?>"
			class="ps-2 text-nowrap mb-2 d-inline-flex align-items-center text-reset text-decoration-none decker-labels-popover"
			data-bs-toggle="modal"
			data-bs-target="#task-modal"
			data-task-id="<?php echo esc_attr( $this->task->ID ); ?>"
			data-decker-open-tab="subtasks"
			data-decker-labels-content="<?php echo esc_attr( $this->build_popover_content( $items ) ); ?>"
			aria-label="<?php echo esc_attr( $summary ); ?>">
			<i class="ri-list-check-2 text-muted me-1"></i>
			<b><?php echo esc_html( $progress['done'] . '/' . $progress['total'] ); ?></b>
			<?php $this->render_bar( $progress ); ?>
		</a>
		<?php
	}

	/**
	 * Echoes the three-segment progress bar for a subtask list.
	 *
	 * @param array $progress The summary from Decker_Subtasks::progress().
	 * @return void
	 */
	private function render_bar( array $progress ): void {
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
	private function build_popover_content( array $items ): string {
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
}
