<?php
/**
 * Shared Decker events exposed through per-account calendar homes.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Uses sabre's recurrence-aware calendar-query and multiget implementations. */
class Decker_Dav_Backend extends Sabre\CalDAV\Backend\AbstractBackend {
	/** Check the site and account collection boundary.
	 *
	 * @param mixed $calendar_id Calendar ID.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	private function guard( $calendar_id ) {
		if ( ! Decker_Dav::allowed() || (string) get_current_blog_id() !== (string) $calendar_id ) {
			throw new Sabre\DAV\Exception\Forbidden();
		}
	}
	/** Discover one shared collection, without duplicating posts.
	 *
	 * @param string $principal_uri Principal.
	 * @return array
	 */
	public function getCalendarsForUser( $principal_uri ) {
		if ( ! Decker_Dav::allowed() || 'principals/' . wp_get_current_user()->user_login !== $principal_uri ) {
			return array();
		}
		return array(
			array(
				'id' => get_current_blog_id(),
				'uri' => 'decker',
				'principaluri' => $principal_uri,
				'{DAV:}displayname' => 'Decker',
				'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet( array( 'VEVENT' ) ),
			),
		);
	}
	/** Calendar creation is unsupported.
	 *
	 * @param string $principal_uri Principal.
	 * @param string $calendar_uri URI.
	 * @param array  $properties Properties.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	public function createCalendar( $principal_uri, $calendar_uri, array $properties ) {
		throw new Sabre\DAV\Exception\Forbidden(); }
	/** Collection removal is unsupported.
	 *
	 * @param mixed $calendar_id ID.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	public function deleteCalendar( $calendar_id ) {
		throw new Sabre\DAV\Exception\Forbidden(); }
	/** List only individually readable published events.
	 *
	 * @param mixed $calendar_id ID.
	 * @return array
	 */
	public function getCalendarObjects( $calendar_id ) {
		$this->guard( $calendar_id );
		$result = array();
		foreach ( Decker_Events::get_events() as $row ) {
			$id = $row['post']->ID;
			if ( empty( $row['meta']['event_start'][0] ) ) {
				continue; }
			if ( current_user_can( 'read_post', $id ) ) {
				Decker_Event_Store::resource( $id );
				$result[] = $this->object( $id );
			}
		}
		return $result;
	}
	/** Build the stable representation; metadata participates in its ETag.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private function object( $id ) {
		$data = Decker_Event_Store::resource( $id );
		return array(
			'id' => $id,
			'uri' => get_post_meta( $id, '_decker_dav_uri', true ),
			'calendarid' => get_current_blog_id(),
			'calendardata' => $data,
			'etag' => '"' . hash( 'sha256', $data . wp_json_encode( get_post_meta( $id ) ) ) . '"',
			'size' => strlen( $data ),
			'lastmodified' => (int) get_post_modified_time( 'U', true, $id ),
		);
	}
	/** Resolve by URI, never by a client-supplied post ID.
	 *
	 * @param mixed  $calendar_id ID.
	 * @param string $object_uri URI.
	 * @return array|null
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	public function getCalendarObject( $calendar_id, $object_uri ) {
		$this->guard( $calendar_id );
		$posts = get_posts(
			array(
				'post_type' => 'decker_event',
				'post_status' => 'publish',
				'meta_key' => '_decker_dav_uri',
				'meta_value' => $object_uri,
				'numberposts' => 1,
			)
		);
		if ( ! $posts ) {
			return null; }
		if ( ! current_user_can( 'read_post', $posts[0]->ID ) ) {
			throw new Sabre\DAV\Exception\Forbidden(); }
		return $this->object( $posts[0]->ID );
	}
	/** Create an event with the client's UID and URI.
	 *
	 * @param mixed  $calendar_id ID.
	 * @param string $object_uri URI.
	 * @param string $calendar_data Data.
	 * @return string
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\PreconditionFailed Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\Conflict Invalid or forbidden calendar operation.
	 */
	public function createCalendarObject( $calendar_id, $object_uri, $calendar_data ) {
		$this->guard( $calendar_id );
		if ( ! current_user_can( 'publish_posts' ) || ! preg_match( '/^[^\/\\\\\x00-\x1f]{1,200}\.ics$/D', $object_uri ) ) {
			throw new Sabre\DAV\Exception\Forbidden(); }
		if ( $this->getCalendarObject( $calendar_id, $object_uri ) ) {
			throw new Sabre\DAV\Exception\PreconditionFailed( 'Resource already exists.' ); }
		$uid = (string) Decker_Event_Store::master( Decker_Event_Store::parse( $calendar_data ) )->{'UID'};
		foreach ( $this->getCalendarObjects( $calendar_id ) as $object ) {
			if ( (string) Decker_Event_Store::master( Decker_Event_Store::parse( $object['calendardata'] ) )->{'UID'} === $uid ) {
				throw new Sabre\DAV\Exception\Conflict( 'UID already exists.' ); }
		}
		$id = Decker_Event_Store::put( 0, $object_uri, $calendar_data );
		return $this->object( $id )['etag'];
	}
	/** Update with per-post capability checks.
	 *
	 * @param mixed  $calendar_id ID.
	 * @param string $object_uri URI.
	 * @param string $calendar_data Data.
	 * @return string
	 * @throws Sabre\DAV\Exception\NotFound Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	public function updateCalendarObject( $calendar_id, $object_uri, $calendar_data ) {
		$object = $this->getCalendarObject( $calendar_id, $object_uri );
		if ( ! $object ) {
			throw new Sabre\DAV\Exception\NotFound(); }
		if ( ! current_user_can( 'edit_post', $object['id'] ) ) {
			throw new Sabre\DAV\Exception\Forbidden(); }
		Decker_Event_Store::put( $object['id'], $object_uri, $calendar_data );
		return $this->object( $object['id'] )['etag'];
	}
	/** Trash the shared post, retaining WordPress recovery.
	 *
	 * @param mixed  $calendar_id ID.
	 * @param string $object_uri URI.
	 * @throws Sabre\DAV\Exception\NotFound Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\ServiceUnavailable Invalid or forbidden calendar operation.
	 */
	public function deleteCalendarObject( $calendar_id, $object_uri ) {
		$object = $this->getCalendarObject( $calendar_id, $object_uri );
		if ( ! $object ) {
			throw new Sabre\DAV\Exception\NotFound(); }
		if ( ! current_user_can( 'delete_post', $object['id'] ) ) {
			throw new Sabre\DAV\Exception\Forbidden(); }
		if ( ! wp_trash_post( $object['id'] ) ) {
			throw new Sabre\DAV\Exception\ServiceUnavailable(); }
	}
}
