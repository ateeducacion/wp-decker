<?php
/**
 * File class-task
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Task
 *
 * Represents a custom post type `decker_task`.
 */
class Task {

	/**
	 * The ID of the task.
	 *
	 * @var int
	 */
	public int $ID = 0;

	/**
	 * The title of the task.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * The description of the task.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * The status of the task (e.g., 'published', 'archived', ...).
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * The stack the task belongs to (default is 'to-do').
	 *
	 * @var string|null
	 */
	public ?string $stack = 'to-do';

	/**
	 * Whether the task has maximum priority.
	 *
	 * @var bool
	 */
	public bool $max_priority = false;

	/**
	 * The due date of the task, or null if not set.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $duedate = null;

	/**
	 * An array of user IDs assigned to the task.
	 *
	 * @var array
	 */
	public array $assigned_users = array();

	/**
	 * The ID of the user who created the task.
	 *
	 * @var int
	 */
	public int $author;

	/**
	 * The user responsible for the task.
	 *
	 * This may be null if get_userdata() fails.
	 *
	 * @var WP_User|null
	 */
	public ?WP_User $responsable = null;

	/**
	 * Whether the task is hidden in listings.
	 *
	 * @var bool
	 */
	public bool $hidden = false;

	/**
	 * The order of the task within its stack.
	 *
	 * @var int
	 */
	public int $order = 0;

	/**
	 * The board the task is associated with, or null if not set.
	 *
	 * @var Board|null
	 */
	public ?Board $board = null;

	/**
	 * An array of labels associated with the task.
	 *
	 * @var array
	 */
	public array $labels = array();

	/**
	 * An array of attachments associated with the task.
	 *
	 * @var array
	 */
	public array $attachments = array();

	/**
	 * An array of custom metadata associated with the task.
	 *
	 * @var array
	 */
	public array $meta = array();

	/**
	 * Task constructor.
	 *
	 * Initializes the task object from an ID or WP_Post object.
	 *
	 * @param int|WP_Post|null $input The ID of the task or a WP_Post object.
	 *                                Null if creating a new task.
	 * @throws Exception If the input is not a valid ID or WP_Post object.
	 */
	public function __construct( $input = null ) {

		if ( $input instanceof WP_Post ) {
			$post = $input;
		} elseif ( is_int( $input ) && $input > 0 ) {
			$post = get_post( $input );
		} else {
			$this->author = get_current_user_id(); // Default author.
			$post         = false;
		}

		if ( $post ) {

			if ( 'decker_task' !== $post->post_type ) {
				throw new Exception( esc_attr_e( 'Invalid post type.', 'decker' ) );
			}

			$this->ID          = $post->ID;
			$this->title       = (string) $post->post_title;
			$this->description = (string) $post->post_content;
			$this->status      = (string) $post->post_status;
			$this->author      = $post->post_author;
			$this->order       = (int) $post->menu_order;

			// Load all metadata once.
			$meta = get_post_meta( $this->ID );

			$responsable_id = isset( $meta['responsable'][0] ) ? (int) $meta['responsable'][0] : $post->post_author;
			$user_object    = get_userdata( $responsable_id );

			// Only assign if $user_object is a WP_User.
			if ( $user_object instanceof WP_User ) {
				$this->responsable = $user_object;
			}

			$this->hidden = isset( $meta['hidden'][0] ) && '1' === $meta['hidden'][0];

			// Use the meta array directly.
			$this->stack        = isset( $meta['stack'][0] ) ? (string) $meta['stack'][0] : null;
			$this->max_priority = isset( $meta['max_priority'][0] ) && '1' === $meta['max_priority'][0];

			// Convert duedate to a DateTime object if set.
			$this->duedate = isset( $meta['duedate'][0] ) ? new DateTime( $meta['duedate'][0] ) : null;

			$this->attachments = isset( $meta['attachments'] ) ? (array) $meta['attachments'] : array();
			$this->meta        = $meta; // Store all meta in case you need it later.

			$this->assigned_users = $this->get_users( $meta );

			// Load taxonomies.
			$this->board  = $this->get_board();
			$this->labels = $this->get_labels();

		}
	}

	/**
	 * Retrieves the term associated with the `decker_board` taxonomy.
	 *
	 * @return Board|null The Board or null if no term is assigned.
	 */
	public function get_board(): ?Board {
		$terms = wp_get_post_terms( $this->ID, 'decker_board' );

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			return new Board( $terms[0] );
		}
		return null;
	}

	/**
	 * Retrieves terms associated with the `decker_label` taxonomy.
	 *
	 * @return Label[] List of Label objects.
	 */
	private function get_labels(): array {
		$terms  = wp_get_post_terms( $this->ID, 'decker_label' );
		$labels = array();
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$labels[] = new Label( $term );
			}
		}
		return $labels;
	}

	/**
	 * Converts an array of user IDs from meta into WP_User objects and adds a `today` property.
	 *
	 * @param array $meta Meta data array containing user IDs.
	 * @return array Array of WP_User objects with an added `today` property.
	 */
	private function get_users( array $meta ): array {
		$users = array();
		if ( isset( $meta['assigned_users'][0] ) ) {
			$user_ids = maybe_unserialize( $meta['assigned_users'][0] );

			foreach ( $user_ids as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					// Add custom `today` property.
					$user->today = $this->is_today_assigned( $user_id, $meta );
					$users[]     = $user;
				}
			}
		}
		return $users;
	}

	/**
	 * Checks if the current user is assigned to the task.
	 *
	 * Iterates through the list of assigned users and compares their IDs
	 * with the current user's ID to determine if the user is assigned.
	 *
	 * @return bool True if the current user is assigned, false otherwise.
	 */
	public function is_current_user_assigned_to_task() {

		$current_user_id = get_current_user_id();

		foreach ( $this->assigned_users as $user ) {

			if ( $current_user_id == $user->ID ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the current user is assigned to the task for today.
	 *
	 * Uses the current user's ID and task metadata to determine if the user
	 * is specifically assigned to the task for today.
	 *
	 * @return bool True if the current user is assigned for today, false otherwise.
	 */
	public function is_current_user_today_assigned() {
		return $this->is_today_assigned( get_current_user_id(), $this->meta );
	}

	/**
	 * Determines if the user should have the `today` flag set to true based on `_user_date_relations` meta.
	 *
	 * @param int   $user_id The user ID.
	 * @param array $meta Meta data array containing `_user_date_relations`.
	 * @return bool True if the user is assigned for today, false otherwise.
	 */
	private function is_today_assigned( int $user_id, array $meta ): bool {

		if ( isset( $meta['_user_date_relations'][0] ) ) {

			$user_date_relations = maybe_unserialize( $meta['_user_date_relations'][0] );

			if ( $user_date_relations && is_array( $user_date_relations ) ) {

				$today = ( new DateTime() )->format( 'Y-m-d' ); // Get today's date in 'Y-m-d' format.

				foreach ( $user_date_relations as $relation ) {

					if ( isset( $relation['user_id'], $relation['date'] ) && $relation['user_id'] == $user_id && $relation['date'] === $today ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get a "pastelized" version of a color, making it softer for background usage.
	 *
	 * @param string $color An HTML hex color (e.g., '#ff0000').
	 *
	 * @return string HTML value of the pastelized color in hex format (e.g., '#ffcccc').
	 */
	public function pastelize_color( ?string $color ): string {
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


	/**
	 * Retrieves a historical record of users and their assigned dates as user objects.
	 *
	 * @return array An array of historical records with user objects and dates.
	 */
	public function get_user_history_with_objects(): array {
		$history = array();

		if ( isset( $this->meta['_user_date_relations'][0] ) ) {
			$user_date_relations = maybe_unserialize( $this->meta['_user_date_relations'][0] );

			if ( $user_date_relations && is_array( $user_date_relations ) ) {
				foreach ( $user_date_relations as $relation ) {
					if ( isset( $relation['user_id'], $relation['date'] ) ) {
						// Retrieve WordPress user object.
						$user = get_userdata( $relation['user_id'] );
						if ( $user ) {
							$history[] = array(
								'user' => $user,
								'date' => $relation['date'],
							);
						}
					}
				}
			}
		}

		return $history;
	}


	/**
	 * Retrieves the relative time for the task's due date.
	 *
	 * @return string The relative time as a human-readable string.
	 */
	public function get_relative_time(): string {
		return Decker::get_relative_time( $this->duedate );
	}

	/**
	 * Converts the due date of the task to a formatted string.
	 *
	 * Checks if the 'duedate' property is a DateTime object or a string
	 * and formats it as 'Y-m-d'. Returns an empty string if 'duedate' is not set.
	 *
	 * @return string The formatted due date as 'Y-m-d', or an empty string if not set.
	 */
	public function get_duedate_as_string(): string {

		// Initialize $duedate to an empty string.
		$duedate = '';

		// Check if 'duedate' property exists and is a DateTime object.
		if ( isset( $this->duedate ) && $this->duedate instanceof DateTime ) {
			// Format the DateTime object to 'Y-m-d'.
			$duedate = $this->duedate->format( 'Y-m-d' );
		} elseif ( isset( $this->duedate ) && is_string( $this->duedate ) ) {
			// If 'duedate' is a string, attempt to parse it to 'Y-m-d'.
			$date = date_create( $this->duedate );
			if ( $date ) {
				$duedate = $date->format( 'Y-m-d' );
			}
		}

		return $duedate;
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
				'id'          => esc_attr( $this->ID ),
			),
			home_url( '/' )
		);
		$priority_badge_class = $this->max_priority ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary';
		$priority_label      = $this->max_priority ? __( '🔥', 'decker' ) : __( 'Normal', 'decker' );
		$formatted_duedate  = $this->get_duedate_as_string();
		$relative_time      = '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . __( 'Undefined date', 'decker' ) . '</span>';

		if ( ! empty( $this->duedate ) ) {
			// $relative_time = esc_html( $this->get_relative_time() );
			$relative_time = $formatted_duedate;
		}

		$card_background_color = '';
		if ( $draw_background_color && $this->board && $this->board->color ) {
			$board_color           = $this->pastelize_color( $this->board->color );
			$card_background_color = 'style="background-color: ' . esc_attr( $board_color ) . ';"';
		} elseif ( $this->hidden ) {
			// For hidden tasks, we set a light gray color.
			$card_background_color = 'style="background-color: gainsboro;"';
		}

		?>
		<div class="task card mb-0" data-task-id="<?php echo esc_attr( $this->ID ); ?>" <?php echo wp_kses_post( $card_background_color ); ?>>
			<div class="card-body p-3">
				<span class="float-end badge <?php echo esc_attr( $priority_badge_class ); ?>">
					<span class="label-to-hide"><?php echo esc_html( $priority_label ); ?></span>
					<span class="menu-order label-to-show" style="display: none;"><?php esc_html_e( 'Order:', 'decker' ); ?> <?php echo esc_html( $this->order ); ?></span>
				</span>

				<small class="text-muted relative-time-badge" title="<?php echo esc_attr( $formatted_duedate ); ?>">
					<span class="task-id label-to-hide"  
							<?php
								if ( isset( $this->duedate ) && $this->duedate instanceof DateTime ){
									$now=new DateTime("now");
									if($now->diff($this->duedate)->invert==1){
										echo 'style="color: var(--ct-danger-text-emphasis)"';
									}
								}
								
							?>
						>
						<?php echo wp_kses_post( $relative_time ); ?>
					</span>
					<span class="task-id label-to-show" style="display: none;">#<?php echo esc_html( $this->ID ); ?></span>
				</small>

				<h5 class="my-2 fs-16" id="task-<?php echo esc_attr( $this->ID ); ?>">
					<a href="<?php echo esc_url( $task_url ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="text-body" data-task-id="<?php echo esc_attr( $this->ID ); ?>">
						<?php echo esc_html( $this->title ); ?>
					</a>
				</h5>

				<p class="mb-0">
					<span class="pe-2 text-nowrap mb-2 d-inline-block">
						<i class="ri-briefcase-2-line text-muted"></i>                       
						<?php

						if ( $draw_background_color && $this->board && $this->board->color ) {
							echo esc_html( $this->board->name );
						}
						?>
					</span>
					<span class="text-nowrap mb-2 d-inline-block">
						<i class="ri-discuss-line text-muted"></i>
						<b><?php echo esc_html( get_comments_number( $this->ID ) ); ?></b> <?php esc_html_e( 'Comments', 'decker' ); ?>
					</span>
				</p>

				<?php $this->render_task_menu(); ?>

				<div class="avatar-group mt-2">
					<?php foreach ( $this->assigned_users as $user_info ) : ?>
						<a href="#" class="avatar-group-item <?php echo $user_info->today ? ' today' : ''; ?>"
						   data-bs-toggle="tooltip" data-bs-placement="top"
						   title="<?php echo esc_attr( $user_info->display_name ); ?>">
							<img src="<?php echo esc_url( get_avatar_url( $user_info->ID ) ); ?>" alt=""
								 class="rounded-circle avatar-xs">
						</a>
					<?php endforeach; ?>
				</div>
			</div> <!-- end card-body -->
		</div>
		<?php
	}


	/**
	 * Render the task card contextual menu.
	 *
	 * @param bool $card The menu is being drawed in a card. Defaults to false.
	 */
	public function render_task_menu( bool $card = false ): void {
		$menu_items = array();

		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id'          => esc_attr( $this->ID ),
			),
			home_url( '/' )
		);

		/*
		TO-DO: Study if this is useful, we can use the next option to get the link
		// Add 'Share URL' menu item at the top.
		// $menu_items[] = sprintf(
		//  '<a href="%s" class="dropdown-item"><i class="ri-share-line me-1"></i>' . __( 'View Task', 'decker' ) . '</a>',
		//  esc_url( $task_url )
		// );

		// // Add divider after Share URL.
		// $menu_items[] = '<div class="dropdown-divider"></div>';
		*/

		if ( ! $card ) {
			// Add 'Edit' menu item.
			$menu_items[] = sprintf(
				'<a href="%s" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="%d" class="dropdown-item"><i class="ri-edit-box-line me-1"></i>' . __( 'Edit', 'decker' ) . '</a>',
				esc_url(
					add_query_arg(
						array(
							'decker_page' => 'task',
							'id'          => esc_attr( $this->ID ),
						),
						home_url( '/' )
					)
				),
				esc_attr( $this->ID )
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			// Add 'Edit in WordPress' menu item.
			$menu_items[] = sprintf(
				'<a href="%s" class="dropdown-item" target="_blank"><i class="ri-wordpress-line me-1"></i>' . __( 'Edit in WordPress', 'decker' ) . '</a>',
				esc_url( get_edit_post_link( $this->ID ) )
			);
		}

		// Add 'Archive' menu item.
		$menu_items[] = sprintf(
			'<a href="#" class="dropdown-item archive-task" data-task-id="%d"><i class="ri-archive-line me-1"></i>' . __( 'Archive', 'decker' ) . '</a>',
			esc_attr( $this->ID )
		);

		if ( ! $card ) {

			// Add 'Assign to me' and 'Leave' menu items based on assigned users.
			$is_assigned  = in_array( get_current_user_id(), array_column( $this->assigned_users, 'ID' ) );
			$menu_items[] = sprintf(
				'<a href="#" class="dropdown-item assign-to-me %s" data-task-id="%d"><i class="ri-user-add-line me-1"></i>' . __( 'Assign to me', 'decker' ) . '</a>',
				$is_assigned ? 'hidden' : '',
				esc_attr( $this->ID ),
			);

			// Add 'Leave' menu item.
			$menu_items[] = sprintf(
				'<a href="#" class="dropdown-item leave-task %s" data-task-id="%d"><i class="ri-logout-circle-line me-1"></i>' . __( 'Leave', 'decker' ) . '</a>',
				! $is_assigned ? 'hidden' : '',
				esc_attr( $this->ID ),
			);

			// Add 'Mark for today' / 'Unmark for today' menu items for assigned users with 'today' flag.
			$is_marked_for_today = false;
			foreach ( $this->assigned_users as $user ) {
				if ( get_current_user_id() == $user->ID && ! empty( $user->today ) ) {
					$is_marked_for_today = true;
					break;
				}
			}

			$menu_items[] = sprintf(
				'<a href="#" class="dropdown-item mark-for-today %s" data-task-id="%d"><i class="ri-calendar-check-line me-1"></i>' . __( 'Mark for today', 'decker' ) . '</a>',
				! $is_assigned || $is_marked_for_today ? 'hidden' : '',
				esc_attr( $this->ID ),
			);

			$menu_items[] = sprintf(
				'<a href="#" class="dropdown-item unmark-for-today %s" data-task-id="%d"><i class="ri-calendar-close-line me-1"></i>' . __( 'Unmark for today', 'decker' ) . '</a>',
				! $is_marked_for_today ? 'hidden' : '',
				esc_attr( $this->ID ),
			);

		}

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
				'<div class="dropdown float-end mt-2">
		            <div class="dropdown-menu dropdown-menu-end">%s</div>
		        </div>',
				wp_kses_post( implode( '', $menu_items ) )
			);

		}
	}
}
