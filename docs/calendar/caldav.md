# CalDAV calendar synchronization

Decker serves a single collaborative calendar of published `decker_event` posts.
Each account discovers its own calendar home, but the underlying posts are shared.
Tasks remain in the read-only ICS feed and never become DAV resources.

## Storage and dates

`sabre/dav` 4.7 and `sabre/vobject` 4.6 handle DAV, validation, queries and bounded
recurrence expansion. `_decker_ical` retains the complete calendar object, including
UID, VTIMEZONE, alarms, client properties, RDATE, EXDATE and recurrence exceptions.
`_decker_dav_uri` stores the independent client resource name. Date metadata is a
queryable projection of the object; REST and the admin saver use the same store.
Web title/content/location changes update the represented master fields while
preserving opaque properties. Assigned people and category are internal fields,
not invitations or iCalendar attendees. DAV creation assigns the creator and the
existing green event category. No scheduling plugin or invitation transport runs.

All-day dates use `YYYY-MM-DD` and exclusive ends everywhere except the event
modal, which converts its inclusive end at the presentation boundary. The
`decker_calendar_dates_v2` migration advances legacy inclusive ends once, with
per-post markers for restartability; it does not delete events. Back up the site
before upgrading. Invalid dates are rejected. A timed event without an end is a
point event; an all-day event without an end lasts one day. No one-hour repair is
performed. Incoming TZID and VTIMEZONE remain in the canonical object.

The web calendar expands occurrences in the requested window (maximum 370 days).
The initial web editor can change master text and internal fields; change recurring
dates and individual exceptions in the DAV client. RANGE=THISANDFUTURE exceptions
are rejected explicitly. The bundled recurrence engine selects RDATE instead of
merging it with RRULE, so resources combining RRULE with RDATE, and period-valued
RDATE, are rejected rather than silently omitting occurrences. Standalone RDATE
is supported. Expansion uses sabre's iteration limits to bound work.
There is no incremental sync-token advertisement or journal: clients use queries,
multiget and ETags. A site-scoped MySQL advisory lock serializes DAV precondition checks with event
post and metadata writes (ten-second lock timeout; no extra daemon). Metadata changes participate in ETags; reads do not regenerate
DTSTAMP. WordPress trash removes a resource from the collection.

## Authentication and devices

In **Users → Profile → Decker Settings**, generate a new calendar token. Old
subscription tokens do not enable DAV writes. Use the displayed server URL and
WordPress login; the new token is the password. HTTPS is required. Regeneration
invalidates the previous DAV credential and the independent read-only ICS secret.
Revocation removes both. Opening a profile and failed authentication never generate
a token. CAS logout does not revoke tokens; use the profile controls. Site
membership, minimum Decker role and WordPress capabilities are checked per request.
The token is not installed as a WordPress password, REST authentication provider,
cookie or application password. Application passwords remain unchanged/disabled.
Never place the DAV token in a URL. The ICS URL has a separate read-only secret.

- iPhone: Settings → Apps → Calendar → Calendar Accounts → Add Account → Other →
  Add CalDAV Account. Enter the displayed server URL, login and token. Enable SSL.
- Android: DAVx⁵ → Add account → Login with URL and user name. Enter the same values,
  discover and enable **Decker**, then enable calendar synchronization. Select
  **Decker** in a compatible calendar app when creating events.

The exact settings labels vary by OS version. Do not add the ICS subscription for
editable synchronization, or subscribe twice to the same data.

## Deployment and CAS

The plugin handles `/decker-dav/` at `parse_request`, before template redirects,
with its own HTTP Basic challenge. It does not invent a WP Cassify bypass hook or
change CAS authentication filters. The repository's optional CAS test uses WP
Cassify; the institution's CAS/proxy configuration is not part of this repository.
If CAS enforces login earlier (init, mu-plugin or reverse proxy), the operator must
exclude **only** the site's DAV path boundary and the discovery URL from interactive
login. All other paths, including wp-admin and REST, must retain their normal CAS
rules. Validate unauthenticated PROPFIND returns 401 + WWW-Authenticate, not 302 or
HTML. Plain HTTP is rejected before any DAV authentication.

Pass Authorization unchanged to PHP; allow OPTIONS, PROPFIND, REPORT, GET, HEAD,
PUT and DELETE. Disable shared caching for the entire DAV path, preserve bodies and
ETag/If-Match/If-None-Match headers, and pass requests to the site's WordPress front
controller. Existing WordPress pretty-permalink front-controller rules suffice.
For PHP-FPM on Nginx include `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.
For Apache/FastCGI use `CGIPassAuth On` where supported. Trust proxy HTTPS only via
the site's existing validated HTTPS configuration; the plugin does not trust an
arbitrary forwarded header.

Discovery is deliberately opt-in: do not replace another service's well-known URL.
After checking that it is unused, configure one of these redirects at the host root
(adjust `/wordpress/` to the actual site path; each multisite host needs its own):

```nginx
location = /.well-known/caldav { return 307 https://$host/wordpress/decker-dav/; }
```

```apache
Redirect 307 /.well-known/caldav https://example.org/wordpress/decker-dav/
```

A single host-root discovery URL cannot select multiple subdirectory sites. Use
the explicit profile URL for those accounts. Block cross-site cache reuse. Never
log Authorization headers or secrets. Configure rate limits for failed Basic
requests at the trusted edge, without blocking normal DAV synchronization.

## Packaging and verification

Run `composer install` to build `admin/vendor/dav/vendor/` using the locked runtime
dependencies with `--no-dev --no-scripts --no-plugins`. `make package` includes that
bundle even though root `vendor/` is excluded. Installing the ZIP requires no
Composer command on the destination. To reproduce the extracted ZIP smoke check,
unzip into `artifacts/package/`, then run `npx wp-env run cli wp --skip-plugins=decker
eval-file wp-content/plugins/decker/tests/package/check-dav.php
/var/www/html/wp-content/plugins/decker/artifacts/package/decker`. It creates and
removes one disposable event on the local development site. Tests exercise the actual sabre request
pipeline with disposable credentials; they do not certify device or institutional
CAS interoperability.

Manual release acceptance (pending access to real devices and deployment):

1. Install the generated ZIP on a staging HTTPS WordPress; keep application
   passwords disabled and CAS enabled. Confirm the ordinary web login still uses
   CAS, and DAV without credentials returns 401, never CAS HTML/redirects.
2. Generate a profile token and connect iPhone and DAVx⁵ without browser cookies.
3. Create a titled event in **Decker** on each device. Confirm one shared WordPress
   event, creator assignment and category; sync repeatedly and check no duplicates.
4. Edit title, dates and location in both directions; delete from each endpoint.
   Check propagation to both devices and trash recovery in WordPress.
5. Repeat for one-day/multi-day all-day events and a timed Canary event crossing
   both DST boundaries. Add reminders, a recurring series, excluded dates and a
   modified occurrence. Edit master text in WordPress; verify reminders/exceptions.
6. Edit the same resource offline on two devices and verify the stale write gets
   a conflict rather than overwriting. Regenerate/revoke the profile token and
   verify both old accounts fail immediately; reconnect with the new credential.
7. Test a removed site member, lower role, another principal and another multisite
   site. Check no inaccessible events or tasks appear in the editable collection.

Do not record real tokens in screenshots, issue reports or test transcripts.

## Implementation validation

Automated checks executed locally: PHP suite (1,288 tests), JavaScript (388 tests),
full browser suite (27 tests), plus a new two-test calendar browser check covering
inclusive form display and exclusive storage. `make lint`, `make check-untranslated`
and `make check-plugin` passed. The generated ZIP was extracted outside the
checkout and loaded into local WordPress with the checkout plugin skipped;
reflection verified that sabre came from the package, then event CRUD passed.

Real iPhone, DAVx⁵, institutional CAS/proxy behavior, and a live multisite network
remain unverified. The automated DAV tests run the real sabre request pipeline
inside WordPress, without a physical mobile client or an external HTTPS proxy.
