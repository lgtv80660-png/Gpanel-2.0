# Fichiers et consolidation

Base : branche sources-backups-local, commit a97342f220cb5cba630d9de8b58cba9992f32bbd, copie ...-11 avec ses correctifs non commitées conservés. Le seul fichier commun différent dans ...-12 était panel_source_relay.php ; son correctif X-Panel-Root est repris. Le chantier source n’a pas été modifié.

La consolidation réutilise Sources & Backups et le relais Live existants, les groupes films, le cache de métadonnées séries, les gardes administrateur/CSRF et les règles de packs. La VOD partage une seule politique et un seul transport ; ses écrans sont inclus dans le contrôleur d’administration Sources existant.

## Fichiers existants modifiés par rapport à la copie source

- assets/sources-backups.js
- content_edit.php
- deploy/start.sh
- Dockerfile
- movie_duplicates.php
- panel_movie_groups.php
- panel_series_cache.php
- panel_source_relay.php
- panel_sources.php
- panel_vod_relay.php
- player_api.php
- series.php
- sources_manage.php
- tests/movie_groups_smoke.php
- tests/run_regression.py
- tests/series_cache.php
- tests/vod_proxy.py
- vod.php

## Nouveaux fichiers applicatifs

- panel_vod_sources.php : politique et validation d’identité communes films/épisodes.
- panel_vod_session.php et vod_source_media.php : capacités opaques et recontrôle des droits.
- panel_vod_manage.php et panel_sources_catalog.php : vues dans Sources & Backups.
- panel_state_path.php : vérification portable du répertoire privé.
- assets/vod-sources.js : gestion des lignes, ordre et confirmation.
- vod_sources.sql et migrate_vod_sources.php : complément additif explicite.
- tests/vod_fixture.php, export_vod_fixture.php, vod_sources.php, vod_transport.php, full_update_http.py et run_full_update.py : fixtures et validation.

Les sources complètes, assets et tests historiques sont inclus. Les documents FULL, la provenance, le manifeste de fichiers et les résultats accompagnent le projet. Les scripts auxiliaires utilisés pour assembler le lot ne sont pas nécessaires à son fonctionnement et ne sont pas inclus.

## Fichiers protégés vérifiés identiques

- admin.php
- panel_security.php
- panel_packs.php
- panel_operations.php
- playback_events.php
- config.php
- live.php

Les SHA-256 de ces fichiers sont enregistrés dans PROVENANCE_FULL.json. Le Dockerfile et le modèle de démarrage sont adaptés uniquement dans ce livrable pour le stockage privé et l’absence de migrations au démarrage ; ils n’ont pas été exécutés. Aucun fichier d’une installation réelle ni aucune variable .env n’a été changé.
