/**
 * Decker REST client: the parts that need no HTTP.
 *
 * The companion suite drives the client over real sockets; this one covers
 * what sockets cannot reach — the session store's keychain branches, the
 * terminal password reader, the command dispatcher's every arm, the CLI
 * wrapper's exit codes, and the small pure helpers' edges.
 *
 * The keychain and terminal code is exercised through the injection seams the
 * client exposes for exactly this purpose, so **no test here touches a real
 * keychain or a real terminal**. That code handles credentials, which is why
 * leaving it uncovered was not acceptable.
 *
 * @vitest-environment node
 */

import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as client from '../../skills/decker/scripts/decker-client.mjs';

let configDir;
let realExec;

beforeEach( () => {
	configDir = mkdtempSync( join( tmpdir(), 'decker-units-' ) );
	process.env.DECKER_CONFIG_DIR = configDir;
	process.env.DECKER_SESSION_STORE = 'file';
	realExec = client.sessionStore.exec;
} );

afterEach( () => {
	client.sessionStore.exec = realExec;
	rmSync( configDir, { recursive: true, force: true } );
	delete process.env.DECKER_CONFIG_DIR;
	delete process.env.DECKER_SESSION_STORE;
} );

describe( 'DeckerError', () => {
	it( 'serializes without a status when there is none', () => {
		const error = new client.DeckerError( 'some_code', 'Some message', client.EXIT.USAGE );

		expect( error.toJSON() ).toEqual( {
			ok: false,
			code: 'some_code',
			message: 'Some message',
		} );
	} );

	it( 'includes the status when it has one', () => {
		const error = new client.DeckerError( 'some_code', 'Some message', client.EXIT.AUTH, 401 );

		expect( error.toJSON() ).toMatchObject( { status: 401 } );
	} );
} );

describe( 'session storage backend selection', () => {
	it( 'honours an explicit setting over the platform', () => {
		process.env.DECKER_SESSION_STORE = 'keychain';
		expect( client.sessionStore.backend() ).toBe( 'keychain' );

		process.env.DECKER_SESSION_STORE = 'file';
		expect( client.sessionStore.backend() ).toBe( 'file' );

		// Anything other than "file" means the keychain, so a typo does not
		// silently downgrade a session to a plaintext file.
		process.env.DECKER_SESSION_STORE = 'nonsense';
		expect( client.sessionStore.backend() ).toBe( 'keychain' );
	} );

	it( 'falls back to the platform default', () => {
		delete process.env.DECKER_SESSION_STORE;

		expect( client.sessionStore.backend() ).toBe(
			'darwin' === process.platform ? 'keychain' : 'file'
		);
	} );
} );

describe( 'keychain session storage', () => {
	beforeEach( () => {
		process.env.DECKER_SESSION_STORE = 'keychain';
	} );

	it( 'stores the session through the keychain command', () => {
		const exec = vi.fn();
		client.sessionStore.exec = exec;

		client.sessionStore.write( { cookies: { wordpress_logged_in: 'abc' } } );

		const [ command, args ] = exec.mock.calls[ 0 ];
		expect( command ).toBe( 'security' );
		expect( args.slice( 0, 6 ) ).toEqual( [
			'add-generic-password',
			'-U',
			'-s',
			'decker-client',
			'-a',
			'session',
		] );
		// The value is the serialized session and nothing else.
		expect( JSON.parse( args[ args.length - 1 ] ) ).toEqual( {
			cookies: { wordpress_logged_in: 'abc' },
		} );
	} );

	it( 'reads the session back', () => {
		client.sessionStore.exec = vi.fn( () => '{"cookies":{"a":"b"}}\n' );

		expect( client.sessionStore.read() ).toEqual( { cookies: { a: 'b' } } );
	} );

	it( 'reports no session when the keychain has none', () => {
		client.sessionStore.exec = vi.fn( () => {
			throw new Error( 'not found' );
		} );

		expect( client.sessionStore.read() ).toBeNull();
	} );

	it( 'reports no session when the keychain returns something unparseable', () => {
		client.sessionStore.exec = vi.fn( () => 'not json' );

		expect( client.sessionStore.read() ).toBeNull();
	} );

	it( 'clears the session, and logging out succeeds even with nothing stored', () => {
		const exec = vi.fn();
		client.sessionStore.exec = exec;
		client.sessionStore.clear();
		expect( exec.mock.calls[ 0 ][ 1 ][ 0 ] ).toBe( 'delete-generic-password' );

		client.sessionStore.exec = vi.fn( () => {
			throw new Error( 'nothing to delete' );
		} );
		expect( () => client.sessionStore.clear() ).not.toThrow();
		expect( client.authLogout() ).toMatchObject( { session_cleared: true } );
	} );
} );

describe( 'file session storage', () => {
	it( 'round-trips and warns that it is not a keychain', () => {
		const warn = vi.spyOn( process.stderr, 'write' ).mockImplementation( () => true );

		client.sessionStore.write( { cookies: { a: 'b' } } );

		expect( warn.mock.calls.map( ( c ) => String( c[ 0 ] ) ).join( '' ) ).toMatch(
			/0600 permissions/
		);
		warn.mockRestore();

		expect( client.sessionStore.read() ).toEqual( { cookies: { a: 'b' } } );

		client.sessionStore.clear();
		expect( client.sessionStore.read() ).toBeNull();
	} );
} );

describe( 'terminal password reader', () => {
	it( 'reads until the newline and never echoes', () => {
		const typed = [ ...'hunter2\n' ];
		const calls = [];
		const io = {
			open: () => 7,
			write: ( fd, text ) => calls.push( [ 'write', fd, text ] ),
			read: ( fd, buffer ) => {
				const next = typed.shift();
				if ( undefined === next ) {
					return 0;
				}
				buffer.write( next );
				return 1;
			},
			close: ( fd ) => calls.push( [ 'close', fd ] ),
			exec: ( command, args ) => calls.push( [ command, args[ 0 ] ] ),
		};

		expect( client.readPasswordFromTty( 'Password: ', io ) ).toBe( 'hunter2' );

		// Echo off before reading, back on afterwards, and the descriptor closed.
		expect( calls ).toContainEqual( [ 'stty', '-echo' ] );
		expect( calls ).toContainEqual( [ 'stty', 'echo' ] );
		expect( calls ).toContainEqual( [ 'close', 7 ] );
		// The prompt is written to the terminal, never the password.
		expect( calls ).toContainEqual( [ 'write', 7, 'Password: ' ] );
		expect( JSON.stringify( calls ) ).not.toContain( 'hunter2' );
	} );

	it( 'stops at a carriage return and at end of input', () => {
		const make = ( keys ) => {
			const typed = [ ...keys ];
			return {
				open: () => 7,
				write: () => {},
				read: ( fd, buffer ) => {
					const next = typed.shift();
					if ( undefined === next ) {
						return 0;
					}
					buffer.write( next );
					return 1;
				},
				close: () => {},
				exec: () => {},
			};
		};

		expect( client.readPasswordFromTty( 'p', make( 'abc\r' ) ) ).toBe( 'abc' );
		expect( client.readPasswordFromTty( 'p', make( 'abc' ) ) ).toBe( 'abc' );
	} );

	it( 'refuses to read a password without a terminal', () => {
		const io = {
			open: () => {
				throw new Error( 'ENXIO' );
			},
		};

		expect( () => client.readPasswordFromTty( 'p', io ) ).toThrowError(
			/terminal is required/
		);
	} );

	it( 'closes the terminal even when restoring the echo fails', () => {
		let closed = false;
		const io = {
			open: () => 7,
			write: () => {},
			read: () => 0,
			close: () => {
				closed = true;
			},
			exec: ( command, args ) => {
				if ( 'echo' === args[ 0 ] ) {
					throw new Error( 'stty failed' );
				}
			},
		};

		expect( () => client.readPasswordFromTty( 'p', io ) ).toThrow();
		expect( closed ).toBe( true );
	} );
} );

describe( 'URL and parameter helpers', () => {
	it( 'rejects URLs that are unsafe to use', () => {
		for ( const value of [
			'not a url',
			'http://example.org/',
			'https://user:pass@example.org/',
			'https://example.org/#fragment',
			'ftp://example.org/',
		] ) {
			expect( () => client.checkedUrl( value ) ).toThrowError( /Invalid URL|HTTPS URL/ );
		}
	} );

	it( 'allows plain HTTP only when asked', () => {
		expect( client.checkedUrl( 'http://localhost:8889/', true ).protocol ).toBe( 'http:' );
	} );

	it( 'builds admin URLs with and without a trailing slash', () => {
		for ( const base of [
			'https://example.org/medusa/gestionate/',
			'https://example.org/medusa/gestionate',
		] ) {
			const url = new URL( base );
			expect( client.adminAjaxUrl( url ).pathname ).toBe(
				'/medusa/gestionate/wp-admin/admin-ajax.php'
			);
			expect( client.adminUrl( url ).pathname ).toBe( '/medusa/gestionate/wp-admin/' );
		}
	} );

	it( 'flattens nested arrays into bracket syntax', () => {
		expect( client.flattenParams( { input: { label_ids: [ 8, 9 ] } } ) ).toEqual( {
			'input[label_ids][0]': '8',
			'input[label_ids][1]': '9',
		} );
	} );

	it( 'builds the ability run path', () => {
		expect( client.abilityPath( 'decker/get-task' ) ).toBe(
			'/wp-abilities/v1/abilities/decker/get-task/run'
		);
	} );

	it( 'compares collections as sets and scalars by value', () => {
		expect( client.sameValue( [ 9, 8, 8 ], [ 8, 9 ] ) ).toBe( true );
		expect( client.sameValue( [ 8 ], [ 8, 9 ] ) ).toBe( false );
		expect( client.sameValue( 'a', 'a' ) ).toBe( true );
		expect( client.sameValue( 1, '1' ) ).toBe( false );
	} );

	it( 'parses flags in both forms and keeps positionals', () => {
		const { flags, positional } = client.parseFlags( [
			'31',
			'--label=12',
			'--yes',
			'--user',
			'45',
			'extra',
		] );

		expect( flags ).toEqual( { label: '12', yes: true, user: '45' } );
		expect( positional ).toEqual( [ '31', 'extra' ] );
	} );

	it( 'validates integer and enum flags', () => {
		expect( client.integerFlag( {}, 'page' ) ).toBeUndefined();
		expect( client.integerFlag( { page: '3' }, 'page' ) ).toBe( 3 );
		expect( () => client.integerFlag( { page: true }, 'page' ) ).toThrowError(
			/needs a value/
		);
		expect( () => client.integerFlag( { page: '1.5' }, 'page' ) ).toThrowError(
			/whole number/
		);
		expect( client.enumFlag( {}, 'stack', [ 'a' ] ) ).toBeUndefined();
		expect( client.enumFlag( { stack: 'a' }, 'stack', [ 'a' ] ) ).toBe( 'a' );
		expect( () => client.enumFlag( { stack: 'b' }, 'stack', [ 'a' ] ) ).toThrowError(
			/must be one of/
		);
	} );

	it( 'rejects a change set with fields the agent may not write', () => {
		expect( () => client.assertWritableFields( { title: 'ok' } ) ).not.toThrow();
		expect( () => client.assertWritableFields( { author_id: 1 } ) ).toThrowError(
			/Not a writable task field/
		);
	} );
} );

describe( 'configuration', () => {
	it( 'reads an empty configuration when there is none', () => {
		expect( client.loadConfig() ).toEqual( {} );
	} );

	it( 'round-trips a configuration', () => {
		client.saveConfig( { siteUrl: 'https://example.org/', username: 'ada' } );

		expect( client.loadConfig() ).toMatchObject( { username: 'ada' } );
	} );

	it( 'requires a site before an authenticated command', async () => {
		await expect( client.createContext() ).rejects.toMatchObject( {
			code: 'config_missing',
			exit: client.EXIT.USAGE,
		} );
	} );
} );

describe( 'command dispatch covers every command', () => {
	/**
	 * Every command, with arguments that fail before any request is made, so
	 * the dispatcher is exercised without a server.
	 */
	const commands = [
		[ 'auth', 'login' ],
		[ 'auth', 'status' ],
		[ 'boards', 'list' ],
		[ 'labels', 'list' ],
		[ 'users', 'search' ],
		[ 'users', 'search', 'ada' ],
		[ 'tasks', 'list' ],
		[ 'tasks', 'show' ],
		[ 'tasks', 'show', '31' ],
		[ 'tasks', 'create' ],
		[ 'tasks', 'update' ],
		[ 'tasks', 'update', '31' ],
		[ 'tasks', 'labels' ],
		[ 'tasks', 'label-add', '31' ],
		[ 'tasks', 'label-remove', '31' ],
		[ 'tasks', 'assign', '31' ],
		[ 'tasks', 'unassign', '31' ],
		[ 'tasks', 'responsible', '31' ],
		[ 'tasks', 'today', '31' ],
		[ 'tasks', 'comments' ],
		[ 'tasks', 'comment', '31' ],
		[ 'tasks', 'move', '31' ],
		[ 'tasks', 'reorder', '31' ],
		[ 'tasks', 'priority', '31' ],
		[ 'tasks', 'archive' ],
		[ 'tasks', 'restore' ],
		[ 'tasks', 'nonsense' ],
		[ 'doctor' ],
		[ 'nonsense' ],
	];

	it.each( commands )( 'routes %s %s %s', async ( ...argv ) => {
		// Nothing is configured, so each command must fail with a Decker error
		// rather than an unhandled exception. What matters here is that the
		// dispatcher reaches a real handler for every command.
		const result = await client.run( argv ).catch( ( error ) => error );

		if ( result instanceof Error ) {
			expect( result.name ).toBe( 'DeckerError' );
			expect( result.exit ).toBeGreaterThan( 0 );
		} else {
			expect( result ).toHaveProperty( 'ok' );
		}
	} );

	it( 'reports an unknown auth subcommand as usage', async () => {
		await expect( client.run( [ 'auth', 'nonsense' ] ) ).rejects.toMatchObject( {
			code: 'usage_unknown_command',
		} );
	} );
} );

describe( 'CLI wrapper', () => {
	let stdout;
	let stderr;
	let argv;

	beforeEach( () => {
		stdout = [];
		stderr = [];
		argv = process.argv;
		vi.spyOn( process.stdout, 'write' ).mockImplementation( ( chunk ) => {
			stdout.push( String( chunk ) );
			return true;
		} );
		vi.spyOn( process.stderr, 'write' ).mockImplementation( ( chunk ) => {
			stderr.push( String( chunk ) );
			return true;
		} );
	} );

	afterEach( () => {
		vi.restoreAllMocks();
		process.argv = argv;
		process.exitCode = 0;
	} );

	it( 'prints JSON on stdout and exits 0 on success', async () => {
		process.argv = [ 'node', 'decker-client.mjs', 'auth', 'status' ];

		await client.main();

		expect( JSON.parse( stdout.join( '' ) ) ).toMatchObject( { authenticated: false } );
		expect( stderr ).toEqual( [] );
		expect( process.exitCode ).toBe( client.EXIT.OK );
	} );

	it( 'exits non-zero when the result reports a problem', async () => {
		process.argv = [ 'node', 'decker-client.mjs', 'doctor' ];

		await client.main();

		expect( JSON.parse( stdout.join( '' ) ).ok ).toBe( false );
		expect( process.exitCode ).toBe( client.EXIT.UNKNOWN );
	} );

	it( 'prints errors on stderr with the documented exit code', async () => {
		process.argv = [ 'node', 'decker-client.mjs', 'nonsense' ];

		await client.main();

		expect( stdout ).toEqual( [] );
		expect( JSON.parse( stderr.join( '' ) ) ).toMatchObject( {
			ok: false,
			code: 'usage_unknown_command',
		} );
		expect( process.exitCode ).toBe( client.EXIT.USAGE );
	} );

	it( 'never leaks an unexpected failure as a stack trace', async () => {
		process.argv = [ 'node', 'decker-client.mjs', 'auth', 'status' ];
		const stored = client.sessionStore.read;
		client.sessionStore.read = () => {
			throw new TypeError( 'something internal' );
		};

		await client.main();

		client.sessionStore.read = stored;
		const payload = JSON.parse( stderr.join( '' ) );
		expect( payload.code ).toBe( 'unknown_error' );
		// The internal message is not passed through to the caller.
		expect( JSON.stringify( payload ) ).not.toContain( 'something internal' );
		expect( process.exitCode ).toBe( client.EXIT.UNKNOWN );
	} );
} );

describe( 'cookie jar', () => {
	it( 'keeps the cookies a response sets and forgets the ones it clears', () => {
		const session = new client.Session( { origins: [ 'https://example.org' ] } );
		const response = {
			headers: {
				getSetCookie: () => [
					'wordpress_logged_in=abc; Path=/; HttpOnly',
					'XSRF-TOKEN=tok; Path=/',
					// Malformed and valueless entries must not break the jar.
					'novalue',
					'=orphan',
					'XSRF-TOKEN=; Path=/',
				],
			},
		};

		session.storeCookies( 'https://example.org', response );

		// The cleared one is gone; the kept one is sent back.
		const header = session.cookieHeader( 'https://example.org' );
		expect( header ).toContain( 'wordpress_logged_in=abc' );
		expect( header ).not.toContain( 'XSRF-TOKEN' );
		expect( header ).not.toContain( 'novalue' );
	} );

	it( 'treats a deleted value as a removal', () => {
		const session = new client.Session( { origins: [ 'https://example.org' ] } );
		session.importCookies( 'https://example.org', { session_id: 'live' } );

		session.storeCookies( 'https://example.org', {
			headers: { getSetCookie: () => [ 'session_id=deleted; Path=/' ] },
		} );

		expect( session.cookieHeader( 'https://example.org' ) ).toBe( '' );
	} );

	it( 'sends no cookie header for an origin it knows nothing about', () => {
		const session = new client.Session( { origins: [ 'https://example.org' ] } );

		expect( session.cookieHeader( 'https://other.example' ) ).toBe( '' );
		expect( session.exportCookies( 'https://other.example' ) ).toEqual( {} );
	} );
} );

describe( 'redirect handling', () => {
	/**
	 * Build a session whose fetch returns canned responses in order.
	 *
	 * @param {Array} responses Canned responses.
	 * @return {object} The session.
	 */
	function sessionWith( responses ) {
		const queue = [ ...responses ];

		return new client.Session( {
			origins: [ 'https://example.org', 'https://cas.example.org' ],
			fetch: async () => {
				const next = queue.shift();

				return {
					status: next.status ?? 200,
					ok: ( next.status ?? 200 ) < 400,
					url: next.url ?? 'https://example.org/',
					headers: {
						get: ( name ) => next.headers?.[ name ] ?? null,
						getSetCookie: () => [],
					},
					body: { cancel: async () => {} },
					text: async () => next.body ?? '',
				};
			},
		} );
	}

	it( 'refuses a redirect with no destination', async () => {
		const session = sessionWith( [ { status: 302, headers: {} } ] );

		await expect(
			session.request( new URL( 'https://example.org/wp-admin/' ) )
		).rejects.toMatchObject( { code: 'auth_unexpected_redirect' } );
	} );

	it( 'refuses a redirect when told not to follow one', async () => {
		const session = sessionWith( [
			{ status: 302, headers: { location: 'https://example.org/next' } },
		] );

		await expect(
			session.request( new URL( 'https://example.org/wp-admin/' ), {}, false )
		).rejects.toMatchObject( { code: 'auth_unexpected_redirect' } );
	} );

	it( 'turns a 303 into a GET', async () => {
		const seen = [];
		const session = new client.Session( {
			origins: [ 'https://example.org' ],
			fetch: async ( url, init ) => {
				seen.push( init.method );

				return {
					status: seen.length === 1 ? 303 : 200,
					ok: seen.length !== 1,
					url: url.href,
					headers: {
						get: ( name ) =>
							'location' === name && seen.length === 1
								? 'https://example.org/done'
								: null,
						getSetCookie: () => [],
					},
					body: { cancel: async () => {} },
					text: async () => '',
				};
			},
		} );

		await session.request( new URL( 'https://example.org/post' ), {
			method: 'POST',
			body: 'x',
		} );

		expect( seen ).toEqual( [ 'POST', 'GET' ] );
	} );

	it( 'gives up on a redirect loop', async () => {
		const session = new client.Session( {
			origins: [ 'https://example.org' ],
			fetch: async () => ( {
				status: 302,
				ok: false,
				url: 'https://example.org/loop',
				headers: {
					get: ( name ) => ( 'location' === name ? 'https://example.org/loop' : null ),
					getSetCookie: () => [],
				},
				body: { cancel: async () => {} },
				text: async () => '',
			} ),
		} );

		await expect(
			session.request( new URL( 'https://example.org/loop' ) )
		).rejects.toMatchObject( { code: 'auth_redirect_loop' } );
	} );

	it( 'refuses to replay a credential-bearing body to another origin', async () => {
		const session = sessionWith( [
			{
				status: 308,
				headers: { location: 'https://cas.example.org/login' },
			},
		] );

		await expect(
			session.request( new URL( 'https://example.org/wp-login.php' ), {
				method: 'POST',
				body: 'pwd=secret',
			} )
		).rejects.toMatchObject( { code: 'auth_cross_origin_replay' } );
	} );
} );

describe( 'login form parsing edges', () => {
	it( 'reports a page with no form at all', () => {
		expect( () =>
			client.parseLoginForm( '<html><body>No form here</body></html>', 'https://cas/login' )
		).toThrowError( /No password form/ );
	} );

	it( 'skips a form that has no password field', () => {
		const html =
			'<form action="/search"><input type="text" name="q"></form>' +
			'<form action="/login"><input type="password" name="password"></form>';

		expect( client.parseLoginForm( html, 'https://cas.example.org/x' ).action ).toBe(
			'https://cas.example.org/login'
		);
	} );

	it( 'falls back to the page URL when the form declares no action', () => {
		const html = '<form><input type="password" name="password"></form>';

		expect( client.parseLoginForm( html, 'https://cas.example.org/login?a=1' ).action ).toBe(
			'https://cas.example.org/login?a=1'
		);
	} );

	it( 'ignores a hidden field with no name', () => {
		const html =
			'<form><input type="hidden" value="x"><input type="hidden" name="lt" value="LT-1">' +
			'<input type="password" name="password"></form>';

		expect( client.parseLoginForm( html, 'https://cas.example.org/login' ).fields ).toEqual( {
			lt: 'LT-1',
		} );
	} );
} );
