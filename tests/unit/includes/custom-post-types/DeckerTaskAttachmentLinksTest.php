<?php
/**
 * Task attachment link validation, persistence and authorization.
 *
 * @package Decker
 */
class DeckerTaskAttachmentLinksTest extends Decker_Test_Base {

	/**
	 * Dispatch a link mutation through the registered REST route.
	 *
	 * @param int    $task_id    Task ID.
	 * @param string $url        Link URL.
	 * @param string $method     HTTP method.
	 * @param string $generation Editing session token.
	 * @return WP_REST_Response The REST response.
	 */
	private function mutate( $task_id, $url, $method = 'POST', $generation = '' ) {
		$request = new WP_REST_Request( $method, '/decker/v1/tasks/' . $task_id . '/attachment-links' );
		$request->set_param( 'url', $url );
		$request->set_param( 'lock_generation', $generation );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Links persist independently, reject unsafe URLs and enforce task editing sessions.
	 */
	public function test_links_are_validated_and_guarded() {
		do_action( 'init' );
		do_action( 'rest_api_init' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$task_id = self::factory()->post->create( array( 'post_type' => 'decker_task', 'post_status' => 'publish', 'post_author' => $editor ) );
		$key     = '_decker_attachment_link';
		$url     = 'https://example.org/document?a=1&b=2#section';
		$locks   = new Decker_Task_Locks();
		$lock    = $locks->acquire_lock( $task_id, $editor );
		$token   = $lock['generation'];

		foreach ( array( '', 'example.org', 'javascript:alert(1)', 'ftp://example.org/file', 'https://', 'https://user:secret@example.org/', "https://example.org/\nheader", 'https://example.org/<script>' ) as $invalid ) {
			$this->assertSame( 400, $this->mutate( $task_id, $invalid, 'POST', $token )->get_status() );
		}
		$this->assertSame( 200, $this->mutate( $task_id, $url, 'POST', $token )->get_status() );
		$this->assertSame( 409, $this->mutate( $task_id, $url, 'POST', $token )->get_status() );
		$this->assertSame( 200, $this->mutate( $task_id, 'http://intranet.local/resource', 'POST', $token )->get_status() );
		$this->assertSame( array( $url, 'http://intranet.local/resource' ), get_post_meta( $task_id, $key ) );

		// Taking over in another tab of the same user invalidates the old form.
		$new_lock = $locks->take_over_lock( $task_id, $editor );
		$this->assertSame( 409, $this->mutate( $task_id, $url, 'DELETE', $token )->get_status() );
		$this->assertSame( 409, $this->mutate( $task_id, 'https://example.org/new' )->get_status() );
		$this->assertSame( 200, $this->mutate( $task_id, $url, 'DELETE', $new_lock['generation'] )->get_status() );
		$this->assertSame( array( 'http://intranet.local/resource' ), get_post_meta( $task_id, $key ) );
		$missing = $this->mutate( $task_id, $url, 'DELETE', $new_lock['generation'] );
		$this->assertSame( 500, $missing->get_status() );
		$this->assertSame( 'decker_link_not_saved', $missing->get_data()['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->mutate( $task_id, $url )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( 403, $this->mutate( $task_id, $url )->get_status() );
		wp_set_current_user( $editor );
		$this->assertSame( 404, $this->mutate( 999999, $url )->get_status() );
		wp_update_post( array( 'ID' => $task_id, 'post_status' => 'archived' ) );
		$this->assertSame( 403, $this->mutate( $task_id, $url, 'POST', $new_lock['generation'] )->get_status() );
	}
}
