<?php
/**
 * Contextual dropdown menu renderer for tasks.
 *
 * Owns the task's kanban/card contextual menu: URL resolution, the
 * admin/owner/archive/assignment item groups, and the dropdown wrapper
 * markup itself.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Menu_Renderer
 */
class Decker_Task_Menu_Renderer {

	/**
	 * The task the menu is rendered for.
	 *
	 * @var Task
	 */
	private $task;

	/**
	 * Store the task the menu is rendered for.
	 *
	 * @param Task $task The task the menu is rendered for.
	 */
	public function __construct( Task $task ) {
		$this->task = $task;
	}

	/**
	 * Render the task card contextual menu.
	 *
	 * @param bool $card The menu is being drawed in a card. Defaults to false.
	 */
	public function render_task_menu( bool $card = false ): void {
		$menu_items = array();

		$menu_items[] = sprintf(
			'<a href="#" class="dropdown-item copy-task-url" data-task-url="%s"><i class="ri-clipboard-line me-1"></i>%s</a>',
			esc_url( $this->get_menu_task_url() ),
			__( 'Copy Task URL', 'decker' )
		);

		if ( ! $card ) {
			// Add 'Edit' menu item.
			$menu_items[] = sprintf(
				'<a href="%s" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="%d" class="dropdown-item"><i class="ri-edit-box-line me-1"></i>' . __( 'Edit', 'decker' ) . '</a>',
				esc_url(
					add_query_arg(
						array(
							'decker_page' => 'task',
							'id'          => esc_attr( $this->task->ID ),
						),
						home_url( '/' )
					)
				),
				esc_attr( $this->task->ID )
			);
		}

		$menu_items = array_merge( $menu_items, $this->get_admin_menu_items() );
		$menu_items = array_merge( $menu_items, $this->get_owner_menu_items() );

		$menu_items[] = $this->get_archive_menu_item();

		if ( ! $card ) {
			$is_assigned         = in_array( get_current_user_id(), array_column( $this->task->assigned_users, 'ID' ) );
			$is_marked_for_today = $this->task->is_marked_for_today_for_current_user();

			$menu_items = array_merge(
				$menu_items,
				$this->get_assignment_menu_items( $is_assigned, $is_marked_for_today )
			);
		}

		$this->print_menu_dropdown( $menu_items, $card );
	}

	/**
	 * Resolves the task URL used for the copy-link menu item.
	 *
	 * @return string The permalink, or a query-arg fallback when unavailable.
	 */
	private function get_menu_task_url(): string {
		$task_url = get_permalink( $this->task->ID );
		if ( ! $task_url || is_wp_error( $task_url ) ) {
			$task_url = add_query_arg(
				array(
					'decker_page' => 'task',
					'id'          => esc_attr( $this->task->ID ),
				),
				home_url( '/' )
			);
		}

		return $task_url;
	}

	/**
	 * Returns the admin-only menu items.
	 *
	 * @return string[] The 'Edit in WordPress' item, or an empty array.
	 */
	private function get_admin_menu_items(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		// Add 'Edit in WordPress' menu item.
		return array(
			sprintf(
				'<a href="%s" class="dropdown-item" target="_blank"><i class="ri-wordpress-line me-1"></i>' . __( 'Edit in WordPress', 'decker' ) . '</a>',
				esc_url( get_edit_post_link( $this->task->ID ) )
			),
		);
	}

	/**
	 * Returns the owner (edit_post) menu items.
	 *
	 * @return string[] The Clone item, plus the Merge item when applicable.
	 */
	private function get_owner_menu_items(): array {
		if ( ! current_user_can( 'edit_post', $this->task->ID ) ) {
			return array();
		}

		// Add 'Clone' menu item.
		$items = array(
			sprintf(
				'<a href="#" class="dropdown-item clone-task" data-task-id="%d"><i class="ri-file-copy-line me-1"></i>' . __( 'Clone', 'decker' ) . '</a>',
				esc_attr( $this->task->ID )
			),
		);

		if ( 'publish' === $this->task->status &&
			! get_post_meta( $this->task->ID, 'merged_into', true ) ) {
			$items[] = sprintf(
				'<a href="#" class="dropdown-item merge-task" data-task-id="%1$d" data-task-title="%2$s"><i class="ri-git-merge-line me-1"></i>%3$s</a>',
				esc_attr( $this->task->ID ),
				esc_attr( $this->task->title ),
				__( 'Merge into...', 'decker' )
			);
		}

		return $items;
	}

	/**
	 * Returns the Archive item for published tasks, or the Unarchive item otherwise.
	 *
	 * @return string The archive/unarchive menu item HTML.
	 */
	private function get_archive_menu_item(): string {
		if ( 'publish' == $this->task->status ) {
			// Add 'Archive' menu item.
			return sprintf(
				'<a href="#" class="dropdown-item archive-task" data-task-id="%d"><i class="ri-archive-line me-1"></i>' . __( 'Archive', 'decker' ) . '</a>',
				esc_attr( $this->task->ID )
			);
		}

		// Add 'Unarchive' menu item.
		return sprintf(
			'<a href="#" class="dropdown-item unarchive-task" data-task-id="%d"><i class="ri-inbox-unarchive-line me-1"></i>' . __( 'Unarchive', 'decker' ) . '</a>',
			esc_attr( $this->task->ID )
		);
	}

	/**
	 * Returns the assignment-related menu items (assign / leave / mark / unmark).
	 *
	 * @param bool $is_assigned Whether the current user is assigned to the task.
	 * @param bool $is_marked_for_today Whether the current user is marked for today.
	 * @return string[] The four assignment menu items.
	 */
	private function get_assignment_menu_items( bool $is_assigned, bool $is_marked_for_today ): array {
		$items = array();

		// Add 'Assign to me' and 'Leave' menu items based on assigned users.
		$items[] = sprintf(
			'<a href="#" class="dropdown-item assign-to-me %s" data-task-id="%d"><i class="ri-user-add-line me-1"></i>' . __( 'Assign to me', 'decker' ) . '</a>',
			$is_assigned ? 'hidden' : '',
			esc_attr( $this->task->ID ),
		);

		// Add 'Leave' menu item.
		$items[] = sprintf(
			'<a href="#" class="dropdown-item leave-task %s" data-task-id="%d"><i class="ri-logout-circle-line me-1"></i>' . __( 'Leave', 'decker' ) . '</a>',
			! $is_assigned ? 'hidden' : '',
			esc_attr( $this->task->ID ),
		);

		// Add 'Mark for today' / 'Unmark for today' menu items for assigned users with 'today' flag.
		$items[] = sprintf(
			'<a href="#" class="dropdown-item mark-for-today %s" data-task-id="%d"><i class="ri-calendar-check-line me-1"></i>' . __( 'Mark for today', 'decker' ) . '</a>',
			! $is_assigned || $is_marked_for_today ? 'hidden' : '',
			esc_attr( $this->task->ID ),
		);

		$items[] = sprintf(
			'<a href="#" class="dropdown-item unmark-for-today %s" data-task-id="%d"><i class="ri-calendar-close-line me-1"></i>' . __( 'Unmark for today', 'decker' ) . '</a>',
			! $is_marked_for_today ? 'hidden' : '',
			esc_attr( $this->task->ID ),
		);

		return $items;
	}

	/**
	 * Prints the dropdown wrapper around the assembled menu items.
	 *
	 * @param string[] $menu_items The menu item HTML fragments.
	 * @param bool     $card Whether the menu is rendered inside a card.
	 * @return void
	 */
	private function print_menu_dropdown( array $menu_items, bool $card ): void {
		if ( ! $card ) {
			// Generate dropdown HTML for card.
			printf(
				'<div class="dropdown float-end mt-2">
		            <a href="#" class="dropdown-toggle text-muted arrow-none" data-bs-toggle="dropdown" aria-expanded="false">
		                <i class="ri-more-2-fill fs-18"></i>
		            </a>
		            <div class="dropdown-menu dropdown-menu-end">%s</div>
		        </div>',
				wp_kses_post( implode( '', $menu_items ) )
			);
		} else {
			printf(
				'<div class="dropdown-menu dropdown-menu-end">%s</div>',
				wp_kses_post( implode( '', $menu_items ) )
			);
		}
	}
}
