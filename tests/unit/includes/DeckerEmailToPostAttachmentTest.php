<?php
/**
 * Characterization tests for e-mail attachment uploads.
 *
 * Attachments arrive from an unauthenticated mail relay, so every field is
 * attacker-controlled. These tests pin each rejection path and the fact that
 * the stored file never keeps the name or the MIME type the sender supplied.
 *
 * @package Decker
 */

class DeckerEmailToPostAttachmentTest extends Decker_Test_Base {

	/**
	 * Instance under test.
	 *
	 * @var Decker_Email_To_Post
	 */
	private $email_to_post;

	/**
	 * Editor user allowed to upload files.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Task the attachments are linked to.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->email_to_post = new Decker_Email_To_Post();

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$this->task_id = self::factory()->task->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Invoke the private uploader.
	 *
	 * @param string   $filename Attachment file name.
	 * @param string   $content  Raw file content.
	 * @param int|null $post_id  Parent post, defaults to the fixture task.
	 * @return int|WP_Error Attachment ID or the rejection.
	 */
	private function upload( $filename, $content = 'hello world', $post_id = null ) {
		$method = new ReflectionMethod( $this->email_to_post, 'upload_attachment' );
		$method->setAccessible( true );

		return $method->invoke(
			$this->email_to_post,
			$filename,
			$content,
			null === $post_id ? $this->task_id : $post_id
		);
	}

	/**
	 * Assert an upload was refused with a given error code.
	 *
	 * @param string $expected_code Error code the uploader should return.
	 * @param mixed  $result        Value returned by the uploader.
	 */
	private function assertRejected( $expected_code, $result ) {
		$this->assertInstanceOf( WP_Error::class, $result, 'The upload should have been rejected.' );
		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	/**
	 * A user without the upload capability cannot store attachments.
	 */
	public function test_rejects_user_without_upload_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertRejected( 'permission_error', $this->upload( 'notes.txt' ) );
	}

	/**
	 * An attachment with no parent task is refused.
	 */
	public function test_rejects_missing_post_id() {
		$this->assertRejected( 'invalid_post', $this->upload( 'notes.txt', 'hello world', 0 ) );
	}

	/**
	 * A name that sanitizes away leaves nothing to store.
	 */
	public function test_rejects_empty_filename() {
		$this->assertRejected( 'invalid_filename', $this->upload( '' ) );
	}

	/**
	 * Executable and script extensions are refused outright.
	 *
	 * @dataProvider disallowed_filename_provider
	 *
	 * @param string $filename Attachment name that must be refused.
	 */
	public function test_rejects_disallowed_extensions( $filename ) {
		$this->assertRejected( 'disallowed_file_type', $this->upload( $filename ) );
	}

	/**
	 * Names covering the denylist and the extensions WordPress will not resolve.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function disallowed_filename_provider() {
		return array(
			'php script'            => array( 'shell.php' ),
			'php variant'           => array( 'shell.phtml' ),
			'php archive'           => array( 'payload.phar' ),
			'trailing php on image' => array( 'avatar.png.php' ),
			'shell script'          => array( 'run.sh' ),
			'windows executable'    => array( 'setup.exe' ),
			'html page'             => array( 'index.html' ),
			'htm page'              => array( 'index.htm' ),
			'svg image'             => array( 'logo.svg' ),
			'htaccess'              => array( 'rules.htaccess' ),
			'unknown extension'     => array( 'archive.xyz' ),
			'no extension'          => array( 'README' ),
		);
	}

	/**
	 * The denylist matches the end of the name, so a trailing safe extension passes.
	 *
	 * This is not a hole: the stored file is renamed to a UUID carrying the
	 * extension WordPress verified, so the ".php" part never reaches disk.
	 */
	public function test_accepts_double_extension_ending_in_a_safe_type() {
		$result = $this->upload( 'invoice.php.txt' );

		$this->assertIsInt( $result );
		$this->assertSame( 'text/plain', get_post_mime_type( $result ) );

		$stored = get_post_meta( $result, '_wp_attached_file', true );
		$this->assertStringEndsWith( '.txt', $stored );
		$this->assertStringNotContainsString( '.php', $stored );
	}

	/**
	 * A valid attachment is stored and linked to the task.
	 */
	public function test_accepts_valid_attachment() {
		$result = $this->upload( 'meeting notes.txt', 'agenda' );

		$this->assertIsInt( $result );

		$attachment = get_post( $result );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertSame( $this->task_id, (int) $attachment->post_parent );
		$this->assertSame( 'text/plain', $attachment->post_mime_type );
	}

	/**
	 * The original name is preserved as metadata but not used on disk.
	 */
	public function test_stores_original_filename_but_renames_on_disk() {
		$result = $this->upload( 'quarterly report.txt', 'figures' );

		$this->assertIsInt( $result );
		$this->assertSame(
			'quarterly-report.txt',
			get_post_meta( $result, '_original_filename', true )
		);

		$stored = basename( get_post_meta( $result, '_wp_attached_file', true ) );
		$this->assertNotSame( 'quarterly-report.txt', $stored );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}\.txt$/', $stored );
	}

	/**
	 * The stored MIME type is resolved from the WordPress allowlist.
	 *
	 * The uploader takes no MIME type from the caller on purpose: whatever the
	 * e-mail announced is attacker-controlled, so the type is derived from the
	 * verified extension instead.
	 */
	public function test_mime_type_is_resolved_from_the_allowlist() {
		$this->assertSame( 'text/plain', get_post_mime_type( $this->upload( 'notes.txt', 'plain content' ) ) );
		$this->assertSame( 'text/csv', get_post_mime_type( $this->upload( 'rows.csv', 'a,b' ) ) );
	}

	/**
	 * The attachment body is written to disk verbatim.
	 */
	public function test_writes_the_attachment_content() {
		$result = $this->upload( 'payload.txt', 'the actual bytes' );

		$this->assertIsInt( $result );

		$path = get_attached_file( $result );
		$this->assertFileExists( $path );
		$this->assertSame( 'the actual bytes', file_get_contents( $path ) );
	}
}
