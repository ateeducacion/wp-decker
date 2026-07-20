/**
 * Unit tests for the task-card edit-lock UI in task-card.js.
 *
 * The real functions are extracted from the source file and executed with
 * mocked dependencies, so the DOM behavior is tested rather than a copy.
 *
 * @package Decker
 */

/* eslint-disable no-undef */

const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Extract a top-level function definition from a source string.
 *
 * @param {string} source       The file source.
 * @param {string} functionName The function name to extract.
 * @return {string} The function source.
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

const SOURCE = fs.readFileSync(
	path.resolve( __dirname, '../../public/assets/js/task-card.js' ),
	'utf8'
);

/**
 * Compile an extracted function with injected dependencies.
 *
 * @param {string} name The function name to compile.
 * @param {Object} deps A map of dependency identifiers to values.
 * @return {Function} The compiled function.
 */
function compile( name, deps ) {
	const src = extractFunctionSource( SOURCE, name );
	const keys = Object.keys( deps );
	const values = keys.map( ( k ) => deps[ k ] );
	// eslint-disable-next-line no-new-func
	return new Function( ...keys, `return (${ src });` )( ...values );
}

const STRINGS = {
	take_over_editing: 'Take over editing',
	taking_over: 'Taking over…',
	card_locked_by: 'This card is currently locked by %s.',
	lock_lost_message: 'You can no longer save this card because another user has taken over editing.',
	lock_takeover_failed: 'The card could not be taken over.',
	reload_card: 'Reload card',
};

/**
 * Build a task form DOM as rendered by task-card.php.
 *
 * @param {Object} lock     The lock info serialized into data-lock.
 * @param {boolean} locked  Whether to render the read-only locked markup.
 * @return {HTMLElement} The context element.
 */
function buildDom( lock, locked ) {
	const takeover = locked && lock.can_take_over
		? `<button type="button" class="btn decker-take-over-lock" data-task-id="${ lock.post_id }">Take over editing</button>`
		: '';
	const banner = locked
		? `<div class="alert decker-lock-banner" data-decker-lock-banner>
				<span class="decker-lock-message">${ lock.message }</span>${ takeover }
			</div>`
		: '';
	const disabledAttr = locked ? 'disabled' : '';

	document.body.innerHTML = `
		<div id="context">
			<form id="task-form" data-task-id="${ lock.post_id }" data-lock='${ JSON.stringify( lock ) }'>
				${ banner }
				<input id="task-title" type="text" value="Title" ${ disabledAttr } />
				<textarea id="notes" ${ disabledAttr }></textarea>
				<select id="task-stack" ${ disabledAttr }><option>to-do</option></select>
				<button type="submit" id="save-task" ${ disabledAttr }>Save</button>
				<button type="button" id="save-task-dropdown" ${ disabledAttr }></button>
			</form>
		</div>
	`;
	return document.getElementById( 'context' );
}

describe( 'task-card lock UI', () => {
	let readTaskLockState;
	let disableEditingControls;

	beforeEach( () => {
		readTaskLockState = compile( 'readTaskLockState', {} );
		disableEditingControls = compile( 'disableEditingControls', {
			assigneesSelect: null,
			labelsSelect: null,
			quill: null,
		} );
		global.alert = jest.fn();
	} );

	test( 'reads the serialized lock state from the form', () => {
		const lock = { post_id: 7, locked: true, owner: { id: 3, display_name: 'Ana' } };
		const context = buildDom( lock, true );

		const parsed = readTaskLockState( context );

		expect( parsed.locked ).toBe( true );
		expect( parsed.owner.display_name ).toBe( 'Ana' );
	} );

	test( 'returns null when there is no lock data', () => {
		document.body.innerHTML = '<div id="context"><form id="task-form"></form></div>';
		const context = document.getElementById( 'context' );

		expect( readTaskLockState( context ) ).toBeNull();
	} );

	test( 'renders the locked message with the owner display name', () => {
		const lock = {
			post_id: 5,
			locked: true,
			can_take_over: true,
			owner: { id: 2, display_name: 'Bruno' },
			message: 'This card is currently locked by Bruno.',
		};
		const context = buildDom( lock, true );

		const message = context.querySelector( '.decker-lock-message' ).textContent;
		expect( message ).toContain( 'Bruno' );
	} );

	test( 'disables editable fields and the save button when locked', () => {
		const lock = { post_id: 5, locked: true, can_take_over: true, message: 'locked' };
		const context = buildDom( lock, true );

		disableEditingControls( context );

		expect( context.querySelector( '#task-title' ).disabled ).toBe( true );
		expect( context.querySelector( '#notes' ).disabled ).toBe( true );
		expect( context.querySelector( '#task-stack' ).disabled ).toBe( true );
		expect( context.querySelector( '#save-task' ).disabled ).toBe( true );
		expect( context.querySelector( '#save-task-dropdown' ).disabled ).toBe( true );
	} );

	test( 'shows the takeover action when the user may take over', () => {
		const lock = { post_id: 5, locked: true, can_take_over: true, message: 'locked' };
		const context = buildDom( lock, true );

		expect( context.querySelector( '.decker-take-over-lock' ) ).not.toBeNull();
	} );

	test( 'hides the takeover action when the user may not take over', () => {
		const lock = { post_id: 5, locked: true, can_take_over: false, message: 'locked' };
		const context = buildDom( lock, true );

		expect( context.querySelector( '.decker-take-over-lock' ) ).toBeNull();
	} );

	test( 'wires a successful takeover to reload the card', async () => {
		const lock = { post_id: 9, locked: true, can_take_over: true, message: 'locked' };
		const context = buildDom( lock, true );

		const lockRequest = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { owned_by_current_user: true } ),
			} )
		);
		const reloadTaskCard = jest.fn();

		const wireTakeOverButton = compile( 'wireTakeOverButton', {
			strings: STRINGS,
			getTaskId: () => '9',
			lockRequest,
			reloadTaskCard,
		} );

		wireTakeOverButton( context );
		context.querySelector( '.decker-take-over-lock' ).click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( lockRequest ).toHaveBeenCalledWith( 'POST', '9', '/takeover' );
		expect( reloadTaskCard ).toHaveBeenCalledWith( '9' );
	} );

	test( 'handles a failed takeover by alerting and re-enabling the button', async () => {
		const lock = { post_id: 9, locked: true, can_take_over: true, message: 'locked' };
		const context = buildDom( lock, true );

		const lockRequest = jest.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () => Promise.resolve( { code: 'decker_task_cannot_edit' } ),
			} )
		);
		const reloadTaskCard = jest.fn();

		const wireTakeOverButton = compile( 'wireTakeOverButton', {
			strings: STRINGS,
			getTaskId: () => '9',
			lockRequest,
			reloadTaskCard,
		} );

		wireTakeOverButton( context );
		const button = context.querySelector( '.decker-take-over-lock' );
		button.click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( reloadTaskCard ).not.toHaveBeenCalled();
		expect( global.alert ).toHaveBeenCalled();
		expect( button.disabled ).toBe( false );
	} );

	test( 'lock-lost injects a warning banner and disables editing', () => {
		const lock = { post_id: 4, locked: false, owned_by_current_user: true };
		const context = buildDom( lock, false );

		const activeLock = { postId: 4, owned: true };
		const handleLockLost = compile( 'handleLockLost', {
			activeLock,
			disableEditingControls,
			strings: STRINGS,
			getTaskId: () => '4',
			reloadTaskCard: jest.fn(),
		} );

		handleLockLost( context, { owner: { id: 2, display_name: 'Bruno' } } );

		const banner = context.querySelector( '[data-decker-lock-lost]' );
		expect( banner ).not.toBeNull();
		expect( banner.textContent ).toContain( 'Bruno' );
		expect( banner.getAttribute( 'aria-live' ) ).toBe( 'assertive' );
		expect( context.querySelector( '#task-title' ).disabled ).toBe( true );
		expect( context.querySelector( '#save-task' ).disabled ).toBe( true );
		expect( activeLock.owned ).toBe( false );
	} );

	test( 'detects lock conflict responses regardless of HTTP success envelope', () => {
		const isTaskLockConflictResponse = compile( 'isTaskLockConflictResponse', {} );

		expect( isTaskLockConflictResponse( {
			success: false,
			data: { code: 'decker_task_locked', message: 'locked', locked: true },
		} ) ).toBe( true );

		expect( isTaskLockConflictResponse( {
			success: false,
			data: { code: 'other_error', message: 'nope' },
		} ) ).toBe( false );

		expect( isTaskLockConflictResponse( null ) ).toBe( false );
		expect( isTaskLockConflictResponse( { success: true } ) ).toBe( false );
	} );

	test( 'HTTP 409 lock conflict shows the lock-lost banner via handleLockLost', () => {
		const lock = { post_id: 4, locked: false, owned_by_current_user: true, generation: 1 };
		const context = buildDom( lock, false );
		const activeLock = { postId: 4, owned: true };
		const handleLockLost = compile( 'handleLockLost', {
			activeLock,
			disableEditingControls,
			strings: STRINGS,
			getTaskId: () => '4',
			reloadTaskCard: jest.fn(),
		} );
		const isTaskLockConflictResponse = compile( 'isTaskLockConflictResponse', {} );

		// Simulate the xhr.onload branch for a 409 body.
		const response = {
			success: false,
			data: {
				code: 'decker_task_locked',
				message: 'You can no longer save this card because another user has taken over editing. Please reload the card to see the latest changes.',
				locked: true,
				owner: { id: 2, display_name: 'Bruno' },
			},
		};
		expect( isTaskLockConflictResponse( response ) ).toBe( true );

		if ( isTaskLockConflictResponse( response ) ) {
			handleLockLost( context, response.data );
		}

		expect( context.querySelector( '[data-decker-lock-lost]' ) ).not.toBeNull();
		expect( context.querySelector( '#save-task' ).disabled ).toBe( true );
	} );
} );
