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

$kb_data = Decker_Kb::get_articles();

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
								$page_title     = __( 'Knowledge Base', 'decker' );
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
									<select id="categoryFilter" class="form-select">
										<option value=""><?php esc_html_e( 'All Categories', 'decker' ); ?></option>
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
														<th width="35%"><?php esc_html_e( 'Title', 'decker' ); ?></th>
														<th width="25%"><?php esc_html_e( 'Tags', 'decker' ); ?></th>
														<th width="10%"><?php esc_html_e( 'Author', 'decker' ); ?></th>
														<th width="20%"><?php esc_html_e( 'Excerpt', 'decker' ); ?></th>
														<th width="10%"><?php esc_html_e( 'Last Updated', 'decker' ); ?></th>
														<th width="10%" class="text-end"><?php esc_html_e( 'Actions', 'decker' ); ?></th>
														<th class="d-none"><?php esc_html_e( 'Content', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php

												foreach ( $kb_data as $article ) {
													echo '<tr>';

													// Article Title with hierarchy.
													echo '<td>';

													// Ensure 'level' meta exists and is an integer.
													$level = get_post_meta( $article->ID, 'level', true );
													if ( empty( $level ) || ! is_numeric( $level ) ) {
														$level = 0;
													}

													// Sanitize and output the article title with hierarchy.
													echo esc_html( str_repeat( '— ', intval( $level ) ) ) .
														'<a href="' . esc_url( get_edit_post_link( $article->ID ) ) . '">' . esc_html( $article->post_title ) . '</a>';

													echo '</td>';

													// Article Category.
													echo '<td>';
													$categories = wp_get_post_terms( $article->ID, 'decker_label' );
													if ( ! empty( $categories ) ) {
														foreach ( $categories as $category ) {
															echo '<span class="badge bg-info">' . esc_html( $category->name ) . '</span> ';
														}
													} else {
														echo '<span class="text-muted">' . esc_html__( 'Uncategorized', 'decker' ) . '</span>';
													}
													echo '</td>';

													// Author with avatar
													echo '<td>';
													echo '<div class="avatar-group">';
													echo '<a href="javascript: void(0);" class="avatar-group-item" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '" data-bs-original-title="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '">';
													echo '<span class="d-none">' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '</span>';
													echo '<img src="' . esc_url( get_avatar_url( $article->post_author ) ) . '" alt="' . esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ) . '" class="rounded-circle avatar-xs">';
													echo '</a>';
													echo '</div>';
													echo '</td>';

													// Excerpt
													echo '<td>';
													$excerpt = wp_strip_all_tags($article->post_content);
													echo esc_html(wp_trim_words($excerpt, 10, '...'));
													echo '</td>';

													// Last Updated.
													echo '<td>' . esc_html( get_the_modified_date( 'Y-m-d', $article->ID ) ) . '</td>';

													// Actions.
													echo '<td class="text-end">';
													echo '<a href="#" class="btn btn-sm btn-info me-2" data-bs-toggle="modal" data-bs-target="#kb-modal" data-article-id="' . esc_attr( $article->ID ) . '"><i class="ri-pencil-line"></i></a>';
													echo '<button type="button" class="btn btn-danger btn-sm" onclick="deleteArticle(' . esc_attr( $article->ID ) . ', \'' . esc_js( $article->post_title ) . '\')">';
													echo '<i class="ri-delete-bin-line"></i>';
													echo '</button>';
													echo '</td>';

													// Hidden content column for search
													echo '<td class="d-none">' . esc_html(wp_strip_all_tags($article->post_content)) . '</td>';

													// Last Updated.
													echo '<td>' . esc_html( get_the_modified_date( 'Y-m-d', $article->ID ) ) . '</td>';

													// Hidden content column for search
													echo '<td class="d-none">' . esc_html(wp_strip_all_tags($article->post_content)) . '</td>';

													// Actions.
													echo '<td class="text-end">';
													echo '<a href="#" class="btn btn-sm btn-info me-2" data-bs-toggle="modal" data-bs-target="#kb-modal" data-article-id="' . esc_attr( $article->ID ) . '"><i class="ri-pencil-line"></i></a>';
													echo '<button type="button" class="btn btn-danger btn-sm" onclick="deleteArticle(' . esc_attr( $article->ID ) . ', \'' . esc_js( $article->post_title ) . '\')">';
													echo '<i class="ri-delete-bin-line"></i>';
													echo '</button>';
													echo '</td>';

													echo '</tr>';
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
	<?php include 'layouts/kb-modal.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
	jQuery(document).ready(function () {
		jQuery('#tablaKB').DataTable({
			language: { 
				url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json',
				searchBuilder: {
					title: 'Búsqueda Avanzada'
				}
			},
			pageLength: 50,
			responsive: true,
			order: [[4, 'desc']],
			columnDefs: [
				{
					targets: [6], // Hidden content column
					visible: false,
					searchable: true
				}
			],
			mark: true, // Enable mark.js
			search: {
				smart: true,
				regex: false,
				caseInsensitive: true
			},
			initComplete: function() {
				// Add custom search placeholder
				jQuery('.dataTables_filter input')
					.attr('placeholder', '<?php esc_html_e('Search in title, tags, excerpt and content...', 'decker'); ?>')
					.css('width', '300px');
			}
		});

		jQuery('#categoryFilter').on('change', function () {
			jQuery('#tablaKB').DataTable().column(1).search(this.value).draw();
		});
	});

	function deleteArticle(id, title) {
		Swal.fire({
			title: '¿Estás seguro?',
			text: 'Se eliminará el artículo "' + title + '"',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Sí, eliminar',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#d33',
			cancelButtonColor: '#3085d6'
		}).then((result) => {
			if (result.isConfirmed) {
				window.location.href = '?action=delete&id=' + id;
			}
		});
	}
	</script>

</body>
</html>
