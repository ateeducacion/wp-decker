/**
 * Unit tests for decker-collaboration.js
 *
 * Tests the DeckerCollaboration IIFE by loading it in jsdom with mocked globals.
 * All CDN dependencies (Yjs, y-webrtc, y-quill, Quill) are mocked at global scope.
 *
 * @package Decker
 */

/* eslint-disable no-undef */

// ── Helpers ──────────────────────────────────────────────────────────

/** Build a minimal mock Quill instance */
function createMockQuill() {
	return {
		clipboard: {
			convert: jest.fn( () => ( { ops: [ { insert: 'hello' } ] } ) ),
			dangerouslyPasteHTML: jest.fn(),
		},
		getText: jest.fn( () => 'content' ),
		setContents: jest.fn(),
		getModule: jest.fn( () => null ), // no cursors module by default
		on: jest.fn(),
		off: jest.fn(),
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
		applyDelta: jest.fn( () => {
			length = 5;
		} ),
		toDelta: jest.fn( () => [ { insert: 'hello' } ] ),
		insert: jest.fn(),
		toString: jest.fn( () => 'content' ),
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
		observe: jest.fn(),
	};
}

/** Build a minimal mock Y.Doc */
function createMockYDoc( ytext, ymap ) {
	return {
		getText: jest.fn( () => ytext ),
		getMap: jest.fn( () => ymap ),
		destroy: jest.fn(),
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
		getStates: jest.fn( () => awarenessStates ),
		setLocalStateField: jest.fn(),
		on: jest.fn(),
	};

	const provider = {
		awareness,
		signalingConns: [],
		connected: false,
		on: jest.fn( ( event, cb ) => {
			if ( ! handlers[ event ] ) {
				handlers[ event ] = [];
			}
			handlers[ event ].push( cb );
		} ),
		connect: jest.fn(),
		disconnect: jest.fn(),
		destroy: jest.fn(),
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
	jest.useFakeTimers();

	mockYText = createMockYText( 0 );
	mockYMap = createMockYMap();
	mockYDoc = createMockYDoc( mockYText, mockYMap );
	mockProvider = createMockProvider();
	mockQuill = createMockQuill();
	mockBinding = { destroy: jest.fn() };

	// Set up global mocks expected by the IIFE's import statements.
	// The IIFE uses ES module imports from esm.sh URLs; we need to
	// intercept those. Since Jest with jsdom can't natively import
	// from URLs, we load the file by stripping the imports and
	// injecting the globals.
	//
	// Strategy: read the file, replace the `import` statements with
	// global assignments, then eval in the jsdom context.

	// Global mock classes
	global.Y = {
		Doc: jest.fn( () => mockYDoc ),
	};
	global.WebrtcProvider = jest.fn( () => mockProvider );
	global.QuillBinding = jest.fn( () => mockBinding );

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
	jest.useRealTimers();
	jest.restoreAllMocks();
	delete global.Y;
	delete global.WebrtcProvider;
	delete global.QuillBinding;
	delete global.WebSocket;
	delete global.window.DeckerCollaboration;
	delete global.window.deckerCollabConfig;
	document.body.innerHTML = '';
} );

/**
 * Load the collaboration module by reading the source file, stripping
 * the ESM import lines, and evaluating the resulting IIFE.
 */
function loadModule() {
	const fs = require( 'fs' );
	const path = require( 'path' );
	const filePath = path.resolve(
		__dirname,
		'../../public/assets/js/decker-collaboration.js'
	);
	let source = fs.readFileSync( filePath, 'utf8' );

	// Strip the three ESM import statements at the top
	source = source.replace( /^import .* from ['"]https:\/\/esm\.sh\/.*['"];?\s*$/gm, '' );

	// Replace references used in module scope
	// Y.Doc, Y.Text, Y.Map → our globals
	// WebrtcProvider, QuillBinding → our globals
	// We need to make the Y, WebrtcProvider and QuillBinding available
	// in the function scope.  They are used like `new Y.Doc()`, etc.

	// Wrap to inject globals
	const wrappedSource = `
		var Y = global.Y;
		var WebrtcProvider = global.WebrtcProvider;
		var QuillBinding = global.QuillBinding;
		${ source }
	`;

	// eslint-disable-next-line no-eval
	eval( wrappedSource );
}

/** Helper: init a session and return it */
function initSession( quill = mockQuill ) {
	const container = document.getElementById( 'test-container' );
	return window.DeckerCollaboration.init( quill, '123', container );
}

// ── Tests ────────────────────────────────────────────────────────────

describe( 'DeckerCollaboration', () => {
	test( 'clipboard.convert is called with {html: ...} object format', () => {
		loadModule();
		const session = initSession();

		// Simulate sync so onSynced fires immediately
		mockProvider._fire( 'synced', { synced: true } );
		jest.runAllTimers();

		const html = '<p>Test content</p>';
		session.initializeContentWithFallback( html );

		expect( mockQuill.clipboard.convert ).toHaveBeenCalledWith( {
			html,
		} );
	} );

	test( 'onSynced fires immediately when already synced', () => {
		loadModule();
		const session = initSession();

		// Trigger sync
		mockProvider._fire( 'synced', true );

		const callback = jest.fn();
		session.onSynced( callback );

		// Should fire synchronously since already synced
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'onSynced fires via promise when sync completes later', async () => {
		loadModule();
		const session = initSession();

		const callback = jest.fn();
		session.onSynced( callback );

		// Not yet synced
		expect( callback ).not.toHaveBeenCalled();

		// Now trigger sync
		mockProvider._fire( 'synced', { synced: true } );

		// Allow microtasks to flush (promise .then)
		await Promise.resolve();

		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'synced event handler accepts boolean true correctly', () => {
		loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', true );

		expect( session.isSynced() ).toBe( true );
	} );

	test( 'synced event handler accepts {synced: true} object correctly', () => {
		loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', { synced: true } );

		expect( session.isSynced() ).toBe( true );
	} );

	test( 'synced event handler ignores false values', () => {
		loadModule();
		const session = initSession();

		mockProvider._fire( 'synced', false );
		expect( session.isSynced() ).toBe( false );

		mockProvider._fire( 'synced', { synced: false } );
		expect( session.isSynced() ).toBe( false );
	} );

	test( 'destroy() calls clearTimeout for sync and single-user timers', () => {
		loadModule();
		const session = initSession();

		const clearTimeoutSpy = jest.spyOn( global, 'clearTimeout' );
		const clearIntervalSpy = jest.spyOn( global, 'clearInterval' );

		session.destroy();

		// Should have cleared: connectionChecker (interval), syncTimeout, singleUserTimerId
		expect( clearIntervalSpy ).toHaveBeenCalled();
		// At minimum 2 clearTimeout calls: syncTimeout + singleUserTimerId
		expect( clearTimeoutSpy.mock.calls.length ).toBeGreaterThanOrEqual( 2 );

		clearTimeoutSpy.mockRestore();
		clearIntervalSpy.mockRestore();
	} );

	test( 'destroy() sets isSynced to true to prevent post-destroy callbacks', () => {
		loadModule();
		const session = initSession();

		expect( session.isSynced() ).toBe( false );
		session.destroy();
		expect( session.isSynced() ).toBe( true );
	} );

	test( 'maxSingleUserChecks exhaustion resolves sync promise', async () => {
		loadModule();

		// Make signaling appear NOT connected so single-user detection
		// can't trigger early (needs signalingOk === true for early detect)
		mockProvider.signalingConns = [];

		const session = initSession();
		const callback = jest.fn();
		session.onSynced( callback );

		// Run through all 10 checks (100ms each) + initial 100ms delay
		for ( let i = 0; i <= 10; i++ ) {
			jest.advanceTimersByTime( 100 );
			await Promise.resolve(); // flush microtasks
		}

		expect( session.isSynced() ).toBe( true );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'initializeContentWithFallback does nothing when ytext already has content', () => {
		// Make ytext have existing content
		mockYText = createMockYText( 10 );
		mockYDoc = createMockYDoc( mockYText, mockYMap );

		loadModule();
		const session = initSession();

		// Trigger sync
		mockProvider._fire( 'synced', true );

		session.initializeContentWithFallback( '<p>New content</p>' );

		// Should NOT have called clipboard.convert since ytext already has content
		expect( mockQuill.clipboard.convert ).not.toHaveBeenCalled();
		expect( mockYText.applyDelta ).not.toHaveBeenCalled();
	} );

	test( 'initializeContentWithFallback populates ytext when empty', () => {
		loadModule();
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
