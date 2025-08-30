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
		add_filter( 'default_content', array( $this, 'set_default_editor_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_decker_journal', array( $this, 'save_meta_box_data' ), 10, 2 );
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
			<label for="attendees"><?php esc_html_e( 'Attendees (one per line):', 'decker' ); ?></label>
			<textarea id="attendees" name="attendees" class="widefat" rows="5"><?php echo esc_textarea( implode( "\n", $attendees ) ); ?></textarea>
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

		$fields = array(
			'journal_date',
			'topic',
			'attendees',
			'agreements',
			'derived_tasks',
			'notes',
			'related_task_ids',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) { // WPCS: input var ok, CSRF ok.
				$value = wp_unslash( $_POST[ $field ] );
				switch ( $field ) {
					case 'attendees':
					case 'agreements':
						$value = array_map( 'sanitize_text_field', explode( "\n", $value ) );
						break;
					case 'derived_tasks':
					case 'notes':
						$value = json_decode( $value, true );
						break;
					case 'related_task_ids':
						$value = array_map( 'absint', explode( ',', $value ) );
						break;
					case 'topic':
						$value = sanitize_text_field( $value );
						break;
					case 'journal_date':
						$value = sanitize_text_field( $value );
						break;
				}
				update_post_meta( $post_id, $field, $value );
			}
		}

		// Save the board taxonomy.
		$board_id = isset( $_POST['decker_board'] ) ? absint( $_POST['decker_board'] ) : 0;
		if ( ! empty( $board_id ) ) {
			wp_set_post_terms( $post_id, $board_id, 'decker_board', false );
		}
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Journal_Editor' ) ) {
	new Decker_Journal_Editor();
}
