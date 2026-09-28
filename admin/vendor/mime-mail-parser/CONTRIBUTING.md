# Contributing

Contributions are welcome.

## Development setup

Requirements:

- PHP 8.2 or newer
- Composer

Install dependencies:

```bash
composer install
```

Run the complete local validation suite:

```bash
composer lint
composer analyse
composer test
composer test-coverage
```

The coverage suite must remain at or above 90%.

## Pull requests

- Add or update tests for behaviour changes.
- Keep backwards compatibility unless the change is explicitly intended for a major release.
- Include real-world or malformed MIME fixtures when they help reproduce a parser issue.
- Keep fuzz cases deterministic so CI failures are reproducible.
- Keep public API changes documented in the README.
- Ensure Composer validation, coding standards, static analysis, tests, and coverage pass.

## Security issues

Do not open public issues for suspected vulnerabilities. Follow [SECURITY.md](SECURITY.md).


## Benchmarks

Run the lightweight parser benchmark harness with:

```bash
composer bench
```

Benchmarks are intentionally informational rather than a CI gate because shared runners are too variable for stable performance thresholds.

## Release process

Releases are created manually from the GitHub Releases interface.

For v1.1.1 and later:

1. Confirm the `main` workflow is green, including the 90% coverage gate.
2. Update `CHANGELOG.md` with the release version and date.
3. Open **Releases → Draft a new release** on GitHub.
4. Choose **Create new tag on publish**, enter the version tag (for example `v1.1.1`), and target `main`.
5. Write or generate the release notes and publish the Release.
6. The `Release assets` workflow will run from the published Release event, check out that tag, build the package ZIP, and upload it to the existing Release.

The workflow must not create the Release or the tag itself.
