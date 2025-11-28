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
$current_board      = $current_board_slug ? BoardManager::get_board_by_slug( $current_board_slug ) : null;
$current_date       = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ( new DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );
$boards             = BoardManager::get_all_boards();

// Sample data for mockup when no board is selected.
$sample_days   = array();
$sample_boards = array(
	array(
		'name' => 'Soporte',
		'color' => '#0d6efd',
	),
	array(
		'name' => 'Desarrollo',
		'color' => '#198754',
	),
	array(
		'name' => 'Planificación',
		'color' => '#6f42c1',
	),
);
// Sample stacks to mimic columns used across views.
$sample_stacks = array( 'to-do', 'in-progress', 'done' );
for ( $i = 0; $i < 5; $i++ ) {
	$date_key                 = ( new DateTime( 'now -' . $i . ' days', wp_timezone() ) )->format( 'Y-m-d' );
	$sample_days[ $date_key ] = array(
		array(
			'title' => 'Revisar incidencias del cliente X',
			'board' => $sample_boards[ $i % 3 ],
			'stack' => $sample_stacks[ $i % 3 ],
		),
		array(
			'title' => 'Preparar informe semanal del proyecto',
			'board' => $sample_boards[ ( $i + 1 ) % 3 ],
			'stack' => $sample_stacks[ ( $i + 1 ) % 3 ],
		),
		array(
			'title' => 'Planificar tareas para el día siguiente',
			'board' => $sample_boards[ ( $i + 2 ) % 3 ],
			'stack' => $sample_stacks[ ( $i + 2 ) % 3 ],
		),
	);
}

?>

<head>
	<title><?php echo esc_html( $current_board ? $current_board->name : __( 'Daily Journal', 'decker' ) ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>
	<?php include 'layouts/head-css.php'; ?>
	<style>
		/* Compact rows for mock task tables */
		.journal-tasks-table.table-sm > :not(caption) > * > * {
			padding-top: .25rem;
			padding-bottom: .25rem;
		}
		.journal-tasks-table .descripcion a {
			display: inline-flex;
			align-items: center;
			gap: .25rem;
		}
	</style>
</head>
<body <?php body_class(); ?>>

	<div class="wrapper">
		<?php include 'layouts/menu.php'; ?>
		<div class="content-page">
			<div class="content">
				<div class="container-fluid">
					<div class="row">
						<div class="col-12">
							<div class="page-title-box d-flex align-items-center justify-content-between">
								<h4 class="page-title">
									<?php
									if ( $current_board ) {
										/* translators: %s: board name */
										printf( esc_html__( 'Journal for %s', 'decker' ), esc_html( $current_board->name ) );
									} else {
										esc_html_e( 'Daily Journal', 'decker' );
									}
									?>
								</h4>
							<div class="d-flex align-items-center">
								<form class="row gy-2 gx-2 align-items-center me-2" id="daily-view-form">
									<div class="col-auto">
										<label for="date-select" class="visually-hidden">Date</label>
										<input class="form-control" type="date" id="date-select" value="<?php echo esc_attr( $current_date ); ?>">
									</div>
									<div class="col-auto">
										<button type="button" class="btn btn-outline-secondary" id="preview-btn" data-bs-toggle="modal" data-bs-target="#journalPreviewModal">
											<i class="ri-eye-line me-1"></i><?php echo esc_html__( 'Preview', 'decker' ); ?>
										</button>
									</div>
								</form>
								<div class="col-auto">
									<input id="task-search-input" type="search" class="form-control" placeholder="<?php esc_attr_e( 'Search tasks...', 'decker' ); ?>">
								</div>
							</div>
						</div>
					</div>
				</div>

				<?php if ( $current_board ) : ?>
				<div id="daily-view-content" class="row d-none">
					<div class="col-12">
						<div class="card">
							<div class="card-body">
								<h5 class="card-title mb-3"><?php esc_html_e( 'Tasks of the Day', 'decker' ); ?></h5>
								<ul id="daily-tasks-list" class="list-group"></ul>
							</div>
						</div>
						<div class="card" id="notes-card">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-3">
									<h5 class="card-title mb-0"><?php esc_html_e( 'Comments/Observations', 'decker' ); ?></h5>
									<button class="btn btn-danger btn-sm d-none" id="delete-notes-btn"><?php esc_html_e( 'Delete Notes', 'decker' ); ?></button>
								</div>
								<?php
								// Use default WordPress editor buttons (no custom table plugin).
								$settings = array(
									'media_buttons' => true,
									'textarea_name' => 'journal_notes',
									'textarea_rows' => 10,
									'tinymce'       => true,
									'quicktags'     => true,
								);
								wp_editor( '', 'journal_notes_editor', $settings );
								?>
								<div class="d-flex justify-content-between align-items-center mt-2">
									<div class="text-muted small">
										<?php esc_html_e( 'Last saved:', 'decker' ); ?>
										<span id="notes-last-saved">—</span>
									</div>
									<button class="btn btn-primary" id="save-notes-btn"><i class="ri-save-3-line me-1"></i><?php esc_html_e( 'Save Notes', 'decker' ); ?></button>
								</div>
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
				<?php else : ?>
				<!-- Mockup view: sample days and tasks -->
				<div id="mock-view-content" class="row">
					<div class="col-12">
						<?php
						// Usuarios implicados (mock) para mostrar avatares.
						$mock_users = get_users(
							array(
								'number'  => 8,
								'orderby' => 'display_name',
								'order'   => 'ASC',
								'fields'  => array( 'ID', 'display_name' ),
							)
						);
						// Control initial open state for the first day.
						$is_first = true;
						?>
					<?php foreach ( $sample_days as $date_key => $tasks ) : ?>
									<div class="card mb-3 journal-day-card" data-date="<?php echo esc_attr( $date_key ); ?>">
										<div class="card-header d-flex justify-content-between align-items-center">
											<h5 class="mb-0">
												<?php
												$date_obj = date_create( $date_key, wp_timezone() );
												echo esc_html( wp_date( 'l, j \d\e F \d\e Y', $date_obj ? $date_obj->getTimestamp() : strtotime( $date_key ) ) );
												?>
										</div>
										<div class="card-body pt-2">
											<div class="table-responsive">
											<table class="table table-sm table-borderless table-hover table-nowrap table-centered m-0 journal-tasks-table">
												<thead class="border-top border-bottom bg-light-subtle border-light">
													<tr>
														<th class="py-1"><?php echo esc_html__( 'Board', 'decker' ); ?></th>
														<th class="py-1"><?php echo esc_html__( 'Title', 'decker' ); ?></th>
														<th class="py-1" style="width: 15%;" data-sort-method="none"><?php esc_html_e( 'Assigned Users', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ( $tasks as $task ) : ?>
														<tr class="task-row">
															<td>
																<?php
																$board_display = '';
																if ( ! empty( $task['board'] ) ) {
																	$board_display = '<span class="custom-badge overflow-visible" style="background-color: ' . esc_attr( $task['board']['color'] ) . ';">' . esc_html( $task['board']['name'] ) . '</span>';
																}
																echo wp_kses_post( $board_display );
																?>
															</td>
															<td class="descripcion" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr( $task['title'] ); ?>">
																<?php if ( ! empty( $task['stack'] ) ) : ?>
																	<?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task['stack'] ) ); ?>
																<?php endif; ?>
																<a href="#" onclick="return false;"><?php echo esc_html( $task['title'] ); ?></a>
															</td>
															<td>
																<div class="avatar-group mt-2">
																	<?php
																	$assigned_users = array();
																	if ( ! empty( $mock_users ) ) {
																		$mock_user_count = count( $mock_users );
																		$n = min( $mock_user_count, max( 2, rand( 2, 3 ) ) );
																		$indices = array_rand( $mock_users, $n );
																		if ( ! is_array( $indices ) ) {
																			$indices = array( $indices );
																		}
																		foreach ( $indices as $idx ) {
																			if ( isset( $mock_users[ $idx ] ) ) {
																				$assigned_users[] = $mock_users[ $idx ];
																			}
																		}
																	}
																	foreach ( $assigned_users as $u ) :
																		?>
																		<a href="javascript: void(0);" class="avatar-group-item" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo esc_attr( $u->display_name ); ?>">
																			<img src="<?php echo esc_url( get_avatar_url( $u->ID ) ); ?>" alt="" class="rounded-circle avatar-xs" data-user-id="<?php echo esc_attr( $u->ID ); ?>">
																		</a>
																	<?php endforeach; ?>
																</div>
															</td>
														</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
											</div>
											<div class="mt-3">
											<h6 class="card-title mb-2"><?php echo esc_html__( 'Daily Notes', 'decker' ); ?></h6>
											<?php
											// Mock editors also use default WordPress buttons (no custom table plugin).
											$settings = array(
												'media_buttons' => true,
												'textarea_name' => 'journal_notes_' . esc_attr( str_replace( '-', '', $date_key ) ),
												'textarea_rows' => 6,
												'tinymce'       => true,
												'quicktags'     => true,
											);
											wp_editor( '', 'journal_notes_editor_' . esc_attr( str_replace( '-', '', $date_key ) ), $settings );
											?>
											<div class="d-flex justify-content-between align-items-center mt-2">
												<div class="text-muted small">
													<?php echo esc_html__( 'Last saved:', 'decker' ); ?>
													<span class="mock-notes-last-saved" data-date="<?php echo esc_attr( $date_key ); ?>">—</span>
												</div>
												<button class="btn btn-primary mock-save-notes-btn" data-date="<?php echo esc_attr( $date_key ); ?>"><i class="ri-save-3-line me-1"></i><?php echo esc_html__( 'Save', 'decker' ); ?></button>
											</div>
										</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<div class="col-lg-4">
							<div class="alert alert-info">
								<?php echo esc_html__( 'Example view with no board selected. Use this mock to review the layout.', 'decker' ); ?>
							</div>
					</div>
				</div>
				<?php endif; ?>

				</div>
			</div>
			<?php include 'layouts/footer.php'; ?>
		</div>
	</div>

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<!-- Preview Modal -->
	<div class="modal fade" id="journalPreviewModal" tabindex="-1" aria-labelledby="journalPreviewModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="journalPreviewModalLabel"><?php echo esc_html__( 'Journal Preview', 'decker' ); ?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'decker' ); ?>"></button>
				</div>
				<div class="modal-body">
					<div class="row g-2 align-items-end mb-3">
						<div class="col-sm-6">
							<label for="preview-from" class="form-label"><?php echo esc_html__( 'From', 'decker' ); ?></label>
							<input type="date" id="preview-from" class="form-control">
						</div>
						<div class="col-sm-6">
							<label for="preview-to" class="form-label"><?php echo esc_html__( 'To', 'decker' ); ?></label>
							<input type="date" id="preview-to" class="form-control">
						</div>
					</div>
					<div id="journal-preview-content" class="preview-content">
						<!-- Rendered preview appears here -->
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
					<button type="button" class="btn btn-primary" id="journal-preview-print"><i class="ri-printer-line me-1"></i><?php echo esc_html__( 'Print', 'decker' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<script>
		jQuery(document).ready(function($) {
			const dateSelect = $('#date-select');
			const searchInput = $('#task-search-input');
			const dailyViewContent = $('#daily-view-content');
			const dailyTasksList = $('#daily-tasks-list');
			const dailyUsersList = $('#daily-users-list');
			const notesCard = $('#notes-card');
			const noTasksAlert = $('#no-tasks-alert');
			const saveNotesBtn = $('#save-notes-btn');
			const deleteNotesBtn = $('#delete-notes-btn');

			// Enhance mock view: collapsible days with prominent H1 date and arrow indicator.
			const mockContainer = $('#mock-view-content');
			if (mockContainer.length) {
				const cards = mockContainer.find('.card.mb-3');
				cards.each(function(index) {
					const card = $(this);
					let header = card.children('.card-header').first();
					if (!header.length) {
						header = card.children('.d-flex').first();
					}
					const collapseId = 'mock_day_' + index;
					const isFirst = index === 0;

					// Extract current date text
					const dateText = $.trim(header.text());

					// Build a proper Bootstrap 5 toggle button with icon + H1
					header.addClass('justify-content-between align-items-center');

					const toggleBtn = $('<button/>', {
						'class': 'btn btn-link text-start p-0 d-flex align-items-center gap-2 flex-grow-1',
						'type': 'button',
						'data-bs-toggle': 'collapse',
						'data-bs-target': '#' + collapseId,
						'aria-controls': collapseId,
						'aria-expanded': isFirst ? 'true' : 'false'
					});
					const icon = $('<i/>', {
						'class': (isFirst ? 'ri-arrow-down-s-line' : 'ri-arrow-right-s-line') + ' fs-4',
						'aria-hidden': 'true'
					});
					const title = $('<span/>', { 'class': 'mb-0 fw-bold fs-3 flex-grow-1', text: dateText });
					toggleBtn.append(icon, title);
					header.empty().append(toggleBtn);

					// Compose participants from unique avatars found in rows
					const participants = $('<div/>', { 'class': 'day-participants avatar-group d-flex align-items-center ms-2' });
					const seen = new Set();
					card.find('img.avatar-xs[data-user-id]').each(function() {
						const uid = $(this).data('user-id');
						if (!uid || seen.has(uid)) return;
						seen.add(uid);
						const title = $(this).closest('a').attr('title') || '';
						const src = $(this).attr('src');
						const a = $('<a/>', { href: 'javascript:void(0);', 'class': 'avatar-group-item', 'data-bs-toggle': 'tooltip', 'data-bs-placement': 'top', title });
						const img = $('<img/>', { src, alt: '', 'class': 'rounded-circle avatar-xs' });
						a.append(img);
						participants.append(a);
					});
					header.append(participants);

					// Wrap following content into a collapsible container
					const siblings = card.children().not(header);
					const wrapper = $('<div/>', { id: collapseId, 'class': 'collapse' + (isFirst ? ' show' : '') });
					siblings.wrapAll(wrapper);

					// Update arrow on show/hide
					const collapseEl = document.getElementById(collapseId);
					collapseEl.addEventListener('show.bs.collapse', function () {
						icon.removeClass('ri-arrow-right-s-line').addClass('ri-arrow-down-s-line');
						toggleBtn.attr('aria-expanded', 'true').removeClass('collapsed');
					});
					collapseEl.addEventListener('hide.bs.collapse', function () {
						icon.removeClass('ri-arrow-down-s-line').addClass('ri-arrow-right-s-line');
						toggleBtn.attr('aria-expanded', 'false').addClass('collapsed');
					});
				});

				// Mock: update last-saved label when clicking save
				mockContainer.on('click', '.mock-save-notes-btn', function() {
					const date = $(this).data('date');
					const target = mockContainer.find('.mock-notes-last-saved[data-date="' + date + '"]');
					const now = new Date();
					try {
						target.text(now.toLocaleString());
					} catch (e) {
						target.text(now.toISOString());
					}
				});
			}

			// Preview modal logic (mock-based rendering)
			const previewModalEl = document.getElementById('journalPreviewModal');
			if (previewModalEl) {
				previewModalEl.addEventListener('shown.bs.modal', function () {
					// Default date range: last 7 days ending today
					const today = new Date();
					const pad = n => (n < 10 ? '0' + n : '' + n);
					const toStr = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
					const fromDate = new Date(today);
					fromDate.setDate(today.getDate() - 7);
					const fromStr = `${fromDate.getFullYear()}-${pad(fromDate.getMonth()+1)}-${pad(fromDate.getDate())}`;

					const fromInput = document.getElementById('preview-from');
					const toInput = document.getElementById('preview-to');
					if (fromInput && !fromInput.value) fromInput.value = fromStr;
					if (toInput && !toInput.value) toInput.value = toStr;

					renderPreview();
				});

				const renderPreview = () => {
					const container = document.getElementById('journal-preview-content');
					if (!container) return;
					container.innerHTML = '';

					const from = document.getElementById('preview-from')?.value;
					const to = document.getElementById('preview-to')?.value;
					if (!mockContainer.length) {
						container.innerHTML = '<div class="alert alert-info"><?php echo esc_html__( 'There is no content to preview in this view.', 'decker' ); ?></div>';
						return;
					}

					const cards = mockContainer.find('.journal-day-card');
					if (!cards.length) {
						container.innerHTML = '<div class="alert alert-info"><?php echo esc_html__( 'No days available.', 'decker' ); ?></div>';
						return;
					}

					const inRange = (d) => {
						if (!from && !to) return true;
						if (from && d < from) return false;
						if (to && d > to) return false;
						return true;
					};

					// Build simple pretty preview (non-collapsible)
					cards.each(function() {
						const el = $(this);
						const dateIso = el.data('date');
						if (!inRange(dateIso)) return;
						const clone = el.clone();
						// Remove collapse-related structure if any and ensure padding
						clone.find('.card-header [data-bs-toggle="collapse"]').each(function(){
							const h1Text = $(this).text();
							$(this).replaceWith($('<h3/>', { 'class': 'mb-0', text: h1Text }));
						});
						clone.find('.collapse').removeClass('collapse').addClass('show');

						// Replace editor with rendered HTML content
						try {
							const originalTextarea = el.find('textarea[id^="journal_notes_editor_"]').first();
							if (originalTextarea.length) {
								const editorId = originalTextarea.attr('id');
								let html = '';
								if (window.tinymce && tinymce.get(editorId)) {
									html = tinymce.get(editorId).getContent();
								} else {
									html = originalTextarea.val() || '';
								}
								const wrap = clone.find('.wp-editor-wrap').first();
								if (wrap.length) {
									const rendered = $('<div/>', { 'class': 'border rounded p-2 bg-light', 'html': html });
									wrap.replaceWith(rendered);
								}
								// Remove save bar within preview
								clone.find('.mock-save-notes-btn').closest('.d-flex').remove();
							}
						} catch (e) {}
						container.appendChild(clone.get(0));
					});

					if (!container.children.length) {
						container.innerHTML = '<div class="alert alert-warning"><?php echo esc_html__( 'No results in the selected range.', 'decker' ); ?></div>';
					}
				};

				document.getElementById('preview-from')?.addEventListener('change', renderPreview);
				document.getElementById('preview-to')?.addEventListener('change', renderPreview);

				document.getElementById('journal-preview-print')?.addEventListener('click', function(){
					const area = document.getElementById('journal-preview-content');
					if (!area) return;
					const w = window.open('', '_blank');
					if (!w) return;
					const html = `<!doctype html><html><head><meta charset="utf-8"><title><?php echo esc_html__( 'Journal Preview', 'decker' ); ?></title>
					<style>
						body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;}
						.card{border:1px solid #ddd;border-radius:.375rem;margin-bottom:1rem;}
						.card-header{padding:.75rem 1rem;border-bottom:1px solid rgba(0,0,0,.125);font-weight:600}
						.card-body{padding:1rem}
						.table{width:100%;border-collapse:collapse}
						.table th,.table td{padding:.5rem;text-align:left;border-top:1px solid #e9ecef}
						.avatar-xs{width:24px;height:24px}
						.rounded-circle{border-radius:50%}
					</style></head><body>` + area.innerHTML + '</body></html>';
					w.document.open();
					w.document.write(html);
					w.document.close();
					w.focus();
					w.print();
				});
			}

			function loadDailyData() {
				const boardSlug = '<?php echo esc_js( $current_board_slug ); ?>';
				const date = dateSelect.val();

				if (!boardSlug || !date) {
					dailyViewContent.addClass('d-none');
					return;
				}

				const url = `${wpApiSettings.root}decker/v1/daily?board=${encodeURIComponent(boardSlug)}&date=${date}`;
				fetch(url, {
					headers: { 'X-WP-Nonce': wpApiSettings.nonce }
				})
				.then(response => {
					if (!response.ok) {
						return response.json().then(err => { throw new Error(err.message) });
					}
					return response.json();
				})
				.then(data => {

					// Render Users
					dailyUsersList.empty();
					if (data.users && data.users.length > 0) {
						const userPromises = data.users.map(userId => fetch(`${wpApiSettings.root}wp/v2/users/${userId}`).then(res => res.json()));
						Promise.all(userPromises).then(usersData => {
							usersData.forEach(user => {
								dailyUsersList.append(`<li class="list-group-item">${user.name}</li>`);
							});
						});
					}

					// Render Tasks
					dailyTasksList.empty();
					if (data.tasks && data.tasks.length > 0) {
						const taskPromises = data.tasks.map(taskId => fetch(`${wpApiSettings.root}wp/v2/tasks/${taskId}`).then(res => res.json()));
						Promise.all(taskPromises).then(tasksData => {
							tasksData.forEach(task => {
								dailyTasksList.append(`<li class="list-group-item"><a href="/decker/task/${task.id}" target="_blank">${task.title.rendered}</a></li>`);
							});
						});

						notesCard.removeClass('d-none');
						noTasksAlert.addClass('d-none');
						if (tinymce.get('journal_notes_editor')) {
							tinymce.get('journal_notes_editor').setContent(data.notes || '');
						}
						if (data.notes) {
							deleteNotesBtn.removeClass('d-none');
						} else {
							deleteNotesBtn.addClass('d-none');
						}
					} else {
						notesCard.addClass('d-none');
						noTasksAlert.removeClass('d-none');
					}

					dailyViewContent.removeClass('d-none');
				})
				.catch(error => {
					console.error('Error fetching daily data:', error);
					alert('Error fetching data: ' + error.message);
				});
			}

			function saveNotes() {
				const boardSlug = '<?php echo esc_js( $current_board_slug ); ?>';
				const date = dateSelect.val();
				const notes = tinymce.get('journal_notes_editor') ? tinymce.get('journal_notes_editor').getContent() : '';

				// Optimistically update last-saved label
				try {
					const now = new Date();
					const label = document.getElementById('notes-last-saved');
					if (label) {
						label.textContent = now.toLocaleString();
					}
				} catch (e) {}

				const url = `${wpApiSettings.root}decker/v1/daily`;
				fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
					body: JSON.stringify({ board: boardSlug, date: date, notes: notes })
				})
				.then(response => {
					if (!response.ok) {
						return response.json().then(err => { throw new Error(err.message) });
					}
					return response.json();
				})
				.then(data => {
					// Maybe show a success message
					location.reload(); // Simple reload on success
				})
				.catch(error => {
					console.error('Error saving notes:', error);
					alert('Error saving notes: ' + error.message);
				});
			}

			function filterTasks() {
				const searchTerm = searchInput.val().toLowerCase();
				$('#daily-tasks-list li').each(function() {
					const taskTitle = $(this).text().toLowerCase();
					if (taskTitle.includes(searchTerm)) {
						$(this).show();
					} else {
						$(this).hide();
					}
				});
			}

			function deleteNotes() {
				if ( ! confirm('<?php esc_html_e( 'Are you sure you want to delete the notes for this day?', 'decker' ); ?>') ) {
					return;
				}
				const boardSlug = '<?php echo esc_js( $current_board_slug ); ?>';
				const date = dateSelect.val();
				const url = `${wpApiSettings.root}decker/v1/daily?board=${encodeURIComponent(boardSlug)}&date=${date}`;
				fetch(url, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': wpApiSettings.nonce },
				})
				.then(response => {
					if (!response.ok) {
						return response.json().then(err => { throw new Error(err.message) });
					}
					location.reload();
				})
				.catch(error => {
					console.error('Error deleting notes:', error);
					alert('Error deleting notes: ' + error.message);
				});
			}

			dateSelect.on('change', function() {
				const newDate = $(this).val();
				const boardSlug = '<?php echo esc_js( $current_board_slug ); ?>';
				if (newDate && boardSlug) {
					window.location.href = `?decker_page=journal&board=${boardSlug}&date=${newDate}`;
				}
			});
			saveNotesBtn.on('click', saveNotes);
			deleteNotesBtn.on('click', deleteNotes);
			searchInput.on('keyup', filterTasks);

			// Initial load if board is selected
			if ('<?php echo esc_js( $current_board_slug ); ?>') {
				loadDailyData();
			}
		});
	</script>

</body>
</html>
