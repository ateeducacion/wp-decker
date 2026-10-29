<?php
/**
 * Relation and "for today" filters for the task listing ability.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decides which tasks relate to the authenticated user.
 *
 * Three of Decker's rules cannot be expressed in SQL: the hidden-task
 * visibility rule, the responsible-user fallback to the post author, and the
 * "for today" relation, which lives inside a serialized meta value. Each one
 * therefore narrows the query in SQL and decides in PHP. The two halves live
 * together here so they cannot drift apart.
 */
class Decker_Ability_Task_Filter {

	/**
	 * Supported relations between a task and the authenticated user.
	 *
	 * @var string[]
	 */
	public const RELATIONS = array( 'any', 'assigned', 'responsible', 'author' );

	/**
	 * "For today" relation reader, created on first use.
	 *
	 * The existing manager is reused rather than reading the relation meta
	 * here, so a listing and a mark/unmark can never disagree about what
	 * "today" means.
	 *
	 * @var Decker_Task_Today_Manager|null
	 */
	private $today_manager = null;

	/**
	 * Normalize the filter input.
	 *
	 * @param array $input Raw input.
	 * @return array<string, mixed> Normalized relation and today values.
	 */
	public function normalize( array $input ): array {
		$relation = sanitize_key( $input['relation'] ?? '' );

		return array(
			// An unrecognized value is ignored rather than matching nothing, so
			// a typo can never be reported as "you have no tasks".
			'relation' => in_array( $relation, self::RELATIONS, true ) ? $relation : '',
			'today'    => rest_sanitize_boolean( $input['today'] ?? false ),
		);
	}

	/**
	 * Whether the listing has to be resolved in PHP rather than in SQL.
	 *
	 * @param array $input Normalized input.
	 * @return bool True when the PHP pass is required.
	 */
	public function requires_php_filter( array $input ): bool {
		return ! empty( $input['include_hidden'] ) || '' !== $input['relation'] || ! empty( $input['today'] );
	}

	/**
	 * Build the SQL constraints that narrow the candidate set.
	 *
	 * These only narrow: which user and which date a relation belongs to is
	 * decided by matches(), because a LIKE over a serialized array cannot tell
	 * whether two values belong to the same entry.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed> Constraints as {author, meta}.
	 */
	public function query_constraints( array $input ): array {
		$constraints = array(
			'author' => 0,
			'meta'   => array(),
		);

		if ( ! empty( $input['today'] ) ) {
			$constraints['meta'][] = array(
				'key'     => Decker_Task_Today_Manager::META_KEY,
				'compare' => 'EXISTS',
			);
		}

		if ( 'author' === $input['relation'] ) {
			// post_author is a real column, so this one constraint is exact.
			$constraints['author'] = get_current_user_id();
		}

		if ( 'assigned' === $input['relation'] ) {
			// LIKE '5' also matches the serialized "i:15", so matches() rechecks.
			$constraints['meta'][] = array(
				'key'     => 'assigned_users',
				'value'   => (string) get_current_user_id(),
				'compare' => 'LIKE',
			);
		}

		return $constraints;
	}

	/**
	 * Whether a task satisfies the relation and today filters.
	 *
	 * @param WP_Post $post  Task post.
	 * @param array   $input Normalized input.
	 * @return bool True when the task matches.
	 */
	public function matches( WP_Post $post, array $input ): bool {
		return $this->matches_relation( $post, (string) $input['relation'] )
			&& $this->matches_today( $post, ! empty( $input['today'] ) );
	}

	/**
	 * Whether a task relates to the authenticated user as requested.
	 *
	 * `responsible` honours the stored fallback: when no `responsable` meta is
	 * set, the task's author is its responsible user, exactly as the task
	 * formatter reports it. That fallback is why this cannot be a meta query.
	 *
	 * @param WP_Post $post     Task post.
	 * @param string  $relation Requested relation, empty for no filter.
	 * @return bool True when the task matches.
	 */
	private function matches_relation( WP_Post $post, string $relation ): bool {
		if ( '' === $relation ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( 'author' === $relation ) {
			return (int) $post->post_author === $user_id;
		}

		if ( 'responsible' === $relation ) {
			return $this->responsible_id( $post ) === $user_id;
		}

		if ( 'assigned' === $relation ) {
			return in_array( $user_id, $this->assignee_ids( $post ), true );
		}

		return (int) $post->post_author === $user_id
			|| $this->responsible_id( $post ) === $user_id
			|| in_array( $user_id, $this->assignee_ids( $post ), true );
	}

	/**
	 * Whether a task carries a "for today" relation for the current user.
	 *
	 * @param WP_Post $post  Task post.
	 * @param bool    $today Whether the filter is requested.
	 * @return bool True when the task matches.
	 */
	private function matches_today( WP_Post $post, bool $today ): bool {
		if ( ! $today ) {
			return true;
		}

		$user_id = get_current_user_id();

		return $user_id > 0 && $this->today_manager()->is_marked_for_today( $post->ID, $user_id );
	}

	/**
	 * Read a task's responsible user, falling back to its author.
	 *
	 * @param WP_Post $post Task post.
	 * @return int Responsible user ID.
	 */
	private function responsible_id( WP_Post $post ): int {
		$stored = absint( get_post_meta( $post->ID, 'responsable', true ) );

		return $stored > 0 ? $stored : (int) $post->post_author;
	}

	/**
	 * Read a task's assignee IDs.
	 *
	 * @param WP_Post $post Task post.
	 * @return int[] Assignee IDs.
	 */
	private function assignee_ids( WP_Post $post ): array {
		$stored = get_post_meta( $post->ID, 'assigned_users', true );

		return is_array( $stored ) ? array_map( 'absint', $stored ) : array();
	}

	/**
	 * Get the "for today" relation reader.
	 *
	 * @return Decker_Task_Today_Manager Relation reader.
	 */
	private function today_manager(): Decker_Task_Today_Manager {
		if ( null === $this->today_manager ) {
			$this->today_manager = new Decker_Task_Today_Manager();
		}

		return $this->today_manager;
	}
}
