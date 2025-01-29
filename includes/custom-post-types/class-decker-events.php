<?php
/**
 * The file that defines the events custom post type
 *
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes/custom-post-types
 */

/**
 * Class to handle the events custom post type
 */
class Decker_Events {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Display the users meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_users_meta_box( $post ) {
		$users = get_users( array( 'orderby' => 'display_name' ) );
		$assigned_users = get_post_meta( $post->ID, 'event_assigned_users', true );
		?>
		<div id="assigned-users" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $users as $user ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="event_assigned_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Hide visibility options for decker_event post type.
	 */
	public function hide_visibility_options() {
		global $post_type;
		if ( 'decker_event' == $post_type ) {
			echo '<style type="text/css">
				.misc-pub-section.misc-pub-visibility {
					display: none;
				}
			</style>';
		}
	}

	/**
	 * Define the hooks for the events custom post type
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 10, 3 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_decker_event', array( $this, 'save_event_meta' ) );

		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 10, 2 );

		// Hide visibility options
		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );
	}

	/**
	 * Register the custom post type
	 */
	public function register_post_type() {

		$labels = array(
			'name'               => _x( 'Events', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Event', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Events', 'admin menu', 'decker' ),
			'name_admin_bar'     => _x( 'Event', 'add new on admin bar', 'decker' ),
			'add_new'            => _x( 'Add New', 'event', 'decker' ),
			'add_new_item'       => __( 'Add New Event', 'decker' ),
			'new_item'           => __( 'New Event', 'decker' ),
			'edit_item'          => __( 'Edit Event', 'decker' ),
			'view_item'          => __( 'View Event', 'decker' ),
			'all_items'          => __( 'Events', 'decker' ),
			'search_items'       => __( 'Search Events', 'decker' ),
			'not_found'          => __( 'No events found.', 'decker' ),
			'not_found_in_trash' => __( 'No events found in Trash.', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'           => true,
			'show_in_menu'      => 'edit.php?post_type=decker_task',
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'events' ),
			'capability_type'   => 'post',
			'map_meta_cap'      => true,
			'has_archive'       => true,
			'hierarchical'      => false,
			'menu_position'     => null,
			'supports'          => array( 'title', 'editor', 'author', 'custom-fields' ),
			'show_in_rest'      => true,
		);

		register_post_type( 'decker_event', $args );

		$this->register_post_meta();
	}

	/**
	 * Register the custom post type meta fields
	 */
	public function register_post_meta() {
		$meta_fields = array(
			'event_allday' => array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'schema' => array(
					'type' => 'boolean',
				),
			),
			'event_start' => array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_event_date' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_end' => array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_event_date' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_location' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event_url' => array(
				'type' => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'event_category' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event_assigned_users' => array(
				'type' => 'array',
				'single' => true,
				'show_in_rest' => array(
					'schema' => array(
						'type' => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => function ( $users ) {
					if ( is_string( $users ) ) {
						$users = explode( ',', $users );
					}
					return array_map( 'absint', (array) $users );
				},
			),
		);

		foreach ( $meta_fields as $key => $args ) {
			$default_args = array(
				'single' => true,
				'show_in_rest' => true,
			);

			register_post_meta(
				'decker_event',
				$key,
				array_merge( $default_args, $args )
			);
		}
	}

	/**
	 * Sanitiza las fechas según el tipo de evento
	 */
	public function sanitize_event_date( $value ) {
		$allday = isset( $_POST['event_allday'] ) ? rest_sanitize_boolean( $_POST['event_allday'] ) : false;

		if ( $allday ) {
			return sanitize_text_field( substr( $value, 0, 10 ) ); // Solo fecha
		}

		return sanitize_text_field( $value ); // Fecha y hora
	}

	/**
	 * Forces classic editor
	 */
	public function force_classic_editor( $use_block_editor, $post_type ) {
		if ( $post_type === 'decker_event' ) {
			return false; // Deactivate gutenberg editor.
		}
		return $use_block_editor;
	}



	/**
	 * Restringe el acceso a los endpoints de 'decker_event' solo a editores y superiores.
	 */
	public function restrict_rest_access( $result, $rest_server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wp/v2/decker_event' ) === 0 ) {
			// Usa la capacidad específica del CPT
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'No tienes permisos para acceder a este recurso.', 'decker' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}


	/**
	 * Add meta boxes for event details
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'decker_event_details',
			__( 'Event Details', 'decker' ),
			array( $this, 'render_event_details_meta_box' ),
			'decker_event',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_users_meta_box',
			__( 'Assigned Users', 'decker' ),
			array( $this, 'display_users_meta_box' ),
			'decker_event',
			'side',
			'default'
		);
	}

	/**
	 * Render the event details meta box
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_event_details_meta_box( $post ) {
		wp_nonce_field( 'decker_event_meta_box', 'decker_event_meta_box_nonce' );

		$allday = get_post_meta( $post->ID, 'event_allday', true );
		$start_date = get_post_meta( $post->ID, 'event_start', true );
		$end_date = get_post_meta( $post->ID, 'event_end', true );
		$location = get_post_meta( $post->ID, 'event_location', true );
		$url = get_post_meta( $post->ID, 'event_url', true );
		$category = get_post_meta( $post->ID, 'event_category', true );
		$assigned_users = get_post_meta( $post->ID, 'event_assigned_users', true );

		?>
			<p>
	<label>
		<input type="checkbox" name="event_allday" id="event_allday" <?php checked( $allday, '1' ); ?>>
		<?php esc_html_e( 'All Day Event', 'decker' ); ?>
	</label>
</p>

<!-- Contenedor para mensajes de error -->
<div id="event_date_error" style="color: red; display: none;">
		<?php esc_html_e( 'End Date must be after Start Date.', 'decker' ); ?>
</div>

<!-- Script para manejar la visibilidad de campos y validación -->
<script>
	(function($) {
		$(document).ready(function() {
			function toggleTimeFields() {
				const isAllDay = $('#event_allday').is(':checked');
				if (isAllDay) {
					// Ocultar campos de hora
					$('#event_start_time_container, #event_end_time_container').hide();
				} else {
					// Mostrar campos de hora
					$('#event_start_time_container, #event_end_time_container').show();
				}
				validateDates(); // Validar fechas cada vez que se cambia el estado
			}
			
			function validateDates() {
				const isAllDay = $('#event_allday').is(':checked');
				let startDate = $('#event_start_date').val();
				let endDate = $('#event_end_date').val();
				
				if (!isAllDay) {
					const startTime = $('#event_start_time').val() || '00:00';
					const endTime = $('#event_end_time').val() || '00:00';
					startDate = `${startDate}T${startTime}`;
					endDate = `${endDate}T${endTime}`;
				} else {
					startDate = `${startDate}T00:00`;
					endDate = `${endDate}T00:00`;
				}
				
				const start = new Date(startDate);
				const end = new Date(endDate);
				
				if (end <= start) {
					$('#event_date_error').show();
					return false;
				} else {
					$('#event_date_error').hide();
					return true;
				}
			}
			
			// Añadir manejadores de eventos
			$('#event_allday').on('change', toggleTimeFields);
			$('#event_start_date, #event_start_time, #event_end_date, #event_end_time').on('change', validateDates);
			
			// Validar al cargar
			toggleTimeFields();
			
			// Validar antes de guardar
			$('#post').on('submit', function(e) {
				if (!validateDates()) {
					e.preventDefault();
					alert('<?php esc_html_e( 'Please ensure that the End Date is after the Start Date.', 'decker' ); ?>');
				}
			});
		});
	})(jQuery);
</script>

<p>
	<label for="event_start_date"><?php esc_html_e( 'Start Date:', 'decker' ); ?></label><br>
	<input type="date" id="event_start_date" name="event_start_date" 
		   value="<?php echo esc_attr( substr( $start_date, 0, 10 ) ); ?>" class="">
</p>
<p id="event_start_time_container">
	<label for="event_start_time"><?php esc_html_e( 'Start Time:', 'decker' ); ?></label><br>
	<input type="time" id="event_start_time" name="event_start_time" 
		   value="<?php echo esc_attr( $allday ? '' : substr( $start_date, 11, 5 ) ); ?>" class="">
</p>
<p>
	<label for="event_end_date"><?php esc_html_e( 'End Date:', 'decker' ); ?></label><br>
	<input type="date" id="event_end_date" name="event_end_date" 
		   value="<?php echo esc_attr( substr( $end_date, 0, 10 ) ); ?>" class="">
</p>
<p id="event_end_time_container">
	<label for="event_end_time"><?php esc_html_e( 'End Time:', 'decker' ); ?></label><br>
	<input type="time" id="event_end_time" name="event_end_time" 
		   value="<?php echo esc_attr( $allday ? '' : substr( $end_date, 11, 5 ) ); ?>" class="">
</p>
		<p>
			<label for="event_location"><?php esc_html_e( 'Location:', 'decker' ); ?></label><br>
			<input type="text" id="event_location" name="event_location" 
				value="<?php echo esc_attr( $location ); ?>" class="widefat">
		</p>
		<p>
			<label for="event_url"><?php esc_html_e( 'URL:', 'decker' ); ?></label><br>
			<input type="url" id="event_url" name="event_url" 
				value="<?php echo esc_attr( $url ); ?>" class="widefat">
		</p>
		<p>
			<label for="event_category"><?php esc_html_e( 'Category:', 'decker' ); ?></label><br>
			<select id="event_category" name="event_category">
				<option value="bg-danger" <?php selected( $category, 'bg-danger' ); ?>><?php esc_html_e( 'Danger', 'decker' ); ?></option>
				<option value="bg-success" <?php selected( $category, 'bg-success' ); ?>><?php esc_html_e( 'Success', 'decker' ); ?></option>
				<option value="bg-primary" <?php selected( $category, 'bg-primary' ); ?>><?php esc_html_e( 'Primary', 'decker' ); ?></option>
				<option value="bg-info" <?php selected( $category, 'bg-info' ); ?>><?php esc_html_e( 'Info', 'decker' ); ?></option>
				<option value="bg-dark" <?php selected( $category, 'bg-dark' ); ?>><?php esc_html_e( 'Dark', 'decker' ); ?></option>
				<option value="bg-warning" <?php selected( $category, 'bg-warning' ); ?>><?php esc_html_e( 'Warning', 'decker' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Save event meta data
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_event_meta( $post_id ) {
		// Skip capability check in test environment
		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			if ( ! isset( $_POST['decker_event_meta_box_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_key( $_POST['decker_event_meta_box_nonce'] ), 'decker_event_meta_box' ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! current_user_can( 'edit_decker_event', $post_id ) ) {
				return;
			}
		}

		// Save all-day event status.
		$allday = isset( $_POST['event_allday'] ) ? 1 : 0;
		update_post_meta( $post_id, 'event_allday', $allday );

		// Special processing for dates.
		// Handle date formatting.
		if ( $allday ) {
			$start = isset( $_POST['event_start_date'] ) ? $this->format_event_date( $_POST['event_start_date'], true ) : '';
			$end = isset( $_POST['event_end_date'] ) ? $this->format_event_date( $_POST['event_end_date'], true ) : '';
		} else {
			$start_date = isset( $_POST['event_start_date'] ) ? sanitize_text_field( $_POST['event_start_date'] ) : '';
			$start_time = isset( $_POST['event_start_time'] ) ? sanitize_text_field( $_POST['event_start_time'] ) : '00:00';
			$end_date = isset( $_POST['event_end_date'] ) ? sanitize_text_field( $_POST['event_end_date'] ) : '';
			$end_time = isset( $_POST['event_end_time'] ) ? sanitize_text_field( $_POST['event_end_time'] ) : '00:00';
			$start = $this->format_event_date( "$start_date $start_time", false );
			$end = $this->format_event_date( "$end_date $end_time", false );
		}

		// Validar que end date es mayor que start date
		if ( strtotime( $end ) <= strtotime( $start ) ) {
			// You can either add an error or simply not save the dates.
			// For simplicity, we'll just skip saving invalid dates.
			// You could also add an error message using admin_notices.
			return;
		}

		update_post_meta( $post_id, 'event_start', $start );
		update_post_meta( $post_id, 'event_end', $end );

		// if ( isset( $_POST['event_start'] ) ) {
		// $start = isset($_POST['event_start']) ? $this->format_event_date($_POST['event_start'], $allday) : '';
		// update_post_meta($post_id, 'event_start', $start);
		// }

		// if ( isset( $_POST['event_end'] ) ) {
		// $end = isset($_POST['event_end']) ? $this->format_event_date($_POST['event_end'], $allday) : '';
		// update_post_meta($post_id, 'event_end', $end);
		// }

		// Save location.
		if ( isset( $_POST['event_location'] ) ) {
			update_post_meta( $post_id, 'event_location', sanitize_text_field( wp_unslash( $_POST['event_location'] ) ) );
		}

		// Save URL.
		if ( isset( $_POST['event_url'] ) ) {
			update_post_meta( $post_id, 'event_url', esc_url_raw( wp_unslash( $_POST['event_url'] ) ) );
		}

		// Save category.
		if ( isset( $_POST['event_category'] ) ) {
			update_post_meta( $post_id, 'event_category', sanitize_text_field( wp_unslash( $_POST['event_category'] ) ) );
		}

		// Save assigned users
		$assigned_users = array();
		if ( isset( $_POST['event_assigned_users'] ) ) {
			$users_data = wp_unslash( $_POST['event_assigned_users'] );
			if ( is_array( $users_data ) ) {
				$assigned_users = array_map( 'absint', $users_data );
			} elseif ( is_string( $users_data ) ) {
				$users_array = explode( ',', $users_data );
				$assigned_users = array_map( 'absint', $users_array );
			}
		}
		update_post_meta( $post_id, 'event_assigned_users', array_filter( $assigned_users ) );
	}

	/**
	 * Formatea la fecha según el tipo de evento
	 */
	private function format_event_date( $date, $allday ) {
		if ( $allday ) {
			return date( 'Y-m-d', strtotime( $date ) ) . 'T00:00:00';
		}
		return date( 'Y-m-d\TH:i:s', strtotime( $date ) );
	}

	/**
	 * Get all events
	 *
	 * @param array $args Optional. Additional arguments for WP_Query.
	 * @return array Array of Event objects.
	 */
	public static function get_events( $args = array() ) {
		$default_args = array(
			'post_type'      => 'decker_event',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'cache_results'  => true, // Enable post caching.
		);

		$args = wp_parse_args( $args, $default_args );
		$posts = get_posts( $args );

		// After getting posts, load all metadata into cache at once.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		update_meta_cache( 'post', $post_ids ); // 1 consulta extra para todos los metadatos

		// Avoid modifying native WP_Post objects.
		$events = array();
		foreach ( $posts as $post ) {
			$events[] = array(
				'post' => $post,
				'meta' => get_post_meta( $post->ID ), // get_post_meta() will use cache and avoid additional queries.

			);
		}

		return $events;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Events' ) ) {
	new Decker_Events();
}
