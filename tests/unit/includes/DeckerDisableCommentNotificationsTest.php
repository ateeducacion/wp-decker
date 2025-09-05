<?php
/**
 * Test Class for Decker_Disable_Comment_Notifications
 */
class DeckerDisableCommentNotificationsTest extends WP_UnitTestCase {

	/**
	 * Instance of the class being tested
	 *
	 * @var Decker_Disable_Comment_Notifications
	 */
	private $notification_disabler;

	/**
	 * Set up test environment
	 */
	public function set_up(): void {
		parent::set_up();

		$this->notification_disabler = new Decker_Disable_Comment_Notifications();

               // Create test user
		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'admin@example.com',
			)
		);

		wp_set_current_user( $this->user_id );

               // Create test content
		$this->task_post_id = self::factory()->task->create(
			array(
				// 'post_type' => 'decker_task',
				'post_author' => $this->user_id,
			)
		);

		$this->regular_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_author' => $this->user_id,
			)
		);
	}

	/**
	 * Test that comment notifications are disabled for decker_task
	 */
	public function test_disable_notifications_for_decker_task() {
		// Crear comentario en tarea
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->task_post_id,
				'user_id'         => $this->user_id,
			)
		);

		// Obtener destinatarios
		$recipients = apply_filters(
			'comment_notification_recipients',
			array( 'admin@example.com' ),
			$comment_id
		);

		$this->assertEmpty(
			$recipients,
			'Should return empty array for decker_task comments'
		);
	}

	/**
	 * Test that notifications work normally for other post types
	 */
	public function test_normal_notifications_for_other_posts() {
		// Crear comentario en post normal
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->regular_post_id,
				'user_id'         => $this->user_id,
			)
		);

		// Obtener destinatarios
		$recipients = apply_filters(
			'comment_notification_recipients',
			array( 'admin@example.com' ),
			$comment_id
		);

		$this->assertNotEmpty(
			$recipients,
			'Should preserve notifications for non-decker_task posts'
		);
	}

	/**
	 * Test moderation emails are also disabled
	 */
	public function test_disable_moderation_emails() {
		// Crear comentario no aprobado en tarea
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_post_id,
				'comment_approved' => 0,
				'user_id'          => $this->user_id,
			)
		);

           // Verify moderation filter
		$recipients = apply_filters(
			'comment_moderation_recipients',
			array( 'admin@example.com' ),
			$comment_id
		);

		$this->assertEmpty(
			$recipients,
			'Should disable moderation emails for decker_task'
		);
	}

	/**
	 * Test that email sending is prevented at the source
	 */
	public function test_email_sending_prevention() {
           // 1. Mock wp_mail()
		$mailer = $this->getMockBuilder( Decker_Mailer::class )
			->onlyMethods( array( 'send_email' ) )
			->getMock();

		$mailer->expects( $this->never() )
			->method( 'send_email' );

		// 2. Crear comentario en decker_task
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $this->task_post_id,
				'comment_author_email' => 'commenter@example.com',
			)
		);

           // 3. Force notification
		do_action( 'comment_post', $comment_id, 1 );
	}
}
