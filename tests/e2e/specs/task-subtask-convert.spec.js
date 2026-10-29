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

test( 'promotes a subtask into a task of its own, and unlinks it without deleting it', async ( {
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

	let childId = null;

	try {
		await setSubtasksEnabled( page, true );

		// Through the modal on the board, which is where the failure was seen.
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
		await expect( page.locator( '#task-form' ) ).toBeVisible();
		await page.locator( 'a[href="#subtasks-tab"]' ).click();

		const rows = page.locator( '.decker-subtask-row' );
		const newItem = page.locator( '.decker-subtask-new' );

		// Exactly the flow a user runs: type, Enter, type, Enter, then promote
		// the first one.
		await newItem.fill( 'hola' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 1 );

		await newItem.fill( 'hola' );
		await newItem.press( 'Enter' );
		await expect( rows ).toHaveCount( 2 );

		// An assignee on the item is what the reported failure had, and a new
		// item now starts out assigned to whoever typed it.
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-assignee' )
		).not.toHaveValue( '0' );
		await expect( page.locator( '.decker-subtasks-status' ) ).toHaveText(
			/Saved|Guardado/
		);

		await rows.nth( 0 ).locator( '.decker-subtask-convert' ).click();
		// Promotion cannot be undone, so it asks first.
		await page.locator( '.swal2-confirm' ).click();

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
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-open' )
		).toHaveCount( 1 );
		// And the row now shows the task, not the frozen copy: the title is a
		// link to it and nothing on the row can be edited.
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-title-link' )
		).toHaveCount( 1 );
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-assignee' )
		).toHaveCount( 0 );

		// And it survives reopening the card.
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#subtasks-tab"]' ).click();
		await expect(
			rows.nth( 0 ).locator( '.decker-subtask-convert' )
		).toHaveCount( 0 );

		const childUrl = await rows
			.nth( 0 )
			.locator( '.decker-subtask-open' )
			.getAttribute( 'href' );
		childId = new URL( childUrl, page.url() ).searchParams.get( 'id' );

		// The task it became says which list it came out of, and links back.
		await page.goto( `/?decker_page=task&id=${ childId }` );
		const back = page.locator( '.decker-subtask-of a' );
		await expect( back ).toHaveText( task.title.rendered );
		await expect( back ).toHaveAttribute( 'href', new RegExp( `id=${ task.id }` ) );

		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#subtasks-tab"]' ).click();

		// Taking a promoted row off the list is an unlink, and archiving the
		// task it became is the opt-in on that same question.
		await rows.nth( 0 ).locator( '.decker-subtask-unlink' ).click();
		await page.locator( '.swal2-checkbox input' ).check();
		await page.locator( '.swal2-confirm' ).click();

		await expect( rows ).toHaveCount( 1 );
		await expect( page.locator( '.decker-subtasks-status' ) ).not.toHaveClass(
			/text-danger/
		);

		// The task survived the unlink, archived because the box was ticked.
		await expect
			.poll( async () => {
				const child = await requestUtils.rest( {
					path: `/wp/v2/tasks/${ childId }`,
				} );
				return child.status;
			} )
			.toBe( 'archived' );
	} finally {
		await setSubtasksEnabled( page, false );
		await requestUtils.rest( {
			path: `/wp/v2/tasks/${ task.id }?force=true`,
			method: 'DELETE',
		} );
		if ( childId ) {
			await requestUtils.rest( {
				path: `/wp/v2/tasks/${ childId }?force=true`,
				method: 'DELETE',
			} );
		}
		await requestUtils.rest( {
			path: `/wp/v2/decker_board/${ board.id }?force=true`,
			method: 'DELETE',
		} );
	}
} );
