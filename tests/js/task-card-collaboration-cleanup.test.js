/**
 * Runtime regression tests for task-card collaboration cleanup.
 *
 * @package Decker
 */

function extractFunctionSource( source, functionName ) {
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

function createMockFormFields( initial = {} ) {
	const store = new Map( Object.entries( initial ) );
	let observer = null;

	return {
		get: ( key ) => store.get( key ),
		set: jest.fn( ( key, value ) => store.set( key, value ) ),
		get size() {
			return store.size;
		},
		observe: jest.fn( ( callback ) => {
			observer = callback;
		} ),
		unobserve: jest.fn( ( callback ) => {
			if ( observer === callback ) {
				observer = null;
			}
		} ),
		emit( keys ) {
			if ( observer ) {
				observer( { keysChanged: new Set( keys ) } );
			}
		},
	};
}

function createMockAwareness() {
	const handlers = new Map();
	const states = new Map( [
		[ 1, { user: { name: 'Me', color: '#00f' } } ],
		[
			2,
			{
				user: { name: 'Teammate', color: '#f00' },
				activeField: 'task-title',
			},
		],
	] );

	return {
		clientID: 1,
		getStates: jest.fn( () => states ),
		on: jest.fn( ( event, callback ) => {
			handlers.set( event, callback );
		} ),
		off: jest.fn( ( event, callback ) => {
			if ( handlers.get( event ) === callback ) {
				handlers.delete( event );
			}
		} ),
		setLocalStateField: jest.fn(),
		emit( event ) {
			const handler = handlers.get( event );
			if ( handler ) {
				handler();
			}
		},
	};
}

function setupDOM() {
	document.body.innerHTML = `
		<div id="context">
			<form id="task-form">
				<div class="form-floating">
					<input id="task-title" type="text" value="Original title" />
				</div>
				<div class="form-check">
					<input id="task-max-priority" type="checkbox" />
				</div>
				<div class="form-check">
					<input id="task-today" type="checkbox" />
				</div>
				<div class="form-floating">
					<select id="task-board">
						<option value="board1" selected>Board 1</option>
						<option value="board2">Board 2</option>
					</select>
				</div>
				<div class="form-floating">
					<select id="task-responsable">
						<option value="user1" selected>User 1</option>
					</select>
				</div>
				<div class="form-floating">
					<select id="task-stack">
						<option value="to-do" selected>To Do</option>
						<option value="done">Done</option>
					</select>
				</div>
				<div class="form-floating">
					<input id="task-due-date" type="date" value="" />
				</div>
			</form>
			<div id="high-label" class="d-none"></div>
		</div>
	`;

	return document.getElementById( 'context' );
}

describe( 'Task card collaboration cleanup', () => {
	let initFormFieldsCollaboration;

	beforeEach( () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const taskCardFile = path.resolve(
			__dirname,
			'../../public/assets/js/task-card.js'
		);
		const source = fs.readFileSync( taskCardFile, 'utf8' );
		const functionSource = extractFunctionSource(
			source,
			'initFormFieldsCollaboration'
		);

		initFormFieldsCollaboration = new Function(
			'FIELD_MAPPINGS',
			'assigneesSelect',
			'labelsSelect',
			'originalValuesSnapshot',
			'strings',
			'debounce',
			'disableAllFormFields',
			'animateRemoteChange',
			'togglePriorityLabel',
			`return (${ functionSource });`
		)(
			[
				{ id: 'task-title', key: 'title', type: 'text' },
				{ id: 'task-max-priority', key: 'maxPriority', type: 'checkbox' },
				{ id: 'task-today', key: 'today', type: 'checkbox' },
				{ id: 'task-board', key: 'board', type: 'select' },
				{ id: 'task-responsable', key: 'responsable', type: 'select' },
				{ id: 'task-stack', key: 'stack', type: 'select' },
				{ id: 'task-due-date', key: 'dueDate', type: 'date' },
			],
			null,
			null,
			{
				fields: {
					title: 'Original title',
					maxPriority: false,
					today: false,
					board: 'board1',
					responsable: 'user1',
					stack: 'to-do',
					dueDate: '',
				},
				choices: {},
			},
			{
				task_archived_by_another_user: 'Archived remotely',
				task_is_archived: 'Archived',
				task_archived: 'Archived',
			},
			// Replace debounce with an immediate pass-through so cleanup assertions
			// stay synchronous and deterministic in this focused unit test.
			( callback ) => {
				const immediateCallback = ( ...args ) => callback( ...args );
				immediateCallback.cancel = jest.fn();
				return immediateCallback;
			},
			jest.fn(),
			jest.fn(),
			( element ) => {
				const highLabel = document.getElementById( 'high-label' );
				if ( highLabel ) {
					highLabel.classList.toggle( 'd-none', ! element.checked );
				}
			}
		);
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'destroy unregisters observers and removes local field listeners', () => {
		const context = setupDOM();
		const formFields = createMockFormFields();
		const awareness = createMockAwareness();
		const session = {
			formFields,
			awareness,
			onSynced: jest.fn( ( callback ) => callback() ),
			setActiveField: jest.fn(),
			clearActiveField: jest.fn(),
		};

		const binding = initFormFieldsCollaboration( session, context );
		const titleInput = context.querySelector( '#task-title' );
		const observedHandler = formFields.observe.mock.calls[ 0 ][ 0 ];
		const awarenessHandler = awareness.on.mock.calls.find(
			( [ event ] ) => event === 'change'
		)[ 1 ];

		titleInput.value = 'Changed before destroy';
		titleInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( formFields.set ).toHaveBeenCalledWith(
			'title',
			'Changed before destroy'
		);

		formFields.set.mockClear();
		session.setActiveField.mockClear();

		binding.destroy();

		expect( formFields.unobserve ).toHaveBeenCalledWith( observedHandler );
		expect( awareness.off ).toHaveBeenCalledWith(
			'change',
			awarenessHandler
		);
		expect( session.clearActiveField ).toHaveBeenCalledTimes( 1 );

		titleInput.value = 'Changed after destroy';
		titleInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		titleInput.dispatchEvent( new Event( 'focus', { bubbles: true } ) );

		expect( formFields.set ).not.toHaveBeenCalled();
		expect( session.setActiveField ).not.toHaveBeenCalled();
	} );

	test( 'destroy prevents pending remote updates from mutating the torn-down DOM', () => {
		jest.useFakeTimers();

		const context = setupDOM();
		const formFields = createMockFormFields( { title: 'Remote title' } );
		const awareness = createMockAwareness();
		const session = {
			formFields,
			awareness,
			onSynced: jest.fn( ( callback ) => callback() ),
			setActiveField: jest.fn(),
			clearActiveField: jest.fn(),
		};

		const binding = initFormFieldsCollaboration( session, context );
		const titleInput = context.querySelector( '#task-title' );

		formFields.emit( [ 'title' ] );
		expect( titleInput.value ).toBe( 'Remote title' );

		titleInput.value = 'Stable after destroy';
		binding.destroy();

		formFields.emit( [ 'title' ] );
		awareness.emit( 'change' );
		jest.runAllTimers();

		expect( titleInput.value ).toBe( 'Stable after destroy' );
		expect(
			context.querySelectorAll( '.decker-field-editor' )
		).toHaveLength( 0 );

		jest.useRealTimers();
	} );
} );
