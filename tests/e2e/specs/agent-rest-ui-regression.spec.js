/**
 * E2E regression: what an agent writes over REST is what the board renders.
 *
 * The agent surface writes through the Abilities REST routes and the Decker
 * `today` and `order` endpoints. Every other test in this repository checks
 * either the stored value or the response payload, so a field that persists
 * correctly but renders wrongly — or not at all — would pass all of them. This
 * spec closes that gap: each change is applied through the same route the
 * client uses, and then asserted in the browser against the real board.
 *
 * What this does NOT do: it does not run the client binary. The client
 * authenticates through CAS, which the deterministic e2e environment does not
 * have; `make test-cas` drives the client itself against a real CAS server and
 * a real WordPress. What is shared between the two is the server surface, and
 * that is what is exercised here: ability run routes with `input`, and the
 * `today` and `order` endpoints over POST.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const BOARD = { name: 'E2E Agent UI Board', slug: 'e2e-agent-ui-board' };
const ASSIGNEE = {
	username: 'agentuiassignee',
	password: 'agent-ui-pass-A-123',
	name: 'Agent UI Assignee',
};

/** Task IDs created by this spec, removed in afterAll. */
const createdTasks = [];

/**
 * Create a taxonomy term, or return the existing one.
 *
 * The fixtures use fixed slugs so the board URL is predictable, which means a
 * second run finds them already there. Creating unconditionally fails with
 * `term_exists`.
 *
 * @param {Object} requestUtils The WordPress request utils.
 * @param {string} taxonomy     REST base of the taxonomy.
 * @param {Object} term         The term name and slug.
 * @return {Promise<Object>} The created or existing term.
 */
async function ensureTerm( requestUtils, taxonomy, term ) {
	try {
		return await requestUtils.rest( {
			path: `/wp/v2/${ taxonomy }`,
			method: 'POST',
			data: term,
		} );
	} catch ( error ) {
		if ( 'term_exists' === error?.code && error?.data?.term_id ) {
			return { id: error.data.term_id, ...term };
		}

		const existing = await requestUtils.rest( {
			path: `/wp/v2/${ taxonomy }?slug=${ term.slug }`,
		} );
		if ( existing?.[ 0 ] ) {
			return existing[ 0 ];
		}

		throw error;
	}
}

/**
 * Run a Decker ability through its REST route, the way the client does.
 *
 * @param {Object} requestUtils The WordPress request utils.
 * @param {string} ability      Ability name, such as `decker/create-task`.
 * @param {Object} input        Ability input.
 * @return {Promise<Object>} The ability result.
 */
async function runAbility( requestUtils, ability, input ) {
	const result = await requestUtils.rest( {
		path: `/wp-abilities/v1/abilities/${ ability }/run`,
		method: 'POST',
		data: { input },
	} );

	if ( 'decker/create-task' === ability && result?.id ) {
		createdTasks.push( result.id );
	}

	return result;
}

test.describe( 'Agent REST writes render on the board', () => {
	let boardId;
	let assigneeId;
	let adminId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		const board = await ensureTerm( requestUtils, 'decker_board', BOARD );
		boardId = board.id;

		const assignee = await requestUtils
			.rest( {
				path: '/wp/v2/users',
				method: 'POST',
				data: {
					username: ASSIGNEE.username,
					password: ASSIGNEE.password,
					name: ASSIGNEE.name,
					email: 'agentuiassignee@example.com',
					roles: [ 'editor' ],
				},
			} )
			.catch( async () => {
				const found = await requestUtils.rest( {
					path: `/wp/v2/users?search=${ ASSIGNEE.username }`,
				} );
				return found[ 0 ];
			} );
		assigneeId = assignee.id;

		const me = await requestUtils.rest( { path: '/wp/v2/users/me' } );
		adminId = me.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// The tests database is shared with every other spec, and this one
		// creates a max-priority task, which is exactly what the Priority smoke
		// test looks for. Leaving the fixtures behind made that test fail.
		for ( const id of createdTasks ) {
			await requestUtils
				.rest( { path: `/wp/v2/tasks/${ id }?force=true`, method: 'DELETE' } )
				.catch( () => {} );
		}
		createdTasks.length = 0;
	} );

	/**
	 * Open the board and return the locator for a task card.
	 *
	 * @param {Object} page    The Playwright page.
	 * @param {number} taskId  The task ID.
	 * @param {string} stack   The stack the card is expected in.
	 * @return {Object} The card locator.
	 */
	async function openBoard( page, taskId, stack = 'to-do' ) {
		await page.goto( `/?decker_page=board&slug=${ BOARD.slug }` );
		await expect( page.locator( `#task-list-${ stack }` ) ).toBeVisible();

		return page.locator(
			`#task-list-${ stack } .task.card[data-task-id="${ taskId }"]`
		);
	}

	test( 'a task created over REST appears on the board with its title', async ( {
		page,
		requestUtils,
	} ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Created by the agent',
			board_id: boardId,
			stack: 'to-do',
			description: 'Body written over REST',
		} );

		const card = await openBoard( page, task.id );

		await expect( card ).toBeVisible();
		await expect( card ).toContainText( 'Created by the agent' );
	} );

	test( 'labels set over REST render on the card', async ( { page, requestUtils } ) => {
		const label = await ensureTerm( requestUtils, 'labels', {
			name: 'Agent UI Label',
			slug: 'agent-ui-label',
		} );

		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent labelled task',
			board_id: boardId,
			stack: 'to-do',
			label_ids: [ label.id ],
		} );

		const card = await openBoard( page, task.id );

		// The card renders its labels into the popover's data attribute, which is
		// what the popover shows when opened, so that is where the name has to
		// be — it is not in the card's text.
		const popover = card.locator( '.decker-labels-popover' );
		await expect( popover ).toHaveCount( 1 );
		await expect( popover ).toHaveAttribute(
			'data-decker-labels-content',
			/Agent UI Label/
		);
		await expect( popover ).toHaveAttribute( 'data-decker-labels-count', '1' );
	} );

	test( 'assignees and the responsible user render, and are told apart', async ( {
		page,
		requestUtils,
	} ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent people task',
			board_id: boardId,
			stack: 'to-do',
			assignee_ids: [ assigneeId, adminId ],
			responsible_user_id: assigneeId,
		} );

		const card = await openBoard( page, task.id );

		// Both people are shown.
		await expect( card.locator( '.avatar-group .avatar-group-item' ) ).toHaveCount( 2 );

		// And the responsible one is marked as such, not merely present. The
		// three roles are distinct in the data, so they must be distinct here.
		const responsible = card.locator(
			`.avatar-group-item-responsable[aria-label="${ ASSIGNEE.name }"]`
		);
		await expect( responsible ).toHaveCount( 1 );
	} );

	// The titles below deliberately avoid the words the Decker views use as
	// section headings. "Agent max priority" collided with the Priority view's
	// own "MAX PRIORITY" heading and broke the unrelated smoke test through a
	// getByText match, which cleanup alone would not prevent after a crashed run.
	test( 'max priority set over REST renders as a priority card', async ( {
		page,
		requestUtils,
	} ) => {
		const normal = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent plain card',
			board_id: boardId,
			stack: 'in-progress',
		} );
		const urgent = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent urgent card',
			board_id: boardId,
			stack: 'in-progress',
			max_priority: true,
		} );

		await page.goto( `/?decker_page=board&slug=${ BOARD.slug }` );
		const column = page.locator( '#task-list-in-progress' );

		await expect(
			column.locator( `.task.card[data-task-id="${ urgent.id }"] .bg-danger-subtle` )
		).toHaveCount( 1 );
		await expect(
			column.locator( `.task.card[data-task-id="${ normal.id }"] .bg-danger-subtle` )
		).toHaveCount( 0 );
	} );

	test( 'a move over REST relocates the card', async ( { page, requestUtils } ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent moved task',
			board_id: boardId,
			stack: 'to-do',
		} );

		await expect( await openBoard( page, task.id, 'to-do' ) ).toBeVisible();

		await runAbility( requestUtils, 'decker/move-task', {
			task_id: task.id,
			stack: 'done',
		} );

		await expect( await openBoard( page, task.id, 'done' ) ).toBeVisible();
		await expect(
			page.locator( `#task-list-to-do .task.card[data-task-id="${ task.id }"]` )
		).toHaveCount( 0 );
	} );

	test( 'a reorder over REST changes the rendered sequence', async ( {
		page,
		requestUtils,
	} ) => {
		const board = await ensureTerm( requestUtils, 'decker_board', {
			name: 'E2E Agent Order Board',
			slug: 'e2e-agent-order-board',
		} );

		const cards = [];
		for ( const title of [ 'Order one', 'Order two', 'Order three' ] ) {
			const created = await runAbility( requestUtils, 'decker/create-task', {
				title,
				board_id: board.id,
				stack: 'to-do',
			} );
			cards.push( created.id );
		}

		await page.goto( `/?decker_page=board&slug=e2e-agent-order-board` );
		const sequence = async () =>
			( await page
				.locator( '#task-list-to-do .task.card' )
				.evaluateAll( ( nodes ) => nodes.map( ( n ) => Number( n.dataset.taskId ) ) )
			).filter( ( id ) => cards.includes( id ) );

		// Filtered to this run's own cards: a re-run finds the board already
		// populated, and the invariant is about these three.
		expect( await sequence() ).toEqual( cards );

		// The real position, read from the board, not a hardcoded 3: the stack
		// may hold cards from an earlier run, and inventing a position is
		// exactly what the client is written never to do.
		const all = await page
			.locator( '#task-list-to-do .task.card' )
			.evaluateAll( ( nodes ) => nodes.map( ( n ) => Number( n.dataset.taskId ) ) );
		const sourceOrder = all.indexOf( cards[ 2 ] ) + 1;
		expect( sourceOrder ).toBeGreaterThan( 0 );

		await requestUtils.rest( {
			path: `/decker/v1/tasks/${ cards[ 2 ] }/order`,
			method: 'POST',
			data: {
				board_id: board.id,
				source_stack: 'to-do',
				target_stack: 'to-do',
				source_order: sourceOrder,
				target_order: 1,
			},
		} );

		await page.goto( `/?decker_page=board&slug=e2e-agent-order-board` );
		const after = await sequence();

		// The rendered sequence, not just the stored column. This is the
		// assertion that would have caught the stale post cache.
		expect( after[ 0 ] ).toBe( cards[ 2 ] );
		expect( after ).toHaveLength( 3 );
		expect( [ ...after ].sort() ).toEqual( [ ...cards ].sort() );
	} );

	test( 'a placement by neighbour id renders where it was asked', async ( {
		page,
		requestUtils,
	} ) => {
		const board = await ensureTerm( requestUtils, 'decker_board', {
			name: 'E2E Agent Place Board',
			slug: 'e2e-agent-place-board',
		} );

		const cards = [];
		for ( const title of [ 'Place one', 'Place two', 'Place three' ] ) {
			const created = await runAbility( requestUtils, 'decker/create-task', {
				title,
				board_id: board.id,
				stack: 'to-do',
			} );
			cards.push( created.id );
		}

		const sequence = async () => {
			await page.goto( '/?decker_page=board&slug=e2e-agent-place-board' );
			return ( await page
				.locator( '#task-list-to-do .task.card' )
				.evaluateAll( ( nodes ) => nodes.map( ( n ) => Number( n.dataset.taskId ) ) )
			).filter( ( id ) => cards.includes( id ) );
		};

		expect( await sequence() ).toEqual( cards );

		// No index anywhere: the neighbour is an id, so this needs nothing read
		// from the board first. That is the whole point of the route.
		const placed = await requestUtils.rest( {
			path: `/decker/v1/tasks/${ cards[ 0 ] }/place`,
			method: 'POST',
			data: { after_task_id: cards[ 2 ] },
		} );
		expect( placed.adjacent ).toBe( true );

		// The rendered board, not the stored column.
		const after = await sequence();
		expect( after ).toEqual( [ cards[ 1 ], cards[ 2 ], cards[ 0 ] ] );
	} );

	test( 'a "for today" mark over REST shows on the card', async ( {
		page,
		requestUtils,
	} ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent today task',
			board_id: boardId,
			stack: 'to-do',
			assignee_ids: [ adminId ],
		} );

		let card = await openBoard( page, task.id );
		await expect( card.locator( '.avatar-group-item.today' ) ).toHaveCount( 0 );

		// POST, not PUT: the route accepts both and a PUT body does not survive
		// every proxy, so POST is the transport the client uses.
		await requestUtils.rest( {
			path: `/decker/v1/tasks/${ task.id }/today`,
			method: 'POST',
			data: { marked: true },
		} );

		card = await openBoard( page, task.id );
		await expect( card.locator( '.avatar-group-item.today' ) ).toHaveCount( 1 );
	} );

	test( 'archiving over REST takes the card off the board, and restoring brings it back', async ( {
		page,
		requestUtils,
	} ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent archived task',
			board_id: boardId,
			stack: 'to-do',
		} );

		await expect( await openBoard( page, task.id ) ).toBeVisible();

		await runAbility( requestUtils, 'decker/archive-task', {
			task_id: task.id,
			archived: true,
		} );

		await page.goto( `/?decker_page=board&slug=${ BOARD.slug }` );
		await expect( page.locator( `#task-list-to-do` ) ).toBeVisible();
		await expect(
			page.locator( `.task.card[data-task-id="${ task.id }"]` )
		).toHaveCount( 0 );

		await runAbility( requestUtils, 'decker/archive-task', {
			task_id: task.id,
			archived: false,
		} );

		// Reversible, which is why there is no delete command at all.
		await expect( await openBoard( page, task.id ) ).toBeVisible();
	} );

	test( 'an edited title over REST replaces the rendered one', async ( {
		page,
		requestUtils,
	} ) => {
		const task = await runAbility( requestUtils, 'decker/create-task', {
			title: 'Agent title before',
			board_id: boardId,
			stack: 'to-do',
		} );

		await runAbility( requestUtils, 'decker/update-task', {
			task_id: task.id,
			title: 'Agent title after',
		} );

		const card = await openBoard( page, task.id );

		await expect( card ).toContainText( 'Agent title after' );
		await expect( card ).not.toContainText( 'Agent title before' );
	} );
} );
