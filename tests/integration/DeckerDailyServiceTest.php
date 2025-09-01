<?php

class DeckerDailyServiceTest extends Decker_Test_Base {

	public function test_get_daily_summary() {
		$board = self::factory()->board->create();
		$this->assertNotWPError( $board );
		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();

		$date1 = '2025-09-15';
		$date2 = '2025-09-16';

		// Task 1: user1 on date1
		$task1 = self::factory()->task->create( array( 'meta_input' => array( 'stack' => 'to-do' ) ) );
		$this->assertNotWPError( $task1 );
		wp_set_post_terms( $task1, $board, 'decker_board' );
		add_post_meta( $task1, '_user_date_relations', array( array( 'user_id' => $user1, 'date' => $date1 ) ) );

		// Task 2: user2 on date1
		$task2 = self::factory()->task->create( array( 'meta_input' => array( 'stack' => 'to-do' ) ) );
		$this->assertNotWPError( $task2 );
		wp_set_post_terms( $task2, $board, 'decker_board' );
		add_post_meta( $task2, '_user_date_relations', array( array( 'user_id' => $user2, 'date' => $date1 ) ) );

		// Task 3: user1 on date2 (should not be included)
		$task3 = self::factory()->task->create( array( 'meta_input' => array( 'stack' => 'to-do' ) ) );
		$this->assertNotWPError( $task3 );
		wp_set_post_terms( $task3, $board, 'decker_board' );
		add_post_meta( $task3, '_user_date_relations', array( array( 'user_id' => $user1, 'date' => $date2 ) ) );

		$summary = Decker_Daily_Service::get_daily_summary( $board, $date1 );

		$this->assertCount( 2, $summary['tasks'] );
		$this->assertContains( $task1, $summary['tasks'] );
		$this->assertContains( $task2, $summary['tasks'] );

		$this->assertCount( 2, $summary['users'] );
		$this->assertContains( $user1, $summary['users'] );
		$this->assertContains( $user2, $summary['users'] );
	}

	public function test_upsert_notes() {
		$board = self::factory()->board->create();
		$this->assertNotWPError( $board );
		$user = self::factory()->user->create();
		$date = '2025-09-15';
		$task = self::factory()->task->create( array( 'meta_input' => array( 'stack' => 'to-do' ) ) );
		$this->assertNotWPError( $task );
		wp_set_post_terms( $task, $board, 'decker_board' );
		add_post_meta( $task, '_user_date_relations', array( array( 'user_id' => $user, 'date' => $date ) ) );

		// Test creating notes
		$notes_content = 'These are the notes for the day.';
		$result = Decker_Daily_Service::upsert_notes( $board, $date, $notes_content );
		$this->assertNotWPError( $result );
		$this->assertIsInt( $result );

		$journal_post = get_post( $result );
		$this->assertEquals( $notes_content, $journal_post->post_content );
		$this->assertEquals( $date, get_post_meta( $result, 'journal_date', true ) );

		// Test updating notes
		$updated_notes_content = 'These are the updated notes.';
		$result2 = Decker_Daily_Service::upsert_notes( $board, $date, $updated_notes_content );
		$this->assertEquals( $result, $result2 ); // Should be the same post ID
		$this->assertEquals( $updated_notes_content, get_post_field( 'post_content', $result ) );
	}

	public function test_upsert_notes_for_day_with_no_tasks() {
		$board = self::factory()->board->create();
		$this->assertNotWPError( $board );
		$date = '2025-09-15';
		$result = Decker_Daily_Service::upsert_notes( $board, $date, 'Some notes' );
		$this->assertWPError( $result );
		$this->assertEquals( 'no_tasks_for_day', $result->get_error_code() );
	}
}
