# VOD session capacity repair — 2026-10-20

## Confirmed incident

On Railway test, under Apache's uid 33, the private session directory existed,
was writable with mode 0700, and contained exactly 1,000 valid expired sessions
and zero unexpired sessions. `vod_plan()` succeeded for movie 8146 and episode
11935. Authenticated requests to both public routes reproduced HTTP 503 with
the exact body `VOD session unavailable`. The old admission check counted all
JSON files without reclaiming expired sessions.

## Repair and boundaries

At capacity, admission reclaims only valid, expired session files after acquiring
their exclusive nonblocking lock. Unexpired sessions, locked transfers, symlinks,
unknown filenames and malformed state are retained. A separate short admission
lock serializes creation and capacity checks; existing media requests bypass it.
Failed creations remove their own incomplete file. Capacity remains 1,000; busy
admission fails closed with a retry hint on VOD opening.

VOD opening logs fixed stage/reason codes, content ID and a whitelisted kind.
It never logs exception messages, request URLs, client credentials, capability
tokens, provider URLs or SQL text. Client-facing failures remain generic.

No domain, frontend, player, Live routing, database schema, authentication rule,
source-selection rule or Docker startup configuration is changed. The shared
session-storage helper necessarily covers both VOD and opt-in Live relay state.

## Validation before deployment

- 105/105 validation commands passed: PHP syntax, policy, authorization,
  transport, HTTP endpoints, administration and Live regression fixtures.
- 12 capacity checks and 38 relay checks also passed on the running Linux
  PHP 8.3.35 runtime as uid 33, using a separate temporary copy of the patch.
- 16 concurrent creators competed for one free slot: exactly one was admitted;
  the other 15 received busy/full and all 999 existing sessions were retained.
- HTTP tests cover unavailable storage, 1,000 active sessions preserved,
  recovery from 1,000 expired sessions, safe diagnostics, relative opaque 307,
  film/episode media, HEAD, byte seeking, source pinning, credential rotation,
  pack revocation and source-policy revocation.
- Real Live baseline: channel 852 returned 302 followed by HTTP 200 and an HLS
  playlist (1,186 bytes). This checks the manifest, not decoded Live frames.

The service currently has one replica and no mounted session volume. Session
files survive requests within a deployment but capabilities do not survive a
container replacement. This repair does not change that deployment behavior.
Post-deployment real-media results are recorded in the delivered incident report.

## Second confirmed blocker: provider CDN port

After the session fix, both real routes returned 307 but media returned 502.
The configured provider returned 302 to a public CDN on TCP port 22541. The
destination validator allowed only 80/443 and rejected that redirect. A bounded
diagnostic with the explicit CDN port allowed returned HTTP 206, video/mp4,
65,536 bytes for each content.

`PANEL_VOD_UPSTREAM_PORTS` now accepts comma-separated additional ports in
1024..65535. Default behavior remains 80/443. Railway test is configured with
the single additional port `22541`; the port and CDN host are not embedded in
application code. Each redirect still validates the scheme, hostname, public
DNS addresses and absence of userinfo, then pins DNS for the connection. Other
ports and all private/reserved addresses remain blocked. The setting applies
only to progressive VOD; Live and the default shared validator retain 80/443.
Transport exceptions log only fixed diagnostic codes and hop/start metadata.

Tests cover explicitly allowed/blocked redirect ports, loopback, metadata,
IPv6 loopback, internal hostnames, userinfo, file URLs, mixed private DNS and
invalid configuration. No database mutation or frontend change is needed.

## Real transport compatibility

The real CDN ignored `Range: bytes=0-`, returning a complete 200 representation;
the strict relay rejected it with 502. A complete response with a valid known
length now satisfies only an open range starting at zero, with equivalent 206
metadata. Ignored bounded or nonzero ranges are still rejected. After pinning a
file, open seeks use its known length to send an explicit end to the provider;
the returned range and file identity must still match exactly.

The gateway closed HEAD requests and its CDN returned 404 for HEAD while GET
worked. For HEAD statuses 0/404/405/501, a one-byte GET discovers redirects or
validates metadata without emitting a media body. A partial one-byte response is
translated into full-file HEAD metadata. Authorization, source pinning and
credential secrecy remain unchanged.

Before deployment, a temporary Linux copy of this final transport returned HEAD
200, open-range 206 and open-seek 206 for both real contents, with consistent
total lengths. Local binary HTTP tests cover empty HEAD replies, 404/405 HEAD,
one-byte metadata probing and ignored initial open ranges. Unit tests reject
ignored bounded/nonzero ranges and seeks beyond the pinned file length.
