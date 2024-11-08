<?php
include 'layouts/main.php';

// Obtener el slug del board desde la URL
$board_slug = isset( $_GET['slug'] ) ? sanitize_title( $_GET['slug'] ) : '';

// Retrieve the term based on the slug
$board_term = get_term_by( 'slug', $board_slug, 'decker_board' );

// Consultar las tareas asociadas al board
$tasks = get_posts(
	array(
		'post_type'   => 'decker_task',
        'post_status'    => 'publish',		
		'tax_query'   => array(
			array(
				'taxonomy' => 'decker_board',
				'field'    => 'slug',
				'terms'    => $board_slug,
			),
		),		
		'meta_key'    => 'max_priority', // Definir el campo meta para ordenar
		'meta_type' => 'BOOL',
		'orderby'     => array(
			'max_priority' => 'DESC',
			'menu_order'   => 'ASC',
		),
		'numberposts' => -1,
	)
);

// Dividir las tareas en columnas
$columns = array(
	'to-do' => array(),
	'in-progress' => array(),
	'done' => array(),
);

foreach ( $tasks as $task ) {
	$stack = get_post_meta( $task->ID, 'stack', true );
	if ( isset( $columns[ $stack ] ) ) {
		$columns[ $stack ][] = $task;
	}
}
function render_task_card( $task ) {
	$user_date_relations = get_post_meta( $task->ID, '_user_date_relations', true );
	$is_today = false;
	if ( $user_date_relations ) {
		foreach ( $user_date_relations as $relation ) {
			if ( $relation['user_id'] == get_current_user_id() && $relation['date'] == date( 'Y-m-d' ) ) {
				$is_today = true;
				break;
			}
		}
	}
	?>
	<div class="card mb-0" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
		<div class="card-body p-3">

			<?php $max_priority = get_post_meta( $task->ID, 'max_priority', true ); ?>
			<span class="float-end badge <?php echo $max_priority ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary'; ?>">
				<span class="label-to-hide"><?php echo $max_priority ? '🔥' : 'Normal'; ?></span>
				<span class="menu-order label-to-show" style="display: none;">Order: <?php echo esc_html(get_post_field('menu_order', $task->ID)); ?></span> 

			</span>
			<?php
				$due_date = get_post_meta( $task->ID, 'duedate', true );

				$relative_time = '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> Undefined date</span>';
				$formatted_due_date = '';

			if ( ! empty( $due_date ) ) {
				$relative_time = esc_html( Decker_Utility_Functions::getRelativeTime( $due_date ) );
				$formatted_due_date = date( 'd M Y', strtotime( $due_date ) );
			}
			?>
	        <small class="text-muted relative-time-badge">
	            <span class="task-id label-to-hide"><?php echo $relative_time; ?></span>
	            <span class="task-id label-to-show" style="display: none;">#<?php echo esc_html($task->ID); ?></span>
	        </small>

			<h5 class="my-2 fs-16" id="task-<?php echo esc_attr( $task->ID ); ?>">
				<a href="
				<?php
				echo add_query_arg(
					array(
						'decker_page' => 'task',
						'id' => esc_attr( $task->ID ),
					),
					home_url( '/' )
				);
				?>
							" data-bs-toggle="modal" data-bs-target="#task-modal" class="text-body" data-task-id="<?php echo esc_attr( $task->ID ); ?>"><?php echo esc_html( $task->post_title ); ?></a>
			</h5>

			<p class="mb-0">
				<span class="pe-2 text-nowrap mb-2 d-inline-block">
					<i class="ri-briefcase-2-line text-muted"></i>
					<?php echo esc_html( get_post_meta( $task->ID, 'project', true ) ); ?>
				</span>
				<span class="text-nowrap mb-2 d-inline-block">
					<i class="ri-discuss-line text-muted"></i>
					<b><?php echo esc_html( get_comments_number( $task->ID ) ); ?></b> Comments
				</span>
			</p>

			<?php echo render_task_menu( $task->ID ); ?>

			<div class="avatar-group mt-2">
				<?php
				$assigned_users = get_post_meta( $task->ID, 'assigned_users', true );
				if ( $assigned_users ) {
					foreach ( $assigned_users as $user_id ) {
						$user_info = get_userdata( $user_id );
						$is_today = false;
						if ( $user_date_relations ) {
							foreach ( $user_date_relations as $relation ) {
								if ( $relation['user_id'] == $user_id && $relation['date'] == date( 'Y-m-d' ) ) {
									$is_today = true;
									break;
								}
							}
						}
						$avatar_class = $is_today ? 'avatar-group-item today' : 'avatar-group-item';
						?>
						<a href="javascript: void(0);" class="<?php echo $avatar_class; ?>"
							data-bs-toggle="tooltip" data-bs-placement="top"
							title="<?php echo esc_attr( $user_info->display_name ); ?>">
							<img src="<?php echo esc_url( get_avatar_url( $user_id ) ); ?>" alt=""
								class="rounded-circle avatar-xs">
						</a>
						<?php
					}
				}
				?>
			</div>
		</div> <!-- end card-body -->
	</div>
	<?php
}

function render_task_menu( $task_id ) {
	return '
    <div class="dropdown float-end mt-2">
        <a href="#" class="dropdown-toggle text-muted arrow-none" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ri-more-2-fill fs-18"></i>
        </a>
        <div class="dropdown-menu dropdown-menu-end">
            <!-- item-->
			<a href="' . add_query_arg(
		array(
			'decker_page' => 'task',
			'id' => esc_attr( $task_id ),
		),
		home_url( '/' )
	) . '" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="' . esc_attr( $task_id ) . '" class="dropdown-item"><i class="ri-edit-box-line me-1"></i>Edit</a>
            <!-- item-->
            <a href="' . get_edit_post_link( $task_id ) . '" class="dropdown-item" target="_blank"><i class="ri-wordpress-line me-1"></i>Edit in WordPress</a>
            <!-- item-->
            <a href="javascript:void(0);" class="dropdown-item archive-task" data-task-id="' . esc_attr( $task_id ) . '"><i class="ri-archive-line me-1"></i>Archive</a>
            <a href="javascript:void(0);" class="dropdown-item assign-to-me" data-task-id="' . esc_attr( $task_id ) . '" style="' . ( in_array( get_current_user_id(), get_post_meta( $task_id, 'assigned_users', true ) ?: array() ) ? 'display: none;' : '' ) . '"><i class="ri-user-add-line me-1"></i>Assign to me</a>
            <a href="javascript:void(0);" class="dropdown-item leave-task" data-task-id="' . esc_attr( $task_id ) . '" style="' . ( ! in_array( get_current_user_id(), get_post_meta( $task_id, 'assigned_users', true ) ?: array() ) ? 'display: none;' : '' ) . '"><i class="ri-logout-circle-line me-1"></i>Leave</a>
            <!-- item-->
            ' . ( in_array( get_current_user_id(), get_post_meta( $task_id, 'assigned_users', true ) ?: array() ) ? '
            <a href="javascript:void(0);" class="dropdown-item mark-for-today" data-task-id="' . esc_attr( $task_id ) . '" style="' . ( in_array(
				array(
					'user_id' => get_current_user_id(),
					'date' => date( 'Y-m-d' ),
				),
				get_post_meta( $task_id, '_user_date_relations', true ) ?: array()
			) ? 'display: none;' : '' ) . '">
                <i class="ri-calendar-check-line me-1"></i>Mark for today
            </a>
            <a href="javascript:void(0);" class="dropdown-item unmark-for-today" data-task-id="' . esc_attr( $task_id ) . '" style="' . ( ! in_array(
				array(
					'user_id' => get_current_user_id(),
					'date' => date( 'Y-m-d' ),
				),
				get_post_meta( $task_id, '_user_date_relations', true ) ?: array()
			) ? 'display: none;' : '' ) . '">
                <i class="ri-calendar-close-line me-1"></i>Unmark for today
            </a>
            ' : '' ) . '
        </div>
    </div>';
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

									<h4 class="page-title"><?php echo esc_html( $board_term->name ); ?>
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
										<h5 class="mt-0 task-header">TODO (<?php echo count( $columns['to-do'] ); ?>)</h5>
										
										<div id="task-list-to-do" class="task-list-items">

											<?php foreach ( $columns['to-do'] as $task ) : ?>
											<!-- Task Item -->
												<?php render_task_card( $task ); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">In Progress (<?php echo count( $columns['in-progress'] ); ?>)</h5>
										
										<div id="task-list-in-progress" class="task-list-items">

											<?php foreach ( $columns['in-progress'] as $task ) : ?>
											<!-- Task Item -->
												<?php render_task_card( $task ); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">Done (<?php echo count( $columns['done'] ); ?>)</h5>
										<div id="task-list-done" class="task-list-items">

											<?php foreach ( $columns['done'] as $task ) : ?>
											<!-- Task Item -->
												<?php render_task_card( $task ); ?>
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

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const userId = <?php echo get_current_user_id(); ?>;
				
				document.querySelectorAll('.assign-to-me').forEach((element) => {
					element.addEventListener('click', function () {
						var taskId = element.getAttribute('data-task-id');
						fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/assign', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
							},
							body: JSON.stringify({ user_id: userId })
						})
						.then(response => {
							if (!response.ok) {
								throw new Error('Network response was not ok');
							}
							return response.json();
						})
						.then(data => {
							if (data.message === 'User assigned successfully.') {
								const taskCard = element.closest('.card');
								const avatarGroup = taskCard.querySelector('.avatar-group');
								const newAvatar = document.createElement('a');
								newAvatar.href = 'javascript: void(0);';
								newAvatar.className = 'avatar-group-item';
								newAvatar.setAttribute('data-bs-toggle', 'tooltip');
								newAvatar.setAttribute('data-bs-placement', 'top');
								newAvatar.setAttribute('data-bs-original-title', '<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>');
								newAvatar.innerHTML = `<img src="<?php echo esc_url( get_avatar_url( get_current_user_id() ) ); ?>" alt="" class="rounded-circle avatar-xs">`;
								avatarGroup.appendChild(newAvatar);

								// Toggle menu options
								element.style.display = 'none';
								taskCard.querySelector('.leave-task').style.display = 'block';
							} else {
								alert('Failed to assign user to task.');
							}
						})
						.catch(error => console.error('Error:', error));
					});
				});

				document.querySelectorAll('.leave-task').forEach((element) => {
					element.addEventListener('click', function () {
						var taskId = element.getAttribute('data-task-id');
						fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/leave', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
							},
							body: JSON.stringify({ user_id: userId })
						})
						.then(response => {
							if (!response.ok) {
								throw new Error('Network response was not ok');
							}
							return response.json();
						})
						.then(data => {
							if (data.message === 'User removed successfully.') {
								const taskCard = element.closest('.card');
								const avatarGroup = taskCard.querySelector('.avatar-group');
								const userAvatar = avatarGroup.querySelector(`a[data-bs-original-title="<?php echo esc_attr( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
								if (userAvatar) {
									userAvatar.remove();
								}

								// Toggle menu options
								element.style.display = 'none';
								taskCard.querySelector('.assign-to-me').style.display = 'block';
								taskCard.querySelector('.mark-for-today').style.display = 'none';
								taskCard.querySelector('.unmark-for-today').style.display = 'none';
							} else {
								alert('Failed to leave the task.');
							}
						})
						.catch(error => console.error('Error:', error));
					});
				});

				document.querySelectorAll('.mark-for-today').forEach((element) => {
					element.addEventListener('click', function () {
						var taskId = element.getAttribute('data-task-id');
						fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/mark_relation', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
							},
							body: JSON.stringify({ user_id: userId, date: '<?php echo date( 'Y-m-d' ); ?>' })
						})
						.then(response => {
							if (!response.ok) {
								throw new Error('Network response was not ok');
							}
							return response.json();
						})
						.then(data => {
							if (data.message === 'Relation marked successfully.') {
								// Toggle menu options
								element.style.display = 'none';
								element.closest('.card').querySelector('.unmark-for-today').style.display = 'block';

								const closestAvatar = element.closest('.card').querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
								if (closestAvatar) {
									closestAvatar.classList.add('today');
								}

							} else {
								alert('Failed to mark task for today.');
							}
						})
						.catch(error => console.error('Error:', error));
					});
				});

				document.querySelectorAll('.unmark-for-today').forEach((element) => {
					element.addEventListener('click', function () {
						var taskId = element.getAttribute('data-task-id');
						fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/unmark_relation', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
							},
							body: JSON.stringify({ user_id: userId, date: '<?php echo date( 'Y-m-d' ); ?>' })
						})
						.then(response => {
							if (!response.ok) {
								throw new Error('Network response was not ok');
							}
							return response.json();
						})
						.then(data => {
							if (data.message === 'Relation unmarked successfully.') {
								// Toggle menu options
								element.style.display = 'none';
								element.closest('.card').querySelector('.mark-for-today').style.display = 'block';
						
								const closestAvatar = element.closest('.card').querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
								if (closestAvatar) {
									closestAvatar.classList.remove('today');
								}

							} else {
								alert('Failed to unmark task for today.');
							}
						})
						.catch(error => console.error('Error:', error));
					});
				});

				document.querySelectorAll('.archive-task').forEach((element) => {
					element.addEventListener('click', function () {
						var taskId = element.getAttribute('data-task-id');
						if (confirm('Are you sure you want to archive this task?')) {
							fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId), {
								method: 'PUT',
								headers: {
									'Content-Type': 'application/json',
									'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
								},
								body: JSON.stringify({ status: 'archived' })
							})
							.then(response => {
								if (!response.ok) {
									throw new Error('Network response was not ok');
								}
								return response.json();
							})
							.then(data => {
								if (data.message === 'Task status updated successfully.') {
									element.closest('.card').remove();
								} else {
									alert('Failed to archive task.');
								}
							})
							.catch(error => console.error('Error:', error));
						}
					});
				});
			});
		</script>
	</body>
</html>
