<?php
/**
 * Integration tests for Decker task revisions.
 *
 * @package Decker
 */

/**
 * Test native revision support and legacy task baseline preservation.
 */
class DeckerTaskRevisionsTest extends Decker_Test_Base {

	/**
	 * Current editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Clean up each test.
	 */
	public function tear_down() {
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that task registration keeps all existing supports and adds revisions.
	 */
	public function test_task_post_type_supports_native_revisions() {
		$expected_supports = array(
			'title',
			'editor',
			'revisions',
			'author',
			'custom-fields',
			'comments',
			'excerpt',
			'page-attributes',
		);

		foreach ( $expected_supports as $support ) {
			$this->assertTrue(
				post_type_supports( 'decker_task', $support ),
				sprintf( 'The decker_task post type must support %s.', $support )
			);
		}
	}

	/**
	 * Test that baseline creation never mutates the legacy parent task.
	 */
	public function test_baseline_preserves_all_existing_parent_data() {
		$fixture = $this->create_legacy_task_fixture();
		$before  = $this->snapshot_task( $fixture['task_id'] );

		do_action( 'pre_post_update', $fixture['task_id'], array() );

		clean_post_cache( $fixture['task_id'] );
		$after = $this->snapshot_task( $fixture['task_id'] );

		$this->assertSame( $before, $after, 'Saving a baseline must not mutate the parent task.' );
		$this->assert_revision_state_exists(
			$fixture['task_id'],
			'Legacy task title',
			'Legacy description before revision support',
			'Legacy task excerpt'
		);
	}

	/**
	 * Test that the pre-update baseline contains the legacy revisionable state.
	 */
	public function test_legacy_state_is_saved_before_first_update() {
		$task_id = $this->create_task(
			array(
				'post_title'   => 'Legacy title',
				'post_content' => 'Legacy description before revision support',
			)
		);
		$this->set_post_excerpt_without_update( $task_id, 'Legacy excerpt' );

		$this->assertSame( array(), $this->get_regular_revisions( $task_id ) );

		$result = wp_update_post(
			array(
				'ID'           => $task_id,
				'post_title'   => 'Updated title',
				'post_content' => 'Description version 1',
				'post_excerpt' => 'Updated excerpt',
			),
			true
		);

		$this->assertNotWPError( $result );
		$revision = $this->assert_revision_state_exists(
			$task_id,
			'Legacy title',
			'Legacy description before revision support',
			'Legacy excerpt'
		);
		$this->assertSame( $task_id, (int) $revision->post_parent );
		$this->assertSame( 'revision', $revision->post_type );
		$this->assertSame( 'inherit', $revision->post_status );
	}

	/**
	 * Test that later edits create discoverable native revisions.
	 */
	public function test_future_content_versions_are_saved_as_native_revisions() {
		$task_id = $this->create_task(
			array( 'post_content' => 'Legacy description before revision support' )
		);

		foreach ( array( 'Description version 1', 'Description version 2', 'Description version 3' ) as $content ) {
			$result = wp_update_post(
				array(
					'ID'           => $task_id,
					'post_content' => $content,
				),
				true
			);
			$this->assertNotWPError( $result );
		}

		foreach (
			array(
				'Legacy description before revision support',
				'Description version 1',
				'Description version 2',
				'Description version 3',
			) as $content
		) {
			$this->assert_revision_content_exists( $task_id, $content );
		}
	}

	/**
	 * Test that metadata-only updates do not duplicate the baseline.
	 */
	public function test_metadata_only_updates_do_not_create_duplicate_baselines() {
		$task_id = $this->create_task(
			array( 'post_content' => 'Stable task description' )
		);

		wp_update_post(
			array(
				'ID'         => $task_id,
				'meta_input' => array( 'stack' => 'in-progress' ),
			)
		);
		wp_update_post(
			array(
				'ID'         => $task_id,
				'meta_input' => array( 'duedate' => '2031-02-03' ),
			)
		);

		$this->assertSame( 1, $this->count_revisions_with_content( $task_id, 'Stable task description' ) );
		$this->assertSame( 1, count( $this->get_regular_revisions( $task_id ) ) );
	}

	/**
	 * Test that invoking the baseline path repeatedly is idempotent.
	 */
	public function test_baseline_creation_is_idempotent() {
		$task_id = $this->create_task(
			array( 'post_content' => 'Original baseline content' )
		);
		$before = $this->snapshot_task( $task_id );

		do_action( 'pre_post_update', $task_id, array() );
		do_action( 'pre_post_update', $task_id, array() );

		clean_post_cache( $task_id );
		$this->assertSame( $before, $this->snapshot_task( $task_id ) );
		$this->assertSame( 1, $this->count_revisions_with_content( $task_id, 'Original baseline content' ) );

		wp_update_post(
			array(
				'ID'           => $task_id,
				'post_content' => 'Content after the baseline',
			)
		);
		$this->assert_revision_content_exists( $task_id, 'Content after the baseline' );
	}

	/**
	 * Test that a task with a regular revision does not receive another baseline.
	 */
	public function test_existing_regular_revision_skips_legacy_baseline_path() {
		$task_id = $this->create_task(
			array( 'post_content' => 'Existing regular revision' )
		);

		do_action( 'pre_post_update', $task_id, array() );
		wp_update_post(
			array(
				'ID'           => $task_id,
				'post_content' => 'Real content update',
			)
		);

		$this->assertSame( 1, $this->count_revisions_with_content( $task_id, 'Existing regular revision' ) );
		$this->assert_revision_content_exists( $task_id, 'Real content update' );
	}

	/**
	 * Test revisions through Decker's primary application save service.
	 */
	public function test_create_or_update_task_path_creates_revisions() {
		$board_id = self::factory()->board->create();
		$label_id = self::factory()->label->create();
		$task_id  = self::factory()->task->create(
			array(
				'board'        => $board_id,
				'labels'       => array( $label_id ),
				'post_title'   => 'Application path task',
				'post_content' => 'Application path legacy description',
			)
		);

		$result = Decker_Task_Writer::create_or_update_task(
			array(
				'id'             => $task_id,
				'title'          => 'Application path task updated',
				'description'    => 'Application path updated description',
				'stack'          => 'in-progress',
				'board'          => $board_id,
				'max_priority'   => true,
				'duedate'        => new DateTime( '2032-04-05' ),
				'author'         => $this->editor_id,
				'responsable'    => $this->editor_id,
				'hidden'         => false,
				'assigned_users' => array( $this->editor_id ),
				'labels'         => array( $label_id ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assert_revision_content_exists( $task_id, 'Application path legacy description' );
		$this->assert_revision_content_exists( $task_id, 'Application path updated description' );
	}

	/**
	 * Test restoration of title, content, and excerpt through the public API.
	 */
	public function test_revision_can_restore_revisionable_task_fields() {
		$task_id = $this->create_task(
			array(
				'post_title'   => 'Initial title',
				'post_content' => 'Initial description',
			)
		);
		$this->set_post_excerpt_without_update( $task_id, 'Initial excerpt' );

		$versions = array(
			array( 'Version 1 title', 'Description version 1', 'Version 1 excerpt' ),
			array( 'Version 2 title', 'Description version 2', 'Version 2 excerpt' ),
			array( 'Version 3 title', 'Description version 3', 'Version 3 excerpt' ),
		);

		foreach ( $versions as $version ) {
			wp_update_post(
				array(
					'ID'           => $task_id,
					'post_title'   => $version[0],
					'post_content' => $version[1],
					'post_excerpt' => $version[2],
				)
			);
		}

		$revision = $this->assert_revision_state_exists(
			$task_id,
			'Version 1 title',
			'Description version 1',
			'Version 1 excerpt'
		);
		$restored = wp_restore_post_revision( $revision->ID );

		$this->assertSame( $task_id, $restored );
		clean_post_cache( $task_id );
		$post = get_post( $task_id );
		$this->assertSame( 'Version 1 title', $post->post_title );
		$this->assertSame( 'Description version 1', $post->post_content );
		$this->assertSame( 'Version 1 excerpt', $post->post_excerpt );
	}

	/**
	 * Test that restoring content leaves Decker metadata and relationships intact.
	 */
	public function test_revision_restoration_does_not_restore_decker_data() {
		$fixture = $this->create_legacy_task_fixture();
		$task_id = $fixture['task_id'];

		wp_update_post(
			array(
				'ID'           => $task_id,
				'post_title'   => 'Current title',
				'post_content' => 'Current description',
				'post_excerpt' => 'Current excerpt',
			)
		);
		$legacy_revision = $this->assert_revision_content_exists(
			$task_id,
			'Legacy description before revision support'
		);

		$current_board = self::factory()->board->create();
		$current_label = self::factory()->label->create();
		$current_meta  = array(
			'stack'                => 'done',
			'duedate'              => '2035-06-07',
			'max_priority'         => '0',
			'responsable'          => $this->editor_id,
			'assigned_users'       => array( $this->editor_id ),
			'hidden'               => '0',
			'attachments'          => array( 901, 902 ),
			'_user_date_relations' => array(
				array(
					'user_id' => $this->editor_id,
					'date'    => '2035-06-07',
				),
			),
			'id_nextcloud_card'    => 'nextcloud-current',
		);

		foreach ( $current_meta as $key => $value ) {
			update_post_meta( $task_id, $key, $value );
		}
		wp_set_object_terms( $task_id, array( $current_board ), 'decker_board', false );
		wp_set_object_terms( $task_id, array( $current_label ), 'decker_label', false );

		$attachment_id = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $task_id,
				'post_title'  => 'Task attachment',
			)
		);
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $task_id,
				'comment_content' => 'Task comment remains attached.',
				'user_id'         => $this->editor_id,
			)
		);

		$before = $this->snapshot_decker_data( $task_id );
		$result = wp_restore_post_revision( $legacy_revision->ID );

		$this->assertSame( $task_id, $result );
		$this->assertSame( $before, $this->snapshot_decker_data( $task_id ) );
		$this->assertSame( $task_id, (int) get_post( $attachment_id )->post_parent );
		$this->assertSame( $task_id, (int) get_comment( $comment_id )->comment_post_ID );
	}

	/**
	 * Test that the baseline hook ignores every unrelated post type.
	 */
	public function test_baseline_ignores_unsupported_post_types() {
		$post_ids = array(
			self::factory()->post->create( array( 'post_type' => 'post' ) ),
			self::factory()->post->create( array( 'post_type' => 'page' ) ),
			self::factory()->post->create( array( 'post_type' => 'decker_event' ) ),
			self::factory()->post->create( array( 'post_type' => 'decker_kb' ) ),
		);

		foreach ( $post_ids as $post_id ) {
			$before = $this->snapshot_core_post( $post_id );
			do_action( 'pre_post_update', $post_id, array() );
			clean_post_cache( $post_id );
			$this->assertSame( $before, $this->snapshot_core_post( $post_id ) );
			$this->assertSame( array(), $this->get_regular_revisions( $post_id ) );
		}
	}

	/**
	 * Test invalid, revision, and autosave IDs do not recurse or create children.
	 */
	public function test_baseline_guards_invalid_revision_and_autosave_posts() {
		$task_id = $this->create_task( array( 'post_content' => 'Guarded parent content' ) );
		do_action( 'pre_post_update', $task_id, array() );
		$revision = $this->assert_revision_content_exists( $task_id, 'Guarded parent content' );

		do_action( 'pre_post_update', 0, array() );
		do_action( 'pre_post_update', 99999999, array() );
		do_action( 'pre_post_update', $revision->ID, array() );
		$this->assertSame( array(), wp_get_post_revisions( $revision->ID ) );

		$autosave_id = wp_create_post_autosave(
			array(
				'post_ID'      => $task_id,
				'post_type'    => 'decker_task',
				'post_title'   => 'Autosaved title',
				'post_content' => 'Autosaved content',
				'post_excerpt' => 'Autosaved excerpt',
			)
		);
		$this->assertNotWPError( $autosave_id );
		$this->assertNotFalse( wp_is_post_autosave( $autosave_id ) );

		do_action( 'pre_post_update', $autosave_id, array() );
		$this->assertSame( array(), wp_get_post_revisions( $autosave_id ) );
		$this->assertSame( 1, $this->count_revisions_with_content( $task_id, 'Guarded parent content' ) );
	}

	/**
	 * Test archived tasks retain their status while receiving revisions.
	 */
	public function test_archived_task_updates_preserve_status_and_create_revisions() {
		$task_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_task',
				'post_status'  => 'archived',
				'post_title'   => 'Archived task',
				'post_content' => 'Archived legacy description',
			)
		);

		$result = wp_update_post(
			array(
				'ID'           => $task_id,
				'post_content' => 'Archived updated description',
			),
			true
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'archived', get_post_status( $task_id ) );
		$this->assert_revision_content_exists( $task_id, 'Archived legacy description' );
		$this->assert_revision_content_exists( $task_id, 'Archived updated description' );
	}

	/**
	 * Test that native revision settings can disable baseline storage.
	 */
	public function test_baseline_respects_native_revision_settings() {
		$task_id = $this->create_task( array( 'post_content' => 'Revisions disabled content' ) );
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$result = wp_update_post(
			array(
				'ID'           => $task_id,
				'post_content' => 'Update without revisions',
			),
			true
		);

		remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Update without revisions', get_post( $task_id )->post_content );
		$this->assertSame( array(), $this->get_regular_revisions( $task_id ) );
	}

	/**
	 * Create a task through the custom Decker task factory.
	 *
	 * @param array $overrides Task field overrides.
	 * @return int Task post ID.
	 */
	private function create_task( array $overrides = array() ): int {
		$task_id = self::factory()->task->create( $overrides );
		$this->assertIsInt( $task_id );
		$this->assertGreaterThan( 0, $task_id );

		return $task_id;
	}

	/**
	 * Create a representative task that predates revision support.
	 *
	 * @return array{task_id:int,board_id:int,label_ids:array<int>}
	 */
	private function create_legacy_task_fixture(): array {
		$board_id  = self::factory()->board->create();
		$label_ids = array(
			self::factory()->label->create(),
			self::factory()->label->create(),
		);
		$task_id   = self::factory()->post->create(
			array(
				'post_type'         => 'decker_task',
				'post_status'       => 'publish',
				'post_title'        => 'Legacy task title',
				'post_content'      => 'Legacy description before revision support',
				'post_excerpt'      => 'Legacy task excerpt',
				'post_author'       => $this->editor_id,
				'post_date'         => '2024-02-03 04:05:06',
				'post_date_gmt'     => '2024-02-03 04:05:06',
				'post_modified'     => '2024-03-04 05:06:07',
				'post_modified_gmt' => '2024-03-04 05:06:07',
				'menu_order'        => 37,
				'post_parent'       => 0,
				'post_name'         => 'legacy-task-slug',
			)
		);

		$metadata = array(
			'stack'                => 'in-progress',
			'duedate'              => '2030-01-02',
			'max_priority'         => '1',
			'responsable'          => $this->editor_id,
			'assigned_users'       => array( $this->editor_id ),
			'hidden'               => '1',
			'attachments'          => array( 101, 202 ),
			'_user_date_relations' => array(
				array(
					'user_id' => $this->editor_id,
					'date'    => '2030-01-02',
				),
			),
			'id_nextcloud_card'    => 'nextcloud-legacy',
			'merged_into'          => '0',
		);

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $task_id, $key, $value );
		}
		wp_set_object_terms( $task_id, array( $board_id ), 'decker_board', false );
		wp_set_object_terms( $task_id, $label_ids, 'decker_label', false );
		clean_post_cache( $task_id );

		return array(
			'task_id'   => $task_id,
			'board_id'  => $board_id,
			'label_ids' => $label_ids,
		);
	}

	/**
	 * Set an excerpt without simulating a future post update.
	 *
	 * This is fixture setup for a task that existed before revision support.
	 *
	 * @param int    $task_id Task post ID.
	 * @param string $excerpt Task excerpt.
	 */
	private function set_post_excerpt_without_update( int $task_id, string $excerpt ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_excerpt' => $excerpt ),
			array( 'ID' => $task_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $task_id );
	}

	/**
	 * Return regular revisions while excluding autosaves.
	 *
	 * @param int $post_id Parent post ID.
	 * @return array<int,WP_Post> Regular revision posts keyed by revision ID.
	 */
	private function get_regular_revisions( int $post_id ): array {
		$regular_revisions = array();

		foreach ( wp_get_post_revisions( $post_id ) as $revision_id => $revision ) {
			if ( ! wp_is_post_autosave( $revision ) ) {
				$regular_revisions[ $revision_id ] = $revision;
			}
		}

		return $regular_revisions;
	}

	/**
	 * Assert that a regular revision contains the requested content.
	 *
	 * @param int    $post_id Parent post ID.
	 * @param string $content Expected revision content.
	 * @return WP_Post Matching revision.
	 */
	private function assert_revision_content_exists( int $post_id, string $content ): WP_Post {
		foreach ( $this->get_regular_revisions( $post_id ) as $revision ) {
			if ( $content === $revision->post_content ) {
				$this->assertSame( $post_id, (int) $revision->post_parent );
				$this->assertSame( 'revision', $revision->post_type );
				$this->assertSame( 'inherit', $revision->post_status );

				return $revision;
			}
		}

		$this->fail( sprintf( 'No regular revision contains content "%s".', $content ) );
	}

	/**
	 * Assert that a regular revision contains the requested field state.
	 *
	 * @param int    $post_id Parent post ID.
	 * @param string $title   Expected revision title.
	 * @param string $content Expected revision content.
	 * @param string $excerpt Expected revision excerpt.
	 * @return WP_Post Matching revision.
	 */
	private function assert_revision_state_exists( int $post_id, string $title, string $content, string $excerpt ): WP_Post {
		foreach ( $this->get_regular_revisions( $post_id ) as $revision ) {
			if (
				$title === $revision->post_title &&
				$content === $revision->post_content &&
				$excerpt === $revision->post_excerpt
			) {
				return $revision;
			}
		}

		$this->fail( sprintf( 'No regular revision contains the expected state for "%s".', $content ) );
	}

	/**
	 * Count regular revisions with exact content.
	 *
	 * @param int    $post_id Parent post ID.
	 * @param string $content Expected revision content.
	 * @return int Matching revision count.
	 */
	private function count_revisions_with_content( int $post_id, string $content ): int {
		$count = 0;

		foreach ( $this->get_regular_revisions( $post_id ) as $revision ) {
			if ( $content === $revision->post_content ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Snapshot all parent task data covered by the safety requirements.
	 *
	 * @param int $task_id Task post ID.
	 * @return array Normalized task snapshot.
	 */
	private function snapshot_task( int $task_id ): array {
		return array(
			'core'     => $this->snapshot_core_post( $task_id ),
			'decker'   => $this->snapshot_decker_data( $task_id ),
			'all_meta' => get_post_meta( $task_id ),
		);
	}

	/**
	 * Snapshot core parent post fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array Normalized core field snapshot.
	 */
	private function snapshot_core_post( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'ID'                => (int) $post->ID,
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_excerpt'      => $post->post_excerpt,
			'post_status'       => $post->post_status,
			'post_author'       => (int) $post->post_author,
			'post_date'         => $post->post_date,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified'     => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'menu_order'        => (int) $post->menu_order,
			'post_parent'       => (int) $post->post_parent,
			'post_name'         => $post->post_name,
		);
	}

	/**
	 * Snapshot representative Decker metadata and taxonomy assignments.
	 *
	 * @param int $task_id Task post ID.
	 * @return array Normalized Decker data snapshot.
	 */
	private function snapshot_decker_data( int $task_id ): array {
		$meta_keys = array(
			'stack',
			'duedate',
			'max_priority',
			'responsable',
			'assigned_users',
			'hidden',
			'attachments',
			'_user_date_relations',
			'id_nextcloud_card',
			'merged_into',
		);
		$metadata  = array();

		foreach ( $meta_keys as $key ) {
			$metadata[ $key ] = get_post_meta( $task_id, $key, true );
		}

		$boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		sort( $boards );
		sort( $labels );

		return array(
			'meta'   => $metadata,
			'boards' => array_map( 'intval', $boards ),
			'labels' => array_map( 'intval', $labels ),
		);
	}
}
