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
 * Authority for Decker decisions lives in a single JSON meta value
 * `_decker_edit_lock_state` of the form:
 * `{"user":123,"token":"uuid","time":1710000000}`.
 * Owner and generation token are always written together via compare-and-swap
 * so concurrent takeovers cannot leave a foreign token paired with a different
 * owner. `_edit_lock` / `_edit_last` are mirrored afterwards for WordPress
 * admin interoperability, but validation prefers the authoritative state.
 *
 * `time` is 0 when the lock has been released while the last token is retained
 * so a stale editor session is still rejected after the winner leaves.
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
	 * Authoritative lock state meta (JSON: user, token, time).
	 *
	 * @var string
	 */
	const STATE_META = '_decker_edit_lock_state';

	/**
	 * Legacy generation-only meta key (read as fallback, no longer written).
	 *
	 * @var string
	 */
	const GENERATION_META = '_decker_edit_lock_generation';

	/**
	 * Maximum CAS attempts for a single lock mutation.
	 *
	 * @var int
	 */
	const CAS_MAX_ATTEMPTS = 5;

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
			'generation'            => '',
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

		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$info = $this->get_lock_info( $post_id, $user_id );

			// Do not steal an active lock owned by another user.
			if ( $info['locked'] ) {
				return $info;
			}

			if ( $this->write_lock( $post_id, $user_id, false ) ) {
				return $this->get_lock_info( $post_id, $user_id );
			}
		}

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

		// Always issue a new generation token on takeover so the previous
		// editor's form session is invalidated even after this owner later
		// releases the lock.
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			if ( $this->write_lock( $post_id, $user_id, true ) ) {
				return $this->get_lock_info( $post_id, $user_id );
			}
		}

		return $this->get_lock_info( $post_id, $user_id );
	}

	/**
	 * Release the lock only when it is owned by the given user.
	 *
	 * Another user's lock is never removed by this method. The generation token
	 * is intentionally kept (time set to 0) so stale editor sessions remain invalid.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user releasing the lock.
	 * @return bool True when the lock was released, false otherwise.
	 */
	public function release_lock( int $post_id, int $user_id ): bool {
		if ( ! $this->is_enabled() || ! $this->is_supported_task( $post_id ) ) {
			return false;
		}

		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->read_authoritative_state( $post_id );
			$active  = $this->state_is_active( $current );

			if ( ! $active || (int) $current['user'] !== (int) $user_id ) {
				// Legacy mirror only: release native lock when we still own it.
				return $this->release_legacy_lock_if_owned( $post_id, $user_id );
			}

			$released = array(
				'user'  => (int) $user_id,
				'token' => (string) $current['token'],
				'time'  => 0,
			);

			if ( $this->cas_write_state( $post_id, $current, $released ) ) {
				delete_post_meta( $post_id, '_edit_lock' );
				return true;
			}
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
	 * @param int         $post_id            The task post ID.
	 * @param int         $user_id            The user attempting to save.
	 * @param string|null $session_generation Generation token embedded in the editor form, or null.
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

		if ( null !== $session_generation && '' !== (string) $session_generation ) {
			$current_generation = $this->get_generation( $post_id );
			if ( (string) $session_generation !== $current_generation ) {
				$message = ! empty( $info['message'] )
					? $info['message']
					: __( 'You can no longer save this card because another user has taken over editing. Please reload the card to see the latest changes.', 'decker' );

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
	 * Read the unique lock-generation token for a task.
	 *
	 * @param int $post_id The task post ID.
	 * @return string The current generation token, or empty string when never locked.
	 */
	public function get_generation( int $post_id ): string {
		$state = $this->read_authoritative_state( $post_id );
		if ( $state && '' !== $state['token'] ) {
			return $state['token'];
		}

		// Legacy single-key generation used before the atomic state meta.
		return (string) get_post_meta( $post_id, self::GENERATION_META, true );
	}

	/**
	 * Read the active lock (owner + time) from the authoritative state.
	 *
	 * Falls back to the native `_edit_lock` mirror when no Decker state exists
	 * (for example a lock set from the WordPress admin editor).
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The active lock, or null when free/released.
	 */
	private function read_lock( int $post_id ) {
		$state = $this->read_authoritative_state( $post_id );
		if ( $state ) {
			if ( ! $this->state_is_active( $state ) ) {
				return null;
			}

			return array(
				'time' => (int) $state['time'],
				'user' => (int) $state['user'],
			);
		}

		return $this->read_legacy_edit_lock( $post_id );
	}

	/**
	 * Write owner + generation as one CAS-protected state blob.
	 *
	 * @param int  $post_id         The task post ID.
	 * @param int  $user_id         The lock owner.
	 * @param bool $bump_generation Force a new generation token.
	 * @return bool True when the CAS write succeeded.
	 */
	private function write_lock( int $post_id, int $user_id, bool $bump_generation = false ): bool {
		$current = $this->read_authoritative_state( $post_id );

		/**
		 * Fires after the expected state is read and before the CAS write.
		 *
		 * Tests may use this to inject a concurrent CAS winner between read and write.
		 *
		 * @param int        $post_id The task post ID.
		 * @param array|null $current The expected previous state, or null.
		 * @param int        $user_id The user about to write the lock.
		 * @param bool       $bump    Whether a new generation token is forced.
		 */
		do_action( 'decker_task_lock_before_cas', $post_id, $current, $user_id, $bump_generation );

		// Re-read after the action so injected concurrent writes are observed and
		// used as the CAS expected value.
		$current = $this->read_authoritative_state( $post_id );

		// Non-forced acquire must not steal an active foreign lock after the barrier.
		if ( ! $bump_generation
			&& $this->state_is_active( $current )
			&& (int) $current['user'] !== (int) $user_id
			&& (int) $current['time'] > ( time() - $this->get_lock_window() ) ) {
			return false;
		}

		$active_same_owner = $this->state_is_active( $current )
			&& (int) $current['user'] === (int) $user_id
			&& (int) $current['time'] > ( time() - $this->get_lock_window() );

		$token = ( $active_same_owner && ! $bump_generation && '' !== $current['token'] )
			? $current['token']
			: wp_generate_uuid4();

		$new = array(
			'user'  => (int) $user_id,
			'token' => (string) $token,
			'time'  => time(),
		);

		if ( ! $this->cas_write_state( $post_id, $current, $new ) ) {
			return false;
		}

		$this->mirror_wp_lock( $post_id, $user_id );
		return true;
	}

	/**
	 * Read the authoritative Decker lock state, if present.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{user:int,token:string,time:int}|null The state, or null when absent.
	 */
	private function read_authoritative_state( int $post_id ) {
		$raw = get_post_meta( $post_id, self::STATE_META, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return array(
			'user'  => isset( $decoded['user'] ) ? (int) $decoded['user'] : 0,
			'token' => isset( $decoded['token'] ) ? (string) $decoded['token'] : '',
			'time'  => isset( $decoded['time'] ) ? (int) $decoded['time'] : 0,
		);
	}

	/**
	 * Whether a state blob represents an actively held lock.
	 *
	 * @param array|null $state The state (user/token/time), or null.
	 * @return bool True when the lock is actively held.
	 */
	private function state_is_active( $state ): bool {
		return is_array( $state )
			&& (int) $state['time'] > 0
			&& (int) $state['user'] > 0;
	}

	/**
	 * Encode lock state for storage. Key order is fixed for stable CAS comparisons.
	 *
	 * @param array $state The state (user/token/time).
	 * @return string JSON payload.
	 */
	private function encode_state( array $state ): string {
		return wp_json_encode(
			array(
				'user'  => (int) $state['user'],
				'token' => (string) $state['token'],
				'time'  => (int) $state['time'],
			)
		);
	}

	/**
	 * Compare-and-swap write of the authoritative lock state.
	 *
	 * @param int        $post_id  The task post ID.
	 * @param array|null $expected Previous state (user/token/time), or null when absent.
	 * @param array      $new      Desired state (user/token/time).
	 * @return bool True when this writer won the CAS.
	 */
	private function cas_write_state( int $post_id, $expected, array $new ): bool {
		$new_raw = $this->encode_state( $new );

		if ( null === $expected ) {
			// Unique add fails when another process created the key first.
			$added = add_post_meta( $post_id, self::STATE_META, $new_raw, true );
			return (bool) $added;
		}

		$expected_raw = $this->encode_state( $expected );
		// update_post_meta returns false when the previous value no longer matches.
		$updated = update_post_meta( $post_id, self::STATE_META, $new_raw, $expected_raw );

		return (bool) $updated;
	}

	/**
	 * Mirror the native WordPress edit-lock fields for admin interoperability.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The lock owner.
	 * @return void
	 */
	private function mirror_wp_lock( int $post_id, int $user_id ) {
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );
		update_post_meta( $post_id, '_edit_last', $user_id );
	}

	/**
	 * Parse the native `_edit_lock` meta (legacy / admin path).
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The parsed lock, or null when absent.
	 */
	private function read_legacy_edit_lock( int $post_id ) {
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
	 * Release a legacy `_edit_lock` mirror when Decker state is absent.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user releasing the lock.
	 * @return bool True when the legacy lock was removed.
	 */
	private function release_legacy_lock_if_owned( int $post_id, int $user_id ): bool {
		$lock = $this->read_legacy_edit_lock( $post_id );
		if ( $lock && (int) $lock['user'] === (int) $user_id ) {
			delete_post_meta( $post_id, '_edit_lock' );
			return true;
		}

		return false;
	}
}
