/**
 * Regression tests for the persisted task list order (issue #317).
 *
 * The table setup lives inline in public/app-tasks.php, so the functions are
 * extracted from the template and evaluated against stubs, the same way the
 * other suites here evaluate their sources.
 *
 * @package Decker
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '../../public/app-tasks.php' ),
	'utf8'
);

const PREFERENCE_KEY = 'decker.tasks.tablePreferences';

/**
 * Extract a top level function from the template by name.
 *
 * @param {string} functionName Name of the function to extract.
 * @return {string} The function source, braces balanced.
 */
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

/**
 * The template's table constants, which the extracted functions close over.
 */
const constants = source.match( /^\tconst TABLE_[A-Z_]+ = .+$/gm ).join( '\n' );

/**
 * Render the table head the preferences are validated against.
 */
function renderTable() {
	document.body.innerHTML = `
		<table id="tablaTareas"><thead><tr>
			<th class="c-priority"></th>
			<th class="c-board"></th>
			<th class="c-stack"></th>
			<th class="c-description"></th>
			<th class="c-tags"></th>
			<th class="c-people"></th>
			<th class="c-time"></th>
			<th class="c-actions"></th>
		</tr></thead><tbody></tbody></table>
	`;
}

/**
 * Run setupAllTasksTable against stubs and return what it did.
 *
 * @param {string} initialBoard Board name coming from the URL, if any.
 * @return {Object} Captured DataTables options, searches and event handlers.
 */
function setupTable( initialBoard = '' ) {
	const searches = [];
	const handlers = {};
	const table = {
		column: ( index ) => ( {
			search: ( value ) => {
				searches.push( { index, value } );
				return table;
			},
		} ),
		draw: () => table,
		on: ( events, handler ) => {
			events.split( ' ' ).forEach( ( event ) => {
				handlers[ event ] = handler;
			} );
			return table;
		},
		order: () => [ [ 4, 'asc' ] ],
		page: { len: () => 200 },
	};

	let options = null;
	const element = {
		DataTable: ( config ) => {
			options = config;
			return table;
		},
		on: () => element,
		append: () => element,
		val: () => element,
	};

	const jQuery = () => element;
	jQuery.fn = { DataTable: { isDataTable: () => false } };

	const setup = new Function(
		'jQuery',
		'updateUrlWithFilters',
		`
		${ constants }
		${ extractFunctionSource( 'readTablePreferences' ) }
		${ extractFunctionSource( 'writeTablePreferences' ) }
		return (${ extractFunctionSource( 'setupAllTasksTable' ) });
		`
	)( jQuery, () => {} );

	setup( initialBoard );

	if ( options.initComplete ) {
		options.initComplete.call( table );
	}

	return { options, searches, handlers };
}

describe( 'task list table preferences', () => {
	beforeEach( () => {
		window.localStorage.clear();
		renderTable();
	} );

	test( 'restores the stored order and page length', () => {
		window.localStorage.setItem(
			PREFERENCE_KEY,
			JSON.stringify( { order: [ [ 1, 'asc' ] ], length: 100 } )
		);

		const { options } = setupTable();

		expect( options.order ).toEqual( [ [ 1, 'asc' ] ] );
		expect( options.pageLength ).toBe( 100 );
	} );

	test( 'keeps the defaults when nothing was stored yet', () => {
		const { options } = setupTable();

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( options.pageLength ).toBe( 50 );
	} );

	test( 'stores the order and page length whenever they change', () => {
		const { handlers } = setupTable();

		expect( typeof handlers[ 'order.dt' ] ).toBe( 'function' );
		expect( typeof handlers[ 'length.dt' ] ).toBe( 'function' );

		handlers[ 'order.dt' ]();

		expect(
			JSON.parse( window.localStorage.getItem( PREFERENCE_KEY ) )
		).toEqual( { order: [ [ 4, 'asc' ] ], length: 200 } );
	} );

	test.each( [
		[ 'not JSON at all', 'not json' ],
		[ 'the wrong shape', JSON.stringify( { order: 'board' } ) ],
		[ 'an unknown column', JSON.stringify( { order: [ [ 42, 'asc' ] ] } ) ],
		[ 'an unknown direction', JSON.stringify( { order: [ [ 1, 'up' ] ] } ) ],
		[ 'a length off the menu', JSON.stringify( { length: 37 } ) ],
	] )( 'ignores a stored preference with %s', ( _label, stored ) => {
		window.localStorage.setItem( PREFERENCE_KEY, stored );

		const { options } = setupTable();

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( options.pageLength ).toBe( 50 );
	} );

	test( 'stays usable when storage is blocked', () => {
		const storage = jest
			.spyOn( window.localStorage.__proto__, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );
		const setter = jest
			.spyOn( window.localStorage.__proto__, 'setItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );

		const { options, handlers } = setupTable();

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( () => handlers[ 'order.dt' ]() ).not.toThrow();

		storage.mockRestore();
		setter.mockRestore();
	} );

	test( 'never stores the filters, which stay URL driven', () => {
		const { handlers } = setupTable();

		handlers[ 'length.dt' ]();

		expect(
			Object.keys(
				JSON.parse( window.localStorage.getItem( PREFERENCE_KEY ) )
			).sort()
		).toEqual( [ 'length', 'order' ] );
	} );

	test( 'still applies the board filter coming from the URL', () => {
		const { searches } = setupTable( 'ATedu' );

		expect( searches ).toEqual( [ { index: 1, value: 'ATedu' } ] );
	} );
} );
