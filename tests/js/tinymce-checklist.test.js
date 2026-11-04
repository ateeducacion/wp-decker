/**
 * Tests for the classic-editor checklist support (DeckerChecklist).
 *
 * The plugin edits the same markup Quill stores (<li data-list>), so these
 * tests exercise the DOM logic against jsdom with a minimal TinyMCE fake.
 *
 * @package Decker
 */

import { beforeAll, beforeEach, describe, expect, test, vi } from 'vitest';

beforeAll( async () => {
	// The script registers window.DeckerChecklist when evaluated. Importing it
	// (rather than evaluating its source by hand) lets the coverage report see it.
	await import( '../../public/assets/js/tinymce-checklist.js' );
} );

/**
 * Build a minimal TinyMCE-like editor over a jsdom container.
 *
 * @param {HTMLElement} container Root element acting as the editor body.
 * @return {Object} Fake editor plus test helpers.
 */
function createFakeEditor( container ) {
	const handlers = {};
	let caretNode = container;

	const editor = {
		dom: {
			getParent( node, selector ) {
				let el = node && node.nodeType === Node.TEXT_NODE ? node.parentElement : node;
				while ( el && el !== container.parentElement ) {
					if ( el.matches && el.matches( selector ) ) {
						return el;
					}
					el = el.parentElement;
				}
				return null;
			},
		},
		selection: {
			getStart: () => caretNode,
		},
		undoManager: {
			transact: ( callback ) => callback(),
		},
		execCommand: vi.fn(),
		nodeChanged: vi.fn(),
		fire: vi.fn(),
		addButton: vi.fn(),
		on( name, callback ) {
			name.split( ' ' ).forEach( ( eventName ) => {
				( handlers[ eventName ] = handlers[ eventName ] || [] ).push( callback );
			} );
		},
	};

	return {
		editor,
		setCaret( node ) {
			caretNode = node;
		},
		emit( name, event ) {
			( handlers[ name ] || [] ).forEach( ( callback ) => callback( event ) );
		},
	};
}

describe( 'DeckerChecklist pure helpers', () => {
	let ul;

	beforeEach( () => {
		document.body.innerHTML = `
			<ul id="list">
				<li>uno</li>
				<li data-list="checked">dos</li>
				<li>tres</li>
			</ul>
		`;
		ul = document.getElementById( 'list' );
	} );

	test.each( [ 'bullet', 'ordered', 'other', '' ] )( 'does not treat data-list=%s as checklist', ( state ) => {
		ul.children[ 0 ].setAttribute( 'data-list', state );
		expect( window.DeckerChecklist.isChecklistItem( ul.children[ 0 ] ) ).toBe( false );
		window.DeckerChecklist.toggleItemState( ul.children[ 0 ] );
		expect( ul.children[ 0 ].getAttribute( 'data-list' ) ).toBe( state );
	} );

	test( 'detection and conversion both affect only immediate items', () => {
		ul.innerHTML = '<li>Parent<ul><li data-list="checked"><strong>Nested</strong></li></ul></li><li>Sibling</li>';
		expect( window.DeckerChecklist.listHasChecklist( ul ) ).toBe( false );
		window.DeckerChecklist.setListChecklist( ul, true );
		expect( ul.children[ 0 ].getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( ul.children[ 0 ].querySelector( 'li' ).getAttribute( 'data-list' ) ).toBe( 'checked' );
		window.DeckerChecklist.setListChecklist( ul, false );
		expect( ul.children[ 0 ].querySelector( 'li' ).getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'setListChecklist enables every item and preserves checked state', () => {
		window.DeckerChecklist.setListChecklist( ul, true );

		const states = Array.from( ul.children ).map( ( li ) => li.getAttribute( 'data-list' ) );
		expect( states ).toEqual( [ 'unchecked', 'checked', 'unchecked' ] );
	} );

	test( 'setListChecklist disables all items', () => {
		window.DeckerChecklist.setListChecklist( ul, false );

		Array.from( ul.children ).forEach( ( li ) => {
			expect( li.hasAttribute( 'data-list' ) ).toBe( false );
		} );
	} );

	test( 'toggleItemState flips checked and unchecked', () => {
		const li = ul.children[ 1 ];

		window.DeckerChecklist.toggleItemState( li );
		expect( li.getAttribute( 'data-list' ) ).toBe( 'unchecked' );

		window.DeckerChecklist.toggleItemState( li );
		expect( li.getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'isChecklistItem and listHasChecklist detect the markup', () => {
		expect( window.DeckerChecklist.isChecklistItem( ul.children[ 1 ] ) ).toBe( true );
		expect( window.DeckerChecklist.isChecklistItem( ul.children[ 0 ] ) ).toBe( false );
		expect( window.DeckerChecklist.listHasChecklist( ul ) ).toBe( true );

		window.DeckerChecklist.setListChecklist( ul, false );
		expect( window.DeckerChecklist.listHasChecklist( ul ) ).toBe( false );
	} );
} );

describe( 'DeckerChecklist editor behavior', () => {
	let container;
	let fake;

	beforeEach( () => {
		document.body.innerHTML = `
			<div id="editor-body">
				<ul id="list">
					<li id="a" data-list="unchecked">uno</li>
					<li id="b" data-list="checked">dos</li>
				</ul>
				<p id="outside">fuera</p>
			</div>
		`;
		container = document.getElementById( 'editor-body' );
		fake = createFakeEditor( container );
		window.DeckerChecklist.attach( fake.editor );
	} );

	test( 'attach registers the TinyMCE 4 toolbar button', () => {
		expect( fake.editor.addButton ).toHaveBeenCalledWith(
			'decker_checklist',
			expect.objectContaining( { tooltip: 'Checklist' } )
		);
	} );

	test( 'toggleChecklist strips an existing checklist', () => {
		fake.setCaret( document.getElementById( 'a' ) );

		window.DeckerChecklist.toggleChecklist( fake.editor );

		expect( document.getElementById( 'a' ).hasAttribute( 'data-list' ) ).toBe( false );
		expect( document.getElementById( 'b' ).hasAttribute( 'data-list' ) ).toBe( false );
		expect( fake.editor.fire ).toHaveBeenCalledWith( 'change' );
	} );

	test( 'toggleChecklist converts a plain list into a checklist', () => {
		window.DeckerChecklist.setListChecklist( document.getElementById( 'list' ), false );
		fake.setCaret( document.getElementById( 'a' ) );

		window.DeckerChecklist.toggleChecklist( fake.editor );

		expect( document.getElementById( 'a' ).getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( document.getElementById( 'b' ).getAttribute( 'data-list' ) ).toBe( 'unchecked' );
	} );

	test( 'numbered list conversion retains presentation and identity attributes', () => {
		container.innerHTML = '<ol id="numbered" class="sample" style="text-align: right" dir="rtl" lang="es" start="5" type="a" reversed><li id="first">Item</li></ol>';
		fake.setCaret( document.getElementById( 'first' ) );

		window.DeckerChecklist.toggleChecklist( fake.editor );

		const list = document.getElementById( 'numbered' );
		expect( list.nodeName ).toBe( 'UL' );
		expect( Array.from( list.attributes, ( attribute ) => [ attribute.name, attribute.value ] ) ).toEqual( [
			[ 'id', 'numbered' ], [ 'class', 'sample' ], [ 'style', 'text-align: right' ], [ 'dir', 'rtl' ], [ 'lang', 'es' ],
		] );
		expect( document.getElementById( 'first' ).getAttribute( 'data-list' ) ).toBe( 'unchecked' );
	} );

	test( 'click on the marker area toggles the item', () => {
		const li = document.getElementById( 'b' );
		const event = { target: li, offsetX: 10, preventDefault: vi.fn() };

		fake.emit( 'click', event );

		expect( li.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( event.preventDefault ).toHaveBeenCalled();
	} );

	test( 'click on the item text does not toggle', () => {
		const li = document.getElementById( 'b' );

		fake.emit( 'click', { target: li, offsetX: 120, preventDefault: vi.fn() } );

		expect( li.getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'Enter creates an unchecked item when the attribute was cloned', () => {
		const list = document.getElementById( 'list' );
		const fresh = document.createElement( 'li' );
		fresh.setAttribute( 'data-list', 'checked' );
		list.appendChild( fresh );
		fake.setCaret( fresh );

		fake.emit( 'keyup', { keyCode: 13 } );

		expect( fresh.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
	} );

	test( 'Enter marks a fresh attribute-less item after a checklist item', () => {
		const list = document.getElementById( 'list' );
		const fresh = document.createElement( 'li' );
		list.appendChild( fresh );
		fake.setCaret( fresh );

		fake.emit( 'keyup', { keyCode: 13 } );

		expect( fresh.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
	} );

	test( 'Enter leaves non-empty checked items untouched', () => {
		const li = document.getElementById( 'b' );
		fake.setCaret( li );

		fake.emit( 'keyup', { keyCode: 13 } );

		expect( li.getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'native list commands strip the checklist state', () => {
		fake.setCaret( document.getElementById( 'a' ) );

		fake.emit( 'ExecCommand', { command: 'InsertOrderedList' } );

		expect( document.getElementById( 'a' ).hasAttribute( 'data-list' ) ).toBe( false );
		expect( document.getElementById( 'b' ).hasAttribute( 'data-list' ) ).toBe( false );
	} );

	test( 'Enter in the middle keeps the original state and creates a pending fragment', () => {
		const original = document.getElementById( 'b' );
		original.innerHTML = '<strong>Before</strong> after';
		fake.setCaret( original );
		fake.emit( 'keydown', { keyCode: 13 } );
		const fresh = document.createElement( 'li' );
		fresh.setAttribute( 'data-list', 'checked' );
		fresh.textContent = 'after';
		original.innerHTML = '<strong>Before</strong>';
		original.after( fresh );
		fake.setCaret( fresh );
		fake.emit( 'input', {} );
		fake.emit( 'keyup', { keyCode: 13 } );
		expect( original.getAttribute( 'data-list' ) ).toBe( 'checked' );
		expect( fresh.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( original.querySelector( 'strong' ).textContent ).toBe( 'Before' );
	} );

	test( 'existing empty completed items stay completed during ordinary input', () => {
		const original = document.getElementById( 'b' );
		original.innerHTML = '<br>';
		fake.setCaret( original );
		fake.emit( 'input', {} );
		expect( original.getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'readonly blocks marker, toolbar, Enter and native-list mutations', () => {
		fake.editor.readonly = true;
		fake.setCaret( document.getElementById( 'b' ) );
		const before = container.innerHTML;
		fake.emit( 'click', { target: document.getElementById( 'b' ), offsetX: 5, preventDefault: vi.fn() } );
		window.DeckerChecklist.toggleChecklist( fake.editor );
		fake.emit( 'keyup', { keyCode: 13 } );
		fake.emit( 'ExecCommand', { command: 'InsertUnorderedList' } );
		const itemButton = fake.editor.addButton.mock.calls.find( ( args ) => args[ 0 ] === 'decker_check_item' );
		itemButton[ 1 ].onclick();
		expect( container.innerHTML ).toBe( before );
	} );

	test( 'toolbar item action preserves rich content and reports a change', () => {
		const li = document.getElementById( 'a' );
		li.innerHTML = '<strong>Rich</strong> <a href="https://example.invalid/?a=1&amp;b=2">link</a><img src="https://example.invalid/image.png">';
		const before = li.innerHTML;
		fake.setCaret( li );
		const itemButton = fake.editor.addButton.mock.calls.find( ( args ) => args[ 0 ] === 'decker_check_item' );
		itemButton[ 1 ].onclick();
		expect( li.getAttribute( 'data-list' ) ).toBe( 'checked' );
		expect( li.innerHTML ).toBe( before );
		expect( fake.editor.fire ).toHaveBeenCalledWith( 'change' );
	} );

	test( 'the undo snapshot includes the new pending state after Enter', () => {
		const original = document.getElementById( 'a' );
		original.setAttribute( 'data-list', 'checked' );
		fake.setCaret( original );
		fake.emit( 'keydown', { keyCode: 13 } );
		const next = original.cloneNode( true );
		original.after( next );
		fake.setCaret( next );
		fake.editor.getContent = () => container.innerHTML;
		const event = { level: { content: container.innerHTML } };
		fake.emit( 'BeforeAddUndo', event );
		expect( original.getAttribute( 'data-list' ) ).toBe( 'checked' );
		expect( next.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( event.level.content ).toBe( container.innerHTML );
	} );

	test( 'creating outside a list preserves adjacent items merged by TinyMCE', () => {
		container.innerHTML = '<p>Create</p><ul><li>Ordinary</li></ul>';
		fake.editor.getBody = () => container;
		fake.setCaret( container.querySelector( 'p' ) );
		fake.editor.execCommand = () => {
			const item = document.createElement( 'li' );
			item.textContent = 'Create';
			container.querySelector( 'ul' ).prepend( item );
			container.querySelector( 'p' ).remove();
			fake.setCaret( item );
		};
		window.DeckerChecklist.toggleChecklist( fake.editor );
		expect( [ ...container.querySelectorAll( 'li' ) ].map( ( li ) => li.getAttribute( 'data-list' ) ) ).toEqual( [ 'unchecked', null ] );
	} );


	test( 'leaving a nested empty item cannot uncheck an existing parent', () => {
		container.innerHTML = '<ul><li data-list="checked">Parent<ul><li data-list="unchecked"><br></li></ul></li></ul>';
		fake.editor.getBody = () => container;
		const parent = container.querySelector( 'li' );
		const child = parent.querySelector( 'li' );
		fake.setCaret( child );
		fake.emit( 'keydown', { keyCode: 13 } );
		child.parentElement.remove();
		fake.setCaret( parent );
		fake.emit( 'keyup', { keyCode: 13 } );
		expect( parent.getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'toolbar item state exposes checked status and disables after a lost lock', () => {
		const element = document.createElement( 'button' );
		const button = { active: vi.fn(), disabled: vi.fn(), getEl: () => element };
		const config = fake.editor.addButton.mock.calls.find( ( args ) => args[ 0 ] === 'decker_check_item' )[ 1 ];
		config.onPostRender.call( button );
		fake.setCaret( document.getElementById( 'b' ) );
		fake.emit( 'NodeChange', {} );
		expect( button.active ).toHaveBeenLastCalledWith( true );
		expect( button.disabled ).toHaveBeenLastCalledWith( false );
		expect( element.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		fake.editor.readonly = true;
		fake.emit( 'NodeChange', {} );
		expect( button.disabled ).toHaveBeenLastCalledWith( true );
		fake.setCaret( document.getElementById( 'outside' ) );
		fake.emit( 'NodeChange', {} );
		expect( element.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
	} );

	test( 'the keyboard shortcut toggles the caret item and uses dirty tracking', () => {
		fake.editor.addShortcut = vi.fn();
		window.DeckerChecklist.attach( fake.editor );
		fake.setCaret( document.getElementById( 'b' ) );
		const [ keys, , action ] = fake.editor.addShortcut.mock.calls[ 0 ];
		expect( keys ).toBe( 'meta+shift+32' );
		action();
		expect( document.getElementById( 'b' ).getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( fake.editor.fire ).toHaveBeenCalledWith( 'change' );
	} );

	test( 'creating a checklist cannot strip an adjacent checklist through a native command event', () => {
		fake.setCaret( document.getElementById( 'b' ) );
		fake.editor.deckerCreatingChecklist = true;
		fake.emit( 'ExecCommand', { command: 'InsertUnorderedList' } );
		expect( document.getElementById( 'b' ).getAttribute( 'data-list' ) ).toBe( 'checked' );
	} );

	test( 'read-only marker clicks cannot mutate an item', () => {
		const item = document.getElementById( 'b' );
		fake.editor.readonly = true;
		fake.emit( 'click', { target: item, offsetX: 10, preventDefault: vi.fn() } );
		expect( item.getAttribute( 'data-list' ) ).toBe( 'checked' );
		expect( fake.editor.fire ).not.toHaveBeenCalledWith( 'change' );
	} );

} );
