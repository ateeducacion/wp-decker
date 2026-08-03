module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	coverageDirectory: 'artifacts/coverage-js',
	coverageReporters: [ 'text', 'lcov' ],
	// `collectCoverageFrom` is deliberately left unset. Most suites here read a
	// source file with fs.readFileSync and evaluate it by hand, which Jest
	// cannot instrument, so listing public/assets/js/** would report those files
	// as 0% covered while their tests pass. Only the files loaded through
	// `require()` are measured; converting the readFileSync suites is what
	// widens this number, not a wider glob.
};
