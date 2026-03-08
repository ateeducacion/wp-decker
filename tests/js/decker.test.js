/**
 * Placeholder test to verify Jest is working.
 *
 * @package Decker
 */
describe( 'Decker JS test setup', () => {
	test( 'Jest runs in jsdom environment', () => {
		expect( typeof document ).toBe( 'object' );
	} );
} );
