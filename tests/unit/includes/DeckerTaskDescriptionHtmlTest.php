<?php
/** Conservative HTML preservation checks. @package Decker */

class DeckerTaskDescriptionHtmlTest extends WP_UnitTestCase {

	/** Separators inside quoted, escaped or functional CSS values are data. */
	public function test_css_value_boundaries_survive_separator_normalization() {
		$values = array(
			'font-family:"Semi\"; Colon"',
			'background-image:url("https://example.invalid/a;b(c).png")',
			'font-family:Escaped\\;Name',
			'font-family:"中文 😀"',
		);
		foreach ( $values as $value ) {
			$before = $this->paragraph( $value . '; color:red; ' );
			$after = $this->paragraph( $value . ';color:red' );
			$this->assertTrue( Decker_Task_Description_Html::same_safe_html( $before, $after ), $value );
			$this->assertFalse( Decker_Task_Description_Html::same_safe_html( $before, $this->paragraph( 'color:red' ) ), $value );
		}
	}

	/** Ambiguous CSS must not gain an equivalence through whitespace cleanup. */
	public function test_unfinished_css_strings_and_functions_require_exact_values() {
		foreach ( array( 'font-family:"Unclosed', 'background-image:url(unclosed', 'font-family:Escape\\' ) as $value ) {
			$before = $this->paragraph( $value . '; color:red;' );
			$after = $this->paragraph( $value . ';color:red' );
			$this->assertFalse( Decker_Task_Description_Html::same_safe_html( $before, $after ), $value );
		}
	}

	/**
	 * Build an attribute without confusing CSS quotes with HTML delimiters.
	 *
	 * @param string $css Original inline CSS.
	 * @return string HTML fragment.
	 */
	private function paragraph( string $css ): string {
		return '<p style="' . htmlspecialchars( $css, ENT_QUOTES, 'UTF-8' ) . '">Text</p>';
	}
}
