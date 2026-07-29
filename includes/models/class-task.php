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
 *
 * The 16 fields mirror the task's persisted attributes one-to-one; splitting
 * them into value objects would trade cohesion for indirection.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
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

		$post = $this->resolve_input( $input );

		if ( ! $post ) {
			return;
		}

		$this->load_from_post( $post );
	}

	/**
	 * Resolves the constructor input into a WP_Post, or false when creating a new task.
	 *
	 * @param int|WP_Post|null $input The ID of the task or a WP_Post object.
	 * @return WP_Post|null|false WP_Post when resolvable, otherwise false.
	 */
	private function resolve_input( $input ) {
		if ( $input instanceof WP_Post ) {
			return $input;
		}

		if ( is_int( $input ) && $input > 0 ) {
			return get_post( $input );
		}

		$this->author = get_current_user_id(); // Default author.

		return false;
	}

	/**
	 * Hydrates the task from a WP_Post object.
	 *
	 * @param WP_Post $post The source post.
	 * @return void
	 * @throws Exception If the post is not a `decker_task`.
	 */
	private function load_from_post( WP_Post $post ): void {

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

		$this->load_meta_fields( $post, $meta );

		$this->assigned_users = $this->get_users( $meta );

		// Load taxonomies.
		$this->board  = $this->get_board();
		$this->labels = $this->get_labels();
	}

	/**
	 * Hydrates the scalar meta-backed fields from the loaded meta array.
	 *
	 * @param WP_Post $post The source post.
	 * @param array   $meta The loaded post meta.
	 * @return void
	 */
	private function load_meta_fields( WP_Post $post, array $meta ): void {

		$responsable_id = isset( $meta['responsable'][0] ) ? (int) $meta['responsable'][0] : $post->post_author;
		$user_object    = get_userdata( $responsable_id );

		// Only assign if $user_object is a WP_User.
		if ( $user_object instanceof WP_User ) {
			$this->responsable = $user_object;
			 $this->responsable->today = $this->is_today_assigned( $responsable_id, $meta );
		}

		$this->hidden = isset( $meta['hidden'][0] ) && '1' === $meta['hidden'][0];

		// Use the meta array directly.
		$this->stack        = isset( $meta['stack'][0] ) ? (string) $meta['stack'][0] : null;
		$this->max_priority = isset( $meta['max_priority'][0] ) && '1' === $meta['max_priority'][0];

		// Convert duedate to a DateTime object if set.
		$this->duedate = isset( $meta['duedate'][0] ) ? new DateTime( $meta['duedate'][0] ) : null;

		$this->attachments = isset( $meta['attachments'] ) ? (array) $meta['attachments'] : array();
		$this->meta        = $meta; // Store all meta in case you need it later.
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
	 * Determines whether the current user is marked for today on this task.
	 *
	 * @return bool True when the current user is assigned with the today flag set.
	 */
	public function is_marked_for_today_for_current_user(): bool {
		// Check assigned users with 'today' flag for the current user.
		foreach ( $this->assigned_users as $user ) {
			if ( get_current_user_id() == $user->ID && ! empty( $user->today ) ) {
				return true;
			}
		}

		return false;
	}

}
