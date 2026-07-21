/**
 * E2E tests for the "For today" quick action.
 *
 * Verifies that a pristine existing task exposes a lightweight quick action
 * that avoids the full task save, that editing a shared field switches to the
 * normal checkbox + full-save flow, that the action works while another user
 * owns the edit lock, and that a failed request leaves state consistent.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const USER_A = { username: 'todayusera', password: 'today-pass-A-123', name: 'Today User A' };
const USER_B = { username: 'todayuserb', password: 'today-pass-B-123', name: 'Today User B' };

/**
 * Log a specific user in through the WordPress login form in a fresh context.
 *
 * @param {import('@playwright/test').Browser} browser The Playwright browser.
 * @param {string}                             baseURL The site base URL.
 * @param {Object}                             user    The user credentials.
 * @return {Promise<{context: any, page: any}>} The authenticated context/page.
 */
async function loginAs( browser, baseURL, user ) {
	// Force a clean context (no inherited admin storage state) so the session
	// belongs solely to this user.
	const context = await browser.newContext( { baseURL, storageState: { cookies: [], origins: [] } } );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user.username );
	await page.fill( '#user_pass', user.password );
	// Wait for the post-login redirect so the auth cookie is fully committed
	// before navigating anywhere else; otherwise, under CI timing, a follow-up
	// goto can render as a different (or anonymous) user and the server-side
	// lock is then acquired for the wrong account.
	await Promise.all( [
		page.waitForURL( '**/wp-admin/**' ),
		page.click( '#wp-submit' ),
	] );
	return { context, page };
}

test.describe( 'Task "For today" quick action', () => {
	let boardId;
	let taskId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		await requestUtils.rest( {
			path: '/wp/v2/users',
			method: 'POST',
			data: { ...USER_A, email: 'todayusera@example.com', roles: [ 'editor' ] },
		} ).catch( () => {} );
		await requestUtils.rest( {
			path: '/wp/v2/users',
			method: 'POST',
			data: { ...USER_B, email: 'todayuserb@example.com', roles: [ 'editor' ] },
		} ).catch( () => {} );

		const board = await requestUtils.rest( {
			path: '/wp/v2/decker_board',
			method: 'POST',
			data: { name: 'E2E Today Board', slug: 'e2e-today-board' },
		} );
		boardId = board.id;
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		const task = await requestUtils.rest( {
			path: '/wp/v2/tasks',
			method: 'POST',
			data: {
				title: 'Today Quick Action Task',
				content: '<p>Distinctive description</p>',
				status: 'publish',
				decker_board: [ boardId ],
				meta: { stack: 'to-do', max_priority: '0', duedate: '' },
			},
		} );
		taskId = task.id;
	} );

	test( 'marks the task for today without a full save', async ( { browser, baseURL } ) => {
		const a = await loginAs( browser, baseURL, USER_A );

		const saveRequests = [];
		a.page.on( 'request', ( request ) => {
			if ( request.method() === 'POST' && request.postData() && request.postData().includes( 'save_decker_task' ) ) {
				saveRequests.push( request.url() );
			}
		} );

		await a.page.goto( `/?decker_page=task&id=${ taskId }` );

		const quick = a.page.locator( '#task-today-quick' );
		await expect( quick ).toBeVisible();
		await expect( quick ).toContainText( 'Add to today' );
		await expect( a.page.locator( '#task-today' ) ).toBeHidden();
		await expect( a.page.locator( '#save-task' ) ).toBeDisabled();

		const [ todayResponse ] = await Promise.all( [
			a.page.waitForResponse( ( r ) => r.url().includes( `/decker/v1/tasks/${ taskId }/today` ) && r.request().method() === 'PUT' ),
			quick.click(),
		] );
		expect( todayResponse.status() ).toBe( 200 );
		expect( saveRequests ).toHaveLength( 0 );

		// The button toggles in place, without navigating away or a full save.
		await expect( quick ).toContainText( 'Remove from today' );
		await expect( a.page.locator( '#task-title' ) ).toHaveValue( 'Today Quick Action Task' );

		// The change persisted on the server.
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( a.page.locator( '#task-today-quick' ) ).toContainText( 'Remove from today' );

		await a.context.close();
	} );

	test( 'unmarks the task for today without a full save', async ( { browser, baseURL } ) => {
		const a = await loginAs( browser, baseURL, USER_A );
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		const quick = a.page.locator( '#task-today-quick' );

		// Mark first (in place).
		await Promise.all( [
			a.page.waitForResponse( ( r ) => r.url().includes( `/tasks/${ taskId }/today` ) ),
			quick.click(),
		] );
		await expect( quick ).toContainText( 'Remove from today' );

		const saveRequests = [];
		a.page.on( 'request', ( request ) => {
			if ( request.postData() && request.postData().includes( 'save_decker_task' ) ) {
				saveRequests.push( request.url() );
			}
		} );

		// Unmark (in place).
		await Promise.all( [
			a.page.waitForResponse( ( r ) => r.url().includes( `/tasks/${ taskId }/today` ) && r.request().method() === 'PUT' ),
			quick.click(),
		] );
		expect( saveRequests ).toHaveLength( 0 );
		await expect( quick ).toContainText( 'Add to today' );

		// The removal persisted on the server.
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( a.page.locator( '#task-today-quick' ) ).toContainText( 'Add to today' );

		await a.context.close();
	} );

	test( 'editing a shared field switches to the checkbox and full save', async ( { browser, baseURL } ) => {
		const a = await loginAs( browser, baseURL, USER_A );
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );

		await a.page.fill( '#task-title', 'Edited title triggers dirty mode' );

		await expect( a.page.locator( '#task-today-quick' ) ).toBeHidden();
		await expect( a.page.locator( '#task-today' ) ).toBeVisible();
		await expect( a.page.locator( '#save-task' ) ).toBeEnabled();

		await a.context.close();
	} );

	test( 'works while another user owns the edit lock, without takeover', async ( { browser, baseURL } ) => {
		// User A opens the task and acquires the edit lock.
		const a = await loginAs( browser, baseURL, USER_A );
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( a.page.locator( '#task-title' ) ).toBeEnabled();

		// User B opens the same task: shared fields are read-only, but the quick
		// action is still available.
		const b = await loginAs( browser, baseURL, USER_B );
		await b.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( b.page.locator( '[data-decker-lock-banner]' ) ).toBeVisible();
		await expect( b.page.locator( '#task-title' ) ).toBeDisabled();

		const quick = b.page.locator( '#task-today-quick' );
		await expect( quick ).toBeVisible();
		await expect( quick ).toBeEnabled();

		const takeoverRequests = [];
		b.page.on( 'request', ( request ) => {
			if ( request.url().includes( '/lock/takeover' ) ) {
				takeoverRequests.push( request.url() );
			}
		} );

		const [ response ] = await Promise.all( [
			b.page.waitForResponse( ( r ) => r.url().includes( `/tasks/${ taskId }/today` ) && r.request().method() === 'PUT' ),
			quick.click(),
		] );
		expect( response.status() ).toBe( 200 );
		expect( takeoverRequests ).toHaveLength( 0 );

		// User A still owns the lock (their card is still editable, no lost banner).
		await a.page.waitForTimeout( 500 );
		await expect( a.page.locator( '[data-decker-lock-lost]' ) ).toHaveCount( 0 );

		await a.context.close();
		await b.context.close();
	} );

	test( 'a failed request keeps the modal open and does not full save', async ( { browser, baseURL } ) => {
		const a = await loginAs( browser, baseURL, USER_A );

		// Force the today endpoint to fail.
		await a.page.route( `**/decker/v1/tasks/${ taskId }/today`, ( route ) => {
			route.fulfill( { status: 500, contentType: 'application/json', body: JSON.stringify( { success: false, message: 'Server error' } ) } );
		} );

		const saveRequests = [];
		a.page.on( 'request', ( request ) => {
			if ( request.postData() && request.postData().includes( 'save_decker_task' ) ) {
				saveRequests.push( request.url() );
			}
		} );

		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		const quick = a.page.locator( '#task-today-quick' );
		await quick.click();
		await a.page.waitForTimeout( 500 );

		// The page did not navigate away and no full save occurred.
		await expect( quick ).toBeVisible();
		await expect( quick ).toBeEnabled();
		expect( saveRequests ).toHaveLength( 0 );

		await a.context.close();
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
