# G-PANEL V2 — opérations

Ces modules réutilisent panel_security, panel_layout, content_playback et le modèle Packs. Aucune migration n'est exécutée par HTTP. Aucun endpoint de lecture existant ne change de contrat. config.php tolère désormais une réinclusion quand RBAC a déjà ouvert la connexion, sans redéfinir le helper ni créer un second PDO. Dockerfile copie uniquement le nouveau operations.sql nécessaire au démarrage.

## Migration et comptes

`php migrate_packs.php` applique désormais Packs et les tables operations.sql sous le verrou existant. `migrate_v2.php --apply --backup-confirmed` applique les mêmes tables. Le démarrage Railway utilise déjà migrate_packs.php, donc aucun changement Docker/startup requis. Faire une sauvegarde vérifiée avant migration. Les tables métier et les mots de passe existants restent intacts. Les comptes présents lors de la première migration reçoivent super_admin pour préserver leur accès ; réduire ensuite leurs droits dans Administration système. Un marqueur en panel_settings empêche qu'une migration ultérieure accorde des droits à un nouveau compte sans rôle. Les comptes sans rôle sont refusés après migration.

RBAC relu côté serveur à chaque requête : super_admin toutes permissions ; admin gestion panel/catalogue, statistiques, monitoring, paramètres ; operator lecture, édition/import catalogue et contrôles ; viewer lecture panel/statistiques/monitoring. PANEL_ADMIN_PERMISSIONS conserve son rôle de plafond supplémentaire (ajouter les nouvelles permissions si utilisé). La page Administration système est réservée à admin/super_admin, la gestion des comptes à super_admin. Ni le compte connecté ni le dernier super_admin actif ne peuvent perdre leur accès. Les mots de passe nouvellement créés/modifiés sont hachés.

Audit : login accepté/refusé, logout, mutations Packs/Clients/Sources et requêtes administratives sensibles (route et statut HTTP, sans affirmer le succès métier), comptes, paramètres et contrôles. Pas d'identifiant fourni lors d'un login refusé, de contenu POST, de mot de passe, de token, d'IP ni d'URL. Échecs d'audit : message générique dans le journal serveur.

## Mesures réelles

Les entrées autorisées live.php/vod.php/series.php produisent un événement `request`, au maximum un par client/contenu/minute (GET seulement). Ce sont des demandes, pas des lectures réussies ni des sessions. Aucun hook dans un segment HLS. Les Top séries regroupent les épisodes par le vrai series_parent quand disponible. Le Pack de l'événement est celui attribué au client au moment de la collecte. L'UI ne présente ni octets, ni sessions simultanées, ni erreurs du lecteur comme des métriques connues.

G-TV peut envoyer par HTTPS `POST /playback_events.php` avec JSON (4096 octets max) :

```json
{"username":"<client>","password":"<secret>","stream_id":123,"event":"heartbeat","device":"tv","duration_seconds":30}
```

Authentification client existante et contrôle Packs/expiration/catalogue côté serveur. Ne jamais placer les credentials dans la query string ni journaliser le body. Événements : start (lecture démarrée confirmée), heartbeat (delta de secondes réellement regardées, 0..60), error (erreur de lecture constatée), stop. device facultatif : tv/mobile/tablet/desktop/other. Envoyer heartbeat au maximum une fois par minute, avec le delta depuis le dernier envoi ; start/error/stop sans durée. Une seule entrée par client/contenu/type d'événement/minute : plusieurs appareils simultanés peuvent être sous-comptés. Données déclaratives par le lecteur, pas preuve de facturation. Ne pas réessayer un heartbeat dans la minute suivante car le delta serait compté deux fois. HTTP 202 = réception best-effort, pas garantie de persistance ; 401/403 = auth/droits, 422 = contrat, 503 = migration requise. Aucune erreur libre n'est stockée.

Le throttle local utilise APCu si disponible, sinon des petits fichiers à verrou non bloquant dans un répertoire privé temporaire hors webroot (256 shards bornés). L'unicité DB protège plusieurs workers/instances. L'échec de collecte n'interrompt jamais le streaming. Chaque demande authentifiée fait au plus un petit INSERT par minute/contenu/type ; le cache local évite les réinsertions courantes. Aucun User-Agent brut n'est enregistré.

## Monitoring

GET affiche uniquement la base. POST CSRF + monitor:check : une chaîne ou trois chaînes filtrées maximum. TTL par défaut 300 s (60..3600), verrou/lease DB de 45 s empêchant le chevauchement. Relancer respecte le TTL. CLI : `php operations_cli.php monitor 10` ; exécuter régulièrement via cron Railway si besoin, aucune clé publique ni daemon obligatoire. Dix chaînes maximum par invocation. Résultats conservés/dates UTC et indication d'obsolescence.

HEAD, puis GET Range 0-4095 si HEAD refusé (400/403/405/501). GET borné par callback à environ un chunk cURL, timeout transfert total 5 s, connexion 1,5 s, trois redirections maximum. Résolution DNS préalable : seules les IP publiques sont admises et épinglées ; chaque redirection est validée, TLS vérifié, proxy environnement désactivé. Ports 80/443/8080/8443 uniquement. Sources privées/autres ports et Stalker marqués DEGRADED avec code synthétique, aucun secret affiché. DNS peut ajouter du délai selon le résolveur système. HTTP 2xx : ONLINE (DEGRADED au-delà de 2 s), 5xx : DEGRADED, autres : OFFLINE ; ce n'est pas une validation audiovisuelle. Source effective réutilisant content_playback, notamment les overrides. backup_stream_id prépare un lien de secours sans bascule automatique et sans Flussonic.

## Rétention / sauvegardes

`php operations_cli.php purge` retire au plus 5000 événements expirés et 5000 entrées d'audit par invocation. Planifier chaque jour (répéter si backlog). Événements : 90 jours par défaut, 7..365 configurables ; audit : 365 jours. Aucun effacement exécuté lors du déploiement ou de l'ouverture d'une page.

L'interface ne crée aucun dump et n'annonce aucune sauvegarde inexistante. Préparer un stockage privé hors webroot, permissions répertoire 0700/fichier 0600, compte de sauvegarde dédié. Exemple Linux/Railway, si mysqldump disponible :

```sh
umask 077
mysqldump --login-path=gpanel-backup --single-transaction --quick --no-tablespaces --hex-blob --result-file=/var/lib/gpanel/backups/gpanel.sql "$MYSQLDATABASE"
```

Configurer le login-path via mysql_config_editor de façon interactive ; ne pas passer le mot de passe dans la commande. Sur Railway, utiliser un job/outil de sauvegarde ayant le réseau privé DB et un stockage persistant ou objet privé ; ne pas dépendre du filesystem éphémère. Le client mysql n'est pas ajouté à l'image. Sur WAMP, utiliser le mysqldump de l'installation avec --login-path et --result-file pointant vers un répertoire privé hors D:\wamp64\www. Le dump ne doit jamais être servi par HTTP. --single-transaction garantit la cohérence uniquement des tables transactionnelles ; vérifier les moteurs, sinon utiliser une fenêtre de sauvegarde appropriée. Tester une restauration dans une base distincte, contrôler les comptes/lignes et conserver la preuve/date dans le processus opérateur. Aucun bouton de restauration destructive.

Les paramètres exposés sont non secrets et utilisés par les modules. Les secrets et la configuration Railway restent en environnement. L'état configuré/non configuré est seul visible. Aucun paramètre G-TV inconnu n'est inventé.
