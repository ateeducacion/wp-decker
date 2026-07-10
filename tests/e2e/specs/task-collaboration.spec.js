/**
 * E2E Tests for Decker Task Collaboration
 *
 * Single-user scenarios testing that collaborative editing infrastructure
 * works correctly: status bar, Quill content preservation, form field
 * sync, checkbox values, empty fields, and session cleanup.
 *
 * NOTE: This spec is excluded from the default (CI) e2e run because it depends
 * on the collaborative editing feature, which (a) is disabled by default, and
 * (b) loads Yjs from an external CDN (esm.sh) and dials an external signalling
 * server (signaling.yjs.dev). To keep CI deterministic it is only collected
 * when `DECKER_E2E_COLLAB=1` is set. Run it locally with:
 *
 *     make test-e2e-collab
 *
 * which enables the `collaborative_editing` setting, runs this spec, and then
 * restores the setting.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Task Collaboration', () => {
	let boardId;
	let taskId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		// Create a board (taxonomy term) for our test tasks
		const boardRes = await requestUtils.rest( {
			path: '/wp/v2/decker_board',
			method: 'POST',
			data: {
				name: 'E2E Collab Board',
				slug: 'e2e-collab-board',
			},
		} );
		boardId = boardRes.id;
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		// Create a fresh task for each test
		const taskRes = await requestUtils.rest( {
			path: '/wp/v2/tasks',
			method: 'POST',
			data: {
				title: 'Collab Test Task',
				content: '<p>Test description content</p>',
				status: 'publish',
				decker_board: [ boardId ],
				meta: {
					stack: 'to-do',
					max_priority: '0',
					duedate: '',
				},
			},
		} );
		taskId = taskRes.id;
	} );

	test( 'task page loads with collaboration status bar', async ( { page } ) => {
		await page.goto( `/?decker_page=task&id=${ taskId }` );

		// Wait for the collaboration module to initialize
		const statusBar = page.locator( '.decker-collab-status' );
		await expect( statusBar ).toBeVisible( { timeout: 10000 } );

		// Status text should show connecting or collaborative mode
		const statusText = statusBar.locator( '.decker-collab-status-text' );
		await expect( statusText ).toBeVisible();
	} );

	test( 'Quill editor preserves server content', async ( { page } ) => {
		await page.goto( `/?decker_page=task&id=${ taskId }` );

		// Wait for collaboration to sync (status bar appears)
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Wait a bit for content to propagate through Yjs
		await page.waitForTimeout( 2000 );

		// Verify Quill editor has content
		const editorContent = await page.locator( '#editor .ql-editor' ).textContent();
		expect( editorContent.trim().length ).toBeGreaterThan( 0 );
	} );

	test( 'form fields populated after sync (single user)', async ( { page } ) => {
		// Update task with specific values
		await page.request.fetch(
			`${ page.url() || '' }`.replace( /\?.*/, '' ) || '/wp-json/wp/v2/tasks/' + taskId,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
			}
		).catch( () => {} );

		await page.goto( `/?decker_page=task&id=${ taskId }` );

		// Wait for sync
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );
		await page.waitForTimeout( 2000 );

		// Title should be populated
		const titleInput = page.locator( '#task-title' );
		await expect( titleInput ).toHaveValue( 'Collab Test Task' );

		// Stack should be populated
		const stackSelect = page.locator( '#task-stack' );
		await expect( stackSelect ).toHaveValue( 'to-do' );
	} );

	test( 'checkbox value round-trips through a save', async ( { page } ) => {
		await page.goto( `/?decker_page=task&id=${ taskId }` );

		// Wait for the collaboration module to initialize.
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Exercise the full form round-trip: check the box and save it.
		await page.fill( '#task-due-date', '2026-12-31' );
		await page.locator( '#task-max-priority' ).check();

		// A successful save redirects back to the task permalink; the reloaded,
		// server-rendered form reflects the stored value.
		await Promise.all( [
			page.waitForURL( /decker_page=task&id=\d+/ ),
			page.locator( '#save-task' ).click(),
		] );

		await expect( page.locator( '#task-max-priority' ) ).toBeChecked();
	} );

	test( 'empty field values preserved', async ( { page } ) => {
		// Task was created with empty due date
		await page.goto( `/?decker_page=task&id=${ taskId }` );

		// Wait for sync
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );
		await page.waitForTimeout( 2000 );

		// Due date should remain empty
		const dueDateInput = page.locator( '#task-due-date' );
		await expect( dueDateInput ).toHaveValue( '' );
	} );

	test( 'session cleanup on navigation', async ( { page } ) => {
		// Navigate to task page
		await page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Navigate away
		await page.goto( '/?decker_page=calendar' );
		await page.waitForTimeout( 1000 );

		// Navigate back to task
		await page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Check for console errors about duplicate sessions
		const consoleErrors = [];
		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' && msg.text().includes( 'duplicate' ) ) {
				consoleErrors.push( msg.text() );
			}
		} );

		await page.waitForTimeout( 2000 );
		expect( consoleErrors ).toHaveLength( 0 );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Clean up: delete task and board
		if ( taskId ) {
			await requestUtils.rest( {
				path: `/wp/v2/tasks/${ taskId }`,
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
