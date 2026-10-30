# Authentication

Decker's client authenticates as **a real WordPress user through CAS** and then
uses that user's own session for every REST call. There is no service account,
no shared token and no administrator credential: whatever the user may not do in
the Decker interface, the client may not do either.

## Configure and log in

```
decker auth login --site https://example.org/medusa/gestionate/ \
                  --cas https://login.example.org/cas/login \
                  --user your.username
```

`--site` is the complete site or subsite URL. `--cas` is the CAS login URL. Both
are remembered in `~/.config/decker/config.json`, so later runs need only
`decker auth login`. The configuration file holds **no secrets**.

The password is typed at a prompt on the terminal, with echo disabled. It is
never accepted as a command-line argument (`--password` is rejected outright),
never read from a pipe and never written anywhere.

```
decker auth status     # is the session still valid?
decker auth logout     # forget the stored session
decker doctor          # what can the client reach from here?
```

## The flow

1. `GET` the WordPress admin URL. Starting there, rather than at `wp-login.php`,
   keeps the CAS callback inside the path WordPress sets its logged-in cookie
   for, and avoids a login loop with some Cassify versions.
2. Follow the redirect to the configured CAS host. A redirect anywhere else is
   refused.
3. Read the login form, keeping every hidden field — `execution`, `lt` and
   anything else the deployment adds — exactly as served.
4. `POST` `username`, `password` and `_eventId=submit` to the form's action,
   which must be on the CAS origin.
5. Follow the service ticket back to WordPress. **WordPress validates the
   ticket**; this client is an HTTP form client, not a CAS server.
6. Keep the WordPress cookies the callback set.
7. Fetch a REST nonce (below).
8. Make authenticated REST requests with the cookies and that nonce.

## Where the nonce comes from

The nonce is fetched from core's own authenticated AJAX action:

```
GET <site>/wp-admin/admin-ajax.php?action=rest-nonce
```

This is deliberate. The reference Code Snippets client scrapes a nonce out of
its own plugin's administration screen, which would require every Decker user to
be able to load that screen. `rest-nonce` exists on every WordPress, requires
only that the user is logged in, and needs no new endpoint in Decker. No
unauthenticated endpoint hands out sessions or nonces.

A nonce is short-lived, so one is fetched per run rather than stored. A response
that is not a nonce — `0`, `-1` or a login page — means the session has expired
and is reported as such, telling you to log in again.

## Where the session is stored

Only the WordPress cookies are stored, never the CAS password.

| Platform | Storage |
| --- | --- |
| macOS | Keychain, under service `decker-client`, account `session` |
| Elsewhere | `~/.config/decker/session.json`, mode `0600`, **with a warning on every write** |

`DECKER_SESSION_STORE=keychain|file` overrides the choice. Linux Secret Service
and Windows Credential Manager are not implemented yet; on those systems the
client uses the file fallback and says so rather than storing a cookie in
plaintext silently. Add them by extending `sessionStore` in
`scripts/decker-client.mjs`.

## Transport

HTTPS is required. `--allow-insecure-http` exists for local testing only and
must be passed explicitly; TLS verification is never disabled.

Both of WordPress's documented routings are supported — `/wp-json/` and
`?rest_route=` — and the client probes for whichever answers with JSON, then
stores that choice and uses it consistently. Both are WordPress's own routing:
neither bypasses an access control, and the alternate form is only useful where
a proxy does not expose the pretty one.

Every redirect hop is checked individually: a hop to an origin that is neither
the configured WordPress nor the configured CAS host is refused, `Authorization`
and `X-WP-Nonce` are dropped when the origin changes, and a 307/308 never
replays a credential-bearing body to another host. Requests time out (15s by
default, `timeoutMs` in the configuration file).

## What this does not do

- It does not run JavaScript, so a login form built by a script is not supported.
- It does not complete MFA, a CAPTCHA, WebAuthn or any interactive challenge.
  Where institutional policy requires one, the client reports that an
  interactive login is needed. **It does not attempt to work around the
  challenge**, and neither should anyone extending it.
- It does not implement the CAS REST/TGT protocol or validate service tickets.
- It does not use Application Passwords. They are not currently reliable on the
  target deployment; the client neither depends on them nor assumes they are
  disabled.
- Passing CI does not certify compatibility with every Apereo or Cassify
  deployment. The tests drive a deterministic local CAS simulator.
