/**
 * Tests for the classic-editor checklist support (DeckerChecklist).
 *
 * The plugin edits the same markup Quill stores (<li data-list>), so these
 * tests exercise the DOM logic against jsdom with a minimal TinyMCE fake.
 *
 * @package Decker
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '../../public/assets/js/tinymce-checklist.js' ),
	'utf8'
);

beforeAll( () => {
	// The script registers window.DeckerChecklist when evaluated.
	new Function( source )();
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
		execCommand: jest.fn(),
		nodeChanged: jest.fn(),
		fire: jest.fn(),
		addButton: jest.fn(),
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

	test( 'click on the marker area toggles the item', () => {
		const li = document.getElementById( 'b' );
		const event = { target: li, offsetX: 10, preventDefault: jest.fn() };

		fake.emit( 'click', event );

		expect( li.getAttribute( 'data-list' ) ).toBe( 'unchecked' );
		expect( event.preventDefault ).toHaveBeenCalled();
	} );

	test( 'click on the item text does not toggle', () => {
		const li = document.getElementById( 'b' );

		fake.emit( 'click', { target: li, offsetX: 120, preventDefault: jest.fn() } );

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
} );
