<?php
/**
 * Class Test_Decker_LabelManager
 *
 * @package Decker
 */

class DeckerLabelManagerTest extends Decker_Test_Base {
	private $test_label_id;
	private $editor;

	public function setUp(): void {
		parent::setUp();

		// // Manually trigger the 'init' action to ensure taxonomies are registered.
		// do_action( 'init' );

		// Create an editor user
		$this->editor = self::factory()->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->editor );

		$result = $this->factory->label->create(
			array(
				'name' => 'Test Label',
				'slug' => 'test-label',
				'color' => '#ff0000',
			)
		);

		if ( is_wp_error( $result ) ) {
			var_dump( $result->get_error_message() );
		} else {
			$this->test_label_id = $result;
		}
	}

	public function tearDown(): void {
		// Clean up test data
		wp_set_current_user( $this->editor );
		wp_delete_term( $this->test_label_id, 'decker_label' );
		wp_delete_user( $this->editor );
		parent::tearDown();
	}

	public function test_get_label_by_name() {
		$label = LabelManager::get_label_by_name( 'Test Label' );

		$this->assertInstanceOf( Label::class, $label );
		$this->assertEquals( 'Test Label', $label->name );
		$this->assertEquals( 'test-label', $label->slug );
		$this->assertEquals( '#ff0000', $label->color );
	}

	public function test_get_label_by_id() {
		$label = LabelManager::get_label_by_id( $this->test_label_id );

		$this->assertInstanceOf( Label::class, $label );
		$this->assertEquals( 'Test Label', $label->name );
		$this->assertEquals( 'test-label', $label->slug );
		$this->assertEquals( '#ff0000', $label->color );
	}

	public function test_get_all_labels() {
		$labels = LabelManager::get_all_labels();

		$this->assertIsArray( $labels );
		$this->assertGreaterThan( 0, count( $labels ) );
		$this->assertInstanceOf( Label::class, $labels[0] );
	}

	public function test_save_label_without_permission() {
		// Ensure no user is logged in
		wp_set_current_user( 0 );

		$new_label_data = array(
			'name' => 'New Test Label',
			'slug' => 'new-test-label',
			'color' => '#00ff00',
		);

		$result = LabelManager::save_label( $new_label_data, 0 );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'You do not have permission to manage labels', $result['message'] );
	}

	public function test_delete_label_without_permission() {
		// Ensure no user is logged in
		wp_set_current_user( 0 );

		$result = LabelManager::delete_label( $this->test_label_id );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'You do not have permission to delete labels', $result['message'] );
	}

	public function test_save_label_create() {
		wp_set_current_user( $this->editor );
		$new_label_data = array(
			'name' => 'New Test Label',
			'slug' => 'new-test-label',
			'color' => '#00ff00',
		);

		$result = LabelManager::save_label( $new_label_data, 0 );

		$this->assertTrue( $result['success'] );

		// Verify the label was created
		$term = get_term_by( 'name', 'New Test Label', 'decker_label' );
		$this->assertNotFalse( $term );
		$this->assertEquals( 'new-test-label', $term->slug );
		$this->assertEquals( '#00ff00', get_term_meta( $term->term_id, 'term-color', true ) );

		// Clean up
		wp_delete_term( $term->term_id, 'decker_label' );
	}

	public function test_save_label_update() {
		wp_set_current_user( $this->editor );
		$updated_data = array(
			'name' => 'Updated Test Label',
			'slug' => 'updated-test-label',
			'color' => '#0000ff',
		);

		$result = LabelManager::save_label( $updated_data, $this->test_label_id );

		$this->assertTrue( $result['success'] );

		// Verify the label was updated
		$term = get_term( $this->test_label_id, 'decker_label' );
		$this->assertEquals( 'Updated Test Label', $term->name );
		$this->assertEquals( 'updated-test-label', $term->slug );
		$this->assertEquals( '#0000ff', get_term_meta( $term->term_id, 'term-color', true ) );
	}

	public function test_delete_label() {
		wp_set_current_user( $this->editor );
		$result = LabelManager::delete_label( $this->test_label_id );

		$this->assertTrue( $result['success'] );
		$this->assertNull( get_term( $this->test_label_id, 'decker_label' ) );
	}

	public function test_get_nonexistent_label() {
		$this->assertNull( LabelManager::get_label_by_name( 'Nonexistent Label' ) );
		$this->assertNull( LabelManager::get_label_by_id( 99999 ) );
	}
}
