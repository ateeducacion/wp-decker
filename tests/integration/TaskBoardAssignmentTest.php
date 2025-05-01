<?php

namespace Decker\Tests\Integration;

use Decker_Tasks;
use Decker_Test_Base;
use WP_Post; // Make sure to import WP_Post

/**
 * Test class for verifying task board assignment behavior
 */
class TaskBoardAssignmentTest extends Decker_Test_Base {

    private $user_id;
    private $board1_id;
    private $board2_id;
    private $board3_id; // For tests with more boards/stacks

    protected function setUp(): void {
        parent::setUp();
        // Create a user and set as current user.
        $this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $this->user_id );
        // Create test boards using the factory
        $this->board1_id = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );
        $this->board2_id = self::factory()->board->create( array( 'name' => 'Test Board 2' ) );
        $this->board3_id = self::factory()->board->create( array( 'name' => 'Test Board 3' ) );
    }

    // --- Helper function to get current order ---
    private function get_current_order($task_id) {
        clean_post_cache($task_id);
        $post = get_post($task_id);
        return $post ? (int) $post->menu_order : -1; // Return -1 if the post doesn't exist
    }

    // --- Tests ---
    public function testExplicitlyChangingBoardShouldWork() {
        // Create initial task in board 1
        $taskA_to_move = self::factory()->task->create( array( 'post_title' => 'Task A To Move', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'Task B', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'Task C', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskD = self::factory()->task->create( array( 'post_title' => 'Task D B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );
        $taskE = self::factory()->task->create( array( 'post_title' => 'Task E B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );

        // Verify initial order
        $this->assertEquals(1, $this->get_current_order($taskA_to_move), 'Initial order TTM');
        $this->assertEquals(2, $this->get_current_order($taskB), 'Initial order B');
        $this->assertEquals(3, $this->get_current_order($taskC), 'Initial order C');
        $this->assertEquals(1, $this->get_current_order($taskD), 'Initial order D');
        $this->assertEquals(2, $this->get_current_order($taskE), 'Initial order E');

        // Move the task
        self::factory()->task->update_object( $taskA_to_move, array( 'board' => $this->board2_id ) );

        // Verify the term was changed
        $currentBoardId = wp_get_post_terms( $taskA_to_move, 'decker_board' )[0]->term_id;
        $this->assertEquals( $this->board2_id, $currentBoardId, 'Board should change' );

        // Verify order in Board 1 (B=1, C=2)
        $this->assertEquals(1, $this->get_current_order($taskB), 'Task B should be 1 in Board 1 after move');
        $this->assertEquals(2, $this->get_current_order($taskC), 'Task C should be 2 in Board 1 after move');

        // Verify order in Board 2 (D=1, E=2, TTM=3)
        $this->assertEquals(1, $this->get_current_order($taskD), 'Task D should be 1 in Board 2 after move');
        $this->assertEquals(2, $this->get_current_order($taskE), 'Task E should be 2 in Board 2 after move');
        $this->assertEquals(3, $this->get_current_order($taskA_to_move), 'Moved Task should be 3 in Board 2 after move');
    }

    public function testInsertingTaskReordersOthersWithMaxOrder() {
        $taskA = self::factory()->task->create( array( 'post_title' => 'A', 'board' => $this->board1_id, 'stack' => 'to-do', 'max_priority' => false ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B', 'board' => $this->board1_id, 'stack' => 'to-do', 'max_priority' => true ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C', 'board' => $this->board1_id, 'stack' => 'to-do', 'max_priority' => false ) );
        $taskD = self::factory()->task->create( array( 'post_title' => 'D', 'board' => $this->board2_id, 'stack' => 'to-do', 'max_priority' => false ) );
        $taskE = self::factory()->task->create( array( 'post_title' => 'E', 'board' => $this->board2_id, 'stack' => 'to-do', 'max_priority' => false ) );

        $this->assertEquals(2, $this->get_current_order($taskA), 'Initial A order after reorder');
        $this->assertEquals(1, $this->get_current_order($taskB), 'Task B should be first in Board 1 due to max_order');
        $this->assertEquals(3, $this->get_current_order($taskC), 'Initial C order after reorder');

        // Move B (max_priority) to Board 2
        self::factory()->task->update_object( $taskB, array( 'board' => $this->board2_id ) );

        // Verify Board 1: A(1), C(2)
        $this->assertEquals(1, $this->get_current_order($taskA), 'Task A should become 1 in Board 1');
        $this->assertEquals(2, $this->get_current_order($taskC), 'Task C should become 2 in Board 1');

        // Verify Board 2: B(1), D(2), E(3)
        $this->assertEquals(1, $this->get_current_order($taskB), 'Task B should be 1 in Board 2 due to max_order');
        $this->assertEquals(2, $this->get_current_order($taskD), 'Task D should become 2 in Board 2');
        $this->assertEquals(3, $this->get_current_order($taskE), 'Task E should become 3 in Board 2');
    }

    public function testInsertingTaskReordersOthers() {
        $taskA = self::factory()->task->create( array( 'post_title' => 'A', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskD = self::factory()->task->create( array( 'post_title' => 'D', 'board' => $this->board2_id, 'stack' => 'to-do' ) );
        $taskE = self::factory()->task->create( array( 'post_title' => 'E', 'board' => $this->board2_id, 'stack' => 'to-do' ) );

        // Move B to Board 2
        self::factory()->task->update_object( $taskB, array( 'board' => $this->board2_id ) );

        // Verify Board 1: A(1), C(2)
        $this->assertEquals( 1, $this->get_current_order($taskA), 'Task A should remain first in Board 1' );
        $this->assertEquals( 2, $this->get_current_order($taskC), 'Task C should shift to second in Board 1' );

        $this->assertEquals( 1, $this->get_current_order($taskD), 'Task D should remain first in Board 2' );
        $this->assertEquals( 2, $this->get_current_order($taskE), 'Task E should remain second in Board 2' );
        $this->assertEquals( 3, $this->get_current_order($taskB), 'Task B should be added as third in Board 2' );
    }

    public function testMoveFirstTask() {
        $taskA = self::factory()->task->create( array( 'post_title' => 'A First', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B Middle', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C Last', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskD = self::factory()->task->create( array( 'post_title' => 'D First B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );
        $taskE = self::factory()->task->create( array( 'post_title' => 'E Last B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );

        // Move A (the first) to Board 2
        self::factory()->task->update_object( $taskA, array( 'board' => $this->board2_id ) );

        // Verify Board 1: B(1), C(2)
        $this->assertEquals(1, $this->get_current_order($taskB), 'Task B should become 1 in Board 1');
        $this->assertEquals(2, $this->get_current_order($taskC), 'Task C should become 2 in Board 1');

        // Verify Board 2: D(1), E(2), A(3)
        $this->assertEquals(1, $this->get_current_order($taskD), 'Task D should remain 1 in Board 2');
        $this->assertEquals(2, $this->get_current_order($taskE), 'Task E should remain 2 in Board 2');
        $this->assertEquals(3, $this->get_current_order($taskA), 'Moved Task A should become 3 in Board 2');
    }

    public function testMoveLastTask() {
        $taskA = self::factory()->task->create( array( 'post_title' => 'A First', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B Middle', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C Last', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskD = self::factory()->task->create( array( 'post_title' => 'D First B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );
        $taskE = self::factory()->task->create( array( 'post_title' => 'E Last B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );

        // Move C (the last) to Board 2
        self::factory()->task->update_object( $taskC, array( 'board' => $this->board2_id ) );

        // Verify Board 1: A(1), B(2)
        $this->assertEquals(1, $this->get_current_order($taskA), 'Task A should remain 1 in Board 1');
        $this->assertEquals(2, $this->get_current_order($taskB), 'Task B should remain 2 in Board 1');

        // Verify Board 2: D(1), E(2), C(3)
        $this->assertEquals(1, $this->get_current_order($taskD), 'Task D should remain 1 in Board 2');
        $this->assertEquals(2, $this->get_current_order($taskE), 'Task E should remain 2 in Board 2');
        $this->assertEquals(3, $this->get_current_order($taskC), 'Moved Task C should become 3 in Board 2');
    }

    public function testChangeStackSameBoard() {
        // This test may fail if changing only the stack does not trigger reordering.
        // If it fails, and you want it to reorder, you will need to add a hook to `update_post_meta` for 'stack'.
        $taskA = self::factory()->task->create( array( 'post_title' => 'A todo', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B todo', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C progress', 'board' => $this->board1_id, 'stack' => 'in-progress' ) );

        // Move B from 'to-do' to 'in-progress'
        self::factory()->task->update_object( $taskB, array( 'stack' => 'in-progress' ) );

        // Verify Board 1, stack 'to-do': A(1)
        $this->assertEquals(1, $this->get_current_order($taskA), 'Task A should remain 1 in todo stack');

        // Verify Board 1, stack 'in-progress': C(1), B(2) (assuming B was created after C)
        $this->assertEquals(1, $this->get_current_order($taskC), 'Task C should be 1 in progress stack');
        $this->assertEquals(2, $this->get_current_order($taskB), 'Task B should be 2 in progress stack');
    }

    public function testChangeStackAndBoard() {
        $taskA = self::factory()->task->create( array( 'post_title' => 'A todo B1', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskB = self::factory()->task->create( array( 'post_title' => 'B todo B1', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskC = self::factory()->task->create( array( 'post_title' => 'C progress B2', 'board' => $this->board2_id, 'stack' => 'in-progress' ) );

        // Move B to Board 2 and to stack 'in-progress'
        self::factory()->task->update_object( $taskB, array( 'board' => $this->board2_id, 'stack' => 'in-progress' ) );

        // Verify Board 1, stack 'to-do': A(1)
        $this->assertEquals(1, $this->get_current_order($taskA), 'Task A should remain 1 in Board 1 todo');

        // Verify Board 2, stack 'in-progress': C(1), B(2)
        $this->assertEquals(1, $this->get_current_order($taskC), 'Task C should be 1 in Board 2 progress');
        $this->assertEquals(2, $this->get_current_order($taskB), 'Task B should be 2 in Board 2 progress');
    }

    public function testMovingTasksAcrossThreeBoardsMaintainsOrder() {
        // Create tasks in each board
        $taskA1 = self::factory()->task->create( array( 'post_title' => 'A1', 'board' => $this->board1_id, 'stack' => 'to-do' ) );
        $taskA2 = self::factory()->task->create( array( 'post_title' => 'A2', 'board' => $this->board1_id, 'stack' => 'to-do' ) );

        $taskB1 = self::factory()->task->create( array( 'post_title' => 'B1', 'board' => $this->board2_id, 'stack' => 'to-do' ) );
        $taskB2 = self::factory()->task->create( array( 'post_title' => 'B2', 'board' => $this->board2_id, 'stack' => 'to-do' ) );

        $taskC1 = self::factory()->task->create( array( 'post_title' => 'C1', 'board' => $this->board3_id, 'stack' => 'to-do' ) );
        $taskC2 = self::factory()->task->create( array( 'post_title' => 'C2', 'board' => $this->board3_id, 'stack' => 'to-do' ) );

        // Move one task from each board to a different board
        self::factory()->task->update_object( $taskA1, array( 'board' => $this->board2_id ) ); // board1 → board2
        self::factory()->task->update_object( $taskB2, array( 'board' => $this->board3_id ) ); // board2 → board3
        self::factory()->task->update_object( $taskC1, array( 'board' => $this->board1_id ) ); // board3 → board1

        // Assert order in board1 (originally A1, A2) → now A2, C1
        $this->assertEquals(1, $this->get_current_order($taskA2), 'A2 should be first in Board 1');
        $this->assertEquals(2, $this->get_current_order($taskC1), 'C1 should be second in Board 1');

        // Assert order in board2 (originally B1, B2) → now B1, A1
        $this->assertEquals(1, $this->get_current_order($taskB1), 'B1 should be first in Board 2');
        $this->assertEquals(2, $this->get_current_order($taskA1), 'A1 should be second in Board 2');

        // Assert order in board3 (originally C1, C2) → now C2, B2
        $this->assertEquals(1, $this->get_current_order($taskC2), 'C2 should be first in Board 3');
        $this->assertEquals(2, $this->get_current_order($taskB2), 'B2 should be second in Board 3');
    }

}
