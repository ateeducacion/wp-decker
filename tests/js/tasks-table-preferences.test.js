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
 * @param {string} type         The task view, as the ?type= URL parameter.
 * @return {Object} Captured DataTables options, searches and event handlers.
 */
function setupTable( initialBoard = '', type = '' ) {
	window.history.replaceState( {}, '', type ? `/?type=${ type }` : '/' );

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
		${ extractFunctionSource( 'getUrlParam' ) }
		${ extractFunctionSource( 'getTableView' ) }
		${ extractFunctionSource( 'readAllTablePreferences' ) }
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

	/**
	 * Seed the stored preferences.
	 *
	 * @param {Object|string} value The value to store, verbatim when a string.
	 */
	function store( value ) {
		window.localStorage.setItem(
			PREFERENCE_KEY,
			typeof value === 'string' ? value : JSON.stringify( value )
		);
	}

	/**
	 * Read the stored preferences back.
	 *
	 * @return {Object} The parsed preferences.
	 */
	function stored() {
		return JSON.parse( window.localStorage.getItem( PREFERENCE_KEY ) );
	}

	test( 'restores the order and page length of the view being opened', () => {
		store( {
			my: { order: [ [ 1, 'asc' ] ], length: 100 },
			archived: { order: [ [ 3, 'asc' ] ], length: 25 },
		} );

		const { options } = setupTable( '', 'my' );

		expect( options.order ).toEqual( [ [ 1, 'asc' ] ] );
		expect( options.pageLength ).toBe( 100 );
	} );

	test( 'does not inherit the order of a sibling view', () => {
		store( { my: { order: [ [ 1, 'asc' ] ], length: 100 } } );

		const { options } = setupTable( '', 'archived' );

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( options.pageLength ).toBe( 50 );
	} );

	test( 'treats the task list with no type as its own view', () => {
		store( { all: { order: [ [ 6, 'desc' ] ], length: 200 } } );

		const { options } = setupTable();

		expect( options.order ).toEqual( [ [ 6, 'desc' ] ] );
		expect( options.pageLength ).toBe( 200 );
	} );

	test( 'keeps the defaults when nothing was stored yet', () => {
		const { options } = setupTable( '', 'my' );

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( options.pageLength ).toBe( 50 );
	} );

	test( 'stores the order and page length under the current view', () => {
		const { handlers } = setupTable( '', 'my' );

		expect( typeof handlers[ 'order.dt' ] ).toBe( 'function' );
		expect( typeof handlers[ 'length.dt' ] ).toBe( 'function' );

		handlers[ 'order.dt' ]();

		expect( stored() ).toEqual( {
			my: { order: [ [ 4, 'asc' ] ], length: 200 },
		} );
	} );

	test( 'leaves the other views alone when storing', () => {
		store( { archived: { order: [ [ 3, 'asc' ] ], length: 25 } } );

		const { handlers } = setupTable( '', 'my' );
		handlers[ 'order.dt' ]();

		expect( stored() ).toEqual( {
			archived: { order: [ [ 3, 'asc' ] ], length: 25 },
			my: { order: [ [ 4, 'asc' ] ], length: 200 },
		} );
	} );

	test( 'ignores a type that is not one of the task views', () => {
		const { options, handlers } = setupTable( '', 'bogus' );

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );

		handlers[ 'order.dt' ]();

		// A crafted ?type= must not seed a preference of its own.
		expect( Object.keys( stored() ) ).toEqual( [ 'all' ] );
	} );

	test.each( [
		[ 'not JSON at all', 'not json' ],
		[ 'the wrong shape', JSON.stringify( { my: 'board' } ) ],
		[ 'a flat legacy value', JSON.stringify( { order: [ [ 1, 'asc' ] ] } ) ],
		[
			'an unknown column',
			JSON.stringify( { my: { order: [ [ 42, 'asc' ] ] } } ),
		],
		[
			'an unknown direction',
			JSON.stringify( { my: { order: [ [ 1, 'up' ] ] } } ),
		],
		[
			'a length off the menu',
			JSON.stringify( { my: { length: 37 } } ),
		],
	] )( 'ignores a stored preference with %s', ( _label, value ) => {
		store( value );

		const { options } = setupTable( '', 'my' );

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( options.pageLength ).toBe( 50 );
	} );

	test( 'stays usable when storage is blocked', () => {
		const getter = jest
			.spyOn( window.localStorage.__proto__, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );
		const setter = jest
			.spyOn( window.localStorage.__proto__, 'setItem' )
			.mockImplementation( () => {
				throw new Error( 'blocked' );
			} );

		const { options, handlers } = setupTable( '', 'my' );

		expect( options.order ).toEqual( [ [ 0, 'desc' ] ] );
		expect( () => handlers[ 'order.dt' ]() ).not.toThrow();

		getter.mockRestore();
		setter.mockRestore();
	} );

	test( 'never stores the filters, which stay URL driven', () => {
		const { handlers } = setupTable( 'ATedu', 'my' );

		handlers[ 'length.dt' ]();

		expect( Object.keys( stored().my ).sort() ).toEqual( [
			'length',
			'order',
		] );
	} );

	test( 'still applies the board filter coming from the URL', () => {
		const { searches } = setupTable( 'ATedu', 'my' );

		expect( searches ).toEqual( [ { index: 1, value: 'ATedu' } ] );
	} );
} );
