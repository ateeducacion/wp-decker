<?php
/** CalDAV protocol and domain behavior. */
class DeckerDavTest extends WP_UnitTestCase {
	private $user;
	private $token = 'disposable-test-token';
	public function set_up() {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'role' => 'editor', 'user_login' => 'davtester' ) );
		update_user_meta( $this->user, 'decker_calendar_token', $this->token );
		update_user_meta( $this->user, 'decker_calendar_dav_enabled', true );
		Decker_Dav::server();
	}
	private function data( $extra = '', $dates = "DTSTART:20261024T100000Z\r\nDTEND:20261024T110000Z" ) {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Decker test//EN\r\nBEGIN:VEVENT\r\nUID:client-uid@example.test\r\nDTSTAMP:20261001T120000Z\r\nSUMMARY:Client event\r\n$dates\r\n$extra" . "END:VEVENT\r\nEND:VCALENDAR\r\n";
	}
	private function request( $method, $path = '', $body = '', $headers = array(), $password = 'disposable-test-token' ) {
		$server = Decker_Dav::server();
		$headers['Authorization'] = 'Basic ' . base64_encode( 'davtester:' . $password );
		$server->httpRequest = new Sabre\HTTP\Request( $method, '/decker-dav/' . $path, $headers, $body );
		$server->httpResponse = new Sabre\HTTP\Response();
		$server->sapi = new class extends Sabre\HTTP\Sapi {
			public static function sendResponse( Sabre\HTTP\ResponseInterface $response ) {}
		};
		$server->exec();
		return $server->httpResponse;
	}
	public function test_authentication_is_token_only_and_rechecks_revocation() {
		foreach ( array( '', 'wrong' ) as $token ) {
			$response = $this->request( 'PROPFIND', '', '', array( 'Depth' => '0' ), $token );
			$this->assertSame( 401, $response->getStatus() );
			$this->assertStringContainsString( 'Basic', $response->getHeader( 'WWW-Authenticate' ) );
		}
		$this->assertSame( 207, $this->request( 'PROPFIND', '', '', array( 'Depth' => '0' ) )->getStatus() );
		update_user_meta( $this->user, 'decker_calendar_token', 'replacement' );
		$this->assertSame( 401, $this->request( 'PROPFIND' )->getStatus() );
		delete_user_meta( $this->user, 'decker_calendar_token' );
		$this->assertSame( 401, $this->request( 'PROPFIND' )->getStatus() );
	}
	public function test_protocol_crud_conditions_and_shared_wordpress_projection() {
		$path = 'calendars/davtester/decker/client-chosen.ics';
		$response = $this->request( 'PUT', $path, $this->data(), array( 'If-None-Match' => '*', 'Content-Type' => 'text/calendar' ) );
		$this->assertSame( 201, $response->getStatus(), (string) $response->getBodyAsString() );
		$get = $this->request( 'GET', $path );
		$etag = $get->getHeader( 'ETag' );
		$this->assertSame( $etag, $this->request( 'GET', $path )->getHeader( 'ETag' ) );
		$this->assertStringContainsString( 'UID:client-uid@example.test', $get->getBodyAsString() );
		$this->assertSame( 412, $this->request( 'PUT', $path, $this->data(), array( 'If-None-Match' => '*' ) )->getStatus() );
		$this->assertSame( 412, $this->request( 'PUT', $path, $this->data(), array( 'If-Match' => '"stale"' ) )->getStatus() );
		$posts = get_posts( array( 'post_type' => 'decker_event' ) );
		$id = $posts[0]->ID;
		$this->assertSame( $this->user, (int) $posts[0]->post_author );
		$this->assertSame( array( $this->user ), get_post_meta( $id, 'event_assigned_users', true ) );
		update_post_meta( $id, 'event_category', 'bg-info' );
		$new = $this->request( 'GET', $path )->getHeader( 'ETag' );
		$this->assertNotSame( $etag, $new );
		$this->assertSame( 204, $this->request( 'PUT', $path, str_replace( 'Client event', 'Changed', $this->data() ), array( 'If-Match' => $new ) )->getStatus() );
		$this->assertSame( 'Changed', get_the_title( $id ) );
		$this->assertSame( 'bg-info', get_post_meta( $id, 'event_category', true ) );
		$this->assertSame( 412, $this->request( 'DELETE', $path, '', array( 'If-Match' => '"stale"' ) )->getStatus() );
		$this->assertSame( 204, $this->request( 'DELETE', $path )->getStatus() );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}
	public function test_all_day_and_opaque_properties_survive_web_edits() {
		wp_set_current_user( $this->user );
		$data = $this->data( "X-APPLE-CUSTOM:test\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Reminder\r\nEND:VALARM\r\n", "DTSTART;VALUE=DATE:20261104\r\nDTEND;VALUE=DATE:20261105" );
		$id = Decker_Event_Store::put( 0, 'one-day.ics', $data );
		$this->assertSame( '2026-11-05', get_post_meta( $id, 'event_end', true ) );
		Decker_Event_Store::web_meta( $id, array( 'event_location' => 'Office' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Web edit' ) );
		$stored = Decker_Event_Store::resource( $id );
		$this->assertStringContainsString( 'BEGIN:VALARM', $stored );
		$this->assertStringContainsString( 'X-APPLE-CUSTOM:test', $stored );
		$this->assertStringContainsString( 'DTEND;VALUE=DATE:20261105', $stored );
	}
	public function test_recurrence_expands_across_canary_dst_and_exclusions() {
		wp_set_current_user( $this->user );
		$data = $this->data( "RRULE:FREQ=DAILY;COUNT=4\r\nEXDATE;TZID=Atlantic/Canary:20261025T100000\r\n", "DTSTART;TZID=Atlantic/Canary:20261024T100000\r\nDTEND;TZID=Atlantic/Canary:20261024T110000" );
		Decker_Event_Store::put( 0, 'series.ics', $data );
		$rows = ( new Decker_Calendar() )->get_occurrences( 'event', '2026-10-24T00:00:00Z', '2026-10-28T00:00:00Z' );
		$this->assertCount( 3, $rows );
		$this->assertSame( '2026-10-24T09:00:00+00:00', $rows[0]['start'] );
		$this->assertSame( '2026-10-26T10:00:00+00:00', $rows[1]['start'] );
	}
	public function test_old_subscription_token_does_not_gain_write_access() {
		delete_user_meta( $this->user, 'decker_calendar_dav_enabled' );
		$this->assertSame( 401, $this->request( 'PROPFIND' )->getStatus() );
	}
	public function test_discovery_multiget_and_time_range_report() {
		$path = 'calendars/davtester/decker/report.ics';
		$this->assertSame( 201, $this->request( 'PUT', $path, $this->data() )->getStatus() );
		$this->assertSame( 207, $this->request( 'PROPFIND', 'calendars/davtester/', '', array( 'Depth' => '1' ) )->getStatus() );
		$xml = '<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><d:href>/decker-dav/' . $path . '</d:href></c:calendar-multiget>';
		$response = $this->request( 'REPORT', 'calendars/davtester/decker/', $xml, array( 'Content-Type' => 'application/xml', 'Depth' => '1' ) );
		$this->assertSame( 207, $response->getStatus(), $response->getBodyAsString() );
		$this->assertStringContainsString( 'client-uid@example.test', $response->getBodyAsString() );
		$xml = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="20261024T000000Z" end="20261025T000000Z"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
		$response = $this->request( 'REPORT', 'calendars/davtester/decker/', $xml, array( 'Content-Type' => 'application/xml', 'Depth' => '1' ) );
		$this->assertSame( 207, $response->getStatus(), $response->getBodyAsString() );
		$this->assertStringContainsString( 'report.ics', $response->getBodyAsString() );
	}
	public function test_other_account_token_and_insufficient_role_are_rejected() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $other, 'decker_calendar_token', 'other-token' );
		$this->assertSame( 401, $this->request( 'PROPFIND', '', '', array(), 'other-token' )->getStatus() );
		$user = new WP_User( $this->user );
		$user->set_role( 'subscriber' );
		$this->assertSame( 401, $this->request( 'PROPFIND' )->getStatus() );
	}
	public function test_another_principal_is_not_discoverable() {
		$response = $this->request( 'PROPFIND', 'calendars/someone-else/', '', array( 'Depth' => '1' ) );
		$this->assertContains( $response->getStatus(), array( 403, 404 ) );
	}
	public function test_duration_and_modified_occurrence_are_preserved() {
		wp_set_current_user( $this->user );
		$data = $this->data( "RRULE:FREQ=DAILY;COUNT=3\r\n", "DTSTART;TZID=Atlantic/Canary:20261024T100000\r\nDURATION:PT30M" );
		$exception = "BEGIN:VEVENT\r\nUID:client-uid@example.test\r\nDTSTAMP:20261001T120000Z\r\nRECURRENCE-ID;TZID=Atlantic/Canary:20261025T100000\r\nDTSTART;TZID=Atlantic/Canary:20261025T150000\r\nDURATION:PT30M\r\nSUMMARY:Exception\r\nEND:VEVENT\r\n";
		$data = str_replace( "END:VCALENDAR", $exception . 'END:VCALENDAR', $data );
		$id = Decker_Event_Store::put( 0, 'exception.ics', $data );
		Decker_Event_Store::web_meta( $id, array( 'event_location' => 'Office' ) );
		$rows = ( new Decker_Calendar() )->get_occurrences( 'event', '2026-10-24', '2026-10-27' );
		$this->assertCount( 3, $rows );
		$this->assertContains( 'Exception', wp_list_pluck( $rows, 'title' ) );
		$this->assertStringContainsString( 'RECURRENCE-ID', Decker_Event_Store::resource( $id ) );
	}
	public function test_migration_is_identified_and_idempotent() {
		$id = self::factory()->post->create( array( 'post_type' => 'decker_event', 'post_status' => 'publish' ) );
		update_post_meta( $id, 'event_allday', true );
		update_post_meta( $id, 'event_start', '2026-11-04' );
		update_post_meta( $id, 'event_end', '2026-11-04' );
		delete_option( 'decker_calendar_dates_v2' );
		Decker_Dav::migrate();
		delete_option( 'decker_calendar_dates_v2' );
		Decker_Dav::migrate();
		$this->assertSame( '2026-11-05', get_post_meta( $id, 'event_end', true ) );
	}

	public function test_rdate_occurrences_are_expanded() {
		wp_set_current_user( $this->user );
		Decker_Event_Store::put( 0, 'rdates.ics', $this->data( "RDATE:20261026T100000Z,20261028T100000Z\r\n" ) );
		$rows = ( new Decker_Calendar() )->get_occurrences( 'event', '2026-10-24', '2026-10-29' );
		$this->assertCount( 3, $rows );
	}
	public function test_combined_rule_and_dates_are_explicitly_rejected() {
		$this->expectException( Sabre\DAV\Exception\BadRequest::class );
		Decker_Event_Store::parse( $this->data( "RRULE:FREQ=DAILY;COUNT=2\r\nRDATE:20261028T100000Z\r\n" ) );
	}

	public function test_invalid_ical_date_is_rejected_without_normalizing() {
		$this->expectException( Sabre\DAV\Exception\BadRequest::class );
		Decker_Event_Store::parse( $this->data( '', "DTSTART;VALUE=DATE:20260230\r\nDTEND;VALUE=DATE:20260303" ) );
	}

	public function test_backend_rejects_another_site_collection() {
		wp_set_current_user( $this->user );
		$this->expectException( Sabre\DAV\Exception\Forbidden::class );
		( new Decker_Dav_Backend() )->getCalendarObjects( get_current_blog_id() + 1 );
	}

	/** @dataProvider unsupported_collection_operations */
	public function test_collection_structure_cannot_be_changed( $operation ) {
		wp_set_current_user( $this->user );
		$backend = new Decker_Dav_Backend();
		$this->expectException( Sabre\DAV\Exception\Forbidden::class );
		if ( 'create' === $operation ) {
			$backend->createCalendar( 'principals/davtester', 'second', array() );
		} else {
			$backend->deleteCalendar( get_current_blog_id() );
		}
	}
	public function unsupported_collection_operations() { return array( array( 'create' ), array( 'delete' ) ); }

	public function test_duplicate_uid_and_uri_do_not_create_or_overwrite_posts() {
		wp_set_current_user( $this->user );
		$backend = new Decker_Dav_Backend();
		$site = get_current_blog_id();
		$etag = $backend->createCalendarObject( $site, 'original.ics', $this->data() );
		foreach ( array( 'original.ics' => Sabre\DAV\Exception\PreconditionFailed::class, 'duplicate.ics' => Sabre\DAV\Exception\Conflict::class ) as $uri => $exception ) {
			try {
				$backend->createCalendarObject( $site, $uri, $this->data() );
				$this->fail( 'Duplicate creation must fail.' );
			} catch ( Sabre\DAV\Exception $error ) {
				$this->assertInstanceOf( $exception, $error );
			}
		}
		$this->assertCount( 1, $backend->getCalendarObjects( $site ) );
		$this->assertSame( $etag, $backend->getCalendarObject( $site, 'original.ics' )['etag'] );
	}

	/** @dataProvider missing_object_operations */
	public function test_missing_resources_cannot_be_updated_or_deleted( $operation ) {
		wp_set_current_user( $this->user );
		$backend = new Decker_Dav_Backend();
		$this->expectException( Sabre\DAV\Exception\NotFound::class );
		if ( 'update' === $operation ) {
			$backend->updateCalendarObject( get_current_blog_id(), 'missing.ics', $this->data() );
		} else {
			$backend->deleteCalendarObject( get_current_blog_id(), 'missing.ics' );
		}
	}
	public function missing_object_operations() { return array( array( 'update' ), array( 'delete' ) ); }

	public function test_author_cannot_modify_another_authors_shared_event() {
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner );
		$id = Decker_Event_Store::put( 0, 'shared.ics', $this->data() );
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'author' ) );
		( new WP_User( $this->user ) )->set_role( 'author' );
		$path = 'calendars/davtester/decker/shared.ics';
		$this->assertSame( 200, $this->request( 'GET', $path )->getStatus() );
		$this->assertSame( 403, $this->request( 'PUT', $path, $this->data() )->getStatus() );
		$this->assertSame( 403, $this->request( 'DELETE', $path )->getStatus() );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_creation_rejects_invalid_names_and_users_without_publish_capability() {
		wp_set_current_user( $this->user );
		$backend = new Decker_Dav_Backend();
		try {
			$backend->createCalendarObject( get_current_blog_id(), '../invalid.ics', $this->data() );
			$this->fail( 'An invalid URI must be rejected.' );
		} catch ( Sabre\DAV\Exception\Forbidden $error ) {
			$this->assertSame( array(), $backend->getCalendarObjects( get_current_blog_id() ) );
		}
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'contributor' ) );
		( new WP_User( $this->user ) )->set_role( 'contributor' );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user );
		$this->expectException( Sabre\DAV\Exception\Forbidden::class );
		$backend->createCalendarObject( get_current_blog_id(), 'valid.ics', $this->data() );
	}

	public function test_unreadable_and_undated_posts_are_not_disclosed() {
		wp_set_current_user( $this->user );
		$id = Decker_Event_Store::put( 0, 'restricted.ics', $this->data() );
		self::factory()->post->create( array( 'post_type' => 'decker_event', 'post_status' => 'publish' ) );
		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $id ) {
			return 'read_post' === $cap && (int) $args[0] === $id ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$backend = new Decker_Dav_Backend();
			$this->assertSame( array(), $backend->getCalendarObjects( get_current_blog_id() ) );
			$this->expectException( Sabre\DAV\Exception\Forbidden::class );
			$backend->getCalendarObject( get_current_blog_id(), 'restricted.ics' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	public function test_failed_trash_reports_error_and_preserves_resource() {
		wp_set_current_user( $this->user );
		$id = Decker_Event_Store::put( 0, 'retained.ics', $this->data() );
		add_filter( 'pre_trash_post', '__return_false' );
		try {
			( new Decker_Dav_Backend() )->deleteCalendarObject( get_current_blog_id(), 'retained.ics' );
			$this->fail( 'A failed deletion must not be reported as successful.' );
		} catch ( Sabre\DAV\Exception\ServiceUnavailable $error ) {
			$this->assertSame( 'publish', get_post_status( $id ) );
		} finally {
			remove_filter( 'pre_trash_post', '__return_false' );
		}
	}

	public function test_uid_change_and_failed_insert_do_not_persist_client_data() {
		wp_set_current_user( $this->user );
		$id = Decker_Event_Store::put( 0, 'identity.ics', $this->data() );
		$original = Decker_Event_Store::resource( $id );
		try {
			Decker_Event_Store::put( $id, 'identity.ics', str_replace( 'client-uid@example.test', 'different-uid', $this->data() ) );
			$this->fail( 'UID changes must fail.' );
		} catch ( Sabre\DAV\Exception\Conflict $error ) {
			$this->assertSame( $original, Decker_Event_Store::resource( $id ) );
		}
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		try {
			$this->expectException( Sabre\DAV\Exception\ServiceUnavailable::class );
			Decker_Event_Store::put( 0, 'failed.ics', $this->data() );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}
	}

	public function test_principal_discovery_does_not_expose_other_users_or_groups() {
		wp_set_current_user( $this->user );
		$principals = new Decker_Dav_Principals();
		$path = 'principals/davtester';
		$this->assertSame( $path, $principals->getPrincipalsByPrefix( 'principals' )[0]['uri'] );
		$this->assertSame( array(), $principals->getPrincipalsByPrefix( 'other' ) );
		$this->assertNull( $principals->getPrincipalByPath( 'principals/another' ) );
		$this->assertSame( array(), $principals->searchPrincipals( 'principals', array( '{DAV:}displayname' => 'someone' ) ) );
		$this->assertSame( array(), $principals->getGroupMemberSet( $path ) );
		$this->assertSame( array(), $principals->getGroupMembership( $path ) );
		$patch = new Sabre\DAV\PropPatch( array( '{DAV:}displayname' => 'Forbidden rename' ) );
		$principals->updatePrincipal( $path, $patch );
		$this->assertNotSame( 'Forbidden rename', $principals->getPrincipalByPath( $path )['{DAV:}displayname'] );
		wp_set_current_user( 0 );
		$this->assertSame( array(), $principals->getPrincipalsByPrefix( 'principals' ) );
		$this->expectException( Sabre\DAV\Exception\Forbidden::class );
		$principals->setGroupMemberSet( $path, array( 'principals/another' ) );
	}

}
