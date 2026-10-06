# Doublons films — reprise

Branche gpanel-v2. Base préservée : 42df97a après 62e79dd. Aucun push, fournisseur réel, base réelle, migration exécutée ou déploiement.

## Commits fonctionnels
- 0ddb2d0 : trois tables additives, décisions séparées des streams, résolveur partagé.
- d5dbff4 : écran movie_duplicates.php lié depuis admin, candidats locaux et confirmation transactionnelle, identifiants collectés à l'import, API/playlist/lecture respectant les désactivations.

## Comportement
- Films uniquement. Aucun ID stream modifié, aucune suppression ni fusion automatique.
- TMDB numérique / IMDb tt+chiffres quand présents dans le catalogue importé ; sinon titre Unicode normalisé + année explicite dans métadonnées ou titre entre parenthèses/crochets. Pas d'année inventée, pas de requête fournisseur pour détecter.
- Tous les candidats demandent confirmation, y compris identifiants fiables. Au moins deux fournisseurs et exactement un principal. Fusion de groupes existants refusée ; modification d'un groupe exige toutes ses sources.
- Principal/secours/désactivé persistés dans movie_group_sources. L'import ne modifie jamais ces rôles. Les identifiants précédemment collectés sont conservés si un catalogue ultérieur les omet.
- Une ancienne URL non désactivée d'un membre du groupe résout le principal ; une URL désactivée retourne 404. Visibilité, catégorie et activité fournisseur vérifiées pour l'ID demandé et la cible.
- Secours seulement par demande explicite vod.php?...&stream=ID_PRINCIPAL&backup_stream=ID_SECOURS ; même groupe, rôle secours et disponibilité locale requis. Aucun diagnostic réseau ni reprise automatique après HTTP 302. Les URL Xtream utilisent le conteneur de la cible.
- Playlist et liste VOD API masquent les sources désactivées. Les autres IDs restent visibles : pas de collapse automatique en une fiche unique. get_vod_info bloque les IDs désactivés avant enrichissement.
- Migration manquante : comportement ancien conservé sur les routes de lecture ; écran doublons refuse l'accès. Aucune migration automatique en lecture.

## Vérifications limitées effectuées
- Syntaxe PHP des huit fichiers applicatifs modifiés et fixture.
- tests/movie_groups_smoke.php sur SQLite en mémoire : confirmation, principal, secours explicite, refus désactivé, rôles indépendants des streams, fournisseur inactif, candidat titre/année, refus de deux principaux.
- git diff --check.
- Aucun test complet HTTP/MySQL/média/visuel. L'indépendance de l'import et des rôles est établie par le code, pas encore par scénario complet de resynchronisation.

## Tests groupés à faire sur copie MySQL
1. Sauvegarde restaurable vérifiée ; vérifier configuration config.php/.env vers la copie uniquement. Exécuter php migrate_v2.php --apply --backup-confirmed. Ce script inclut movie_groups.sql. Comparer IDs, nombres de streams, clients, personnalisations et exclusions avant/après ; réexécuter pour idempotence.
2. Exécuter les tests import sélectif et HTTP existants avec fixtures, puis tests/movie_groups_smoke.php (PDO SQLite et mbstring activés). Ne pas utiliser de fournisseur réel pour ces tests.
3. Importer catalogues synthétiques multi-fournisseurs avec TMDB/IMDb, titre+année, homonymes d'années différentes, identifiants contradictoires et absence d'année. Vérifier que rien n'est regroupé avant confirmation.
4. Vérifier auth admin, permission catalog:import, CSRF, pagination, rejet d'un groupe à deux principaux et tentative de fusion de groupes. Confirmer puis modifier les rôles en incluant toutes les sources.
5. Refaire import incrémental/sélectif synthétique : IDs, exclusions, titres et rôles conservés ; vérifier API VOD, playlist, ancienne URL désactivée (404), principal, secours explicite, fournisseur/catégorie/source invisible ou inactive. Vérifier Live et épisodes inchangés.
6. Tester la redirection média sur serveurs synthétiques uniquement ; le changement de conteneur entre sources peut demander validation selon les lecteurs.

## Manques / limites
- Séries et épisodes non regroupés ; aucun failover automatique après erreur média.
- Les catalogues historiques sans identifiants doivent être réimportés avec fixtures/métadonnées disponibles pour alimenter movie_identifiers. Les identifiants provenant de get_vod_info ne sont pas encore collectés.
- Détection en mémoire sur tout le catalogue films ; affichage paginé par 20, mais charge mémoire à mesurer sur copie volumineuse.
- Pas d'interface de sélection arbitraire sans candidat ni de dissolution de groupe ; pas de fiche canonique unique ni suppression des doublons secours dans les listes.
- Migration et concurrence MySQL, resynchronisation complète, droits/CSRF HTTP et lecture réelle non validés ici.
