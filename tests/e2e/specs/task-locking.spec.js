/**
 * E2E tests for Decker task edit locking (WordPress post lock compatible).
 *
 * Two separate browser contexts play the role of two users editing the same
 * card. It verifies that the second user is blocked, can explicitly take over,
 * and that the first user can no longer save stale changes afterwards.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const USER_A = { username: 'lockusera', password: 'lock-pass-A-123', name: 'Lock User A' };
const USER_B = { username: 'lockuserb', password: 'lock-pass-B-123', name: 'Lock User B' };

/**
 * Whether a response is the Decker save_decker_task AJAX action.
 *
 * @param {import('@playwright/test').Response} response The Playwright response.
 * @return {boolean} True when the request is a task save.
 */
function isSaveTaskResponse( response ) {
	const request = response.request();
	const postData = request.postData() || '';

	return response.url().includes( 'admin-ajax.php' ) &&
		request.method() === 'POST' &&
		postData.includes( 'action=save_decker_task' );
}

/**
 * Read a single application/x-www-form-urlencoded field from a save response.
 *
 * @param {import('@playwright/test').Response} response The save response.
 * @param {string}                              key      The field name.
 * @return {string|null} The field value, or null when absent.
 */
function getSavePostParam( response, key ) {
	const postData = response.request().postData() || '';
	return new URLSearchParams( postData ).get( key );
}

/**
 * Log a specific user in through the WordPress login form in a fresh context.
 *
 * @param {import('@playwright/test').Browser} browser  The Playwright browser.
 * @param {string}                             baseURL  The site base URL.
 * @param {Object}                             user     The user credentials.
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

test.describe( 'Task edit locking', () => {
	let boardId;
	let taskId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );

		// Two editor users play the two concurrent editors.
		await requestUtils.rest( {
			path: '/wp/v2/users',
			method: 'POST',
			data: { ...USER_A, email: 'lockusera@example.com', roles: [ 'editor' ] },
		} ).catch( () => {} );
		await requestUtils.rest( {
			path: '/wp/v2/users',
			method: 'POST',
			data: { ...USER_B, email: 'lockuserb@example.com', roles: [ 'editor' ] },
		} ).catch( () => {} );

		const boardRes = await requestUtils.rest( {
			path: '/wp/v2/decker_board',
			method: 'POST',
			data: { name: 'E2E Lock Board', slug: 'e2e-lock-board' },
		} );
		boardId = boardRes.id;
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		const taskRes = await requestUtils.rest( {
			path: '/wp/v2/tasks',
			method: 'POST',
			data: {
				title: 'Lock Test Task',
				content: '<p>Lock test content</p>',
				status: 'publish',
				decker_board: [ boardId ],
				meta: { stack: 'to-do', max_priority: '0', duedate: '' },
			},
		} );
		taskId = taskRes.id;
	} );

	test( 'user B is blocked, takes over, and user A can no longer save', async ( {
		browser,
		baseURL,
	} ) => {
		// User A opens the card and acquires the lock.
		const a = await loginAs( browser, baseURL, USER_A );
		await a.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( a.page.locator( '#task-title' ) ).toBeEnabled();
		await expect( a.page.locator( '[data-decker-lock-banner]' ) ).toHaveCount( 0 );

		// User B opens the same card and sees it locked by user A.
		const b = await loginAs( browser, baseURL, USER_B );
		await b.page.goto( `/?decker_page=task&id=${ taskId }` );

		const banner = b.page.locator( '[data-decker-lock-banner]' );
		await expect( banner ).toBeVisible();
		await expect( banner ).toContainText( USER_A.name );

		// User B cannot edit or save.
		await expect( b.page.locator( '#task-title' ) ).toBeDisabled();
		await expect( b.page.locator( '#save-task' ) ).toBeDisabled();

		// User B explicitly takes over.
		await b.page.locator( '.decker-take-over-lock' ).click();
		await b.page.waitForLoadState( 'networkidle' );

		// After takeover, user B can edit and save.
		await expect( b.page.locator( '#task-title' ) ).toBeEnabled();
		await expect( b.page.locator( '[data-decker-lock-banner]' ) ).toHaveCount( 0 );
		await b.page.fill( '#task-title', 'Updated by user B' );
		// The due date is a required field; a full save is blocked client-side
		// (form validation) until it is set.
		await b.page.fill( '#task-due-date', '2026-12-31' );
		const [ bSaveResponse ] = await Promise.all( [
			b.page.waitForResponse( isSaveTaskResponse ),
			b.page.locator( '#save-task' ).click(),
		] );
		expect( bSaveResponse.status() ).toBe( 200 );
		// The browser must send a non-empty lock generation from data-lock.
		const bGeneration = getSavePostParam( bSaveResponse, 'lock_generation' );
		expect( bGeneration ).toBeTruthy();
		expect( bGeneration ).not.toBe( '0' );
		// Saving an existing full-page card must keep the session instead of
		// redirecting, and return to pristine mode (Save disabled until dirty).
		await expect( b.page.locator( '#task-title' ) ).toHaveValue( 'Updated by user B' );
		await expect( b.page.locator( '#save-task' ) ).toBeDisabled( { timeout: 10000 } );

		// Explicitly release the active lock via REST (same outcome as modal
		// close / pagehide) and wait for the response so the 409 below cannot
		// be explained by an still-held _edit_lock — only by the generation
		// mismatch from A's stale form.
		const releaseResult = await b.page.evaluate( async ( id ) => {
			const root = window.wpApiSettings && window.wpApiSettings.root
				? window.wpApiSettings.root
				: '/wp-json/';
			const nonce = window.wpApiSettings && window.wpApiSettings.nonce
				? window.wpApiSettings.nonce
				: '';
			const response = await fetch( `${ root }decker/v1/tasks/${ id }/lock`, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin',
			} );
			const data = await response.json();
			return { ok: response.ok, status: response.status, data };
		}, taskId );
		expect( releaseResult.ok ).toBe( true );
		expect( releaseResult.data.released ).toBe( true );

		// User A tries to save a stale change and is rejected with HTTP 409.
		a.page.on( 'dialog', ( dialog ) => dialog.accept() );
		await a.page.fill( '#task-title', 'Updated by user A' );
		await a.page.fill( '#task-due-date', '2026-12-30' );
		const [ aSaveResponse ] = await Promise.all( [
			a.page.waitForResponse( isSaveTaskResponse ),
			a.page.locator( '#save-task' ).click(),
		] );
		const aGeneration = getSavePostParam( aSaveResponse, 'lock_generation' );
		expect( aGeneration ).toBeTruthy();
		expect( aGeneration ).not.toBe( '0' );
		// After takeover, A's form still carries the pre-takeover generation.
		expect( aGeneration ).not.toBe( bGeneration );
		expect( aSaveResponse.status() ).toBe( 409 );
		const aSaveBody = await aSaveResponse.json();
		expect( aSaveBody.success ).toBe( false );
		expect( aSaveBody.data.code ).toBe( 'decker_task_locked' );
		await expect(
			a.page.locator( '[data-decker-lock-lost]' )
		).toBeVisible( { timeout: 10000 } );

		// The final stored title is user B's value.
		const final = await loginAs( browser, baseURL, USER_B );
		await final.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( final.page.locator( '#task-title' ) ).toHaveValue( 'Updated by user B' );

		await a.context.close();
		await b.context.close();
		await final.context.close();
	} );

	test( 'a fresh user can edit a card with no active lock', async ( {
		browser,
		baseURL,
	} ) => {
		const b = await loginAs( browser, baseURL, USER_B );
		await b.page.goto( `/?decker_page=task&id=${ taskId }` );

		await expect( b.page.locator( '#task-title' ) ).toBeEnabled();
		await expect( b.page.locator( '[data-decker-lock-banner]' ) ).toHaveCount( 0 );
		await b.page.fill( '#task-title', 'Edited without a prior lock' );
		// The due date is a required field; fill it so the full save is allowed.
		await b.page.fill( '#task-due-date', '2026-12-31' );
		const [ saveResponse ] = await Promise.all( [
			b.page.waitForResponse( isSaveTaskResponse ),
			b.page.locator( '#save-task' ).click(),
		] );
		expect( saveResponse.status() ).toBe( 200 );
		expect( getSavePostParam( saveResponse, 'lock_generation' ) ).toBeTruthy();
		await expect( b.page.locator( '#task-title' ) ).toHaveValue( 'Edited without a prior lock' );
		await expect( b.page.locator( '#save-task' ) ).toBeDisabled( { timeout: 10000 } );

		await b.page.goto( `/?decker_page=task&id=${ taskId }` );
		await expect( b.page.locator( '#task-title' ) ).toHaveValue( 'Edited without a prior lock' );

		await b.context.close();
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
