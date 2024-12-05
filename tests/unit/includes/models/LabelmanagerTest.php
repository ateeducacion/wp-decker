<?php
/**
 * Class Test_Decker_LabelManager
 *
 * @package Decker
 */

class DeckerLabelManagerTest extends WP_UnitTestCase {
    private $test_label_id;
    private $test_label_data;

    public function setUp(): void {
        parent::setUp();
        
        // Create a test label
        $this->test_label_data = [
            'name' => 'Test Label',
            'slug' => 'test-label',
            'color' => '#ff0000'
        ];
        
        $result = wp_insert_term('Test Label', 'decker_label', ['slug' => 'test-label']);
        $this->test_label_id = $result['term_id'];
        update_term_meta($this->test_label_id, 'term-color', '#ff0000');
    }

    public function tearDown(): void {
        // Clean up test data
        wp_delete_term($this->test_label_id, 'decker_label');
        parent::tearDown();
    }

    public function test_get_label_by_name() {
        $label = LabelManager::get_label_by_name('Test Label');
        
        $this->assertInstanceOf(Label::class, $label);
        $this->assertEquals('Test Label', $label->name);
        $this->assertEquals('test-label', $label->slug);
        $this->assertEquals('#ff0000', $label->color);
    }

    public function test_get_label_by_id() {
        $label = LabelManager::get_label_by_id($this->test_label_id);
        
        $this->assertInstanceOf(Label::class, $label);
        $this->assertEquals('Test Label', $label->name);
        $this->assertEquals('test-label', $label->slug);
        $this->assertEquals('#ff0000', $label->color);
    }

    public function test_get_all_labels() {
        $labels = LabelManager::get_all_labels();
        
        $this->assertIsArray($labels);
        $this->assertGreaterThan(0, count($labels));
        $this->assertInstanceOf(Label::class, $labels[0]);
    }

    public function test_save_label_create() {
        $new_label_data = [
            'name' => 'New Test Label',
            'slug' => 'new-test-label',
            'color' => '#00ff00'
        ];
        
        $result = LabelManager::save_label($new_label_data, 0);
        
        $this->assertTrue($result['success']);
        
        // Verify the label was created
        $term = get_term_by('name', 'New Test Label', 'decker_label');
        $this->assertNotFalse($term);
        $this->assertEquals('new-test-label', $term->slug);
        $this->assertEquals('#00ff00', get_term_meta($term->term_id, 'term-color', true));
        
        // Clean up
        wp_delete_term($term->term_id, 'decker_label');
    }

    public function test_save_label_update() {
        $updated_data = [
            'name' => 'Updated Test Label',
            'slug' => 'updated-test-label',
            'color' => '#0000ff'
        ];
        
        $result = LabelManager::save_label($updated_data, $this->test_label_id);
        
        $this->assertTrue($result['success']);
        
        // Verify the label was updated
        $term = get_term($this->test_label_id, 'decker_label');
        $this->assertEquals('Updated Test Label', $term->name);
        $this->assertEquals('updated-test-label', $term->slug);
        $this->assertEquals('#0000ff', get_term_meta($term->term_id, 'term-color', true));
    }

    public function test_delete_label() {
        $result = LabelManager::delete_label($this->test_label_id);
        
        $this->assertTrue($result['success']);
        $this->assertNull(get_term($this->test_label_id, 'decker_label'));
    }

    public function test_get_nonexistent_label() {
        $this->assertNull(LabelManager::get_label_by_name('Nonexistent Label'));
        $this->assertNull(LabelManager::get_label_by_id(99999));
    }
}
