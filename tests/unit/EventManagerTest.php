<?php
/**
 * Class EventManagerTest
 *
 * @package Decker
 */

/**
 * EventManager test case.
 */
class EventManagerTest extends WP_UnitTestCase {

	/**
	 * Test event creation and retrieval
	 */
	public function test_create_and_get_event() {
		$title = 'Test Event';
		$description = 'Test Description';
		$start_date = new DateTime();
		$end_date = new DateTime( '+1 hour' );
		$location = 'Test Location';
		$url = 'https://example.com';
		$category = 'bg-success';
		$assigned_users = array( 1, 2, 3 );

		$event_id = EventManager::create_or_update_event(
			0,
			$title,
			$description,
			$start_date,
			$end_date,
			$location,
			$url,
			$category,
			$assigned_users
		);

		$this->assertGreaterThan( 0, $event_id );

		$event = EventManager::get_event( $event_id );

		$this->assertInstanceOf( Event::class, $event );
		$this->assertEquals( $title, $event->get_title() );
		$this->assertEquals( $description, $event->get_description() );
		$this->assertEquals( $start_date->format( 'Y-m-d H:i:s' ), $event->get_start_date()->format( 'Y-m-d H:i:s' ) );
		$this->assertEquals( $end_date->format( 'Y-m-d H:i:s' ), $event->get_end_date()->format( 'Y-m-d H:i:s' ) );
		$this->assertEquals( $location, $event->get_location() );
		$this->assertEquals( $url, $event->get_url() );
		$this->assertEquals( $category, $event->get_category() );
		$this->assertEquals( $assigned_users, $event->get_assigned_users() );
	}

	/**
	 * Test event update
	 */
	public function test_update_event() {
		$event_id = EventManager::create_or_update_event(
			0,
			'Original Title',
			'Original Description',
			new DateTime(),
			new DateTime( '+1 hour' )
		);

		$new_title = 'Updated Title';
		$new_description = 'Updated Description';
		$new_start_date = new DateTime( '+1 day' );
		$new_end_date = new DateTime( '+1 day +1 hour' );

		EventManager::create_or_update_event(
			$event_id,
			$new_title,
			$new_description,
			$new_start_date,
			$new_end_date
		);

		$updated_event = EventManager::get_event( $event_id );

		$this->assertEquals( $new_title, $updated_event->get_title() );
		$this->assertEquals( $new_description, $updated_event->get_description() );
		$this->assertEquals(
			$new_start_date->format( 'Y-m-d H:i:s' ),
			$updated_event->get_start_date()->format( 'Y-m-d H:i:s' )
		);
		$this->assertEquals(
			$new_end_date->format( 'Y-m-d H:i:s' ),
			$updated_event->get_end_date()->format( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Test get all events
	 */
	public function test_get_events() {
		// Create multiple events
		$event_ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$event_ids[] = EventManager::create_or_update_event(
				0,
				"Event $i",
				"Description $i",
				new DateTime(),
				new DateTime( '+1 hour' )
			);
		}

		$events = EventManager::get_events();

		$this->assertCount( 3, $events );
		foreach ( $events as $event ) {
			$this->assertInstanceOf( Event::class, $event );
		}
	}

	/**
	 * Test event deletion
	 */
	public function test_delete_event() {
		$event_id = EventManager::create_or_update_event(
			0,
			'Event to Delete',
			'Description',
			new DateTime(),
			new DateTime( '+1 hour' )
		);

		$this->assertTrue( EventManager::delete_event( $event_id ) );
		$this->assertNull( EventManager::get_event( $event_id ) );
	}
}
