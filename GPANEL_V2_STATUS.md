# G-PANEL V2 — bilan et reprise

Date : 6 octobre 2026. Base exacte uploadée : `c0163611b4372552be14e6915e4e3c793b1099df`.
Branche de travail exclusive : `gpanel-v2`. Aucun déploiement, fusion, accès Railway/Cloudflare, appel fournisseur ou connexion à la base réelle pendant ce chantier.

## Feuille de route et état réel

| Étape | État | Résultat / limite |
|---|---|---|
| Audit des données et du schéma | Achevé pour les fichiers uploadés | Dump avec comptes et identifiants en clair ; tables catalogue MyISAM ; fichier de migrations requis mais absent sous son nom PHP. |
| Import incrémental et ID stables | Implémenté, tests locaux réussis | Clé fournisseur + type + ID distant ; créations par lots de 500 ; aucune suppression ; verrou par fournisseur sur MySQL ; transaction par type ; données absentes du fournisseur conservées. |
| Priorité aux personnalisations | Implémentée pour réimport et épisodes | Conserve nom, icône, catégorie, visibilité, format, métadonnées. Rafraîchit uniquement source et identité du contenu existant. Métadonnées série complétées seulement si vides. |
| Catégories | Sélection existante conservée et rendue non destructive | Sélection indépendante Live/films/séries ; catégories masquées restent masquées. Un lot terminé reste acquis si un autre type échoue. |
| Sélection individuelle, filtres formats/qualités | À réaliser | L'éditeur existant permet la visibilité individuelle. Aucun nouveau sélecteur d'import individuel ni mesure de qualité média dans cette étape. Formats existants conservés. |
| Diagnostic Live/VOD/séries | Diagnostic catalogue local disponible | `panel_diagnostics.php`, protégé par `panel:read`, compte contenus disponibles, orphelins, identités dupliquées, moteurs et candidats inter-fournisseurs. Aucun test réel de disponibilité des flux. |
| Doublons entre fournisseurs | Candidats exacts seulement | Aucun regroupement automatique, aucun rôle principal/secours/désactivé et aucun basculement de lecture implémentés. Ne pas confondre titres similaires et contenu identique. |
| Épisodes Xtream à la demande + cache | Implémenté, tests de fixture réussis | Appels `get_series_info`, ID locaux stables, épisodes masqués exclus, noms/icônes/formats préservés ; cache des métadonnées distantes 15 min, secours périmé limité à 24 h en cas d'échec ; réponse client reconstruite à chaque requête. |
| Synchronisation rapide avec l'app | Protocole existant conservé | Revalidation existante avec accusé de réception testé ; aucun nouveau consommateur activé. Code/contrat de l'app absents de ce dépôt : synchronisation de bout en bout non vérifiée. |
| SQL | Améliorations préparées | Recherche d'épisodes par lots de 400 au lieu d'un SELECT par épisode ; migration explicite vers InnoDB et index. Aucune mesure EXPLAIN/charge sur MySQL réel. |

Ce point de reprise est un socle V2, pas une version « full options » ni une version validée en production.

## Audit et conservation des données

Le dump de la base uploadée contient **15 046** lignes de contenus : 653 Live, 11 961 films, 2 203 séries, 229 épisodes. Les « 512 contenus actuels » ne peuvent pas être identifiés comme un sous-ensemble précis à partir du dépôt seul. Les tests utilisent explicitement 512 contenus d'origine et vérifient leur conservation ; ce n'est pas une vérification de la base réelle.

`mysql.sql` devient un **schéma sans données**, utilisable seulement pour une base locale neuve. Il ne faut pas le réimporter sur une base existante. Aucun compte, fournisseur, client ou contenu réel n'a été supprimé d'une base ; aucune base réelle n'a été ouverte. Le dump uploadé original reste dans l'historique de base, dont `main` n'a pas été modifiée.

Les mots de passe administrateur/client et les identifiants fournisseur présents dans l'ancien dump sont à renouveler. Leur retrait dans le dernier état des fichiers ne les retire pas de l'historique Git ni des éventuelles copies. Aucun historique distant réécrit dans cette tâche. Les fichiers SQL, sauvegardes `.bk`, `.dez`, environnement et logs sont bloqués via `.htaccess` et le routeur local. Les scripts de réparation sont réservés à la CLI avec confirmation de sauvegarde ; le diagnostic Stalker requiert désormais les permissions d'import.

Autres limites préexistantes : transport fournisseur désactivant encore la vérification TLS dans certains chemins ; comptes clients compatibles Xtream conservés en clair dans la base ; import Stalker paginé restant long ; rafraîchissement de certaines métadonnées VOD encore historique. Ces points nécessitent des changements séparés et des essais compatibles avec les fournisseurs. Le diagnostic local ne certifie pas la santé réseau d'un fournisseur.

## Migration explicite — non exécutée sur une base réelle

Prérequis : PHP 8.1+ avec PDO MySQL, curl, mbstring ; schéma applicatif uploadé V5 ; base dédiée, jamais le schéma système `mysql`.

1. Faire une sauvegarde complète hors racine web et **tester sa restauration** sur une base locale dédiée. Noter les ID, comptes, visibilités et compteurs par type avant changement.
2. Configurer `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` pour cette copie seulement.
3. En maintenance, lancer `php migrate_v2.php --apply --backup-confirmed` sur la copie. DDL MySQL auto-commit : cette opération ne peut pas être annulée par rollback. Vérifier moteurs, index et compteurs après exécution, puis réexécuter pour tester l'idempotence sur MySQL.
4. Rejouer les scénarios d'import et de lecture sur cette copie avant toute décision pour la base utilisée. L'import refuse MyISAM au lieu de prétendre annuler ses écritures. La migration ne fusionne ni ne supprime les doublons et ne change aucun ID ou identifiant.

Une erreur DDL n'est pas ignorée. Une interruption peut laisser une conversion/indexation partielle : reprendre après contrôle de l'état, ou restaurer la sauvegarde. La migration ne démarre aucun service. L'API ne migre plus à chaque appel.

Pour activer le cache, créer un dossier privé **hors racine web**, accessible au compte PHP, et définir `PANEL_STATE_DIR`. Le nom des fichiers est haché ; les métadonnées distantes restent privées. Ne pas sauvegarder/publiquement exposer ce dossier ; purger les fichiers obsolètes lors d'une rotation des identifiants ou d'un changement de fournisseur. Sans dossier sûr, le fonctionnement à la demande continue sans cache. Aucun URL client ni réponse d'authentification n'est caché. Les requêtes simultanées sans cache disponible peuvent recevoir une liste vide pendant le chargement plutôt que lancer plusieurs téléchargements.

## Tests effectivement exécutés

PHP officiel portable 8.3.26, fixtures SQLite temporaires, aucun réseau fournisseur :

- `python tests/run_regression.py --php <php.exe> --ext <dossier-ext>` : authentification admin/client, permissions, CSRF, expiration, langues, édition, import vide, diagnostic ; épisodes à la demande, cache sur deuxième appel, ID/noms/formats ; redirections Live/films/épisodes sans suivre le fournisseur ; catégories masquées et fournisseurs désactivés dans API, détails, lecture et playlist M3U.
- `php -d extension=pdo_sqlite -d extension=mbstring tests/import_v2.php` : 512 contenus préservés, stabilité des ID, priorité des personnalisations, répétition d'import, doublons dans une réponse, isolation fournisseur/épisodes, rollback, import vide, catégorie non sélectionnée, URL M3U longue avec identité hachée.
- `php -n tests/series_cache.php` : cache frais, isolation fournisseur, changement d'identifiants, secours périmé borné, refus de données invalides et de stockage en racine web.
- `php -n tests/revalidation.php` : contrat de notification, TLS, délais, statuts d'erreur, accusé de réception et secret manquant ; transport simulé.
- Analyse de syntaxe PHP et `git diff --check`.

Non exécutés : suite UI navigateur, migrations et verrouillages MySQL réels, benchmark SQL, lecture média réelle/Range, essais fournisseurs Xtream/Stalker/M3U, app externe. Tests SQLite ne prouvent pas le comportement des DDL/verrous MySQL.

## Points de reprise

- `19a264a` : audit, schéma sans données, migrations explicites et accès sensibles.
- `90420f9` : import incrémental, ID et personnalisations protégés, tests 512.
- `3916a9b` : cache et épisodes à la demande, diagnostic local, visibilité cohérente API/lecture/playlist.
- Commit de documentation suivant : bilan précis et procédure de reprise.

Prochaine étape : valider la migration et les verrous sur une **copie MySQL restaurée**, puis construire le sélecteur individuel/formats/qualités. Ensuite, ajouter un modèle additif de groupes et sources : ID canonique existant conservé, validation manuelle des candidats, principal/secours/désactivé, résolveur partagé avec tests de lecture et d'API. Enfin obtenir le contrat/code de l'app pour une révision de catalogue et une synchronisation mesurée. Conserver ces évolutions dans `gpanel-v2`, avec un commit et des tests pertinents à chaque étape. Aucune fusion ni publication de site prévue.
