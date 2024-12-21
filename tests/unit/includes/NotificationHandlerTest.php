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
	 * Captured email content
	 *
	 * @var array
	 */
	private $captured_mail = array();

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Crear un usuario de prueba
		$this->test_user = $this->factory->user->create(
			array(
				'role' => 'author',
				'user_email' => 'test@example.com',
			)
		);

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

		// Interceptar las llamadas a wp_mail
		add_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 10, 2 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		// Eliminar la tarea y el usuario de prueba
		wp_delete_post( $this->test_task, true );
		wp_delete_user( $this->test_user );
		delete_option( 'decker_settings' );

		// Limpiar correos capturados
		$this->captured_mail = array();

		// Remover el interceptor de wp_mail
		remove_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 10 );

		parent::tear_down();
	}

	/**
	 * Intercept wp_mail calls and capture the mail parameters.
	 *
	 * @param null|bool $pre Whether to short-circuit wp_mail()
	 * @param array     $args The wp_mail arguments
	 * @return bool Always returns false to prevent actual email sending
	 */
	public function intercept_mail( $pre, $args ) {
		$this->captured_mail = $args;
		return false; // Previene que se envíe el email real
	}

	/**
	 * Helper method to trigger notification actions.
	 */
	private function trigger_notifications( $action, ...$params ) {
		do_action( $action, ...$params );
	}

	/**
	 * Test notification handling when notifications están deshabilitadas.
	 */
	public function test_notifications_disabled() {
		update_option(
			'decker_settings',
			array(
				'enable_email_notifications' => false,
			)
		);

		// Trigger varias notificaciones
		$this->trigger_notifications( 'decker_task_assigned', $this->test_task, $this->test_user );
		$this->trigger_notifications( 'decker_task_completed', $this->test_task, $this->test_user );
		$this->trigger_notifications( 'decker_task_comment_added', $this->test_task, 1, $this->test_user );

		// Verificar que no se envió ningún correo
		$this->assertEmpty( $this->captured_mail, 'No debería haberse enviado ningún email.' );
	}

	/**
	 * Test task assigned notification.
	 */
	public function test_task_assigned_notification() {
		// Establecer preferencias de notificación del usuario
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_assigned' => true,
			)
		);

		// Trigger assignment notification
		$this->trigger_notifications( 'decker_task_assigned', $this->test_task, $this->test_user );

		// Verificar que se capturó un correo
		$this->assertNotEmpty( $this->captured_mail, 'No se capturó ningún email.' );

		// Verificar los detalles del correo
		$this->assertEquals( 'test@example.com', $this->captured_mail['to'], 'El destinatario no coincide.' );
		$this->assertStringContainsString( 'New Task Assigned', $this->captured_mail['subject'], 'El asunto del email no coincide.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail['message'], 'El contenido del email no contiene el título de la tarea.' );
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

		// Crear un comentario de prueba
		$comment_id = $this->factory->comment->create(
			array(
				'comment_post_ID' => $this->test_task,
				'comment_content' => 'Test comment',
				'user_id' => $this->test_user,
			)
		);

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
}
