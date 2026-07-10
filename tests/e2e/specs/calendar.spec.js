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

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'decker' );
	} );
} );
