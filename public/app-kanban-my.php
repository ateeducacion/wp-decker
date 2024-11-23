<?php
include 'layouts/main.php';

$user_id = get_current_user_id();
$taskManager = new TaskManager();
$tasks = $taskManager->getTasksByUser($user_id);


// Dividir las tareas en columnas
$columns = array(
	'to-do' => array(),
	'in-progress' => array(),
	'done' => array(),
);

foreach ( $tasks as $task ) {
	$columns[ $task->stack ][] = $task;
}

?>
<head>
	<title><?php esc_html_e('My Board', 'decker'); ?> | Decker</title>
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

										<div class="input-group mb-3">
											<!-- Icono de búsqueda integrado en el campo -->
											<span class="input-group-text bg-white border-end-0">
												<i class="ri-search-line"></i>
											</span>
											
											<!-- Campo de búsqueda con botón de borrar (X) dentro -->
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attr_e('Search...', 'decker'); ?>" aria-label="<?php esc_attr_e('Search', 'decker'); ?>">

										</div>

									</div>

									<h4 class="page-title"><?php echo esc_html_e( "My Board", "decker" ); ?>
										<a href="<?php echo esc_url(add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) )); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3"><?php esc_html_e('Add New', 'decker'); ?></a>
									</h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

						<div class="row">
							<div class="col-12">
								<div class="board">
									<div class="tasks" data-plugin="dragula" data-containers='["task-list-to-do", "task-list-in-progress", "task-list-done"]'>
										<h5 class="mt-0 task-header"><?php esc_html_e('TO-DO', 'decker'); ?> (<?php echo esc_html(count( $columns['to-do'] )); ?>)</h5>
										
										<div id="task-list-to-do" class="task-list-items">

											<?php foreach ( $columns['to-do'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(true); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e('In Progress', 'decker'); ?> (<?php echo esc_html(count( $columns['in-progress'] )); ?>)</h5>
										
										<div id="task-list-in-progress" class="task-list-items">

											<?php foreach ( $columns['in-progress'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(true); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e('Done', 'decker'); ?> (<?php echo esc_html(count( $columns['done'] )); ?>)</h5>
										<div id="task-list-done" class="task-list-items">

											<?php foreach ( $columns['done'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(true); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-4-->
									</div>

								</div> <!-- end .board-->
							</div> <!-- end col -->
						</div>
						<!-- end row-->
						
					</div> <!-- container -->

				</div> <!-- content -->

				<?php include 'layouts/footer.php'; ?>

			</div>

			<!-- ============================================================== -->
			<!-- End Page content -->
			<!-- ============================================================== -->

		</div>
		<!-- END wrapper -->
		


		<?php include 'layouts/right-sidebar.php'; ?>

		<?php include 'layouts/task-modal.php'; ?>

		<?php include 'layouts/footer-scripts.php'; ?>

		<!-- dragula js-->
		<script src='https://cdnjs.cloudflare.com/ajax/libs/dragula/3.7.3/dragula.min.js'></script>


		<!-- Start dragula -->
<!-- 		<script type="text/javascript">

! function(r) {
	"use strict";

	function t() {
		this.$body = r("body")
	}
	t.prototype.init = function() {
		r('[data-plugin="dragula"]').each(function() {
			var t = r(this).data("containers"),
				a = [];
			if (t)
				for (var n = 0; n < t.length; n++) a.push(r("#" + t[n])[0]);
			else a = [r(this)[0]];
			var i = r(this).data("handleclass");
			const drake = i ? dragula(a, {
				moves: function(t, a, n) {
					return n.classList.contains(i)
				}
			}) : dragula(a);

			let oldOrder = null;

			drake.on('drag', function(el, source) {
			    // Capture the old order of the element being dragged
			    oldOrder = Array.from(source.children).indexOf(el) + 1;
			});

			drake.on('drop', function (el, target, source, sibling) {
				const taskId = el.getAttribute('data-task-id');
				if (!taskId) {
					console.error('Task ID is undefined');
					return;
				}
				const boardId = <?php echo $main_board->id; ?>;
				const newOrder = Array.from(target.children).indexOf(el) + 1;

				const sourceStack = source.id.replace('task-list-', '');
				const targetStack = target.id.replace('task-list-', '');

				if (sourceStack === targetStack) {
					fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/order', {
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
						},
						body: JSON.stringify({
							task_id: taskId,
							board_id: boardId,
							source_stack: sourceStack,
							target_stack: targetStack,
							source_order: oldOrder,
							target_order: newOrder
						})
					})
					.then(response => {
						if (!response.ok) {
							throw new Error('Network response was not ok');
						}
						return response.json();
					})
					.then(data => {
						if (!data.success) {
							alert('Failed to update task order.');
						}
					})
					.catch(error => console.error('Error:', error));
				} else {
					fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/stack', {
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
						},
						body: JSON.stringify({
							task_id: taskId,
							board_id: boardId,
							source_stack: sourceStack,
							target_stack: targetStack,
							source_order: oldOrder,
							target_order: newOrder
						})


					})
					.then(response => {
						if (!response.ok) {
							throw new Error('Network response was not ok');
						}
						return response.json();
					})
					.then(data => {
						if (!data.success) {
							alert('Failed to update task stack and order.');
						}
					})
					.catch(error => console.error('Error:', error));
				}
			});
		})
	}, r.Dragula = new t, r.Dragula.Constructor = t
}(window.jQuery),
function() {
	"use strict";
	window.jQuery.Dragula.init()
}();            


		</script> -->

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const searchInput = document.getElementById('searchInput');

				function filterTasks() {
					const searchText = searchInput.value.toLowerCase();
					document.querySelectorAll('.card').forEach((card) => {
						const titleElement = card.querySelector('.text-body');
						const title = titleElement ? titleElement.textContent.toLowerCase() : '';
						const matchesSearch = title.includes(searchText);

						if (matchesSearch) {
							card.style.display = '';
						} else {
							card.style.display = 'none';
						}
					});
				}

				searchInput.addEventListener('input', filterTasks);
			});
		</script>

	</body>
</html>