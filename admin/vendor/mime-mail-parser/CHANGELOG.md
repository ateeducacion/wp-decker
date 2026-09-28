# Changelog

All notable changes to this project are documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [1.1.1] - Unreleased

### Fixed

- Release automation now uploads the package archive to an existing manually published GitHub Release instead of trying to create a duplicate Release.

## [1.1.0] - 2026-09-19

### Added

- `Message::fromStream()` for bounded parsing from readable PHP streams.
- Structured `Address` values and parsed From, To, Cc, Bcc, and Reply-To accessors.
- Raw and decoded Cc/Bcc helpers plus `Message::getMessageId()`.
- MIME media type, Content-Type parameter, disposition, and disposition-parameter helpers.
- Lazy parsing of attached `message/rfc822` parts and `Message::getAttachedMessages()`.
- Representative Outlook, Apple Mail, and Thunderbird fixtures.
- Deterministic fuzz/property tests for malformed MIME input.
- A dependency-free parser benchmark harness available through `composer bench`.

### Changed

- README examples consistently use the canonical `Erseco\MimeMailParser\...` namespace.
- MIME type checks reuse normalized media type and disposition metadata.
- File parsing delegates to the common stream-reading implementation.

### Compatibility

- Legacy `Erseco\...` class aliases remain available.
- PHP 8.2 or newer is required.
- The local and Codecov coverage requirement remains at 90%.

## [1.0.6] - 2026-07-26

See the GitHub release notes for the complete list of changes in v1.0.6.
