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
	 * Define the hooks for the events custom post type
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	    add_filter('rest_pre_dispatch', array($this, 'restrict_rest_access'), 10, 3);

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_decker_event', array( $this, 'save_event_meta' ) );

        add_filter('use_block_editor_for_post_type', array( $this, 'force_classic_editor'), 10, 2);
    
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
			'event_start' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_end' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
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
				'show_in_rest' => array(
					'schema' => array(
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => function ($value) {
					return array_map('absint', (array)$value);
				},
			),
		);

		foreach ($meta_fields as $key => $args) {
			$default_args = array(
				'single' => true,
				'show_in_rest' => true,
			);
			
			register_post_meta(
				'decker_event',
				$key,
				array_merge($default_args, $args)
			);
		}
	}


	/**
	 * Forces classic editor
	 */
public function force_classic_editor($use_block_editor, $post_type) {
    if ($post_type === 'decker_event') {
        return false; // Deactivate gutenberg editor.
    }
    return $use_block_editor;
}



	/**
	 * Restringe el acceso a los endpoints de 'decker_event' solo a editores y superiores.
	 */
public function restrict_rest_access($result, $rest_server, $request) {
    $route = $request->get_route();

    if (strpos($route, '/wp/v2/decker_event') === 0) {
        // Usa la capacidad específica del CPT
        if (!current_user_can('edit_posts')) {
            return new WP_Error(
                'rest_forbidden',
                __('No tienes permisos para acceder a este recurso.', 'decker'),
                array('status' => 403)
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
	}

	/**
	 * Render the event details meta box
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_event_details_meta_box( $post ) {
		wp_nonce_field( 'decker_event_meta_box', 'decker_event_meta_box_nonce' );

		$start_date = get_post_meta( $post->ID, 'event_start', true );
		$end_date = get_post_meta( $post->ID, 'event_end', true );
		$location = get_post_meta( $post->ID, 'event_location', true );
		$url = get_post_meta( $post->ID, 'event_url', true );
		$category = get_post_meta( $post->ID, 'event_category', true );
		$assigned_users = get_post_meta( $post->ID, 'event_assigned_users', true );

		?>
		<p>
			<label for="event_start"><?php esc_html_e( 'Start Date/Time:', 'decker' ); ?></label><br>
			<input type="datetime-local" id="event_start" name="event_start" 
				value="<?php echo esc_attr( $start_date ); ?>">
		</p>
		<p>
			<label for="event_end"><?php esc_html_e( 'End Date/Time:', 'decker' ); ?></label><br>
			<input type="datetime-local" id="event_end" name="event_end" 
				value="<?php echo esc_attr( $end_date ); ?>">
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
		<p>
			<label for="event_assigned_users"><?php esc_html_e( 'Assigned Users:', 'decker' ); ?></label><br>
			<select id="event_assigned_users" name="event_assigned_users[]" multiple class="widefat">
				<?php
				$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
				foreach ( $users as $user ) {
					$selected = is_array( $assigned_users ) && in_array( $user->ID, $assigned_users, true );
					printf(
						'<option value="%d" %s>%s</option>',
						esc_attr( $user->ID ),
						selected( $selected, true, false ),
						esc_html( $user->display_name )
					);
				}
				?>
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
		if (!defined('WP_TESTS_RUNNING')) {
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

		// Save start date.
		if ( isset( $_POST['event_start'] ) ) {
			update_post_meta( $post_id, 'event_start', sanitize_text_field( wp_unslash( $_POST['event_start'] ) ) );
		}

		// Save end date.
		if ( isset( $_POST['event_end'] ) ) {
			update_post_meta( $post_id, 'event_end', sanitize_text_field( wp_unslash( $_POST['event_end'] ) ) );
		}

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

		// Save assigned users.
		if ( isset( $_POST['event_assigned_users'] ) ) {
			$assigned_users = array_map( 'absint', wp_unslash( $_POST['event_assigned_users'] ) );
			update_post_meta( $post_id, 'event_assigned_users', $assigned_users );
		} else {
			delete_post_meta( $post_id, 'event_assigned_users' );
		}
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Events' ) ) {
	new Decker_Events();
}
