<?php
/**
 * Class Test_Decker_Tasks_REST
 *
 * @package Decker
 */

class DeckerTasksRestTest extends Decker_Test_Base {

    /**
     * Users and objects.
     */
    private $editor;
    private $board_id;
    private $label_id;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Register REST routes
		do_action( 'init' ); // Very important to have registered all meta fields!.
        do_action( 'rest_api_init' );

        // Create editor user
        $this->editor = self::factory()->user->create(
            array(
                'role' => 'editor',
            )
        );
        wp_set_current_user( $this->editor );

        // Create board and label
        $this->board_id = self::factory()->board->create();
        $this->label_id = self::factory()->label->create();
    }

    /**
     * Clean up after each test.
     */
    public function tear_down() {
        wp_delete_user( $this->editor );
        parent::tear_down();
    }

    /**
     * Test creating a task via REST
     */
    public function test_create_task_via_rest() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );



        $task_data = array(
            'title'   => 'REST Task',
            'content' => 'REST Description',
            'status'  => 'publish',

		    'boards' => [ $this->board_id ], // Slug de taxonomía
		    'labels' => [ $this->label_id ],
		    // 'meta' => [

            // Importante: si 'decker_board' y 'decker_label' son taxonomías, se pasan así:
            // 'tax_input' => array(
            //     'decker_board' => array( $this->board_id ),
            //     'decker_label' => array( $this->label_id ),
            // ),

            // // Para tus metadatos registrados en 'show_in_rest'
            'meta' => array(
                'stack'          => 'to-do',
                'max_priority'   => true,
                'duedate'        => '2024-12-31',
                'assigned_users' => array( $this->editor ),
                'responsable'    => $this->editor,
            ),
        );

        $request->set_body( wp_json_encode( $task_data ) );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        // Check response
        $this->assertEquals( 201, $response->get_status(), 'Expected 201 on task creation' );
        $this->assertEquals( 'REST Task', $data['title']['raw'], 'Task title did not match' );

		$task_id = $data['id'];


		$this->assertIsInt( $task_id, 'Task creation failed.' );
		$this->assertGreaterThan( 0, $task_id );

        // Verificar el valor de stack directamente desde la base de datos
        $stack_value = get_post_meta($task_id, 'stack', true);
        $this->assertEquals('to-do', $stack_value, 'Stack meta not set correctly in database');

        // Check taxonomies
        $terms = wp_get_post_terms( $data['id'], 'decker_board' );
        $this->assertNotEmpty( $terms, 'Expected at least one board term' );
        $this->assertEquals( $this->board_id, $terms[0]->term_id, 'Board term_id not matching' );


        $terms = wp_get_post_terms( $data['id'], 'decker_label' );
        $this->assertNotEmpty( $terms, 'Expected at least one label term' );
        $this->assertEquals( $this->label_id, $terms[0]->term_id, 'Label term_id not matching' );
    }

    /**
     * Test updating a task via REST
     */
    public function test_update_task_via_rest() {
        // Create initial task
        $task_id = self::factory()->task->create(
            array(
                'post_title' => 'Original Title',
                // Asumiendo que la factoría asigne 'board' => $this->board_id de algún modo:
                'board'      => $this->board_id,
            )
        );

        $request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $task_id );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $update_data = array(
            'title' => 'Updated Title',
            'meta'  => array(
                'stack'       => 'in-progress',
                'max_priority'=> false,
            ),
        );

        $request->set_body( wp_json_encode( $update_data ) );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        // Check changes
        $this->assertEquals( 200, $response->get_status(), 'Expected 200 on task update' );
        $this->assertEquals( 'Updated Title', $data['title']['raw'] );
        
        // Verificar los metadatos directamente desde la base de datos
        $stack_value = get_post_meta($task_id, 'stack', true);
        $this->assertEquals('in-progress', $stack_value, 'Stack meta not set correctly in database');
        
        $max_priority = get_post_meta($task_id, 'max_priority', true);
        $this->assertEmpty($max_priority, 'Max priority should be false/empty');

        $terms = wp_get_post_terms( $data['id'], 'decker_board' );
        $this->assertNotEmpty( $terms, 'Expected at least one board term' );
        $this->assertEquals( $this->board_id, $terms[0]->term_id, 'Board term_id not matching' );


		// Second update to add labels and duedate
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $task_id );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$update_data_2 = array(
		    'labels' => [ $this->label_id ],
		    'meta'   => array(
		        'duedate' => '2025-01-15',
		    ),
		);

		$request->set_body( wp_json_encode( $update_data_2 ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Check second update changes
		$this->assertEquals( 200, $response->get_status(), 'Expected 200 on second update' );

		$terms = wp_get_post_terms( $data['id'], 'decker_label' );
		$this->assertNotEmpty( $terms, 'Expected at least one label term after update' );
		$this->assertEquals( $this->label_id, $terms[0]->term_id, 'Label term_id not matching after update' );

		$this->assertEquals( '2025-01-15', $data['meta']['duedate'], 'Duedate meta not set correctly' );


    }

    /**
     * Test marking/unmarking for today
     */
    public function test_mark_unmark_for_today() {
        $task_id = self::factory()->task->create();
        $user_id = $this->editor;
        $today   = date( 'Y-m-d' );

        // Mark
        $request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/mark_relation' );
        $request->set_param( 'user_id', $user_id );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );

        // Check meta
        $relations = get_post_meta( $task_id, '_user_date_relations', true );
        $this->assertNotEmpty( $relations );
        $this->assertEquals( $user_id, $relations[0]['user_id'] );
        $this->assertEquals( $today, $relations[0]['date'] );

        // Unmark
        $request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/unmark_relation' );
        $request->set_param( 'user_id', $user_id );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );

        // Check removal
        $relations = get_post_meta( $task_id, '_user_date_relations', true );
        $this->assertEmpty( $relations );
    }

    /**
     * Test task ordering via REST
     */
    public function test_task_ordering_via_rest() {
        $task1 = self::factory()->task->create(
            array(
                'board' => $this->board_id,
                'stack' => 'to-do',
            )
        );
        $task2 = self::factory()->task->create(
            array(
                'board' => $this->board_id,
                'stack' => 'to-do',
            )
        );

        // Primero, asegurarse de que el orden inicial es correcto
        $task1_initial = get_post($task1);
        $task2_initial = get_post($task2);
        
        // Forzar el orden inicial para asegurar que la prueba sea consistente
        wp_update_post(array(
            'ID' => $task1,
            'menu_order' => 1
        ));
        
        wp_update_post(array(
            'ID' => $task2,
            'menu_order' => 2
        ));
        
        // Actualizar el orden
        $request = new WP_REST_Request('PUT', '/decker/v1/tasks/' . $task1 . '/order');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->add_header('Content-Type', 'application/json');
        
        $order_data = array(
            'board_id' => $this->board_id,
            'source_stack' => 'to-do',
            'target_stack' => 'to-do',
            'source_order' => 1,
            'target_order' => 2
        );
        
        $request->set_body(wp_json_encode($order_data));
        
        // Forzar limpieza de caché
        clean_post_cache($task1);

        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );

        // Check new order
        $task = get_post( $task1 );
        $this->assertEquals( 2, $task->menu_order, 'Menu order did not match expected value 2' );
    }

    /**
     * Test invalid task creation
     */
    public function test_invalid_task_creation() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        // Missing title or board => forced 400 by our plugin logic
        $invalid_data = array(
            // Sin título ni contenido
            'meta'    => array(
                'stack' => 'to-do',
            ),
            // Sin board, debería fallar
        );

        $request->set_body( wp_json_encode( $invalid_data ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertEquals(
            400,
            $response->get_status(),
            'Expected 400 for missing required fields'
        );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'code', $data, 'Expected an error code in response' );
    }

    /**
     * Test REST API authorization
     */
    public function test_rest_api_authorization() {
        // Create user with no editing capabilities
        $subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        $request = new WP_REST_Request( 'GET', '/wp/v2/tasks' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        // Our code should disallow subscribers: expecting 403
        $this->assertEquals( 403, $response->get_status() );
    }
}
