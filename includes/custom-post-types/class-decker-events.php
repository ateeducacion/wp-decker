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

		// Add columns to admin.
		add_filter( 'manage_decker_event_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_decker_event_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );

		add_action( 'rest_after_insert_decker_event', array( $this, 'rest_fix_datetime_meta' ), 10, 3 );
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
				'schema' => array(
					'type' => 'boolean',
				),
			),
			'event_start' => array(
				'type' => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_event_datetime' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_end' => array(
				'type' => 'string',
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
				   // Use the specific capability of the CPT.
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
	 * Sanitize a UTC date‑time.
	 *
	 * - Raw date (YYYY‑MM‑DD) → treat as all‑day, keep it as is.
	 * - ISO‑8601 UTC with “Z” → keep as is.
	 * - Anything else → normaliza a 'Y-m-d H:i:s' (UTC).
	 *
	 * @param string $value      Raw input.
	 * @param string $meta_key   Meta key (unused, needed for callback signature).
	 * @param string $object_type Object type (unused).
	 * @return string Sanitized value or empty string on failure.
	 */
	public static function sanitize_event_datetime( $value, $meta_key = '', $object_type = '' ) {
		$value = sanitize_text_field( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		// YYYY‑MM‑DD → all‑day.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		// ISO 8601 UTC already.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return $value;
		}

		// Accept "YYYY‑MM‑DD HH:MM:SS" or "YYYY‑MM‑DDTHH:MM:SSZ".
		$normalized = str_replace( 'T', ' ', rtrim( $value, 'Z' ) );

		try {
			$dt = new DateTime( $normalized, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Normalize date metadata after a REST operation.
	 *
	 * – For all-day: trim the time.
	 * – For timed events:
	 *     · Convert ISO ('T…Z') to 'Y-m-d H:i:s'.
	 *     · If only the date arrives, add '00:00:00'.
	 *     · If end ≤ start, adjust end = start + 1h.
	 *
	 * @param WP_Post         $post the post.
	 * @param WP_REST_Request $request the request.
	 * @param bool            $update is updating.
	 */
	public function rest_fix_datetime_meta( $post, $request, $update ) {

		$allday = get_post_meta( $post->ID, 'event_allday', true );

		// 1. All‑day  →   YYYY‑MM‑DD.
		if ( '1' === $allday ) {
			foreach ( array( 'event_start', 'event_end' ) as $key ) {
				$val = get_post_meta( $post->ID, $key, true );
				update_post_meta( $post->ID, $key, substr( $val, 0, 10 ) );
			}
			return;
		}

		// 2. Timed events.
		$start = get_post_meta( $post->ID, 'event_start', true );
		$end   = get_post_meta( $post->ID, 'event_end', true );

		// a) ISO → space.
		foreach ( array(
			'start' => 'event_start',
			'end' => 'event_end',
		) as $var => $meta ) {
			$$var = preg_match( '/Z$/', $$var )
			? str_replace( array( 'T', 'Z' ), array( ' ', '' ), $$var )
			: $$var;

						// b) date only → midnight.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $$var ) ) {
				$$var .= ' 00:00:00';
			}

			update_post_meta( $post->ID, $meta, $$var );
		}

		   // c) end empty or ≤ start  →  +1h.
		if ( ! $end || strtotime( $end ) <= strtotime( $start ) ) {
			$end = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS );
			update_post_meta( $post->ID, 'event_end', $end );
		}
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
			$start_for_input = $start_utc;
			$end_for_input   = $end_utc;
		} elseif ( $start_utc ) {
			// Already UTC; just format for <input type="datetime-local">.
			$start_for_input = str_replace( ' ', 'T', $start_utc );
			$end_for_input   = str_replace( ' ', 'T', $end_utc );
		}

		$step_attr  = $allday ? '' : ' step="60s"';          // 60s ⇒ oculta segundos.
		$value_attr = $allday
			? esc_attr( $start_utc )
			: esc_attr( gmdate( 'Y-m-d\TH:i', strtotime( $start_utc . ' UTC' ) ) );

		?>
			<p>
	<label>
		<input type="checkbox" name="event_allday" id="event_allday" <?php checked( $allday, '1' ); ?>>
		<?php esc_html_e( 'All Day Event Event', 'decker' ); ?>
	</label>
</p>

<!-- Container for error messages -->
<div id="event_date_error" style="color: red; display: none;">
		<?php esc_html_e( 'End Date must be after Start Date.', 'decker' ); ?>
</div>

<!-- Script to handle field visibility and validation -->
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

				// Reassign value (convert if necessary)
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
		   class="widefat"<?php echo esc_attr( $step_attr ); ?>>
	<small class="description">
		<?php esc_html_e( 'Time is stored in UTC. Adjust accordingly.', 'decker' ); ?>
	</small>

</p>
<p>
	<label for="event_end"><?php esc_html_e( 'End:', 'decker' ); ?></label><br>
	<input type="<?php echo esc_attr( $input_type ); ?>" 
		   id="event_end" 
		   name="event_end"
		   value="<?php echo esc_attr( $end_for_input ); ?>"
		   class="widefat"<?php echo esc_attr( $step_attr ); ?>>
	<small class="description">
		<?php esc_html_e( 'Time is stored in UTC. Adjust accordingly.', 'decker' ); ?>
	</small>

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
	 * Add custom columns to the event admin list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_custom_columns( $columns ) {
		   unset( $columns['date'] ); // Optional: remove the default date column.

		$columns['event_allday'] = __( 'All Day Event', 'decker' );
		$columns['event_start'] = __( 'Start', 'decker' );
		$columns['event_end']   = __( 'End', 'decker' );
		$columns['event_category'] = __( 'Category', 'decker' );

		   $columns['date'] = __( 'Date', 'decker' ); // Add it again at the end.
		return $columns;
	}

	/**
	 * Render content for custom columns in the event admin list.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'event_allday':
				$allday = get_post_meta( $post_id, 'event_allday', true );
				printf(
					'<input type="checkbox" disabled %s>',
					checked( $allday, '1', false )
				);
				break;
			case 'event_start':
				$start = get_post_meta( $post_id, 'event_start', true );
				echo esc_html( $start );
				break;
			case 'event_end':
				$end = get_post_meta( $post_id, 'event_end', true );
				echo esc_html( $end );
				break;
			case 'event_category':
				$category = get_post_meta( $post_id, 'event_category', true );
				echo esc_html( $category );
				break;
		}
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
			$end_input = gmdate( 'Y-m-d H:i:s', strtotime( $start_input . ' UTC' ) + HOUR_IN_SECONDS );
		}

		if ( $allday ) {

			$start_input = substr( $start_input, 0, 10 );
			$end_input   = substr( $end_input, 0, 10 );

			// Only date part matters.
			$start_date = $start_input ? gmdate( 'Y-m-d', strtotime( $start_input . ' UTC' ) ) : '';
			$end_date   = $end_input ? gmdate( 'Y-m-d', strtotime( $end_input . ' UTC' ) ) : '';

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

				   // If it comes in YYYY‑MM‑DD format → append 00:00:00.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_input ) ) {
				$start_input .= ' 00:00:00';
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_input ) ) {
						   $end_input .= ' 01:00:00'; // will be corrected below if appropriate.
			}

			// Timed event: if end missing or end ≤ start, default to start + 1 h (UTC).
			// 2a) Malformed start?
			if ( $start_input && false === strtotime( $start_input . ' UTC' ) ) {
				$start_input = gmdate( 'Y-m-d H:i:s', 0 );
				$end_input   = gmdate( 'Y-m-d H:i:s', HOUR_IN_SECONDS );
			} else {
				// 2b) Missing end → start + 1 h.
				if ( $start_input && ! $end_input ) {
					$start_ts = strtotime( $start_input . ' UTC' );
					$end_input = gmdate( 'Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS );
				}

				// 2c) End ≤ start → adjust to start + 1 h.
				if ( $start_input && $end_input && strtotime( $end_input . ' UTC' ) <= strtotime( $start_input . ' UTC' ) ) {
					$start_ts  = strtotime( $start_input . ' UTC' );
					$end_input = gmdate( 'Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS );
				}
			}

			$start_input = gmdate( 'Y-m-d H:i:00', strtotime( $start_input . ' UTC' ) );
			$end_input   = gmdate( 'Y-m-d H:i:00', strtotime( $end_input . ' UTC' ) );

			// Save raw UTC strings.
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
		   update_meta_cache( 'post', $post_ids ); // One extra query for all metadata.

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

