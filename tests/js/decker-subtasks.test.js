/**
 * Unit tests for the subtask pane in decker-subtasks.js.
 *
 * The module is an IIFE that publishes window.deckerInitSubtasks, so the real
 * file is imported once with its globals stubbed and then driven through the
 * DOM, rather than having its functions copied here.
 *
 * @package Decker
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';

/* eslint-disable no-undef */

// The globals the module reads are installed before it is imported: the IIFE
// runs at import time. Importing it (rather than evaluating its source by hand)
// is what lets the coverage reporter instrument the file.
global.deckerVars = { strings: {}, home_url: 'https://example.test/' };
global.wpApiSettings = {
	root: 'https://example.test/wp-json/',
	nonce: 'abc',
};
// Sortable is loaded globally in production; the pane degrades to a
// non-draggable list without it, which is what the tests exercise.
global.Sortable = undefined;

await import( '../../public/assets/js/decker-subtasks.js' );

/**
 * Render the card markup the pane expects.
 *
 * @param {Array}  items    The stored subtask items.
 * @param {Object} options  Extra rendering options.
 * @return {HTMLElement} The card context element.
 */
function renderCard( items, options = {} ) {
	const people = options.people || [ { id: 7, name: 'Fran' } ];
	const readOnly = options.readOnly ? '1' : '0';

	document.body.innerHTML = `
		<div id="card">
			<form id="task-form" data-lock='{"generation":"gen-1"}'>
				<input id="task-due-date" type="date" value="${ options.taskDueDate ?? '' }">
			</form>
			<span class="badge" id="subtask-count">0/0</span>
			<div class="decker-subtasks"
				data-task-id="${ options.taskId ?? 42 }"
				data-readonly="${ readOnly }"
				data-items='${ JSON.stringify( items ) }'
				data-people='${ JSON.stringify( people ) }'>
				<div class="progress decker-subtasks-progress"></div>
				<small class="decker-subtasks-summary"></small>
				<div class="decker-subtasks-list"></div>
				<p class="decker-subtasks-empty d-none">Empty</p>
				<div class="decker-subtasks-add">
					<input type="text" class="decker-subtask-new">
					<button type="button" class="decker-subtask-add-btn">Add</button>
				</div>
				<div class="decker-subtasks-status"></div>
			</div>
		</div>
	`;

	return document.getElementById( 'card' );
}

/**
 * Build a stored item with the shape the server returns.
 *
 * @param {Object} overrides Fields to override.
 * @return {Object} The item.
 */
function item( overrides = {} ) {
	return {
		id: 1,
		title: 'Step',
		status: 'to-do',
		assignee: 0,
		duedate: '',
		task_id: 0,
		...overrides,
	};
}

/**
 * Resolve the pending microtasks so a fetch chain settles.
 *
 * @return {Promise} A promise resolved after the queue drains.
 */
function settle() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * The payload of the last fetch call.
 *
 * @return {Object} The parsed request body.
 */
function lastBody() {
	const calls = global.fetch.mock.calls;
	return JSON.parse( calls[ calls.length - 1 ][ 1 ].body );
}

/**
 * Make fetch answer with a stored list, echoing what was submitted.
 */
function mockFetchEcho() {
	global.fetch = vi.fn( ( url, options ) => {
		const body = JSON.parse( options.body );
		const items = ( body.items || [] ).map( ( entry, index ) => ( {
			...item(),
			...entry,
			id: entry.id || index + 1,
		} ) );

		return Promise.resolve( {
			ok: true,
			json: () =>
				Promise.resolve( {
					success: true,
					items,
					progress: {
						total: items.length,
						done: items.filter( ( i ) => i.status === 'done' ).length,
						counts: {},
						percent: 0,
					},
				} ),
		} );
	} );
}

describe( 'subtask pane', () => {
	beforeEach( () => {
		mockFetchEcho();
		vi.useRealTimers();
		global.deckerVars.current_user_id = 0;
		// Deleting a row asks first; the tests that do not care about the
		// question answer yes.
		window.confirm = vi.fn( () => true );
		window.deckerSubtasksChangedInSession = false;
	} );

	test( 'renders one row per stored item', () => {
		const context = renderCard( [ item(), item( { id: 2, title: 'Second' } ) ] );
		window.deckerInitSubtasks( context );

		const rows = context.querySelectorAll( '.decker-subtask-row' );
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 1 ].querySelector( '.decker-subtask-title' ).value ).toBe(
			'Second'
		);
	} );

	test( 'shows the empty state and no rows for a task without subtasks', () => {
		const context = renderCard( [] );
		window.deckerInitSubtasks( context );

		expect( context.querySelectorAll( '.decker-subtask-row' ) ).toHaveLength( 0 );
		expect(
			context.querySelector( '.decker-subtasks-empty' ).classList.contains( 'd-none' )
		).toBe( false );
	} );

	test( 'updates the tab badge from the stored list', () => {
		const context = renderCard( [
			item( { status: 'done' } ),
			item( { id: 2 } ),
			item( { id: 3 } ),
		] );
		window.deckerInitSubtasks( context );

		expect( context.querySelector( '#subtask-count' ).textContent ).toBe( '1/3' );
	} );

	test( 'draws one progress segment per represented state', () => {
		const context = renderCard( [
			item( { status: 'done' } ),
			item( { id: 2, status: 'in-progress' } ),
			item( { id: 3, status: 'to-do' } ),
			item( { id: 4, status: 'to-do' } ),
		] );
		window.deckerInitSubtasks( context );

		const segments = context.querySelectorAll(
			'.decker-subtasks-progress .progress-bar'
		);
		expect( segments ).toHaveLength( 3 );
		expect( segments[ 0 ].className ).toContain( 'bg-success' );
		expect( segments[ 1 ].className ).toContain( 'bg-warning' );
		expect( segments[ 2 ].className ).toContain( 'bg-secondary' );
	} );

	test( 'cycles an item through the three states and saves each step', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		const button = context.querySelector( '.decker-subtask-status' );

		button.click();
		await settle();
		expect( lastBody().items[ 0 ].status ).toBe( 'in-progress' );

		button.click();
		await settle();
		expect( lastBody().items[ 0 ].status ).toBe( 'done' );

		button.click();
		await settle();
		expect( lastBody().items[ 0 ].status ).toBe( 'to-do' );
	} );

	test( 'sends the form lock generation with every write', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-status' ).click();
		await settle();

		expect( lastBody().lock_generation ).toBe( 'gen-1' );
	} );

	test( 'adds one item per line when several lines are pasted', async () => {
		const context = renderCard( [] );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		const event = new window.Event( 'paste', { bubbles: true, cancelable: true } );
		event.clipboardData = {
			getData: () => 'Primero\nSegundo\n\n  Tercero  \n',
		};
		input.dispatchEvent( event );
		await settle();

		expect( lastBody().items.map( ( i ) => i.title ) ).toEqual( [
			'Primero',
			'Segundo',
			'Tercero',
		] );
		expect( context.querySelectorAll( '.decker-subtask-row' ) ).toHaveLength( 3 );
	} );

	test( 'a single-line paste is left to the browser', async () => {
		const context = renderCard( [] );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		const event = new window.Event( 'paste', { bubbles: true, cancelable: true } );
		event.clipboardData = { getData: () => 'Just one line' };
		input.dispatchEvent( event );
		await settle();

		expect( event.defaultPrevented ).toBe( false );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	test( 'Enter adds the typed item without submitting the card form', async () => {
		const context = renderCard( [] );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		input.value = 'Typed step';

		const event = new window.KeyboardEvent( 'keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		} );
		input.dispatchEvent( event );
		await settle();

		expect( event.defaultPrevented ).toBe( true );
		expect( lastBody().items[ 0 ].title ).toBe( 'Typed step' );
		expect( input.value ).toBe( '' );
	} );

	test( 'deleting a row drops it from the saved list', async () => {
		const context = renderCard( [ item(), item( { id: 2, title: 'Second' } ) ] );
		window.deckerInitSubtasks( context );

		context
			.querySelectorAll( '.decker-subtask-row' )[ 0 ]
			.querySelector( '.decker-subtask-delete' )
			.click();
		await settle();

		expect( window.confirm ).toHaveBeenCalled();
		expect( lastBody().items.map( ( i ) => i.title ) ).toEqual( [ 'Second' ] );
	} );

	test( 'a stored change asks the board behind the modal to refresh', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		expect( window.deckerSubtasksChangedInSession ).toBeFalsy();
		context.querySelector( '.decker-subtask-status' ).click();
		await settle();

		// The chips, the counter and the faces on them are PHP-rendered, so
		// the card behind the modal is stale until the view reloads.
		expect( window.deckerSubtasksChangedInSession ).toBe( true );
	} );

	test( 'the list is written with a method every host forwards', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-status' ).click();
		await settle();

		// Not PUT: some hosts — WordPress Playground among them — never hand
		// PHP the body of a PUT, and the whole list travels in that body.
		expect( global.fetch.mock.calls[ 0 ][ 1 ].method ).toBe( 'POST' );
	} );

	test( 'a delete the user calls off changes nothing', async () => {
		window.confirm.mockReturnValue( false );
		const context = renderCard( [ item(), item( { id: 2, title: 'Second' } ) ] );
		window.deckerInitSubtasks( context );

		context
			.querySelectorAll( '.decker-subtask-row' )[ 0 ]
			.querySelector( '.decker-subtask-delete' )
			.click();
		await settle();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect(
			context.querySelectorAll( '.decker-subtask-row' )
		).toHaveLength( 2 );
	} );

	test( 'the assignee select only offers the people on the card', () => {
		const context = renderCard( [ item( { assignee: 7 } ) ], {
			people: [
				{ id: 7, name: 'Fran' },
				{ id: 9, name: 'Humberto' },
			],
		} );
		window.deckerInitSubtasks( context );

		const select = context.querySelector( '.decker-subtask-assignee' );
		expect(
			Array.from( select.options ).map( ( option ) => option.value )
		).toEqual( [ '0', '7', '9' ] );
		expect( select.value ).toBe( '7' );
	} );

	test( 'an unfinished item past its date is flagged', () => {
		const context = renderCard( [ item( { duedate: '2000-01-01' } ) ] );
		window.deckerInitSubtasks( context );

		const field = context.querySelector( '.decker-subtask-duedate' );

		// Its own class: Bootstrap's is-invalid paints an error icon inside
		// the field, which read as a rejected value rather than a late one.
		expect( field.classList.contains( 'decker-subtask-overdue' ) ).toBe(
			true
		);
		expect( field.classList.contains( 'is-invalid' ) ).toBe( false );
	} );

	test( 'a finished item past its date is not flagged', () => {
		const context = renderCard( [
			item( { duedate: '2000-01-01', status: 'done' } ),
		] );
		window.deckerInitSubtasks( context );

		expect(
			context
				.querySelector( '.decker-subtask-duedate' )
				.classList.contains( 'decker-subtask-overdue' )
		).toBe( false );
	} );

	test( 'a read-only card offers no editing controls', () => {
		const context = renderCard( [ item() ], { readOnly: true } );
		window.deckerInitSubtasks( context );

		expect( context.querySelector( '.decker-subtask-status' ).disabled ).toBe(
			true
		);
		expect( context.querySelector( '.decker-subtask-title' ).readOnly ).toBe(
			true
		);
		expect( context.querySelector( '.decker-subtask-delete' ) ).toBeNull();
		expect( context.querySelector( '.decker-subtask-handle' ) ).toBeNull();
	} );

	test( 'a promoted item is frozen and links to its task', () => {
		const context = renderCard( [ item( { task_id: 99 } ) ] );
		window.deckerInitSubtasks( context );

		expect( context.querySelector( '.decker-subtask-title' ).readOnly ).toBe(
			true
		);
		expect( context.querySelector( '.decker-subtask-convert' ) ).toBeNull();
		expect( context.querySelector( '.decker-subtask-row a' ).href ).toContain(
			'id=99'
		);
	} );

	test( 'the pane adopts the list the server stored', async () => {
		global.fetch = vi.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () =>
					Promise.resolve( {
						success: true,
						// The server dropped the blank title the client sent.
						items: [ item( { title: 'Kept' } ) ],
						progress: { total: 1, done: 0, counts: {}, percent: 0 },
					} ),
			} )
		);

		const context = renderCard( [ item( { title: 'Kept' } ) ] );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		input.value = '   ';
		context.querySelector( '.decker-subtask-add-btn' ).click();
		await settle();

		expect( context.querySelectorAll( '.decker-subtask-row' ) ).toHaveLength( 1 );
	} );

	test( 'a rejected write is reported and nothing is silently lost', async () => {
		global.fetch = vi.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () =>
					Promise.resolve( {
						success: false,
						code: 'decker_task_locked',
						message: 'This card is locked.',
					} ),
			} )
		);

		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-status' ).click();
		await settle();

		const status = context.querySelector( '.decker-subtasks-status' );
		expect( status.textContent ).toBe( 'This card is locked.' );
		expect( status.classList.contains( 'text-danger' ) ).toBe( true );
	} );

	test( 'a new card does not write anything', async () => {
		const context = renderCard( [], { taskId: 0 } );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		input.value = 'Step';
		context.querySelector( '.decker-subtask-add-btn' ).click();
		await settle();

		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	test( 'the confirmation is a SweetAlert dialog where there is one', async () => {
		const fire = vi.fn( () => Promise.resolve( { isConfirmed: false } ) );
		window.Swal = { fire };
		const context = renderCard( [ item( { title: 'Second pass' } ) ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-delete' ).click();
		await settle();

		expect( window.confirm ).not.toHaveBeenCalled();
		expect( fire.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			text: 'Second pass',
			icon: 'warning',
			showCancelButton: true,
		} );
		// It answered no, so the row is still there.
		expect(
			context.querySelectorAll( '.decker-subtask-row' )
		).toHaveLength( 1 );
		delete window.Swal;
	} );

	test( 'promoting a subtask asks first, and a no promotes nothing', async () => {
		window.confirm.mockReturnValue( false );
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-convert' ).click();
		await settle();
		await settle();

		expect( window.confirm ).toHaveBeenCalled();
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	test( 'converting writes the list first, then promotes', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-convert' ).click();
		await settle();
		await settle();

		const urls = global.fetch.mock.calls.map( ( call ) => call[ 0 ] );
		expect( urls[ 0 ] ).toContain( '/tasks/42/subtasks' );
		expect( urls[ 0 ] ).not.toContain( '/convert' );
		expect( urls[ 1 ] ).toContain( '/tasks/42/subtasks/1/convert' );
	} );

	test( 'a list that could not be saved is not converted', async () => {
		// The reported failure: a write had been rejected, so the item the row
		// showed had never reached the server, and converting it came back
		// "subtask not found" — blaming the wrong step.
		global.fetch = vi.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () =>
					Promise.resolve( {
						success: false,
						code: 'decker_task_locked',
						message: 'This card is locked.',
					} ),
			} )
		);

		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-convert' ).click();
		await settle();
		await settle();

		// Only the write was attempted, and its error is what is reported.
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( global.fetch.mock.calls[ 0 ][ 0 ] ).not.toContain( '/convert' );
		expect(
			context.querySelector( '.decker-subtasks-status' ).textContent
		).toBe( 'This card is locked.' );
	} );

	test( 'the assigned person’s picture is shown, and hidden when nobody is', async () => {
		const context = renderCard( [ item( { assignee: 7 } ) ], {
			people: [ { id: 7, name: 'Fran', avatar: 'https://example.test/fran.png' } ],
		} );
		window.deckerInitSubtasks( context );

		const avatar = context.querySelector( '.decker-subtask-avatar' );
		expect( avatar.getAttribute( 'src' ) ).toBe( 'https://example.test/fran.png' );
		expect( avatar.hidden ).toBe( false );
		expect( avatar.getAttribute( 'alt' ) ).toBe( '' );

		const select = context.querySelector( '.decker-subtask-assignee' );
		select.value = '0';
		select.dispatchEvent( new window.Event( 'change' ) );
		await settle();

		expect( avatar.hidden ).toBe( true );
		expect( avatar.hasAttribute( 'src' ) ).toBe( false );
	} );

	test( 'a new item starts out the viewer’s, due when the task is', async () => {
		global.deckerVars.current_user_id = 7;

		const context = renderCard( [], {
			people: [ { id: 7, name: 'Fran' } ],
			taskDueDate: '2026-12-01',
		} );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-new' ).value = 'Typed step';
		context.querySelector( '.decker-subtask-add-btn' ).click();
		await settle();

		expect( lastBody().items[ 0 ].assignee ).toBe( 7 );
		expect( lastBody().items[ 0 ].duedate ).toBe( '2026-12-01' );
	} );

	test( 'a viewer who is not on the card is not assigned by default', async () => {
		global.deckerVars.current_user_id = 99;

		const context = renderCard( [], { people: [ { id: 7, name: 'Fran' } ] } );
		window.deckerInitSubtasks( context );

		context.querySelector( '.decker-subtask-new' ).value = 'Typed step';
		context.querySelector( '.decker-subtask-add-btn' ).click();
		await settle();

		expect( lastBody().items[ 0 ].assignee ).toBe( 0 );
		expect( lastBody().items[ 0 ].duedate ).toBe( '' );
	} );

	test( 'the assignee field becomes the card’s own picker, with the face on it', () => {
		const created = [];
		global.Choices = class {
			constructor( element, config ) {
				created.push( { element, config } );
			}
			destroy() {}
		};
		global.Choices.defaults = {
			templates: {
				item: () => document.createElement( 'div' ),
				choice: () => document.createElement( 'div' ),
			},
		};

		try {
			const context = renderCard( [ item( { assignee: 7 } ) ], {
				people: [
					{ id: 7, name: 'Fran', avatar: 'https://example.test/fran.png' },
				],
			} );
			window.deckerInitSubtasks( context );

			expect( created ).toHaveLength( 1 );
			expect(
				created[ 0 ].element.classList.contains( 'decker-subtask-assignee' )
			).toBe( true );

			const templates = created[ 0 ].config.callbackOnCreateTemplates();
			const data = {
				customProperties: { avatar: 'https://example.test/fran.png' },
			};

			[ 'item', 'choice' ].forEach( ( name ) => {
				const rendered = templates[ name ].call( {}, {}, data );
				expect( rendered.firstChild.getAttribute( 'src' ) ).toBe(
					'https://example.test/fran.png'
				);
			} );

			// Nothing to show for the unassigned entry, so no broken image.
			expect(
				templates.item.call( {}, {}, {} ).querySelector( 'img' )
			).toBe( null );
		} finally {
			delete global.Choices;
		}
	} );

	test( 'a slow write does not undo what was typed while it was away', async () => {
		// The reply to the first write describes the list as it was before the
		// second item existed, and it lands last.
		const replies = [];
		global.fetch = vi.fn( ( url, options ) => {
			const sent = JSON.parse( options.body ).items;
			return new Promise( ( resolve ) => {
				replies.push( () =>
					resolve( {
						ok: true,
						json: () =>
							Promise.resolve( {
								success: true,
								items: sent,
								progress: {
									total: sent.length,
									done: 0,
									counts: {},
									percent: 0,
								},
							} ),
					} )
				);
			} );
		} );

		const context = renderCard( [] );
		window.deckerInitSubtasks( context );

		const input = context.querySelector( '.decker-subtask-new' );
		const add = context.querySelector( '.decker-subtask-add-btn' );

		input.value = 'First';
		add.click();
		input.value = 'Second';
		add.click();
		expect( replies ).toHaveLength( 2 );

		replies[ 1 ]();
		await settle();
		replies[ 0 ]();
		await settle();

		expect(
			Array.from( context.querySelectorAll( '.decker-subtask-title' ) ).map(
				( field ) => field.value
			)
		).toEqual( [ 'First', 'Second' ] );
	} );

	test( 'typing a title is debounced into a single write', async () => {
		const context = renderCard( [ item() ] );
		window.deckerInitSubtasks( context );

		const title = context.querySelector( '.decker-subtask-title' );
		title.value = 'Ste';
		title.dispatchEvent( new window.Event( 'input' ) );
		title.value = 'Step two';
		title.dispatchEvent( new window.Event( 'input' ) );

		expect( global.fetch ).not.toHaveBeenCalled();

		// The blur flush is what guarantees the last keystrokes are not lost
		// when the modal closes before the debounce window elapses.
		title.dispatchEvent( new window.Event( 'blur' ) );
		await settle();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( lastBody().items[ 0 ].title ).toBe( 'Step two' );
	} );
} );
