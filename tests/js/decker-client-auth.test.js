/**
 * Decker REST client: authentication, session, nonce, routing and transport.
 *
 * These tests drive the real client over real HTTP against two local servers
 * standing in for WordPress and CAS, on separate ports so that the
 * cross-origin rules are genuinely exercised rather than mocked. No
 * institutional credentials and no network access are involved, and passing
 * here does not certify compatibility with any particular CAS deployment.
 *
 * @vitest-environment node
 */

import { createServer } from 'node:http';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import * as client from '../../skills/decker/scripts/decker-client.mjs';

const NONCE = 'a1b2c3d4e5';
const USERNAME = 'decker.user';
const PASSWORD = 'correct horse battery staple';

let configDir;

/**
 * Write a JSON change file into the test's temporary directory.
 *
 * @param {object} contents Contents to write.
 * @return {string} Path to the file.
 */
function writeTempJson( contents ) {
	const path = join( configDir, 'changes.json' );
	writeFileSync( path, JSON.stringify( contents ) );

	return path;
}

/**
 * Start an HTTP server on a loopback port.
 *
 * @param {Function} handler Request handler.
 * @return {Promise<{origin: string, close: Function, server: object}>} Server handle.
 */
function startServer( handler ) {
	const server = createServer( handler );

	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => {
			const { port } = server.address();
			resolve( {
				server,
				origin: `http://127.0.0.1:${ port }`,
				close: () => new Promise( ( done ) => server.close( done ) ),
			} );
		} );
	} );
}

/**
 * The state the simulated deployment exposes to assertions.
 */
const state = {
	/** Fields the CAS server received on its last login POST. */
	submitted: null,
	/** Whether the CAS form includes a password input. */
	passwordField: true,
	/** Origin the CAS form posts to, when it must be wrong on purpose. */
	formActionOrigin: null,
	/** Whether the stored WordPress session is valid. */
	sessionValid: true,
	/** Whether /wp-json/ answers with JSON. */
	prettyRouting: true,
	/** Canned response for the next REST call: {status, type, body}. */
	restResponse: null,
	/** Milliseconds to stall the next WordPress request. */
	stallMs: 0,
	/** Origin WordPress redirects the admin entry point to. */
	entryRedirectOrigin: null,
	/** The last ability run the server saw. */
	lastRun: null,
	/** Whether /wp/v2/users answers with an HTML error page. */
	usersRouteBlocked: false,
	/** The last /wp/v2 collection request the server saw. */
	lastCollection: null,
	/** The last write the server saw. */
	lastWrite: null,
	/** The stored task, mutated by the write path. */
	task: null,
	/** Whether the task changes right after the next read. */
	changeAfterNextRead: false,
};

/** The task the simulator starts each test with. */
const INITIAL_TASK = {
	id: 31,
	title: 'A task',
	description: 'Body',
	board_id: 7,
	stack: 'to-do',
	label_ids: [ 8, 9 ],
	assignee_ids: [ 45 ],
	responsible_user_id: 45,
	author_id: 45,
	max_priority: false,
	hidden: false,
	archived: false,
	modified: '2026-10-29T10:00:00',
};

let wordpress;
let cas;
let elsewhere;

/**
 * Read a request body.
 *
 * @param {object} request Incoming request.
 * @return {Promise<string>} Body text.
 */
async function readBody( request ) {
	const chunks = [];
	for await ( const chunk of request ) {
		chunks.push( chunk );
	}
	return Buffer.concat( chunks ).toString( 'utf8' );
}

/**
 * Whether a request carries the simulated logged-in cookie.
 *
 * @param {object} request Incoming request.
 * @return {boolean} True when authenticated.
 */
function hasSessionCookie( request ) {
	return ( request.headers.cookie ?? '' ).includes( 'wordpress_logged_in=' ) && state.sessionValid;
}

beforeAll( async () => {
	elsewhere = await startServer( ( _request, response ) => {
		response.writeHead( 200, { 'content-type': 'text/html' } );
		response.end( '<html>another origin</html>' );
	} );

	cas = await startServer( async ( request, response ) => {
		const url = new URL( request.url, cas.origin );
		const service = url.searchParams.get( 'service' ) ?? '';

		if ( 'POST' === request.method ) {
			const fields = new URLSearchParams( await readBody( request ) );
			state.submitted = Object.fromEntries( fields );

			const valid = fields.get( 'username' ) === USERNAME
				&& fields.get( 'password' ) === PASSWORD
				&& 'e1s1' === fields.get( 'execution' );

			if ( ! valid ) {
				response.writeHead( 401, { 'content-type': 'text/html' } );
				response.end( '<html>Invalid credentials</html>' );
				return;
			}

			const ticketed = new URL( service );
			ticketed.searchParams.set( 'ticket', 'ST-OK' );
			response.writeHead( 302, { location: ticketed.href } );
			response.end();
			return;
		}

		const action = state.formActionOrigin
			? `${ state.formActionOrigin }/cas/login?service=${ encodeURIComponent( service ) }`
			: `/cas/login?service=${ encodeURIComponent( service ) }`;

		response.writeHead( 200, { 'content-type': 'text/html' } );
		response.end( `<html><body>
			<form method="post" action="${ action }">
				<input type="hidden" name="execution" value="e1s1" />
				<input type="hidden" name="lt" value="LT-7&amp;x" />
				<input type="hidden" name="disabled_field" value="skip" disabled />
				<input type="text" name="username" />
				${ state.passwordField ? '<input type="password" name="password" />' : '' }
				<input type="submit" name="submit" value="LOGIN" />
			</form>
		</body></html>` );
	} );

	wordpress = await startServer( async ( request, response ) => {
		const url = new URL( request.url, wordpress.origin );

		if ( state.stallMs ) {
			const stall = state.stallMs;
			state.stallMs = 0;
			await new Promise( ( resolve ) => setTimeout( resolve, stall ) );
		}

		if ( url.pathname === '/wp-admin/admin-ajax.php' && 'rest-nonce' === url.searchParams.get( 'action' ) ) {
			response.writeHead( 200, { 'content-type': 'text/html' } );
			response.end( hasSessionCookie( request ) ? NONCE : '0' );
			return;
		}

		if ( url.pathname === '/wp-admin/' ) {
			if ( 'ST-OK' === url.searchParams.get( 'ticket' ) ) {
				response.writeHead( 302, {
					'set-cookie': 'wordpress_logged_in=abc123; Path=/; HttpOnly',
					location: '/wp-admin/',
				} );
				response.end();
				return;
			}

			if ( hasSessionCookie( request ) ) {
				response.writeHead( 200, { 'content-type': 'text/html' } );
				response.end( '<html>Dashboard</html>' );
				return;
			}

			const target = state.entryRedirectOrigin ?? cas.origin;
			const login = new URL( '/cas/login', target );
			login.searchParams.set( 'service', `${ wordpress.origin }/wp-admin/` );
			response.writeHead( 302, { location: login.href } );
			response.end();
			return;
		}

		const restPath = url.pathname.startsWith( '/wp-json' )
			? url.pathname.slice( '/wp-json'.length ) || '/'
			: url.searchParams.get( 'rest_route' );

		if ( null === restPath ) {
			response.writeHead( 404, { 'content-type': 'text/html' } );
			response.end( '<html>Not found</html>' );
			return;
		}

		if ( url.pathname.startsWith( '/wp-json' ) && ! state.prettyRouting ) {
			response.writeHead( 404, { 'content-type': 'text/html' } );
			response.end( '<html>Institutional error page</html>' );
			return;
		}

		if ( state.restResponse ) {
			const canned = state.restResponse;
			state.restResponse = null;
			response.writeHead( canned.status, { 'content-type': canned.type } );
			response.end( canned.body );
			return;
		}

		if ( '/' === restPath ) {
			response.writeHead( 200, { 'content-type': 'application/json' } );
			response.end( JSON.stringify( { name: 'Decker test site', routes: {} } ) );
			return;
		}

		if ( NONCE !== request.headers[ 'x-wp-nonce' ] ) {
			response.writeHead( 401, { 'content-type': 'application/json' } );
			response.end( JSON.stringify( { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' } ) );
			return;
		}

		if ( restPath.startsWith( '/wp-abilities/v1/abilities/decker/' ) && restPath.endsWith( '/run' ) ) {
			const ability = restPath.slice( '/wp-abilities/v1/abilities/'.length, -'/run'.length );
			state.lastRun = { ability, method: request.method, query: Object.fromEntries( url.searchParams ) };

			if ( 'decker/update-task' === ability || 'decker/create-task' === ability ) {
				const payload = JSON.parse( await readBody( request ) ).input ?? {};
				state.lastWrite = { ability, input: payload };

				if ( 'decker/update-task' === ability
					&& undefined !== payload.expected_modified
					&& payload.expected_modified !== state.task.modified ) {
					response.writeHead( 409, { 'content-type': 'application/json' } );
					response.end( JSON.stringify( {
						code: 'decker_task_stale',
						message: 'The task changed after it was read.',
						data: { status: 409 },
					} ) );
					return;
				}

				const { task_id: ignoredId, expected_modified: ignoredModified, ...changes } = payload;
				state.task = { ...state.task, ...changes, modified: '2026-10-29T12:00:00' };

				response.writeHead( 200, { 'content-type': 'application/json' } );
				response.end( JSON.stringify( state.task ) );
				return;
			}

			if ( 'decker/get-task' === ability && state.changeAfterNextRead ) {
				// Simulates the real race: someone else saves the task in the
				// window between the client's read and its write.
				state.changeAfterNextRead = false;
				response.writeHead( 200, { 'content-type': 'application/json' } );
				response.end( JSON.stringify( state.task ) );
				state.task = { ...state.task, modified: '2026-10-29T11:30:00' };
				return;
			}

			const bodies = {
				'decker/list-boards': { boards: [ { id: 7, name: 'Board A', slug: 'board-a', description: '' } ] },
				'decker/list-tasks': {
					tasks: [ { id: 31, title: 'A task', board_id: 7 } ],
					page: 1,
					per_page: 20,
					total: 1,
					total_pages: 1,
				},
				'decker/get-task': state.task,
				'decker/list-users': state.usersRouteBlocked
					? null
					: {
						users: [ { id: 45, name: 'Ada Lovelace', slug: 'ada' } ],
						page: 1,
						per_page: 50,
						total: 1,
						total_pages: 1,
					},
			};

			if ( null === bodies[ ability ] ) {
				// Stands in for a proxy answering a REST route with its own page.
				response.writeHead( 404, { 'content-type': 'text/html' } );
				response.end( '<html>Institutional error page</html>' );
				return;
			}

			response.writeHead( 200, { 'content-type': 'application/json' } );
			response.end( JSON.stringify( bodies[ ability ] ?? {} ) );
			return;
		}

		if ( '/wp/v2/labels' === restPath ) {
			state.lastCollection = { path: restPath, query: Object.fromEntries( url.searchParams ) };
			response.writeHead( 200, {
				'content-type': 'application/json',
				'x-wp-total': '3',
				'x-wp-totalpages': '2',
			} );
			response.end( JSON.stringify( [
				{ id: 8, name: 'Añadir café', slug: 'anadir-cafe', count: 2 },
				{ id: 9, name: 'Urgent', slug: 'urgent', count: 0 },
			] ) );
			return;
		}

		if ( '/wp/v2/users' === restPath ) {
			if ( state.usersRouteBlocked ) {
				response.writeHead( 404, { 'content-type': 'text/html' } );
				response.end( '<html>Institutional error page</html>' );
				return;
			}
			response.writeHead( 200, {
				'content-type': 'application/json',
				'x-wp-total': '1',
				'x-wp-totalpages': '1',
			} );
			response.end( JSON.stringify( [ { id: 45, name: 'Ada Lovelace', slug: 'ada' } ] ) );
			return;
		}

		if ( '/wp-abilities/v1/abilities' === restPath ) {
			response.writeHead( 200, { 'content-type': 'application/json' } );
			response.end( JSON.stringify( [
				{ name: 'decker/list-tasks' },
				{ name: 'decker/create-task' },
				{ name: 'core/unrelated' },
			] ) );
			return;
		}

		response.writeHead( 200, { 'content-type': 'application/json' } );
		response.end( JSON.stringify( { echo: restPath, query: Object.fromEntries( url.searchParams ) } ) );
	} );
} );

afterAll( async () => {
	await Promise.all( [ wordpress?.close(), cas?.close(), elsewhere?.close() ] );
} );

beforeEach( () => {
	configDir = mkdtempSync( join( tmpdir(), 'decker-client-' ) );
	// The real OS keychain must never be touched by a test run.
	process.env.DECKER_CONFIG_DIR = configDir;
	process.env.DECKER_SESSION_STORE = 'file';

	state.submitted = null;
	state.passwordField = true;
	state.formActionOrigin = null;
	state.sessionValid = true;
	state.prettyRouting = true;
	state.restResponse = null;
	state.stallMs = 0;
	state.entryRedirectOrigin = null;
	state.lastRun = null;
	state.usersRouteBlocked = false;
	state.lastCollection = null;
	state.lastWrite = null;
	state.task = { ...INITIAL_TASK, label_ids: [ 8, 9 ], assignee_ids: [ 45 ] };
	state.changeAfterNextRead = false;
} );

afterEach( () => {
	rmSync( configDir, { recursive: true, force: true } );
	delete process.env.DECKER_CONFIG_DIR;
	delete process.env.DECKER_SESSION_STORE;
} );

/**
 * Log in with the simulated deployment's settings.
 *
 * @param {object} [overrides] Flag overrides.
 * @return {Promise<object>} Login result.
 */
function login( overrides = {} ) {
	return client.authLogin(
		{
			site: wordpress.origin,
			cas: `${ cas.origin }/cas/login`,
			user: USERNAME,
			'allow-insecure-http': true,
			...overrides,
		},
		{ readPassword: () => PASSWORD }
	);
}

describe( 'CAS authentication', () => {
	it( 'establishes a session and reports what it configured', async () => {
		const result = await login();

		expect( result ).toMatchObject( {
			ok: true,
			authenticated: true,
			user: USERNAME,
			routing: 'wp-json',
			nonce_acquired: true,
		} );
	} );

	it( 'preserves the hidden CAS form fields, including entity-encoded ones', async () => {
		await login();

		expect( state.submitted ).toMatchObject( {
			execution: 'e1s1',
			lt: 'LT-7&x',
			username: USERNAME,
			_eventId: 'submit',
		} );
		expect( state.submitted ).not.toHaveProperty( 'disabled_field' );
	} );

	it( 'rejects a wrong password without storing a session', async () => {
		await expect(
			client.authLogin(
				{
					site: wordpress.origin,
					cas: `${ cas.origin }/cas/login`,
					user: USERNAME,
					'allow-insecure-http': true,
				},
				{ readPassword: () => 'wrong' }
			)
		).rejects.toMatchObject( { code: 'auth_login_rejected', exit: client.EXIT.AUTH } );

		expect( client.sessionStore.read() ).toBeNull();
	} );

	it( 'reports an unsupported login form instead of guessing at it', async () => {
		state.passwordField = false;

		await expect( login() ).rejects.toMatchObject( { code: 'auth_form_unsupported' } );
	} );

	it( 'refuses to post the credentials to another origin', async () => {
		state.formActionOrigin = elsewhere.origin;

		await expect( login() ).rejects.toMatchObject( { code: 'auth_cross_origin_form' } );
		expect( state.submitted ).toBeNull();
	} );

	it( 'refuses a redirect to an origin that is not configured', async () => {
		state.entryRedirectOrigin = elsewhere.origin;

		await expect( login() ).rejects.toMatchObject( {
			code: 'auth_untrusted_redirect',
			exit: client.EXIT.AUTH,
		} );
	} );

	it( 'refuses plain HTTP unless it is explicitly allowed', async () => {
		await expect(
			login( { 'allow-insecure-http': undefined } )
		).rejects.toMatchObject( { code: 'config_invalid_url', exit: client.EXIT.USAGE } );
	} );

	it( 'never accepts a password as a command-line argument', () => {
		expect( () => client.parseFlags( [ '--user', 'x', '--password', 'secret' ] ) )
			.toThrowError( /never accepted as a command-line argument/ );
	} );

	it( 'keeps the password and cookies out of the result it prints', async () => {
		const result = await login();
		const printed = JSON.stringify( result );

		expect( printed ).not.toContain( PASSWORD );
		expect( printed ).not.toContain( NONCE );
		expect( printed ).not.toContain( 'wordpress_logged_in' );
	} );

	it( 'times out instead of hanging', async () => {
		client.saveConfig( { timeoutMs: 50 } );
		state.stallMs = 300;

		await expect(
			client.authLogin(
				{
					site: wordpress.origin,
					cas: `${ cas.origin }/cas/login`,
					user: USERNAME,
					'allow-insecure-http': true,
				},
				{ readPassword: () => PASSWORD }
			)
		).rejects.toMatchObject( { code: 'network_failed', exit: client.EXIT.NETWORK } );
	}, 10000 );
} );

describe( 'real Apereo CAS markup', () => {
	// Captured 2026-10-29 from https://www.casserverpac4j.dev/login?service=...,
	// the public Apereo/pac4j demo server, byte for byte. The fixture keeps the
	// whole page rather than the form alone, so the parser is shown picking the
	// right form out of a real document with its inline CSS and scripts. No
	// credentials were submitted to capture it.
	const page = readFileSync(
		new URL( '../fixtures/cas-apereo-login.html', import.meta.url ),
		'utf8'
	);
	const pageUrl = 'https://www.casserverpac4j.dev/login?service=http%3A%2F%2Flocalhost%3A8889%2Fwp-admin%2F';

	it( 'finds the login form in a real CAS page', () => {
		const { fields } = client.parseLoginForm( page, pageUrl );

		// A modern CAS carries its flow state in `execution`; the old `lt`
		// field is gone, which is why no field name is required to be present.
		expect( fields.execution ).toMatch( /^[0-9a-f-]{36}_/ );
		expect( fields.execution.length ).toBeGreaterThan( 100 );
		expect( fields._eventId ).toBe( 'submit' );
	} );

	it( 'preserves the empty hidden fields a real CAS page carries', () => {
		const { fields } = client.parseLoginForm( page, pageUrl );

		// geolocation and deviceFingerprint are populated by the page's own
		// JavaScript, which this client does not run. They are sent back empty,
		// exactly as a browser with scripting disabled would send them.
		expect( fields ).toHaveProperty( 'geolocation', '' );
		expect( fields ).toHaveProperty( 'deviceFingerprint', '' );
	} );

	it( 'uses the field names the real form declares', () => {
		// Asserted against the fixture so a future CAS that renamed these is
		// caught here rather than as a mysterious rejected login.
		expect( page ).toMatch( /<input[^>]*type="text"[^>]*name="username"/ );
		expect( page ).toMatch( /name="password"/ );
	} );

	it( 'resolves the relative form action the way a browser does', () => {
		const { action } = client.parseLoginForm( page, pageUrl );

		// The real page uses action="login", which resolves against the page URL
		// and therefore DROPS the ?service= parameter. That is not a defect: a
		// browser resolves it identically, and CAS carries the service inside
		// the `execution` flow state. Asserted explicitly because it looks wrong.
		expect( action ).toBe( 'https://www.casserverpac4j.dev/login' );
	} );

	it( 'keeps the submission on the CAS origin', () => {
		const { action } = client.parseLoginForm( page, pageUrl );

		expect( new URL( action ).origin ).toBe( 'https://www.casserverpac4j.dev' );
	} );
} );

describe( 'session lifecycle', () => {
	it( 'reports an authenticated status after login', async () => {
		await login();

		await expect( client.authStatus() ).resolves.toMatchObject( {
			authenticated: true,
			user: USERNAME,
		} );
	} );

	it( 'reports an expired session rather than failing', async () => {
		await login();
		state.sessionValid = false;

		await expect( client.authStatus() ).resolves.toMatchObject( {
			authenticated: false,
			reason: 'session_expired',
		} );
	} );

	it( 'reports no session before any login', async () => {
		await expect( client.authStatus() ).resolves.toMatchObject( { authenticated: false } );
	} );

	it( 'clears the stored session on logout', async () => {
		await login();
		expect( client.sessionStore.read() ).not.toBeNull();

		expect( client.authLogout() ).toMatchObject( { session_cleared: true } );
		expect( client.sessionStore.read() ).toBeNull();
	} );

	it( 'requires a login before an authenticated command', async () => {
		client.saveConfig( { siteUrl: wordpress.origin, allowInsecureHttp: true } );

		await expect( client.createContext() ).rejects.toMatchObject( {
			code: 'auth_required',
			exit: client.EXIT.AUTH,
		} );
	} );
} );

describe( 'REST routing and transport', () => {
	it( 'falls back to rest_route when /wp-json/ is not served', async () => {
		state.prettyRouting = false;

		const result = await login();

		expect( result.routing ).toBe( 'rest-route' );
	} );

	it( 'builds both routing forms with bracketed input parameters', () => {
		const base = new URL( 'https://example.org/medusa/gestionate/' );
		const params = { input: { task_id: 12, include_hidden: false } };

		expect( client.restUrl( base, 'wp-json', '/wp-abilities/v1/abilities', params ).href ).toBe(
			'https://example.org/medusa/gestionate/wp-json/wp-abilities/v1/abilities?input%5Btask_id%5D=12&input%5Binclude_hidden%5D=0'
		);
		expect( client.restUrl( base, 'rest-route', '/wp-abilities/v1/abilities', params ).href ).toBe(
			'https://example.org/medusa/gestionate/?rest_route=%2Fwp-abilities%2Fv1%2Fabilities&input%5Btask_id%5D=12&input%5Binclude_hidden%5D=0'
		);
	} );

	it( 'sends booleans as 1 and 0, never as true and false', () => {
		expect( client.flattenParams( { input: { a: true, b: false } } ) ).toEqual( {
			'input[a]': '1',
			'input[b]': '0',
		} );
	} );

	it( 'drops omitted values instead of sending them as empty', () => {
		expect( client.flattenParams( { input: { a: 1, b: undefined, c: null } } ) ).toEqual( {
			'input[a]': '1',
		} );
	} );

	it( 'sends the nonce and decodes the response', async () => {
		await login();
		const context = await client.createContext();

		await expect( client.restRequest( context, 'GET', '/decker/v1/ping' ) ).resolves.toMatchObject( {
			echo: '/decker/v1/ping',
		} );
	} );

	it( 'treats HTML on a read as a transport failure', async () => {
		await login();
		const context = await client.createContext();
		state.restResponse = { status: 200, type: 'text/html', body: '<html>Login</html>' };

		await expect( client.restRequest( context, 'GET', '/decker/v1/ping' ) ).rejects.toMatchObject( {
			code: 'rest_not_json',
			exit: client.EXIT.NETWORK,
		} );
	} );

	it( 'treats HTML on a write as an uncertain outcome, not a failure to retry', async () => {
		await login();
		const context = await client.createContext();
		state.restResponse = { status: 502, type: 'text/html', body: '<html>Bad gateway</html>' };

		await expect(
			client.restRequest( context, 'POST', '/decker/v1/ping', { body: { a: 1 } } )
		).rejects.toMatchObject( {
			code: 'rest_not_json',
			exit: client.EXIT.UNCERTAIN,
		} );
	} );

	it( 'reports malformed JSON', async () => {
		await login();
		const context = await client.createContext();
		state.restResponse = { status: 200, type: 'application/json', body: '{"a":' };

		await expect( client.restRequest( context, 'GET', '/decker/v1/ping' ) ).rejects.toMatchObject( {
			code: 'rest_malformed_json',
		} );
	} );

	it.each( [
		[ 401, client.EXIT.AUTH ],
		[ 403, client.EXIT.FORBIDDEN ],
		[ 404, client.EXIT.NOT_FOUND ],
		[ 409, client.EXIT.CONFLICT ],
		[ 429, client.EXIT.RATE_LIMITED ],
		[ 500, client.EXIT.SERVER ],
	] )( 'maps HTTP %i to its documented exit code', async ( status, exit ) => {
		await login();
		const context = await client.createContext();
		state.restResponse = {
			status,
			type: 'application/json',
			body: JSON.stringify( { code: 'wp_error_code', message: 'Private task title must not leak' } ),
		};

		const error = await client.restRequest( context, 'GET', '/decker/v1/ping' ).catch( ( caught ) => caught );

		expect( error.exit ).toBe( exit );
		expect( error.status ).toBe( status );
		// The server's message may quote private content, so it is not echoed.
		expect( error.message ).not.toContain( 'Private task title' );
	} );

	it( 'rejects an invalid nonce as an expired session', async () => {
		await login();
		const context = await client.createContext();
		context.nonce = 'ffffffffff';

		await expect( client.restRequest( context, 'GET', '/decker/v1/ping' ) ).rejects.toMatchObject( {
			code: 'rest_cookie_invalid_nonce',
			exit: client.EXIT.AUTH,
		} );
	} );
} );

describe( 'doctor', () => {
	it( 'reports the configuration gap before anything is set up', async () => {
		await expect( client.doctor() ).resolves.toMatchObject( {
			ok: false,
			site: null,
			checks: { config: expect.stringContaining( 'missing' ) },
		} );
	} );

	it( 'reports routing, session and the exposed Decker abilities', async () => {
		await login();

		const report = await client.doctor();

		expect( report.ok ).toBe( true );
		expect( report.checks.routing ).toBe( 'wp-json' );
		expect( report.checks.session ).toBe( 'valid' );
		expect( report.checks.abilities ).toContain( 'decker/list-tasks' );
		// Only Decker's abilities are reported; the rest is noise.
		expect( report.checks.abilities ).not.toContain( 'core/unrelated' );
	} );

	it( 'reports an expired session without failing hard', async () => {
		await login();
		state.sessionValid = false;

		const report = await client.doctor();

		expect( report.ok ).toBe( false );
		expect( report.checks.session ).toBe( 'auth_session_expired' );
	} );
} );

describe( 'read commands', () => {
	beforeEach( async () => {
		await login();
	} );

	it( 'lists boards through the ability', async () => {
		await expect( client.boardsList() ).resolves.toMatchObject( {
			ok: true,
			boards: [ { id: 7, slug: 'board-a' } ],
		} );
		expect( state.lastRun ).toMatchObject( { ability: 'decker/list-boards', method: 'GET' } );
	} );

	it( 'lists labels with the collection totals from the headers', async () => {
		const result = await client.labelsList( {} );

		expect( result ).toMatchObject( { ok: true, total: 3, total_pages: 2, page: 1 } );
		// Unicode survives the round trip intact.
		expect( result.labels[ 0 ].name ).toBe( 'Añadir café' );
	} );

	it( 'requests only the label fields an agent needs, and honours pagination', async () => {
		await client.labelsList( { page: '2', 'per-page': '50' } );

		// Asking for every field would pour term meta into the model's context.
		expect( state.lastCollection.query ).toMatchObject( {
			_fields: 'id,name,slug,count',
			page: '2',
			per_page: '50',
		} );
	} );

	it( 'searches users through the Decker ability, not /wp/v2/users', async () => {
		await expect( client.usersSearch( 'ada', {} ) ).resolves.toMatchObject( {
			ok: true,
			users: [ { id: 45, slug: 'ada' } ],
			total: 1,
		} );

		// Core's users route answers an ordinary user with an empty list, so the
		// client must not be reaching for it.
		expect( state.lastRun.ability ).toBe( 'decker/list-users' );
		expect( state.lastRun.query ).toMatchObject( { 'input[search]': 'ada' } );
	} );

	it( 'requires a query to search users', async () => {
		await expect( client.usersSearch( '   ', {} ) ).rejects.toMatchObject( {
			code: 'usage_missing_query',
			exit: client.EXIT.USAGE,
		} );
	} );

	it( 'fails loudly when the route is answered with an HTML page', async () => {
		state.usersRouteBlocked = true;

		// An HTML error page must never be reported as "no users matched".
		await expect( client.usersSearch( 'ada', {} ) ).rejects.toMatchObject( {
			code: 'rest_not_json',
		} );
	} );

	it( 'lists tasks and passes the filters as bracketed input', async () => {
		await client.tasksList( { mine: true, today: true, board: '7', stack: 'in-progress' } );

		expect( state.lastRun.ability ).toBe( 'decker/list-tasks' );
		expect( state.lastRun.method ).toBe( 'GET' );
		expect( state.lastRun.query ).toMatchObject( {
			'input[relation]': 'any',
			'input[today]': '1',
			'input[board_id]': '7',
			'input[stack]': 'in-progress',
			'input[page]': '1',
			'input[per_page]': '20',
		} );
	} );

	it( 'maps each relation flag to its server-side relation', async () => {
		const cases = {
			mine: 'any',
			'assigned-to-me': 'assigned',
			'responsible-by-me': 'responsible',
			'authored-by-me': 'author',
		};

		for ( const [ flag, relation ] of Object.entries( cases ) ) {
			await client.tasksList( { [ flag ]: true } );
			expect( state.lastRun.query[ 'input[relation]' ] ).toBe( relation );
		}
	} );

	it( 'refuses two relation flags rather than silently answering one', async () => {
		await expect(
			client.tasksList( { mine: true, 'assigned-to-me': true } )
		).rejects.toMatchObject( { code: 'usage_conflicting_flags', exit: client.EXIT.USAGE } );
	} );

	it( 'omits a filter that was not asked for', async () => {
		await client.tasksList( {} );

		expect( state.lastRun.query ).not.toHaveProperty( 'input[relation]' );
		expect( state.lastRun.query ).not.toHaveProperty( 'input[today]' );
		expect( state.lastRun.query ).not.toHaveProperty( 'input[include_hidden]' );
	} );

	it( 'validates pagination before sending a request', async () => {
		await expect( client.tasksList( { page: '0' } ) ).rejects.toMatchObject( {
			code: 'usage_invalid_value',
			exit: client.EXIT.USAGE,
		} );
		await expect( client.tasksList( { 'per-page': '500' } ) ).rejects.toMatchObject( {
			code: 'usage_invalid_value',
		} );
		await expect( client.tasksList( { page: 'two' } ) ).rejects.toMatchObject( {
			code: 'usage_invalid_value',
		} );
	} );

	it( 'validates the stack and status values before sending', async () => {
		await expect( client.tasksList( { stack: 'nope' } ) ).rejects.toMatchObject( {
			code: 'usage_invalid_value',
		} );
		await expect( client.tasksList( { status: 'deleted' } ) ).rejects.toMatchObject( {
			code: 'usage_invalid_value',
		} );
	} );

	it( 'reports a flag that is missing its value', async () => {
		await expect( client.tasksList( { board: true } ) ).rejects.toMatchObject( {
			code: 'usage_missing_value',
		} );
	} );

	it( 'shows one task', async () => {
		await expect( client.tasksShow( 31 ) ).resolves.toMatchObject( {
			ok: true,
			task: { id: 31 },
		} );
		expect( state.lastRun.query ).toMatchObject( { 'input[task_id]': '31' } );
	} );

	it( 'rejects a task id that is not a positive integer', async () => {
		for ( const id of [ '0', '-3', 'abc', undefined ] ) {
			await expect( client.tasksShow( id ) ).rejects.toMatchObject( {
				code: 'usage_invalid_task_id',
				exit: client.EXIT.USAGE,
			} );
		}
	} );

	it( 'routes the read commands through the dispatcher', async () => {
		await expect( client.run( [ 'boards', 'list' ] ) ).resolves.toMatchObject( { ok: true } );
		await expect( client.run( [ 'tasks', 'show', '31' ] ) ).resolves.toMatchObject( { ok: true } );
		await expect( client.run( [ 'users', 'search', 'ada' ] ) ).resolves.toMatchObject( { ok: true } );
		await expect( client.run( [ 'tasks', 'list', '--mine' ] ) ).resolves.toMatchObject( { ok: true } );
	} );

	it( 'lists the read commands in its usage message', async () => {
		const error = await client.run( [ 'nonsense' ] ).catch( ( caught ) => caught );

		expect( error.message ).toContain( 'tasks list' );
		expect( error.message ).toContain( 'labels list' );
	} );
} );

describe( 'write commands', () => {
	beforeEach( async () => {
		await login();
	} );

	it( 'refuses to write without an explicit confirmation flag', async () => {
		await expect(
			client.tasksUpdate( 31, { title: 'Renamed' } )
		).rejects.toMatchObject( {
			code: 'usage_confirmation_required',
			exit: client.EXIT.USAGE,
		} );

		expect( state.lastWrite ).toBeNull();
		expect( state.task.title ).toBe( 'A task' );
	} );

	it( 'previews a change without touching the server state', async () => {
		const result = await client.tasksUpdate( 31, { title: 'Renamed', 'dry-run': true } );

		expect( result ).toMatchObject( {
			dry_run: true,
			changed: false,
			before: { title: 'A task' },
			after: { title: 'Renamed' },
		} );
		expect( state.lastWrite ).toBeNull();
		expect( state.task.title ).toBe( 'A task' );
	} );

	it( 'applies a confirmed change and sends the read timestamp with it', async () => {
		const result = await client.tasksUpdate( 31, { title: 'Renamed', yes: true } );

		expect( result ).toMatchObject( { changed: true, task: { title: 'Renamed' } } );
		expect( state.lastWrite.input ).toMatchObject( {
			task_id: 31,
			title: 'Renamed',
			expected_modified: '2026-10-29T10:00:00',
		} );
	} );

	it( 'reports a no-op instead of writing it', async () => {
		// Writing an unchanged value would still bump post_modified and could
		// create a revision, so the client does not send it.
		const result = await client.tasksUpdate( 31, { title: 'A task', yes: true } );

		expect( result ).toMatchObject( { changed: false, reason: 'no_change' } );
		expect( state.lastWrite ).toBeNull();
	} );

	it( 'surfaces a stale write as a conflict and does not retry it', async () => {
		// The task changes in the window between the client's read and its
		// write, which is the race the timestamp exists to catch.
		state.changeAfterNextRead = true;

		const error = await client
			.tasksUpdate( 31, { title: 'Renamed', yes: true } )
			.catch( ( caught ) => caught );

		expect( error.code ).toBe( 'decker_task_stale' );
		expect( error.exit ).toBe( client.EXIT.CONFLICT );
		// It sent the timestamp it actually read, and the server refused it.
		expect( state.lastWrite.input.expected_modified ).toBe( '2026-10-29T10:00:00' );
		// One attempt only: a silent retry would overwrite the other change.
		expect( state.task.title ).toBe( 'A task' );
		expect( state.task.modified ).toBe( '2026-10-29T11:30:00' );
	} );

	it( 'adds one label and keeps the others', async () => {
		const result = await client.tasksLabelChange( 31, { label: '12', yes: true }, true );

		expect( result.changed ).toBe( true );
		expect( state.lastWrite.input.label_ids.sort() ).toEqual( [ 8, 9, 12 ].sort() );
		expect( state.task.label_ids ).toContain( 8 );
		expect( state.task.label_ids ).toContain( 9 );
	} );

	it( 'removes one label and keeps the others', async () => {
		await client.tasksLabelChange( 31, { label: '9', yes: true }, false );

		expect( state.lastWrite.input.label_ids ).toEqual( [ 8 ] );
	} );

	it( 'treats adding an existing label as a no-op', async () => {
		const result = await client.tasksLabelChange( 31, { label: '8', yes: true }, true );

		expect( result ).toMatchObject( { changed: false, reason: 'no_change' } );
		expect( state.lastWrite ).toBeNull();
	} );

	it( 'treats removing an absent label as a no-op', async () => {
		const result = await client.tasksLabelChange( 31, { label: '99', yes: true }, false );

		expect( result ).toMatchObject( { changed: false, reason: 'no_change' } );
		expect( state.lastWrite ).toBeNull();
	} );

	it( 'clears every label only when that is what was asked', async () => {
		await client.tasksUpdate( 31, { 'json-file': writeTempJson( { label_ids: [] } ), yes: true } );

		// An explicit empty array clears; an omitted field would have preserved.
		expect( state.lastWrite.input.label_ids ).toEqual( [] );
		expect( state.lastWrite.input ).not.toHaveProperty( 'assignee_ids' );
		expect( state.lastWrite.input ).not.toHaveProperty( 'title' );
	} );

	it( 'needs a label id', async () => {
		await expect(
			client.tasksLabelChange( 31, { yes: true }, true )
		).rejects.toMatchObject( { code: 'usage_missing_label' } );
	} );

	it( 'assigns a user without disturbing the existing assignees', async () => {
		await client.tasksAssign( 31, { user: '46', yes: true }, true );

		expect( state.lastWrite.input.assignee_ids.sort() ).toEqual( [ 45, 46 ] );
		expect( state.lastWrite.input ).not.toHaveProperty( 'label_ids' );
	} );

	it( 'unassigns a user without disturbing the others', async () => {
		state.task.assignee_ids = [ 45, 46 ];

		await client.tasksAssign( 31, { user: '46', yes: true }, false );

		expect( state.lastWrite.input.assignee_ids ).toEqual( [ 45 ] );
	} );

	it( 'assigning an already assigned user changes nothing', async () => {
		const result = await client.tasksAssign( 31, { user: '45', yes: true }, true );

		expect( result ).toMatchObject( { changed: false, reason: 'no_change' } );
	} );

	it( 'changes the responsible user without touching the assignees', async () => {
		await client.tasksResponsible( 31, { user: '46', yes: true } );

		// Author, responsible user and assignees are three different things.
		expect( state.lastWrite.input ).toMatchObject( { responsible_user_id: 46 } );
		expect( state.lastWrite.input ).not.toHaveProperty( 'assignee_ids' );
		expect( state.lastWrite.input ).not.toHaveProperty( 'author_id' );
	} );

	it( 'never sends the author through a write command', async () => {
		await expect(
			client.tasksUpdate( 31, { 'json-file': writeTempJson( { author_id: 99 } ), yes: true } )
		).rejects.toMatchObject( { code: 'usage_unknown_field' } );

		expect( state.lastWrite ).toBeNull();
	} );

	it( 'rejects an unknown field before sending it', async () => {
		await expect(
			client.tasksUpdate( 31, { 'json-file': writeTempJson( { nonsense: 1 } ), yes: true } )
		).rejects.toMatchObject( { code: 'usage_unknown_field', exit: client.EXIT.USAGE } );
	} );

	it( 'reports an unreadable or malformed change file', async () => {
		expect( () => client.readJsonFile( join( configDir, 'missing.json' ) ) ).toThrowError(
			/Could not read/
		);

		const broken = join( configDir, 'broken.json' );
		writeFileSync( broken, '{ not json' );
		expect( () => client.readJsonFile( broken ) ).toThrowError( /not valid JSON/ );

		const list = join( configDir, 'list.json' );
		writeFileSync( list, '[1,2]' );
		expect( () => client.readJsonFile( list ) ).toThrowError( /must contain a JSON object/ );
	} );

	it( 'requires a title and a board to create a task', async () => {
		await expect( client.tasksCreate( { title: 'Only a title', yes: true } ) ).rejects.toMatchObject(
			{ code: 'usage_missing_fields' }
		);
		expect( state.lastWrite ).toBeNull();
	} );

	it( 'previews a creation by reading, never by writing', async () => {
		const result = await client.tasksCreate( {
			title: 'New task',
			board: '7',
			'dry-run': true,
		} );

		expect( result ).toMatchObject( {
			dry_run: true,
			created: false,
			board_exists: true,
			would_create: { title: 'New task', board_id: 7 },
		} );
		expect( state.lastWrite ).toBeNull();
	} );

	it( 'reports a board that does not exist in a dry run', async () => {
		const result = await client.tasksCreate( { title: 'New task', board: '999', 'dry-run': true } );

		expect( result ).toMatchObject( { board_exists: false, board: null } );
	} );

	it( 'creates a task once confirmed', async () => {
		const result = await client.tasksCreate( { title: 'New task', board: '7', yes: true } );

		expect( result ).toMatchObject( { created: true } );
		expect( state.lastWrite ).toMatchObject( {
			ability: 'decker/create-task',
			input: { title: 'New task', board_id: 7 },
		} );
	} );

	it( 'ignores a task id or timestamp smuggled in through the change file', async () => {
		await client.tasksUpdate( 31, {
			'json-file': writeTempJson( { task_id: 999, expected_modified: 'whenever', title: 'Renamed' } ),
			yes: true,
		} );

		expect( state.lastWrite.input.task_id ).toBe( 31 );
		expect( state.lastWrite.input.expected_modified ).toBe( '2026-10-29T10:00:00' );
	} );

	it( 'lists a task\'s labels with their names', async () => {
		await expect( client.tasksLabels( 31 ) ).resolves.toMatchObject( {
			ok: true,
			task_id: 31,
			labels: [ { id: 8, name: 'Añadir café' }, { id: 9, name: 'Urgent' } ],
		} );
	} );

	it( 'routes the write commands through the dispatcher', async () => {
		await expect(
			client.run( [ 'tasks', 'label-add', '31', '--label', '12', '--dry-run' ] )
		).resolves.toMatchObject( { dry_run: true } );

		await expect(
			client.run( [ 'tasks', 'assign', '31', '--user', '46', '--dry-run' ] )
		).resolves.toMatchObject( { dry_run: true } );

		await expect(
			client.run( [ 'tasks', 'responsible', '31', '--user', '46' ] )
		).rejects.toMatchObject( { code: 'usage_confirmation_required' } );

		expect( state.lastWrite ).toBeNull();
	} );
} );

describe( 'command dispatch', () => {
	it( 'rejects an unknown command with the usage exit code', async () => {
		await expect( client.run( [ 'nonsense' ] ) ).rejects.toMatchObject( {
			code: 'usage_unknown_command',
			exit: client.EXIT.USAGE,
		} );
	} );

	it( 'routes auth status through the dispatcher', async () => {
		await login();

		await expect( client.run( [ 'auth', 'status' ] ) ).resolves.toMatchObject( { authenticated: true } );
	} );
} );
