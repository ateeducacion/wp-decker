<?php
/**
 * Tests for the task edit-screen meta boxes.
 *
 * Each box turns stored task meta into form controls, so these assert that the
 * stored values come back pre-selected and that every box emits the nonce and
 * field names Decker_Task_Meta_Saver reads on submit.
 *
 * @package Decker
 */

class DeckerTaskMetaBoxesTest extends Decker_Test_Base {

	/**
	 * The meta box renderer under test.
	 *
	 * @var Decker_Task_Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Editor used as the current user.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Board the fixtures are attached to.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->meta_boxes = new Decker_Task_Meta_Boxes();

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Mona Meta',
			)
		);
		wp_set_current_user( $this->editor );

		$this->board_id = self::factory()->board->create(
			array(
				'name' => 'Meta Board',
				'slug' => 'meta-board',
			)
		);
	}

	/**
	 * Capture the output of a meta box callback.
	 *
	 * @param string  $method The Decker_Task_Meta_Boxes method to invoke.
	 * @param WP_Post $post   The post to render for.
	 * @return string The captured markup.
	 */
	private function render( string $method, WP_Post $post ): string {
		ob_start();
		$this->meta_boxes->{$method}( $post );
		return ob_get_clean();
	}

	/**
	 * Create a task and return it as a WP_Post.
	 *
	 * @param array $args Overrides for the task factory.
	 * @return WP_Post The created task.
	 */
	private function create_task_post( array $args = array() ): WP_Post {
		$task_id = self::factory()->task->create(
			array_merge(
				array( 'board' => $this->board_id ),
				$args
			)
		);

		return get_post( $task_id );
	}

	/**
	 * The details box pre-fills every stored field and emits the save nonce.
	 */
	public function test_details_box_prefills_stored_values() {
		$post = $this->create_task_post(
			array(
				'stack'        => 'done',
				'max_priority' => true,
				'duedate'      => '2031-03-04',
				'responsable'  => $this->editor,
				'hidden'       => true,
			)
		);
		update_post_meta( $post->ID, 'id_nextcloud_card', 4321 );

		$output = $this->render( 'display_meta_box', $post );

		$this->assertStringContainsString( 'name="decker_task_nonce"', $output );
		$this->assertStringContainsString( 'name="duedate" value="2031-03-04"', $output );
		$this->assertStringContainsString( 'name="id_nextcloud_card" value="4321"', $output );
		$this->assertStringContainsString( 'name="responsable" value="' . $this->editor . '"', $output );

		// Checkboxes and the stack select reflect the stored state.
		$this->assertMatchesRegularExpression( '/name="max_priority" value="1"\s+checked/', $output );
		$this->assertMatchesRegularExpression( '/name="hidden" value="1"\s+checked/', $output );
		$this->assertMatchesRegularExpression( '/<option value="done"\s+selected/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<option value="to-do"\s+selected/', $output );
	}

	/**
	 * A task with no priority or hidden flag renders unchecked boxes.
	 */
	public function test_details_box_leaves_unset_flags_unchecked() {
		$post = $this->create_task_post( array( 'stack' => 'to-do' ) );

		$output = $this->render( 'display_meta_box', $post );

		$this->assertStringNotContainsString( 'name="max_priority" value="1" checked', $output );
		$this->assertStringNotContainsString( 'name="hidden" value="1" checked', $output );
		$this->assertMatchesRegularExpression( '/<option value="to-do"\s+selected/', $output );
	}

	/**
	 * The board box preselects the board the task is filed under.
	 */
	public function test_board_box_preselects_the_assigned_board() {
		$other_board = self::factory()->board->create(
			array(
				'name' => 'Other Board',
				'slug' => 'other-board',
			)
		);

		$post = $this->create_task_post();

		$output = $this->render( 'display_board_meta_box', $post );

		$this->assertStringContainsString( 'name="decker_board"', $output );
		$this->assertMatchesRegularExpression(
			'/<option value="' . $this->board_id . '"\s+selected/',
			$output
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="' . $other_board . '"\s+selected/',
			$output
		);
	}

	/**
	 * The labels box checks only the labels assigned to the task.
	 */
	public function test_labels_box_checks_only_assigned_labels() {
		wp_set_current_user( 1 );
		$assigned = self::factory()->label->create(
			array(
				'name' => 'Assigned Label',
				'slug' => 'assigned-label',
			)
		);
		$other    = self::factory()->label->create(
			array(
				'name' => 'Other Label',
				'slug' => 'other-label',
			)
		);
		wp_set_current_user( $this->editor );

		$post = $this->create_task_post( array( 'labels' => array( $assigned ) ) );

		$output = $this->render( 'display_labels_meta_box', $post );

		$this->assertStringContainsString( 'name="decker_labels[]"', $output );
		$this->assertMatchesRegularExpression(
			'/name="decker_labels\[\]" value="' . $assigned . '"\s+checked/',
			$output
		);
		$this->assertDoesNotMatchRegularExpression(
			'/name="decker_labels\[\]" value="' . $other . '"\s+checked/',
			$output
		);
	}

	/**
	 * The users box checks only the users assigned to the task.
	 */
	public function test_users_box_checks_only_assigned_users() {
		$other_user = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Unassigned Person',
			)
		);

		$post = $this->create_task_post( array( 'assigned_users' => array( $this->editor ) ) );

		$output = $this->render( 'display_users_meta_box', $post );

		$this->assertStringContainsString( 'Mona Meta', $output );
		$this->assertStringContainsString( 'Unassigned Person', $output );
		$this->assertMatchesRegularExpression(
			'/name="assigned_users\[\]" value="' . $this->editor . '"\s+checked/',
			$output
		);
		$this->assertDoesNotMatchRegularExpression(
			'/name="assigned_users\[\]" value="' . $other_user . '"\s+checked/',
			$output
		);
	}

	/**
	 * The attachments box lists attached media with a removable hidden input.
	 */
	public function test_attachment_box_lists_attached_media() {
		$post = $this->create_task_post();

		$attachment_id = self::factory()->attachment->create(
			array(
				'post_parent'    => $post->ID,
				'post_title'     => 'Design Notes',
				'post_mime_type' => 'application/pdf',
				'guid'           => 'http://example.org/wp-content/uploads/design-notes.pdf',
			)
		);

		$output = $this->render( 'display_attachment_meta_box', $post );

		$this->assertStringContainsString( 'id="attachments-list"', $output );
		$this->assertStringContainsString( 'data-attachment-id="' . $attachment_id . '"', $output );
		$this->assertStringContainsString( 'Design Notes.pdf', $output );
		$this->assertStringContainsString( 'name="attachments[]" value="' . $attachment_id . '"', $output );
	}

	/**
	 * A task with no media renders the empty attachment list.
	 */
	public function test_attachment_box_renders_empty_list_without_media() {
		$post = $this->create_task_post();

		$output = $this->render( 'display_attachment_meta_box', $post );

		// The list element is emitted but has no <li> in it. The inline script
		// also mentions data-attachment-id, so scope the check to the markup
		// before the <script> block.
		$markup = substr( $output, 0, strpos( $output, '<script>' ) );

		$this->assertStringContainsString( 'id="attachments-list"', $markup );
		$this->assertStringNotContainsString( 'data-attachment-id=', $markup );
		$this->assertStringNotContainsString( 'name="attachments[]"', $markup );
	}

	/**
	 * add_meta_boxes() registers every Decker box on the task screen.
	 */
	public function test_add_meta_boxes_registers_every_task_box() {
		global $wp_meta_boxes;

		$wp_meta_boxes = array();
		set_current_screen( 'decker_task' );

		$this->meta_boxes->add_meta_boxes();

		$registered = array();
		foreach ( $wp_meta_boxes['decker_task'] as $context => $priorities ) {
			foreach ( $priorities as $boxes ) {
				$registered = array_merge( $registered, array_keys( $boxes ) );
			}
		}

		$this->assertContains( 'decker_task_meta_box', $registered );
		$this->assertContains( 'decker_users_meta_box', $registered );
		$this->assertContains( 'user_date_meta_box', $registered );
		$this->assertContains( 'attachment_meta_box', $registered );
		$this->assertContains( 'decker_labels_meta_box', $registered );
		$this->assertContains( 'decker_board_meta_box', $registered );
	}
}
