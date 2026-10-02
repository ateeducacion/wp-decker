/**
 * Mixed file and link attachments in the shared task card.
 *
 * @package Decker
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test( 'adds, reloads and removes links alongside uploaded files', async ( { page, requestUtils } ) => {
	await requestUtils.activatePlugin( 'decker' );
	const task = await requestUtils.rest( {
		path: '/wp/v2/tasks', method: 'POST',
		data: { title: 'Documentación del proyecto', status: 'publish', content: '<p>Archivos y enlaces de referencia para el equipo.</p>', meta: { stack: 'to-do' } },
	} );
	let mediaId;
	try {
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await page.locator( 'a[href="#attachments-tab"]' ).click();
		await page.locator( '#file-input' ).setInputFiles( {
			name: 'notas.txt', mimeType: 'text/plain', buffer: Buffer.from( 'Project notes' ),
		} );
		await page.locator( '#upload-file' ).click();
		const file = page.locator( '#attachments-list [data-attachment-id]' );
		await expect( file ).toHaveCount( 1 );
		mediaId = await file.getAttribute( 'data-attachment-id' );
		await page.locator( '#attachment-type-link' ).check();
		await expect( page.locator( '#attachment-file-controls' ) ).toBeHidden();
		await page.locator( '#attachment-url' ).fill( 'javascript:alert(1)' );
		await page.locator( '#add-attachment-link' ).click();
		await expect( page.locator( '#attachment-count' ) ).toHaveText( '1' );
		const url = 'https://developer.wordpress.org/plugins/?ref=decker&view=guide#intro';
		await page.locator( '#attachment-url' ).fill( url );
		await page.locator( '#add-attachment-link' ).click();
		const link = page.locator( '#attachments-list [data-attachment-url]' );
		await expect( link ).toHaveCount( 1 );
		await expect( link.locator( 'a' ) ).toHaveAttribute( 'href', url );
		await expect( link.locator( '.ri-link' ) ).toHaveCount( 1 );
		await expect( page.locator( '#attachment-count' ) ).toHaveText( '2' );
		await page.reload();
		await page.locator( 'a[href="#attachments-tab"]' ).click();
		await expect( link ).toHaveCount( 1 );
		await expect( file ).toHaveCount( 1 );
		await page.locator( '#attachment-type-link' ).check();
		await page.setViewportSize( { width: 1440, height: 1100 } );
		await page.locator( '#attachment-url' ).fill( 'https://example.org/documento' );
		await page.locator( '#task-form' ).screenshot( { path: 'artifacts/task-attachment-links.png' } );
		page.once( 'dialog', dialog => dialog.accept() );
		await link.locator( '.remove-attachment' ).click();
		await expect( link ).toHaveCount( 0 );
		await expect( page.locator( '#attachment-count' ) ).toHaveText( '1' );
		await page.reload();
		await page.locator( 'a[href="#attachments-tab"]' ).click();
		await expect( link ).toHaveCount( 0 );
		await expect( file ).toHaveCount( 1 );
	} finally {
		if ( mediaId ) await requestUtils.rest( { path: `/wp/v2/media/${ mediaId }?force=true`, method: 'DELETE' } );
		await requestUtils.rest( { path: `/wp/v2/tasks/${ task.id }?force=true`, method: 'DELETE' } );
	}
} );
