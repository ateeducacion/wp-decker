<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Main plugin test case.
 */
class Test_Decker extends WP_UnitTestCase {
    protected $decker;

    public function set_up() {
        parent::set_up();
        
        // Mock that we're in WP Admin context
        set_current_screen('edit-post');
        
        $this->decker = new Decker();
    }

    public function test_plugin_initialization() {
        $this->assertInstanceOf(Decker::class, $this->decker);
        $this->assertEquals('decker', $this->decker->get_plugin_name());
        $this->assertEquals(DECKER_VERSION, $this->decker->get_version());
    }

    public function test_plugin_dependencies() {
        // Verify loader exists
        $loader = $this->get_private_property($this->decker, 'loader');
        $this->assertInstanceOf('Decker_Loader', $loader);

        // Verify i18n is loaded
        $this->assertTrue(
            has_action('plugins_loaded', array($this->decker->get_private_property('plugin_i18n'), 'load_plugin_textdomain'))
        );
    }

    public function test_hooks_are_registered() {
        $loader = $this->get_private_property($this->decker, 'loader');
        $actions = $this->get_private_property($loader, 'actions');
        $filters = $this->get_private_property($loader, 'filters');

        $this->assertIsArray($actions);
        $this->assertIsArray($filters);
        $this->assertNotEmpty($actions);
    }

    /**
     * Helper method to access private properties
     */
    protected function get_private_property($object, $property) {
        $reflection = new ReflectionClass(get_class($object));
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    public function tear_down() {
        parent::tear_down();
    }
}
