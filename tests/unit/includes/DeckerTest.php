<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Main plugin test case.
 */
class DeckerTest extends Decker_Test_Base {
	protected $decker;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

		// Forzar la inicialización de taxonomías y roles
		do_action( 'init' );

		// Crear un usuario administrador para las pruebas
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		// Instanciar el plugin
		$this->decker = new Decker();
	}

	public function test_plugin_initialization() {
		$this->assertInstanceOf( Decker::class, $this->decker );
		$this->assertEquals( 'decker', $this->decker->get_plugin_name() );
		$this->assertEquals( DECKER_VERSION, $this->decker->get_version() );
	}

	public function test_plugin_dependencies() {
		// Verificar que el loader existe y está correctamente instanciado
		$loader = $this->get_private_property( $this->decker, 'loader' );
		$this->assertInstanceOf( 'Decker_Loader', $loader );

		// Verificar que las propiedades requeridas están configuradas
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'plugin_name' ) );
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'version' ) );
	}

	/**
	 * Helper method to access private properties
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * Test current_user_has_at_least_minimum_role.
	 */
	public function test_current_user_has_at_least_minimum_role() {
		// Configurar los ajustes de Decker
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'editor' ) );

		// Crear usuarios con roles estándar
		$admin      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Probar con un administrador
		wp_set_current_user( $admin );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Administrator should have editor access.' );

		// Probar con un editor
		wp_set_current_user( $editor );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Editor should have editor access.' );

		// Probar con un suscriptor
		wp_set_current_user( $subscriber );
		$this->assertFalse( Decker::current_user_has_at_least_minimum_role(), 'Subscriber should not have editor access.' );

		// Restaurar el usuario actual
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// Limpiar datos
		wp_delete_user( $this->admin_user_id );
		delete_option( 'decker_settings' );
		parent::tear_down();
	}
}
