<?php
/**
 * Term screen controls for the board taxonomy.
 *
 * Boards carry the shared colour picker plus two visibility toggles that decide
 * whether the board shows up in the Boards and Knowledge Base sidebars.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Board_Term_Fields
 */
class Decker_Board_Term_Fields extends Decker_Term_Color_Field {

	/**
	 * Render the visibility toggles on the "add term" form.
	 *
	 * A new board is visible in both sections unless the editor says otherwise.
	 */
	protected function render_extra_add_fields() {
		?>
		<div class="form-field term-show-in-boards-wrap">
			<label for="term-show-in-boards">
				<input type="checkbox" name="term-show-in-boards" id="term-show-in-boards" value="1" checked>
				<?php esc_html_e( 'Show in Boards', 'decker' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Display this board in the Boards section of the sidebar', 'decker' ); ?></p>
		</div>
		<div class="form-field term-show-in-kb-wrap">
			<label for="term-show-in-kb">
				<input type="checkbox" name="term-show-in-kb" id="term-show-in-kb" value="1" checked>
				<?php esc_html_e( 'Show in Knowledge Base', 'decker' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Display this board in the Knowledge Base section of the sidebar', 'decker' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the visibility toggles on the "edit term" form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	protected function render_extra_edit_fields( $term ) {
		$term_id        = $term->term_id;
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb     = get_term_meta( $term_id, 'term-show-in-kb', true );

		// Default to true if not set.
		if ( '' === $show_in_boards ) {
			$show_in_boards = '1';
		}
		if ( '' === $show_in_kb ) {
			$show_in_kb = '1';
		}
		?>
		<tr class="form-field term-show-in-boards-wrap">
			<th scope="row"><?php esc_html_e( 'Visibility', 'decker' ); ?></th>
			<td>
				<label for="term-show-in-boards">
					<input type="checkbox" name="term-show-in-boards" id="term-show-in-boards" value="1" <?php checked( $show_in_boards, '1' ); ?>>
					<?php esc_html_e( 'Show in Boards', 'decker' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Display this board in the Boards section of the sidebar', 'decker' ); ?></p>

				<label for="term-show-in-kb">
					<input type="checkbox" name="term-show-in-kb" id="term-show-in-kb" value="1" <?php checked( $show_in_kb, '1' ); ?>>
					<?php esc_html_e( 'Show in Knowledge Base', 'decker' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Display this board in the Knowledge Base section of the sidebar', 'decker' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the visibility toggles.
	 *
	 * An unchecked box submits nothing, so absence means "hidden" rather than
	 * "leave alone"; both keys are always written.
	 *
	 * @param int   $term_id The term ID.
	 * @param array $posted  The unslashed submission.
	 */
	protected function save_extra_fields( $term_id, array $posted ) {
		update_term_meta( $term_id, 'term-show-in-boards', isset( $posted['term-show-in-boards'] ) ? '1' : '0' );
		update_term_meta( $term_id, 'term-show-in-kb', isset( $posted['term-show-in-kb'] ) ? '1' : '0' );
	}
}
