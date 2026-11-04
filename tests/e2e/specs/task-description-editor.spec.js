/** Real WordPress/TinyMCE round trips of anonymous historical descriptions. */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

function cli( args ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', 'env', 'DECKER_DESCRIPTION_E2E=1', 'wp', ...args ], { encoding: 'utf8', timeout: 60_000 } );
}

function seed( name ) {
	const output = cli( [ 'eval-file', 'wp-content/plugins/decker/tests/e2e/description-fixture.php', name, '--user=admin' ] );
	return JSON.parse( output.match( /DECKER_RESULT:(\{[^\n]+\})/ )[ 1 ] );
}

async function ready( page ) {
	await page.waitForFunction( () => window.tinymce?.get( 'task-description' )?.initialized );
	if ( process.env.DECKER_E2E_WORDPRESS_VERSION ) {
		const href = await page.locator( '#dashicons-css' ).getAttribute( 'href' );
		expect( new URL( href, page.url() ).searchParams.get( 'ver' ) ).toBe( process.env.DECKER_E2E_WORDPRESS_VERSION );
	}
}

async function saved( page ) {
	expect( await page.locator( '#task-form' ).evaluate( ( form ) => [ ...form.querySelectorAll( ':invalid' ) ].map( ( node ) => node.id ) ) ).toEqual( [] );
	if ( await page.locator( '#task-modal.show' ).count() ) {
		// Proxy the real request so immediate board navigation cannot discard its body.
		let resolve;
		const result = new Promise( ( done ) => { resolve = done; } );
		const capture = async ( route ) => {
			if ( !( route.request().postData() || '' ).includes( 'action=save_decker_task' ) ) return route.continue();
			const response = await route.fetch();
			expect( response.status() ).toBe( 200 );
			const body = await response.json();
			await route.fulfill( { response } );
			resolve( body );
		};
		await page.route( '**/admin-ajax.php*', capture );
		await page.locator( '#save-task' ).click();
		const body = await result;
		expect( body.success ).toBe( true );
		expect( body.data.success ).toBe( true );
		await page.unroute( '**/admin-ajax.php*', capture );
		return;
	}
	// Read immediately: a successful modal save closes and can reload the board.
	const response = page.waitForResponse( ( result ) => result.url().includes( 'admin-ajax.php' ) && result.request().method() === 'POST' && ( result.request().postData() || '' ).includes( 'action=save_decker_task' ) ).then( async ( actual ) => {
		expect( actual.status() ).toBe( 200 );
		return actual.json();
	} );
	await page.locator( '#save-task' ).click();
	const body = await response;
	expect( body.success ).toBe( true );
	expect( body.data.success ).toBe( true );
}

async function stored( requestUtils, id ) {
	const task = await requestUtils.rest( { path: `/wp/v2/tasks/${ id }?context=edit` } );
	return task.content.raw;
}

async function signature( page, html ) {
	return page.evaluate( ( content ) => {
		const root = new DOMParser().parseFromString( content, 'text/html' );
		const items = [ ...root.querySelectorAll( 'li' ) ];
		return items.map( ( li ) => {
			const clone = li.cloneNode( true );
			clone.querySelectorAll( 'ul,ol,span.ql-ui' ).forEach( ( node ) => node.remove() );
			return {
				parent: items.indexOf( li.parentElement.closest( 'li' ) ),
				state: li.getAttribute( 'data-list' ),
				type: li.parentElement.tagName,
				// Collapsible HTML whitespace may normalize; pre/code is checked exactly.
				text: clone.textContent.replace( /\s+/gu, ' ' ).trim(),
				links: [ ...clone.querySelectorAll( 'a' ) ].map( ( a ) => [ a.getAttribute( 'href' ), a.textContent ] ),
				images: [ ...clone.querySelectorAll( 'img' ) ].map( ( img ) => [ img.getAttribute( 'src' ), img.getAttribute( 'alt' ) ] ),
				bold: [ ...clone.querySelectorAll( 'strong,b' ) ].map( ( node ) => node.textContent ),
				italic: [ ...clone.querySelectorAll( 'em,i' ) ].map( ( node ) => node.textContent ),
			};
		} );
	}, html );
}

async function documentMeaning( page, html ) {
	return page.evaluate( ( value ) => {
		const root = new DOMParser().parseFromString( value, 'text/html' );
		const text = ( node ) => {
			if ( node.nodeType === Node.TEXT_NODE ) return node.nodeValue;
			const content = [ ...node.childNodes ].map( text ).join( '' );
			return /^(P|DIV|LI|UL|OL|BR|PRE|H[1-6]|HR|TD|TH|TR|BLOCKQUOTE)$/.test( node.nodeName ) ? ' ' + content + ' ' : content;
		};
		const normalized = ( node ) => node.textContent.replace( /\s+/gu, ' ' ).trim();
		return {
			text: text( root.body ).replace( /\s+/gu, ' ' ).trim(),
			headings: [ ...root.querySelectorAll( 'h1,h2,h3,h4,h5,h6' ) ].map( ( n ) => [ n.tagName, normalized( n ) ] ),
			rules: root.querySelectorAll( 'hr' ).length,
			links: [ ...root.querySelectorAll( 'a' ) ].map( ( n ) => [ n.getAttribute( 'href' ), normalized( n ) ] ),
			images: [ ...root.querySelectorAll( 'img' ) ].map( ( n ) => [ n.getAttribute( 'src' ), n.getAttribute( 'alt' ) ] ),
			presentation: [ ...root.querySelectorAll( '[style],[dir]' ) ].map( ( n ) => {
				const styles = [ ...n.style ].sort().map( ( property ) => [ property, n.style.getPropertyValue( property ), n.style.getPropertyPriority( property ) ] );
				return [ n.tagName, normalized( n ), n.getAttribute( 'dir' ), styles ];
			} ).filter( ( entry ) => entry[ 2 ] || entry[ 3 ].length ),
		};
	}, html );
}

test.describe( 'Classic task descriptions', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'decker' );
	} );

	for ( const fixture of [ 'task-5531.html', 'task-6976.html', 'task-6936.html', 'task-3905.html', 'task-4480.html', 'task-6745.html', 'task-8201.html', 'task-6639.html', 'empty-checklist.html', 'empty-list.html', 'empty-paragraphs.html' ] ) {
		test( `migrate, edit title only, save and reload ${ fixture }`, async ( { page, requestUtils } ) => {
			const task = seed( fixture );
			if ( fixture === 'task-6936.html' ) expect( task.characters ).toBe( 189084 );
			cli( [ 'decker', 'migrate-descriptions', '--writers-paused', '--batch-size=2' ] );
			const before = await stored( requestUtils, task.id );
			await page.goto( `/?decker_page=task&id=${ task.id }` );
			await ready( page );
			const expected = await signature( page, before );
			const meaning = await documentMeaning( page, before );
			await page.fill( '#task-title', 'Title changed after migration' );
			await saved( page );
			const after = await stored( requestUtils, task.id );
			expect( await signature( page, after ) ).toEqual( expected );
			expect( await documentMeaning( page, after ) ).toEqual( meaning );
			if ( fixture === 'empty-paragraphs.html' ) expect( ( after.match( /<p[ >]/g ) || [] ).length ).toBe( 3 );
			const code = await page.evaluate( ( h ) => [ ...new DOMParser().parseFromString( h, 'text/html' ).querySelectorAll( 'pre' ) ].map( ( n ) => n.textContent ), before );
			expect( await page.evaluate( ( h ) => [ ...new DOMParser().parseFromString( h, 'text/html' ).querySelectorAll( 'pre' ) ].map( ( n ) => n.textContent ), after ) ).toEqual( code );
			await page.reload();
			await ready( page );
			expect( await signature( page, await stored( requestUtils, task.id ) ) ).toEqual( expected );
			expect( await documentMeaning( page, await editorHtml( page ) ) ).toEqual( meaning );
			await page.fill( '#task-title', 'Second title-only save after reload' );
			await saved( page );
			const repeated = await stored( requestUtils, task.id );
			expect( await signature( page, repeated ) ).toEqual( expected );
			expect( await documentMeaning( page, repeated ) ).toEqual( meaning );
		} );
	}

	test( 'Visual and Text edits both persist with legacy editor configuration', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		cli( [ 'eval', "$settings = get_option('decker_settings', array()); $settings['task_editor_type'] = 'quill'; update_option('decker_settings', $settings);" ] );
		const quillRequests = [];
		page.on( 'request', ( req ) => { if ( /quill|htmlEditButton/i.test( req.url() ) ) quillRequests.push( req.url() ); } );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await ready( page );
		await page.evaluate( () => { const ed = tinymce.get( 'task-description' ); ed.insertContent( '<p>Visual addition</p>' ); ed.fire( 'change' ); } );
		await page.locator( '#task-description-html' ).click();
		await page.locator( '#task-description' ).fill( ( await page.locator( '#task-description' ).inputValue() ) + '<h2>Text addition</h2>' );
		await saved( page );
		expect( await stored( requestUtils, task.id ) ).toContain( 'Text addition' );
		await page.reload();
		await ready( page );
		await page.locator( '#task-description-html' ).click();
		await page.locator( '#task-description' ).fill( ( await page.locator( '#task-description' ).inputValue() ) + '<p>Text first</p>' );
		await page.locator( '#task-description-tmce' ).click();
		await page.evaluate( () => { const ed = tinymce.get( 'task-description' ); ed.insertContent( '<p>Visual last</p>' ); ed.fire( 'change' ); } );
		await saved( page );
		const html = await stored( requestUtils, task.id );
		for ( const text of [ 'Visual addition', 'Text addition', 'Text first', 'Visual last' ] ) expect( html ).toContain( text );
		await page.reload();
		await ready( page );
		await page.locator( '#task-description-html' ).click();
		await page.locator( '#task-description' ).evaluate( ( textarea ) => {
			const offset = textarea.value.indexOf( 'Text first' );
			textarea.focus();
			textarea.setSelectionRange( offset, offset + 'Text first'.length );
		} );
		await page.locator( '#qt_task-description_strong' ).click();
		await expect( page.locator( '#save-task' ) ).toBeEnabled();
		await saved( page );
		expect( await stored( requestUtils, task.id ) ).toContain( '<strong>Text first</strong>' );
		expect( quillRequests ).toEqual( [] );
	} );
} );

// Selection uses TinyMCE's real range API; edits and undo use browser keys/buttons.
async function caret( page, selector, offset = -1 ) {
	await page.evaluate( ( { selector, offset } ) => {
		const ed = tinymce.get( 'task-description' );
		const node = ed.getBody().querySelector( selector );
		ed.focus();
		if ( offset < 0 ) ed.selection.select( node, true );
		else ed.selection.setCursorLocation( node.firstChild || node, offset );
		if ( offset < 0 ) ed.selection.collapse( false );
		ed.nodeChanged();
	}, { selector, offset } );
}

async function shortcut( page, keys ) {
	const modifier = await page.evaluate( () => tinymce.Env.mac ? 'Meta' : 'Control' );
	await page.keyboard.press( modifier + '+' + keys );
}

async function editorHtml( page ) {
	return page.evaluate( () => tinymce.get( 'task-description' ).getContent() );
}

async function setEditor( page, html ) {
	await page.evaluate( ( value ) => {
		const ed = tinymce.get( 'task-description' );
		ed.setContent( value );
		ed.undoManager.clear();
		ed.undoManager.add();
		ed.fire( 'change' );
	}, html );
}

test.describe( 'Classic checklist and modal lifecycle', () => {
	test.beforeAll( async ( { requestUtils } ) => { await requestUtils.activatePlugin( 'decker' ); } );

	test( 'real checklist creation, keyboard toggle, Enter, nesting, undo and persistence', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await ready( page );
		await setEditor( page, '<p>Completed</p><ul><li>Ordinary</li></ul>' );
		await caret( page, 'p' );
		await page.locator( '.mce-btn[aria-label="Checklist"] button' ).click();
		expect( ( await signature( page, await editorHtml( page ) ) )[ 0 ].state ).toBe( 'unchecked' );
		await caret( page, 'li' );
		await shortcut( page, 'Shift+Space' );
		expect( ( await signature( page, await editorHtml( page ) ) )[ 0 ].state ).toBe( 'checked' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( 'New pending' );
		let items = await signature( page, await editorHtml( page ) );
		expect( items.slice( 0, 2 ).map( ( item ) => [ item.text, item.state ] ) ).toEqual( [ [ 'Completed', 'checked' ], [ 'New pending', 'unchecked' ] ] );
		await caret( page, 'li', 3 );
		await page.keyboard.press( 'Enter' );
		items = await signature( page, await editorHtml( page ) );
		expect( items.slice( 0, 3 ).map( ( item ) => [ item.text, item.state ] ) ).toEqual( [ [ 'Com', 'checked' ], [ 'pleted', 'unchecked' ], [ 'New pending', 'unchecked' ] ] );
		await shortcut( page, 'z' );
		expect( ( await signature( page, await editorHtml( page ) ) )[ 0 ] ).toMatchObject( { text: 'Completed', state: 'checked' } );
		await shortcut( page, 'Shift+z' );
		expect( ( await signature( page, await editorHtml( page ) ) )[ 1 ] ).toMatchObject( { text: 'pleted', state: 'unchecked' } );
		await page.evaluate( () => { const ed = tinymce.get( 'task-description' ); ed.execCommand( 'Indent' ); } );
		expect( ( await signature( page, await editorHtml( page ) ) )[ 1 ].parent ).toBe( 0 );
		await page.evaluate( () => { const ed = tinymce.get( 'task-description' ); ed.execCommand( 'Outdent' ); } );
		expect( ( await signature( page, await editorHtml( page ) ) )[ 1 ].parent ).toBe( -1 );
		const expected = await signature( page, await editorHtml( page ) );
		expect( expected.at( -1 ) ).toMatchObject( { text: 'Ordinary', state: null } );
		await saved( page );
		expect( await signature( page, await stored( requestUtils, task.id ) ) ).toEqual( expected );
		await page.reload();
		await ready( page );
		expect( await signature( page, await editorHtml( page ) ) ).toEqual( expected );
	} );

	test( 'direct list actions leave nested lists alone, paste and empty-list exit', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await ready( page );
		await setEditor( page, '<ul><li data-list="checked">Parent<ul><li data-list="unchecked"><strong>Nested</strong></li></ul></li><li data-list="unchecked">Sibling</li></ul><ol><li>Numbered</li></ol>' );
		await caret( page, 'li', 2 );
		await page.locator( '.mce-btn[aria-label="Checklist"] button' ).click();
		let items = await signature( page, await editorHtml( page ) );
		expect( items.map( ( i ) => i.state ) ).toEqual( [ null, 'unchecked', null, null ] );
		await page.locator( '.mce-btn[aria-label="Checklist"] button' ).click();
		await caret( page, 'ul ul li' );
		await page.evaluate( () => tinymce.get( 'task-description' ).execCommand( 'mceInsertClipboardContent', false, { content: '<em> pasted</em>' } ) );
		items = await signature( page, await editorHtml( page ) );
		expect( items[ 1 ] ).toMatchObject( { text: 'Nested pasted', state: 'unchecked', bold: [ 'Nested pasted' ], italic: [ ' pasted' ] } );
		await saved( page );
		expect( await signature( page, await stored( requestUtils, task.id ) ) ).toEqual( items );
		await setEditor( page, '<p>Paste target</p>' );
		await caret( page, 'p', 0 );
		await page.evaluate( () => tinymce.get( 'task-description' ).execCommand( 'mceInsertClipboardContent', false, {
			content: '<ul><li data-list="checked"><strong>Copied marked</strong></li><li data-list="unchecked">Copied pending</li><li data-list="checked"><br></li></ul>',
		} ) );
		const pasted = await signature( page, await editorHtml( page ) );
		expect( pasted.map( ( item ) => [ item.text, item.state ] ) ).toEqual( [ [ 'Copied marked', 'checked' ], [ 'Copied pending', 'unchecked' ], [ '', 'checked' ] ] );
		expect( pasted[ 0 ].bold ).toEqual( [ 'Copied marked' ] );
		await saved( page );
		expect( await signature( page, await stored( requestUtils, task.id ) ) ).toEqual( pasted );
		await page.reload();
		await ready( page );
		expect( await signature( page, await editorHtml( page ) ) ).toEqual( pasted );
		await setEditor( page, '<ul><li data-list="checked">Keep</li><li data-list="unchecked"><br></li></ul>' );
		await caret( page, 'li:last-child', 0 );
		await page.keyboard.press( 'Enter' );
		expect( await page.evaluate( () => tinymce.get( 'task-description' ).selection.getNode().closest( 'li' ) ) ).toBeNull();
		expect( ( await signature( page, await editorHtml( page ) ) )[ 0 ].state ).toBe( 'checked' );
	} );

	test( 'modal dialogs receive focus and closing/reopening leaves one editor', async ( { page, requestUtils } ) => {
		const task = seed( 'task-6976.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		const board = await requestUtils.rest( { path: `/wp/v2/decker_board/${ task.board_id }` } );
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		for ( let repeat = 0; repeat < 2; repeat++ ) {
			await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
			await ready( page );
			expect( await page.evaluate( () => tinymce.editors.filter( ( e ) => e.id === 'task-description' ).length ) ).toBe( 1 );
			await caret( page, 'li', 0 );
			await page.evaluate( () => wpLink.open( 'task-description' ) );
			await expect( page.locator( '#wp-link-url' ) ).toBeVisible();
			await page.locator( '#wp-link-url' ).fill( 'https://example.invalid/modal-link' );
			await expect( page.locator( '#wp-link-url' ) ).toBeFocused();
			await page.locator( '#wp-link-cancel button' ).click();
			await page.locator( '.insert-media' ).click();
			await expect( page.locator( '.media-modal' ) ).toBeVisible();
			await page.getByRole( 'tab', { name: /Insert from URL|Insertar desde una URL/ } ).click();
			await page.locator( '.media-modal input[type="url"]' ).fill( 'https://example.invalid/media.png' );
			await expect( page.locator( '.media-modal input[type="url"]' ) ).toBeFocused();
			await page.locator( '.media-modal .media-toolbar-primary .media-button' ).click();
			await expect( page.locator( '.media-modal' ) ).not.toBeVisible();
			await expect.poll( () => editorHtml( page ) ).toContain( 'https://example.invalid/media.png' );
				const reloaded = page.waitForEvent( 'framenavigated', { predicate: ( frame ) => frame === page.mainFrame() } );
			await saved( page );
				await reloaded;
				await page.waitForLoadState( 'domcontentloaded' );
			expect( await stored( requestUtils, task.id ) ).toContain( 'https://example.invalid/media.png' );
			await expect( page.locator( '#task-modal' ) ).not.toBeVisible();
				expect( await page.evaluate( () => !!window.tinymce?.get( 'task-description' ) ) ).toBe( false );
		}
	} );

	test( 'old title-only form is rejected after a changed conversion', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await ready( page );
		// Restore exact legacy bytes while keeping the open pre-restore form.
		cli( [ 'decker', 'restore-descriptions', `--task-id=${ task.id }`, '--writers-paused' ] );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		const expected = await stored( requestUtils, task.id );
		await page.fill( '#task-title', 'Stale title must not overwrite' );
		const response = page.waitForResponse( ( r ) => ( r.request().postData() || '' ).includes( 'action=save_decker_task' ) );
		await page.locator( '#save-task' ).click();
		const result = await response;
		expect( result.status() ).toBe( 409 );
		expect( ( await result.json() ).success ).toBe( false );
		expect( await stored( requestUtils, task.id ) ).toBe( expected );
	} );

		test( 'ambiguous legacy input returns HTTP 400 without changing stored content', async ( { page, requestUtils } ) => {
			const task = seed( 'task-5531.html' );
			cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
			await page.goto( `/?decker_page=task&id=${ task.id }` );
			await ready( page );
			const original = await stored( requestUtils, task.id );
			await page.locator( '#task-description-html' ).click();
			await page.locator( '#task-description' ).fill( '<p class="ql-unknown">Needs review</p>' );
			const response = page.waitForResponse( ( result ) => ( result.request().postData() || '' ).includes( 'action=save_decker_task' ) );
			await page.locator( '#save-task' ).click();
			const result = await response;
			expect( result.status() ).toBe( 400 );
			const body = await result.json();
			expect( body.success ).toBe( false );
			expect( body.data.code ).toBe( 'decker_description_review' );
			expect( await stored( requestUtils, task.id ) ).toBe( original );
			await page.reload();
			await ready( page );
			expect( await stored( requestUtils, task.id ) ).toBe( original );
		} );

	test( 'closing while real TinyMCE init is held cannot orphan an instance', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		const board = await requestUtils.rest( { path: `/wp/v2/decker_board/${ task.board_id }` } );
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		await page.evaluate( () => {
			const original = wp.editor.initialize;
			window.heldEditorInit = null;
			wp.editor.initialize = function( id, config ) {
				const setup = config.tinymce.setup;
				config.tinymce.setup = function( editor ) {
					const on = editor.on;
					editor.on = function( events, handler, ...rest ) {
						return on.call( this, events, events === 'init' ? function( ...args ) {
							window.heldEditorInit = () => handler.apply( this, args );
						} : handler, ...rest );
					};
					setup( editor );
					editor.on = on;
				};
				wp.editor.initialize = original;
				return original.call( this, id, config );
			};
		} );
		await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
		await expect.poll( () => page.evaluate( () => typeof window.heldEditorInit ) ).toBe( 'function' );
		await page.locator( '#task-modal .modal-header .btn-close' ).click();
		await expect( page.locator( '#task-modal' ) ).not.toBeVisible();
		await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
		await ready( page );
		await page.evaluate( () => { window.reopenedEditor = tinymce.get( 'task-description' ); window.heldEditorInit(); } );
		expect( await page.evaluate( () => tinymce.get( 'task-description' ) === window.reopenedEditor ) ).toBe( true );
		await page.locator( '#task-modal .modal-header .btn-close' ).click();
		await expect.poll( () => page.evaluate( () => !!tinymce.get( 'task-description' ) ) ).toBe( false );
	} );

	test( 'archived description, checklist, Text and media remain read-only', async ( { page, requestUtils } ) => {
		const task = seed( 'task-5531.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		cli( [ 'post', 'update', String( task.id ), '--post_status=archived' ] );
		const before = await stored( requestUtils, task.id );
		await page.goto( `/?decker_page=task&id=${ task.id }` );
		await ready( page );
		expect( await page.evaluate( () => tinymce.get( 'task-description' ).getBody().getAttribute( 'contenteditable' ) ) ).toBe( 'false' );
		await expect( page.locator( '#task-description' ) ).toBeDisabled();
		await expect( page.locator( '.insert-media:enabled, .quicktags-toolbar input:enabled' ) ).toHaveCount( 0 );
		await caret( page, 'li', 0 );
		await shortcut( page, 'Shift+Space' );
		expect( await signature( page, await editorHtml( page ) ) ).toEqual( await signature( page, before ) );
		await expect( page.locator( '#save-task' ) ).toBeDisabled();
	} );


	test( 'knowledge base retains its own editor and saves independently', async ( { page, requestUtils } ) => {
		const article = await requestUtils.rest( { path: '/wp/v2/decker_kb', method: 'POST', data: { title: 'Classic KB regression', content: '<h2>Knowledge heading</h2><p>Original article</p>', status: 'publish' } } );
		await page.goto( '/?decker_page=knowledge-base' );
		await page.locator( `button.btn.kb-edit-btn[data-article-id="${ article.id }"]` ).click();
		await page.waitForFunction( ( id ) => window.tinymce?.get( `kb-editor-${ id }` )?.initialized, article.id );
		expect( await page.evaluate( () => !!tinymce.get( 'task-description' ) ) ).toBe( false );
		await page.evaluate( ( id ) => { const ed = tinymce.get( `kb-editor-${ id }` ); ed.insertContent( '<p>Independent KB edit</p>' ); ed.fire( 'change' ); }, article.id );
		const response = page.waitForResponse( ( r ) => r.url().includes( '/decker/v1/kb' ) && r.request().method() === 'POST' );
		await page.locator( `#edit-container-${ article.id } .kb-inline-save` ).click();
		const actual = await response;
		expect( actual.ok() ).toBe( true );
		expect( ( await actual.json() ).success ).toBe( true );
		const persisted = await requestUtils.rest( { path: `/wp/v2/decker_kb/${ article.id }?context=edit` } );
		expect( persisted.content.raw ).toContain( '<h2>Knowledge heading</h2>' );
		expect( persisted.content.raw ).toContain( 'Independent KB edit' );
		await page.reload();
		await expect( page.locator( `.kb-title-text .view-article-link[data-id="${ article.id }"]` ) ).toHaveAttribute( 'data-content', /Independent KB edit/ );
	} );


	test( 'alternating cards never mixes their descriptions or leaves editors behind', async ( { page, requestUtils } ) => {
		const first = seed( 'task-5531.html' );
		const second = seed( 'task-6976.html' );
		cli( [ 'decker', 'migrate-descriptions', '--writers-paused' ] );
		cli( [ 'eval', `wp_set_object_terms(${ second.id }, array(${ first.board_id }), 'decker_board');` ] );
		const board = await requestUtils.rest( { path: `/wp/v2/decker_board/${ first.board_id }` } );
		await page.goto( `/?decker_page=board&slug=${ board.slug }` );
		for ( const task of [ first, second, first ] ) {
			const persisted = await stored( requestUtils, task.id );
			await page.locator( `h5 a[data-task-id="${ task.id }"]` ).click();
			await ready( page );
			expect( await signature( page, await editorHtml( page ) ) ).toEqual( await signature( page, persisted ) );
			expect( await page.evaluate( () => tinymce.editors.filter( ( ed ) => ed.id === 'task-description' ).length ) ).toBe( 1 );
			await page.locator( '#task-modal .modal-header .btn-close' ).click();
			await expect( page.locator( '#task-modal' ) ).not.toBeVisible();
			await expect.poll( () => page.evaluate( () => !!tinymce.get( 'task-description' ) ) ).toBe( false );
		}
	} );

} );
