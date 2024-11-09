<?php
include 'layouts/main.php';

// Obtener el slug del board desde la URL
$board_slug = isset( $_GET['slug'] ) ? sanitize_title( $_GET['slug'] ) : '';

// Retrieve the Board based on the slug
$main_board = BoardManager::getBoardBySlug($board_slug);
$taskManager = new TaskManager();
$tasks = $taskManager->getTasksByBoard($main_board);



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
	<title>Kanban Board | Decker</title>
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
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="Search..." aria-label="Search">

											<!-- Select de usuarios -->
											<select id="boardUserFilter" class="form-select ms-2">
												<option value="">All Users</option>
												<?php
												$users = get_users();
												foreach ( $users as $user ) {
													echo '<option value="' . esc_attr( $user->display_name ) . '">' . esc_html( $user->display_name ) . '</option>';
												}
												?>
											</select>
										</div>

									</div>

									<h4 class="page-title"><?php echo esc_html( $main_board->name ); ?>
										<a href="<?php echo add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3">Add New</a>

									</h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

						<div class="row">
							<div class="col-12">
								<div class="board">
									<div class="tasks" data-plugin="dragula" data-containers='["task-list-to-do", "task-list-in-progress", "task-list-done"]'>
										<h5 class="mt-0 task-header">TO-DO (<?php echo count( $columns['to-do'] ); ?>)</h5>
										
										<div id="task-list-to-do" class="task-list-items">

											<?php foreach ( $columns['to-do'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">In Progress (<?php echo count( $columns['in-progress'] ); ?>)</h5>
										
										<div id="task-list-in-progress" class="task-list-items">

											<?php foreach ( $columns['in-progress'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">Done (<?php echo count( $columns['done'] ); ?>)</h5>
										<div id="task-list-done" class="task-list-items">

											<?php foreach ( $columns['done'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
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
		<script type="text/javascript">

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

			drake.on('drop', function (el, target, source, sibling) {
				const taskId = el.getAttribute('data-task-id');
				if (!taskId) {
					console.error('Task ID is undefined');
					return;
				}
				const newStack = target.id.replace('task-list-', '');
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
						body: JSON.stringify({ order: newOrder })
					})
					.then(response => {
						if (!response.ok) {
							throw new Error('Network response was not ok');
						}
						return response.json();
					})
					.then(data => {
						if (data.message !== 'Task order updated successfully.') {
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
						body: JSON.stringify({ stack: newStack, order: newOrder })
					})
					.then(response => {
						if (!response.ok) {
							throw new Error('Network response was not ok');
						}
						return response.json();
					})
					.then(data => {
						if (data.status !== 'success') {
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


		</script>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const searchInput = document.getElementById('searchInput');
				const boardUserFilter = document.getElementById('boardUserFilter');

				function filterTasks() {
					const searchText = searchInput.value.toLowerCase();
					const selectedUser = boardUserFilter.value;

					document.querySelectorAll('.card').forEach((card) => {
						const titleElement = card.querySelector('.text-body');
						const title = titleElement ? titleElement.textContent.toLowerCase() : '';
						const assignedUsers = Array.from(card.querySelectorAll('.avatar-group-item')).map(item => item.getAttribute('data-bs-original-title').toLowerCase());
						const matchesSearch = title.includes(searchText) || assignedUsers.some(user => user.includes(searchText));
						const matchesUserFilter = !selectedUser || assignedUsers.includes(selectedUser.toLowerCase());

						if (matchesSearch && matchesUserFilter) {
							card.style.display = '';
						} else {
							card.style.display = 'none';
						}
					});
				}

				searchInput.addEventListener('input', filterTasks);
				boardUserFilter.addEventListener('change', filterTasks);
			});
		</script>

	</body>
</html>
