<?php
/**
 * Daily View for Journals
 *
 * @package    Decker
 * @subpackage Decker/public
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

$current_board_slug = isset( $_GET['board'] ) ? sanitize_text_field( wp_unslash( $_GET['board'] ) ) : '';
$current_date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ( new DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );
$boards = BoardManager::get_all_boards();

?>

<head>
	<title><?php esc_html_e( 'Daily Journal', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>
	<?php include 'layouts/head-css.php'; ?>
</head>
<body <?php body_class(); ?>>

	<div class="wrapper">
		<?php include 'layouts/menu.php'; ?>
		<div class="content-page">
			<div class="content">
				<div class="container-fluid">
					<div class="row">
						<div class="col-12">
							<div class="page-title-box">
								<h4 class="page-title"><?php esc_html_e( 'Daily Journal', 'decker' ); ?></h4>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-12">
							<div class="card">
								<div class="card-body">
									<form class="row gy-2 gx-2 align-items-center" id="daily-view-form">
										<div class="col-auto">
											<label for="board-select" class="visually-hidden">Board</label>
											<select class="form-select" id="board-select">
												<option value=""><?php esc_html_e( 'Select a Board...', 'decker' ); ?></option>
												<?php foreach ( $boards as $board ) : ?>
													<option value="<?php echo esc_attr( $board->slug ); ?>" <?php selected( $current_board_slug, $board->slug ); ?>>
														<?php echo esc_html( $board->name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="col-auto">
											<label for="date-select" class="visually-hidden">Date</label>
											<input class="form-control" type="date" id="date-select" value="<?php echo esc_attr( $current_date ); ?>">
										</div>
									</form>
								</div>
							</div>
						</div>
					</div>

					<div id="daily-view-content" class="row d-none">
						<div class="col-lg-8">
							<div class="card">
								<div class="card-body">
									<h5 class="card-title mb-3"><?php esc_html_e( 'Tasks of the Day', 'decker' ); ?></h5>
									<ul id="daily-tasks-list" class="list-group"></ul>
								</div>
							</div>
							<div class="card" id="notes-card">
								<div class="card-body">
									<h5 class="card-title mb-3"><?php esc_html_e( 'Comments/Observations', 'decker' ); ?></h5>
									<div id="notes-editor" style="height: 300px;"></div>
									<button class="btn btn-primary mt-2" id="save-notes-btn"><?php esc_html_e( 'Save Notes', 'decker' ); ?></button>
								</div>
							</div>
							<div class="alert alert-info d-none" id="no-tasks-alert">
								<?php esc_html_e( 'No tasks for this board on this date.', 'decker' ); ?>
							</div>
						</div>
						<div class="col-lg-4">
							<div class="card">
								<div class="card-body">
									<h5 class="card-title mb-3"><?php esc_html_e( 'Users of the Day', 'decker' ); ?></h5>
									<ul id="daily-users-list" class="list-group"></ul>
								</div>
							</div>
						</div>
					</div>

				</div>
			</div>
			<?php include 'layouts/footer.php'; ?>
		</div>
	</div>

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
		jQuery(document).ready(function($) {
			const boardSelect = $('#board-select');
			const dateSelect = $('#date-select');
			const dailyViewContent = $('#daily-view-content');
			const dailyTasksList = $('#daily-tasks-list');
			const dailyUsersList = $('#daily-users-list');
			const notesCard = $('#notes-card');
			const noTasksAlert = $('#no-tasks-alert');
			const saveNotesBtn = $('#save-notes-btn');
			let notesEditor;

			function initializeQuill() {
				if (!notesEditor) {
					notesEditor = new Quill('#notes-editor', {
						theme: 'snow',
						modules: { toolbar: [[{ 'header': [1, 2, false] }], ['bold', 'italic', 'underline'], ['link', 'blockquote']] }
					});
				}
			}

			async function loadDailyData() {
				const boardSlug = boardSelect.val();
				const date = dateSelect.val();

				if (!boardSlug || !date) {
					dailyViewContent.addClass('d-none');
					return;
				}

				const url = `${wpApiSettings.root}decker/v1/daily?board=${encodeURIComponent(boardSlug)}&date=${date}`;
				try {
					const response = await fetch(url, {
						headers: { 'X-WP-Nonce': wpApiSettings.nonce }
					});
					const data = await response.json();

					if (!response.ok) {
						throw new Error(data.message);
					}

					// Render Users
					dailyUsersList.empty();
					if (data.users && data.users.length > 0) {
						const userPromises = data.users.map(userId => fetch(`${wpApiSettings.root}wp/v2/users/${userId}`).then(res => res.json()));
						const usersData = await Promise.all(userPromises);
						usersData.forEach(user => {
							dailyUsersList.append(`<li class="list-group-item">${user.name}</li>`);
						});
					}

					// Render Tasks
					dailyTasksList.empty();
					if (data.tasks && data.tasks.length > 0) {
						const taskPromises = data.tasks.map(taskId => fetch(`${wpApiSettings.root}wp/v2/decker_task/${taskId}`).then(res => res.json()));
						const tasksData = await Promise.all(taskPromises);
						tasksData.forEach(task => {
							dailyTasksList.append(`<li class="list-group-item"><a href="/decker/task/${task.id}" target="_blank">${task.title.rendered}</a></li>`);
						});
						notesCard.removeClass('d-none');
						noTasksAlert.addClass('d-none');
						initializeQuill();
						notesEditor.root.innerHTML = data.notes || '';
					} else {
						notesCard.addClass('d-none');
						noTasksAlert.removeClass('d-none');
					}

					dailyViewContent.removeClass('d-none');
				} catch (error) {
					console.error('Error fetching daily data:', error);
					alert('Error fetching data: ' + error.message);
				}
			}

			async function saveNotes() {
				const boardSlug = boardSelect.val();
				const date = dateSelect.val();
				const notes = notesEditor.root.innerHTML;

				const url = `${wpApiSettings.root}decker/v1/daily`;
				try {
					const response = await fetch(url, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
						body: JSON.stringify({ board: boardSlug, date: date, notes: notes })
					});
					const data = await res.json();
					if (!response.ok) {
						throw new Error(data.message || 'Error saving notes.');
					}
					// Maybe show a success message
				} catch (error) {
					console.error('Error saving notes:', error);
					alert('Error saving notes: ' + error.message);
				}
			}

			boardSelect.on('change', loadDailyData);
			dateSelect.on('change', loadDailyData);
			saveNotesBtn.on('click', saveNotes);

			// Initial load if board is selected
			if (boardSelect.val()) {
				loadDailyData();
			}
		});
	</script>

</body>
</html>
