<?php
/**
 * File app-event-manager
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

$events_data = Decker_Events::get_events();

?>

<head>
	<title><?php esc_html_e( 'Events', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>
	<?php include 'layouts/head-css.php'; ?>
</head>

<body <?php body_class(); ?>>
	<!-- Begin page -->
	<div class="wrapper">
		<?php include 'layouts/menu.php'; ?>
		<div class="content-page">
			<div class="content">
				<!-- Start Content-->
				<div class="container-fluid">
					<div class="row">
						<div class="col-xxl-12">
							<div class="page-title-box d-flex align-items-center justify-content-between">
								<h4 class="page-title">
									<?php esc_html_e( 'Events', 'decker' ); ?>
									<a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#event-modal" data-event-id="0">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Event', 'decker' ); ?>
									</a>
								</h4>
								<div class="page-title-right">
									<div class="input-group mb-3">
										<span class="input-group-text bg-white border-end-0">
											<i class="ri-search-line"></i>
										</span>
										<input id="searchInput" type="search" class="form-control border-start-0" 
											placeholder="<?php esc_attr_e( 'Search...', 'decker' ); ?>" 
											aria-label="<?php esc_attr_e( 'Search', 'decker' ); ?>">
									</div>
								</div>
							</div>

							<?php include 'layouts/top-alert.php'; ?>

							<div class="row">
								<div class="col-12">
									<div class="card">
										<div class="card-body table-responsive">
											<table id="eventsTable" class="table table-striped table-bordered dataTable no-footer dt-responsive nowrap w-100">
												<thead>
													<tr>
														<th data-sort-default><?php esc_html_e( 'Title', 'decker' ); ?></th>
														<th><?php esc_html_e( 'All Day', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Start', 'decker' ); ?></th>
														<th><?php esc_html_e( 'End', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Category', 'decker' ); ?></th>
														<th data-sort-method='none'><?php esc_html_e( 'Actions', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>

<?php 	
foreach ( $events_data as $event_data ) : 
	$post = $event_data['post'];
	$meta = $event_data['meta'];

    // Asegurarse de que el objeto $post sea válido
    if ( ! $post instanceof WP_Post ) {
        continue;
    }

	// Extraer metadatos con valores predeterminados
	$event_id = $post->ID;
	$title = $post->post_title;
	$allday = isset( $meta['event_allday'] ) ? $meta['event_allday'][0] : false;
	$start_date = isset( $meta['event_start'] ) ? $meta['event_start'][0] : '';
	$end_date = isset( $meta['event_end'] ) ? $meta['event_end'][0] : '';
	$category = isset( $meta['event_category'] ) ? $meta['event_category'][0] : '';


	// Formatear fechas
	$start_formatted = '';
	$end_formatted = '';


	if (!empty($start_date)) {
	    try {
	        $start_datetime = new DateTime($start_date);
	        $start_formatted = $allday ? $start_datetime->format('Y-m-d') : $start_datetime->format('Y-m-d H:i');
	    } catch (Exception $e) {
	        error_log('Error al analizar la fecha de inicio para el evento ID ' . $event_id . ': ' . $e->getMessage());
	        $start_formatted = 'Fecha inválida'; // Opcional: Mensaje amigable
	    }
	}

	if (!empty($end_date)) {
	    try {
	        $end_datetime = new DateTime($end_date);
	        $end_formatted = $allday ? $end_datetime->format('Y-m-d') : $end_datetime->format('Y-m-d H:i');
	    } catch (Exception $e) {
	        error_log('Error al analizar la fecha de fin para el evento ID ' . $event_id . ': ' . $e->getMessage());
	        $end_formatted = 'Fecha inválida'; // Opcional: Mensaje amigable
	    }
	}

?>

<tr>
	<td class="event-title">
	    <?php echo esc_html( $title ); ?>
	</td>
	<td class="event-allday">
	    <?php echo esc_html( $allday ); ?>
	</td>
	<td class="event-start">
	    <?php echo esc_html( $start_formatted ); ?>
	</td>
	<td class="event-end">
	    <?php echo esc_html( $end_formatted ); ?>
	</td>
	<td class="event-category">
	    <?php if ( ! empty( $category ) ) : ?>
	        <span class="badge <?php echo esc_attr( $category ); ?>">
	            <?php echo esc_html( str_replace( 'bg-', '', $category ) ); ?>
	        </span>
	    <?php else : ?>
	        <?php esc_html_e( 'Uncategorized', 'decker' ); ?>
	    <?php endif; ?>
	</td>
	<td>
	    <a href="#" class="btn btn-sm btn-info me-2 edit-event" data-bs-toggle="modal" data-bs-target="#event-modal"
	       data-event-id="<?php echo esc_attr( $event_id ); ?>">
	        <i class="ri-pencil-line"></i>
	    </a>
	    <button type="button" class="btn btn-sm btn-danger" 
	       onclick="window.deleteEvent(<?php echo esc_attr( $event_id ); ?>, '<?php echo esc_js( $title ); ?>')">
	        <i class="ri-delete-bin-line"></i>
	    </button>
	</td>
</tr>
<?php endforeach; ?>

													
												</tbody>
											</table>
										</div>
									</div>
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
	<?php
	include 'layouts/footer-scripts.php';
	wp_localize_script(
		'jquery',
		'wpApiSettings',
		array(
			'root' => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
	?>

	<?php 
	// Include event modal template
	include plugin_dir_path( __FILE__ ) . 'layouts/event-modal.php';
	?>
</body>
</html>
