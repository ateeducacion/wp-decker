<?php

namespace Decker\Tests\Integration;

use Decker_Tasks;
use Decker_Test_Base;

/**
 * Test class for verifying task board assignment behavior
 */
class TaskBoardAssignmentTest extends Decker_Test_Base {

	private $taskManager;
	private $testTaskId;
	private $originalBoardId;
	private $newBoardId;

	protected function setUp(): void {
		parent::setUp();

		// Create a user and set as current user.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// Create test boards using the factory
		$this->originalBoardId = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );
		$this->newBoardId = self::factory()->board->create( array( 'name' => 'Test Board 2' ) );

		// Create a test task using the factory
		$this->testTaskId = self::factory()->task->create(
			array(
				'post_title' => 'Original Title',
				'post_content' => 'Original Description',
				'stack' => 'to-do',
				'max_priority' => false,
				'board' => $this->originalBoardId,
			)
		);
	}

	protected function tearDown(): void {
		// Clean up test data
		wp_delete_post( $this->testTaskId, true );
		wp_delete_term( $this->originalBoardId, 'decker_board' );
		wp_delete_term( $this->newBoardId, 'decker_board' );

		parent::tearDown();
	}

	public function testExplicitlyChangingBoardShouldWork() {
		// Create three tasks in the original board's to-do stack
		for ( $i = 1; $i <= 3; $i++ ) {
			self::factory()->task->create(
				array(
					'post_title' => "Task $i",
						'stack' => 'to-do',
						'max_priority' => false,
						'board' => $this->originalBoardId,
				)
			);
		}

		// 2) Verificamos que la tarea principal sigue teniendo menu_order = 1
		//    (es la primera creada, antes de las 3 que acabamos de añadir)
		$initial_menu_order = get_post_field( 'menu_order', $this->testTaskId );
		$this->assertEquals(
		    1,
		    $initial_menu_order,
		    'Initial task should have menu_order = 1 before changing board.'
		);


		// Create two tasks in the new board's to-do stack
		for ( $i = 1; $i <= 2; $i++ ) {
			self::factory()->task->create(
				array(
					'post_title' => "New Board Task $i",
					'stack' => 'to-do',
					'max_priority' => false,
					'board' => $this->newBoardId,
				)
			);
		}

		// Update the board explicitly using the factory
		self::factory()->task->update_object(
			$this->testTaskId,
			array(
				'post_title' => 'Original Title',
				'post_content' => 'Original Description',
				'stack' => 'to-do',
				'max_priority' => false,
				'board' => $this->newBoardId,
			)
		);

		// Verify initial menu_order is 1 (first task in original board)
		$initial_menu_order = get_post_field( 'menu_order', $this->testTaskId );
		$this->assertEquals( 1, $initial_menu_order, 'Initial task should have menu_order 1' );

		// Get the current board ID and verify board changed
		$currentBoardId = wp_get_post_terms( $this->testTaskId, 'decker_board' )[0]->term_id;
		$this->assertEquals(
			$this->newBoardId,
			$currentBoardId,
			'Board should change when explicitly updated'
		);

		// Get all tasks in the new board's to-do stack
		$tasks_in_new_board = get_posts(
			array(
				'post_type' => 'decker_task',
				'orderby' => 'menu_order',
				'order' => 'ASC',
				'tax_query' => array(
					array(
						'taxonomy' => 'decker_board',
						'field' => 'term_id',
						'terms' => $this->newBoardId,
					),
				),
				'meta_query' => array(
					array(
						'key' => 'stack',
						'value' => 'to-do',
						'compare' => '=',
					),
				),
				'posts_per_page' => -1,
				'fields' => 'ids',
			)
		);

	// Confirmamos que la tarea aparece en la lista de tareas del nuevo board
	$this->assertContains(
	    $this->testTaskId,
	    $tasks_in_new_board,
	    'Task should now be in the new board\'s to-do stack'
	);

		// // Verify the moved task is the last one in the list
		// $this->assertEquals(
		// 	$this->testTaskId,
		// 	end( $tasks ),
		// 	'Task should be placed at the end of the stack in the new board'
		// );

		// // Verify final menu_order is 3 (after 2 existing tasks in new board)
		// $final_menu_order = get_post_field( 'menu_order', $this->testTaskId );
		// $this->assertEquals( 3, $final_menu_order, 'Task should have menu_order 3 after moving to new board' );
	}

/**
 * Tests that inserting a task at a specific menu_order increments subsequent tasks.
 */
// public function testInsertingTaskReordersOthers() {
//     // 1) Create one board for all tasks.
//     $board_id = self::factory()->board->create( array( 'name' => 'Reorder Board' ) );

//     // 2) Create three tasks in 'to-do' stack. They should get menu_order 1,2,3 automatically (based on your logic).
//     $taskA = self::factory()->task->create(
//         array(
//             'post_title'   => 'Task A',
//             'stack'        => 'to-do',
//             'board'        => $board_id,
//         )
//     );
//     $taskB = self::factory()->task->create(
//         array(
//             'post_title'   => 'Task B',
//             'stack'        => 'to-do',
//             'board'        => $board_id,
//         )
//     );
//     $taskC = self::factory()->task->create(
//         array(
//             'post_title'   => 'Task C',
//             'stack'        => 'to-do',
//             'board'        => $board_id,
//         )
//     );

//     // Fetch their initial menu_order for clarity.
//     // This test assumes each new task is appended at the end by default.
//     // (Task A = 1, B = 2, C = 3).
//     $orderA = get_post_field( 'menu_order', $taskA );
//     $orderB = get_post_field( 'menu_order', $taskB );
//     $orderC = get_post_field( 'menu_order', $taskC );

//     // Verify they are 1, 2, 3
//     $this->assertEquals( 1, (int) $orderA, 'Task A should have menu_order = 1' );
//     $this->assertEquals( 2, (int) $orderB, 'Task B should have menu_order = 2' );
//     $this->assertEquals( 3, (int) $orderC, 'Task C should have menu_order = 3' );

//     // 3) Create (or update) a new task D and force it into "position 2" within the stack.
//     // Depending on your plugin's actual reordering logic, you might do:
//     // - an update_object() with a custom 'menu_order' param
//     // - a custom function that sets its position
//     // The snippet below is hypothetical. Adjust to match your plugin.
//     $taskD = $this->testTaskId;

//     //  self::factory()->task->create(
//     //     array(
//     //         'post_title'   => 'Task D',
//     //         'stack'        => 'to-do',
//     //         'board'        => $board_id,
//     //         // Hypothetical approach: we rely on your plugin's reorder logic if it respects 'menu_order'
//     //         'menu_order'   => 2, // We want it inserted at position 2
//     //     )
//     // );

//     // 4) Now fetch the new menu_order for all tasks again.
//     $orderA = get_post_field( 'menu_order', $taskA );
//     $orderB = get_post_field( 'menu_order', $taskB );
//     $orderC = get_post_field( 'menu_order', $taskC );
//     $orderD = get_post_field( 'menu_order', $taskD );

//     // 5) We expect:
//     // - A remains 1
//     // - D is now 2
//     // - B has shifted to 3
//     // - C has shifted to 4
//     $this->assertEquals(
//         1,
//         (int) $orderA,
//         'Task A remains in position 1'
//     );
//     $this->assertEquals(
//         2,
//         (int) $orderD,
//         'Task D is inserted at position 2'
//     );
//     $this->assertEquals(
//         3,
//         (int) $orderB,
//         'Task B shifts from position 2 to 3'
//     );
//     $this->assertEquals(
//         4,
//         (int) $orderC,
//         'Task C shifts from position 3 to 4'
//     );
// }

public function testInsertingTaskReordersOthersWithMaxOrder() {
    // Crear el primer board y añadir tres tareas.
    $board1 = self::factory()->board->create( array( 'name' => 'Board 1' ) );
    $taskA = self::factory()->task->create(
        array(
            'post_title' => 'Task A',
            'stack'      => 'to-do',
            'board'      => $board1,
        )
    );
    $taskB = self::factory()->task->create(
        array(
            'post_title'   => 'Task B',
            'stack'        => 'to-do',
            'board'        => $board1,
            'max_priority' => true,
        )
    );
    $taskC = self::factory()->task->create(
        array(
            'post_title' => 'Task C',
            'stack'      => 'to-do',
            'board'      => $board1,
        )
    );

    // Crear el segundo board y añadir cuatro tareas.
    $board2 = self::factory()->board->create( array( 'name' => 'Board 2' ) );
    $taskD = self::factory()->task->create(
        array(
            'post_title' => 'Task D',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskE = self::factory()->task->create(
        array(
            'post_title' => 'Task E',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskF = self::factory()->task->create(
        array(
            'post_title' => 'Task F',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskG = self::factory()->task->create(
        array(
            'post_title' => 'Task G',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );

    $this->assertEquals( 1, get_post_field( 'menu_order', $taskB ), 'Task B should be first in Board 1 due to max_order' );

    // Mover la tarea B desde el primer board al segundo, manteniendo max_order = true.
    self::factory()->task->update_object(
        $taskB,
        array(
            'board' => $board2,
            'stack' => 'to-do',
        )
    );

    // Verificar el nuevo board de la tarea B.
    $currentBoardId = wp_get_post_terms( $taskB, 'decker_board' )[0]->term_id;
    $this->assertEquals(
        $board2,
        $currentBoardId,
        'Board should change when explicitly updated.'
    );

    // Verificar el nuevo orden en el Board 1 (Task A y Task C).
    $this->assertEquals( 1, get_post_field( 'menu_order', $taskA ), 'Task A should remain first in Board 1' );
    $this->assertEquals( 2, get_post_field( 'menu_order', $taskC ), 'Task C should shift to second in Board 1' );

    // Verificar el nuevo orden en el Board 2.
    $this->assertEquals( 1, get_post_field( 'menu_order', $taskB ), 'Task B should be first in Board 2 due to max_order' );
    $this->assertEquals( 2, get_post_field( 'menu_order', $taskD ), 'Task D should shift to second in Board 2' );
    $this->assertEquals( 3, get_post_field( 'menu_order', $taskE ), 'Task E should shift to third in Board 2' );
    $this->assertEquals( 4, get_post_field( 'menu_order', $taskF ), 'Task F should shift to fourth in Board 2' );
    $this->assertEquals( 5, get_post_field( 'menu_order', $taskG ), 'Task G should shift to fifth in Board 2' );
}


public function testInsertingTaskReordersOthers() {
    // Crear el primer board y añadir tres tareas.
    $board1 = self::factory()->board->create( array( 'name' => 'Board 1' ) );
    $taskA = self::factory()->task->create(
        array(
            'post_title' => 'Task A',
            'stack'      => 'to-do',
            'board'      => $board1,
        )
    );
    $taskB = self::factory()->task->create(
        array(
            'post_title' => 'Task B',
            'stack'      => 'to-do',
            'board'      => $board1,
        )
    );
    $taskC = self::factory()->task->create(
        array(
            'post_title' => 'Task C',
            'stack'      => 'to-do',
            'board'      => $board1,
        )
    );

    // Crear el segundo board y añadir cuatro tareas.
    $board2 = self::factory()->board->create( array( 'name' => 'Board 2' ) );
    $taskD = self::factory()->task->create(
        array(
            'post_title' => 'Task D',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskE = self::factory()->task->create(
        array(
            'post_title' => 'Task E',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskF = self::factory()->task->create(
        array(
            'post_title' => 'Task F',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );
    $taskG = self::factory()->task->create(
        array(
            'post_title' => 'Task G',
            'stack'      => 'to-do',
            'board'      => $board2,
        )
    );

    // Verificar el orden inicial en ambos boards.
    $this->assertEquals( 1, get_post_field( 'menu_order', $taskA ), 'Task A should be first in Board 1' );
    $this->assertEquals( 2, get_post_field( 'menu_order', $taskB ), 'Task B should be second in Board 1' );
    $this->assertEquals( 3, get_post_field( 'menu_order', $taskC ), 'Task C should be third in Board 1' );

    $this->assertEquals( 1, get_post_field( 'menu_order', $taskD ), 'Task D should be first in Board 2' );
    $this->assertEquals( 2, get_post_field( 'menu_order', $taskE ), 'Task E should be second in Board 2' );
    $this->assertEquals( 3, get_post_field( 'menu_order', $taskF ), 'Task F should be third in Board 2' );
    $this->assertEquals( 4, get_post_field( 'menu_order', $taskG ), 'Task G should be fourth in Board 2' );

    // Mover la tarea B desde el primer board al segundo.
	// Update the board explicitly using the factory
	self::factory()->task->update_object(
		$taskB,
		array(
			'board' => $board2,
			'stack' => 'to-do',
		)
	);


	// Get the current board ID and verify board changed
	$currentBoardId = wp_get_post_terms( $taskB, 'decker_board' )[0]->term_id;
	$this->assertEquals(
		$board2,
		$currentBoardId,
		'Board should change when explicitly updated'
	);


    // Verificar el nuevo orden en el Board 1 (Task A y Task C).
    $this->assertEquals( 1, get_post_field( 'menu_order', $taskA ), 'Task A should remain first in Board 1' );
    $this->assertEquals( 2, get_post_field( 'menu_order', $taskC ), 'Task C should shift to second in Board 1' );

    // Verificar el nuevo orden en el Board 2.
    $this->assertEquals( 1, get_post_field( 'menu_order', $taskD ), 'Task D should remain first in Board 2' );

    $this->assertEquals( 2, get_post_field( 'menu_order', $taskE ), 'Task E should remain second in Board 2' );
    $this->assertEquals( 3, get_post_field( 'menu_order', $taskF ), 'Task F should remain third in Board 2' );
    $this->assertEquals( 4, get_post_field( 'menu_order', $taskG ), 'Task G should remain fourth in Board 2' );
    $this->assertEquals( 5, get_post_field( 'menu_order', $taskB ), 'Task B should be added as fifth in Board 2' );
}

}
