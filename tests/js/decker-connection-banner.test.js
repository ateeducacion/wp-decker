/**
 * Unit tests for the connection banner in decker-heartbeat.js.
 *
 * The real setConnectionState and renderConnectionState are extracted from the
 * source file and executed with mocked collaborators, so behavior (not a copy)
 * is tested.
 *
 * @package Decker
 */

/* eslint-disable no-undef */

const fs = require( 'fs' );
const path = require( 'path' );

const SOURCE = fs.readFileSync(
	path.resolve( __dirname, '../../public/assets/js/decker-heartbeat.js' ),
	'utf8'
);

const STRINGS = {
	connection_offline: 'No internet connection.',
	connection_lost: 'The server is not responding.',
	connection_restored: 'Connection restored.',
	session_expired_message: 'Your session has expired.',
	log_in_again: 'Log in again',
};

/**
 * Extract a top-level function definition from the source.
 *
 * @param {string} functionName The function name to extract.
 * @return {string} The function source.
 */
function extractFunctionSource( functionName ) {
	const start = SOURCE.indexOf( `function ${ functionName }` );
	if ( start === -1 ) {
		throw new Error( `Function ${ functionName } not found` );
	}
	const bodyStart = SOURCE.indexOf( '{', start );
	let depth = 0;
	for ( let position = bodyStart; position < SOURCE.length; position++ ) {
		const char = SOURCE[ position ];
		if ( char === '{' ) {
			depth++;
		} else if ( char === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return SOURCE.slice( start, position + 1 );
			}
		}
	}
	throw new Error( `Function ${ functionName } has no closing brace` );
}

/**
 * Compile the extracted function with injected dependencies.
 *
 * @param {string} name The function name.
 * @param {Object} deps A map of dependency identifiers to values.
 * @return {Function} The compiled function.
 */
function compile( name, deps ) {
	const src = extractFunctionSource( name );
	const keys = Object.keys( deps );
	const values = keys.map( ( key ) => deps[ key ] );
	// eslint-disable-next-line no-new-func
	return new Function( ...keys, `return (${ src });` )( ...values );
}

describe( 'decker-heartbeat connection banner', () => {
	let setConnectionState;
	let showConnectionRestoredToast;

	/**
	 * Read the banner currently in the document, if any.
	 *
	 * @return {HTMLElement|null} The banner element.
	 */
	function banner() {
		return document.getElementById( 'decker-connection-banner' );
	}

	beforeEach( () => {
		document.body.innerHTML = '';
		showConnectionRestoredToast = jest.fn();
		setConnectionState = compile( 'setConnectionState', {
			CONNECTION_BANNER_ID: 'decker-connection-banner',
			deckerString: ( key ) => STRINGS[ key ] || '',
			showConnectionRestoredToast,
			deckerVars: { login_url: 'https://example.test/wp-login.php' },
		} );
	} );

	test( 'an unreachable server gets a danger banner without a log-in link', () => {
		setConnectionState( 'server' );

		expect( banner().className ).toContain( 'alert-danger' );
		expect( banner().textContent ).toBe( 'The server is not responding.' );
		expect( banner().querySelector( 'a' ) ).toBeNull();
	} );

	test( 'an offline browser gets a warning banner', () => {
		setConnectionState( 'offline' );

		expect( banner().className ).toContain( 'alert-warning' );
		expect( banner().textContent ).toBe( 'No internet connection.' );
	} );

	test( 'an expired session offers a log-in link in a new tab', () => {
		setConnectionState( 'session' );

		const link = banner().querySelector( 'a' );
		expect( link.href ).toBe( 'https://example.test/wp-login.php' );
		expect( link.target ).toBe( '_blank' );
		expect( link.textContent ).toBe( 'Log in again' );
	} );

	test( 'repeating the same state does not stack banners', () => {
		setConnectionState( 'server' );
		setConnectionState( 'server' );

		expect(
			document.querySelectorAll( '#decker-connection-banner' )
		).toHaveLength( 1 );
	} );

	test( 'a new state replaces the previous banner', () => {
		setConnectionState( 'offline' );
		setConnectionState( 'session' );

		expect(
			document.querySelectorAll( '#decker-connection-banner' )
		).toHaveLength( 1 );
		expect( banner().textContent ).toContain( 'Your session has expired.' );
	} );

	test( 'recovering from a connection problem clears the banner and confirms', () => {
		setConnectionState( 'server' );
		setConnectionState( '' );

		expect( banner() ).toBeNull();
		expect( showConnectionRestoredToast ).toHaveBeenCalled();
	} );

	test( 'logging back in clears the banner without claiming the connection was lost', () => {
		setConnectionState( 'session' );
		setConnectionState( '' );

		expect( banner() ).toBeNull();
		expect( showConnectionRestoredToast ).not.toHaveBeenCalled();
	} );

	test( 'a healthy tick on a healthy page does nothing', () => {
		setConnectionState( '' );

		expect( banner() ).toBeNull();
		expect( showConnectionRestoredToast ).not.toHaveBeenCalled();
	} );
} );

describe( 'decker-heartbeat connection state priority', () => {
	/**
	 * Render with a given combination of problems and report what it asked for.
	 *
	 * @param {Object} problems Flags: offline, serverDown, sessionExpired.
	 * @return {string} The state handed to setConnectionState.
	 */
	function render( problems ) {
		const setConnectionState = jest.fn();
		compile( 'renderConnectionState', {
			navigator: { onLine: ! problems.offline },
			serverDown: !! problems.serverDown,
			sessionExpired: !! problems.sessionExpired,
			setConnectionState,
		} )();

		return setConnectionState.mock.calls[ 0 ][ 0 ];
	}

	test( 'no problem renders no banner', () => {
		expect( render( {} ) ).toBe( '' );
	} );

	test( 'a missing network outranks everything else', () => {
		expect(
			render( { offline: true, serverDown: true, sessionExpired: true } )
		).toBe( 'offline' );
	} );

	test( 'an unreachable server outranks an expired session', () => {
		expect( render( { serverDown: true, sessionExpired: true } ) ).toBe(
			'server'
		);
	} );

	test( 'the network coming back does not hide a server that is still down', () => {
		expect( render( { serverDown: true } ) ).toBe( 'server' );
	} );

	test( 'the network coming back does not hide a session that is still expired', () => {
		expect( render( { sessionExpired: true } ) ).toBe( 'session' );
	} );
} );
