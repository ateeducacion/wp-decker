# Security Policy

## Supported versions

Security fixes are provided for the latest released version.

## Reporting a vulnerability

Please do not open a public issue for a suspected security vulnerability.

Report vulnerabilities through GitHub's private vulnerability reporting feature for this repository. Include:

- the affected version or commit;
- a minimal reproducer or sample message when possible;
- the expected and observed behaviour;
- the security impact;
- any suggested mitigation.

This library parses untrusted MIME input. Reports involving denial of service, resource exhaustion, malformed MIME structures, header parsing, attachment handling, or charset decoding are considered security-relevant.
