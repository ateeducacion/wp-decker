/**
 * E2E regression coverage for edit-session invalidation after an out-of-band mutation.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Whether a response is the Decker task save AJAX action.
 *
 * @param {import('@playwright/test').Response} response The Playwright response.
 * @return {boolean} Whether the response belongs to the task save request.
 */
function isSaveTaskResponse( response ) {
	const request = response.request();
	const postData = request.postData() || '';

	return response.url().includes( 'admin-ajax.php' ) &&
		request.method() === 'POST' &&
		postData.includes( 'action=save_decker_task' );
}

test.describe( 'Task out-of-band mutation invalidation', () => {
	let boardId;
	let taskId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		const board = await requestUtils.rest( {
			path: '/wp/v2/decker_board',
			method: 'POST',
			data: { name: 'E2E Mutation Board', slug: 'e2e-mutation-board' },
		} );
		boardId = board.id;
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		const task = await requestUtils.rest( {
			path: '/wp/v2/tasks',
			method: 'POST',
			data: {
				title: 'Out-of-band mutation task',
				content: '<p>Original content</p>',
				status: 'publish',
				decker_board: [ boardId ],
				meta: { stack: 'to-do', max_priority: '0', duedate: '2026-12-01' },
			},
		} );
		taskId = task.id;
	} );

	test( 'rejects a stale full-form save after the due date changes through REST', async ( { page } ) => {
		await page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( page.locator( '#task-title' ) ).toBeEnabled();

		// data-lock is a JSON blob; require a real acquired session generation,
		// not just a truthy attribute, so a silent no-session render cannot
		// turn the stale-save assertion into a false positive.
		const lockState = JSON.parse(
			await page.locator( '#task-form' ).getAttribute( 'data-lock' )
		);
		expect( lockState.generation ).toBeTruthy();

		const mutation = await page.evaluate( async ( id ) => {
			const root = window.wpApiSettings?.root || '/wp-json/';
			const nonce = window.wpApiSettings?.nonce || '';
			const response = await fetch( `${ root }decker/v1/tasks/${ id }/update_due_date`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( { duedate: '2026-12-15' } ),
			} );

			return {
				status: response.status,
				body: await response.json(),
			};
		}, taskId );

		expect( mutation.status ).toBe( 200 );
		// The endpoint answers 200 even when it updates nothing, so assert the
		// mutation actually wrote the new value before relying on it.
		expect( mutation.body.updated_meta ).toEqual( { duedate: '2026-12-15' } );

		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.fill( '#task-title', 'Stale title that must not win' );
		await page.fill( '#task-due-date', '2026-12-20' );

		const [ saveResponse ] = await Promise.all( [
			page.waitForResponse( isSaveTaskResponse ),
			page.locator( '#save-task' ).click(),
		] );

		expect( saveResponse.status() ).toBe( 409 );
		const saveBody = await saveResponse.json();
		expect( saveBody.success ).toBe( false );
		expect( saveBody.data.code ).toBe( 'decker_task_locked' );
		await expect( page.locator( '[data-decker-lock-lost]' ) ).toBeVisible();

		await page.reload();
		await expect( page.locator( '#task-title' ) ).toHaveValue( 'Out-of-band mutation task' );
		await expect( page.locator( '#task-due-date' ) ).toHaveValue( '2026-12-15' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
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
