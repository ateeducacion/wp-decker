<?php
/**
 * Class Test_Decker_Admin
 *
 * @package Decker
 */

class Test_Decker_Admin extends WP_UnitTestCase {
    protected $admin;

    public function set_up() {
        parent::set_up();
        
        // Mock admin context
        set_current_screen('edit-post');
        
        // Create admin user and log in
        $admin_user = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_user);
        
        $this->admin = new Decker_Admin('decker', DECKER_VERSION);
    }

    public function test_admin_menu_pages() {
        // Simulate admin menu loading
        do_action('admin_menu');
        
        global $menu, $submenu;
        
        // Check if main menu exists
        $menu_exists = false;
        foreach ($menu as $menu_item) {
            if ($menu_item[0] === 'Decker') {
                $menu_exists = true;
                break;
            }
        }
        $this->assertTrue($menu_exists);
    }

    public function test_admin_enqueue_scripts() {
        // Trigger admin scripts enqueue
        do_action('admin_enqueue_scripts');
        
        $this->assertTrue(wp_script_is('decker-admin'));
        $this->assertTrue(wp_style_is('decker-admin'));
    }

    public function test_settings_registration() {
        // Trigger admin init
        do_action('admin_init');
        
        // Verify settings are registered
        $registered_settings = get_registered_settings();
        $this->assertArrayHasKey('decker_settings', $registered_settings);
    }

    public function test_admin_notices() {
        ob_start();
        do_action('admin_notices');
        $notices = ob_get_clean();
        
        // Add specific notice tests based on your implementation
        $this->assertIsString($notices);
    }

    public function tear_down() {
        parent::tear_down();
        wp_set_current_user(0);
    }
}
