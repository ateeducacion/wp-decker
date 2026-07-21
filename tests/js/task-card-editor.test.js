/**
 * Regression tests for the classic (TinyMCE) task editor integration.
 *
 * Covers the review findings on PR #183: Text-tab content retrieval,
 * read-only initialization, dirty tracking from the quicktags textarea,
 * and orphan-instance cleanup when the modal closes before init.
 *
 * @package Decker
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '../../public/assets/js/task-card.js' ),
	'utf8'
);

function extractFunctionSource( functionName ) {
	const start = source.indexOf( `function ${ functionName }` );
	if ( start === -1 ) {
		throw new Error( `Function ${ functionName } not found` );
	}

	const bodyStart = source.indexOf( '{', start );
	let depth = 0;

	for ( let position = bodyStart; position < source.length; position++ ) {
		const char = source[ position ];
		if ( char === '{' ) {
			depth++;
		} else if ( char === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return source.slice( start, position + 1 );
			}
		}
	}

	throw new Error( `Function ${ functionName } has no closing brace` );
}

function instantiate( functionName, scope ) {
	const keys = Object.keys( scope );
	const values = keys.map( ( key ) => scope[ key ] );
	const src = extractFunctionSource( functionName );
	return new Function( ...keys, `return (${ src });` )( ...values );
}

/**
 * Minimal TinyMCE-like editor capturing event handlers by name.
 */
function createFakeEditor() {
	const handlers = {};
	return {
		editor: {
			on( names, callback ) {
				names.split( ' ' ).forEach( ( name ) => {
					( handlers[ name ] = handlers[ name ] || [] ).push( callback );
				} );
			},
		},
		emit( name, event ) {
			( handlers[ name ] || [] ).forEach( ( callback ) => callback( event ) );
		},
	};
}

describe( 'getTaskDescription', () => {
	beforeEach( () => {
		document.body.innerHTML = '<form id="f"><textarea id="task-description">textarea value</textarea></form>';
	} );

	test( 'prefers wp.editor.getContent so Text-tab edits are never lost', () => {
		const getContent = jest.fn( () => 'from wp.editor' );
		const getTaskDescription = instantiate( 'getTaskDescription', {
			quill: null,
			taskEditor: { getContent: () => 'stale tinymce buffer' },
			wp: { editor: { getContent } },
			tinyMCE: undefined,
		} );

		const result = getTaskDescription( document.getElementById( 'f' ) );

		expect( result ).toBe( 'from wp.editor' );
		expect( getContent ).toHaveBeenCalledWith( 'task-description' );
	} );

	test( 'falls back to the textarea when wp.editor.getContent is unavailable', () => {
		const getTaskDescription = instantiate( 'getTaskDescription', {
			quill: null,
			taskEditor: null,
			wp: { editor: {} },
			tinyMCE: undefined,
		} );

		expect( getTaskDescription( document.getElementById( 'f' ) ) ).toBe( 'textarea value' );
	} );
} );

describe( 'destroyTaskEditor', () => {
	test( 'removes the instance even when init has not fired yet', () => {
		const remove = jest.fn();
		const destroyTaskEditor = instantiate( 'destroyTaskEditor', {
			taskEditor: { initialized: false },
			wp: { editor: { remove } },
		} );

		destroyTaskEditor();

		expect( remove ).toHaveBeenCalledWith( 'task-description' );
	} );

	test( 'is a safe no-op without wp.editor', () => {
		const destroyTaskEditor = instantiate( 'destroyTaskEditor', {
			taskEditor: null,
			wp: undefined,
		} );

		expect( () => destroyTaskEditor() ).not.toThrow();
	} );
} );

describe( 'initializeTaskEditor', () => {
	let initialize;
	let capturedConfig;
	let enterDirtyEditMode;
	let context;

	function build( readOnly ) {
		capturedConfig = null;
		enterDirtyEditMode = jest.fn();
		document.body.innerHTML = '<form id="f"><textarea id="task-description">hola</textarea></form>';
		context = document.getElementById( 'f' );

		const initializeTaskEditor = instantiate( 'initializeTaskEditor', {
			taskEditor: null,
			wp: {
				editor: {
					initialize: ( id, config ) => {
						capturedConfig = config;
					},
				},
			},
			debounce: ( fn ) => fn,
			enterDirtyEditMode,
		} );

		initialize = initializeTaskEditor( context, readOnly );
		return initialize;
	}

	test( 'passes readonly to TinyMCE and locks the quicktags textarea', () => {
		build( true );

		expect( capturedConfig.tinymce.readonly ).toBe( true );
		expect( document.getElementById( 'task-description' ).readOnly ).toBe( true );
	} );

	test( 'read-only cards get no Text tab or media buttons (Quicktags bypasses readOnly)', () => {
		build( true );

		expect( capturedConfig.quicktags ).toBe( false );
		expect( capturedConfig.mediaButtons ).toBe( false );
	} );

	test( 'stays editable by default', () => {
		build( false );

		expect( capturedConfig.tinymce.readonly ).toBe( false );
		expect( capturedConfig.quicktags ).toBe( true );
		expect( capturedConfig.mediaButtons ).toBe( true );
		expect( document.getElementById( 'task-description' ).readOnly ).toBe( false );
	} );

	test( 'Text-tab textarea edits mark the card dirty', () => {
		build( false );

		const textarea = document.getElementById( 'task-description' );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( enterDirtyEditMode ).toHaveBeenCalledWith( context );
	} );

	test( 'programmatic changes on a read-only textarea never mark the card dirty', () => {
		build( true );

		const textarea = document.getElementById( 'task-description' );
		// Quicktags-style programmatic mutation: value assignment + change event.
		textarea.value = 'mutated';
		textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( enterDirtyEditMode ).not.toHaveBeenCalled();
	} );

	test( 'changes are ignored after the textarea is disabled by a lost lock', () => {
		build( false );

		const textarea = document.getElementById( 'task-description' );
		textarea.disabled = true;
		textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( enterDirtyEditMode ).not.toHaveBeenCalled();
	} );

	test( 'disableEditingControls disables Quicktags toolbar, media and switch buttons', () => {
		document.body.innerHTML = `
			<div id="ctx">
				<form id="task-form">
					<div class="wp-editor-wrap">
						<button type="button" class="wp-switch-editor">Visual</button>
						<button type="button" class="insert-media">Add media</button>
						<input type="button" class="ed_button" value="b" />
						<textarea id="task-description"></textarea>
					</div>
				</form>
			</div>
		`;
		const disableEditingControls = instantiate( 'disableEditingControls', {
			assigneesSelect: null,
			labelsSelect: null,
			quill: null,
			taskEditor: null,
		} );

		disableEditingControls( document.getElementById( 'ctx' ) );

		document.querySelectorAll( '.wp-editor-wrap button, .wp-editor-wrap input' ).forEach( ( el ) => {
			expect( el.disabled ).toBe( true );
		} );
	} );

	test( 'init callback survives an early destroy (uses the local editor)', async () => {
		const promise = build( false );
		const fake = createFakeEditor();

		capturedConfig.tinymce.setup( fake.editor );

		// Simulate the modal closing before init: the module-level reference
		// is nulled by destroyTaskEditor. The init callback must not throw.
		expect( () => fake.emit( 'init' ) ).not.toThrow();
		expect( fake.editor.initialized ).toBe( true );
		await promise;
	} );
} );
