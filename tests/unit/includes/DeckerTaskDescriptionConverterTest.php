<?php
/** Conversion tests against anonymous historical structures. @package Decker */
require_once dirname( __DIR__, 2 ) . '/includes/class-decker-description-snapshot.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-decker-task-description-converter.php';

class DeckerTaskDescriptionConverterTest extends WP_UnitTestCase {
	/** Historical fixtures retain each item, including inline trees and empty items. */
	public function test_historical_fixtures_preserve_every_item_and_are_idempotent() {
		foreach ( glob( dirname( __DIR__, 2 ) . '/fixtures/task-descriptions/*.html' ) as $file ) {
			$input = file_get_contents( $file );
			$output = Decker_Task_Description_Converter::convert( $input );
			$this->assertSame( Decker_Description_Snapshot::items( $input ), Decker_Description_Snapshot::items( $output ), basename( $file ) );
			$this->assertSame( $output, Decker_Task_Description_Converter::convert( $output ), basename( $file ) );
			$this->assertTrue( Decker_Description_Snapshot::preserves_inline_styles( $input, $output ), basename( $file ) );
			foreach ( array( 'text', 'code', 'resources' ) as $check ) {
				$this->assertSame( Decker_Description_Snapshot::$check( $input ), Decker_Description_Snapshot::$check( $output ), basename( $file ) . ': ' . $check );
			}
			$this->assertStringNotContainsString( 'ql-', $output, basename( $file ) );
		}
	}

	/** Classic, visually empty markup and plain text need no reserialization. */
	public function test_identity_including_empty_markup() {
		foreach ( array( '', 'Plain 😀 & text', "<p><br></p>\n<p> </p>", '<ol start="5"><li><del>Old</del></li></ol>', '<ul><li data-list="checked"><br></li><li data-list="unchecked"></li></ul>', '<img alt="Existing missing source">' ) as $html ) {
			$this->assertSame( $html, Decker_Task_Description_Converter::convert( $html ) );
		}
	}

	/** Blank checked and pending elements remain distinct. */
	public function test_empty_items_inline_image_and_unicode() {
		$input = '<ol><li data-list="checked"><span class="ql-ui"></span><br></li><li data-list="unchecked"><span class="ql-ui"></span></li><li data-list="checked"><strong>😀 &amp; "quote"</strong><a href="https://example.invalid/?a=1&amp;b=2">C:\\tasks</a><img src="https://example.invalid/image.png" alt="Image"></li></ol>';
		$output = Decker_Task_Description_Converter::convert( $input );
		$this->assertSame( Decker_Description_Snapshot::items( $input ), Decker_Description_Snapshot::items( $output ) );
		$this->assertStringStartsWith( '<ul>', $output );
		$this->assertStringNotContainsString( 'ql-ui', $output );
	}

	/** Item nesting and paragraph indentation are different operations. */
	public function test_list_hierarchy_and_paragraph_indentation() {
		$html = '<p class="ql-indent-2">Paragraph</p><ol><li data-list="ordered">Parent</li><li class="ql-indent-1" data-list="checked">Child</li><li class="ql-indent-1" data-list="unchecked">Sibling</li><li data-list="ordered">Next parent</li></ol>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$this->assertSame( array( null, 0, 0, null ), array_column( Decker_Description_Snapshot::items( $output ), 'parent' ) );
		$this->assertStringContainsString( 'padding-left: 6em', $output );
		$this->assertSame( 1, substr_count( $output, '<ol' ) );
		$xpath = new DOMXPath( Decker_Description_Snapshot::document( $output ) );
		$this->assertSame( 2, $xpath->query( '/html/body/ol/li' )->length );
	}

	/** libxml's HTML serializer must not alter real legacy URL attributes. */
	public function test_links_keep_spaces_and_unicode_in_the_attribute_value() {
		$html = '<ol><li data-list="bullet"><a href="https://example.invalid/ítem?x=1&amp;y=2 ">Link</a></li></ol>';
		$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( Decker_Task_Description_Converter::convert( $html ) ) );
	}

	/** Ordinals continue across mixed types, and reset after a paragraph. */
	public function test_numbering_across_mixed_lists() {
		$html = '<ol id="list-anchor"><li data-list="ordered">One</li><li data-list="bullet">Bullet</li><li data-list="checked">Done</li><li data-list="ordered">Two</li></ol><p>Reset</p><ol><li data-list="ordered">One again</li></ol>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$this->assertSame( 1, substr_count( $output, 'start="2"' ) );
		$this->assertSame( 3, substr_count( $output, '<ol' ) );
		$this->assertSame( 5, count( Decker_Description_Snapshot::items( $output ) ) );
		$this->assertSame( 1, substr_count( $output, 'id="list-anchor"' ) );
	}

	/** Explicit restarts and item values override inherited Quill counters. */
	public function test_explicit_numbering_survives_mixed_types() {
		$html = '<ol><li data-list="ordered">One</li></ol><ol start="5" type="A"><li data-list="ordered" value="8">Eight</li><li data-list="bullet">Bullet</li><li data-list="ordered">Nine</li></ol>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$this->assertStringContainsString( 'start="5" type="A"', $output );
		$this->assertStringContainsString( 'value="8"', $output );
		$this->assertStringContainsString( 'start="9" type="A"', $output );
		$this->assertSame( $output, Decker_Task_Description_Converter::convert( $output ) );
	}

	/** Node boundaries encode line breaks even without literal newlines. */
	public function test_code_preserves_blank_lines_tabs_spaces_and_characters() {
		$html = '<div class="ql-code-block-container"><div class="ql-code-block">  C:\\path &lt;x&gt;</div><div class="ql-code-block"><br></div><div class="ql-code-block">' . "\t" . '😀 &amp; "quoted"  </div><div class="ql-code-block"><br></div></div>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$document = Decker_Description_Snapshot::document( $output );
		$this->assertSame( "  C:\\path <x>\n\n\t😀 & \"quoted\"  \n", $document->getElementsByTagName( 'code' )->item( 0 )->textContent );
		$this->assertSame( 1, $document->getElementsByTagName( 'pre' )->length );
	}

	/** Known formats become standard CSS; unrelated classes remain. */
	public function test_presentation_formats() {
		$output = Decker_Task_Description_Converter::convert( '<p class="external ql-align-justify ql-direction-rtl"><span class="ql-font-monospace ql-size-large ql-color-red">Text</span></p>' );
		foreach ( array( 'class="external"', 'text-align: justify', 'dir="rtl"', 'font-family: Monaco, Courier New, monospace', 'font-size: 1.5em', 'color: #e60000' ) as $expected ) {
			$this->assertStringContainsString( $expected, $output );
		}
	}

	/** HTML5 accepted by WordPress must coexist with real checklist markup. */
	public function test_html5_media_and_checklists_survive_the_html4_parser() {
		$html = '<figure><img src="https://example.invalid/image.png"><figcaption>Caption</figcaption></figure><ol><li data-list="checked">Done</li></ol>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$this->assertStringContainsString( '<figure>', $output );
		$this->assertStringContainsString( '<figcaption>Caption</figcaption>', $output );
		$this->assertSame( $output, Decker_Task_Description_Converter::convert( $output ) );
		$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( $output ) );
	}

	/** Never silently repair unknown or ambiguous historical structures. */
	public function test_ambiguous_inputs_fail() {
		$inputs = array( '<p class="ql-align-center" style="text-align:right">Ambiguous formatting</p>', '<ul><li data-list="checked"><span class="ql-ui" title="Content"></span></li></ul>', '<ol><li data-list="unknown">Text</li></ol>', '<ol><li class="ql-indent-2" data-list="checked">Orphan</li></ol>', '<p class="ql-unknown">Text</p>', '<ol><li data-list="checked"><span class="ql-ui">Actual text</span></li></ol>', '<ol><li data-list="checked">Broken</div></ol>' );
		$inputs = array_merge( $inputs, array( '<p class="ql-align-center">' . "\xff" . '</p>', '<ol><div class="ql-align-left">Unidentified list child</div></ol>', '<ol reversed><li data-list="ordered">Reversed legacy list</li></ol>', '<div class="ql-code-block-container"><p>Unknown line</p></div>', '<div class="ql-code-block-container"><div class="ql-code-block"><a href="https://example.invalid/">Unknown code structure</a></div></div>' ) );
		foreach ( $inputs as $input ) {
			try {
				Decker_Task_Description_Converter::convert( $input );
				$this->fail( 'Expected a visible conversion error.' );
			} catch ( UnexpectedValueException $error ) {
				$this->assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_code_highlighting_container_whitespace_and_html_comments() {
		$html = '<div class="ql-code-block-container">' . "\n  " . '<div class="ql-code-block"><span class="hljs-keyword">if</span> (x)</div>' . "\n " . '<div class="ql-code-block"><br></div>' . "\n" . '</div><pre class="ql-syntax">  old code</pre><p class="ql-align-left">Text<!--keep this comment--></p>';
		$output = Decker_Task_Description_Converter::convert( $html );
		$this->assertStringContainsString( "<pre><code>if (x)\n</code></pre>", $output );
		$this->assertStringContainsString( '<pre>  old code</pre>', $output );
		$this->assertStringContainsString( '<!--keep this comment-->', $output );
		$this->assertSame( $output, Decker_Task_Description_Converter::convert( $output ) );
	}
}
