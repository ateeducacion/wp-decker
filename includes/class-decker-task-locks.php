<?php
/**
 * File class-decker-task-locks
 *
 * WordPress-compatible edit locking for Decker task/card editing.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Locks
 *
 * Thin, WordPress-compatible wrapper around the native `_edit_lock` post meta
 * convention used by WordPress core to prevent concurrent post editing.
 *
 * The lock value is stored exactly like WordPress core stores it, as a
 * "{timestamp}:{user_id}" string in the `_edit_lock` meta key, and the stale
 * window is derived from the same `wp_check_post_lock_window` filter core uses.
 * This keeps Decker locks interoperable with the native WordPress edit screen
 * while remaining usable from the front-end app, where the admin-only
 * wp_check_post_lock()/wp_set_post_lock() helpers are not loaded.
 *
 * In addition to the active lock, Decker stores a monotonic lock generation in
 * `_decker_edit_lock_generation`. The generation is bumped whenever ownership
 * changes (including explicit takeovers). Editor sessions embed the generation
 * they opened with; a stale session is rejected on save even if the active lock
 * was later released (for example after the new owner closed the modal).
 *
 * The class only handles lock bookkeeping. Rendering, REST routing and AJAX
 * handling live in their own classes.
 */
class Decker_Task_Locks {

	/**
	 * The post type this manager is allowed to lock.
	 *
	 * @var string
	 */
	const POST_TYPE = 'decker_task';

	/**
	 * Post meta key for the monotonic lock generation counter.
	 *
	 * @var string
	 */
	const GENERATION_META = '_decker_edit_lock_generation';

	/**
	 * Get the stale-lock window in seconds.
	 *
	 * Mirrors WordPress core, which filters the default 150 second window via
	 * `wp_check_post_lock_window`.
	 *
	 * @return int The number of seconds a lock stays active without a refresh.
	 */
	public function get_lock_window(): int {
		return (int) apply_filters( 'wp_check_post_lock_window', 150 );
	}

	/**
	 * Determine whether edit locking is active.
	 *
	 * Locking is the fallback for concurrent editing. When Decker's real-time
	 * collaborative editing is enabled it already resolves concurrency through
	 * the shared CRDT, so locking stands down to avoid blocking a second editor
	 * from joining the collaborative session.
	 *
	 * @return bool True when locking should be enforced.
	 */
	public function is_enabled(): bool {
		$options = get_option( 'decker_settings', array() );

		$collaboration_enabled = ! empty( $options['collaborative_editing'] )
			&& '1' === $options['collaborative_editing'];

		return ! $collaboration_enabled;
	}

	/**
	 * Build the normalized lock information for a task from a viewer's point of view.
	 *
	 * @param int $post_id         The task post ID.
	 * @param int $current_user_id The user the information is computed for.
	 * @return array The normalized lock metadata.
	 */
	public function get_lock_info( int $post_id, int $current_user_id ): array {
		$base = array(
			'post_id'               => $post_id,
			'valid'                 => false,
			'locked'                => false,
			'owned_by_current_user' => false,
			'owner'                 => null,
			'is_stale'              => false,
			'can_take_over'         => false,
			'lock_window'           => $this->get_lock_window(),
			'generation'            => 0,
			'message'               => '',
		);

		// Unsupported post, or locking disabled: always report as unlocked.
		if ( ! $this->is_supported_task( $post_id ) ) {
			return $base;
		}

		$base['valid']      = true;
		$base['generation'] = $this->get_generation( $post_id );

		if ( ! $this->is_enabled() ) {
			return $base;
		}

		$lock = $this->read_lock( $post_id );
		if ( ! $lock ) {
			return $base;
		}

		$base['owner'] = $this->build_owner_info( $lock['user'] );

		// A lock older than the window is stale and does not block a new editor.
		if ( $lock['time'] <= ( time() - $this->get_lock_window() ) ) {
			$base['is_stale'] = true;
			return $base;
		}

		// Active lock owned by the viewer themselves.
		if ( $lock['user'] === $current_user_id ) {
			$base['owned_by_current_user'] = true;
			return $base;
		}

		// Active lock owned by another user.
		$base['locked']        = true;
		$base['can_take_over'] = user_can( $current_user_id, 'edit_post', $post_id );

		$owner_name = $base['owner'] ? $base['owner']['display_name'] : '';
		/* translators: %s is the display name of the user currently editing the card. */
		$base['message'] = sprintf( __( 'This card is currently locked by %s.', 'decker' ), $owner_name );

		return $base;
	}

	/**
	 * Build the public owner descriptor for a lock, or null when unavailable.
	 *
	 * Only the id and display name are exposed; private data such as the email
	 * address is never included.
	 *
	 * @param int $owner_id The lock owner user ID.
	 * @return array{id:int,display_name:string}|null The owner descriptor.
	 */
	private function build_owner_info( int $owner_id ) {
		if ( ! $owner_id ) {
			return null;
		}

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return null;
		}

		return array(
			'id'           => $owner_id,
			'display_name' => $owner->display_name,
		);
	}

	/**
	 * Acquire or refresh the lock for the given user.
	 *
	 * The lock is only written when the task is unlocked, already owned by the
	 * user, or held by a stale lock. An active lock owned by another user is
	 * never stolen; the caller receives the current lock info instead so it can
	 * offer an explicit takeover.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user acquiring the lock.
	 * @return array|WP_Error The lock info on success, or WP_Error on failure.
	 */
	public function acquire_lock( int $post_id, int $user_id ) {
		// Locking is disabled (collaborative editing takes over): report unlocked.
		if ( ! $this->is_enabled() ) {
			return $this->get_lock_info( $post_id, $user_id );
		}

		$guard = $this->guard_lock_operation( $post_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$info = $this->get_lock_info( $post_id, $user_id );

		// Do not steal an active lock owned by another user.
		if ( $info['locked'] ) {
			return $info;
		}

		$this->write_lock( $post_id, $user_id );

		return $this->get_lock_info( $post_id, $user_id );
	}

	/**
	 * Take over an active lock owned by another user.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user taking over the lock.
	 * @return array|WP_Error The lock info on success, or WP_Error on failure.
	 */
	public function take_over_lock( int $post_id, int $user_id ) {
		// Locking is disabled (collaborative editing takes over): report unlocked.
		if ( ! $this->is_enabled() ) {
			return $this->get_lock_info( $post_id, $user_id );
		}

		$guard = $this->guard_lock_operation( $post_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Always bump the generation on takeover so the previous editor's form
		// session is invalidated even after this owner later releases the lock.
		$this->write_lock( $post_id, $user_id, true );

		return $this->get_lock_info( $post_id, $user_id );
	}

	/**
	 * Release the lock only when it is owned by the given user.
	 *
	 * Another user's lock is never removed by this method. The generation meta
	 * is intentionally kept so stale editor sessions remain invalid.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user releasing the lock.
	 * @return bool True when the lock was released, false otherwise.
	 */
	public function release_lock( int $post_id, int $user_id ): bool {
		if ( ! $this->is_enabled() || ! $this->is_supported_task( $post_id ) ) {
			return false;
		}

		$lock = $this->read_lock( $post_id );
		if ( $lock && $lock['user'] === $user_id ) {
			delete_post_meta( $post_id, '_edit_lock' );
			return true;
		}

		return false;
	}

	/**
	 * Ensure the given user is allowed to save the task.
	 *
	 * A save is rejected when:
	 * - another user currently owns an active lock, or
	 * - the editor session submitted a lock generation that no longer matches
	 *   the server generation (the card was taken over, even if the lock has
	 *   since been released).
	 *
	 * When no session generation is provided the check falls back to the active
	 * lock only, which keeps admin/meta paths and older clients working.
	 *
	 * @param int      $post_id            The task post ID.
	 * @param int      $user_id            The user attempting to save.
	 * @param int|null $session_generation Generation embedded in the editor form, or null.
	 * @return true|WP_Error True when the save may proceed, WP_Error otherwise.
	 */
	public function assert_user_can_save( int $post_id, int $user_id, $session_generation = null ) {
		// When collaborative editing is enabled, locking does not gate saves.
		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( ! $this->is_supported_task( $post_id ) ) {
			return new WP_Error(
				'decker_invalid_task',
				__( 'Invalid task.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		$info = $this->get_lock_info( $post_id, $user_id );

		if ( $info['locked'] ) {
			return new WP_Error(
				'decker_task_locked',
				$info['message'],
				array(
					'status'     => 409,
					'owner'      => $info['owner'],
					'generation' => $info['generation'],
				)
			);
		}

		if ( null !== $session_generation ) {
			$current_generation = $this->get_generation( $post_id );
			if ( (int) $session_generation !== $current_generation ) {
				$message = ! empty( $info['message'] )
					? $info['message']
					: __( 'You can no longer save this card because another user has taken over editing.', 'decker' );

				return new WP_Error(
					'decker_task_locked',
					$message,
					array(
						'status'     => 409,
						'owner'      => $info['owner'],
						'generation' => $current_generation,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate a lock operation before it mutates state.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user performing the operation.
	 * @return true|WP_Error True when the operation may proceed, WP_Error otherwise.
	 */
	private function guard_lock_operation( int $post_id, int $user_id ) {
		if ( ! $this->is_supported_task( $post_id ) ) {
			return new WP_Error(
				'decker_invalid_task',
				__( 'Invalid task.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $user_id || ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'decker_task_cannot_edit',
				__( 'You are not allowed to edit this card.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Determine whether a post ID refers to an existing supported task.
	 *
	 * @param int $post_id The post ID to validate.
	 * @return bool True when the post exists and is a decker_task.
	 */
	private function is_supported_task( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type;
	}

	/**
	 * Read the monotonic lock generation for a task.
	 *
	 * @param int $post_id The task post ID.
	 * @return int The current generation (0 when never locked).
	 */
	public function get_generation( int $post_id ): int {
		return max( 0, (int) get_post_meta( $post_id, self::GENERATION_META, true ) );
	}

	/**
	 * Read and parse the native `_edit_lock` meta for a task.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The parsed lock, or null when absent.
	 */
	private function read_lock( int $post_id ) {
		$raw = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! $raw ) {
			return null;
		}

		$parts = explode( ':', (string) $raw );

		return array(
			'time' => isset( $parts[0] ) ? (int) $parts[0] : 0,
			'user' => isset( $parts[1] ) ? (int) $parts[1] : 0,
		);
	}

	/**
	 * Write the native `_edit_lock` meta for a task and record the last editor.
	 *
	 * The lock generation is bumped when ownership changes, or when the caller
	 * forces a bump (explicit takeover). Same-owner refreshes keep the generation
	 * so the open form session remains valid.
	 *
	 * @param int  $post_id         The task post ID.
	 * @param int  $user_id         The lock owner.
	 * @param bool $bump_generation Force a generation bump even for the same owner.
	 * @return void
	 */
	private function write_lock( int $post_id, int $user_id, bool $bump_generation = false ) {
		$previous      = $this->read_lock( $post_id );
		$owner_changed = ! $previous || (int) $previous['user'] !== (int) $user_id;

		if ( $bump_generation || $owner_changed ) {
			$generation = $this->get_generation( $post_id ) + 1;
			update_post_meta( $post_id, self::GENERATION_META, $generation );
		}

		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );
		update_post_meta( $post_id, '_edit_last', $user_id );
	}
}
