<?php
/**
 * Colour picker shown on the term screens of a Decker taxonomy.
 *
 * Boards and labels both offer the same colour control, so the markup, the
 * nonce and the save guards live here once. A taxonomy that needs extra
 * controls alongside the colour subclasses this and fills in the hooks below,
 * which keeps a single nonce field and a single save guard per form.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Term_Color_Field
 */
class Decker_Term_Color_Field {

	/**
	 * Taxonomy this instance is attached to.
	 *
	 * @access protected
	 * @var    string
	 */
	protected $taxonomy;

	/**
	 * Attach the colour field to a taxonomy's term screens.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function __construct( $taxonomy ) {
		$this->taxonomy = $taxonomy;

		add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_field' ), 10, 2 );
		add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_field' ), 10, 2 );
		add_action( "created_{$taxonomy}", array( $this, 'save' ), 10, 2 );
		add_action( "edited_{$taxonomy}", array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Render the colour field on the "add term" form.
	 */
	public function render_add_field() {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );
		?>
		<div class="form-field term-color-wrap">
			<label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label>
			<input name="term-color" id="term-color" type="color" value="">
		</div>
		<?php
		$this->render_extra_add_fields();
	}

	/**
	 * Render the colour field on the "edit term" form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	public function render_edit_field( $term ) {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );

		$color = get_term_meta( $term->term_id, 'term-color', true );
		?>
		<tr class="form-field term-color-wrap">
			<th scope="row"><label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
			<td>
				<input name="term-color" id="term-color" type="color" value="<?php echo esc_attr( $color ) ? esc_attr( $color ) : ''; ?>">
			</td>
		</tr>
		<?php
		$this->render_extra_edit_fields( $term );
	}

	/**
	 * Persist the colour when a term is created or edited.
	 *
	 * @param int $term_id The term ID.
	 */
	public function save( $term_id ) {
		// Check if nonce is set and verified.
		if ( isset( $_POST['decker_term_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['decker_term_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'decker_term_action' ) ) {
				return;
			}
		} else {
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		if ( isset( $_POST['term-color'] ) ) {
			$term_color = sanitize_hex_color( wp_unslash( $_POST['term-color'] ) );
			update_term_meta( $term_id, 'term-color', $term_color );
		}

		// Hand the submission to the subclass so it never has to re-check the
		// nonce or reach into the superglobal itself.
		$this->save_extra_fields( $term_id, wp_unslash( $_POST ) );
	}

	/**
	 * Render any additional controls on the "add term" form.
	 *
	 * Subclasses override this; the colour field alone needs nothing extra.
	 */
	protected function render_extra_add_fields() {
	}

	/**
	 * Render any additional controls on the "edit term" form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	protected function render_extra_edit_fields( $term ) {
	}

	/**
	 * Persist any additional controls.
	 *
	 * Only called once the nonce and capability checks have passed.
	 *
	 * @param int   $term_id The term ID.
	 * @param array $posted  The unslashed submission.
	 */
	protected function save_extra_fields( $term_id, array $posted ) {
	}
}
