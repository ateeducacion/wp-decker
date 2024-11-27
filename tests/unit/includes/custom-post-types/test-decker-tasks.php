<?php
/**
 * Class Test_Decker_Tasks
 *
 * @package Decker
 */

class Test_Decker_Tasks extends WP_UnitTestCase {

    protected $post_type = 'decker_task';

    public function set_up() {
        parent::set_up();

        // Carga la clase Decker_Tasks si no está cargada
        if ( ! class_exists( 'Decker_Tasks' ) ) {
            require_once plugin_dir_path( __FILE__ ) . '/path-to-decker-tasks-class.php'; // Ajusta la ruta según tu estructura
        }

        // Instancia la clase si no está ya instanciada
        if ( ! class_exists( 'Decker_Tasks' ) || ! isset( $GLOBALS['decker_tasks'] ) ) {
            $GLOBALS['decker_tasks'] = new Decker_Tasks();
        }

        // Asegura que los hooks se ejecuten
        do_action( 'init' );
    }

    public function tear_down() {
        // Limpia los posts creados durante las pruebas
        $posts = get_posts( array( 'post_type' => $this->post_type, 'numberposts' => -1 ) );
        foreach ( $posts as $post ) {
            wp_delete_post( $post->ID, true );
        }

        // Limpia los términos de taxonomía creados durante las pruebas
        $taxonomies = array( 'decker_board', 'decker_label' );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ) );
            foreach ( $terms as $term ) {
                wp_delete_term( $term->term_id, $taxonomy );
            }
        }

        parent::tear_down();
    }

    /**
     * Test if the Custom Post Type exists.
     */
    public function test_cpt_exists() {
        $this->assertTrue( post_type_exists( $this->post_type ) );
    }

    /**
     * Test the labels of the Custom Post Type.
     */
    public function test_cpt_labels() {
        $post_type_object = get_post_type_object( $this->post_type );

        $this->assertEquals( 'Task', $post_type_object->labels->singular_name );
        $this->assertEquals( 'Tasks', $post_type_object->labels->name );
    }

    /**
     * Test creating a task.
     */
    public function test_create_task() {
        $task_data = array(
            'post_title'   => 'Test Task',
            'post_content' => 'Task Description',
            'post_status'  => 'publish',
            'post_type'    => $this->post_type,
        );

        $task_id = wp_insert_post( $task_data );

        $this->assertNotEquals( 0, $task_id, 'Task ID should not be 0' );
        $this->assertEquals( 'Test Task', get_post( $task_id )->post_title, 'Task title should match' );
    }

    /**
     * Test the capabilities of the Custom Post Type.
     */
    public function test_task_capabilities() {
        $post_type_object = get_post_type_object( $this->post_type );

        $this->assertTrue( $post_type_object->public, 'Post type should be public' );
        $this->assertTrue( $post_type_object->show_ui, 'Post type should show UI' );
        $this->assertTrue( $post_type_object->show_in_menu, 'Post type should show in menu' );
        $this->assertFalse( $post_type_object->show_in_rest, 'Post type should not be shown in REST API' );
    }

    /**
     * Test that metaboxes are added.
     */
    public function test_metaboxes_added() {
        global $wp_meta_boxes;

        $this->assertArrayHasKey( 'decker_task_meta_box', $wp_meta_boxes[ $this->post_type ]['normal']['high'], 'Task Meta Box should be added' );
        $this->assertArrayHasKey( 'decker_users_meta_box', $wp_meta_boxes[ $this->post_type ]['side']['default'], 'Users Meta Box should be added' );
        $this->assertArrayHasKey( 'user_date_meta_box', $wp_meta_boxes[ $this->post_type ]['normal']['high'], 'User-Date Meta Box should be added' );
        $this->assertArrayHasKey( 'attachment_meta_box', $wp_meta_boxes[ $this->post_type ]['normal']['high'], 'Attachment Meta Box should be added' );
        $this->assertArrayHasKey( 'decker_labels_meta_box', $wp_meta_boxes[ $this->post_type ]['side']['default'], 'Labels Meta Box should be added' );
        $this->assertArrayHasKey( 'decker_board_meta_box', $wp_meta_boxes[ $this->post_type ]['side']['default'], 'Board Meta Box should be added' );
    }

    /**
     * Test taxonomy assignment when creating a task.
     */
    public function test_taxonomy_assignment() {
        // Crea términos de taxonomía
        $board_term = wp_insert_term( 'Board 1', 'decker_board' );
        $label_term = wp_insert_term( 'Label 1', 'decker_label' );

        // Verifica que los términos se crearon correctamente
        $this->assertNotWPError( $board_term, 'Board term should be created successfully' );
        $this->assertNotWPError( $label_term, 'Label term should be created successfully' );

        $task_id = $this->factory->post->create( array(
            'post_title'   => 'Taxonomy Test Task',
            'post_content' => 'Testing taxonomy assignment',
            'post_status'  => 'publish',
            'post_type'    => $this->post_type,
        ) );

        // Asigna taxonomías
        wp_set_post_terms( $task_id, array( $board_term['term_id'] ), 'decker_board' );
        wp_set_post_terms( $task_id, array( $label_term['term_id'] ), 'decker_label' );

        // Verifica la asignación
        $assigned_boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
        $assigned_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );

        $this->assertContains( $board_term['term_id'], $assigned_boards, 'Board term should be assigned to the task' );
        $this->assertContains( $label_term['term_id'], $assigned_labels, 'Label term should be assigned to the task' );
    }

    /**
     * Test saving and retrieving task meta.
     */
    public function test_save_task_meta() {
        $task_id = $this->factory->post->create( array(
            'post_title'   => 'Meta Test Task',
            'post_content' => 'Testing meta fields',
            'post_status'  => 'publish',
            'post_type'    => $this->post_type,
        ) );

        // Simula la actualización de metadatos
        update_post_meta( $task_id, 'duedate', '2024-12-31' );
        update_post_meta( $task_id, 'stack', 'in-progress' );

        // Verifica que los metadatos se guardaron correctamente
        $this->assertEquals( '2024-12-31', get_post_meta( $task_id, 'duedate', true ), 'Duedate meta should match' );
        $this->assertEquals( 'in-progress', get_post_meta( $task_id, 'stack', true ), 'Stack meta should match' );
    }

    /**
     * Test REST API routes registration.
     */
    public function test_rest_routes_registered() {
        global $wp_rest_server;

        // Asegura que el servidor REST esté inicializado
        if ( ! isset( $wp_rest_server ) ) {
            $wp_rest_server = new WP_REST_Server();
            $wp_rest_server->init();
            do_action( 'rest_api_init' );
        }

        $routes = $wp_rest_server->get_routes();

        $expected_routes = array(
            '/decker/v1/tasks/(?P<id>\d+)/mark_relation',
            '/decker/v1/tasks/(?P<id>\d+)/unmark_relation',
            '/decker/v1/tasks/(?P<id>\d+)/order',
            '/decker/v1/tasks/(?P<id>\d+)/stack',
            '/decker/v1/tasks/(?P<id>\d+)/leave',
            '/decker/v1/tasks/(?P<id>\d+)/assign',
            '/decker/v1/tasks/(?P<id>\d+)/archive',
            '/decker/v1/fix-order/(?P<board_id>\d+)',
        );

        foreach ( $expected_routes as $route ) {
            $this->assertArrayHasKey( $route, $routes, "Route {$route} is not registered." );
        }
    }

    // Puedes agregar más pruebas para cubrir otras funcionalidades, como AJAX handlers, ordenamiento personalizado, etc.
}
