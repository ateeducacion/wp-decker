/**
 * E2E Test for Decker Calendar
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Decker Calendar', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );
	} );

	test( 'should render the calendar with instruction message', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/', 'decker_page=calendar' );

		await expect(
			page.getByText( 'Drag and drop your event or click in the calendar', { exact: false } )
		).toBeVisible();
	} );


	test.afterAll

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'decker' );
	} );

} );
