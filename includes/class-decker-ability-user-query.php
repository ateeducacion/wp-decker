<?php
/**
 * User lookups for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves user names to IDs for the agent surface.
 */
class Decker_Ability_User_Query {

	/**
	 * List users who can be set as an assignee or responsible user.
	 *
	 * Core's /wp/v2/users route cannot serve this: `list_users` is an
	 * administrator capability, so an ordinary Decker user receives HTTP 200
	 * with an empty array — which reads as "that person does not exist" and
	 * invites picking the wrong user. Decker's own task editor already shows
	 * every user's display name to anyone holding `edit_posts`, so this ability
	 * exposes exactly that, and only the fields needed to resolve a name.
	 *
	 * @param array|null $input Ability input.
	 * @return array<string, mixed> User collection.
	 */
	public function list_users( $input ): array {
		$input = wp_parse_args(
			is_array( $input ) ? $input : array(),
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 50,
			)
		);

		$search   = sanitize_text_field( $input['search'] );
		$page     = max( 1, absint( $input['page'] ) );
		$per_page = min( 100, max( 1, absint( $input['per_page'] ) ) );

		$arguments = array(
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'number'      => $per_page,
			'paged'       => $page,
			'count_total' => true,
			'fields'      => array( 'ID', 'display_name', 'user_nicename' ),
		);

		if ( '' !== $search ) {
			$arguments['search']         = '*' . $search . '*';
			// Email is deliberately not searchable: it would make this ability an
			// "is this address registered?" oracle, and a display name, login or
			// slug is enough to resolve a colleague.
			$arguments['search_columns'] = array( 'user_login', 'user_nicename', 'display_name' );
		}

		$query = new WP_User_Query( $arguments );
		$total = (int) $query->get_total();

		$users = array_map(
			static function ( $user ): array {
				return array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
					'slug' => (string) $user->user_nicename,
				);
			},
			(array) $query->get_results()
		);

		return array(
			'users'       => $users,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
}
