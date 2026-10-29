/**
 * Decker REST client against a real CAS server and a real WordPress.
 *
 * Opt-in: the whole suite is skipped unless DECKER_CAS_LIVE=1. Run it with
 * `make test-cas`, which installs and configures WP Cassify in the wp-env
 * tests environment and removes it again afterwards.
 *
 * What is real here: an Apereo CAS server issuing a genuine service ticket, WP
 * Cassify validating that ticket over HTTPS, WordPress establishing a real
 * session, core's rest-nonce action, and Decker's abilities answering over
 * REST. The deterministic simulator in decker-client-auth.test.js covers the
 * failure paths; this one proves the happy path is real.
 *
 * Nothing secret is involved. The CAS server is the public Apereo/pac4j demo,
 * whose FakeAuthenticationHandler accepts any credentials, so the password is
 * the literal string "password" and the account is disposable. Passing here
 * does not certify compatibility with any particular institutional CAS, and
 * especially not with one that requires MFA or a CAPTCHA.
 *
 * @vitest-environment node
 */

import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import * as client from '../../skills/decker/scripts/decker-client.mjs';

const live = '1' === process.env.DECKER_CAS_LIVE;
const site = process.env.DECKER_CAS_SITE ?? 'http://localhost:8889';
const casUrl = process.env.DECKER_CAS_URL ?? 'https://www.casserverpac4j.dev/login';
const username = process.env.DECKER_CAS_USER ?? 'deckertest';

let configDir;

/**
 * Write a description change file for the update command.
 *
 * @return {string} Path to the file.
 */
function descriptionFile() {
	const path = join( configDir, 'description.json' );
	writeFileSync( path, JSON.stringify( { description: 'Written by the live test' } ) );

	return path;
}

beforeEach( () => {
	configDir = mkdtempSync( join( tmpdir(), 'decker-cas-live-' ) );
	process.env.DECKER_CONFIG_DIR = configDir;
	// Never touch the developer's real keychain from a test run.
	process.env.DECKER_SESSION_STORE = 'file';
} );

afterEach( () => {
	rmSync( configDir, { recursive: true, force: true } );
	delete process.env.DECKER_CONFIG_DIR;
	delete process.env.DECKER_SESSION_STORE;
} );

describe.skipIf( ! live )( 'live CAS authentication', () => {
	/**
	 * Log in through the real CAS server.
	 *
	 * @return {Promise<object>} Login result.
	 */
	function login() {
		return client.authLogin(
			{
				site,
				cas: casUrl,
				user: username,
				// The wp-env tests site is served over plain HTTP on loopback.
				'allow-insecure-http': true,
				timeoutMs: 30000,
			},
			{ readPassword: () => 'password' }
		);
	}

	it( 'completes the CAS flow and establishes a WordPress session', async () => {
		const result = await login();

		expect( result ).toMatchObject( { ok: true, authenticated: true, user: username } );
		expect( result.nonce_acquired ).toBe( true );
		// Pretty permalinks are enabled by setup-tests-env, so the primary
		// routing must be the one that was detected.
		expect( result.routing ).toBe( 'wp-json' );
	}, 60000 );

	it( 'keeps the credential out of the result and of the stored session', async () => {
		const result = await login();
		const stored = client.sessionStore.read();

		expect( JSON.stringify( result ) ).not.toContain( 'password' );
		expect( JSON.stringify( stored ) ).not.toContain( 'password' );
		// Only cookies are persisted, never the CAS password or a nonce.
		expect( Object.keys( stored ) ).toEqual( [ 'cookies' ] );
	}, 60000 );

	it( 'reports the session as valid afterwards', async () => {
		await login();

		await expect( client.authStatus() ).resolves.toMatchObject( {
			authenticated: true,
			user: username,
		} );
	}, 60000 );

	it( 'reaches Decker abilities over REST as the CAS user', async () => {
		await login();

		const report = await client.doctor();

		expect( report.checks.session ).toBe( 'valid' );
		expect( report.checks.abilities ).toContain( 'decker/list-tasks' );
		expect( report.ok ).toBe( true );
	}, 60000 );

	it( 'runs a read-only ability as that user', async () => {
		await login();
		const context = await client.createContext();

		const boards = await client.restRequest(
			context,
			'GET',
			'/wp-abilities/v1/abilities/decker/list-boards/run'
		);

		// list-boards returns { boards: [...] }; an empty site has no boards,
		// so the shape is what matters, not the contents.
		expect( boards ).toHaveProperty( 'boards' );
		expect( Array.isArray( boards.boards ) ).toBe( true );
	}, 60000 );

	it( 'runs the read commands against the real stack', async () => {
		await login();

		const boards = await client.boardsList();
		expect( boards.ok ).toBe( true );
		expect( Array.isArray( boards.boards ) ).toBe( true );

		const labels = await client.labelsList( {} );
		expect( labels.ok ).toBe( true );
		expect( Array.isArray( labels.labels ) ).toBe( true );
		// The taxonomy route reports its totals in headers; a proxy that strips
		// them would leave null here, which is worth knowing.
		expect( labels.total ).not.toBeNull();

		const tasks = await client.tasksList( { mine: true } );
		expect( tasks.ok ).toBe( true );
		expect( Array.isArray( tasks.tasks ) ).toBe( true );
		expect( tasks ).toMatchObject( { page: 1, per_page: 20 } );
	}, 60000 );

	it( 'resolves the CAS user through the users route', async () => {
		await login();

		// This is the route the deployment is known to answer with an HTML error
		// page in production. Here it must work, so a failure is a real finding.
		const found = await client.usersSearch( username, {} );

		expect( found.ok ).toBe( true );
		expect( found.users.some( ( user ) => user.slug === username ) ).toBe( true );
	}, 60000 );

	it( 'creates, labels, assigns and updates a task, verifying each step', async () => {
		await login();

		const boards = await client.boardsList();
		const board = boards.boards.find( ( entry ) => 'CAS Live Board' === entry.name );
		expect( board, 'make test-cas must have created the board' ).toBeDefined();

		const labels = await client.labelsList( { 'per-page': '100' } );
		const alpha = labels.labels.find( ( entry ) => 'CAS Live Alpha' === entry.name );
		const beta = labels.labels.find( ( entry ) => 'CAS Live Beta' === entry.name );
		expect( alpha ).toBeDefined();
		expect( beta ).toBeDefined();

		const me = await client.usersSearch( username, {} );
		const myId = me.users.find( ( user ) => user.slug === username ).id;

		// A write without --yes must not create anything.
		await expect(
			client.tasksCreate( { title: 'Unconfirmed task', board: String( board.id ) } )
		).rejects.toMatchObject( { code: 'usage_confirmation_required' } );

		const created = await client.tasksCreate( {
			title: 'CAS live task',
			board: String( board.id ),
			yes: true,
		} );
		expect( created.created ).toBe( true );
		const taskId = created.task.id;

		// Read it back through a fresh request, not from the create response.
		const readBack = await client.tasksShow( taskId );
		expect( readBack.task ).toMatchObject( {
			title: 'CAS live task',
			board_id: board.id,
			stack: 'to-do',
		} );

		// Two labels, then a third operation that must preserve them.
		await client.tasksLabelChange( taskId, { label: String( alpha.id ), yes: true }, true );
		await client.tasksLabelChange( taskId, { label: String( beta.id ), yes: true }, true );

		const withLabels = await client.tasksLabels( taskId );
		expect( withLabels.labels.map( ( entry ) => entry.id ).sort() ).toEqual(
			[ alpha.id, beta.id ].sort()
		);

		// Assigning must not disturb the labels.
		await client.tasksAssign( taskId, { user: String( myId ), yes: true }, true );
		const afterAssign = await client.tasksShow( taskId );
		expect( afterAssign.task.assignee_ids ).toContain( myId );
		expect( afterAssign.task.label_ids.sort() ).toEqual( [ alpha.id, beta.id ].sort() );

		// Removing one label must leave the other.
		await client.tasksLabelChange( taskId, { label: String( beta.id ), yes: true }, false );
		const afterRemoval = await client.tasksShow( taskId );
		expect( afterRemoval.task.label_ids ).toEqual( [ alpha.id ] );
		// ...and must not have dropped the assignee.
		expect( afterRemoval.task.assignee_ids ).toContain( myId );

		// A description change must preserve labels and assignees.
		await client.tasksUpdate( taskId, { 'json-file': descriptionFile(), yes: true } );
		const afterDescription = await client.tasksShow( taskId );
		expect( afterDescription.task.description ).toContain( 'Written by the live test' );
		expect( afterDescription.task.label_ids ).toEqual( [ alpha.id ] );
		expect( afterDescription.task.assignee_ids ).toContain( myId );

		// A no-op is reported, not written.
		const noop = await client.tasksUpdate( taskId, { title: 'CAS live task', yes: true } );
		expect( noop ).toMatchObject( { changed: false, reason: 'no_change' } );

		// The stale-write guard is NOT asserted here. post_modified_gmt has
		// one-second granularity and this whole sequence runs inside a second
		// or two, so a conflict cannot be produced reliably from outside the
		// server. DeckerAbilitiesStaleWriteTest covers it deterministically by
		// pushing the stored timestamp forward.
		const final = await client.tasksShow( taskId );
		expect( final.task.title ).toBe( 'CAS live task' );
	}, 120000 );

	it( 'logs out and then reports no session', async () => {
		await login();

		client.authLogout();

		await expect( client.authStatus() ).resolves.toMatchObject( { authenticated: false } );
	}, 60000 );
} );
