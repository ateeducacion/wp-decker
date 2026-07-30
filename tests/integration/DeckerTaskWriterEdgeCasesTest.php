<?php
/**
 * Edge-case tests for Decker_Task_Writer.
 *
 * @package Decker
 */

class DeckerTaskWriterEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Board term ID.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Clean up fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		wp_delete_user( $this->admin_id );
		parent::tear_down();
	}

	/**
	 * Return the minimum valid writer arguments.
	 *
	 * @return array Valid arguments.
	 */
	private function get_valid_args() {
		return array(
			'title'       => 'Edge task',
			'description' => '<p>Body</p>',
			'stack'       => 'to-do',
			'board'       => $this->board_id,
			'author'      => $this->admin_id,
			'responsable' => $this->admin_id,
		);
	}

	/**
	 * Reject every invalid required-field variant without creating a task.
	 *
	 * @dataProvider provide_invalid_required_fields
	 *
	 * @param array  $overrides Expected argument overrides.
	 * @param string $code      Expected error code.
	 */
	public function test_invalid_required_fields_do_not_create_tasks( $overrides, $code ) {
		$before = (int) wp_count_posts( 'decker_task' )->publish;
		$result = Decker_Task_Writer::create_or_update_task( array_merge( $this->get_valid_args(), $overrides ) );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, (int) wp_count_posts( 'decker_task' )->publish );
	}

	/**
	 * Invalid required-field cases.
	 *
	 * @return array Test cases.
	 */
	public function provide_invalid_required_fields() {
		return array(
			'empty title'       => array( array( 'title' => '' ), 'missing_field' ),
			'empty stack'       => array( array( 'stack' => '' ), 'missing_field' ),
			'invalid stack'     => array( array( 'stack' => 'blocked' ), 'invalid_field' ),
			'missing board'     => array( array( 'board' => 0 ), 'missing_field' ),
			'nonexistent board' => array( array( 'board' => 999999 ), 'invalid' ),
		);
	}

	/**
	 * Normalize WP_User objects and only fire assignment hooks for newly added users.
	 */
	public function test_assigned_user_objects_are_normalized_and_duplicate_hooks_are_not_fired() {
		$user_one = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_two = self::factory()->user->create( array( 'role' => 'editor' ) );
		$assigned = array();

		$listener = static function ( $task_id, $user_id ) use ( &$assigned ) {
			$assigned[] = array( (int) $task_id, (int) $user_id );
		};
		add_action( 'decker_user_assigned', $listener, 10, 2 );

		$args                    = $this->get_valid_args();
		$args['assigned_users']  = array( get_user_by( 'id', $user_one ) );
		$task_id                 = Decker_Task_Writer::create_or_update_task( $args );
		$args['id']              = $task_id;
		$args['assigned_users']  = array( get_user_by( 'id', $user_one ), get_user_by( 'id', $user_two ) );
		$updated_task_id         = Decker_Task_Writer::create_or_update_task( $args );

		remove_action( 'decker_user_assigned', $listener, 10 );

		$this->assertSame( $task_id, $updated_task_id );
		$this->assertSame( array( $user_one, $user_two ), get_post_meta( $task_id, 'assigned_users', true ) );
		$this->assertSame(
			array(
				array( $task_id, $user_one ),
				array( $task_id, $user_two ),
			),
			$assigned
		);
	}

	/**
	 * Clear all labels when an update supplies an empty label set.
	 */
	public function test_empty_label_array_replaces_existing_labels() {
		$label_one = self::factory()->label->create();
		$label_two = self::factory()->label->create();
		$args      = $this->get_valid_args();
		$args['labels'] = array( $label_one, $label_two );
		$task_id         = Decker_Task_Writer::create_or_update_task( $args );

		$args['id']     = $task_id;
		$args['labels'] = array();
		Decker_Task_Writer::create_or_update_task( $args );

		$this->assertSame( array(), wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Preserve a linked Nextcloud card when an unrelated update passes the default zero value.
	 */
	public function test_update_does_not_clear_existing_nextcloud_card_with_default_zero() {
		$args                       = $this->get_valid_args();
		$args['id_nextcloud_card']  = 321;
		$task_id                    = Decker_Task_Writer::create_or_update_task( $args );
		$args['id']                 = $task_id;
		$args['title']              = 'Updated title';
		$args['id_nextcloud_card']  = 0;

		Decker_Task_Writer::create_or_update_task( $args );

		$this->assertSame( '321', get_post_meta( $task_id, 'id_nextcloud_card', true ) );
	}
}
