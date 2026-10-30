<?php
/**
 * The list-users ability.
 *
 * Resolving a name to a user ID is needed before any assignment, and core's
 * /wp/v2/users route cannot do it for an ordinary Decker user: `list_users` is
 * an administrator capability, so core answers HTTP 200 with an empty array.
 * An agent reading that would conclude the person does not exist. This ability
 * exposes the same list Decker's own task editor already shows to anyone
 * holding `edit_posts`, and only the fields needed to resolve a name.
 *
 * @package Decker
 */

class DeckerAbilitiesListUsersTest extends Decker_Test_Base {

	/**
	 * Ability service under test.
	 *
	 * @var Decker_Ability_Service
	 */
	private $service;

	/**
	 * An editor: an ordinary Decker user.
	 *
	 * @var int
	 */
	private $editor_id;

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->service   = new Decker_Ability_Service();
		$this->editor_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ada Lovelace',
				'user_login'   => 'ada',
			)
		);

		wp_set_current_user( $this->editor_id );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The regression this ability exists for.
	 */
	public function test_core_users_route_is_empty_for_an_ordinary_user() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_param( 'search', 'ada' );
		$response = rest_do_request( $request );

		// Documents why Decker does not use this route: not an error, just empty.
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, (array) $response->get_data() );
		$this->assertFalse( current_user_can( 'list_users' ) );
	}

	/**
	 * The ability finds the user that core could not.
	 */
	public function test_ability_finds_the_user() {
		$result = $this->service->list_users( array( 'search' => 'Ada' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame(
			array(
				'id'   => $this->editor_id,
				'name' => 'Ada Lovelace',
				'slug' => 'ada',
			),
			$result['users'][0]
		);
	}

	/**
	 * Only the fields needed to resolve a name are returned.
	 */
	public function test_no_private_user_data_is_exposed() {
		$result = $this->service->list_users( array() );

		foreach ( $result['users'] as $user ) {
			$this->assertSame( array( 'id', 'name', 'slug' ), array_keys( $user ) );
		}

		// Nothing anywhere in the payload may carry an address or a role.
		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( '@', $encoded );
		$this->assertStringNotContainsString( 'capabilities', $encoded );
		$this->assertStringNotContainsString( 'editor', $encoded );
	}

	/**
	 * An email address must not be searchable.
	 */
	public function test_email_is_not_searchable() {
		$target = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'user_email'   => 'secret.address@example.org',
				'display_name' => 'Grace Hopper',
			)
		);

		$result = $this->service->list_users( array( 'search' => 'secret.address@example.org' ) );

		// Otherwise the ability becomes an "is this address registered?" oracle.
		$this->assertSame( 0, $result['total'] );

		// The person is still findable by name.
		$by_name = $this->service->list_users( array( 'search' => 'Grace' ) );
		$this->assertSame( 1, $by_name['total'] );
		$this->assertSame( $target, $by_name['users'][0]['id'] );
	}

	/**
	 * Search matches a login and a slug as well as a display name.
	 */
	public function test_search_matches_login_and_slug() {
		foreach ( array( 'ada', 'Lovelace' ) as $term ) {
			$result = $this->service->list_users( array( 'search' => $term ) );
			$this->assertSame( 1, $result['total'], 'searching for ' . $term );
		}
	}

	/**
	 * A search that matches nobody says so, rather than erroring.
	 */
	public function test_no_match_is_an_empty_collection() {
		$result = $this->service->list_users( array( 'search' => 'nobody-by-this-name' ) );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['users'] );
		$this->assertSame( 0, $result['total_pages'] );
	}

	/**
	 * Results are paginated and ordered deterministically.
	 */
	public function test_pagination_is_deterministic() {
		foreach ( array( 'Zoe Zenith', 'Bob Baker', 'Carol Clark' ) as $name ) {
			self::factory()->user->create(
				array(
					'role'         => 'editor',
					'display_name' => $name,
				)
			);
		}

		$first  = $this->service->list_users( array( 'per_page' => 2, 'page' => 1 ) );
		$second = $this->service->list_users( array( 'per_page' => 2, 'page' => 2 ) );

		$this->assertSame( 2, count( $first['users'] ) );
		$this->assertSame( $first['total'], $second['total'] );
		$this->assertGreaterThan( 1, $first['total_pages'] );

		$names = array_merge(
			array_column( $first['users'], 'name' ),
			array_column( $second['users'], 'name' )
		);
		$sorted = $names;
		// Case-insensitively, the way the database collation orders them: the
		// invariant is "ordered by display name", not "ordered by ID or by
		// registration order".
		usort( $sorted, 'strcasecmp' );
		$this->assertSame( $sorted, $names );

		$first_ids  = array_column( $first['users'], 'id' );
		$second_ids = array_column( $second['users'], 'id' );
		$this->assertSame( array(), array_intersect( $first_ids, $second_ids ) );
	}

	/**
	 * per_page is capped so one call cannot pull every user into a model.
	 */
	public function test_per_page_is_capped() {
		$result = $this->service->list_users( array( 'per_page' => 5000 ) );

		$this->assertSame( 100, $result['per_page'] );
	}

	/**
	 * The ability is read-only, gated like the rest of the Decker surface.
	 */
	public function test_permissions_and_annotations() {
		$definition = ( new Decker_Abilities() )->get_ability_definitions()['decker/list-users'];

		$this->assertTrue( $definition['meta']['annotations']['readonly'] );
		$this->assertFalse( $definition['meta']['annotations']['destructive'] );
		$this->assertTrue( $definition['meta']['show_in_rest'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->service->can_list_tasks() );

		wp_set_current_user( 0 );
		$this->assertWPError( $this->service->can_list_tasks() );
	}
}
