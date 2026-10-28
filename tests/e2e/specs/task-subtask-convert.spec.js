/**
 * Promoting a subtask into a task of its own, from the card.
 *
 * @package Decker
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const SETTINGS_URL = '/wp-admin/options-general.php?page=decker_settings';
const SETTING = 'input[name="decker_settings[subtasks_enabled]"]';

/**
 * Turn the subtasks feature on or off through the settings screen.
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

test( 'promotes a subtask typed in the card into a task of its own', async ( {
	page,
	requestUtils,
} ) => {
	await requestUtils.activatePlugin( 'decker' );

	const board = await requestUtils.rest( {
		path: '/wp/v2/decker_board',
		method: 'POST',
		data: { name: 'E2E Convert', slug: `e2e-convert-${ Date.now() }` },
	} );

	const task = await requestUtils.rest( {
		path: '/wp/v2/tasks',
		method: 'POST',
		data: {
			title: `Tarea con subtareas ${ Date.now() }`,
			status: 'publish',
			decker_board: [ board.id ],
			meta: { stack: 'to-do', max_priority: true },
		},
	} );

	try {
		await setSubtasksEnabled( page, true );

		// Through the modal on the board, which is where the failure was seen.
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
		await expect( page.locator( '#task-form' ) ).toBeVisible();
		await page.locator( 'a[href="#subtasks-tab"]' ).click();

		const rows = page.locator( '.decker-subtask-row' );
		const newItem = page.locator( '.decker-subtask-new' );

		// Exactly the flow a user runs: type, Enter, type, Enter, then set an
		// assignee and a date on the first one, then promote it.
		await newItem.fill( 'hola' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 1 );

		await newItem.fill( 'hola' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 2 );

		// An assignee on the item is what the reported failure had.
		await rows.nth( 0 ).locator( '.decker-subtask-assignee' ).selectOption( { index: 1 } );
		await rows.nth( 0 ).locator( '.decker-subtask-duedate' ).fill( '2026-10-29' );
		await rows.nth( 1 ).locator( '.decker-subtask-duedate' ).fill( '2026-10-29' );
		await expect( page.locator( '.decker-subtasks-status' ) ).toHaveText(
			/Saved|Guardado/
		);

		await rows.nth( 0 ).locator( '.decker-subtask-convert' ).click();

		// The status line must report success, not "Subtask not found".
		await expect( page.locator( '.decker-subtasks-status' ) ).toHaveText(
			/task of its own|tarea independiente/i
		);
		await expect( page.locator( '.decker-subtasks-status' ) ).not.toHaveClass(
			/text-danger/
		);

		// The promoted item is frozen and now links to the task it became.
		await expect( rows ).toHaveCount( 2 );
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-convert' )
		).toHaveCount( 0 );
		await expect( rows.nth( 0 ).locator( 'a' ) ).toHaveCount( 1 );

		// And it survives reopening the card.
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#subtasks-tab"]' ).click();
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-convert' )
		).toHaveCount( 0 );
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
