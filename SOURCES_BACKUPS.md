# Sources & Backups — chantier local

Base officielle : `lgtv80660-png/Gpanel-2.0`, `main`,
`cc17acf1cbf3a524336bcdf931701a80858666f0` (HEAD vérifié le 14 octobre 2026).
Branche de travail : `sources-backups-local`. Point de retour :
`return-before-sources-cc17acf`. Les anciens clones n'ont pas été modifiés.

## Ce qui fonctionne dans le panel

- Menu Sources & Backups, avec accès à la gestion/import des hosts existante.
- Rôles manuels indépendants LIVE / FILMS / SÉRIES, aucun rôle par défaut.
- Un PRIMARY maximum par type ; BACKUP avec ordres positifs uniques ; NEUTRAL.
- Bouton dans les lignes Live de `editor.php` et onglet Sources de `content_edit.php`.
- Surcharge par chaîne : première ligne principale, suivantes en ordre de secours,
  réordonnancement, suppression, lien manuel ou chaîne importée choisie par ID.
- Pas de plafond applicatif de backups ; taille POST, `max_input_vars` et ressources
  serveur restent des limites. Ne pas augmenter ces limites sans mesure.
- Correspondances proposées, jamais ajoutées seules : normalisation Unicode,
  ponctuation, casse, tags qualité/codecs ; langue/pays, +1 et numéros conservés.
  Qualité estimée depuis le titre. Pas de fuzzy matching risqué des événements,
  ni de fusion Films/Séries. Plusieurs candidats dans un host sont ambigus :
  la résolution globale ignore ce host jusqu'au choix manuel dans la chaîne.
- Admin `catalog:edit`, CSRF existant, audit avec codes sans secrets, no-store,
  referrer désactivé. Hosts identifiés par ID ; credentials exclus des options.
  L'aperçu de l'origine masque chemin et paramètres, donc user/password Xtream.
- Les anciens remplacements `content_settings` restent séparés et sont signalés.
  Les imports ne touchent pas les nouvelles tables et aucun ID catalogue ne change.
- Révisions optimistes + verrou transactionnel global sur MySQL évitent les
  sauvegardes concurrentes qui écrasent silencieusement une configuration.

## Migration et retour arrière

Schéma inspecté : `fournisseurs`, `streams`, `categories`, `content_settings`.
Quatre tables InnoDB additives dans `sources.sql`, contraintes uniques/ordres,
aucun ALTER, aucune donnée catalogue supprimée. Références applicatives validées
avant sauvegarde et résolution ; pas de FK ajouté sur les tables historiques.
Les suppressions historiques peuvent laisser une référence : elle échoue fermée
pour PRIMARY et est signalée/ignorée pour un backup. MySQL 8.0.16+ applique CHECK ;
les versions antérieures nécessitent aussi la validation applicative existante.

Sur une **copie locale restaurée**, avec environnement MYSQL* explicitement dirigé
vers cette copie et sauvegarde testée :

```text
php migrate_sources.php                           # plan, sans connexion DB
php migrate_sources.php --apply --backup-confirmed
```

La migration n'est jamais appelée au démarrage ou par HTTP. Elle vérifie les
colonnes nécessaires et InnoDB ; aucun changement automatique de moteur.
DDL MySQL auto-commit : interruption possible entre deux CREATE. La reprise
réexécute les CREATE IF NOT EXISTS. Inspecter les tables avant reprise.

Retour arrière après **export des quatre tables de configuration Sources** :

```text
php migrate_sources.php --down --apply --backup-confirmed --discard-source-settings
```

Cela supprime seulement les quatre nouvelles tables, donc perd les nouveaux
réglages si non exportés. Les médias, comptes, hosts et remplacements historiques
restent intacts. Restaurer ensuite l'export pour réinstaller les réglages.
Les fichiers peuvent être retirés par retour au commit de base dans cette copie
isolée, après sauvegarde du chantier. Ne pas exécuter un reset sur un ancien clone.

## Résolution et relais HLS local, désactivé par défaut

`sources_plan()` produit un ordre avec URLs **serveur uniquement**. Ne jamais
renvoyer cet objet en JSON au client, dans une playlist ou dans un Location.
La politique héritée exige un PRIMARY défini et une correspondance non ambiguë.
Un mode personnalisé ignore entièrement les rôles globaux.

`sources_failover()` est une machine d'état testée et raccordée au relais local.
Une session authentifiée
commence sur PRIMARY ; erreurs connexion/timeout/HTTP/manifest/segment ou signal
client explicite avancent vers le suivant. Délai de trois secondes, liste des
sources essayées, aucun retour en boucle au PRIMARY. Épuisement explicite.
Une nouvelle session explicite ou une nouvelle révision recommence la sélection.
Le relais doit conserver cet état hors navigateur, protéger l'association
client/chaîne/session et appliquer les permissions habituelles avant résolution.

Un changement d'origine augmente `generation` et exige une **réouverture** :
annuler les requêtes en cours, vider manifest/segments/clés/maps et cache de
l'origine précédente, rouvrir la playlist Live. Ne jamais substituer un segment
d'un autre host dans une playlist en cours. Aucun mélange de séquences HLS.

`sources_url()` refuse schemes non HTTP(S), userinfo, ports hors 80/443, contrôles,
IP privées/réservées/multicast, DNS mixte ou absent. `sources_probe()` est un
transport de diagnostic optionnel : DNS validé puis épinglé avec CURLOPT_RESOLVE,
proxy désactivé, TLS vérifié, timeouts et taille bornés, redirections refusées.
Chaque URL HLS (playlist, segment, clé, map) devra être validée/épinglée séparément
par le futur relais ; une validation à la sauvegarde ne suffit pas contre rebinding.
Ce probe n'est pas un lecteur, et ne prouve pas la santé des segments vidéo.

`panel_source_relay.php` fournit le raccordement HLS opt-in ; `live.php` le
sélectionne **seulement** si `PANEL_SOURCES_LOCAL_HLS=1`. Valeur absente = chemin
historique inchangé. Les contrôles client/pack existants précèdent l'ouverture ;
`live_source_media.php` recontrôle client actif et droit du pack à chaque ressource.
Une révision de politique invalide la session précédente. Les liens publics
`/live/<client>/<password>/<id>.m3u8` restent stables. Une URL manuelle ou un host
choisi remplace effectivement l'origine dans ce mode local.

Playlist, sous-playlist, segment, key et map sont réécrits vers des IDs opaques.
Aucune URL de host ni header upstream n'est exposé. Chaque transfert valide DNS,
l'épingle, vérifie TLS et revalide les redirections (maximum trois, sans boucle).
Les Range simples sont servis sur le contenu borné ; multipart Range refusé.
Manifests limités à 1 MiB, segments/maps à 16 MiB ; pas de streaming TS continu.
Un segment surdimensionné échoue explicitement, jamais transmis partiellement.

Pour un test LOCAL uniquement : créer un dossier privé existant, hors racine web,
accessible en écriture au seul processus PHP ; définir `PANEL_SOURCES_STATE_DIR`
vers ce dossier et `PANEL_SOURCES_LOCAL_HLS=1`. Aucun de ces paramètres n'est
configuré en production par ce chantier. Le dossier contient des URLs secrètes,
doit être exclu des exports publics et sauvegardes diffusées. Sessions de 30 min,
verrou exclusif non bloquant par session, limite de 1000 fichiers ; organiser leur
purge après expiration dans ce dossier dédié. Au plafond, le relais échoue fermé.

Sur échec réel, le relais avance la priorité, efface les ressources de l'ancienne
origine et renvoie 502/409 avec `X-Panel-Reopen` (URL opaque), `Retry-After: 3`.
Le lecteur doit **rouvrir** cette URL après temporisation et réinitialiser son HLS.
Le relais ne renvoie jamais un segment de backup dans l'ancienne playlist.
Ce contrat exige un lecteur compatible pour une bascule automatique en lecture ;
GTV n'est pas modifié ici. Une réouverture normale de l'URL publique crée une
nouvelle session et repart de PRIMARY, donc n'est pas équivalente à ce contrat.

HTTP 200 avec écran noir n'est pas détectable de manière fiable par le panel.
Le futur contrat GTV doit signaler explicitement l'échec de décodage/absence de
frames pour une session autorisée. `client_black_screen` teste la transition,
L'endpoint local accepte POST `event=client_black_screen` sur l'URL de session
avec génération courante ; le token est une capacité temporaire à protéger,
pas un identifiant fournisseur. Le contrat est testé ; aucun changement GTV livré.

**Limites restantes** : aucune activation ni validation avec un host réel.
Le mode opt-in refuse les entrées publiques `.ts` avec 501, sans redirection
révélant des credentials ; les hosts Xtream sélectionnés sont demandés en `.m3u8`.
Les anciens remplacements restent dans `content_settings` et ne sont pas migrés
automatiquement dans les nouvelles politiques. Films/Séries, auth, clients,
synchro et VOD restent inchangés. Hors mode opt-in, la redirection Live historique
reste présente ; ce chantier ne prétend pas avoir supprimé cette limite existante.
Restent les tests MySQL réels, streaming TS continu, lecteur GTV compatible
avec la réouverture/signal explicite, et validation média complète HLS réel.

## Validation

Résultats exécutés le 14 octobre 2026 : 50 contrôles Sources & Backups réussis,
25 contrôles relais réussis, 30 contrôles HTTP réussis, suite existante
`content_v2.php` réussie (dont les 512 contenus conservés), syntaxe PHP et JavaScript
valide, `git diff --check` propre. Interface inspectée dans le navigateur local :
ajout d'un backup, passage automatique en surcharge personnalisée, choix d'un
host détecté et réordonnancement. Le serveur de fixtures a été arrêté ensuite.

Chemin réellement travaillé :
`C:\Users\Playe\Documents\Codex\2026-10-14\referenced-chatgpt-conversation-this-is-an-9\work\Gpanel-2.0-sources`.
Remote : `https://github.com/lgtv80660-png/Gpanel-2.0.git`.
Le clone initial `C:\Users\Playe\Gpanel-2.0` était à
`e2ed309df15ac31eb3c2346c455f86f09d016b18`, propre et plus ancien ; il reste intact.
Le SHA base local correspond exactement au HEAD officiel relevé par ls-remote.
La branche locale main reste à ce SHA ; seul `sources-backups-local` reçoit le
chantier. Aucun statut de déploiement n'est affirmé.

Fichiers ajoutés : `sources.sql`, `migrate_sources.php`, `panel_sources.php`,
`sources_manage.php`, `assets/sources-backups.js`, `tests/sources_backups.php`,
`tests/sources_http.py`, `SOURCES_BACKUPS.md`, `panel_source_relay.php`,
`live_source_media.php`, `tests/source_relay.php`.
Fichiers existants modifiés : `editor.php`, `content_edit.php`, `panel_visual.php`
(liens vers la nouvelle gestion), `live.php` (raccordement local sous drapeau,
désactivé par défaut).

`tests/sources_backups.php` : schéma additif réel traduit en dialecte SQLite,
rôles/priorités, ambiguïtés, conservation ID, conflits, rollback transactionnel,
SSRF, masquage, état de bascule et signal écran noir explicite.
`tests/sources_http.py` : fixtures jetables, admin/viewer/anonyme, CSRF,
rendu, sauvegardes, secrets absents, échappement HTML, absence de migration auto,
retour arrière du schéma, Live URL stable, client révoqué, changement d'origine
et contrat de réouverture (transport simulé).
`tests/source_relay.php` : URI HLS, SSRF de redirection, budgets de réponse,
tokens privés, expiration, Range et impossibilité de mélanger deux origines.
`tests/content_v2.php` : non-régression des imports,
512 contenus, remplacements et personnalisation existants.

Migration MySQL réelle, verrous concurrents réels, lecture de médias, curl/DNS
réels et GTV ne sont pas validés par les fixtures SQLite. Aucun fournisseur ou
DB de production n'est contacté. Aucun push ni déploiement.
