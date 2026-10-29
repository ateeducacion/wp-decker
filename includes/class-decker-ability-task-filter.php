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
	 * Narrow a task query to the candidates the filters can possibly match.
	 *
	 * This only narrows: which user and which date a relation belongs to is
	 * decided by matches(), because a LIKE over a serialized array cannot tell
	 * whether two values belong to the same entry. Both halves live in this
	 * class so they cannot drift apart.
	 *
	 * @param array $arguments Query arguments to narrow.
	 * @param array $input     Normalized input.
	 * @return array<string, mixed> Narrowed query arguments.
	 */
	public function narrow( array $arguments, array $input ): array {
		$meta = array();

		if ( ! empty( $input['today'] ) ) {
			// Narrowed to this user and this date rather than every task that
			// has ever carried a relation: the PHP pass is capped, so without
			// this a user's own task could fall outside the candidate set on a
			// busy site. The value is a serialized array, so these are prefixes
			// of its records and matches() still decides. "i:5;" cannot match
			// "i:15;", because the terminator is part of the needle.
			$meta[] = array(
				'key'     => Decker_Task_Today_Manager::META_KEY,
				'value'   => sprintf( '"user_id";i:%d;', get_current_user_id() ),
				'compare' => 'LIKE',
			);
			$meta[] = array(
				'key'     => Decker_Task_Today_Manager::META_KEY,
				'value'   => sprintf( '"date";s:10:"%s";', Decker_Task_Date_Relations::today_string() ),
				'compare' => 'LIKE',
			);
		}

		if ( 'author' === $input['relation'] ) {
			// post_author is a real column, so this one constraint is exact.
			$arguments['author'] = get_current_user_id();
		}

		if ( 'assigned' === $input['relation'] ) {
			// LIKE '5' also matches the serialized "i:15", so matches() rechecks.
			$meta[] = array(
				'key'     => 'assigned_users',
				'value'   => (string) get_current_user_id(),
				'compare' => 'LIKE',
			);
		}

		if ( 'any' === $input['relation'] || 'responsible' === $input['relation'] ) {
			// These two cannot be a single WP_Query constraint: "any" is an OR
			// across a column and two meta keys, and "responsible" falls back to
			// the author when the meta is absent. Without narrowing, the PHP
			// pass had to scan a capped candidate set, so on a board with more
			// tasks than the cap a user's own task could be missing from their
			// own listing. The candidates are collected with cheap targeted
			// queries instead, which is exact and bounded by how many tasks
			// relate to this one user.
			$arguments['post__in'] = $this->related_task_ids( $input['relation'] );
		}

		if ( ! empty( $meta ) ) {
			$arguments['meta_query'] = array_merge(
				isset( $arguments['meta_query'] ) ? (array) $arguments['meta_query'] : array(),
				$meta
			);
		}

		return $arguments;
	}

	/**
	 * Collect the IDs of tasks related to the current user.
	 *
	 * Three targeted queries rather than one scan: tasks they authored, tasks
	 * where they are the stored responsible user, and tasks listing them as an
	 * assignee. matches() still re-checks every candidate, because the assignee
	 * query is a LIKE over a serialized array and the responsible fallback has
	 * to be resolved per task.
	 *
	 * @param string $relation Requested relation.
	 * @return int[] Candidate task IDs, or array( 0 ) when there are none.
	 */
	private function related_task_ids( string $relation ): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			// An impossible ID, so the listing is empty rather than unfiltered.
			return array( 0 );
		}

		$base = array(
			'post_type'              => 'decker_task',
			'post_status'            => array( 'publish', 'archived' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$queries = array(
			// Authored: also covers "responsible" when the meta is absent.
			array_merge( $base, array( 'author' => $user_id ) ),
			array_merge(
				$base,
				array(
					'meta_query' => array(
						array(
							'key'   => 'responsable',
							'value' => (string) $user_id,
						),
					),
				)
			),
		);

		if ( 'any' === $relation ) {
			$queries[] = array_merge(
				$base,
				array(
					'meta_query' => array(
						array(
							'key'     => 'assigned_users',
							'value'   => (string) $user_id,
							'compare' => 'LIKE',
						),
					),
				)
			);
		}

		$ids = array();
		foreach ( $queries as $arguments ) {
			$ids = array_merge( $ids, ( new WP_Query( $arguments ) )->posts );
		}

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		return empty( $ids ) ? array( 0 ) : $ids;
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
