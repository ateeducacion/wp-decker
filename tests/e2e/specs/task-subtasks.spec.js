/**
 * Subtasks on the shared task card: the tab, the states and the board counter.
 *
 * @package Decker
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const SETTINGS_URL = '/wp-admin/options-general.php?page=decker_settings';
const SETTING = 'input[name="decker_settings[subtasks_enabled]"]';

/**
 * Turn the subtasks feature on or off through the settings screen.
 *
 * The flag lives in the `decker_settings` option, which is not exposed over
 * REST, so the switch goes through the admin UI it belongs to.
 *
 * @param {Object}  page    The Playwright page.
 * @param {boolean} enabled Whether the feature should end up enabled.
 */
async function setSubtasksEnabled( page, enabled ) {
	await page.goto( SETTINGS_URL );
	await page.locator( SETTING ).setChecked( enabled );
	await page.locator( '#submit' ).click();
	await expect( page.locator( SETTING ) ).toBeChecked( { checked: enabled } );
}

test( 'breaks a task down, tracks the three states and shows progress on the board', async ( {
	page,
	requestUtils,
} ) => {
	await requestUtils.activatePlugin( 'decker' );

	const board = await requestUtils.rest( {
		path: '/wp/v2/decker_board',
		method: 'POST',
		data: { name: 'E2E Subtasks', slug: `e2e-subtasks-${ Date.now() }` },
	} );

	// The task list paginates, so the title has to be unique enough to be
	// found through the table's own search box.
	const title = `Sustituir las apariciones ${ Date.now() }`;
	const task = await requestUtils.rest( {
		path: '/wp/v2/tasks',
		method: 'POST',
		data: {
			title,
			status: 'publish',
			decker_board: [ board.id ],
			// The task list orders by `max_priority`, which drops tasks that
			// carry no such meta, so the fixture has to set it explicitly.
			meta: { stack: 'to-do', max_priority: true },
		},
	} );

	try {
		await setSubtasksEnabled( page, true );

		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#subtasks-tab"]' ).click();

		const rows = page.locator( '.decker-subtask-row' );
		await expect( rows ).toHaveCount( 0 );
		await expect( page.locator( '.decker-subtasks-empty' ) ).toBeVisible();

		// Adding by keyboard is the fast path the feature was asked for.
		const newItem = page.locator( '.decker-subtask-new' );
		await newItem.fill( 'Localizar en la web de ATE' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 1 );

		await newItem.fill( 'Sustituir en Educarobot' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 2 );

		await expect( page.locator( '#subtask-count' ) ).toHaveText( '0/2' );

		// One click moves an item to in-progress, a second one to done.
		const firstStatus = rows.nth( 0 ).locator( '.decker-subtask-status' );
		await firstStatus.click();
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-status i.ri-progress-3-line' )
		).toHaveCount( 1 );

		await firstStatus.click();
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-status i.ri-checkbox-circle-line' )
		).toHaveCount( 1 );
		await expect( page.locator( '#subtask-count' ) ).toHaveText( '1/2' );

		// A new item starts out assigned to whoever typed it, and the owner is
		// changed through the Choices.js picker, which is what hides the plain
		// select a test could otherwise drive directly.
		await expect(
			rows.nth( 1 ).locator( '.decker-subtask-assignee' )
		).not.toHaveValue( '0' );

		const picker = rows.nth( 0 ).locator( '.decker-subtask-assignee-field' );
		await picker.click();
		await picker
			.locator( '.choices__list--dropdown .choices__item' )
			.filter( { hasText: /Unassigned|Sin asignar/ } )
			.first()
			.click();
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-assignee' )
		).toHaveValue( '0' );

		// Give the second item a date.
		await rows.nth( 1 ).locator( '.decker-subtask-duedate' ).fill( '2026-11-20' );
		await expect( page.locator( '.decker-subtasks-status' ) ).toHaveText(
			/Saved|Guardado/
		);

		// Everything above was written as it happened, with no card save.
		await page.reload();
		await page.locator( 'a[href="#subtasks-tab"]' ).click();
		await expect( rows ).toHaveCount( 2 );
		await expect( page.locator( '#subtask-count' ) ).toHaveText( '1/2' );
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-status i.ri-checkbox-circle-line' )
		).toHaveCount( 1 );
		await expect(
			rows.nth( 1 ).locator( '.decker-subtask-duedate' )
		).toHaveValue( '2026-11-20' );
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-assignee' )
		).toHaveValue( '0' );

		await page.setViewportSize( { width: 1440, height: 1100 } );
		await page
			.locator( '#task-form' )
			.screenshot( { path: 'artifacts/task-subtasks.png' } );

		// The task list carries the aggregate in the three stack colors.
		await page.goto( '/?decker_page=tasks' );
		await page.locator( '#tablaTareas_filter input' ).fill( title );

		const row = page.locator( `tr.task:has(a[data-task-id="${ task.id }"])` );
		await expect( row ).toHaveCount( 1 );

		const counter = row.locator( '.decker-subtasks-bar' );
		await expect( counter ).toHaveCount( 1 );
		await expect( counter.locator( '.progress-bar.bg-success' ) ).toHaveCount( 1 );
		await expect( counter.locator( '.progress-bar.bg-secondary' ) ).toHaveCount(
			1
		);
		await expect( row.locator( '.decker-labels-popover b' ) ).toHaveText( '1/2' );

		// Chips are the board's job, not the table's.
		await expect( row.locator( '.decker-subtask-chip' ) ).toHaveCount( 0 );

		// On the board card, only the open subtask gets a chip: the finished one
		// is already counted by the 1/2 and the bar.
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		const card = page.locator( `.task[data-task-id="${ task.id }"]` );
		const chips = card.locator( '.decker-subtask-chip' );
		await expect( chips ).toHaveCount( 1 );
		await expect( chips.first() ).toHaveText( 'Sustituir en Educarobot' );
		await expect(
			chips.first().locator( '.decker-subtask-dot-to-do' )
		).toHaveCount( 1 );

		await card.screenshot( { path: 'artifacts/task-subtask-chips.png' } );

		// Clicking a chip opens the card straight on the Subtasks tab.
		await chips.first().click();
		await expect( page.locator( '#subtasks-tab' ) ).toHaveClass( /\bactive\b/ );

		// Deleting the last open item leaves the task fully done.
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#subtasks-tab"]' ).click();
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await rows.nth( 1 ).locator( '.decker-subtask-delete' ).click();
		await expect( rows ).toHaveCount( 1 );
		await expect( page.locator( '#subtask-count' ) ).toHaveText( '1/1' );

		// With the feature off, the tab and the counter are gone.
		await setSubtasksEnabled( page, false );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await expect( page.locator( 'a[href="#subtasks-tab"]' ) ).toHaveCount( 0 );
	} finally {
		await setSubtasksEnabled( page, false );
		await requestUtils.rest( {
			path: `/wp/v2/tasks/${ task.id }?force=true`,
			method: 'DELETE',
		} );
		await requestUtils.rest( {
			path: `/wp/v2/decker_board/${ board.id }?force=true`,
			method: 'DELETE',
		} );
	}
} );
