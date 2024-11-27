<?php
/**
 * Test_Decker class
 *
 * @package    Decker
 * @subpackage Decker/tests
 */

class Decker_Test extends WP_UnitTestCase {

	public function setUp() {
		parent::setUp();
		// Configuración previa a cada prueba
	}

	public function test_create_decker_task() {
		// Crea un usuario con un rol específico
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Crea un 'decker_task'
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Tarea de prueba',
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
			)
		);

		// Verifica que se haya creado correctamente
		$this->assertIsInt( $post_id );
		$this->assertEquals( 'decker_task', get_post_type( $post_id ) );
	}

	public function test_delete_decker_task() {
		// Implementa la lógica para eliminar y verificar
	}

	// Agrega más métodos para pruebas adicionales
}
