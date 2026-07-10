/**
 * Unit tests for the "For today" quick action in task-card.js.
 *
 * The real functions are extracted from the source file and executed with
 * mocked dependencies, so behavior (not a copy) is tested.
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
 * @param {string} name The function name.
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
	adding_to_today: 'Adding to today…',
	removing_from_today: 'Removing from today…',
	today_update_failed: 'The task could not be updated.',
};

/**
 * Build the today control DOM as rendered by task-card.php.
 *
 * @param {Object} opts Options: taskId, marked, showQuick, sharedDisabled.
 * @return {HTMLElement} The context element.
 */
function buildDom( opts = {} ) {
	const taskId = 'taskId' in opts ? opts.taskId : 5;
	const marked = !! opts.marked;
	const showQuick = 'showQuick' in opts ? opts.showQuick : taskId !== 0;
	const sharedDisabled = !! opts.sharedDisabled;
	const dis = sharedDisabled ? 'disabled' : '';

	const label = marked ? 'Remove from today' : 'Add to today';
	const quick = showQuick
		? `<button type="button" id="task-today-quick" class="btn btn-sm ${ marked ? 'btn-outline-secondary' : 'btn-success' } decker-today-quick" data-task-id="${ taskId }" data-marked="${ marked ? '1' : '0' }"><span class="decker-today-quick-label">${ label }</span></button>`
		: '';

	document.body.innerHTML = `
		<div id="context">
			<form id="task-form" data-task-id="${ taskId }">
				<input type="hidden" name="task_id" value="${ taskId }">
				<input id="task-title" type="text" value="Title" ${ dis }>
				<div id="task-today-control" class="decker-today-control">
					${ quick }
					<span class="form-check form-switch decker-today-checkbox ${ showQuick ? 'd-none' : '' }">
						<input class="form-check-input" type="checkbox" id="task-today" ${ marked ? 'checked' : '' } ${ showQuick ? 'tabindex="-1" aria-hidden="true" disabled' : '' }>
						<label class="form-check-label" for="task-today">For today</label>
					</span>
				</div>
				<button type="submit" id="save-task" disabled>Save</button>
				<button type="button" id="save-task-dropdown"></button>
			</form>
		</div>`;
	return document.getElementById( 'context' );
}

describe( 'task-card today quick action', () => {
	let showTodayCheckbox;
	let enterDirtyEditMode;

	beforeEach( () => {
		showTodayCheckbox = compile( 'showTodayCheckbox', {} );
		enterDirtyEditMode = compile( 'enterDirtyEditMode', { showTodayCheckbox } );
		window.deckerHasUnsavedChanges = false;
		global.fetch = jest.fn();
		global.alert = jest.fn();
		global.wpApiSettings = { nonce: 'nonce-123', root: 'http://x/wp-json/' };
	} );

	test( 'collaboration mapping excludes the personal today field', () => {
		const start = SOURCE.indexOf( 'const FIELD_MAPPINGS' );
		const end = SOURCE.indexOf( '];', start );
		const mappings = SOURCE.slice( start, end );
		expect( mappings ).not.toContain( 'task-today' );
		expect( mappings ).toContain( 'task-title' );
	} );

	test( 'existing pristine unmarked task shows the add button and hides the checkbox', () => {
		const context = buildDom( { marked: false } );
		const button = context.querySelector( '#task-today-quick' );

		expect( button ).not.toBeNull();
		expect( button.textContent ).toContain( 'Add to today' );
		expect( context.querySelector( '.decker-today-checkbox' ).classList.contains( 'd-none' ) ).toBe( true );
		expect( context.querySelector( '#task-today' ).disabled ).toBe( true );
		expect( context.querySelector( '#save-task' ).disabled ).toBe( true );
	} );

	test( 'existing pristine marked task shows the remove button', () => {
		const context = buildDom( { marked: true } );
		const button = context.querySelector( '#task-today-quick' );

		expect( button.textContent ).toContain( 'Remove from today' );
		expect( button.dataset.marked ).toBe( '1' );
	} );

	test( 'new task shows the checkbox and no quick button', () => {
		const context = buildDom( { taskId: 0 } );

		expect( context.querySelector( '#task-today-quick' ) ).toBeNull();
		expect( context.querySelector( '.decker-today-checkbox' ).classList.contains( 'd-none' ) ).toBe( false );
	} );

	test( 'dirty transition hides the quick action and reveals the checkbox', () => {
		const context = buildDom( { marked: false } );

		enterDirtyEditMode( context );

		expect( context.querySelector( '#task-today-quick' ).classList.contains( 'd-none' ) ).toBe( true );
		expect( context.querySelector( '.decker-today-checkbox' ).classList.contains( 'd-none' ) ).toBe( false );
		expect( context.querySelector( '#task-today' ).disabled ).toBe( false );
		expect( context.querySelector( '#task-today' ).hasAttribute( 'aria-hidden' ) ).toBe( false );
		expect( context.querySelector( '#save-task' ).disabled ).toBe( false );
		expect( window.deckerHasUnsavedChanges ).toBe( true );
	} );

	test( 'the pristine-to-dirty transition is one-way and idempotent', () => {
		const context = buildDom( { marked: false } );

		enterDirtyEditMode( context );
		// A second call must not revert or re-run the transition.
		enterDirtyEditMode( context );

		expect( window.deckerHasUnsavedChanges ).toBe( true );
		expect( context.querySelector( '#task-today-quick' ).classList.contains( 'd-none' ) ).toBe( true );
	} );

	test( 'clicking the quick button sends only the task id and boolean state', async () => {
		const context = buildDom( { marked: false } );

		global.fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { success: true, marked: true, task_id: 5, user_id: 9, date: '2026-07-10' } ),
		} );

		const setTodayQuickActionLoading = compile( 'setTodayQuickActionLoading', { strings: STRINGS } );
		const onTodayQuickActionSuccess = jest.fn();
		const notifyTodayResult = jest.fn();
		const submitTodayQuickAction = compile( 'submitTodayQuickAction', {
			getTaskId: () => '5',
			deckerRestUrl: 'http://x/wp-json/decker/v1/',
			wpApiSettings: window.wpApiSettings,
			setTodayQuickActionLoading,
			onTodayQuickActionSuccess,
			notifyTodayResult,
			strings: STRINGS,
		} );
		const initializeTodayQuickAction = compile( 'initializeTodayQuickAction', { submitTodayQuickAction } );

		initializeTodayQuickAction( context );
		context.querySelector( '#task-today-quick' ).click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = global.fetch.mock.calls[ 0 ];
		expect( url ).toBe( 'http://x/wp-json/decker/v1/tasks/5/today?marked=true' );
		expect( options.method ).toBe( 'PUT' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'nonce-123' );

		const body = JSON.parse( options.body );
		expect( body ).toEqual( { marked: true } );
		expect( body.user_id ).toBeUndefined();
		expect( options.body ).not.toContain( 'title' );
		expect( options.body ).not.toContain( 'save_decker_task' );
		expect( onTodayQuickActionSuccess ).toHaveBeenCalled();
	} );

	test( 'clicking the remove button requests marked=false', async () => {
		const context = buildDom( { marked: true } );
		global.fetch.mockResolvedValue( { ok: true, json: () => Promise.resolve( { success: true, marked: false } ) } );

		const submitTodayQuickAction = compile( 'submitTodayQuickAction', {
			getTaskId: () => '5',
			deckerRestUrl: 'http://x/wp-json/decker/v1/',
			wpApiSettings: window.wpApiSettings,
			setTodayQuickActionLoading: compile( 'setTodayQuickActionLoading', { strings: STRINGS } ),
			onTodayQuickActionSuccess: jest.fn(),
			notifyTodayResult: jest.fn(),
			strings: STRINGS,
		} );
		const initializeTodayQuickAction = compile( 'initializeTodayQuickAction', { submitTodayQuickAction } );

		initializeTodayQuickAction( context );
		context.querySelector( '#task-today-quick' ).click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( global.fetch.mock.calls[ 0 ][ 0 ] ).toContain( '?marked=false' );
		expect( JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body ) ).toEqual( { marked: false } );
	} );

	test( 'a double click sends only one request while submitting', async () => {
		const context = buildDom( { marked: false } );
		let resolveFetch;
		global.fetch.mockReturnValue( new Promise( ( r ) => { resolveFetch = r; } ) );

		const submitTodayQuickAction = compile( 'submitTodayQuickAction', {
			getTaskId: () => '5',
			deckerRestUrl: 'http://x/wp-json/decker/v1/',
			wpApiSettings: window.wpApiSettings,
			setTodayQuickActionLoading: compile( 'setTodayQuickActionLoading', { strings: STRINGS } ),
			onTodayQuickActionSuccess: jest.fn(),
			notifyTodayResult: jest.fn(),
			strings: STRINGS,
		} );
		const initializeTodayQuickAction = compile( 'initializeTodayQuickAction', { submitTodayQuickAction } );

		initializeTodayQuickAction( context );
		const button = context.querySelector( '#task-today-quick' );
		button.click();
		button.click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		resolveFetch( { ok: true, json: () => Promise.resolve( { success: true } ) } );
	} );

	test( 'a dirty form ignores the quick button', () => {
		const context = buildDom( { marked: false } );
		window.deckerHasUnsavedChanges = true;

		const submitTodayQuickAction = jest.fn();
		const initializeTodayQuickAction = compile( 'initializeTodayQuickAction', { submitTodayQuickAction } );
		initializeTodayQuickAction( context );
		context.querySelector( '#task-today-quick' ).click();

		expect( submitTodayQuickAction ).not.toHaveBeenCalled();
	} );

	test( 'success updates local state, releases the lock, notifies and reloads', () => {
		const context = buildDom( { marked: false } );
		const reloadParentView = jest.fn();
		window.deckerReleaseActiveTaskLock = jest.fn();
		window.deckerHasUnsavedChanges = true; // ensure it gets reset
		const notifyTodayResult = jest.fn();
		const dispatched = [];
		document.dispatchEvent = jest.fn( ( e ) => dispatched.push( e ) );

		const onTodayQuickActionSuccess = compile( 'onTodayQuickActionSuccess', {
			notifyTodayResult,
			reloadParentView,
			bootstrap: { Modal: { getInstance: () => null } },
		} );

		onTodayQuickActionSuccess( context, { success: true, marked: true, task_id: 5, user_id: 9, date: '2026-07-10', message: 'Task added to today.' } );

		expect( context.querySelector( '#task-today' ).checked ).toBe( true );
		expect( window.deckerHasUnsavedChanges ).toBe( false );
		expect( window.deckerReleaseActiveTaskLock ).toHaveBeenCalled();
		expect( notifyTodayResult ).toHaveBeenCalledWith( expect.anything(), true );
		expect( dispatched[ 0 ].type ).toBe( 'decker:task-today-changed' );
		expect( reloadParentView ).toHaveBeenCalled();
	} );

	test( 'failure keeps the modal open, restores the button and does not release the lock', async () => {
		const context = buildDom( { marked: false } );
		global.fetch.mockResolvedValue( { ok: false, json: () => Promise.resolve( { success: false, message: 'Nope' } ) } );

		window.deckerReleaseActiveTaskLock = jest.fn();
		const onTodayQuickActionSuccess = jest.fn();
		const notifyTodayResult = jest.fn();
		const setTodayQuickActionLoading = compile( 'setTodayQuickActionLoading', { strings: STRINGS } );
		const submitTodayQuickAction = compile( 'submitTodayQuickAction', {
			getTaskId: () => '5',
			deckerRestUrl: 'http://x/wp-json/decker/v1/',
			wpApiSettings: window.wpApiSettings,
			setTodayQuickActionLoading,
			onTodayQuickActionSuccess,
			notifyTodayResult,
			strings: STRINGS,
		} );

		submitTodayQuickAction( context );
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( onTodayQuickActionSuccess ).not.toHaveBeenCalled();
		expect( notifyTodayResult ).toHaveBeenCalledWith( 'Nope', false );
		expect( window.deckerReleaseActiveTaskLock ).not.toHaveBeenCalled();
		expect( context.querySelector( '#task-today-quick' ).disabled ).toBe( false );
	} );

	test( 'the quick action stays enabled when shared controls are locked', () => {
		const context = buildDom( { marked: false, sharedDisabled: true } );

		// Shared field is disabled by the server, but the quick action is not.
		expect( context.querySelector( '#task-title' ).disabled ).toBe( true );
		expect( context.querySelector( '#task-today-quick' ).disabled ).toBe( false );
	} );
} );
