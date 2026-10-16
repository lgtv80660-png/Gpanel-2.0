# Validation FULL — résultats réellement exécutés

Date : 16 octobre 2026. **102/102 commandes réussies**, dont 83 contrôles de syntaxe PHP et deux contrôles JavaScript. Les nombres ci-dessous sont les contrôles annoncés par chaque suite ou ses lignes PASS ; les suites peuvent couvrir des cas communs. Ils ne constituent pas une preuve de lecture vidéo réelle.

| Suite / commande | Contrôles | Résultat |
| --- | ---: | --- |
| sources_backups.php | 50 | réussie |
| sources_suggestions.php | 12 | réussie |
| source_relay.php | 38 | réussie |
| vod_sources.php | 47 | réussie |
| vod_transport.php | 26 | réussie |
| movie_groups_smoke.php | — | réussie |
| content_v2.php | 39 | réussie |
| import_v2.php | 21 | réussie |
| import_selective.php | 48 | réussie |
| series_cache.php | 7 | réussie |
| revalidation.php | 18 | réussie |
| JavaScript syntax sources-backups.js | — | syntaxe valide |
| JavaScript syntax vod-sources.js | — | syntaxe valide |
| sources migration PLAN | — | plan sans connexion ni application |
| VOD migration PLAN | — | plan sans connexion ni application |
| sources_http.py | 34 | réussie |
| full_update_http.py | 36 | réussie |
| vod_proxy.py | 11 | réussie |
| run_regression.py | 137 | réussie |

Les sorties intégrales et codes de retour sont dans `test-results/validation.txt` et `test-results/results.json`. Exécution PHP avec extensions explicites PDO SQLite/mbstring/intl ; curl pour le test binaire localhost. Les scripts historiques qui simulent curl sont exécutés sans charger curl. Python pilote les fixtures ; Node vérifie les scripts d’interface.

## Ce que cela valide

- Rôles indépendants, suggestions jamais enregistrées seules, conflits de révision, rollback, maintien des IDs et des groupes existants, sources désactivées conservées.
- 133 hosts et un groupe films de 110 sources acceptés dans les fixtures, sans les anciennes limites métier de quatre backups ou 100 membres.
- Sources SQL et VOD SQL réelles traduites en dialecte SQLite pour les fixtures, contraintes et opérations de configuration testées. Les plans CLI n’ouvrent aucune connexion.
- Live HLS : URI opaques, segments/key/map, refus SSRF, limites de réponse, Range, changement de génération, temporisation, signal explicite et observation de playlist figée. L’ancien chemin Live par défaut reste dans un fichier live.php identique à la base.
- VOD : associations explicites, numéros de saison/épisode et saisons spéciales, IDs Xtream distincts, refus des mauvais épisodes, variants ambigus non choisis, URLs manuelles masquées, fixation du fichier, Range validé et aucun mélange après émission des octets.
- Contrôles client/pack, changement de credentials, changement de politique, retrait de correspondance, capacité malformée, accès administrateur/viewer/anonyme et CSRF dans de vrais endpoints PHP locaux.
- Transfert binaire avec curl réel entre deux serveurs localhost jetables : erreurs, redirections, Range, HEAD et transfert tronqué. Le transport de test mappe explicitement les cibles synthétiques vers localhost ; aucune exception loopback n’est ajoutée à la politique de destination en production.
- Suite historique de catalogue : authentification existante, import, contenus personnels, métadonnées, exclusions et préservation des données. Ses fixtures ont été complétées avec les rôles/packs actuels et ses attentes adaptées au contrat opaque VOD et aux refus 403 existants. Le code d’authentification n’a pas été modifié pour les faire passer.

## Correctifs de reproductibilité

La fixture films historique ne créait pas content_settings ; elle est maintenant complète. La suite HTTP historique manquait de tables de rôles/packs et attendait des redirections VOD désormais remplacées par des sessions opaques. Le cache séries gère explicitement le DOCUMENT_ROOT vide du CLI ; un contrôle d’écriture réelle est utilisé sur Windows lorsque is_writable(directory) ne reflète pas les droits effectifs. La politique de répertoire privé hors web est conservée.

Les tests ont aussi couvert et corrigé la forme JSON des saisons spéciales (episodes reste un objet) et le cas d’une tranche binaire Range commençant par un caractère ressemblant à du JSON. Les anciens échecs de diagnostic ne sont pas présentés comme des réussites antérieures ; ce rapport décrit la version consolidée finale.

## Validations restantes avant toute décision de déploiement

1. MySQL réel sur restauration locale : syntaxe moteur/version, CHECK, verrous concurrents, schéma existant, reprise après DDL partiel et sauvegarde/restauration. Aucun serveur MySQL n’a été utilisé dans cette mission.
2. Fournisseurs autorisés et médias décodés : durée complète, audio/codec/édition, redirections réelles, Range et changement de source avant lecture. Les « vidéos » des fixtures sont des octets synthétiques.
3. Lecteurs réels : suivi du 307, réutilisation de la capacité opaque pour les seeks, requêtes concurrentes et expiration. HLS VOD et Stalker VOD ne sont pas ajoutés ; les entrées correspondantes sont refusées plutôt que redirigées avec des secrets.
4. Intégration du lecteur GTV stable au contrat HLS de réouverture ; aucune modification GTV Engine, GPlayer ou frontend. Aucune affirmation de Live réellement fonctionnel dans GTV.
5. Stockage partagé et comportement multi-instance, charge longue et plafonds techniques. Docker/Apache/Railway n’ont pas été exécutés ni déployés. L’interface a été testée via HTTP et les scripts vérifiés ; aucune validation visuelle navigateur n’est revendiquée.

Il n’existe plus de blocage empêchant de livrer cette candidate cohérente pour validation locale. Ces points empêchent de la qualifier de version validée en production ou en lecture réelle.
