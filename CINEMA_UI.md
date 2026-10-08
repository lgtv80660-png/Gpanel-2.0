# G-PANEL cinema UI

Shared system: `panel_visual.php`, `panel_layout.php`, `assets/cinema.css`, `assets/cinema.js`.

Admin routes: `admin.php` (dashboard), `admin.php?view=clients`, `admin.php?view=sources`, `packs.php`, `panel_catalog.php?type=movie|series|live|all`, `statistics.php`, `live_monitor.php`, `system_admin.php`. Existing editor, imports, personal content, grouping, diagnostics and edit forms use the same shell. Existing PHP handlers, form names, CSRF and authorization are retained. Playback/API routes are unchanged.

Artwork uses imported `stream_icon`, `vod_backdrop`, genre, rating and release fields. Existing `movie_identifiers.tmdb_id` enriches movies through the authenticated `panel_artwork.php`; provider IDs are never treated as TMDB IDs. Imported series images are used directly; season/episode counts are displayed only when locally available. No provider calls are added to catalogue or monitoring page loads.

`TMDB_API_KEY` is the existing server-only configuration. Optional `FANART_API_KEY` enables Fanart.tv v3.2 movie backgrounds by the existing TMDB identifier, with TMDB fallback. No distinct key is required. Both keys stay on the server. Only whitelisted image URLs and rating are returned. Artwork responses are cached for 24 hours in a project-specific temporary directory, bounded to 600 JSON entries; media files are never downloaded into the repository. HTTPS certificate validation stays enabled. Lazy enrichment allows three simultaneous requests. Imported artwork containing URL credentials or query parameters is rejected to avoid exposing credentials; missing or failed images render a local fallback.

Statistics charts show daily authorized requests in UTC, not proof of successful playback. Device distribution uses received G-TV events. Monitoring KPIs show stored HTTP states, without claiming current reachability. Unknown/empty values stay explicit. The dark cinema theme is the default and takes priority over old stored light-theme settings. French/English/Arabic language controls remain.

Local verification: PHP lint, JS parsing, whitespace checks, URL/XSS/fallback helper checks. No database mutation tests, migrations, commits, pushes or deployments. Authenticated page/data validation requires the local database to be available.
