# Catalogue V2 — 7 octobre 2026

Branche exclusive `gpanel-v2`, application Railway `gpanel-v2-test` dans `G-PANEL V2 TEST / test`. Aucun appel réel aux fournisseurs pendant les tests.

- `panel_diagnostics.php` : page Santé responsive ; `panel_diagnostics_json.php` : JSON séparé, permission `panel:read`, GET uniquement, sans identifiants fournisseur.
- `import_selective.php` : catalogue local disponible sans téléchargement ; catalogue distant uniquement sur action explicite ; catégories et contenus déjà en base, nouveau/importé/exclu/inactif/disparu, renommage, visibilité, exclusions individuelles et de catégorie. Une absence distante ne supprime jamais les contenus.
- `content_edit.php` : titres, affiches, catégories, métadonnées, qualité manuelle, source directe ou fournisseur/ID distant de remplacement. Les remplacements de séries utilisent une source Xtream et sont hérités par les épisodes matérialisés. Désactivation sans suppression ; suppression définitive après saisie de `SUPPRIMER <ID>`. Les suppressions fournisseur créent une exclusion ; les catégories doivent être vides. Une série personnelle doit être vidée explicitement de ses épisodes avant suppression. Les épisodes fournisseur déjà liés à une série supprimée sont conservés et refusés à la lecture.
- `personal_content.php` : catégories indépendantes et CRUD Live/films/séries/épisodes, propriétaire interne `0`, sans fournisseur artificiel. URLs HTTP/HTTPS ; épisodes liés à une série personnelle, saison et numéro. Catalogue Xtream, playlist M3U, lectures authentifiées existantes. `production_ref` est une simple référence facultative, sans nouveau système de productions.
- Qualité : résolution explicite d'abord, puis un token non ambigu du titre. Origine vérifiée par métadonnées / estimée / manuelle ; ni codec ni conteneur utilisés comme résolution. Aucun sondage média. Les corrections et remplacements sont conservés dans `content_settings` et prioritaires sur les réimports.

Migration additive : `content_v2.sql`, appelée par `migrate_v2.php` et intégrée au schéma neuf et au Dockerfile. Aucune ligne ni aucun ID du catalogue existant réécrit. Le démarrage Railway conserve la base de test marquée, crée la table additive et exécute la migration deux fois. Aucun dump historique réimporté.

Tests : `tests/content_v2.php`, `tests/import_selective.php`, `tests/run_regression.py` (normal et `--selective`) et `tests/content_ui.cjs` (1440 et 390 px). Fixtures SQLite et transport simulé uniquement. Contrôles HTTP/MySQL du service TEST après déploiement. Lecture de vrais médias, charge et compatibilité avec l'app externe non testées, conformément à l'absence d'appels fournisseurs.

Les anciens épisodes fournisseur sans lien connu sont conservés ; le lien est établi à la prochaine matérialisation de leur série. Les exclusions ne masquent pas les contenus existants : utiliser la désactivation séparée. Une exclusion de catégorie bloque les futurs nouveaux contenus autant que les rafraîchissements.

## Déploiement vérifié

Code applicatif : `5b8d08bd27fa70609380ae232ea6a17734772489`. Railway : `06feda0c-0378-476d-bcd4-4e0684310659`, statut SUCCESS et santé PHP/MySQL validée. URL : https://gpanel-v2-test-test.up.railway.app.

Catalogue conservé : 65 Live, 403 films, 358 séries, 25 épisodes (851 contenus), zéro orphelin. Pages Diagnostic, Contenus personnels, Création de série et Gestion fournisseur contrôlées avec authentification. Table additive `content_settings` en InnoDB. Listes Xtream et M3U vérifiées par présence des IDs synthétiques, sans supposer une base vide ; métadonnées et lecture uniquement des fixtures en boucle locale. Aucun appel de catalogue fournisseur réel ni lecture des autres contenus.
