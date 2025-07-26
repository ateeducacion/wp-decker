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
		$screen = get_current_screen();
		if ( $screen && 'decker_event' === $screen->post_type ) {
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
		add_action( 'save_post_decker_event', array( $this, 'save_event_meta' ), 10, 3 );

		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 10, 2 );

		// Hide visibility options.
		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );

		// Regiter REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'           => true,
			'show_in_menu'      => 'edit.php?post_type=decker_task',
			'query_var'         => true,
			'rewrite'           => false,
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
				// 'sanitize_callback' => array( __CLASS__, 'sanitize_event_allday' ),
				'schema' => array(
					'type' => 'boolean',
				),
			),
			'event_start' => array(
				'type' => 'string',
				// 'sanitize_callback' => 'sanitize_text_field',
				'sanitize_callback' => array( __CLASS__, 'sanitize_event_datetime' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_end' => array(
				'type' => 'string',
				// 'sanitize_callback' => 'sanitize_text_field',
				'sanitize_callback' => array( __CLASS__, 'sanitize_event_datetime' ),
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
	 * Forces the classic editor for decker_event post type.
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type The post type being checked.
	 * @return bool Whether to use the classic editor.
	 */
	public function force_classic_editor( $use_block_editor, $post_type ) {
		if ( 'decker_event' === $post_type ) {
			return false; // Deactivate gutenberg editor.
		}
		return $use_block_editor;
	}

	/**
	 * Register REST API routes for decker_task.
	 */
	public function register_rest_routes() {

		register_rest_route(
			'decker/v1',
			'/events/(?P<id>\d+)/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_decker_event' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Restricts REST API access for decker_event post type.
	 *
	 * @param mixed           $result The pre-calculated result to return.
	 * @param WP_REST_Server  $rest_server The REST server instance.
	 * @param WP_REST_Request $request The current REST request.
	 * @return mixed WP_Error if unauthorized, otherwise the original result.
	 */
	public function restrict_rest_access( $result, $rest_server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wp/v2/decker_event' ) === 0 ) {
			// Usa la capacidad específica del CPT.
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to access this resource.', 'decker' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	/**
	 * Updates meta fields for a 'decker_event' post type via the WordPress REST API.
	 *
	 * This function retrieves the event ID from the request, validates its existence,
	 * sanitizes and updates the provided meta fields, and returns a success or error response.
	 *
	 * @param WP_REST_Request $request The REST API request containing event data.
	 * @return WP_REST_Response JSON response with success or error message.
	 **/
	public function update_decker_event( WP_REST_Request $request ) {
		// Retrieve parameters from request.
		$event_id = $request->get_param( 'id' );

		// Check if the event exists, if not return error response.
		if ( ! get_post( $event_id ) || get_post_type( $event_id ) !== 'decker_event' ) {
			return new WP_REST_Response(
				array(
					'error' => 'Invalid event ID',
				),
				404
			);
		}

		// Define meta fields and sanitization functions.
		$meta_fields = array(
			'event_allday' => 'rest_sanitize_boolean',
			// 'event_start' => 'sanitize_text_field', // Not adding the data here, because is saved out of the foreach.
			// 'event_end' => 'sanitize_text_field', // Not adding the data here, because is saved out of the foreach.
			'event_location' => 'sanitize_text_field',
			'event_url' => 'esc_url_raw',
			'event_category' => 'sanitize_text_field',
			'event_assigned_users' => function ( $users ) {
				return array_map( 'absint', is_string( $users ) ? explode( ',', $users ) : (array) $users );
			},
		);

		$is_all_day = $request->get_param( 'event_allday' );
		if ( $is_all_day ) {
			// Procesamiento para todo el día.
			$date = sanitize_text_field( $request->get_param( 'event_start' ) );

			update_post_meta( $event_id, 'event_start', $date );
			update_post_meta( $event_id, 'event_end', $date );

			$timezone = new DateTimeZone( wp_timezone_string() );
			$start = new DateTime( $date, $timezone );
			$start->setTime( 0, 0, 0 );

			$end = new DateTime( $date, $timezone );
			$end->setTime( 23, 59, 59 );

			$start_utc = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			$end_utc = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

			update_post_meta( $event_id, 'event_start', $start_utc );
			update_post_meta( $event_id, 'event_end', $end_utc );
		} else {
			// Procesamiento normal para eventos con hora.
			$start = get_gmt_from_date( $request->get_param( 'event_start' ) );
			$end = get_gmt_from_date( $request->get_param( 'event_end' ) );

			update_post_meta( $event_id, 'event_start', $start );
			update_post_meta( $event_id, 'event_end', $end );
		}

		// Update event in WP.
		$updated_meta = array();

		// Loop through meta fields and update if present.
		foreach ( $meta_fields as $key => $sanitize_callback ) {
			if ( $request->has_param( $key ) ) {
				$value = call_user_func( $sanitize_callback, $request->get_param( $key ) );
				update_post_meta( $event_id, $key, $value );
				$updated_meta[ $key ] = $value;
			}
		}

		// Step 4: Return response.
		return new WP_REST_Response(
			array(
				'message' => 'Event meta updated successfully',
				'updated_meta' => $updated_meta,
			),
			200
		);
	}



	/**
	 * Sanitize callback for the all-day flag.
	 *
	 * Always returns the string '1' or '0'.
	 *
	 * @param mixed $value the value.
	 * @return string
	 */
	public static function sanitize_event_allday( $value ) {
		$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		return $bool ? '1' : '0';
	}

	/**
	 * Sanitize callback for date/time meta.
	 *
	 * - If date-only (YYYY-MM-DD), returns as-is.
	 * - Otherwise parses in site timezone, converts to UTC, and returns MySQL datetime.
	 *
	 * @param string $value the value.
	 * @return string
	 */
	public static function sanitize_event_datetime( $value ) {
		$value = sanitize_text_field( $value );

		// If exactly YYYY-MM-DD, treat as all-day.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		// Normalize ISO8601 to space‑separated.
		$value = str_replace( 'T', ' ', $value );

		// Parse in site timezone.
		$tz_local = new DateTimeZone( wp_timezone_string() );
		try {
			$dt = new DateTime( $value, $tz_local );
		} catch ( Exception $e ) {
			// Fallback to system timezone.
			$dt = new DateTime( $value );
		}

		// Convert to UTC and format.
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
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
		$start_utc = get_post_meta( $post->ID, 'event_start', true );
		$end_utc = get_post_meta( $post->ID, 'event_end', true );
		$location = get_post_meta( $post->ID, 'event_location', true );
		$url = get_post_meta( $post->ID, 'event_url', true );
		$category = get_post_meta( $post->ID, 'event_category', true );
		$assigned_users = get_post_meta( $post->ID, 'event_assigned_users', true );

		$start_for_input = '';
		$end_for_input   = '';
		$input_type      = $allday ? 'date' : 'datetime-local';

		if ( $allday ) {
			// Para 'all-day', el valor es Y-m-d, no necesita conversión.
			$start_for_input = $start_utc;
			$end_for_input   = $end_utc;
		} elseif ( $start_utc ) {
			// Para eventos con hora, convertimos de UTC a la zona horaria local.
			// El formato 'Y-m-d\TH:i:s' es el que espera <input type="datetime-local">.
			$start_for_input = get_date_from_gmt( $start_utc, 'Y-m-d\TH:i:s' );
			$end_for_input   = get_date_from_gmt( $end_utc, 'Y-m-d\TH:i:s' );
		}

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
		function toggleDateType() {
			const isAllDay = $('#event_allday').is(':checked');
			const type = isAllDay ? 'date' : 'datetime-local';

			$('#event_start, #event_end').each(function() {
				const value = this.value;
				const newInput = this.cloneNode();
				newInput.type = type;

				// Reasignar valor (convertir si es necesario)
				if (type === 'date') {
					newInput.value = value.split('T')[0];
				} else {
					const parts = value.split('T');
					if (parts.length === 2) {
						newInput.value = value;
					} else {
						newInput.value = value + 'T00:00';
					}
				}

				$(this).replaceWith(newInput);
			});
		}

		$('#event_allday').on('change', toggleDateType);
	});
})(jQuery);
</script>

<p>
	<label for="event_start"><?php esc_html_e( 'Start:', 'decker' ); ?></label><br>
	<input type="<?php echo esc_attr( $input_type ); ?>" 
		   id="event_start" 
		   name="event_start"
		   value="<?php echo esc_attr( $start_for_input ); ?>"
		   class="widefat">
</p>
<p>
	<label for="event_end"><?php esc_html_e( 'End:', 'decker' ); ?></label><br>
	<input type="<?php echo esc_attr( $input_type ); ?>" 
		   id="event_end" 
		   name="event_end"
		   value="<?php echo esc_attr( $end_for_input ); ?>"
		   class="widefat">
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
	 * Process and save meta data from a data array.
	 * This is the core logic, decoupled from $_POST for testability.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data to save (e.g., from $_POST).
	 */
	public function process_and_save_meta( $post_id, $data ) {
		// Save all-day event status.
		$allday = ! empty( $data['event_allday'] ) && filter_var( $data['event_allday'], FILTER_VALIDATE_BOOLEAN );

		update_post_meta( $post_id, 'event_allday', (bool) $allday ? '1' : '0' );

		// Process and save dates.
		$start_input = isset( $data['event_start'] ) ? sanitize_text_field( wp_unslash( $data['event_start'] ) ) : '';
		$end_input   = isset( $data['event_end'] ) ? sanitize_text_field( wp_unslash( $data['event_end'] ) ) : '';

		// a missing event_end is copied from start + 1 h.
		if ( ! $allday && '' === $end_input && '' !== $start_input ) {
			$end_input = gmdate( 'Y-m-d H:i:s', strtotime( $start_input ) + HOUR_IN_SECONDS );
		}

		if ( $allday ) {
			// Only date part matters.
			$start_date = $start_input ? gmdate( 'Y-m-d', strtotime( $start_input ) ) : '';
			$end_date   = $end_input ? gmdate( 'Y-m-d', strtotime( $end_input ) ) : '';

			// Enforce end ≥ start.
			if ( $start_date && $end_date && strtotime( $end_date ) < strtotime( $start_date ) ) {
				$end_date = $start_date;
			}
			if ( $start_date && ! $end_date ) {
				$end_date = $start_date;
			}

			update_post_meta( $post_id, 'event_start', $start_date );
			update_post_meta( $post_id, 'event_end', $end_date );
		} else {
			 // Timed event: if end missing or end ≤ start, default to start +1h (local).
			// 2a) Completely malformed start?.
			if ( $start_input && false === strtotime( $start_input ) ) {
				// epoch start +1h for end.
				$start_input = gmdate( 'Y-m-d H:i:s', 0 );
				$end_input   = gmdate( 'Y-m-d H:i:s', HOUR_IN_SECONDS );
			} else {
				// 2b) Missing end → start +1h.
				if ( $start_input && ! $end_input ) {
					try {
						$dt = new DateTime( $start_input, new DateTimeZone( wp_timezone_string() ) );
						$dt->modify( '+1 hour' );
						$end_input = $dt->format( 'Y-m-d H:i:s' );
					} catch ( Exception $e ) {
						// fallback to epoch+1h.
						$end_input = gmdate( 'Y-m-d H:i:s', HOUR_IN_SECONDS );
					}
				}

				// 2c) End ≤ start → adjust to start +1h.
				if ( $start_input && $end_input && strtotime( $end_input ) <= strtotime( $start_input ) ) {
					try {
						$dt = new DateTime( $start_input, new DateTimeZone( wp_timezone_string() ) );
						$dt->modify( '+1 hour' );
						$end_input = $dt->format( 'Y-m-d H:i:s' );
					} catch ( Exception $e ) {
						$end_input = gmdate( 'Y-m-d H:i:s', strtotime( $start_input ) + HOUR_IN_SECONDS );
					}
				}
			}

			// Save raw local strings; our REST sanitizer will convert them on save,
			// and our unit‐test factory uses process_and_save_meta too, so it benefits.
			update_post_meta( $post_id, 'event_start', $start_input );
			update_post_meta( $post_id, 'event_end', $end_input );
		}

		// Save other fields.
		$fields_to_save = array(
			'event_location' => 'sanitize_text_field',
			'event_url'      => 'esc_url_raw',
			'event_category' => 'sanitize_text_field',
		);

		foreach ( $fields_to_save as $key => $sanitize_callback ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $key, call_user_func( $sanitize_callback, wp_unslash( $data[ $key ] ) ) );
			}
		}

		// Save assigned users.
		$assigned_users = array();
		if ( isset( $data['event_assigned_users'] ) && is_array( $data['event_assigned_users'] ) ) {
			$assigned_users = array_map( 'intval', $data['event_assigned_users'] );
		}
		update_post_meta( $post_id, 'event_assigned_users', array_filter( $assigned_users ) );
	}

	/**
	 * Save event meta data from admin form submission.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	public function save_event_meta( $post_id, $post, $update ) {
		// Check nonce, user permissions, and autosave.
		if ( ! isset( $_POST['decker_event_meta_box_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['decker_event_meta_box_nonce'] ), 'decker_event_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call the main logic function with the $_POST data.
		$this->process_and_save_meta( $post_id, $_POST );
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

