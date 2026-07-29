<?php
/**
 * Admin_Settings Class
 *
 * This class handles the settings page for the Decker plugin.
 *
 * @package Decker
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Admin_Settings
 *
 * Handles the settings page for the Decker plugin.
 */
class Decker_Admin_Settings {

	/**
	 * Renders the settings fields.
	 *
	 * @var Decker_Admin_Settings_Fields
	 */
	private $fields;

	/**
	 * Validates settings submissions.
	 *
	 * @var Decker_Admin_Settings_Validator
	 */
	private $validator;

	/**
	 * Constructor
	 *
	 * Initializes the class by defining hooks.
	 */
	public function __construct() {
		$this->fields    = new Decker_Admin_Settings_Fields();
		$this->validator = new Decker_Admin_Settings_Validator();

		$this->define_hooks();
	}

	/**
	 * Handle Clear All Data.
	 *
	 * Handles the clearing of all Decker data.
	 */
	public function handle_clear_all_data() {
		if ( isset( $_POST['decker_clear_all_data'] ) && check_admin_referer( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce' ) ) {

			// Delete all Decker custom post types and taxonomies.
			$custom_post_types = array( 'decker_task' );
			foreach ( $custom_post_types as $post_type ) {
				$posts = get_posts(
					array(
						'post_type'   => $post_type,
						'numberposts' => -1,
						'post_status' => array( 'publish', 'archived' ),
					)
				);
				foreach ( $posts as $post ) {
					wp_delete_post( $post->ID, true );
				}
			}

			// Delete all Decker taxonomies.
			$taxonomies = array( 'decker_board', 'decker_label' );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				foreach ( $terms as $term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}

			// Redirect and terminate execution.
			$redirect_url = add_query_arg(
				array(
					'page'                => 'decker_settings',
					'decker_data_cleared' => 'true',
				),
				admin_url( 'options-general.php' )
			);

			$this->redirect_and_exit( $redirect_url );

		}
	}

	/**
	 * Redirect and Exit.
	 *
	 * Handles the redirection and termination of execution.
	 *
	 * @param string $url URL to redirect to.
	 */
	protected function redirect_and_exit( $url ) {
		wp_redirect( $url );
		exit;
	}

	/**
	 * Define Hooks.
	 *
	 * Registers all the hooks related to the settings page.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_all_data' ) );
	}

	/**
	 * Create Menu.
	 *
	 * Adds the settings page to the admin menu.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Decker Settings', 'decker' ),
			__( 'Decker', 'decker' ),
			'manage_options',
			'decker_settings',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Settings Initialization.
	 *
	 * Registers settings and adds settings sections and fields.
	 */
	public function settings_init() {
		register_setting( 'decker', 'decker_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'decker_main_section',
			__( 'Decker Configuration', 'decker' ),
			array( $this->fields, 'settings_section_callback' ),
			'decker'
		);

		add_settings_section(
			'decker_ai_section',
			__( 'AI Configuration', 'decker' ),
			array( $this->fields, 'ai_settings_section_callback' ),
			'decker'
		);

		$fields = array(
			'alert_color'           => __( 'Alert Color', 'decker' ), // Alert color radio buttons.
			'alert_message'         => __( 'Alert Message', 'decker' ), // Alert message field.
			'minimum_user_profile'  => __( 'Minimum User Profile', 'decker' ), // User profile dropdown.
			'task_editor_type'      => __( 'Task Editor Type', 'decker' ),
			'shared_key'            => __( 'Shared Key', 'decker' ),
			'allow_email_notifications' => __( 'Allow Email Notifications', 'decker' ),
			'collaborative_editing' => __( 'Collaborative Editing', 'decker' ),
			'sidebar_board_status'  => __( 'Board status indicators', 'decker' ),
			'signaling_server'      => __( 'Signaling Server', 'decker' ),
			'clear_all_data_button' => __( 'Clear All Data', 'decker' ),
			'ignored_users'         => __( 'Ignored Users', 'decker' ),
		);

		foreach ( $fields as $field_id => $field_title ) {
			add_settings_field(
				$field_id,
				$field_title,
				array( $this->fields, 'render' ),
				'decker',
				'decker_main_section',
				array( 'field' => $field_id )
			);
		}

		$ai_fields = array(
			'ai_enabled'  => __( 'AI Improvements', 'decker' ),
			'ai_provider' => __( 'AI Provider', 'decker' ),
			'ai_api_key'  => __( 'Gemini API Key', 'decker' ),
			'ai_model'    => __( 'Gemini Model', 'decker' ),
			'ai_prompt'   => __( 'AI Prompt', 'decker' ),
		);

		foreach ( $ai_fields as $field_id => $field_title ) {
			add_settings_field(
				$field_id,
				$field_title,
				array( $this->fields, 'render' ),
				'decker',
				'decker_ai_section',
				array( 'field' => $field_id )
			);
		}
	}

	/**
	 * Options Page.
	 *
	 * Renders the settings page.
	 */
	public function options_page() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'decker' );
			do_settings_sections( 'decker' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Admin Notices.
	 *
	 * Displays admin notices.
	 */
	public function admin_notices() {
		if ( isset( $_GET['decker_data_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Decker records have been deleted.', 'decker' ) . '</p></div>';
		}

		$invalid_user_ids = get_transient( 'decker_invalid_user_ids' );
		if ( false !== $invalid_user_ids ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				sprintf(
					// Translators: %s is a list of invalid user IDs that have been removed.
					esc_html__( 'The following user IDs were invalid and have been removed: %s', 'decker' ),
					esc_html( implode( ', ', $invalid_user_ids ) )
				) .
				'</p></div>';
			delete_transient( 'decker_invalid_user_ids' );
		}
	}

	/**
	 * Settings Validation.
	 *
	 * Validates the settings fields.
	 *
	 * @param array $input The input fields to validate.
	 * @return array The validated fields.
	 */
	public function settings_validate( $input ) {
		return $this->validator->validate( $input, get_option( 'decker_settings', array() ) );
	}
}
