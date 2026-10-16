# FULL update — 16 October 2026

Target confirmed by the owner: `lgtv80660-png/Gpanel-2.0`, Railway project `G-PANEL V2 TEST`, environment `test`, service `gpanel-v2-test`, domain `gpanel-v2.up.railway.app`.

## Code preparation

The delivery's 153 manifest entries were verified before copying. Compared with main at cc17acf, 17 existing files differ and 37 paths are new (including the delivery manifest and historical test results). The original config.php, authentication, packs, operations and customized assets are identical. Existing repository-only files are retained. The delivery manifest and historical test output are not copied into the updated repository as current validation evidence.

The update adds Sources & Backups administration, independent host roles, Live opt-in HLS relay and opaque progressive VOD sessions. Public Xtream IDs and existing film-group anchors remain the references. No provider roles are assigned automatically. Live HLS remains disabled by default. No GTV project or player is modified.

## Validation actually rerun

102/102 validation commands passed using PHP with PDO SQLite, mbstring, intl and curl for the binary transport fixture. Includes PHP/JavaScript syntax, source roles and suggestions, VOD identity and transport, HTTP authorization and CSRF, Range handling, migration plans and historical catalogue regression. Fixtures use disposable databases and simulated providers; this does not prove real media playback or real-player compatibility.

The first local runs were unsuccessful because of missing PHP extensions and sandbox restrictions on localhost. The final complete run used the full PHP runtime and permitted localhost access.

## Deployment prerequisites

The current service answered HTTP 200 with `{"status":"ready","environment":"test"}` before deployment. The latest successful deployment observed is 43e8d5fe-de53-4ab6-9d13-bbd8aed3c6c9. MySQL has a persistent volume; the application has no volume.

Railway already has a pending source change from main to update/2026-10-14-consolidation, pinned to 70b9f05639b4d2cd483356ab739287cc02fd9349. Do not accept or overwrite that pending patch as a side effect of this update.

Before deploying: obtain working SSH access, export a consistent private database backup, verify restoration on a dedicated copy, inspect existing schema/roles/IDs, validate additive migrations on that copy and verify Docker/Apache. Never load mysql.sql into the existing database. Do not pass --local-copy-confirmed against the remote database; the supplied VOD migration script is specifically scoped to a local copy. Any remote migration needs an explicit maintenance path appropriate to the confirmed test target, backed by verified recovery and schema checks. Preserve client/provider credentials and existing source/group rows.

The supplied start script no longer runs migrations automatically. New private source-session storage is /var/lib/gpanel/source-sessions. Recheck health, admin access, Xtream catalogues, existing IDs/roles and actual authorized Live/VOD playback after deployment. A successful build or health endpoint alone is insufficient to claim playback verification.
