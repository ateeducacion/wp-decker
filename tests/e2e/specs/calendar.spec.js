/**
 * E2E Test for Decker Calendar
 *
 * Decker pages are rendered on the front end via `template_redirect`
 * (`home_url( '?decker_page=...' )`), so they must be reached with a plain
 * front-end navigation, not `admin.visitAdminPage()` (which targets wp-admin,
 * where the Decker router never runs).
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Decker Calendar', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );
	} );

	test( 'should render the calendar with instruction message', async ( { page } ) => {
		await page.goto( '/?decker_page=calendar' );

		// The FullCalendar container is present on the page.
		await expect( page.locator( '#calendar' ) ).toBeVisible();

		// The helper instruction is shown next to the calendar.
		await expect(
			page.getByText( 'Drag and drop your event or click in the calendar', { exact: false } )
		).toBeVisible();
	} );

	test( 'all-day modal converts inclusive display back to an exclusive stored end', async ( { page, requestUtils } ) => {
		const today = new Date();
		const start = `${ today.getFullYear() }-${ String( today.getMonth() + 1 ).padStart( 2, '0' ) }-10`;
		const end = start.slice( 0, 8 ) + '11';
		const event = await requestUtils.rest( {
			path: '/wp/v2/decker_event', method: 'POST',
			data: { title: 'Exclusive end browser test', status: 'publish', meta: {
				event_allday: true, event_start: start, event_end: end, event_category: 'bg-success',
			} },
		} );
		try {
			await page.goto( '/?decker_page=calendar' );
			await page.getByText( 'Exclusive end browser test', { exact: true } ).first().click();
			const modal = page.locator( '#event-modal' );
			await expect( modal.locator( '#event-start' ) ).toHaveValue( start );
			await expect( modal.locator( '#event-end' ) ).toHaveValue( start );
			const response = page.waitForResponse( reply => reply.url().includes( `decker_event/${ event.id }` ) && reply.request().method() === 'POST' );
			await modal.locator( '#form-event' ).evaluate( form => form.requestSubmit() );
			expect( ( await response ).ok() ).toBeTruthy();
			const saved = await requestUtils.rest( { path: `/wp/v2/decker_event/${ event.id }` } );
			expect( saved.meta.event_end ).toBe( end );
		} finally {
			await requestUtils.rest( { path: `/wp/v2/decker_event/${ event.id }`, method: 'DELETE', data: { force: true } } );
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'decker' );
	} );
} );
