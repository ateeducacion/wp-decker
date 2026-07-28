<?php
/**
 * Characterization tests for the term fields on the board and label screens.
 *
 * These drive the WordPress hooks rather than the classes behind them, so the
 * fields can be moved to a different owner without the tests noticing. What is
 * pinned is the rendered markup, the saved meta, and the guards around saving.
 *
 * @package Decker
 */

class DeckerTermFieldsTest extends Decker_Test_Base {

	/**
	 * Administrator able to manage terms.
	 *
	 * @var int
	 */
	private $administrator;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Capture the markup a term form hook produces.
	 *
	 * @param string $hook Action name.
	 * @param mixed  $arg  Optional argument passed to the hook.
	 * @return string Rendered markup.
	 */
	private function render( $hook, $arg = null ) {
		ob_start();

		if ( null === $arg ) {
			do_action( $hook );
		} else {
			do_action( $hook, $arg );
		}

		return ob_get_clean();
	}

	/**
	 * Create a term and return it.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term name.
	 * @return WP_Term
	 */
	private function make_term( $taxonomy, $name ) {
		$ids = wp_insert_term( $name, $taxonomy );
		$this->assertNotWPError( $ids );

		return get_term( $ids['term_id'], $taxonomy );
	}

	/**
	 * The board add form offers a colour picker and both visibility toggles.
	 */
	public function test_board_add_form_fields() {
		$html = $this->render( 'decker_board_add_form_fields' );

		$this->assertStringContainsString( 'name="term-color"', $html );
		$this->assertStringContainsString( 'type="color"', $html );
		$this->assertStringContainsString( 'name="term-show-in-boards"', $html );
		$this->assertStringContainsString( 'name="term-show-in-kb"', $html );

		// Both toggles default to on when creating a board.
		$this->assertSame( 2, substr_count( $html, 'value="1" checked' ) );
	}

	/**
	 * The label add form offers a colour picker and no visibility toggles.
	 */
	public function test_label_add_form_fields() {
		$html = $this->render( 'decker_label_add_form_fields' );

		$this->assertStringContainsString( 'name="term-color"', $html );
		$this->assertStringContainsString( 'type="color"', $html );
		$this->assertStringNotContainsString( 'term-show-in-boards', $html );
		$this->assertStringNotContainsString( 'term-show-in-kb', $html );
	}

	/**
	 * Exactly one nonce field is emitted per form.
	 *
	 * Splitting the fields across several hook callbacks would be an easy way to
	 * end up printing the nonce twice, so this is pinned explicitly.
	 *
	 * @dataProvider term_form_hook_provider
	 *
	 * @param string $hook       Action name.
	 * @param bool   $needs_term Whether the hook receives a term.
	 * @param string $taxonomy   Taxonomy slug.
	 */
	public function test_exactly_one_nonce_field_per_form( $hook, $needs_term, $taxonomy ) {
		$arg = $needs_term ? $this->make_term( $taxonomy, 'Nonce fixture' ) : null;

		$html = $this->render( $hook, $arg );

		$this->assertSame(
			1,
			substr_count( $html, 'name="decker_term_nonce"' ),
			"Hook '{$hook}' should print the nonce field exactly once."
		);
	}

	/**
	 * Every term form hook, and whether it receives a term.
	 *
	 * @return array<string, array{0:string,1:bool,2:string}>
	 */
	public function term_form_hook_provider() {
		return array(
			'board add'  => array( 'decker_board_add_form_fields', false, 'decker_board' ),
			'board edit' => array( 'decker_board_edit_form_fields', true, 'decker_board' ),
			'label add'  => array( 'decker_label_add_form_fields', false, 'decker_label' ),
			'label edit' => array( 'decker_label_edit_form_fields', true, 'decker_label' ),
		);
	}

	/**
	 * The board edit form reflects the stored colour and visibility meta.
	 */
	public function test_board_edit_form_reflects_stored_meta() {
		$term = $this->make_term( 'decker_board', 'Stored board' );

		update_term_meta( $term->term_id, 'term-color', '#abcdef' );
		update_term_meta( $term->term_id, 'term-show-in-boards', '0' );
		update_term_meta( $term->term_id, 'term-show-in-kb', '1' );

		$html = $this->render( 'decker_board_edit_form_fields', $term );

		$this->assertStringContainsString( 'value="#abcdef"', $html );

		// Only the knowledge base toggle should come back checked.
		$this->assertSame( 1, substr_count( $html, "checked='checked'" ) );
		$this->assertMatchesRegularExpression(
			'/term-show-in-kb"[^>]*checked=/',
			$html,
			'The knowledge base toggle should be checked.'
		);
	}

	/**
	 * Visibility toggles default to on for a board that has no stored preference.
	 */
	public function test_board_edit_form_defaults_visibility_to_on() {
		$term = $this->make_term( 'decker_board', 'Fresh board' );

		$html = $this->render( 'decker_board_edit_form_fields', $term );

		$this->assertSame( 2, substr_count( $html, "checked='checked'" ) );
	}

	/**
	 * The label edit form shows the colour and nothing about visibility.
	 */
	public function test_label_edit_form_shows_colour_only() {
		$term = $this->make_term( 'decker_label', 'Stored label' );
		update_term_meta( $term->term_id, 'term-color', '#123456' );

		$html = $this->render( 'decker_label_edit_form_fields', $term );

		$this->assertStringContainsString( 'value="#123456"', $html );
		$this->assertStringNotContainsString( 'term-show-in-boards', $html );
	}

	/**
	 * Saving a board stores the sanitized colour and both visibility flags.
	 */
	public function test_saving_a_board_stores_colour_and_visibility() {
		$term = $this->make_term( 'decker_board', 'Saved board' );

		$_POST = array(
			'decker_term_nonce'    => wp_create_nonce( 'decker_term_action' ),
			'term-color'           => '#00ff00',
			'term-show-in-boards'  => '1',
			// term-show-in-kb intentionally absent: an unchecked box sends nothing.
		);

		do_action( 'edited_decker_board', $term->term_id, 0 );

		$this->assertSame( '#00ff00', get_term_meta( $term->term_id, 'term-color', true ) );
		$this->assertSame( '1', get_term_meta( $term->term_id, 'term-show-in-boards', true ) );
		$this->assertSame( '0', get_term_meta( $term->term_id, 'term-show-in-kb', true ) );
	}

	/**
	 * Saving a label stores the colour.
	 */
	public function test_saving_a_label_stores_colour() {
		$term = $this->make_term( 'decker_label', 'Saved label' );

		$_POST = array(
			'decker_term_nonce' => wp_create_nonce( 'decker_term_action' ),
			'term-color'        => '#ff0000',
		);

		do_action( 'edited_decker_label', $term->term_id, 0 );

		$this->assertSame( '#ff0000', get_term_meta( $term->term_id, 'term-color', true ) );
	}

	/**
	 * A colour that is not a valid hex value is rejected by sanitization.
	 */
	public function test_invalid_colour_is_not_stored() {
		$term = $this->make_term( 'decker_board', 'Bad colour' );

		$_POST = array(
			'decker_term_nonce' => wp_create_nonce( 'decker_term_action' ),
			'term-color'        => 'javascript:alert(1)',
		);

		do_action( 'edited_decker_board', $term->term_id, 0 );

		$this->assertEmpty( get_term_meta( $term->term_id, 'term-color', true ) );
	}

	/**
	 * Nothing is saved without a valid nonce.
	 *
	 * @dataProvider missing_nonce_provider
	 *
	 * @param array $post Payload standing in for $_POST.
	 */
	public function test_save_requires_a_valid_nonce( $post ) {
		$term = $this->make_term( 'decker_board', 'Nonce guarded' );

		$_POST = array_merge( array( 'term-color' => '#123123' ), $post );

		do_action( 'edited_decker_board', $term->term_id, 0 );

		$this->assertEmpty( get_term_meta( $term->term_id, 'term-color', true ) );
		$this->assertEmpty( get_term_meta( $term->term_id, 'term-show-in-boards', true ) );
	}

	/**
	 * Payloads that must not pass the nonce guard.
	 *
	 * @return array<string, array{0:array}>
	 */
	public function missing_nonce_provider() {
		return array(
			'no nonce at all' => array( array() ),
			'wrong nonce'     => array( array( 'decker_term_nonce' => 'not-a-real-nonce' ) ),
		);
	}

	/**
	 * A user without the capability cannot change term meta.
	 */
	public function test_save_requires_the_edit_term_capability() {
		$term = $this->make_term( 'decker_board', 'Capability guarded' );

		$nonce = wp_create_nonce( 'decker_term_action' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'decker_term_nonce' => $nonce,
			'term-color'        => '#654321',
		);

		do_action( 'edited_decker_board', $term->term_id, 0 );

		$this->assertEmpty( get_term_meta( $term->term_id, 'term-color', true ) );
	}
}
