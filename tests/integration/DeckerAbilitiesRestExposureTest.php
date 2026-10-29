<?php
/**
 * Decker abilities must be reachable over the core Abilities REST routes.
 *
 * The adapter registered the abilities but left them out of the REST API, so an
 * external client (an agent CLI authenticated as a real user) could not reach
 * them at all. Exposure is opt-in through `meta.show_in_rest`; these tests
 * dispatch the real `/wp-abilities/v1` routes so the method rules, the
 * permission callbacks and the persisted result are all checked together.
 *
 * @package Decker
 */

class DeckerAbilitiesRestExposureTest extends Decker_Test_Base {

	/**
	 * REST server used to dispatch the requests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Editor with the Decker task capabilities.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber without `edit_posts`.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Board used by the fixtures.
	 *
	 * @var int
	 */
	private $board_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! Decker_Abilities::is_available() ) {
			$this->markTestSkipped( 'The WordPress Abilities API is not available.' );
		}

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->editor_id );
		$this->board_id = self::factory()->board->create(
			array(
				'name'  => 'Abilities REST Board',
				'color' => '#0099ff',
			)
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Resolve the registered run route for an ability.
	 *
	 * WordPress 6.9 registered `/wp-abilities/v1/<namespace>/<ability>/run`;
	 * 7.1 moved it under the `abilities` base as
	 * `/wp-abilities/v1/abilities/<namespace>/<ability>/run`. The route is
	 * resolved from the server so the test states the contract instead of
	 * guessing the installed shape.
	 *
	 * @param string $name Ability name.
	 * @return string Route.
	 */
	private function run_route( $name ) {
		$routes = $this->server->get_routes( 'wp-abilities/v1' );

		foreach ( array_keys( $routes ) as $route ) {
			if ( false === strpos( $route, '/run' ) ) {
				continue;
			}
			if ( 0 === strpos( $route, '/wp-abilities/v1/abilities/' ) ) {
				return '/wp-abilities/v1/abilities/' . $name . '/run';
			}
		}

		return '/wp-abilities/v1/' . $name . '/run';
	}

	/**
	 * Run an ability as a given user.
	 *
	 * @param int        $user_id User to act as, or 0 for anonymous.
	 * @param string     $method  HTTP method.
	 * @param string     $name    Ability name, such as `decker/get-task`.
	 * @param array|null $input   Ability input.
	 * @return WP_REST_Response Response.
	 */
	private function run_as( $user_id, $method, $name, $input = null ) {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( $method, $this->run_route( $name ) );

		if ( null !== $input ) {
			if ( 'POST' === $method ) {
				$request->add_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
			} else {
				// WordPress 7.1 reads GET input from the structured `input` query
				// parameter and coerces the scalars to the schema types; it does
				// not JSON-decode a string. See references/api.md for the 6.9
				// difference.
				$request->set_query_params( array( 'input' => $input ) );
			}
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Every Decker ability must opt into REST exposure.
	 */
	public function test_every_definition_opts_into_rest() {
		$definitions = ( new Decker_Abilities() )->get_ability_definitions();

		$this->assertNotEmpty( $definitions );
		foreach ( $definitions as $name => $definition ) {
			$this->assertTrue(
				$definition['meta']['show_in_rest'],
				$name . ' must set meta.show_in_rest'
			);
		}
	}

	/**
	 * The registered routes must exist for every ability.
	 */
	public function test_run_routes_are_registered() {
		$routes = $this->server->get_routes( 'wp-abilities/v1' );
		$this->assertNotEmpty( $routes, 'The core abilities namespace must be registered.' );

		$names = array_keys( ( new Decker_Abilities() )->get_ability_definitions() );
		foreach ( $names as $name ) {
			$response = $this->run_as( $this->editor_id, 'GET', $name );
			$this->assertNotSame(
				404,
				$response->get_status(),
				$name . ' must be reachable over REST'
			);
		}
	}

	/**
	 * A listed ability must appear in the core abilities listing.
	 */
	public function test_abilities_are_listed_for_an_authorized_user() {
		wp_set_current_user( $this->editor_id );

		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' )
		);

		$this->assertSame( 200, $response->get_status() );

		$listed = array();
		foreach ( (array) $response->get_data() as $ability ) {
			if ( isset( $ability['name'] ) ) {
				$listed[] = $ability['name'];
			}
		}

		$this->assertContains( 'decker/list-tasks', $listed );
		$this->assertContains( 'decker/create-task', $listed );
	}

	/**
	 * Anonymous requests must not reach an ability.
	 */
	public function test_anonymous_requests_are_rejected() {
		$response = $this->run_as( 0, 'GET', 'decker/list-tasks' );

		$this->assertContains( $response->get_status(), array( 401, 403 ), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A user without the task capabilities must be refused server-side.
	 */
	public function test_subscriber_cannot_create_a_task() {
		$response = $this->run_as(
			$this->subscriber_id,
			'POST',
			'decker/create-task',
			array(
				'title'    => 'Subscriber task',
				'board_id' => $this->board_id,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame(
			0,
			count( get_posts( array( 'post_type' => 'decker_task', 'title' => 'Subscriber task', 'post_status' => 'any' ) ) )
		);
	}

	/**
	 * A contributor holds edit_posts, and that is not enough.
	 *
	 * The default minimum role is editor, so a contributor is refused the
	 * Decker screens. They hold `edit_posts` but not `publish_posts`, and a
	 * created task is published, so authorizing an ability on `edit_posts`
	 * alone would let that account publish through the abilities what it
	 * cannot publish anywhere else, interface included.
	 */
	public function test_a_contributor_can_neither_list_nor_create() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertTrue( user_can( $contributor, 'edit_posts' ) );
		$this->assertFalse( user_can( $contributor, 'publish_posts' ) );

		$listed = $this->run_as( $contributor, 'GET', 'decker/list-tasks', array() );
		$this->assertSame( 403, $listed->get_status(), wp_json_encode( $listed->get_data() ) );

		$created = $this->run_as(
			$contributor,
			'POST',
			'decker/create-task',
			array(
				'title'    => 'Contributor task',
				'board_id' => $this->board_id,
			)
		);

		$this->assertSame( 403, $created->get_status() );
		$this->assertSame(
			0,
			count(
				get_posts(
					array(
						'post_type'   => 'decker_task',
						'title'       => 'Contributor task',
						'post_status' => 'any',
					)
				)
			)
		);
	}

	/**
	 * An author is refused at the default minimum, and allowed when configured.
	 *
	 * The abilities answer to the same gate as the Decker screens, so the
	 * configured role is what decides, not the capability alone.
	 */
	public function test_an_author_follows_the_configured_minimum_role() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$refused = $this->run_as( $author, 'GET', 'decker/list-tasks', array() );
		$this->assertSame( 403, $refused->get_status(), wp_json_encode( $refused->get_data() ) );

		update_option( 'decker_settings', array( 'minimum_user_profile' => 'author' ) );

		$allowed = $this->run_as( $author, 'GET', 'decker/list-tasks', array() );
		$this->assertSame( 200, $allowed->get_status(), wp_json_encode( $allowed->get_data() ) );

		delete_option( 'decker_settings' );
	}

	/**
	 * The method rules follow the ability annotations.
	 */
	public function test_method_rules_are_enforced() {
		$read_with_post = $this->run_as( $this->editor_id, 'POST', 'decker/list-tasks', array() );
		$this->assertSame( 405, $read_with_post->get_status() );

		$write_with_get = $this->run_as(
			$this->editor_id,
			'GET',
			'decker/create-task',
			array(
				'title'    => 'Wrong method task',
				'board_id' => $this->board_id,
			)
		);
		$this->assertSame( 405, $write_with_get->get_status() );
	}

	/**
	 * A create over REST must persist through the existing domain method.
	 */
	public function test_create_over_rest_persists_the_task() {
		$response = $this->run_as(
			$this->editor_id,
			'POST',
			'decker/create-task',
			array(
				'title'       => 'Created over REST',
				'board_id'    => $this->board_id,
				'stack'       => 'in-progress',
				'description' => 'Body text',
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );

		$task_id = (int) $data['id'];
		$this->assertSame( 'Created over REST', get_post( $task_id )->post_title );
		$this->assertSame( 'in-progress', get_post_meta( $task_id, 'stack', true ) );
		$this->assertSame(
			array( $this->board_id ),
			wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * Input validation must reject a bad payload before it reaches the domain.
	 */
	public function test_invalid_input_is_rejected() {
		$response = $this->run_as(
			$this->editor_id,
			'POST',
			'decker/create-task',
			array(
				'title'    => 'Invalid stack task',
				'board_id' => $this->board_id,
				'stack'    => 'nope',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			0,
			count( get_posts( array( 'post_type' => 'decker_task', 'title' => 'Invalid stack task', 'post_status' => 'any' ) ) )
		);
	}

	/**
	 * Hidden-task visibility must hold over REST exactly as it does in-process.
	 */
	public function test_hidden_task_visibility_is_preserved_over_rest() {
		wp_set_current_user( $this->editor_id );
		$hidden_id = self::factory()->task->create(
			array(
				'post_title'  => 'Hidden over REST',
				'board'       => $this->board_id,
				'stack'       => 'to-do',
				'post_author' => $this->editor_id,
				'author'      => $this->editor_id,
			)
		);
		update_post_meta( $hidden_id, 'hidden', '1' );

		$owner = $this->run_as( $this->editor_id, 'GET', 'decker/get-task', array( 'task_id' => $hidden_id ) );
		$this->assertSame( 200, $owner->get_status(), wp_json_encode( $owner->get_data() ) );

		$outsider_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$outsider    = $this->run_as( $outsider_id, 'GET', 'decker/get-task', array( 'task_id' => $hidden_id ) );
		$this->assertContains( $outsider->get_status(), array( 403, 404 ) );

		$listing = $this->run_as( $outsider_id, 'GET', 'decker/list-tasks', array( 'include_hidden' => true ) );
		$this->assertSame( 200, $listing->get_status() );
		foreach ( (array) ( $listing->get_data()['tasks'] ?? array() ) as $task ) {
			$this->assertNotSame( $hidden_id, (int) $task['id'] );
		}
	}

	/**
	 * A string "false" must not switch hidden tasks on.
	 *
	 * WordPress 6.9 -- the deployed baseline -- passes GET input to the ability
	 * uncoerced, so `input[include_hidden]=false` reaches the listing as the
	 * string "false". A plain boolean cast makes that true, which would include
	 * every hidden task the caller may see in a listing that asked for none. The
	 * query service is called directly because the 7.1 test environment coerces
	 * the value before it ever reaches Decker, hiding the regression.
	 */
	public function test_string_false_does_not_enable_hidden_tasks() {
		wp_set_current_user( $this->editor_id );

		$hidden_id = self::factory()->task->create(
			array(
				'post_title'  => 'Hidden from string false',
				'board'       => $this->board_id,
				'stack'       => 'to-do',
				'post_author' => $this->editor_id,
				'author'      => $this->editor_id,
			)
		);
		update_post_meta( $hidden_id, 'hidden', '1' );

		$service = new Decker_Ability_Service();

		foreach ( array( 'false', '0', '' ) as $value ) {
			$result = $service->list_tasks( array( 'include_hidden' => $value ) );

			$this->assertIsArray( $result );
			foreach ( $result['tasks'] as $task ) {
				$this->assertNotSame(
					$hidden_id,
					(int) $task['id'],
					'include_hidden="' . $value . '" must not include hidden tasks'
				);
			}
		}

		// The opt-in still works for a user entitled to see the task.
		$opted_in = $service->list_tasks( array( 'include_hidden' => 'true' ) );
		$ids      = array_map( static fn( $task ) => (int) $task['id'], $opted_in['tasks'] );
		$this->assertContains( $hidden_id, $ids );
	}
}
