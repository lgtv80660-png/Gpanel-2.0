# VOD relay and admin completion

VOD URL shape, catalogue IDs, Xtream metadata and M3U entries stay unchanged. Authenticated playback uses a server-side relay. Only eligible backup sources from the same confirmed group are attempted (at most four, ordered by stable stream ID). Hidden/disabled sources, categories and providers are filtered through existing checks. An explicit backup remains an explicit selection without automatic cycling.

The relay forwards GET/HEAD and a single byte range, preserving 200/206 and media length/range headers. It never forwards provider cookies, authentication headers or URLs to clients. Upstream redirects are handled internally with three hops maximum and HTTP(S) only. TLS verification remains enabled. Connection timeout is four seconds; a stalled transfer is stopped after eight seconds below one byte/second. Errors before output try the next source. All failed sources return a generic 502. After output begins, no source switch is attempted: splicing two independent files would corrupt playback. The server cannot observe a decoder failure in an external app.

This relay supports binary file VOD. HTML, JSON, XML and HLS playlists are rejected rather than exposing nested provider URLs. Full HLS playlist/segment rewriting is not implemented. Relay capacity and concurrent long downloads have not been load-tested. A Range/seek request is a fresh request, without a sticky source guarantee.

The admin finishing layer preserves original forms, CSRF fields and handlers. It extends navigation to import screens, adds current page markers, keyboard focus, skip links, mobile navigation and reduced motion support, empty tables and consistent responsive surfaces. Browser fixture checks cover desktop/tablet/mobile widths and FR/EN/AR.

Local tests use in-memory/disposable SQLite and loopback servers only. The Railway media fixture is a known binary string, deliberately not a decodable video. Neither the fixture nor successful proxy tests prove external IPTV playback. No legitimate external app playback has been validated in this environment.
