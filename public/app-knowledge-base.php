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
$is_all_view = ( 'all' === $view );

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
								   id="add-root-article-btn"
								   class="btn btn-success btn-sm ms-3 <?php echo esc_attr( $class_disabled ); ?>"
								   data-bs-toggle="modal" data-bs-target="#kb-modal" data-parent-id="0">
									<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Article', 'decker' ); ?>
								</a>
								</h4>

                                                               <div class="d-flex align-items-center">
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

												<table id="tableKB" class="table table-striped table-bordered dt-responsive nowrap w-100" style="display:none;">
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
													echo '<td title="' . esc_html( wp_trim_words( $excerpt, 100, '...' ) ) . '">';
													echo '<div class="kb-excerpt">' . esc_html( wp_trim_words( $excerpt, 30, '...' ) );
													echo '</div>';
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

											<!-- New KB Tree UI -->
											<div class="mb-3 mt-2">
												<input type="text" id="searchInput" class="form-control" placeholder="<?php esc_attr_e( 'Search articles...', 'decker' ); ?>">
											</div>
											<?php
											$roots = array_filter(
												$kb_data,
												function ( $p ) {
													return isset( $p->depth ) && 0 === intval( $p->depth );
												}
											);
											if ( ! function_exists( 'decker_render_kb_node' ) ) {
												/**
												 * Render a KB article node with recursive children.
												 *
												 * Outputs a list item with actions, optional board badge (in view=all),
												 * and a collapsible list for children.
												 *
												 * @param WP_Post $article            The article post object.
												 * @param bool    $is_all_view_flag   Whether the current rendering is for the "view=all" mode.
												 *
												 * @return void
												 */
												function decker_render_kb_node( $article, $is_all_view_flag = false ) {
													$has_children = isset( $article->children ) && ! empty( $article->children );
													$labels       = wp_get_post_terms( $article->ID, 'decker_label' );
													$labels_data  = array();
													foreach ( $labels as $label ) {
														$color          = get_term_meta( $label->term_id, 'term-color', true );
														$labels_data[]  = array(
															'name' => $label->name,
															'color' => $color,
														);
													}
													$board_terms  = wp_get_post_terms( $article->ID, 'decker_board' );
													$board_name   = '';
													$board_color  = '';
													$board_id     = 0;
													if ( ! empty( $board_terms ) ) {
														$board       = $board_terms[0];
														$board_name  = $board->name;
														$board_color = get_term_meta( $board->term_id, 'term-color', true );
														$board_id    = (int) $board->term_id;
													}
													$article_data_json = array(
														'id'      => $article->ID,
														'title'   => $article->post_title,
														'content' => $article->post_content,
														'labels'  => wp_json_encode( $labels_data ),
														'board'   => wp_json_encode(
															array(
																'name' => $board_name,
																'color' => $board_color,
															)
														),
													);
													?>
											<li class="kb-item list-group-item" data-article-id="<?php echo esc_attr( $article->ID ); ?>" data-parent-id="<?php echo esc_attr( $article->post_parent ); ?>" data-menu-order="<?php echo esc_attr( $article->menu_order ); ?>" data-board-id="<?php echo esc_attr( $board_id ); ?>">
														<div class="d-flex align-items-center justify-content-between">
											<div class="d-flex align-items-center gap-2">
													<?php
														// Compute swatch vars once.
													$swatch_color = $board_color ? $board_color : '#6c757d';
													$swatch_title = $board_name ? $board_name : __( 'No Board', 'decker' );
													?>
                                                                                                        <?php if ( $has_children ) : ?>
													<button class="btn btn-sm btn-outline-secondary kb-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#children-of-<?php echo esc_attr( $article->ID ); ?>" aria-expanded="false" aria-controls="children-of-<?php echo esc_attr( $article->ID ); ?>" title="<?php echo $is_all_view_flag ? esc_attr( $swatch_title ) : ''; ?>">
														<i class="ri-arrow-right-s-line"></i>
													</button>
												<?php else : ?>
													<span class="btn btn-sm btn-outline-light disabled" aria-hidden="true" title="<?php echo $is_all_view_flag ? esc_attr( $swatch_title ) : ''; ?>">
														<i class="ri-arrow-right-s-line"></i>
													</span>
												<?php endif; ?>
												<span class="kb-title-text">
													<a href="#" class="view-article-link"
														data-id="<?php echo esc_attr( $article->ID ); ?>"
														data-title="<?php echo esc_attr( $article->post_title ); ?>"
														data-content="<?php echo esc_attr( $article->post_content ); ?>"
														data-labels='<?php echo esc_attr( $article_data_json['labels'] ); ?>'
														data-board='<?php echo esc_attr( $article_data_json['board'] ); ?>'><?php echo esc_html( $article->post_title ); ?></a>
												</span>
												<span class="kb-hidden-content d-none"><?php echo esc_html( wp_strip_all_tags( $article->post_content ) ); ?></span>
															</div>
												<div class="d-flex align-items-center gap-2">
													<?php if ( ! empty( $labels_data ) ) : ?>
														<?php
														$labels_count   = count( $labels_data );
														$display_labels = array_slice( $labels_data, 0, 3 );
														$extra_labels   = max( 0, $labels_count - 3 );
														?>
														<div class="kb-labels d-flex align-items-center flex-wrap" style="gap:4px;">
															<?php foreach ( $display_labels as $ld ) : ?>
																<span class="badge" style="background-color: <?php echo esc_attr( $ld['color'] ); ?>;">
																	<?php echo esc_html( $ld['name'] ); ?>
																</span>
															<?php endforeach; ?>
															<?php if ( $extra_labels > 0 ) : ?>
																<?php $popover_id = 'kb-popover-' . $article->ID; ?>
																<span class="badge bg-secondary"
																	  role="button"
																	  tabindex="0"
																	  data-bs-toggle="popover"
																	  data-popover-target="#<?php echo esc_attr( $popover_id ); ?>"
																	  title="<?php echo esc_attr__( 'Labels', 'decker' ); ?>"
																>+<?php echo esc_html( $extra_labels ); ?></span>
																<div id="<?php echo esc_attr( $popover_id ); ?>" class="d-none">
																	<div class="d-flex align-items-center flex-wrap" style="gap:4px;">
																		<?php foreach ( $labels_data as $ld_full ) : ?>
																			<span class="badge me-1 mb-1" style="background-color: <?php echo esc_attr( $ld_full['color'] ); ?>;"><?php echo esc_html( $ld_full['name'] ); ?></span>
																		<?php endforeach; ?>
																	</div>
																</div>
															<?php endif; ?>
														</div>
													<?php endif; ?>

													<img src="<?php echo esc_url( get_avatar_url( $article->post_author, array( 'size' => 24 ) ) ); ?>" alt="<?php echo esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ); ?>" class="rounded-circle" style="width:24px;height:24px;" title="<?php echo esc_attr( get_the_author_meta( 'display_name', $article->post_author ) ); ?>" />

													<div class="btn-group btn-group-sm">
														<button type="button" class="btn btn-outline-secondary view-article-btn"
														data-id="<?php echo esc_attr( $article->ID ); ?>"
														data-title="<?php echo esc_attr( $article->post_title ); ?>"
														data-content="<?php echo esc_attr( $article->post_content ); ?>"
														data-labels='<?php echo esc_attr( $article_data_json['labels'] ); ?>'
														data-board='<?php echo esc_attr( $article_data_json['board'] ); ?>'>
														<i class="ri-eye-line"></i>
														</button>
														<button type="button" class="btn btn-outline-info kb-edit-btn" data-article-id="<?php echo esc_attr( $article->ID ); ?>">
														<i class="ri-pencil-line"></i>
														</button>
														<button type="button" class="btn btn-outline-success add-child-btn" title="<?php echo esc_attr__( 'Add Child', 'decker' ); ?>" data-parent-id="<?php echo esc_attr( $article->ID ); ?>" data-bs-toggle="modal" data-bs-target="#kb-modal">
														<i class="ri-add-line"></i>
														</button>
														<button type="button" class="btn btn-outline-danger kb-delete-btn" data-article-id="<?php echo esc_attr( $article->ID ); ?>" data-article-title="<?php echo esc_attr( $article->post_title ); ?>">
														<i class="ri-delete-bin-line"></i>
														</button>
													</div>
												</div>
												</div>
												<div class="edit-container mt-2" id="edit-container-<?php echo esc_attr( $article->ID ); ?>" style="display: none;"></div>
												<ul class="list-group list-group-flush collapse kb-children" id="children-of-<?php echo esc_attr( $article->ID ); ?>" data-parent-id="<?php echo esc_attr( $article->ID ); ?>" data-board-id="<?php echo esc_attr( $board_id ); ?>">
															<?php if ( $has_children ) : ?>
																<?php
																foreach ( $article->children as $child ) {
																	decker_render_kb_node( $child, $is_all_view_flag ); }
																?>
													<?php endif; ?>
												</ul>
												</li>
															<?php
												}
											}
											?>
								<?php
								// Expose current board id (if filtering by board) for JS defaulting in inline creation.
								$current_board_id = 0;
								if ( ! empty( $board_slug ) ) {
									$board_term = get_term_by( 'slug', $board_slug, 'decker_board' );
									if ( $board_term ) {
										$current_board_id = (int) $board_term->term_id; }
								}
								?>
								<?php if ( $is_all_view ) : ?>
									<?php
									// Group roots by board term id (0 for none).
									$grouped = array();
									foreach ( $roots as $r ) {
										$bterms = wp_get_post_terms( $r->ID, 'decker_board' );
										$bid = 0;
										$bname = __( 'No Board', 'decker' );
										$bcolor = '#6c757d';
										if ( ! empty( $bterms ) ) {
											$b = $bterms[0];
											$bid = (int) $b->term_id;
											$bname = $b->name;
											$bcolor = get_term_meta( $b->term_id, 'term-color', true );
										}
										if ( ! isset( $grouped[ $bid ] ) ) {
											$grouped[ $bid ] = array(
												'name'   => $bname,
												'color'  => $bcolor,
												'items'  => array(),
											);
										}
										$grouped[ $bid ]['items'][] = $r;
									}
									// Render each board section.
									foreach ( $grouped as $bid => $info ) :
										?>
										<div class="mb-2 mt-3 d-flex align-items-center">
											<span class="badge" style="background-color: <?php echo esc_attr( $info['color'] ); ?>; color:#fff; border:1px solid rgba(0,0,0,.1); min-width: 140px; text-align:center;">
												<?php echo esc_html( $info['name'] ); ?>
											</span>
										</div>
										<ul class="list-group kb-root" data-parent-id="0" data-board-id="<?php echo esc_attr( $bid ); ?>">
											<?php
											foreach ( $info['items'] as $root ) {
												decker_render_kb_node( $root, true ); }
											?>
										</ul>
									<?php endforeach; ?>
								<?php else : ?>
									<ul class="list-group" id="kb-root" data-parent-id="0" data-current-board-id="<?php echo esc_attr( $current_board_id ); ?>">
										<?php
										foreach ( $roots as $root ) {
											decker_render_kb_node( $root, false ); }
										?>
									</ul>
								<?php endif; ?>

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
	<?php include 'layouts/kb-view-modal.php'; ?>
	<?php include 'layouts/kb-modal.php'; ?>
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
			// The table view is deprecated; interactions are now handled in knowledge-base.js
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
						throw new Error('Deletion error');
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
