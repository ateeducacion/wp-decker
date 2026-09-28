/**
 * Placeholder test to verify Vitest is working.
 *
 * @package Decker
 */

import { describe, expect, test } from 'vitest';
describe( 'Decker JS test setup', () => {
	test( 'Vitest runs in jsdom environment', () => {
		expect( typeof document ).toBe( 'object' );
	} );
} );
