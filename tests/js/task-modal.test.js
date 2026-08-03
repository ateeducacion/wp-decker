/**
 * Regression tests for safe task modal title rendering.
 *
 * @package Decker
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '../../public/assets/js/task-modal.js' ),
	'utf8'
);

function extractFunctionSource( functionName ) {
	const start = source.indexOf( `function ${ functionName }` );
	if ( start === -1 ) {
		throw new Error( `Function ${ functionName } not found` );
	}

	const bodyStart = source.indexOf( '{', start );
	let depth = 0;

	for ( let position = bodyStart; position < source.length; position++ ) {
		const char = source[ position ];
		if ( char === '{' ) {
			depth++;
		} else if ( char === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return source.slice( start, position + 1 );
			}
		}
	}

	throw new Error( `Function ${ functionName } has no closing brace` );
}

function instantiate( functionName, scope ) {
	const keys = Object.keys( scope );
	const values = keys.map( ( key ) => scope[ key ] );
	const functionSource = extractFunctionSource( functionName );
	return new Function( ...keys, `return (${ functionSource });` )( ...values );
}

function wrapElement( element ) {
	const wrapper = {
		element,
		empty() {
			element.replaceChildren();
			return wrapper;
		},
		append( child ) {
			element.appendChild( child.element || child );
			return wrapper;
		},
		text( value ) {
			element.textContent = value;
			return wrapper;
		},
		attr( name, value ) {
			element.setAttribute( name, value );
			return wrapper;
		},
		addClass( className ) {
			element.classList.add( className );
			return wrapper;
		},
	};

	return wrapper;
}

function jQueryStub( markup ) {
	const match = /^<([a-z]+)><\/\1>$/.exec( markup );
	if ( ! match ) {
		throw new Error( `Unsupported jQuery markup: ${ markup }` );
	}

	return wrapElement( document.createElement( match[ 1 ] ) );
}

describe( 'renderTaskModalTitle', () => {
	let modalTitle;
	let renderTaskModalTitle;

	beforeEach( () => {
		document.body.innerHTML = '<h5 id="task-title"></h5>';
		modalTitle = document.getElementById( 'task-title' );

		const normalizeTaskId = instantiate( 'normalizeTaskId', {} );
		renderTaskModalTitle = instantiate( 'renderTaskModalTitle', {
			normalizeTaskId,
			deckerVars: {
				taskPermalinkStructure: 'https://example.com/tasks/%d',
				strings: {
					copy_task_url: 'Copy task URL',
				},
			},
			jQuery: jQueryStub,
			document,
		} );
	} );

	test( 'renders a normal task ID and the expected permalink', () => {
		renderTaskModalTitle( wrapElement( modalTitle ), '123' );

		expect( modalTitle.firstChild.nodeValue ).toBe( 'Task #123 ' );
		const copyLink = modalTitle.querySelector( 'a.copy-task-url' );
		expect( copyLink ).not.toBeNull();
		expect( copyLink.getAttribute( 'href' ) ).toBe( '#' );
		expect( copyLink.dataset.taskUrl ).toBe( 'https://example.com/tasks/123' );
		expect( copyLink.getAttribute( 'title' ) ).toBe( 'Copy task URL' );
		expect( copyLink.querySelector( 'i.ri-clipboard-line' ) ).not.toBeNull();
	} );

	test( 'rejects HTML-like task IDs without creating injected elements', () => {
		renderTaskModalTitle(
			wrapElement( modalTitle ),
			'123"><img src=x onerror=alert(1)>'
		);

		expect( modalTitle.textContent ).toBe( 'Task' );
		expect( modalTitle.querySelector( 'a' ) ).toBeNull();
		expect( modalTitle.querySelector( 'img' ) ).toBeNull();
	} );

	test( 'preserves task IDs beyond the JavaScript safe integer range', () => {
		const largeTaskId = '9007199254740993';
		renderTaskModalTitle( wrapElement( modalTitle ), largeTaskId );

		expect( modalTitle.firstChild.nodeValue ).toBe( `Task #${ largeTaskId } ` );
		expect( modalTitle.querySelector( 'a' ).dataset.taskUrl )
			.toBe( `https://example.com/tasks/${ largeTaskId }` );
	} );
} );
