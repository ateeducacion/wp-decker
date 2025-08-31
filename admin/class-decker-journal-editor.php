<?php
/**
 * Journal Editor for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Journal_Editor.
 *
 * Handles the editor experience for the Journal CPT.
 */
class Decker_Journal_Editor {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks.
	 */
	private function define_hooks() {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_filter( 'default_content', array( $this, 'set_default_editor_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_decker_journal', array( $this, 'save_meta_box_data' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
	}

	/**
	 * Set the default editor content for new journal entries.
	 *
	 * @param string  $content The default content.
	 * @param WP_Post $post    The post object.
	 * @return string The modified default content.
	 */
	public function set_default_editor_content( $content, $post ) {
		if ( 'decker_journal' === $post->post_type ) {
			$today_date = gmdate( 'd/m/Y' );
			$title_placeholder = 'xxx'; // The user will enter the real title.

			$template = "[[TOC]]\n";
			$template .= "[toc]\n";
			$template .= "# {$today_date} {$title_placeholder}\n";
			$template .= "**Asistentes:** \n";
			$template .= "**Asunto:** \n";
			$template .= "**Descripción:**\n\n";
			$template .= "**Acuerdos:**\n";
			$template .= "- \n";
			$template .= "- \n\n";
			$template .= "**Tareas derivadas en Decker:**\n";
			$template .= "**Descripción**\n";
			$template .= "**Responsable (Equipo)**\n";
			$template .= "**Enlace a tarjeta**\n\n";
			$template .= "**Nota:**\n";
			$template .= "[ ] \n";

			return $template;
		}
		return $content;
	}

	/**
	 * Disable Gutenberg editor for journal entries.
	 *
	 * @param bool   $current_status The current status.
	 * @param string $post_type      The post type.
	 * @return bool
	 */
	public function disable_gutenberg( $current_status, $post_type ) {
		if ( 'decker_journal' === $post_type ) {
			return false;
		}
		return $current_status;
	}

	/**
	 * Add meta box to the journal editor screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'decker_journal_details',
			__( 'Journal Details', 'decker' ),
			array( $this, 'render_meta_box' ),
			'decker_journal',
			'normal',
			'high'
		);
		add_meta_box(
			'decker_journal_users',
			__( 'Users', 'decker' ),
			array( $this, 'render_users_meta_box' ),
			'decker_journal',
			'side',
			'default'
		);
		add_meta_box(
			'decker_journal_labels',
			__( 'Labels', 'decker' ),
			array( $this, 'render_labels_meta_box' ),
			'decker_journal',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'decker_journal_meta_box', 'decker_journal_meta_box_nonce' );

		$journal_date = get_post_meta( $post->ID, 'journal_date', true ) ? get_post_meta( $post->ID, 'journal_date', true ) : gmdate( 'Y-m-d' );
		$attendees = get_post_meta( $post->ID, 'attendees', true ) ? get_post_meta( $post->ID, 'attendees', true ) : array();
		$topic = get_post_meta( $post->ID, 'topic', true );
		$agreements = get_post_meta( $post->ID, 'agreements', true ) ? get_post_meta( $post->ID, 'agreements', true ) : array();
		$board_terms = get_terms(
			array(
				'taxonomy' => 'decker_board',
				'hide_empty' => false,
			)
		);
		$assigned_board = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
		$assigned_board = ! empty( $assigned_board ) ? $assigned_board[0] : '';
		?>
		<p>
			<label for="decker_board"><strong><?php esc_html_e( 'Board:', 'decker' ); ?></strong></label>
			<select name="decker_board" id="decker_board" class="widefat">
				<option value=""><?php esc_html_e( 'Select a Board (required)', 'decker' ); ?></option>
				<?php foreach ( $board_terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $assigned_board, $term->term_id ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="journal_date"><?php esc_html_e( 'Journal Date:', 'decker' ); ?></label>
			<input type="date" id="journal_date" name="journal_date" value="<?php echo esc_attr( $journal_date ); ?>" />
		</p>
		<p>
			<label for="topic"><?php esc_html_e( 'Topic (Asunto):', 'decker' ); ?></label>
			<input type="text" id="topic" name="topic" value="<?php echo esc_attr( $topic ); ?>" class="widefat" />
		</p>
		<p>
			<label for="agreements"><?php esc_html_e( 'Agreements (one per line):', 'decker' ); ?></label>
			<textarea id="agreements" name="agreements" class="widefat" rows="5"><?php echo esc_textarea( implode( "\n", $agreements ) ); ?></textarea>
		</p>
		<?php
		// NOTE: Derived Tasks, Notes, and Related Tasks will be simple textareas for now.
		// A more advanced UI will be built with JavaScript later if needed.
		$derived_tasks = get_post_meta( $post->ID, 'derived_tasks', true ) ? get_post_meta( $post->ID, 'derived_tasks', true ) : array();
		$notes = get_post_meta( $post->ID, 'notes', true ) ? get_post_meta( $post->ID, 'notes', true ) : array();
		$related_task_ids = get_post_meta( $post->ID, 'related_task_ids', true ) ? get_post_meta( $post->ID, 'related_task_ids', true ) : array();
		?>
		<p>
			<label for="derived_tasks"><?php esc_html_e( 'Derived Tasks (JSON format):', 'decker' ); ?></label>
			<textarea id="derived_tasks" name="derived_tasks" class="widefat" rows="5"><?php echo esc_textarea( wp_json_encode( $derived_tasks, JSON_PRETTY_PRINT ) ); ?></textarea>
		</p>
		<p>
			<label for="notes"><?php esc_html_e( 'Notes (JSON format):', 'decker' ); ?></label>
			<textarea id="notes" name="notes" class="widefat" rows="5"><?php echo esc_textarea( wp_json_encode( $notes, JSON_PRETTY_PRINT ) ); ?></textarea>
		</p>
		<p>
			<label for="related_task_ids"><?php esc_html_e( 'Related Task IDs (comma-separated):', 'decker' ); ?></label>
			<input type="text" id="related_task_ids" name="related_task_ids" value="<?php echo esc_attr( implode( ',', $related_task_ids ) ); ?>" class="widefat" />
		</p>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 */
	public function save_meta_box_data( $post_id, $post ) {
		if ( ! isset( $_POST['decker_journal_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['decker_journal_meta_box_nonce'] ), 'decker_journal_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Validation for admin editor.
		$board_id = isset( $_POST['decker_board'] ) ? absint( $_POST['decker_board'] ) : 0;
		if ( empty( $board_id ) ) {
			set_transient( 'decker_journal_error', __( 'A board is required to save a journal entry.', 'decker' ) );
			return;
		}

		$journal_date = isset( $_POST['journal_date'] ) ? sanitize_text_field( wp_unslash( $_POST['journal_date'] ) ) : '';
		$query = new WP_Query(
			array(
				'post_type'      => 'decker_journal',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'post__not_in'   => array( $post_id ),
				'meta_key'       => 'journal_date',
				'meta_value'     => $journal_date,
				'tax_query'      => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_id,
					),
				),
				'posts_per_page' => 1,
			)
		);
		if ( $query->have_posts() ) {
			set_transient( 'decker_journal_error', __( 'A journal entry for this board and date already exists.', 'decker' ) );
			return;
		}

		if ( isset( $_POST['journal_date'] ) ) {
			update_post_meta( $post_id, 'journal_date', sanitize_text_field( wp_unslash( $_POST['journal_date'] ) ) );
		}
		if ( isset( $_POST['topic'] ) ) {
			update_post_meta( $post_id, 'topic', sanitize_text_field( wp_unslash( $_POST['topic'] ) ) );
		}
		if ( isset( $_POST['journal_users'] ) ) {
			$journal_users = array_map( 'absint', wp_unslash( $_POST['journal_users'] ) );
			update_post_meta( $post_id, 'journal_users', $journal_users );
		}
		if ( isset( $_POST['decker_labels'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'intval', wp_unslash( $_POST['decker_labels'] ) ), 'decker_label', false );
		} else {
			wp_set_post_terms( $post_id, array(), 'decker_label', false );
		}
		if ( isset( $_POST['agreements'] ) ) {
			$agreements = array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['agreements'] ) ) );
			update_post_meta( $post_id, 'agreements', $agreements );
		}
		if ( isset( $_POST['derived_tasks'] ) ) {
			$derived_tasks = json_decode( wp_unslash( $_POST['derived_tasks'] ), true );
			update_post_meta( $post_id, 'derived_tasks', $derived_tasks );
		}
		if ( isset( $_POST['notes'] ) ) {
			$notes = json_decode( wp_unslash( $_POST['notes'] ), true );
			update_post_meta( $post_id, 'notes', $notes );
		}
		if ( isset( $_POST['related_task_ids'] ) ) {
			$related_task_ids = array_map( 'absint', explode( ',', wp_unslash( $_POST['related_task_ids'] ) ) );
			update_post_meta( $post_id, 'related_task_ids', $related_task_ids );
		}

		// Save the board taxonomy.
		if ( ! empty( $board_id ) ) {
			wp_set_post_terms( $post_id, $board_id, 'decker_board', false );
		}
	}

	/**
	 * Display admin notices.
	 */
	public function display_admin_notices() {
		if ( $message = get_transient( 'decker_journal_error' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
			delete_transient( 'decker_journal_error' );
		}
	}

	/**
	 * Render the users meta box.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_users_meta_box( $post ) {
		$users = get_users( array( 'orderby' => 'display_name' ) );
		$assigned_users = get_post_meta( $post->ID, 'journal_users', true );
		?>
		<div id="assigned-users" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $users as $user ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="journal_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the labels meta box.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_labels_meta_box( $post ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);
		$assigned_labels = wp_get_post_terms( $post->ID, 'decker_label', array( 'fields' => 'ids' ) );
		?>
		<div id="decker-labels" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $terms as $term ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="decker_labels[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( is_array( $assigned_labels ) && in_array( $term->term_id, $assigned_labels ) ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Journal_Editor' ) ) {
	new Decker_Journal_Editor();
}
