# G-PANEL 2.0 FULL UPDATE — LIVE + VOD

Version consolidée du 16 octobre 2026, **candidate à la validation locale**. Projet complet, pas un patch. Aucun déploiement ni changement de base réelle effectué. Commencer par `MISE_A_JOUR_LOCALE.md`, puis consulter `VALIDATION_FULL.md` et `GTV_CONTRACT_FULL.md`.

## Fonctionnalités livrées

- Un point d’administration `sources_manage.php` : hosts, rôles indépendants LIVE/FILMS/SÉRIES, accès aux chaînes, films, séries et épisodes. Navigation depuis l’éditeur de contenu et le menu Sources & Backups existants.
- Live existant consolidé : choix manuel, liens multiples, suggestions confirmées, priorités, relais HLS opt-in, capacités opaques, temporisation, détection de playlist figée et réouverture sans mélange d’origines. Activation/désactivation conservant les liens personnalisés.
- Films : réutilisation des groupes et suggestions existants, ancre publique conservée, priorités de lecture distinctes de la représentation catalogue, liens manuels et backups activables. Les anciens groupes sont lus directement, sans recopie automatique.
- Séries : associations de séries confirmées ; backups d’épisodes explicitement confirmés avec même saison et numéro. Les IDs Xtream de chaque fournisseur et son extension sont utilisés. Les variantes d’audio, codec et édition restent un choix humain explicite. Pas de fusion de catalogue ni d’équivalence déduite d’un ID distant.
- Transport VOD commun : binaire progressif côté serveur, redirections et identifiants fournisseur masqués, validation DNS publique à chaque destination et épinglage, TLS, Range simple vérifié, capacités opaques liées au client et au contenu autorisé. Fallback avant les premiers octets seulement.
- Pas de plafond métier sur hosts, liens ou taille d’un groupe films. Restent les limites techniques des requêtes, SQL, mémoire et ressources. L’affichage du catalogue est paginé ; cela ne plafonne pas les données.
- Aucun Redis. Aucun nouveau sondage automatique ni téléchargement de catalogues depuis les pages de gestion. Le cache de métadonnées séries préexistant est réutilisé pour économiser les requêtes.

## Utilisation

1. Les hosts importés restent NEUTRAL jusqu’au choix administrateur. Définir un PRIMARY et des ordres BACKUP distincts pour chaque type.
2. Films : les groupes confirmés dans `movie_duplicates.php` continuent à servir. Pour modifier la source de lecture sans changer la carte publique, utiliser Sources & Backups sur **l’ID public existant**. Le principal catalogue d’un groupe existant ne peut plus être déplacé par l’écran de doublons ; la priorité de lecture reste modifiable dans Sources & Backups.
3. Séries : ouvrir une série, confirmer l’ID local de sa série équivalente, puis ouvrir chaque épisode et confirmer son alternative. Même série confirmée + même saison + même épisode sont exigés. Les variantes ne sont jamais choisies seules.
4. Les imports Xtream futurs conservent les saisons/numéros fournis sans écraser les valeurs existantes. Les épisodes déjà importés sans numérotation doivent être renseignés explicitement dans l’écran d’épisode ou complétés par le chargement habituel des métadonnées. Un conflit de parent ne réaffecte pas un épisode public.
5. Mode personnalisé : première ligne PRIMARY, suivantes BACKUP. Désactiver PRIMARY bloque la lecture. Mode hosts : seuls les liens déjà confirmés ci-dessous sont ordonnés par les rôles FILMS ou SÉRIES ; un host ambigu est ignoré, un PRIMARY ambigu bloque. Les URLs manuelles utilisent le mode personnalisé.
6. Les liens manuels VOD existants sont masqués dans l’interface. Laisser le champ vide les conserve ; saisir une nouvelle URL exige une nouvelle confirmation.

## Limites de lecture à connaître

- Live local HLS désactivé par défaut. La route historique Live reste disponible. En mode local HLS, les entrées `.ts` continues restent non prises en charge et échouent explicitement ; utiliser `.m3u8` et un lecteur compatible avec la réouverture.
- VOD : MP4/MKV et autres réponses binaires progressives acceptées. Les playlists HLS VOD et documents HTML/JSON/XML sont refusés ; aucun nouveau lecteur ou pipeline VOD HLS n’a été inventé. Les fournisseurs VOD Stalker ne sont pas pris en charge par ce transport.
- Aucune reprise transparente entre deux fichiers : après le début de la lecture, la session reste sur le fichier choisi. Un seek utilise cette même URL opaque. Un seek initial à une position non nulle sur l’URL publique sans session reçoit 409. Un lecteur qui recommence chaque Range sur l’URL publique doit être validé/adapté avant utilisation.
- Une longueur ou un validateur HTTP modifié invalide le transfert épinglé. Sans validateur fournisseur, le contenu binaire n’est pas authentifié. HTTP 200 ne prouve pas le décodage ni l’absence d’écran noir.
- Une capacité VOD expire après six heures ; une seule requête de transfert par capacité est exécutée à la fois. Une requête concurrente reçoit 503/Retry-After. Une nouvelle requête recontrôle le client actif, les credentials, le pack, l’ancre et la politique ; ces contrôles n’interrompent pas rétroactivement un transfert déjà en cours.
- Les alternatives sont des transports explicitement approuvés du contenu canonique : le droit porte sur son ID public. Elles ne créent aucun droit de parcourir les autres contenus du fournisseur. La visibilité et l’activité de chaque alternative et de sa série parente sont recontrôlées.

## Périmètre de la livraison

Le ZIP inclut le projet, les migrations additives, les tests, leurs sorties et le manifeste SHA-256 de chaque fichier. Il exclut `.git`, `.env`, dumps de données, états de sessions et sauvegardes obsolètes `.bk/.dez/.old`. Le `config.php` fourni attend une configuration explicite ; il ne contient aucun identifiant réel.

Les documents antérieurs comme SOURCES_BACKUPS.md, FAILOVER_LIVE.md ou les résumés V2 sont conservés comme historique. Ce document et les trois documents FULL font foi pour cette consolidation.

GTV Engine, GPlayer, frontend GTV, authentification et publicités n’ont pas été modifiés. Railway et Cloudflare n’ont pas été contactés ou changés. Aucun commit, push, déploiement, redémarrage ou migration réelle n’a été exécuté.
