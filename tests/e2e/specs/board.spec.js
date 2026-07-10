/**
 * E2E tests for the Decker Kanban board.
 *
 * Covers the most important board flows:
 *   1. The board view renders its three stack columns.
 *   2. A task created through the real task form lands in the board's TO-DO
 *      column (exercising the full front-end create + save + render round-trip).
 *   3. A task created over REST with an explicit stack renders in that column
 *      (regression coverage for the task meta being exposed over REST).
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Decker Kanban board', () => {
	let boardId;
	let boardSlug;
	const createdTaskIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		const board = await requestUtils.rest( {
			path: '/wp/v2/decker_board',
			method: 'POST',
			data: { name: 'E2E Board', slug: 'e2e-board' },
		} );
		boardId = board.id;
		boardSlug = board.slug;
	} );

	test( 'renders the three stack columns and the create action', async ( { page } ) => {
		await page.goto( `/?decker_page=board&slug=${ boardSlug }` );

		await expect( page.locator( '#task-list-to-do' ) ).toBeVisible();
		await expect( page.locator( '#task-list-in-progress' ) ).toBeVisible();
		await expect( page.locator( '#task-list-done' ) ).toBeVisible();

		// The column headers name each stack.
		await expect( page.getByRole( 'heading', { name: /TO-DO/i } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: /In Progress/i } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: /Done/i } ) ).toBeVisible();

		// The "Add New Task" entry point is available.
		await expect(
			page.getByRole( 'link', { name: /Add New Task/i } )
		).toBeVisible();
	} );

	test( 'a task created through the form appears in the TO-DO column', async ( { page } ) => {
		const title = `Board Task ${ Date.now() }`;

		// Open the standalone "new task" form.
		await page.goto( '/?decker_page=task' );

		await page.fill( '#task-title', title );
		await page.selectOption( '#task-board', String( boardId ) );
		await page.selectOption( '#task-stack', 'to-do' );
		// Responsable is required; pick the first real user (index 0 is the
		// disabled placeholder option).
		await page.selectOption( '#task-responsable', { index: 1 } );
		// Due date is required for a full save.
		await page.fill( '#task-due-date', '2026-12-31' );

		// Saving a standalone task redirects to its permalink (?...&id=NN).
		await Promise.all( [
			page.waitForURL( /decker_page=task&id=\d+/ ),
			page.locator( '#save-task' ).click(),
		] );

		const newId = new URL( page.url() ).searchParams.get( 'id' );
		expect( newId ).toBeTruthy();
		createdTaskIds.push( newId );

		// The task now shows as a card inside the board's TO-DO column.
		await page.goto( `/?decker_page=board&slug=${ boardSlug }` );

		const todoColumn = page.locator( '#task-list-to-do' );
		const card = todoColumn.locator( `.task.card[data-task-id="${ newId }"]` );
		await expect( card ).toBeVisible();
		await expect( card.getByText( title, { exact: false } ) ).toBeVisible();
	} );

	test( 'a task created over REST with a stack renders in that column', async ( { requestUtils, page } ) => {
		const title = `REST Board Task ${ Date.now() }`;

		const task = await requestUtils.rest( {
			path: '/wp/v2/tasks',
			method: 'POST',
			data: {
				title,
				status: 'publish',
				decker_board: [ boardId ],
				meta: { stack: 'in-progress', max_priority: false, duedate: '2026-12-31' },
			},
		} );
		createdTaskIds.push( task.id );

		await page.goto( `/?decker_page=board&slug=${ boardSlug }` );

		// The stack was honoured, so the card is in the In Progress column and
		// not in TO-DO.
		const card = page.locator( `#task-list-in-progress .task.card[data-task-id="${ task.id }"]` );
		await expect( card ).toBeVisible();
		await expect(
			page.locator( `#task-list-to-do .task.card[data-task-id="${ task.id }"]` )
		).toHaveCount( 0 );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of createdTaskIds ) {
			await requestUtils.rest( {
				path: `/wp/v2/tasks/${ id }`,
				method: 'DELETE',
				data: { force: true },
			} ).catch( () => {} );
		}
		if ( boardId ) {
			await requestUtils.rest( {
				path: `/wp/v2/decker_board/${ boardId }`,
				method: 'DELETE',
				data: { force: true },
			} ).catch( () => {} );
		}
		await requestUtils.deactivatePlugin( 'decker' );
	} );
} );
