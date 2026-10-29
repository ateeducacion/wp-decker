#!/usr/bin/env node
/**
 * Decker REST client.
 *
 * A dependency-free client for Decker's WordPress REST and Abilities API
 * endpoints, authenticating through CAS with an ordinary WordPress session.
 * It runs on Node 22+ with built-ins only, so an installed Agent Skill needs
 * no `npm install` and carries no dependency tree.
 *
 * Secrets never reach stdout: the password is read from the terminal with echo
 * disabled, the session cookies live in the OS keychain, and no error message
 * or log line carries a cookie, a CAS ticket or a REST nonce.
 *
 * Phase 3 scope: transport, CAS authentication, session storage, nonce
 * bootstrap, route detection and `doctor`. Resource commands arrive in the
 * later phases.
 */

import { execFileSync } from 'node:child_process';
import { closeSync, mkdirSync, openSync, readFileSync, readSync, rmSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

/**
 * Stable process exit codes. Documented in references/troubleshooting.md; an
 * agent and a shell script both branch on these, so the numbers are a contract.
 */
export const EXIT = {
	OK: 0,
	UNKNOWN: 1,
	USAGE: 2,
	AUTH: 3,
	FORBIDDEN: 4,
	NOT_FOUND: 5,
	CONFLICT: 6,
	RATE_LIMITED: 7,
	SERVER: 8,
	NETWORK: 9,
	UNCERTAIN: 10,
};

/** Maximum redirects followed in one logical request. */
const MAX_REDIRECTS = 10;

/** A WordPress REST nonce as wp_create_nonce() renders it. */
const NONCE_PATTERN = /^[A-Za-z0-9]{10}$/;

/**
 * An error carrying a stable code and exit status, safe to print.
 *
 * The message is written by this module and never interpolates a response
 * body, a header or a credential, so a structured error can be shown to a
 * language model without leaking private task content.
 */
export class DeckerError extends Error {
	/**
	 * @param {string} code    Stable error code.
	 * @param {string} message Human-readable, secret-free message.
	 * @param {number} exit    Process exit code.
	 * @param {number} [status] HTTP status, when one applies.
	 */
	constructor( code, message, exit = EXIT.UNKNOWN, status = 0 ) {
		super( message );
		this.name = 'DeckerError';
		this.code = code;
		this.exit = exit;
		this.status = status;
	}

	/**
	 * Render the error as the CLI's stderr JSON payload.
	 *
	 * @return {object} Serializable error.
	 */
	toJSON() {
		const payload = { ok: false, code: this.code, message: this.message };
		if ( this.status ) {
			payload.status = this.status;
		}
		return payload;
	}
}

/**
 * Parse and check a URL before it is ever requested.
 *
 * Rejects embedded credentials, fragments and — unless explicitly allowed for
 * local testing — plain HTTP, so TLS is never silently dropped.
 *
 * @param {string}  value           URL to check.
 * @param {boolean} [allowHttp]     Whether to permit http: (local tests only).
 * @return {URL} The parsed URL.
 */
export function checkedUrl( value, allowHttp = false ) {
	let url;
	try {
		url = new URL( value );
	} catch {
		throw new DeckerError( 'config_invalid_url', 'Invalid URL.', EXIT.USAGE );
	}

	const allowed = allowHttp ? [ 'https:', 'http:' ] : [ 'https:' ];
	if ( url.username || url.password || url.hash || ! allowed.includes( url.protocol ) ) {
		throw new DeckerError(
			'config_invalid_url',
			'Use an HTTPS URL without credentials or a fragment.',
			EXIT.USAGE
		);
	}

	return url;
}

/**
 * An HTTP session with a cookie jar and an origin allowlist.
 *
 * Redirects are followed by hand so that each hop can be checked: a hop to an
 * unconfigured origin is refused, credential headers are stripped when the
 * origin changes, and a 307/308 never replays a password body to another host.
 *
 * ponytail: the jar keys cookies by origin and ignores Domain and Path. Decker
 * talks to one WordPress host and one CAS host, so per-origin is enough; swap
 * in a real jar if a deployment ever spreads cookies across subdomains.
 */
export class Session {
	/**
	 * @param {object}   options             Session options.
	 * @param {string[]} options.origins     Allowed origins.
	 * @param {number}   [options.timeoutMs] Per-request timeout.
	 * @param {boolean}  [options.allowHttp] Whether http: is permitted.
	 * @param {Function} [options.fetch]     Fetch implementation.
	 */
	constructor( { origins, timeoutMs = 15000, allowHttp = false, fetch: fetchImpl = globalThis.fetch } ) {
		this.origins = new Set( origins );
		this.timeoutMs = timeoutMs;
		this.allowHttp = allowHttp;
		this.fetch = fetchImpl;
		/** @type {Map<string, Map<string, string>>} */
		this.cookies = new Map();
	}

	/**
	 * Store the cookies a response set.
	 *
	 * @param {string}   origin  Origin that served the response.
	 * @param {Response} response Response to read.
	 * @return {void}
	 */
	storeCookies( origin, response ) {
		const jar = this.cookies.get( origin ) ?? new Map();

		for ( const header of response.headers.getSetCookie() ) {
			const [ pair ] = header.split( ';' );
			const index = pair.indexOf( '=' );
			if ( index < 1 ) {
				continue;
			}
			const name = pair.slice( 0, index ).trim();
			const value = pair.slice( index + 1 ).trim();
			if ( '' === value || 'deleted' === value ) {
				jar.delete( name );
			} else {
				jar.set( name, value );
			}
		}

		this.cookies.set( origin, jar );
	}

	/**
	 * Render the Cookie header for an origin.
	 *
	 * @param {string} origin Origin.
	 * @return {string} Cookie header value, empty when there is none.
	 */
	cookieHeader( origin ) {
		const jar = this.cookies.get( origin );
		if ( ! jar || 0 === jar.size ) {
			return '';
		}

		return [ ...jar ].map( ( [ name, value ] ) => `${ name }=${ value }` ).join( '; ' );
	}

	/**
	 * Export the WordPress session cookies for secure storage.
	 *
	 * @param {string} origin WordPress origin.
	 * @return {object} Serializable cookie map.
	 */
	exportCookies( origin ) {
		return Object.fromEntries( this.cookies.get( origin ) ?? [] );
	}

	/**
	 * Restore previously stored cookies.
	 *
	 * @param {string} origin  WordPress origin.
	 * @param {object} cookies Cookie map.
	 * @return {void}
	 */
	importCookies( origin, cookies ) {
		this.cookies.set( origin, new Map( Object.entries( cookies ?? {} ) ) );
	}

	/**
	 * Perform a request, following redirects under the session's rules.
	 *
	 * @param {URL}     input          Target URL.
	 * @param {object}  [init]         Fetch init.
	 * @param {boolean} [followRedirects] Whether to follow redirects.
	 * @return {Promise<Response>} The final response.
	 */
	async request( input, init = {}, followRedirects = true ) {
		let url = input;
		let method = init.method ?? 'GET';
		let body = init.body ?? null;
		const headers = new Headers( init.headers );

		for ( let hop = 0; hop <= MAX_REDIRECTS; hop++ ) {
			checkedUrl( url.href, this.allowHttp );

			if ( ! this.origins.has( url.origin ) ) {
				throw new DeckerError(
					'auth_untrusted_redirect',
					'Refused a redirect to an origin that is not the configured WordPress or CAS host.',
					EXIT.AUTH
				);
			}

			const cookie = this.cookieHeader( url.origin );
			headers.delete( 'cookie' );
			if ( cookie ) {
				headers.set( 'cookie', cookie );
			}

			let response;
			try {
				response = await this.fetch( url, {
					method,
					body,
					headers,
					redirect: 'manual',
					signal: AbortSignal.timeout( this.timeoutMs ),
				} );
			} catch {
				// The cause is deliberately dropped: a fetch error can quote the
				// request, and the request carries the session cookie.
				throw new DeckerError(
					'network_failed',
					'The request failed or timed out.',
					EXIT.NETWORK
				);
			}

			this.storeCookies( url.origin, response );

			if ( ! [ 301, 302, 303, 307, 308 ].includes( response.status ) ) {
				return response;
			}

			const location = response.headers.get( 'location' );
			if ( ! followRedirects || ! location ) {
				await response.body?.cancel();
				throw new DeckerError(
					'auth_unexpected_redirect',
					'The server redirected unexpectedly; the session may have expired.',
					EXIT.AUTH,
					response.status
				);
			}

			const next = new URL( location, url );

			if ( next.origin !== url.origin ) {
				if ( [ 307, 308 ].includes( response.status ) && 'GET' !== method && 'HEAD' !== method ) {
					await response.body?.cancel();
					throw new DeckerError(
						'auth_cross_origin_replay',
						'Refused to replay a credential-bearing request to another origin.',
						EXIT.AUTH
					);
				}
				headers.delete( 'authorization' );
				headers.delete( 'x-wp-nonce' );
			}

			if ( 303 === response.status || ( [ 301, 302 ].includes( response.status ) && 'GET' !== method && 'HEAD' !== method ) ) {
				method = 'GET';
				body = null;
				headers.delete( 'content-type' );
			}

			await response.body?.cancel();
			url = next;
		}

		throw new DeckerError(
			'auth_redirect_loop',
			'Too many redirects while authenticating.',
			EXIT.AUTH
		);
	}
}

/**
 * Find the login form that carries a password field and read its fields.
 *
 * ponytail: the form is located with regular expressions rather than a parser,
 * which keeps the client dependency-free. It handles the conventional CAS and
 * wp-login.php markup; a form built by JavaScript, or one with no password
 * input, is reported as unsupported rather than guessed at. Install a real HTML
 * parser if a deployment needs more.
 *
 * @param {string} html    Page HTML.
 * @param {string} pageUrl URL the HTML came from.
 * @return {{action: string, fields: object}} Form action and hidden fields.
 */
export function parseLoginForm( html, pageUrl ) {
	const forms = html.match( /<form\b[\s\S]*?<\/form>/gi ) ?? [];
	const form = forms.find( ( candidate ) => /<input\b[^>]*type\s*=\s*["']?password["']?/i.test( candidate ) );

	if ( ! form ) {
		throw new DeckerError(
			'auth_form_unsupported',
			'No password form was found. The deployment may require interactive login, MFA or a CAPTCHA, which this client deliberately does not attempt.',
			EXIT.AUTH
		);
	}

	const actionMatch = /<form\b[^>]*\baction\s*=\s*["']([^"']*)["']/i.exec( form );
	const action = new URL( actionMatch?.[ 1 ] || pageUrl, pageUrl ).href;

	const fields = {};
	for ( const input of form.match( /<input\b[^>]*>/gi ) ?? [] ) {
		if ( ! /type\s*=\s*["']?hidden["']?/i.test( input ) || /\bdisabled\b/i.test( input ) ) {
			continue;
		}
		const name = /\bname\s*=\s*["']([^"']*)["']/i.exec( input )?.[ 1 ];
		if ( ! name ) {
			continue;
		}
		const value = /\bvalue\s*=\s*["']([^"']*)["']/i.exec( input )?.[ 1 ] ?? '';
		// Hidden CAS state such as `execution` and `lt` must be sent back as-is.
		fields[ name ] = decodeHtmlEntities( value );
	}

	return { action, fields };
}

/**
 * Decode the few HTML entities that appear in form values.
 *
 * @param {string} value Raw attribute value.
 * @return {string} Decoded value.
 */
function decodeHtmlEntities( value ) {
	return value
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&quot;/g, '"' )
		.replace( /&#0?39;/g, "'" )
		.replace( /&amp;/g, '&' );
}

/**
 * Authenticate against CAS and establish a WordPress session.
 *
 * The flow starts at a WordPress URL so the CAS callback lands inside the
 * cookie path WordPress expects, follows the redirect to CAS, submits the
 * login form with its hidden state preserved, and follows the service ticket
 * back. WordPress validates the ticket; this client is an HTTP form client,
 * not a CAS server.
 *
 * @param {Session} session        Session to authenticate.
 * @param {object}  options        Login options.
 * @param {URL}     options.entryUrl  WordPress entry point.
 * @param {URL}     options.casUrl    CAS login URL.
 * @param {string}  options.username  Username.
 * @param {string}  options.password  Password.
 * @return {Promise<void>} Resolves once WordPress accepted the ticket.
 */
export async function casLogin( session, { entryUrl, casUrl, username, password } ) {
	const landing = await session.request( entryUrl );

	if ( ! landing.ok ) {
		await landing.body?.cancel();
		throw new DeckerError(
			'auth_entry_failed',
			'The WordPress entry point did not load.',
			EXIT.AUTH,
			landing.status
		);
	}

	const landingUrl = new URL( landing.url );
	if ( landingUrl.origin !== casUrl.origin ) {
		await landing.body?.cancel();
		throw new DeckerError(
			'auth_cas_not_reached',
			'WordPress did not redirect to the configured CAS host. Check the CAS login URL, or whether the session is already valid.',
			EXIT.AUTH
		);
	}

	const { action, fields } = parseLoginForm( await landing.text(), landing.url );
	const actionUrl = checkedUrl( action, session.allowHttp );

	if ( actionUrl.origin !== casUrl.origin ) {
		throw new DeckerError(
			'auth_cross_origin_form',
			'Refused to submit the login form to an origin other than the configured CAS host.',
			EXIT.AUTH
		);
	}

	const body = new URLSearchParams( fields );
	body.set( 'username', username );
	body.set( 'password', password );
	body.set( '_eventId', 'submit' );

	const submitted = await session.request( actionUrl, { method: 'POST', body } );
	const ok = submitted.ok;
	const status = submitted.status;
	await submitted.body?.cancel();

	if ( ! ok ) {
		throw new DeckerError(
			'auth_login_rejected',
			'CAS rejected the login. Check the username and password, or whether the account requires an interactive challenge.',
			EXIT.AUTH,
			status
		);
	}
}

/**
 * Fetch a REST nonce for the current session.
 *
 * Core's `rest-nonce` AJAX action is used rather than scraping a plugin admin
 * page: it is authenticated, it exists on every WordPress, and it needs no
 * capability beyond being logged in, so an ordinary Decker user does not have
 * to be able to load any particular administration screen.
 *
 * @param {Session} session  Authenticated session.
 * @param {URL}     adminAjaxUrl Site admin-ajax.php URL.
 * @return {Promise<string>} The nonce.
 */
export async function fetchNonce( session, adminAjaxUrl ) {
	const url = new URL( adminAjaxUrl );
	url.searchParams.set( 'action', 'rest-nonce' );

	const response = await session.request( url );
	const status = response.status;
	const text = ( await response.text() ).trim();

	if ( ! NONCE_PATTERN.test( text ) ) {
		// "0", "-1" and a login page all mean the same thing: no session.
		throw new DeckerError(
			'auth_session_expired',
			'No valid session. Run `decker auth login`.',
			EXIT.AUTH,
			status
		);
	}

	return text;
}

/**
 * Detect which REST routing the site serves.
 *
 * Pretty permalinks under `/wp-json/` are preferred; the documented
 * `?rest_route=` form is the fallback for a site whose permalinks or proxy do
 * not expose `/wp-json/`. Both are WordPress's own routing: neither bypasses
 * an access control.
 *
 * @param {Session} session Session to probe with.
 * @param {URL}     baseUrl Site base URL.
 * @return {Promise<'wp-json'|'rest-route'>} Detected routing.
 */
export async function detectRouting( session, baseUrl ) {
	for ( const routing of [ 'wp-json', 'rest-route' ] ) {
		const url = restUrl( baseUrl, routing, '/' );
		let response;
		try {
			response = await session.request( url );
		} catch {
			continue;
		}

		const type = response.headers.get( 'content-type' ) ?? '';
		const ok = response.ok && type.includes( 'json' );
		await response.body?.cancel();

		if ( ok ) {
			return routing;
		}
	}

	throw new DeckerError(
		'rest_unavailable',
		'The WordPress REST API did not answer with JSON through either /wp-json/ or ?rest_route=. Check the site URL and whether a proxy blocks the REST API.',
		EXIT.NETWORK
	);
}

/**
 * Build a REST URL for the configured routing.
 *
 * @param {URL}    baseUrl Site base URL.
 * @param {string} routing Detected routing.
 * @param {string} path    REST path, starting with a slash.
 * @param {object} [params] Query parameters, nested objects flattened to
 *                          bracket syntax.
 * @return {URL} Request URL.
 */
export function restUrl( baseUrl, routing, path, params = {} ) {
	const basePath = baseUrl.pathname.endsWith( '/' ) ? baseUrl.pathname : `${ baseUrl.pathname }/`;
	const url = new URL( baseUrl );

	if ( 'rest-route' === routing ) {
		url.pathname = basePath;
		url.searchParams.set( 'rest_route', path );
	} else {
		url.pathname = `${ basePath }wp-json${ path }`;
	}

	for ( const [ key, value ] of Object.entries( flattenParams( params ) ) ) {
		url.searchParams.set( key, value );
	}

	return url;
}

/**
 * Flatten nested parameters into PHP's bracket syntax.
 *
 * The Abilities run route reads GET input from the `input` query parameter, and
 * nothing in WordPress JSON-decodes a string there, so `input[task_id]=12` is
 * the form that validates. WordPress 6.9 — the deployed baseline — also applies
 * no type coercion, so booleans are sent as `1`/`0` rather than `true`/`false`,
 * which a string cast would read as true.
 *
 * @param {object} params Parameters.
 * @param {string} [prefix] Current key prefix.
 * @return {object} Flat key/value pairs.
 */
export function flattenParams( params, prefix = '' ) {
	const flat = {};

	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( undefined === value || null === value ) {
			continue;
		}

		const name = prefix ? `${ prefix }[${ key }]` : key;

		if ( 'boolean' === typeof value ) {
			flat[ name ] = value ? '1' : '0';
		} else if ( Array.isArray( value ) ) {
			value.forEach( ( item, index ) => {
				Object.assign( flat, flattenParams( { [ index ]: item }, name ) );
			} );
		} else if ( 'object' === typeof value ) {
			Object.assign( flat, flattenParams( value, name ) );
		} else {
			flat[ name ] = String( value );
		}
	}

	return flat;
}

/**
 * Map an HTTP status to a stable error.
 *
 * @param {number} status HTTP status.
 * @param {string} [code] Error code reported by WordPress.
 * @return {DeckerError} The mapped error.
 */
function errorForStatus( status, code = '' ) {
	const map = {
		400: [ 'request_invalid', 'The request was rejected as invalid.', EXIT.USAGE ],
		401: [ 'auth_session_expired', 'Not authenticated. Run `decker auth login`.', EXIT.AUTH ],
		403: [ 'forbidden', 'The server refused this operation for this user.', EXIT.FORBIDDEN ],
		404: [ 'not_found', 'The requested resource or route does not exist.', EXIT.NOT_FOUND ],
		405: [ 'method_not_allowed', 'That route does not accept this HTTP method.', EXIT.USAGE ],
		409: [ 'conflict', 'The task changed since it was read. Re-read it and try again; the write was not applied.', EXIT.CONFLICT ],
		429: [ 'rate_limited', 'The server is rate limiting requests. Retry later.', EXIT.RATE_LIMITED ],
	};

	const [ mapped, message, exit ] = map[ status ] ?? [
		status >= 500 ? 'server_error' : 'request_failed',
		status >= 500 ? 'The server reported an error.' : 'The request failed.',
		status >= 500 ? EXIT.SERVER : EXIT.UNKNOWN,
	];

	// WordPress's own code is more specific than the status, and is a fixed
	// identifier rather than content, so it is safe to surface.
	return new DeckerError( code || mapped, message, exit, status );
}

/**
 * Perform an authenticated REST request and decode the JSON response.
 *
 * @param {object} context         Client context.
 * @param {string} method          HTTP method.
 * @param {string} path            REST path.
 * @param {object} [options]       Request options.
 * @param {object} [options.params] Query parameters.
 * @param {object} [options.body]  JSON body.
 * @param {boolean} [options.withMeta] Return pagination metadata alongside the
 *                                     body, read from the X-WP-Total headers.
 * @return {Promise<any>} Decoded response, or {items, total, total_pages}.
 */
export async function restRequest( context, method, path, { params = {}, body = null, withMeta = false } = {} ) {
	const { session, baseUrl, routing, nonce } = context;
	const url = restUrl( baseUrl, routing, path, params );

	const headers = new Headers( { accept: 'application/json', 'x-wp-nonce': nonce } );
	let payload = null;
	if ( null !== body ) {
		headers.set( 'content-type', 'application/json' );
		payload = JSON.stringify( body );
	}

	// A write is never replayed: the server may have applied it before the
	// response was lost, so a retry could duplicate a task or a comment.
	const response = await session.request( url, { method, headers, body: payload } );
	const type = response.headers.get( 'content-type' ) ?? '';
	const text = await response.text();

	if ( ! type.includes( 'json' ) ) {
		const isWrite = ! [ 'GET', 'HEAD' ].includes( method );
		throw new DeckerError(
			'rest_not_json',
			`The server answered ${ response.status } with ${ type || 'no content type' } instead of JSON, which usually means a login page or a proxy error page.${ isWrite ? ' The outcome of this write is unknown: read the task before retrying.' : '' }`,
			isWrite ? EXIT.UNCERTAIN : EXIT.NETWORK,
			response.status
		);
	}

	let data;
	try {
		data = JSON.parse( text );
	} catch {
		throw new DeckerError(
			'rest_malformed_json',
			'The server returned malformed JSON.',
			EXIT.NETWORK,
			response.status
		);
	}

	if ( ! response.ok ) {
		throw errorForStatus( response.status, 'string' === typeof data?.code ? data.code : '' );
	}

	if ( ! withMeta ) {
		return data;
	}

	// WordPress reports collection totals in headers, not in the body. A proxy
	// that strips them leaves null rather than a guessed number.
	const total = response.headers.get( 'x-wp-total' );
	const totalPages = response.headers.get( 'x-wp-totalpages' );

	return {
		items: data,
		total: null === total ? null : Number( total ),
		total_pages: null === totalPages ? null : Number( totalPages ),
	};
}

/**
 * Build the REST path that runs an ability.
 *
 * @param {string} name Ability name, such as `decker/list-tasks`.
 * @return {string} REST path.
 */
export function abilityPath( name ) {
	return `/wp-abilities/v1/abilities/${ name }/run`;
}

/**
 * Read a positive integer flag.
 *
 * Input is validated before a request is sent, so a typo costs nothing and
 * reports what was wrong rather than a puzzling server error.
 *
 * @param {object} flags Parsed flags.
 * @param {string} name  Flag name.
 * @param {object} [bounds] Optional {min, max}.
 * @return {number|undefined} The value, or undefined when absent.
 */
export function integerFlag( flags, name, { min = 1, max = Number.MAX_SAFE_INTEGER } = {} ) {
	if ( ! ( name in flags ) || true === flags[ name ] ) {
		if ( true === flags[ name ] ) {
			throw new DeckerError( 'usage_missing_value', `--${ name } needs a value.`, EXIT.USAGE );
		}
		return undefined;
	}

	const value = Number( flags[ name ] );
	if ( ! Number.isInteger( value ) || value < min || value > max ) {
		throw new DeckerError(
			'usage_invalid_value',
			`--${ name } must be a whole number between ${ min } and ${ max }.`,
			EXIT.USAGE
		);
	}

	return value;
}

/**
 * Read a flag from a fixed set of values.
 *
 * @param {object}   flags   Parsed flags.
 * @param {string}   name    Flag name.
 * @param {string[]} allowed Allowed values.
 * @return {string|undefined} The value, or undefined when absent.
 */
export function enumFlag( flags, name, allowed ) {
	if ( ! ( name in flags ) ) {
		return undefined;
	}

	const value = String( flags[ name ] );
	if ( ! allowed.includes( value ) ) {
		throw new DeckerError(
			'usage_invalid_value',
			`--${ name } must be one of: ${ allowed.join( ', ' ) }.`,
			EXIT.USAGE
		);
	}

	return value;
}

/**
 * Resolve the configuration directory.
 *
 * @return {string} Directory path.
 */
function configDir() {
	return process.env.DECKER_CONFIG_DIR || join( process.env.XDG_CONFIG_HOME || join( homedir(), '.config' ), 'decker' );
}

/**
 * Read the stored configuration.
 *
 * @return {object} Configuration, empty when absent.
 */
export function loadConfig() {
	try {
		return JSON.parse( readFileSync( join( configDir(), 'config.json' ), 'utf8' ) );
	} catch {
		return {};
	}
}

/**
 * Write the configuration. It holds no secrets.
 *
 * @param {object} config Configuration to store.
 * @return {void}
 */
export function saveConfig( config ) {
	const path = join( configDir(), 'config.json' );
	mkdirSync( dirname( path ), { recursive: true } );
	writeFileSync( path, `${ JSON.stringify( config, null, '\t' ) }\n`, { mode: 0o600 } );
}

/**
 * Session-secret storage.
 *
 * macOS Keychain is used when available. Nothing is written in plaintext
 * silently: the file fallback is only used when DECKER_SESSION_STORE=file is
 * set explicitly, and it warns on stderr every time it stores a session.
 */
export const sessionStore = {
	/**
	 * Decide which backend to use.
	 *
	 * @return {'keychain'|'file'} Backend name.
	 */
	backend() {
		if ( process.env.DECKER_SESSION_STORE ) {
			return 'file' === process.env.DECKER_SESSION_STORE ? 'file' : 'keychain';
		}

		return 'darwin' === process.platform ? 'keychain' : 'file';
	},

	/**
	 * Read the stored session.
	 *
	 * @return {object|null} Stored session, or null when there is none.
	 */
	read() {
		if ( 'keychain' === this.backend() ) {
			try {
				const value = execFileSync(
					'security',
					[ 'find-generic-password', '-s', 'decker-client', '-a', 'session', '-w' ],
					{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
				).trim();
				return JSON.parse( value );
			} catch {
				return null;
			}
		}

		try {
			return JSON.parse( readFileSync( join( configDir(), 'session.json' ), 'utf8' ) );
		} catch {
			return null;
		}
	},

	/**
	 * Store the session.
	 *
	 * @param {object} session Session data (cookies only; no password).
	 * @return {void}
	 */
	write( session ) {
		const value = JSON.stringify( session );

		if ( 'keychain' === this.backend() ) {
			execFileSync(
				'security',
				[ 'add-generic-password', '-U', '-s', 'decker-client', '-a', 'session', '-w', value ],
				{ stdio: [ 'ignore', 'ignore', 'ignore' ] }
			);
			return;
		}

		const path = join( configDir(), 'session.json' );
		mkdirSync( dirname( path ), { recursive: true } );
		writeFileSync( path, value, { mode: 0o600 } );
		process.stderr.write(
			'Warning: the session cookies were stored in a file with 0600 permissions because no OS keychain is in use. Set DECKER_SESSION_STORE=keychain on a system with one.\n'
		);
	},

	/**
	 * Remove the stored session.
	 *
	 * @return {void}
	 */
	clear() {
		if ( 'keychain' === this.backend() ) {
			try {
				execFileSync(
					'security',
					[ 'delete-generic-password', '-s', 'decker-client', '-a', 'session' ],
					{ stdio: [ 'ignore', 'ignore', 'ignore' ] }
				);
			} catch {
				// Nothing stored; logging out is still a success.
			}
			return;
		}

		rmSync( join( configDir(), 'session.json' ), { force: true } );
	},
};

/**
 * Read a password from the terminal with echo disabled.
 *
 * The terminal is opened directly rather than read from stdin so that the
 * password never travels through a pipe an agent runtime could capture, and it
 * is never accepted as a command-line argument.
 *
 * @param {string} prompt Prompt to show.
 * @return {string} The password.
 */
export function readPasswordFromTty( prompt ) {
	let tty;
	try {
		tty = openSync( '/dev/tty', 'r+' );
	} catch {
		throw new DeckerError(
			'auth_no_tty',
			'A terminal is required to type the password. Run `decker auth login` yourself in a terminal; the password is never accepted as an argument or through a pipe.',
			EXIT.USAGE
		);
	}

	try {
		writeFileSync( tty, prompt );
		execFileSync( 'stty', [ '-echo' ], { stdio: [ tty, 'ignore', 'ignore' ] } );

		const buffer = Buffer.alloc( 1 );
		let password = '';
		for ( ;; ) {
			const read = readSync( tty, buffer, 0, 1, null );
			if ( 0 === read || '\n' === buffer.toString() || '\r' === buffer.toString() ) {
				break;
			}
			password += buffer.toString();
		}

		return password;
	} finally {
		try {
			execFileSync( 'stty', [ 'echo' ], { stdio: [ tty, 'ignore', 'ignore' ] } );
			writeFileSync( tty, '\n' );
		} finally {
			closeSync( tty );
		}
	}
}

/**
 * Build the request context for an authenticated command.
 *
 * @param {object} [options] Context options.
 * @param {Function} [options.fetch] Fetch implementation, for tests.
 * @return {Promise<object>} Context with an authenticated session and a nonce.
 */
export async function createContext( { fetch: fetchImpl } = {} ) {
	const config = loadConfig();

	if ( ! config.siteUrl ) {
		throw new DeckerError(
			'config_missing',
			'No site configured. Run `decker auth login --site <url> --cas <url>`.',
			EXIT.USAGE
		);
	}

	const allowHttp = Boolean( config.allowInsecureHttp );
	const baseUrl = checkedUrl( config.siteUrl, allowHttp );
	const origins = [ baseUrl.origin ];
	if ( config.casLoginUrl ) {
		origins.push( checkedUrl( config.casLoginUrl, allowHttp ).origin );
	}

	const session = new Session( {
		origins,
		allowHttp,
		timeoutMs: config.timeoutMs ?? 15000,
		fetch: fetchImpl,
	} );

	const stored = sessionStore.read();
	if ( ! stored?.cookies ) {
		throw new DeckerError(
			'auth_required',
			'No stored session. Run `decker auth login`.',
			EXIT.AUTH
		);
	}
	session.importCookies( baseUrl.origin, stored.cookies );

	const nonce = await fetchNonce( session, adminAjaxUrl( baseUrl ) );
	const routing = config.routing ?? ( await detectRouting( session, baseUrl ) );

	return { session, baseUrl, routing, nonce, config };
}

/**
 * Build the admin-ajax.php URL for a site.
 *
 * @param {URL} baseUrl Site base URL.
 * @return {URL} admin-ajax.php URL.
 */
export function adminAjaxUrl( baseUrl ) {
	const basePath = baseUrl.pathname.endsWith( '/' ) ? baseUrl.pathname : `${ baseUrl.pathname }/`;
	const url = new URL( baseUrl );
	url.pathname = `${ basePath }wp-admin/admin-ajax.php`;
	return url;
}

/**
 * Build the WordPress admin URL used as the CAS entry point.
 *
 * Starting at the admin URL keeps the CAS callback inside the path WordPress
 * sets its logged-in cookie for.
 *
 * @param {URL} baseUrl Site base URL.
 * @return {URL} Admin URL.
 */
export function adminUrl( baseUrl ) {
	const basePath = baseUrl.pathname.endsWith( '/' ) ? baseUrl.pathname : `${ baseUrl.pathname }/`;
	const url = new URL( baseUrl );
	url.pathname = `${ basePath }wp-admin/`;
	return url;
}

/**
 * Parse command-line flags.
 *
 * @param {string[]} argv Arguments after the command words.
 * @return {object} Flags and positional arguments.
 */
export function parseFlags( argv ) {
	const flags = {};
	const positional = [];

	for ( let index = 0; index < argv.length; index++ ) {
		const argument = argv[ index ];

		if ( ! argument.startsWith( '--' ) ) {
			positional.push( argument );
			continue;
		}

		const name = argument.slice( 2 );
		if ( name.includes( '=' ) ) {
			const [ key, ...rest ] = name.split( '=' );
			flags[ key ] = rest.join( '=' );
			continue;
		}

		const next = argv[ index + 1 ];
		if ( undefined === next || next.startsWith( '--' ) ) {
			flags[ name ] = true;
		} else {
			flags[ name ] = next;
			index++;
		}
	}

	if ( 'password' in flags ) {
		throw new DeckerError(
			'usage_password_argument',
			'A password is never accepted as a command-line argument. Run `decker auth login` and type it at the prompt.',
			EXIT.USAGE
		);
	}

	return { flags, positional };
}

/**
 * `decker auth login`.
 *
 * @param {object} flags            Parsed flags.
 * @param {object} [options]        Options.
 * @param {Function} [options.fetch] Fetch implementation.
 * @param {Function} [options.readPassword] Password reader, for tests.
 * @return {Promise<object>} Command result.
 */
export async function authLogin( flags, { fetch: fetchImpl, readPassword = readPasswordFromTty } = {} ) {
	const config = loadConfig();

	const siteUrl = flags.site ?? config.siteUrl;
	const casLoginUrl = flags.cas ?? config.casLoginUrl;
	const username = flags.user ?? config.username;

	if ( ! siteUrl || ! casLoginUrl || ! username ) {
		throw new DeckerError(
			'usage_missing_options',
			'Needs --site, --cas and --user the first time; they are then remembered.',
			EXIT.USAGE
		);
	}

	const allowHttp = Boolean( flags[ 'allow-insecure-http' ] ?? config.allowInsecureHttp );
	const baseUrl = checkedUrl( siteUrl, allowHttp );
	const casUrl = checkedUrl( casLoginUrl, allowHttp );

	const session = new Session( {
		origins: [ baseUrl.origin, casUrl.origin ],
		allowHttp,
		timeoutMs: config.timeoutMs ?? 15000,
		fetch: fetchImpl,
	} );

	const password = readPassword( `Password for ${ username }: ` );
	try {
		await casLogin( session, { entryUrl: adminUrl( baseUrl ), casUrl, username, password } );
	} finally {
		// The CAS password is never stored, and the only copy is dropped here.
	}

	const nonce = await fetchNonce( session, adminAjaxUrl( baseUrl ) );
	const routing = await detectRouting( session, baseUrl );

	sessionStore.write( { cookies: session.exportCookies( baseUrl.origin ) } );
	saveConfig( {
		...config,
		siteUrl: baseUrl.href,
		casLoginUrl: casUrl.href,
		username,
		routing,
		allowInsecureHttp: allowHttp,
	} );

	// The nonce is deliberately not reported: it is a credential, and a fresh
	// one is fetched per run.
	return {
		ok: true,
		authenticated: true,
		site: baseUrl.href,
		user: username,
		routing,
		session_storage: sessionStore.backend(),
		nonce_acquired: NONCE_PATTERN.test( nonce ),
	};
}

/**
 * `decker auth status`.
 *
 * @param {object} [options]        Options.
 * @param {Function} [options.fetch] Fetch implementation.
 * @return {Promise<object>} Command result.
 */
export async function authStatus( { fetch: fetchImpl } = {} ) {
	const config = loadConfig();
	const stored = sessionStore.read();

	if ( ! config.siteUrl || ! stored?.cookies ) {
		return {
			ok: true,
			authenticated: false,
			site: config.siteUrl ?? null,
			session_storage: sessionStore.backend(),
		};
	}

	const baseUrl = checkedUrl( config.siteUrl, Boolean( config.allowInsecureHttp ) );
	const session = new Session( {
		origins: [ baseUrl.origin ],
		allowHttp: Boolean( config.allowInsecureHttp ),
		timeoutMs: config.timeoutMs ?? 15000,
		fetch: fetchImpl,
	} );
	session.importCookies( baseUrl.origin, stored.cookies );

	try {
		await fetchNonce( session, adminAjaxUrl( baseUrl ) );
	} catch ( error ) {
		if ( error instanceof DeckerError && 'auth_session_expired' === error.code ) {
			return {
				ok: true,
				authenticated: false,
				site: baseUrl.href,
				user: config.username ?? null,
				reason: 'session_expired',
				session_storage: sessionStore.backend(),
			};
		}
		throw error;
	}

	return {
		ok: true,
		authenticated: true,
		site: baseUrl.href,
		user: config.username ?? null,
		routing: config.routing ?? null,
		session_storage: sessionStore.backend(),
	};
}

/**
 * `decker auth logout`.
 *
 * @return {object} Command result.
 */
export function authLogout() {
	sessionStore.clear();

	return { ok: true, authenticated: false, session_cleared: true };
}

/**
 * `decker doctor`.
 *
 * Reports what the client can and cannot reach, without ever printing a
 * credential or a response body.
 *
 * @param {object} [options]        Options.
 * @param {Function} [options.fetch] Fetch implementation.
 * @return {Promise<object>} Diagnostic report.
 */
export async function doctor( { fetch: fetchImpl } = {} ) {
	const config = loadConfig();
	const report = {
		ok: true,
		node: process.version,
		node_supported: 22 <= Number( process.versions.node.split( '.' )[ 0 ] ),
		site: config.siteUrl ?? null,
		cas: config.casLoginUrl ?? null,
		session_storage: sessionStore.backend(),
		session_stored: Boolean( sessionStore.read()?.cookies ),
		checks: {},
	};

	if ( ! config.siteUrl ) {
		report.ok = false;
		report.checks.config = 'missing: run `decker auth login --site <url> --cas <url> --user <name>`';
		return report;
	}

	const allowHttp = Boolean( config.allowInsecureHttp );
	const baseUrl = checkedUrl( config.siteUrl, allowHttp );
	const session = new Session( {
		origins: [ baseUrl.origin ],
		allowHttp,
		timeoutMs: config.timeoutMs ?? 15000,
		fetch: fetchImpl,
	} );

	const stored = sessionStore.read();
	if ( stored?.cookies ) {
		session.importCookies( baseUrl.origin, stored.cookies );
	}

	try {
		report.checks.routing = await detectRouting( session, baseUrl );
	} catch ( error ) {
		report.ok = false;
		report.checks.routing = error instanceof DeckerError ? error.code : 'failed';
		return report;
	}

	try {
		await fetchNonce( session, adminAjaxUrl( baseUrl ) );
		report.checks.session = 'valid';
	} catch ( error ) {
		report.ok = false;
		report.checks.session = error instanceof DeckerError ? error.code : 'failed';
		return report;
	}

	try {
		const context = { session, baseUrl, routing: report.checks.routing, nonce: await fetchNonce( session, adminAjaxUrl( baseUrl ) ) };
		const abilities = await restRequest( context, 'GET', '/wp-abilities/v1/abilities', { params: { per_page: 100 } } );
		const names = ( Array.isArray( abilities ) ? abilities : abilities?.abilities ?? [] )
			.map( ( ability ) => ability?.name )
			.filter( ( name ) => 'string' === typeof name && name.startsWith( 'decker/' ) );
		report.checks.abilities = names.length ? names : 'none exposed: update the Decker plugin';
		if ( ! names.length ) {
			report.ok = false;
		}
	} catch ( error ) {
		report.ok = false;
		report.checks.abilities = error instanceof DeckerError ? error.code : 'failed';
	}

	return report;
}

/**
 * `decker boards list`.
 *
 * @param {object} [options] Options.
 * @return {Promise<object>} Command result.
 */
export async function boardsList( options = {} ) {
	const context = await createContext( options );
	const result = await restRequest( context, 'GET', abilityPath( 'decker/list-boards' ) );

	return { ok: true, boards: result.boards ?? [] };
}

/**
 * `decker labels list`.
 *
 * Labels come from the taxonomy's own REST route: Decker has no list-labels
 * ability, and adding one for a plain taxonomy read would duplicate what
 * WordPress already exposes with the same permissions.
 *
 * @param {object} flags     Parsed flags.
 * @param {object} [options] Options.
 * @return {Promise<object>} Command result.
 */
export async function labelsList( flags, options = {} ) {
	const context = await createContext( options );
	const page = integerFlag( flags, 'page' ) ?? 1;
	const perPage = integerFlag( flags, 'per-page', { min: 1, max: 100 } ) ?? 100;

	const { items, total, total_pages: totalPages } = await restRequest( context, 'GET', '/wp/v2/labels', {
		params: {
			page,
			per_page: perPage,
			// Only the fields an agent needs to resolve a name to an id, so a
			// large taxonomy does not fill the model's context with term meta.
			_fields: 'id,name,slug,count',
		},
		withMeta: true,
	} );

	return { ok: true, labels: items, page, per_page: perPage, total, total_pages: totalPages };
}

/**
 * `decker users search QUERY`.
 *
 * @param {string} query     Search text.
 * @param {object} flags     Parsed flags.
 * @param {object} [options] Options.
 * @return {Promise<object>} Command result.
 */
export async function usersSearch( query, flags, options = {} ) {
	if ( ! query || ! String( query ).trim() ) {
		throw new DeckerError(
			'usage_missing_query',
			'Usage: decker users search <query>',
			EXIT.USAGE
		);
	}

	const context = await createContext( options );

	// Decker's own ability, not /wp/v2/users: `list_users` is an administrator
	// capability, so core answers an ordinary user with HTTP 200 and an empty
	// array, which reads as "no such person". The deployment's proxy also
	// answers some /wp/v2/users requests with an HTML error page.
	const result = await restRequest( context, 'GET', abilityPath( 'decker/list-users' ), {
		params: {
			input: {
				search: String( query ).trim(),
				page: integerFlag( flags, 'page' ) ?? 1,
				per_page: integerFlag( flags, 'per-page', { min: 1, max: 100 } ) ?? 50,
			},
		},
	} );

	return { ok: true, ...result };
}

/**
 * `decker tasks list`.
 *
 * Every filter is applied by the server, against the authenticated user. There
 * is no client-side filtering: it would be wrong over a paginated response and
 * it would pull tasks into the model's context only to discard them.
 *
 * @param {object} flags     Parsed flags.
 * @param {object} [options] Options.
 * @return {Promise<object>} Command result.
 */
export async function tasksList( flags, options = {} ) {
	const context = await createContext( options );

	const input = {
		page: integerFlag( flags, 'page' ) ?? 1,
		per_page: integerFlag( flags, 'per-page', { min: 1, max: 100 } ) ?? 20,
	};

	const board = integerFlag( flags, 'board' );
	if ( undefined !== board ) {
		input.board_id = board;
	}

	const stack = enumFlag( flags, 'stack', [ 'to-do', 'in-progress', 'done' ] );
	if ( undefined !== stack ) {
		input.stack = stack;
	}

	const status = enumFlag( flags, 'status', [ 'publish', 'archived' ] );
	if ( undefined !== status ) {
		input.status = status;
	}

	if ( 'search' in flags && true !== flags.search ) {
		input.search = String( flags.search );
	}

	// The relation flags are mutually exclusive: they are different questions,
	// and silently letting the last one win would answer the wrong one.
	const relations = {
		mine: 'any',
		'assigned-to-me': 'assigned',
		'responsible-by-me': 'responsible',
		'authored-by-me': 'author',
	};
	const requested = Object.keys( relations ).filter( ( flag ) => flags[ flag ] );
	if ( requested.length > 1 ) {
		throw new DeckerError(
			'usage_conflicting_flags',
			`Use only one of: ${ Object.keys( relations ).map( ( flag ) => `--${ flag }` ).join( ', ' ) }.`,
			EXIT.USAGE
		);
	}
	if ( requested.length === 1 ) {
		input.relation = relations[ requested[ 0 ] ];
	}

	if ( flags.today ) {
		input.today = true;
	}

	if ( flags[ 'include-hidden' ] ) {
		input.include_hidden = true;
	}

	const result = await restRequest( context, 'GET', abilityPath( 'decker/list-tasks' ), {
		params: { input },
	} );

	return { ok: true, ...result };
}

/**
 * `decker tasks show ID`.
 *
 * @param {string|number} id        Task ID.
 * @param {object}        [options] Options.
 * @return {Promise<object>} Command result.
 */
export async function tasksShow( id, options = {} ) {
	const taskId = Number( id );
	if ( ! Number.isInteger( taskId ) || taskId < 1 ) {
		throw new DeckerError( 'usage_invalid_task_id', 'Usage: decker tasks show <id>', EXIT.USAGE );
	}

	const context = await createContext( options );
	const task = await restRequest( context, 'GET', abilityPath( 'decker/get-task' ), {
		params: { input: { task_id: taskId } },
	} );

	return { ok: true, task };
}

/**
 * Dispatch a command line.
 *
 * @param {string[]} argv      Arguments without the node binary and script.
 * @param {object}   [options] Options passed to the commands.
 * @return {Promise<object>} Command result.
 */
export async function run( argv, options = {} ) {
	const [ command, subcommand, ...rest ] = argv;

	if ( 'auth' === command ) {
		const { flags } = parseFlags( rest );

		if ( 'login' === subcommand ) {
			return authLogin( flags, options );
		}
		if ( 'status' === subcommand ) {
			return authStatus( options );
		}
		if ( 'logout' === subcommand ) {
			return authLogout();
		}
	}

	if ( 'doctor' === command ) {
		return doctor( options );
	}

	if ( 'boards' === command && 'list' === subcommand ) {
		return boardsList( options );
	}

	if ( 'labels' === command && 'list' === subcommand ) {
		return labelsList( parseFlags( rest ).flags, options );
	}

	if ( 'users' === command && 'search' === subcommand ) {
		const { flags, positional } = parseFlags( rest );
		return usersSearch( positional.join( ' ' ), flags, options );
	}

	if ( 'tasks' === command ) {
		if ( 'list' === subcommand ) {
			return tasksList( parseFlags( rest ).flags, options );
		}
		if ( 'show' === subcommand ) {
			const { positional } = parseFlags( rest );
			return tasksShow( positional[ 0 ], options );
		}
	}

	throw new DeckerError(
		'usage_unknown_command',
		[
			'Usage:',
			'  decker doctor',
			'  decker auth <login|status|logout>',
			'  decker boards list',
			'  decker labels list [--page N] [--per-page N]',
			'  decker users search <query> [--page N] [--per-page N]',
			'  decker tasks list [--mine|--assigned-to-me|--responsible-by-me|--authored-by-me]',
			'                    [--today] [--board ID] [--stack to-do|in-progress|done]',
			'                    [--status publish|archived] [--search TEXT]',
			'                    [--include-hidden] [--page N] [--per-page N]',
			'  decker tasks show <id>',
		].join( '\n' ),
		EXIT.USAGE
	);
}

/**
 * CLI entry point. JSON on stdout, diagnostics on stderr, stable exit codes.
 *
 * @return {Promise<void>} Resolves once the process result is written.
 */
async function main() {
	try {
		const result = await run( process.argv.slice( 2 ) );
		process.stdout.write( `${ JSON.stringify( result, null, '\t' ) }\n` );
		process.exitCode = false === result.ok ? EXIT.UNKNOWN : EXIT.OK;
	} catch ( error ) {
		const wrapped = error instanceof DeckerError
			? error
			: new DeckerError( 'unknown_error', 'The command failed.', EXIT.UNKNOWN );
		process.stderr.write( `${ JSON.stringify( wrapped.toJSON(), null, '\t' ) }\n` );
		process.exitCode = wrapped.exit;
	}
}

if ( process.argv[ 1 ] && import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	await main();
}
