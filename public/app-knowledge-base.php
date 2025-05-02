<?php
/**
 * File app-knowledge-base
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

// Get filter parameters.
$board_slug = isset( $_GET['board'] ) ? sanitize_text_field( wp_unslash( $_GET['board'] ) ) : '';
$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';

// Get articles based on filters.
$args = array();
if ( ! empty( $board_slug ) && 'all' !== $view ) {
	// Filter by board.
	$board_term = get_term_by( 'slug', $board_slug, 'decker_board' );
	if ( $board_term ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'decker_board',
				'field'    => 'slug',
				'terms'    => $board_slug,
			),
		);
	}
}

$kb_data = Decker_Kb::get_articles( $args );

/*
// Test.
echo '<pre>';
print_r($kb_data);
die();
*/
?>

<head>
	<title><?php esc_html_e( 'Knowledge Base', 'decker' ); ?> | Decker</title>
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
						<div class="col-xxl-12">

							<div class="page-title-box d-flex align-items-center justify-content-between">
							
								<?php
								$page_title = __( 'Knowledge Base', 'decker' );

								// Add board name to title if filtering by board.
								if ( ! empty( $board_slug ) ) {
									$board_term = get_term_by( 'slug', $board_slug, 'decker_board' );
									if ( $board_term ) {
										$page_title .= ' - ' . $board_term->name;
									}
								} elseif ( 'all' === $view ) {
									$page_title .= ' - ' . __( 'All Articles', 'decker' );
								}
								?>

								<h4 class="page-title">
									<?php echo esc_html( $page_title ); ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'knowledge-base' ), home_url( '/' ) ) ); ?>" 
									   class="btn btn-success btn-sm ms-3 <?php echo esc_attr( $class_disabled ); ?>" 
									   data-bs-toggle="modal" data-bs-target="#kb-modal" data-article-id="">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Article', 'decker' ); ?>
									</a>
								</h4>

								<div class="d-flex align-items-center">
									<div class="me-2">
										<a href="
										<?php
										echo esc_url(
											add_query_arg(
												array(
													'decker_page' => 'knowledge-base',
													'view' => 'all',
												),
												home_url( '/' )
											)
										);
										?>
										" 
										   class="btn btn-outline-primary btn-sm <?php echo 'all' === $view ? 'active' : ''; ?>">
											<?php esc_html_e( 'All Articles', 'decker' ); ?>
										</a>
									</div>
									<select id="categoryFilter" class="form-select">
										<option value=""><?php esc_html_e( 'All Labels', 'decker' ); ?></option>
										<?php
										$categories = get_terms(
											array(
												'taxonomy'   => 'decker_label',
												'hide_empty' => false,
											)
										);
										foreach ( $categories as $category ) {
											echo '<option value="' . esc_attr( $category->name ) . '">' . esc_html( $category->name ) . '</option>';
										}
										?>
									</select>
								</div>
							</div>

							<?php include 'layouts/top-alert.php'; ?>

							<div class="row">
								<div class="col-12">
									<div class="card">
										<div class="card-body table-responsive">

											<table id="tablaKB" class="table table-striped table-bordered dt-responsive nowrap w-100">
												<thead>
													<tr>
														<th class="col-3"><?php esc_html_e( 'Title', 'decker' ); ?></th>
														<?php if ( 'all' === $view ) : ?>
														<th class="col-1"><?php esc_html_e( 'Board', 'decker' ); ?></th>
														<?php endif; ?>
														<th class="col-2"><?php esc_html_e( 'Tags', 'decker' ); ?></th>
														<th class="col-1"><?php esc_html_e( 'Author', 'decker' ); ?></th>
														<th class="col-1"><?php esc_html_e( 'Excerpt', 'decker' ); ?></th>
														<th class="col-2"><?php esc_html_e( 'Updated', 'decker' ); ?></th>
														<th class="col-2 text-end"><?php esc_html_e( 'Actions', 'decker' ); ?></th>
														<th class="d-none"><?php esc_html_e( 'Content', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php

												foreach ( $kb_data as $article ) {
													echo '<tr>';

													// Article Title with hierarchy.
													echo '<td>';

													// Get board data for the article.
													$board_data = null;
													$board_terms = wp_get_post_terms( $article->ID, 'decker_board' );
													if ( ! empty( $board_terms ) ) {
														$board = $board_terms[0];
														$board_color = get_term_meta( $board->term_id, 'term-color', true );
														$board_data = array(
															'name' => $board->name,
															'color' => $board_color,
														);
													}

													// Get labels with colors.
													$labels_data = array();
													$labels = wp_get_post_terms( $article->ID, 'decker_label' );
													if ( ! empty( $labels ) ) {
														foreach ( $labels as $label ) {
															$color = get_term_meta( $label->term_id, 'term-color', true );
															$labels_data[] = array(
																'name' => $label->name,
																'color' => $color,
															);
														}
													}

													// Store article data as data attributes.
													$article_data = array(
														'id' => $article->ID,
														'title' => $article->post_title,
														'content' => $article->post_content,
														'labels' => $labels_data,
														'board' => $board_data,
													);

													// JSON encode for data attributes.
													$article_data_json = array(
														'id' => $article->ID,
														'title' => $article->post_title,
														'content' => $article->post_content,
														'labels' => wp_json_encode( $article_data['labels'] ),
														'board' => wp_json_encode( $article_data['board'] ),
													);

													// Sanitize and output the article title with hierarchy.
													echo esc_html( str_repeat( '— ', intval( $article->depth ) ) ) .
														'<a href="javascript:void(0);" class="view-article-link" ' .
														'data-id="' . esc_attr( $article_data['id'] ) . '" ' .
														'data-title="' . esc_attr( $article_data['title'] ) . '" ' .
														'data-content="' . esc_attr( $article_data['content'] ) . '" ' .
														'data-labels=\'' . esc_attr( $article_data_json['labels'] ) . '\' ' .
														'data-board=\'' . esc_attr( $article_data_json['board'] ) . '\' ' .
														'title="' . esc_attr( $article->post_title ) . '">' .
														esc_html( $article->post_title ) . '</a>';

													echo '</td>';

													// Show board column when viewing all articles.
													if ( 'all' === $view ) {
														echo '<td>';
														$board_terms = wp_get_post_terms( $article->ID, 'decker_board' );
														if ( ! empty( $board_terms ) ) {
															$board = $board_terms[0];
															$board_color = get_term_meta( $board->term_id, 'term-color', true );
															echo '<span class="badge" style="background-color: ' . esc_attr( $board_color ) . ';">' . esc_html( $board->name ) . '</span>';
														} else {
															echo '<span class="text-muted">' . esc_html__( 'No Board', 'decker' ) . '</span>';
														}
														echo '</td>';
													}

													// Labels with colors.
													echo '<td>';
													$labels = wp_get_post_terms( $article->ID, 'decker_label' );
													if ( ! empty( $labels ) ) {
														foreach ( $labels as $label ) {
															$color = get_term_meta( $label->term_id, 'term-color', true );
															echo '<span class="badge me-1 mb-1 d-block" style="background-color: ' . esc_attr( $color ) . ';">' . esc_html( $label->name ) . '</span>';
														}
													} else {
														echo '<span class="text-muted">' . esc_html__( 'Uncategorized', 'decker' ) . '</span>';
													}
													echo '</td>';

													// Author with avatar.
													echo '<td>';
													echo '<div class="avatar-group">';
													echo '<a href="javascript: void(0);" class="avatar-group-item" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '" data-bs-original-title="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '">';
													echo '<span class="d-none">' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '</span>';
													echo '<img src="' . esc_url( get_avatar_url( $article->post_author ) ) . '" alt="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '" class="rounded-circle avatar-xs">';
													echo '</a>';
													echo '</div>';
													echo '</td>';

													// Excerpt.
													$excerpt = wp_strip_all_tags( $article->post_content );
													echo '<td title="' . esc_html( wp_trim_words( $excerpt, 50, '...' ) ) . '">';
													echo esc_html( wp_trim_words( $excerpt, 10, '...' ) );
													echo '</td>';

													// Last Updated with friendly date.
													$exact_date = get_the_modified_date( 'Y-m-d', $article->ID );
													$relative_date = Decker_Kb::get_relative_time( $article->ID );
													echo '<td title="' . esc_attr( $exact_date ) . '">' . esc_html( $relative_date ) . '</td>';

													// Actions.
													echo '<td class="text-end">';
													// View button.
													echo '<button type="button" class="btn btn-sm btn-secondary me-2 view-article-btn" ' .
														'data-id="' . esc_attr( $article_data['id'] ) . '" ' .
														'data-title="' . esc_attr( $article_data['title'] ) . '" ' .
														'data-content="' . esc_attr( $article_data['content'] ) . '" ' .
														'data-labels=\'' . esc_attr( $article_data_json['labels'] ) . '\' ' .
														'data-board=\'' . esc_attr( $article_data_json['board'] ) . '\'>' .
														'<i class="ri-eye-line"></i></button>';
													// Edit button.
													echo '<a href="#" class="btn btn-sm btn-info me-2" data-bs-toggle="modal" data-bs-target="#kb-modal" data-article-id="' . esc_attr( $article->ID ) . '"><i class="ri-pencil-line"></i></a>';
													// Delete button.
													echo '<button type="button" class="btn btn-danger btn-sm" onclick="deleteArticle(' . esc_attr( $article->ID ) . ', \'' . esc_js( $article->post_title ) . '\')">';
													echo '<i class="ri-delete-bin-line"></i>';
													echo '</button>';
													echo '</td>';

													// Hidden content column for search.
													echo '<td class="d-none">' . esc_html( wp_strip_all_tags( $article->post_content ) ) . '</td>';

												}
												?>
												</tbody>
											</table>

										</div>
									</div>
								</div>
							</div> <!-- end row-->

						</div>
					</div>

				</div>

			</div>

			<?php include 'layouts/footer.php'; ?>

		</div>

	</div>

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php
	include 'layouts/kb-modal.php';
	include 'layouts/kb-view-modal.php';
	?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
	// Get URL parameters
	function getUrlParameter(name) {
		name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
		var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
		var results = regex.exec(location.search);
		return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
	}
	
	jQuery(document).ready(function () {
		// Handle article view links and buttons
		jQuery(document).on('click', '.view-article-link, .view-article-btn', function(e) {
			e.preventDefault();
			const $this = jQuery(this);
			const id = $this.data('id');
			const title = $this.data('title');
			const content = $this.data('content');
			const labelsJson = $this.data('labels');
			const boardJson = $this.data('board');
			
			viewArticle(id, title, content, labelsJson, boardJson);
		});
		// Determine the index of the hidden content column based on view.
		const isViewAll = <?php echo 'all' === $view ? 'true' : 'false'; ?>;
		const hiddenContentColumnIndex = isViewAll ? 7 : 6; // 7 if view=all (extra board column), 6 otherwise.
		
		jQuery('#tablaKB').DataTable({
			language: { 
				url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json',
				searchBuilder: {
					title: 'Búsqueda Avanzada'
				}
			},
			pageLength: 50,
			responsive: true,
			ordering: false,
			order: [[4, 'desc']],
			columnDefs: [
				{
					targets: [hiddenContentColumnIndex], // Dynamic hidden content column index.
					visible: false,
					searchable: true
				}
			],
			mark: true, // Enable mark.js.
			search: {
				smart: true,
				regex: false,
				caseInsensitive: true
			},
			initComplete: function() {
				// Add custom search placeholder.
				jQuery('.dataTables_filter input')
					.attr('placeholder', '<?php esc_html_e( 'Search in title, tags, excerpt and content...', 'decker' ); ?>')
					.css('width', '300px');
			}
		});

		jQuery('#categoryFilter').on('change', function () {
			jQuery('#tablaKB').DataTable().column(1).search(this.value).draw();
		});
	});

	// Function to delete an event
	function deleteEvent(id, title) {
		if (confirm(strings.confirm_delete_event + ' "' + title + '"')) {
			fetch(wpApiSettings.root + wpApiSettings.versionString + 'decker_event/' + id, {
				method: 'DELETE',
				headers: {
					'X-WP-Nonce': wpApiSettings.nonce,
					'Content-Type': 'application/json'
				}
			})
			.then(response => response.json())
			.then(() => {
				location.reload();
			})
			.catch(error => {
				console.error('Error:', error);
				alert(strings.error_deleting_event);
			});
		}
	}

	function deleteArticle(id, title) {
		Swal.fire({
			title: '<?php esc_html_e( 'Are you sure?', 'decker' ); ?>',
			text: '<?php esc_html_e( 'The article', 'decker' ); ?> "' + title + '" <?php esc_html_e( 'will be deleted', 'decker' ); ?>',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: '<?php esc_html_e( 'Yes, delete', 'decker' ); ?>',
			cancelButtonText: '<?php esc_html_e( 'Cancel', 'decker' ); ?>',
			confirmButtonColor: '#d33',
			cancelButtonColor: '#3085d6'
		}).then((result) => {
			if (result.isConfirmed) {
				fetch(wpApiSettings.root + wpApiSettings.versionString + 'decker_kb/' + id, {
					method: 'DELETE',
					headers: {
						'X-WP-Nonce': wpApiSettings.nonce,
						'Content-Type': 'application/json'
					}
				})
				.then(response => {
					if (!response.ok) {
						throw new Error('Error en la eliminación');
					}
					return response.json();
				})
				.then(() => {
					Swal.fire(
						'<?php esc_html_e( 'Deleted', 'decker' ); ?>', 
						'<?php esc_html_e( 'The article has been successfully deleted.', 'decker' ); ?>', 
						'success'
					).then(() => location.reload());
				})
				.catch(error => {
					console.error('Error:', error);
					Swal.fire(
						'<?php esc_html_e( 'Error', 'decker' ); ?>', 
						'<?php esc_html_e( 'Could not delete the article.', 'decker' ); ?>', 
						'error'
					);
				});
			}
		});
	}

	</script>

</body>
</html>
