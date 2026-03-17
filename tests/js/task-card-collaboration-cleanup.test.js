const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Regression tests for task-card collaboration cleanup.
 *
 * @package Decker
 */
describe( 'Task card collaboration cleanup', () => {
	test( 'unregisters form field and awareness observers on destroy', () => {
		const taskCardFile = path.resolve(
			__dirname,
			'../../public/assets/js/task-card.js'
		);
		const source = fs.readFileSync( taskCardFile, 'utf8' );

		expect( source ).toMatch( /let remoteChangesHandler = null;/ );
		expect( source ).toMatch( /let fieldAwarenessChangeHandler = null;/ );
		expect( source ).toMatch( /let remoteUpdateResetTimerId = null;/ );
		expect( source ).toMatch( /const fieldCleanupCallbacks = \[\];/ );
		expect( source ).toMatch( /formFields\.observe\(remoteChangesHandler\);/ );
		expect( source ).toMatch(
			/formFields\.unobserve\(remoteChangesHandler\);/
		);
		expect( source ).toMatch(
			/awareness\.on\('change', fieldAwarenessChangeHandler\);/
		);
		expect( source ).toMatch(
			/awareness\.off\('change', fieldAwarenessChangeHandler\);/
		);
		expect( source ).toMatch( /clearTimeout\(remoteUpdateResetTimerId\);/ );
		expect( source ).toMatch(
			/fieldCleanupCallbacks\.forEach\(cleanup => cleanup\(\)\);/
		);
	} );
} );
