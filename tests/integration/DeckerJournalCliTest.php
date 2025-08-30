<?php

class DeckerJournalCliTest extends Decker_Test_Base {

	public function test_create_command() {
		$board = self::factory()->board->create( array( 'name' => 'CLI Test Board' ) );

		$assoc_args = array(
			'board' => $board,
			'title' => 'CLI Journal Entry',
			'date' => '2025-09-10',
			'topic' => 'CLI Test Topic',
			'attendees' => 'User1,User2',
		);

		$result = WP_CLI::run_command( 'decker journal create', array(
			'assoc_args' => $assoc_args,
			'return' => 'all'
		) );

		$this->assertStringContainsString( 'Journal entry created successfully!', $result->stdout );

		$post = get_page_by_title( 'CLI Journal Entry', OBJECT, 'decker_journal' );
		$this->assertNotNull( $post );

		$this->assertEquals( 'CLI Test Topic', get_post_meta( $post->ID, 'topic', true ) );
		$this->assertEquals( array( 'User1', 'User2' ), get_post_meta( $post->ID, 'attendees', true ) );

		// Test idempotency
		$result_again = WP_CLI::run_command( 'decker journal create', array(
			'assoc_args' => $assoc_args,
			'return' => 'all'
		) );
		$this->assertStringContainsString( 'A journal entry for this board and date already exists.', $result_again->stdout );

		// Test --force
		$assoc_args['force'] = true;
		$result_force = WP_CLI::run_command( 'decker journal create', array(
			'assoc_args' => $assoc_args,
			'return' => 'all'
		) );
		$this->assertStringContainsString( 'Journal entry created successfully!', $result_force->stdout );
	}
}
