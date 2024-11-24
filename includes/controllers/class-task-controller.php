<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class TaskController
 *
 * Handles operations related to tasks.
 */
class TaskController {

	private TaskManager $task_manager;

	/**
	 * TaskController constructor.
	 */
	public function __construct() {
		$this->taskManager = new TaskManager();
	}

	/**
	 * Displays all tasks.
	 */
	public function displayAllTasks(): void {
		$tasks = $this->taskManager->get_tasks();
		if ( empty( $tasks ) ) {
			echo '<p>No tasks found.</p>';
			return;
		}

		foreach ( $tasks as $task ) {
			echo '<div>';
			echo '<h3>' . esc_html( $task->title ) . '</h3>';
			echo '<p>' . esc_html( $task->content ) . '</p>';
			echo '<p>Status: ' . esc_html( $task->status ) . '</p>';
			echo '<p>Author: ' . esc_html( $task->author ) . '</p>';
			echo '<p>Order: ' . esc_html( $task->order ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Displays a single task by ID.
	 *
	 * @param int $id The ID of the task to display.
	 */
	public function displayTask( int $id ): void {
		$task = $this->taskManager->get_task( $id );
		if ( ! $task ) {
			echo '<p>Task not found.</p>';
			return;
		}

		echo '<div>';
		echo '<h3>' . esc_html( $task->title ) . '</h3>';
		echo '<p>' . esc_html( $task->content ) . '</p>';
		echo '<p>Status: ' . esc_html( $task->status ) . '</p>';
		echo '<p>Author: ' . esc_html( $task->author ) . '</p>';
		echo '<p>Order: ' . esc_html( $task->order ) . '</p>';
		echo '</div>';
	}

	/**
	 * Creates or updates a task based on form data.
	 *
	 * @param array $data The form data.
	 */
	public function handleTaskFormSubmission( array $data ): void {
		$title   = sanitize_text_field( $data['title'] );
		$content = sanitize_textarea_field( $data['content'] );
		$status  = sanitize_text_field( $data['status'] ?? 'publish' );
		$task_id = intval( $data['task_id'] ?? 0 );

		if ( $task_id > 0 ) {
			// Update existing task
			$task = $this->taskManager->get_task( $task_id );
			if ( $task ) {
				$task->title   = $title;
				$task->content = $content;
				$task->status  = $status;
				$task->save();
				echo '<p>Task updated successfully.</p>';
			} else {
				echo '<p>Task not found.</p>';
			}
		} else {
			// Create new task
			$newTask = $this->taskManager->createTask( $title, $content, $status );
			if ( $newTask ) {
				echo '<p>Task created successfully with ID: ' . esc_html( $newTask->id ) . '</p>';
			} else {
				echo '<p>Failed to create task.</p>';
			}
		}
	}

	/**
	 * Deletes a task by its ID.
	 *
	 * @param int $id The ID of the task to delete.
	 */
	public function deleteTask( int $id ): void {
		$success = $this->taskManager->deleteTask( $id );
		if ( $success ) {
			echo '<p>Task deleted successfully.</p>';
		} else {
			echo '<p>Failed to delete task.</p>';
		}
	}
}
