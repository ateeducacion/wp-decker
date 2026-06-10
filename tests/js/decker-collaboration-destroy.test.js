const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Regression test for collaboration destroy cleanup.
 *
 * @package Decker
 */
describe( 'Decker collaboration destroy cleanup', () => {
	test( 'unregisters retained handlers', () => {
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
