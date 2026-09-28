/**
 * Unit tests for decker-collaboration.js
 *
 * Tests the DeckerCollaboration IIFE by importing it in jsdom. Its esm.sh imports
 * (Yjs, y-webrtc, y-quill) are mocked with vi.mock and delegate to per-test
 * global mocks; Quill is a plain mock object.
 *
 * @package Decker
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

// The module imports its CDN dependencies from esm.sh. Each URL is mocked with
// a constructor that delegates to the per-test global mock set up in
// beforeEach, so tests keep asserting on global.Y.Doc, global.WebrtcProvider
// and global.QuillBinding.
vi.mock( 'https://esm.sh/yjs@13.6.20', () => ( {
	// eslint-disable-next-line object-shorthand -- a method is not constructible.
	Doc: function ( ...args ) {
		return new globalThis.Y.Doc( ...args );
	},
} ) );
vi.mock( 'https://esm.sh/y-webrtc@10.3.0?deps=yjs@13.6.20', () => ( {
	// eslint-disable-next-line object-shorthand -- a method is not constructible.
	WebrtcProvider: function ( ...args ) {
		return new globalThis.WebrtcProvider( ...args );
	},
} ) );
vi.mock( 'https://esm.sh/y-quill@1.0.0?deps=yjs@13.6.20', () => ( {
	// eslint-disable-next-line object-shorthand -- a method is not constructible.
	QuillBinding: function ( ...args ) {
		return new globalThis.QuillBinding( ...args );
	},
} ) );

/* eslint-disable no-undef */

// ── Helpers ──────────────────────────────────────────────────────────

/** Build a minimal mock Quill instance */
function createMockQuill() {
	return {
		clipboard: {
			convert: vi.fn( () => ( { ops: [ { insert: 'hello' } ] } ) ),
			dangerouslyPasteHTML: vi.fn(),
		},
		getText: vi.fn( () => 'content' ),
		setContents: vi.fn(),
		getModule: vi.fn( () => null ), // no cursors module by default
		on: vi.fn(),
		off: vi.fn(),
	};
}

/** Build a minimal mock Y.Text */
function createMockYText( initialLength = 0 ) {
	let length = initialLength;
	return {
		get length() {
			return length;
		},
		set length( v ) {
			length = v;
		},
		applyDelta: vi.fn( () => {
			length = 5;
		} ),
		toDelta: vi.fn( () => [ { insert: 'hello' } ] ),
		insert: vi.fn(),
		toString: vi.fn( () => 'content' ),
	};
}

/** Build a minimal mock Y.Map */
function createMockYMap() {
	const store = new Map();
	return {
		get: ( k ) => store.get( k ),
		set: ( k, v ) => store.set( k, v ),
		get size() {
			return store.size;
		},
		observe: vi.fn(),
	};
}

/** Build a minimal mock Y.Doc */
function createMockYDoc( ytext, ymap ) {
	return {
		getText: vi.fn( () => ytext ),
		getMap: vi.fn( () => ymap ),
		destroy: vi.fn(),
	};
}

/**
 * Build a mock WebrtcProvider.
 * Callers can fire events with provider._fire(eventName, payload).
 */
function createMockProvider() {
	const handlers = {};
	const awarenessStates = new Map();
	awarenessStates.set( 1, { user: { name: 'Me' } } ); // self

	const awareness = {
		clientID: 1,
		getStates: vi.fn( () => awarenessStates ),
		setLocalStateField: vi.fn(),
		on: vi.fn(),
		off: vi.fn(),
	};

	const provider = {
		awareness,
		signalingConns: [],
		connected: false,
		on: vi.fn( ( event, cb ) => {
			if ( ! handlers[ event ] ) {
				handlers[ event ] = [];
			}
			handlers[ event ].push( cb );
		} ),
		off: vi.fn(),
		connect: vi.fn(),
		disconnect: vi.fn(),
		destroy: vi.fn(),
		_fire( event, payload ) {
			( handlers[ event ] || [] ).forEach( ( cb ) => cb( payload ) );
		},
		_handlers: handlers,
		_awarenessStates: awarenessStates,
	};

	return provider;
}

// ── Test setup ──────────────────────────────────────────────────────

let mockQuill;
let mockYText;
let mockYMap;
let mockYDoc;
let mockProvider;
let mockBinding;

beforeEach( () => {
	vi.useFakeTimers();

	mockYText = createMockYText( 0 );
	mockYMap = createMockYMap();
	mockYDoc = createMockYDoc( mockYText, mockYMap );
	mockProvider = createMockProvider();
	mockQuill = createMockQuill();
	mockBinding = { destroy: vi.fn() };

	// Global mock classes. The module calls them with `new`, which a mock
	// only supports with a `function` implementation, not an arrow.
	global.Y = {
		Doc: vi.fn( function () {
			return mockYDoc;
		} ),
	};
	global.WebrtcProvider = vi.fn( function () {
		return mockProvider;
	} );
	global.QuillBinding = vi.fn( function () {
		return mockBinding;
	} );

	// WebSocket constant needed by isSignalingConnected()
	global.WebSocket = { OPEN: 1 };

	// WordPress configuration
	global.window.deckerCollabConfig = {
		enabled: true,
		signalingServer: 'wss://test.example.com',
		roomPrefix: 'test-room-',
		userName: 'TestUser',
		userColor: '#FF0000',
		userId: 42,
		userAvatar: null,
	};

	// Provide a container with needed DOM structure
	document.body.innerHTML = `
		<div id="test-container">
			<div id="editor-container"><div id="editor"></div></div>
		</div>
	`;
} );

afterEach( () => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	delete global.Y;
	delete global.WebrtcProvider;
	delete global.QuillBinding;
	delete global.WebSocket;
	delete global.window.DeckerCollaboration;
	delete global.window.deckerCollabConfig;
	document.body.innerHTML = '';
} );

/**
 * Evaluate the collaboration module afresh, with its CDN imports mocked.
 */
async function loadModule() {
	vi.resetModules();
	await import( '../../public/assets/js/decker-collaboration.js' );
}

/** Helper: init a session and return it */
function initSession( quill = mockQuill ) {
	const container = document.getElementById( 'test-container' );
	return window.DeckerCollaboration.init( quill, '123', container );
}

// ── Tests ────────────────────────────────────────────────────────────

describe( 'DeckerCollaboration', () => {
	test( 'clipboard.convert is called with {html: ...} object format', async () => {
		await loadModule();
		const session = initSession();

		// Simulate sync so onSynced fires immediately
		mockProvider._fire( 'synced', { synced: true } );
		vi.runAllTimers();

		const html = '<p>Test content</p>';
		session.initializeContentWithFallback( html );

		expect( mockQuill.clipboard.convert ).toHaveBeenCalledWith( {
			html,
		} );
	} );

	test( 'onSynced fires immediately when already synced', async () => {
		await loadModule();
		const session = initSession();

		// Trigger sync
		mockProvider._fire( 'synced', true );

		const callback = vi.fn();
		session.onSynced( callback );

		// Should fire synchronously since already synced
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'onSynced fires via promise when sync completes later', async () => {
		await loadModule();
		const session = initSession();

		const callback = vi.fn();
		session.onSynced( callback );

		// Not yet synced
		expect( callback ).not.toHaveBeenCalled();

		// Now trigger sync
		mockProvider._fire( 'synced', { synced: true } );

		// Allow microtasks to flush (promise .then)
		await Promise.resolve();

		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'synced event handler accepts boolean true correctly', async () => {
		await loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', true );

		expect( session.isSynced() ).toBe( true );
	} );

	test( 'synced event handler accepts {synced: true} object correctly', async () => {
		await loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', { synced: true } );

		expect( session.isSynced() ).toBe( true );
	} );

	test( 'synced event handler ignores false values', async () => {
		await loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', false );
		expect( session.isSynced() ).toBe( false );

		mockProvider._fire( 'synced', { synced: false } );
		expect( session.isSynced() ).toBe( false );
	} );

	test( 'destroy() calls clearTimeout for sync and single-user timers', async () => {
		await loadModule();
		const session = initSession();

		const clearTimeoutSpy = vi.spyOn( global, 'clearTimeout' );
		const clearIntervalSpy = vi.spyOn( global, 'clearInterval' );

		session.destroy();

		// Should have cleared: connectionChecker (interval), syncTimeout, singleUserTimerId
		expect( clearIntervalSpy ).toHaveBeenCalled();
		// At minimum 2 clearTimeout calls: syncTimeout + singleUserTimerId
		expect( clearTimeoutSpy.mock.calls.length ).toBeGreaterThanOrEqual( 2 );

		clearTimeoutSpy.mockRestore();
		clearIntervalSpy.mockRestore();
	} );

	test( 'destroy() sets isSynced to true to prevent post-destroy callbacks', async () => {
		await loadModule();
		const session = initSession();

		expect( session.isSynced() ).toBe( false );
		session.destroy();
		expect( session.isSynced() ).toBe( true );
	} );

	test( 'maxSingleUserChecks exhaustion resolves sync promise', async () => {
		await loadModule();

		// Make signaling appear NOT connected so single-user detection
		// can't trigger early (needs signalingOk === true for early detect)
		mockProvider.signalingConns = [];

		const session = initSession();
		const callback = vi.fn();
		session.onSynced( callback );

		// Run through all single-user checks (100ms each) + initial delay. The probe
		// now waits a longer quiet window (~2s ceiling) before proceeding when alone.
		for ( let i = 0; i <= 22; i++ ) {
			vi.advanceTimersByTime( 100 );
			await Promise.resolve(); // flush microtasks
		}

		expect( session.isSynced() ).toBe( true );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'initializeContentWithFallback does nothing when ytext already has content', async () => {
		// Make ytext have existing content
		mockYText = createMockYText( 10 );
		mockYDoc = createMockYDoc( mockYText, mockYMap );

		await loadModule();
		const session = initSession();

		// Trigger sync
		mockProvider._fire( 'synced', true );

		session.initializeContentWithFallback( '<p>New content</p>' );

		// Should NOT have called clipboard.convert since ytext already has content
		expect( mockQuill.clipboard.convert ).not.toHaveBeenCalled();
		expect( mockYText.applyDelta ).not.toHaveBeenCalled();
	} );

	test( 'initializeContentWithFallback populates ytext when empty', async () => {
		await loadModule();
		const session = initSession();

		// Trigger sync
		mockProvider._fire( 'synced', true );

		session.initializeContentWithFallback( '<p>Hello world</p>' );

		expect( mockQuill.clipboard.convert ).toHaveBeenCalledWith( {
			html: '<p>Hello world</p>',
		} );
		expect( mockYText.applyDelta ).toHaveBeenCalledWith( [
			{ insert: 'hello' },
		] );
	} );
} );

// ── Race-condition regression guards (enter/leave content replacement) ──
describe( 'DeckerCollaboration seeding race guards', async () => {
	/** Make the signaling socket appear OPEN. */
	function connectSignaling() {
		mockProvider.signalingConns = [ { ws: { readyState: 1 } } ];
	}

	/** Add a remote awareness peer (clientId !== self). */
	function addAwarenessPeer( clientId = 2 ) {
		mockProvider._awarenessStates.set( clientId, { user: { name: 'Peer' } } );
	}

	test( 'does NOT seed DB content when a peer is present in awareness', async () => {
		await loadModule();
		const session = initSession();

		// A peer is already in the room (its awareness is visible).
		addAwarenessPeer( 2 );

		// Sync resolves and the editor tries to seed the stale DB snapshot.
		mockProvider._fire( 'synced', { synced: true } );
		session.initializeContentWithFallback( '<p>STALE DB CONTENT</p>' );

		// The DB content must NOT be written into the shared CRDT: the peer's
		// live content (delivered via sync) is kept instead.
		expect( mockQuill.clipboard.convert ).not.toHaveBeenCalled();
		expect( mockYText.applyDelta ).not.toHaveBeenCalled();
		expect( mockYText.insert ).not.toHaveBeenCalled();
	} );

	test( 'does NOT seed DB content once a peers event has fired (peerEverSeen latch)', async () => {
		await loadModule();
		const session = initSession();

		// y-webrtc reports a connected peer before the Yjs doc finishes syncing.
		mockProvider._fire( 'peers', { added: [ 2 ], webrtcPeers: [ 2 ], bcPeers: [] } );

		mockProvider._fire( 'synced', { synced: true } );
		session.initializeContentWithFallback( '<p>STALE DB CONTENT</p>' );

		expect( mockQuill.clipboard.convert ).not.toHaveBeenCalled();
		expect( mockYText.applyDelta ).not.toHaveBeenCalled();
	} );

	test( 'still seeds DB content when genuinely alone (no peers)', async () => {
		await loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', { synced: true } );
		session.initializeContentWithFallback( '<p>Hello world</p>' );

		// No peer present -> we are the authoritative seeder, content is applied.
		expect( mockQuill.clipboard.convert ).toHaveBeenCalledWith( {
			html: '<p>Hello world</p>',
		} );
		expect( mockYText.applyDelta ).toHaveBeenCalled();
	} );

	test( 'does NOT declare single-user prematurely (~300ms); waits the settle window', async () => {
		await loadModule();
		connectSignaling();

		const session = initSession();

		// Advance ~300ms (the old premature threshold) — must NOT be synced yet.
		for ( let i = 0; i < 4; i++ ) {
			vi.advanceTimersByTime( 100 );
			await Promise.resolve();
		}
		expect( session.isSynced() ).toBe( false );

		// Advance through the full quiet window — now a genuinely-alone user resolves.
		for ( let i = 0; i < 14; i++ ) {
			vi.advanceTimersByTime( 100 );
			await Promise.resolve();
		}
		expect( session.isSynced() ).toBe( true );
	} );

	test( 'does NOT declare single-user while a peer is present; waits for real sync', async () => {
		await loadModule();
		connectSignaling();
		addAwarenessPeer( 2 ); // peer present from the start

		const session = initSession();

		// Even well past the single-user window, presence of a peer prevents the
		// premature single-user resolution (sync must come from the peer).
		for ( let i = 0; i < 20; i++ ) {
			vi.advanceTimersByTime( 100 );
			await Promise.resolve();
		}
		expect( session.isSynced() ).toBe( false );

		// The real Yjs sync event resolves it.
		mockProvider._fire( 'synced', { synced: true } );
		expect( session.isSynced() ).toBe( true );
	} );
} );
