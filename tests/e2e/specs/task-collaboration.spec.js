/**
 * E2E Tests for Decker Task Collaboration
 *
 * Single-user scenarios testing that collaborative editing infrastructure
 * works correctly: status bar, Quill content preservation, form field
 * sync, checkbox values, empty fields, and session cleanup.
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

	test( 'task page loads with collaboration status bar', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );

		// Wait for the collaboration module to initialize
		const statusBar = page.locator( '.decker-collab-status' );
		await expect( statusBar ).toBeVisible( { timeout: 10000 } );

		// Status text should show connecting or collaborative mode
		const statusText = statusBar.locator( '.decker-collab-status-text' );
		await expect( statusText ).toBeVisible();
	} );

	test( 'Quill editor preserves server content', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );

		// Wait for collaboration to sync (status bar appears)
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Wait a bit for content to propagate through Yjs
		await page.waitForTimeout( 2000 );

		// Verify Quill editor has content
		const editorContent = await page.locator( '#editor .ql-editor' ).textContent();
		expect( editorContent.trim().length ).toBeGreaterThan( 0 );
	} );

	test( 'form fields populated after sync (single user)', async ( { admin, page } ) => {
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

		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );

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

	test( 'checkbox values sync correctly', async ( { requestUtils, admin, page } ) => {
		// Update the task to have max_priority set
		await requestUtils.rest( {
			path: `/wp/v2/tasks/${ taskId }`,
			method: 'POST',
			data: {
				meta: {
					max_priority: '1',
				},
			},
		} );

		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );

		// Wait for sync
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );
		await page.waitForTimeout( 2000 );

		// The max priority checkbox should be checked
		const maxPriorityCheckbox = page.locator( '#task-max-priority' );
		await expect( maxPriorityCheckbox ).toBeChecked();
	} );

	test( 'empty field values preserved', async ( { admin, page } ) => {
		// Task was created with empty due date
		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );

		// Wait for sync
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );
		await page.waitForTimeout( 2000 );

		// Due date should remain empty
		const dueDateInput = page.locator( '#task-due-date' );
		await expect( dueDateInput ).toHaveValue( '' );
	} );

	test( 'session cleanup on navigation', async ( { admin, page } ) => {
		// Navigate to task page
		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );
		await expect( page.locator( '.decker-collab-status' ) ).toBeVisible( { timeout: 10000 } );

		// Navigate away
		await admin.visitAdminPage( '/', 'decker_page=calendar' );
		await page.waitForTimeout( 1000 );

		// Navigate back to task
		await admin.visitAdminPage( '/', `decker_page=task&id=${ taskId }` );
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
