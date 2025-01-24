<?php
/**
 * Class EventTest
 *
 * @package Decker
 */

/**
 * Event test case.
 */
class EventTest extends WP_UnitTestCase {

	/**
	 * Test event creation and getters
	 */
	public function test_event_creation_and_getters() {
		$id = 1;
		$title = 'Test Event';
		$description = 'Test Description';
		$start_date = new DateTime();
		$end_date = new DateTime( '+1 hour' );
		$location = 'Test Location';
		$url = 'https://example.com';
		$category = 'bg-success';
		$assigned_users = array( 1, 2, 3 );

		$event = new Event(
			$id,
			$title,
			$description,
			$start_date,
			$end_date,
			$location,
			$url,
			$category,
			$assigned_users
		);

		$this->assertEquals( $id, $event->get_id() );
		$this->assertEquals( $title, $event->get_title() );
		$this->assertEquals( $description, $event->get_description() );
		$this->assertEquals( $start_date, $event->get_start_date() );
		$this->assertEquals( $end_date, $event->get_end_date() );
		$this->assertEquals( $location, $event->get_location() );
		$this->assertEquals( $url, $event->get_url() );
		$this->assertEquals( $category, $event->get_category() );
		$this->assertEquals( $assigned_users, $event->get_assigned_users() );
	}

	/**
	 * Test event to array conversion
	 */
	public function test_event_to_array() {
		$id = 1;
		$title = 'Test Event';
		$description = 'Test Description';
		$start_date = new DateTime( '2024-01-24 10:00:00' );
		$end_date = new DateTime( '2024-01-24 11:00:00' );
		$location = 'Test Location';
		$url = 'https://example.com';
		$category = 'bg-success';
		$assigned_users = array( 1, 2, 3 );

		$event = new Event(
			$id,
			$title,
			$description,
			$start_date,
			$end_date,
			$location,
			$url,
			$category,
			$assigned_users
		);

		$array = $event->to_array();

		$this->assertEquals(
			array(
				'id'             => $id,
				'title'          => $title,
				'description'    => $description,
				'start'          => '2024-01-24T10:00:00',
				'end'            => '2024-01-24T11:00:00',
				'location'       => $location,
				'url'            => $url,
				'className'      => $category,
				'assigned_users' => $assigned_users,
			),
			$array
		);
	}
}
