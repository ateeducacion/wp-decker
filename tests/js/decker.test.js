const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Placeholder test to verify Jest is working.
 *
 * @package Decker
 */
describe( 'Decker JS test setup', () => {
	test( 'Jest runs in jsdom environment', () => {
		expect( typeof document ).toBe( 'object' );
	} );

	test( 'collaboration destroy cleanup unregisters retained handlers', () => {
		const collaborationFile = path.resolve(
			__dirname,
			'../../public/assets/js/decker-collaboration.js'
		);
		const source = fs.readFileSync( collaborationFile, 'utf8' );

		expect( source ).toMatch( /let remoteCursorChangeHandler = null;/ );
		expect( source ).toMatch(
			/const awarenessStatusChangeHandler = \(\) => \{/
		);
		expect( source ).toMatch(
			/const providerStatusChangeHandler = \(\) => \{/
		);
		expect( source ).toMatch( /isDisabled = true;/ );
		expect( source ).toMatch(
			/awareness\.off\('change', awarenessStatusChangeHandler\);/
		);
		expect( source ).toMatch(
			/awareness\.off\('change', remoteCursorChangeHandler\);/
		);
		expect( source ).toMatch(
			/provider\.off\('status', providerStatusChangeHandler\);/
		);
	} );
} );
