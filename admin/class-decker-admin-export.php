<?php
/**
 * Decker_Admin_Export Class
 *
 * This class handles the export functionality for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decker_Admin_Export Class
 *
 * Handles the export functionality for the Decker plugin.
 */
class Decker_Admin_Export {

	/**
	 * Constructor
	 *
	 * Initializes the class by setting up the export functionality.
	 */
	public function __construct() {
		add_action( 'export_filters', array( $this, 'add_export_option' ) );
		add_action( 'export_wp', array( $this, 'process_export' ) );
	}

	/**
	 * Get custom post types for the export.
	 *
	 * @return array List of custom post types.
	 */
	private function get_custom_post_types() {
		// Define the custom post types to export.
		return array( 'decker_task', 'decker_kb', 'decker_event' );
	}

	/**
	 * Get custom taxonomies for the export.
	 *
	 * @return array List of custom taxonomies.
	 */
	private function get_custom_taxonomies() {
		// Define the custom taxonomies to export.
		return array( 'decker_board', 'decker_action', 'decker_label' );
	}

	/**
	 * Adds the export option for Decker data.
	 */
	public function add_export_option() {
		?>
		<fieldset>
			<p>
				<label>
					<input type="radio" name="content" value="decker" />
					<?php esc_html_e( 'Decker', 'decker' ); ?>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Processes the export for Decker data.
	 *
	 * @param array $args Arguments for the export.
	 */
	public function process_export( $args ) {
		if ( isset( $_GET['content'] ) && 'decker' === $_GET['content'] ) {
			// Create the backup and return JSON.
			echo wp_json_encode( $this->create_backup() );
		}
	}

	/**
	 * Creates a backup of Decker data and downloads it as a JSON file.
	 */
	private function create_backup() {
		$custom_post_types = $this->get_custom_post_types();
		$custom_taxonomies = $this->get_custom_taxonomies();
		$data              = array();

		foreach ( $custom_post_types as $post_type ) {
			$posts              = $this->export_post_type( $post_type );
			$data[ $post_type ] = $posts;
		}

		foreach ( $custom_taxonomies as $taxonomy ) {
			$terms             = $this->export_taxonomy_terms( $taxonomy );
			$data[ $taxonomy ] = $terms;
		}

		// return the data.
		return $data;
	}

	/**
	 * Exports posts of a specific post type.
	 *
	 * @param string $post_type The post type to export.
	 * @return array Exported posts.
	 */
	public function export_post_type( $post_type ) {
		$posts = array();

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$posts[] = array(
					'ID'            => get_the_ID(),
					'post_title'    => get_the_title(),
					'post_content'  => get_the_content(),
					'post_parent'   => get_post()->post_parent,
					'menu_order'    => get_post()->menu_order,
					'post_meta'     => get_post_meta( get_the_ID() ),
					'decker_board'  => wp_get_object_terms( get_the_ID(), 'decker_board' ),
					'decker_label'  => wp_get_object_terms( get_the_ID(), 'decker_label' ),
					'decker_action' => wp_get_object_terms( get_the_ID(), 'decker_action' ),
				);
			}
			wp_reset_postdata();
		}

		return $posts;
	}

	/**
	 * Exports terms of a specific taxonomy.
	 *
	 * @param string $taxonomy The taxonomy to export.
	 * @return array Exported terms.
	 */
	public function export_taxonomy_terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'get'        => 'all', // Fetch all fields.
			)
		);

		$exported_terms = array();

		foreach ( $terms as $term ) {
			$term_meta        = get_term_meta( $term->term_id );
			$exported_terms[] = array(
				'term_id'          => $term->term_id,
				'name'             => $term->name,
				'slug'             => $term->slug,
				'description'      => $term->description,
				'term_group'       => $term->term_group,
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'taxonomy'         => $term->taxonomy,
				'parent'           => $term->parent,
				'count'            => $term->count,
				'filter'           => $term->filter,
				'term_meta'        => $term_meta,
			);
		}

		return $exported_terms;
	}
}
