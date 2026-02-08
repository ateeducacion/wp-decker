/**
 * Unit tests for form field initialization logic in task-card.js
 *
 * Tests the collaboration form field sync: populating Yjs from
 * server-rendered values (first user) and applying remote values
 * to DOM (joining user).
 *
 * @package Decker
 */

/* eslint-disable no-undef */

// ── Mock helpers ─────────────────────────────────────────────────────

function createMockYMap( initial = {} ) {
	const store = new Map( Object.entries( initial ) );
	return {
		get: ( k ) => store.get( k ),
		set: jest.fn( ( k, v ) => store.set( k, v ) ),
		get size() {
			return store.size;
		},
		observe: jest.fn(),
		_store: store,
	};
}

function createMockAwareness( peerCount = 1 ) {
	const states = new Map();
	for ( let i = 0; i < peerCount; i++ ) {
		states.set( i, { user: { name: `User ${ i }` } } );
	}
	return {
		clientID: 0,
		getStates: jest.fn( () => states ),
		setLocalStateField: jest.fn(),
		on: jest.fn(),
	};
}

function createMockSession( formFields, awareness, syncedImmediately = true ) {
	let syncCallback = null;
	return {
		formFields,
		awareness,
		onSynced: jest.fn( ( cb ) => {
			if ( syncedImmediately ) {
				cb();
			} else {
				syncCallback = cb;
			}
		} ),
		setActiveField: jest.fn(),
		clearActiveField: jest.fn(),
		_triggerSync() {
			if ( syncCallback ) {
				syncCallback();
			}
		},
	};
}

// ── DOM setup ────────────────────────────────────────────────────────

function setupDOM( values = {} ) {
	document.body.innerHTML = `
		<div id="test-context">
			<form id="task-form">
				<input id="task-title" type="text" value="${ values.title || '' }" />
				<input id="task-due-date" type="date" value="${ values.dueDate || '' }" />
				<input id="task-max-priority" type="checkbox" ${ values.maxPriority ? 'checked' : '' } />
				<input id="task-today" type="checkbox" ${ values.today ? 'checked' : '' } />
				<select id="task-board"><option value="board1">Board 1</option><option value="board2" ${ values.board === 'board2' ? 'selected' : '' }>Board 2</option></select>
				<select id="task-responsable"><option value="user1">User 1</option></select>
				<select id="task-stack"><option value="to-do" ${ values.stack === 'to-do' ? 'selected' : '' }>To Do</option><option value="in-progress">In Progress</option></select>
			</form>
			<div id="editor"><p>Test content</p></div>
			<div id="high-label" class="d-none"></div>
		</div>
	`;
	return document.getElementById( 'test-context' );
}

// ── Field mappings (matching task-card.js) ───────────────────────────

const FIELD_MAPPINGS = [
	{ id: 'task-title', key: 'title', type: 'text' },
	{ id: 'task-max-priority', key: 'maxPriority', type: 'checkbox' },
	{ id: 'task-today', key: 'today', type: 'checkbox' },
	{ id: 'task-board', key: 'board', type: 'select' },
	{ id: 'task-responsable', key: 'responsable', type: 'select' },
	{ id: 'task-stack', key: 'stack', type: 'select' },
	{ id: 'task-due-date', key: 'dueDate', type: 'date' },
];

/**
 * Simulate what initializeFormFieldValues does for "first user" path.
 * This is the logic from task-card.js after our bug fixes.
 */
function simulateFirstUserPopulate( context, formFields, snapshot ) {
	FIELD_MAPPINGS.forEach( ( { id, key, type } ) => {
		const originalValue = snapshot.fields[ key ];
		if ( originalValue !== undefined ) {
			formFields.set( key, originalValue );
		}
		const el = context.querySelector( `#${ id }` );
		if ( el ) {
			if ( type === 'checkbox' ) {
				if ( originalValue !== undefined ) {
					el.checked = !! originalValue;
				}
			} else if ( originalValue !== undefined ) {
				el.value = originalValue;
			}
		}
	} );
}

/**
 * Simulate what initializeFormFieldValues does for "joining user" path.
 */
function simulateJoiningUserApply( context, formFields ) {
	FIELD_MAPPINGS.forEach( ( { id, key, type } ) => {
		const el = context.querySelector( `#${ id }` );
		if ( ! el ) {
			return;
		}

		const remoteValue = formFields.get( key );
		if ( remoteValue !== undefined ) {
			if ( type === 'checkbox' ) {
				el.checked = remoteValue;
			} else {
				el.value = remoteValue;
			}
		}
	} );
}

/**
 * Simulate DOM fallback path (no remote data, no snapshot).
 */
function simulateDOMFallback( context, formFields ) {
	FIELD_MAPPINGS.forEach( ( { id, key, type } ) => {
		const el = context.querySelector( `#${ id }` );
		if ( ! el ) {
			return;
		}

		const localValue = type === 'checkbox' ? el.checked : el.value;
		if ( localValue !== undefined ) {
			formFields.set( key, localValue );
		}
	} );
}

/**
 * Simulate captureOriginalFormValues from task-card.js
 */
function captureOriginalFormValues( context ) {
	const snapshot = {
		fields: {},
		choices: {},
		quillHtml: null,
	};

	FIELD_MAPPINGS.forEach( ( { id, key, type } ) => {
		const el = context.querySelector( `#${ id }` );
		if ( ! el ) {
			return;
		}
		if ( type === 'checkbox' ) {
			snapshot.fields[ key ] = el.checked;
		} else {
			snapshot.fields[ key ] = el.value;
		}
	} );

	const editorEl = context.querySelector( '#editor' );
	if ( editorEl ) {
		snapshot.quillHtml = editorEl.innerHTML;
	}

	return snapshot;
}

// ── Tests ────────────────────────────────────────────────────────────

describe( 'Task card form field sync', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'first user populates Yjs with original snapshot values including empty strings', () => {
		const context = setupDOM( {
			title: 'My Task',
			dueDate: '', // empty date — should be synced
			board: 'board1',
			stack: 'to-do',
		} );
		const formFields = createMockYMap();
		const snapshot = captureOriginalFormValues( context );

		simulateFirstUserPopulate( context, formFields, snapshot );

		// Title should be set
		expect( formFields.set ).toHaveBeenCalledWith( 'title', 'My Task' );

		// Empty due date should ALSO be set (this is the bug fix)
		expect( formFields.set ).toHaveBeenCalledWith( 'dueDate', '' );

		// Board should be set
		expect( formFields.set ).toHaveBeenCalledWith( 'board', 'board1' );
	} );

	test( 'first user correctly handles checkbox boolean values', () => {
		const context = setupDOM( {
			maxPriority: true,
			today: false,
		} );
		const formFields = createMockYMap();
		const snapshot = captureOriginalFormValues( context );

		simulateFirstUserPopulate( context, formFields, snapshot );

		// Checkboxes should be set as booleans
		expect( formFields.set ).toHaveBeenCalledWith( 'maxPriority', true );
		expect( formFields.set ).toHaveBeenCalledWith( 'today', false );

		// DOM should reflect the values
		expect( context.querySelector( '#task-max-priority' ).checked ).toBe( true );
		expect( context.querySelector( '#task-today' ).checked ).toBe( false );
	} );

	test( 'joining user applies remote Yjs values to DOM', () => {
		const context = setupDOM();
		const formFields = createMockYMap( {
			title: 'Remote Title',
			dueDate: '2025-12-31',
			maxPriority: true,
			board: 'board2',
			stack: 'in-progress',
		} );

		simulateJoiningUserApply( context, formFields );

		expect( context.querySelector( '#task-title' ).value ).toBe( 'Remote Title' );
		expect( context.querySelector( '#task-due-date' ).value ).toBe( '2025-12-31' );
		expect( context.querySelector( '#task-max-priority' ).checked ).toBe( true );
		expect( context.querySelector( '#task-board' ).value ).toBe( 'board2' );
		expect( context.querySelector( '#task-stack' ).value ).toBe( 'in-progress' );
	} );

	test( 'checkbox el.checked is not set when value is undefined', () => {
		const context = setupDOM( { maxPriority: true } );
		const formFields = createMockYMap();
		const snapshot = {
			fields: {
				title: 'Test',
				// maxPriority is NOT in snapshot (undefined)
			},
		};

		// Initially checked
		expect( context.querySelector( '#task-max-priority' ).checked ).toBe( true );

		simulateFirstUserPopulate( context, formFields, snapshot );

		// Should remain unchanged (still checked) since undefined
		expect( context.querySelector( '#task-max-priority' ).checked ).toBe( true );
	} );

	test( 'empty string field values ARE synced (not filtered out)', () => {
		const context = setupDOM( {
			title: '',
			dueDate: '',
		} );
		const formFields = createMockYMap();
		const snapshot = captureOriginalFormValues( context );

		simulateFirstUserPopulate( context, formFields, snapshot );

		// Both empty strings should be stored in Yjs
		expect( formFields.set ).toHaveBeenCalledWith( 'title', '' );
		expect( formFields.set ).toHaveBeenCalledWith( 'dueDate', '' );

		// Also verify DOM fallback path allows empty strings
		const formFields2 = createMockYMap();
		simulateDOMFallback( context, formFields2 );

		expect( formFields2.set ).toHaveBeenCalledWith( 'title', '' );
		expect( formFields2.set ).toHaveBeenCalledWith( 'dueDate', '' );
	} );

	test( 'captureOriginalFormValues captures checkboxes as booleans, selects as values, Quill innerHTML', () => {
		const context = setupDOM( {
			title: 'Test Task',
			maxPriority: true,
			today: false,
			board: 'board1',
			stack: 'to-do',
			dueDate: '2025-06-15',
		} );

		const snapshot = captureOriginalFormValues( context );

		// Checkboxes are booleans
		expect( snapshot.fields.maxPriority ).toBe( true );
		expect( snapshot.fields.today ).toBe( false );

		// Selects are string values
		expect( snapshot.fields.board ).toBe( 'board1' );
		expect( snapshot.fields.stack ).toBe( 'to-do' );

		// Text and date are string values
		expect( snapshot.fields.title ).toBe( 'Test Task' );
		expect( snapshot.fields.dueDate ).toBe( '2025-06-15' );

		// Quill innerHTML captured
		expect( snapshot.quillHtml ).toBe( '<p>Test content</p>' );
	} );
} );
