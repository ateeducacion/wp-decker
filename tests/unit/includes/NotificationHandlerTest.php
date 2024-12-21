<?php
/**
 * Class DeckerNotificationHandlerTest
 *
 * @package Decker
 */

class DeckerNotificationHandlerTest extends Decker_Test_Base {
	/**
	 * Instance of Decker_Notification_Handler
	 *
	 * @var Decker_Notification_Handler
	 */
	private $notification_handler;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $test_user;

	/**
	 * Test task ID
	 *
	 * @var int
	 */
	private $test_task;

	/**
	 * Track if hooks were fired
	 *
	 * @var array
	 */
	private $fired_hooks = array();
	private $captured_mail = array();

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Reset hooks tracking
		$this->fired_hooks = array();

		// Set up email capturing
		add_filter( 'wp_mail', array( $this, 'capture_mail' ) );

		// Crear un usuario de prueba
		$this->test_user = $this->factory->user->create(
			array(
				'role' => 'editor',
				'user_email' => 'test@example.com',
			)
		);
		wp_set_current_user( $this->test_user );

		// Crear una tarea de prueba
		$this->test_task = $this->factory->task->create(
			array(
				'post_title' => 'Test Task',
			)
		);

		// Habilitar notificaciones por email en la configuración
		update_option(
			'decker_settings',
			array(
				'enable_email_notifications' => true,
			)
		);

		// Inicializar el manejador de notificaciones
		$this->notification_handler = new Decker_Notification_Handler();

		// Track hooks being fired
		add_action( 'decker_task_assigned', array( $this, 'track_hook' ), 10, 2 );
		add_action( 'decker_task_completed', array( $this, 'track_hook' ), 10, 2 );
		add_action( 'decker_task_comment_added', array( $this, 'track_hook' ), 10, 3 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		// Eliminar la tarea y el usuario de prueba
		wp_delete_post( $this->test_task, true );
		wp_delete_user( $this->test_user );
		delete_option( 'decker_settings' );

		// Reset hook tracking
		$this->fired_hooks = array();

		// Remove hook tracking
		remove_action( 'decker_task_assigned', array( $this, 'track_hook' ), 10 );
		remove_action( 'decker_task_completed', array( $this, 'track_hook' ), 10 );
		remove_action( 'decker_task_comment_added', array( $this, 'track_hook' ), 10 );

		// Reset captured mail
		$this->captured_mail = array();
		remove_filter( 'wp_mail', array( $this, 'capture_mail' ) );

		parent::tear_down();
	}

	/**
	 * Track which hooks were fired and with what parameters
	 */
	public function track_hook() {
		$this->fired_hooks[] = array(
			'hook' => current_filter(),
			'args' => func_get_args(),
		);
	}

	/**
	 * Helper method to trigger notification actions.
	 */
	private function trigger_notifications( $action, ...$params ) {
		do_action( $action, ...$params );
	}

	// /**
	// * Test that hooks are not processed when notifications are disabled
	// */
	// public function test_notifications_disabled() {
	// Verificar estado inicial
	// $this->assertEmpty( $this->fired_hooks, 'fired_hooks debería estar vacío al inicio' );

	// Deshabilitar notificaciones explícitamente
	// update_option(
	// 'decker_settings',
	// array(
	// 'enable_email_notifications' => false,
	// )
	// );

	// Verificar que las notificaciones están deshabilitadas
	// $settings = get_option( 'decker_settings' );
	// $this->assertFalse(
	// $settings['enable_email_notifications'],
	// 'Las notificaciones deberían estar deshabilitadas. Estado actual: ' .
	// var_export( $settings, true )
	// );

	// Verificar que no hay hooks registrados
	// global $wp_filter;
	// $registered_hooks = array(
	// 'decker_task_assigned' => isset( $wp_filter['decker_task_assigned'] ),
	// 'decker_task_completed' => isset( $wp_filter['decker_task_completed'] ),
	// 'decker_task_comment_added' => isset( $wp_filter['decker_task_comment_added'] ),
	// );

	// $this->assertEmpty(
	// array_filter( $registered_hooks ),
	// 'No deberían haber hooks registrados. Hooks actuales: ' .
	// var_export( $registered_hooks, true )
	// );

	// Trigger notifications
	// do_action( 'decker_task_assigned', $this->test_task, $this->test_user );
	// do_action( 'decker_task_completed', $this->test_task, $this->test_user );
	// do_action( 'decker_task_comment_added', $this->test_task, 1, $this->test_user );

	// Verificar que no se procesaron hooks
	// $this->assertEmpty(
	// $this->fired_hooks,
	// 'No deberían haberse procesado hooks. Hooks disparados: ' .
	// var_export( $this->fired_hooks, true )
	// );
	// }

	/**
	 * Test task assigned notification hook processing
	 */
	public function test_task_assigned_notification() {
		// Set user preferences
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_assigned' => true,
			)
		);

		// Enable notifications
		update_option( 'decker_settings', array( 'enable_email_notifications' => true ) );

		// Trigger notification
		do_action( 'decker_task_assigned', $this->test_task, $this->test_user );

		// Verify hook was processed
		$this->assertCount( 1, $this->fired_hooks, 'Hook should have been processed once.' );
		$this->assertEquals( 'decker_task_assigned', $this->fired_hooks[0]['hook'] );
		$this->assertEquals( array( $this->test_task, $this->test_user ), $this->fired_hooks[0]['args'] );
	}

	/**
	 * Test task completed notification.
	 */
	public function test_task_completed_notification() {
		// Establecer preferencias de notificación del usuario
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_completed' => true,
			)
		);

		// Asignar la tarea al usuario de prueba
		update_post_meta( $this->test_task, 'assigned_to', $this->test_user );

		// Trigger completion notification
		$this->trigger_notifications( 'decker_task_completed', $this->test_task, $this->test_user );

		// Verificar que se capturó un correo
		$this->assertNotEmpty( $this->captured_mail, 'No se capturó ningún email.' );

		// Verificar los detalles del correo
		$this->assertEquals( 'test@example.com', $this->captured_mail['to'], 'El destinatario no coincide.' );
		$this->assertStringContainsString( 'Task Completed', $this->captured_mail['subject'], 'El asunto del email no coincide.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail['message'], 'El contenido del email no contiene el título de la tarea.' );
	}

	/**
	 * Test task comment notification.
	 */
	public function test_task_comment_notification() {
		// Establecer preferencias de notificación del usuario
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_comments' => true,
			)
		);

		// Asignar la tarea al usuario de prueba
		update_post_meta( $this->test_task, 'assigned_to', $this->test_user );

		// Crear un comentario usando el factory de WordPress
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->test_task,
				'comment_content' => 'Test comment',
				'user_id'        => $this->test_user,
			)
		);

		$this->assertNotEquals( 0, $comment_id, 'Failed to create comment' );
		$this->assertNotFalse( $comment_id, 'Failed to create comment' );

		$comment = get_comment( $comment_id );
		$this->assertEquals( 'Test comment', $comment->comment_content, 'Incorrect comment content' );

		// Trigger comment notification
		$this->trigger_notifications( 'decker_task_comment_added', $this->test_task, $comment_id, $this->test_user );

		// Verificar que se capturó un correo
		$this->assertNotEmpty( $this->captured_mail, 'No se capturó ningún email.' );

		// Verificar los detalles del correo
		$this->assertEquals( 'test@example.com', $this->captured_mail['to'], 'El destinatario no coincide.' );
		$this->assertStringContainsString( 'New Comment', $this->captured_mail['subject'], 'El asunto del email no coincide.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail['message'], 'El contenido del email no contiene el título de la tarea.' );
	}

	/**
	 * Test that no notifications are sent when the action is realizado por el mismo usuario.
	 */
	public function test_no_self_notifications() {
		// Establecer el usuario actual como el usuario de prueba
		wp_set_current_user( $this->test_user );

		// Establecer preferencias de notificación del usuario
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_assigned' => true,
				'notify_completed' => true,
				'notify_comments' => true,
			)
		);

		// Trigger notifications que deberían ser ignoradas
		$this->trigger_notifications( 'decker_task_assigned', $this->test_task, $this->test_user );
		$this->trigger_notifications( 'decker_task_completed', $this->test_task, $this->test_user );
		$this->trigger_notifications( 'decker_task_comment_added', $this->test_task, 1, $this->test_user );

		// Verificar que no se envió ningún correo
		$this->assertEmpty( $this->captured_mail, 'No debería haberse enviado ningún email.' );
	}

	/**
	 * Capture emails sent via wp_mail
	 *
	 * @param array $args Email arguments
	 * @return array Modified email arguments
	 */
	public function capture_mail( $args ) {
		$this->captured_mail = $args;
		return $args;
	}
}
