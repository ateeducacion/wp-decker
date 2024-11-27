<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Sample test case.
 */
class Test_Decker extends WP_UnitTestCase {
	public function set_up() {
        parent::set_up();
        
        // Mock that we're in WP Admin context.
		// See https://wordpress.stackexchange.com/questions/207358/unit-testing-in-the-wordpress-backend-is-admin-is-true
        set_current_screen( 'edit-post' );
        
        $this->decker = new Decker();
    }

	/**
	 * A single example test.
	 */
	public function test_sample() {
		// Replace this with some actual testing code.
		$this->assertTrue( true );
	}

    public function testCreateAndDeleteUser() {
        // Crear un nuevo usuario
        $user_data = [
            'user_login' => 'testuser_' . uniqid(),
            'user_email' => 'testuser_' . uniqid() . '@example.com',
            'user_pass'  => wp_generate_password(),
            'role'       => 'subscriber'
        ];

        $user_id = wp_insert_user($user_data);

        // Verificar que el usuario se creó correctamente
        $this->assertNotFalse($user_id, 'El usuario no se pudo crear');
        $this->assertTrue(is_int($user_id), 'El ID de usuario debe ser un entero');

        // Verificar que el usuario existe
        $user = get_user_by('ID', $user_id);
        $this->assertNotFalse($user, 'El usuario no se encontró después de la creación');

        // Eliminar el usuario
        $deleted = wp_delete_user($user_id);

        // Verificar que el usuario se eliminó correctamente
        $this->assertTrue($deleted, 'El usuario no se pudo eliminar');
        $user_after_deletion = get_user_by('ID', $user_id);
        $this->assertFalse($user_after_deletion, 'El usuario aún existe después de la eliminación');
    }


    public function tear_down() {
        parent::tear_down();
    }

	public function test_has_correct_token() {
		$has_correct_token = ( 'decker' === $this->decker->token );
		
		$this->assertTrue( $has_correct_token );
	}

	public function test_has_admin_interface() {
		$has_admin_interface = ( is_a( $this->decker->admin, 'Decker_Admin' ) );
		
		$this->assertTrue( $has_admin_interface );
	}

	public function test_has_settings_interface() {
		$has_settings_interface = ( is_a( $this->decker->settings, 'Decker_Settings' ) );
		
		$this->assertTrue( $has_settings_interface );
	}

	public function test_has_post_types() {
		$has_post_types = ( 0 < count( $this->decker->post_types ) );
		
		$this->assertTrue( $has_post_types );
	}

	public function test_has_load_plugin_textdomain() {
		$has_load_plugin_textdomain = ( is_int( has_action( 'init', [ $this->decker, 'load_plugin_textdomain' ] ) ) );
		
		$this->assertTrue( $has_load_plugin_textdomain );
	}
}