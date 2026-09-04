# ADR 002 — Reuse WordPress Heartbeat for server availability

- **Status:** Accepted
- **Date:** 2026-09-04

## Context

Decker already uses the WordPress Heartbeat API for notifications and task edit locks. The application needs to warn users when the server stops responding without introducing a second polling mechanism or an additional health endpoint.

## Decision

Reuse the existing Heartbeat connection state:

- `heartbeat-connection-lost` marks the server as unavailable and displays a non-blocking warning.
- `heartbeat-connection-restored` clears the warning automatically.
- No extra polling, health endpoint, or `navigator.onLine` check is added.
- The Heartbeat interval remains at 15 seconds for low-usage deployments. For high-volume deployments, increasing the interval is recommended to reduce request load.

## Consequences

Server availability monitoring adds no periodic requests beyond those Decker already makes. Detection follows Heartbeat semantics, so transient request failures are handled by WordPress before Decker presents the persistent warning.
