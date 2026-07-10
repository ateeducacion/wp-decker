/**
 * Decker front-end smoke test.
 *
 * Replaces the old WordPress-core boilerplate specs (hello / edit-posts) with a
 * check that the plugin's own front-end app boots: the Decker router resolves a
 * `decker_page` request, the left sidebar renders and the requested view loads.
 *
 * @package Decker
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Decker front-end', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );
	} );

	test( 'renders the Priority view with the Decker shell', async ( { page } ) => {
		await page.goto( '/?decker_page=priority' );

		// The Decker application shell (left sidebar) is present.
		await expect( page.locator( '.leftside-menu' ) ).toBeVisible();

		// The requested view rendered its own page title.
		await expect( page.locator( 'h4.page-title' ) ).toContainText( 'Priority' );

		// A Priority-specific section is rendered (not just the shell).
		await expect( page.getByText( 'MAX PRIORITY', { exact: false } ) ).toBeVisible();

		// The document title is branded, confirming the Decker template loaded
		// (and not the default WordPress front page).
		await expect( page ).toHaveTitle( /Priority \| Decker/ );
	} );

	test( 'navigates between Decker views from the sidebar', async ( { page } ) => {
		await page.goto( '/?decker_page=priority' );

		await page.getByRole( 'link', { name: 'Calendar' } ).first().click();

		await expect( page ).toHaveURL( /decker_page=calendar/ );
		await expect( page.locator( '#calendar' ) ).toBeVisible();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'decker' );
	} );
} );
