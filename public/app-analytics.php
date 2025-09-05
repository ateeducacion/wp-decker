<?php
/**
 * File app-analytics
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';
?>

<head>
	<title><?php esc_html_e( 'Analytics Dashboard', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>
</head>
<body <?php body_class(); ?>>
	<!-- Begin page -->
	<div class="wrapper">

	<?php include 'layouts/menu.php'; ?>

		<!-- ============================================================== -->
		<!-- Start Page Content here -->
		<!-- ============================================================== -->

		<div class="content-page">
			<div class="content">

				<!-- Start Content-->
				<div class="container-fluid">



					<div class="row">
						<div class="col-12">
							<div class="page-title-box">
								<div class="page-title-right">
									<ol class="breadcrumb m-0">
										<li class="breadcrumb-item"><a href="javascript: void(0);">Decker</a></li>
										<li class="breadcrumb-item active"><?php esc_html_e( 'Analytics', 'decker' ); ?></li>
									</ol>
								</div>
								<h4 class="page-title"><?php esc_html_e( 'Analytics', 'decker' ); ?></h4>
							</div>
						</div>
					</div>     

<?php include 'layouts/top-alert.php'; ?>

					<!-- Dashboard Stats -->
					<div class="row">
						<div class="col-lg-3 col-md-6">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title"><?php esc_html_e( 'Active Tasks', 'decker' ); ?></h4>
									<h2 class="text-primary" id="active-tasks-count"><?php echo esc_html( wp_count_posts( 'decker_task' )->publish ); ?></h2>
								</div>
							</div>
						</div>
						<div class="col-lg-3 col-md-6">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title"><?php esc_html_e( 'Total Users', 'decker' ); ?></h4>
									<h2 class="text-primary" id="total-users-count"><?php echo esc_html( count_users()['total_users'] ); ?></h2>
								</div>
							</div>
						</div>
						<div class="col-lg-3 col-md-6">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title"><?php esc_html_e( 'Archived Tasks', 'decker' ); ?></h4>
									<h2 class="text-primary" id="archived-tasks-count"><?php echo esc_html( wp_count_posts( 'decker_task' )->archived ); ?></h2>
								</div>
							</div>
						</div>
						<div class="col-lg-3 col-md-6">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title"><?php esc_html_e( 'Total Boards', 'decker' ); ?></h4>
									<h2 class="text-primary" id="total-boards-count"><?php echo esc_html( wp_count_terms( 'decker_board' ) ); ?></h2>
								</div>
							</div>
						</div>
					</div>


<!-- Charts Section -->
<div id="chartsContainer" class="container">
	<!-- Row for charts -->
	<div class="row">
		<!-- Card for chartByBoard -->
		<div class="col-md-6">
			<div class="card">
				<div class="card-body">
					<h5 class="card-title"><?php esc_html_e( 'Tasks by Board', 'decker' ); ?></h5>
					<canvas id="chartByBoard" class="chartCanvas"></canvas>
				</div>
			</div>
		</div>
		<!-- Card for chartByUser -->
		<div class="col-md-6">
			<div class="card">
				<div class="card-body">
					<h5 class="card-title"><?php esc_html_e( 'Tasks by User', 'decker' ); ?></h5>
					<canvas id="chartByUser" class="chartCanvas"></canvas>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<!-- Card for chartByStack -->
		<div class="col-md-6 mx-auto">
			<div class="card">
				<div class="card-body">
					<h5 class="card-title"><?php esc_html_e( 'Tasks by Stack', 'decker' ); ?></h5>
					<canvas id="chartByStack" class="chartCanvas"></canvas>
				</div>
			</div>
		</div>
		<!-- Card for chartByStack -->
		<div class="col-md-6 mx-auto">
			<div class="card">
				<div class="card-body">
					<h5 class="card-title"><?php esc_html_e( 'Tasks by Stack from current User', 'decker' ); ?></h5>
					<canvas id="chartUser" class="chartCanvas"></canvas>
				</div>
			</div>
		</div>

	</div>
</div>




					<!-- end row -->

				</div>
				<!-- container -->

			</div>
			<!-- content -->

			<?php include 'layouts/footer.php'; ?>

		</div>

		<!-- ============================================================== -->
		<!-- End Page content -->
		<!-- ============================================================== -->

	</div>
	<!-- END wrapper -->

	<?php include 'layouts/right-sidebar.php'; ?>

	<?php include 'layouts/footer-scripts.php'; ?>

	<!-- Custom Script for Dashboard -->
	<script>
		<?php

			   // Get task data by board and colors.
		$boards           = BoardManager::get_all_boards();
		$tasks_by_board_and_stack = array();
		$board_labels             = array();
		$board_colors             = array();

			   // Get all posts of type decker_task at once.
		// TO-DO use the TaskManager.
		$all_tasks = get_posts(
			array(
				'post_type'   => 'decker_task',
				'numberposts' => -1,
				'post_status' => 'publish',
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'   => 'stack',
						'value' => 'to-do',
					),
					array(
						'key'   => 'stack',
						'value' => 'in-progress',
					),
					array(
						'key'   => 'stack',
						'value' => 'done',
					),
				),
			)
		);

			   // Process tasks by board and stack.
		foreach ( $boards as $board ) {
			$board_labels[]                           = $board->name;
			$board_colors[]                           = $board->color;
			$tasks_by_board_and_stack[ $board->name ] = array(
				'to-do'       => 0,
				'in-progress' => 0,
				'done'        => 0,
			);

			foreach ( $all_tasks as $task ) {
				$task_boards = wp_get_post_terms( $task->ID, 'decker_board', array( 'fields' => 'ids' ) );
				$task_stack  = get_post_meta( $task->ID, 'stack', true );

				if ( in_array( $board->id, $task_boards ) ) {
					$tasks_by_board_and_stack[ $board->name ][ $task_stack ]++;
				}
			}
		}

			   // Get task data by user.
		$users                   = get_users();
		$tasks_by_user_and_stack = array();
		$user_labels             = array();
		foreach ( $users as $user ) {
			$user_labels[]                                  = $user->display_name;
			$tasks_by_user_and_stack[ $user->display_name ] = array(
				'to-do'       => 0,
				'in-progress' => 0,
				'done'        => 0,
			);

			foreach ( $all_tasks as $task ) {
				$assigned_users = get_post_meta( $task->ID, 'assigned_users', true );
				$task_stack     = get_post_meta( $task->ID, 'stack', true );

				if ( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ) {
					$tasks_by_user_and_stack[ $user->display_name ][ $task_stack ]++;
				}
			}
		}

			   // Get task data by stack.
		$tasks_by_stack = array(
			'to-do'       => 0,
			'in-progress' => 0,
			'done'        => 0,
		);
		foreach ( $all_tasks as $task ) {
			$task_stack = get_post_meta( $task->ID, 'stack', true );
			$tasks_by_stack[ $task_stack ]++;
		}

		   // Convert the data to a numeric array for Chart.js.
		$tasks_by_stack_array = array(
			$tasks_by_stack['to-do'],
			$tasks_by_stack['in-progress'],
			$tasks_by_stack['done'],
		);


			   // Get task data by state for the current user.
		$current_user_id = get_current_user_id();
		$user_tasks_by_state = array(
			'to-do'       => 0,
			'in-progress' => 0,
			'done'        => 0,
		);

		foreach ( $all_tasks as $task ) {
				   // Switch to 'assigned_users' and check if the current user is assigned.
						$assigned_users = get_post_meta( $task->ID, 'assigned_users', true );
			if ( is_array( $assigned_users ) && in_array( $current_user_id, $assigned_users ) ) {
					$task_state = get_post_meta( $task->ID, 'stack', true ); // Reuse 'stack' for the states.
				if ( isset( $user_tasks_by_state[ $task_state ] ) ) {
					$user_tasks_by_state[ $task_state ]++;
				}
			}
		}

		   // Convert the data to a numeric array for Chart.js.
		$user_tasks_by_state_array = array(
			$user_tasks_by_state['to-do'],
			$user_tasks_by_state['in-progress'],
			$user_tasks_by_state['done'],
		);


		?>

		   // Data for the charts
		const tasksData = {
			labels: <?php echo wp_json_encode( $board_labels ); ?>,
			datasets: [
				{
					label: 'To-Do',
					data: <?php echo wp_json_encode( array_column( $tasks_by_board_and_stack, 'to-do' ) ); ?>,
					backgroundColor: '#ff6384'
				},
				{
					label: 'In Progress',
					data: <?php echo wp_json_encode( array_column( $tasks_by_board_and_stack, 'in-progress' ) ); ?>,
					backgroundColor: '#36a2eb'
				},
				{
					label: 'Done',
					data: <?php echo wp_json_encode( array_column( $tasks_by_board_and_stack, 'done' ) ); ?>,
					backgroundColor: '#cc65fe'
				}
			]
		};

		const usersData = {
			labels: <?php echo wp_json_encode( $user_labels ); ?>,
			datasets: [
				{
					label: 'To-Do',
					data: <?php echo wp_json_encode( array_column( $tasks_by_user_and_stack, 'to-do' ) ); ?>,
					backgroundColor: '#ff6384'
				},
				{
					label: 'In Progress',
					data: <?php echo wp_json_encode( array_column( $tasks_by_user_and_stack, 'in-progress' ) ); ?>,
					backgroundColor: '#36a2eb'
				},
				{
					label: 'Done',
					data: <?php echo wp_json_encode( array_column( $tasks_by_user_and_stack, 'done' ) ); ?>,
					backgroundColor: '#cc65fe'
				}
			]
		};

		const stackData = {
			labels: ['To Do', 'In Progress', 'Done'],
			datasets: [{
				label: 'Tasks by Stack',
				data: <?php echo wp_json_encode( $tasks_by_stack_array ); ?>,
				backgroundColor: ['#ff6384', '#36a2eb', '#cc65fe', '#ffce56']
			}]
		};

		const userStackData = {
			labels: ['To Do', 'In Progress', 'Done'],
			datasets: [{
				label: 'My Tasks by State',
				data: <?php echo wp_json_encode( $user_tasks_by_state_array ); ?>,
				backgroundColor: ['#ff9f40', '#4bc0c0', '#9966ff']
			}]
		};


		// Initialize charts.
		const ctx1 = document.getElementById('chartByBoard').getContext('2d');
		const chartByBoard = new Chart(ctx1, {
			type: 'bar',
			data: tasksData,
			options: {
				indexAxis: 'y',
				scales: {
					x: {
						stacked: true,
					},
					y: {
						stacked: true,
                                                   ticks: {
                                                        autoSkip: false, // Ensures labels are not skipped.
                                                },
					},
				},
			},
		});

		const ctx2 = document.getElementById('chartByUser').getContext('2d');
		const chartByUser = new Chart(ctx2, {
			type: 'bar',
			data: usersData,
			options: {
				indexAxis: 'y',
				scales: {
					x: {
						stacked: true,
					},
					y: {
						stacked: true,
                                                   ticks: {
                                                        autoSkip: false, // Ensures labels are not skipped.
                                                },
					},
				},
			},
		});

		const ctx3 = document.getElementById('chartByStack').getContext('2d');
		const chartByStack = new Chart(ctx3, {
			type: 'doughnut',
			data: stackData,
			options: {
				responsive: true,
				plugins: {
					legend: {
						display: true,
						position: 'top'
					}
				}
			}
		});


		const ctx4 = document.getElementById('chartUser').getContext('2d');
		const chartUser = new Chart(ctx4, {
			type: 'doughnut',
			data: userStackData,
			options: {
				responsive: true,
				plugins: {
					legend: {
						display: true,
						position: 'top'
					}
				}
			}
		});


	</script>
</body>
</html>
