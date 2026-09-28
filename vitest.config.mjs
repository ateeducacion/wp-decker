/**
 * Vitest configuration for Decker JavaScript unit tests.
 *
 * Run through `npm run test:js` (`wp-scripts test-unit-js`, which starts the
 * Vitest installed in this project).
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		globals: false,
		// Match the Jest semantics the suite was written against: mocks keep
		// their calls and implementations until a test resets them. Vitest 5
		// clears them between tests by default.
		clearMocks: false,
		mockReset: false,
		restoreMocks: false,
		coverage: {
			enabled: true,
			provider: 'v8',
			reportsDirectory: 'artifacts/coverage-js',
			reporter: [ 'text', 'lcov' ],
			// `include` is deliberately left unset, so only the files a test
			// loads through the module graph (`import`) are reported. Several
			// suites read a source file with fs.readFileSync and evaluate one
			// function of it by hand, which Vitest cannot instrument, so listing
			// public/assets/js/** would report those files as 0% covered while
			// their tests pass. Converting those suites to `import` is what
			// widens this number, not a wider glob.
		},
	},
} );
