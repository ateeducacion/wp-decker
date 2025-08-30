<?php

class DeckerJournalCliTest extends Decker_Test_Base {

	public function test_create_command() {
		$board = self::factory()->board->create();
		$cli_command = new Decker_Journal_CLI();

		$assoc_args = array(
			'board' => $board,
			'title' => 'CLI Journal Entry',
			'date'  => '2025-09-10',
			'topic' => 'CLI Test Topic',
		);

		// Test successful creation
		$result = $cli_command->create_journal_entry( $assoc_args );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Journal entry created successfully!', $result['message'] );

		$post = get_page_by_title( 'CLI Journal Entry', OBJECT, 'decker_journal' );
		$this->assertNotNull( $post );
		$this->assertEquals( 'CLI Test Topic', get_post_meta( $post->ID, 'topic', true ) );

		// Test idempotency
		$result_again = $cli_command->create_journal_entry( $assoc_args );
		$this->assertFalse( $result_again['success'] );
		$this->assertEquals( 'warning', $result_again['type'] );

		// Test --force
		$assoc_args['force'] = true;
		$result_force = $cli_command->create_journal_entry( $assoc_args );
		$this->assertTrue( $result_force['success'] );
	}
}
