/**
 * E2E tests for the Decker Kanban board.
 *
 * Covers the two most important board flows:
 *   1. The board view renders its three stack columns.
 *   2. A task created through the real task form lands in the board's TO-DO
 *      column (exercising the full front-end create + save + render round-trip).
 *
 * Note: task meta such as `stack` is written by the plugin's own form-save
 * handler, not by the generic REST meta API, so a task must be created through
 * the UI to be placed in a stack column. Creating it via REST would leave it
 * with an empty stack and it would not appear in any column.
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
