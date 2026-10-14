# Failover Live — 14 octobre 2026

Travail effectué dans une nouvelle copie du chantier Gpanel-2.0-sources après
le correctif DZtv/FRtv. Aucun ancien clone, GTV, fournisseur, base de production,
déploiement ou push modifié. Aucun paramètre de production activé.

## Chemin constaté dans le code local

GTV `app/live/page.tsx:1102` produit `/api/hls?id=<stream_id>` et transmet une
seule source à LivePlayer (ligne 1260). `app/api/hls/route.ts:25` construit
`<base de session>/live/<client>/<password>/<id>.m3u8`. Si cette base désigne
G-PANEL, le routeur du panneau appelle live.php. La base effective d'une session
déployée n'a pas été vérifiée : aucune lecture des secrets ni connexion externe.

Sans opt-in, live.php répond 302 vers le fournisseur : le panneau ne voit plus
les transferts ni les erreurs de lecture. Les backups ne peuvent pas basculer
une lecture déjà redirigée.

Avec `PANEL_SOURCES_LOCAL_HLS=1`, le panneau conserve les transferts HLS dans
une session privée (`PANEL_SOURCES_STATE_DIR` hors racine web). Le plan existant
utilise les liens PRIMARY/BACKUP personnalisés par chaîne, sinon les rôles des
hosts et uniquement les correspondances strictes non ambiguës. Les suggestions
approximatives restent soumises à confirmation manuelle. L'ID public est conservé.

## Modification minimale

Le relais détectait déjà les erreurs transport/HTTP/manifest/segment et avançait
vers le prochain backup avec une génération différente, un délai de 3 secondes
et suppression des anciennes ressources. Ajout : une playlist média Live
inchangée pendant au moins max(30 secondes, 3 × TARGETDURATION) devient une panne,
même en HTTP 200. La séquence et les URI de segments déterminent le progrès.
Chaque playlist est observée séparément, avec au plus 32 observations par session.
Master playlists et ENDLIST sont exclus. Tout changement d'origine efface aussi
ces observations. L'horloge peut être injectée pour les tests déterministes.

La détection se fait lors des requêtes de playlist : aucun daemon ni probe
périodique ajouté. Une image noire avec segments qui progressent nécessite le
signal explicite du lecteur. Les URI renouvelées sans progrès vidéo peuvent
échapper à cette détection ; le relais ne décode pas la vidéo.

## Raccord GTV manquant — aucune modification GTV

La bascule serveur fonctionne, mais la reprise automatique visible dans GTV
actuel n'est pas réalisable avec le seul panneau :

1. `app/api/hls/route.ts` recrée la session depuis l'URL publique à chaque demande
   racine. Il convertit une erreur upstream en 502 et ne relaie pas
   `X-Panel-Reopen`/`Retry-After`. Il doit conserver le token et la génération de
   la session panneau pour un client/chaîne, et traduire les liens de réouverture
   vers une route GTV autorisée. Ne jamais rouvrir l'URL publique pour basculer :
   cela repartirait sur PRIMARY.
2. Cette route réécrit seulement les lignes URI, pas les URI d'attributs KEY/MAP.
   Le raccord doit couvrir playlists imbriquées, clés et maps sans exposer les
   credentials fournisseur ni perdre l'association avec la session.
3. `app/api/hlsseg/route.ts` masque aussi les headers de réouverture et cache
   les réponses une heure. Le raccord doit transmettre le signal de génération
   et empêcher le cache entre origines, y compris sur les sous-playlists.
4. `lib/player/engine.ts` retente `startLoad()` ou `recoverMediaError()` ; il
   n'interprète pas le contrat panneau. Sur 502/409 avec X-Panel-Reopen, il doit
   attendre Retry-After, annuler les requêtes, détruire l'ancien moteur HLS,
   vider l'état média et charger la racine de la nouvelle génération. Un 503
   attend le délai ; épuisement 409 sans changement de génération doit terminer
   les tentatives. Une route GTV doit valider l'URL de reprise contre l'origine
   et le chemin panneau attendus et lier la capacité au client connecté.
5. Pour absence de frames/décodage, envoyer un POST autorisé
   `event=client_black_screen` à la session et génération courante, puis appliquer
   la même réouverture. Aucun segment backup ne doit remplacer un segment ancien.

Ce raccord est nécessaire même si le relais a sélectionné correctement un backup.
Les sessions expirent après 30 minutes ; le cycle de renouvellement et la purge
des fichiers privés doivent aussi être gérés pour une utilisation durable.
Le mode reste HLS uniquement, désactivé par défaut ; TS public reçoit 501.

## Fichiers modifiés par ce travail

- panel_source_relay.php : observations de progrès, horloge injectée, nettoyage.
- live_source_media.php : nettoyage des observations lors du signal client.
- tests/source_relay.php : 13 contrôles supplémentaires, dont délai, progression,
  HTTP 200 figé, backup, générations, épuisement et playlists exclues.
- FAILOVER_LIVE.md : ce compte rendu.

panel_sources.php, sources_manage.php, live.php et le catalogue sont inchangés
par rapport au chantier de suggestions repris.

## Validation

- 38 contrôles relais réussis (25 existants + 13 supplémentaires).
- 50 contrôles Sources & Backups réussis.
- 12 contrôles suggestions réussis.
- 34 contrôles HTTP réussis, SQLite temporaire et transport simulé.
- Suite Content V2 réussie, dont conservation des 512 contenus.
- Syntaxe PHP des trois fichiers exécutables modifiés valide.

Les premières exécutions sous sandbox ont bloqué les sockets locaux et la
vérification Windows is_writable ; les suites relais/HTTP ont ensuite réussi
hors sandbox dans cette copie isolée. Aucun contrôle de sécurité du relais
n'a été retiré. Pas de validation avec vidéo/fournisseur réel, MySQL réel ni
lecture GTV. La continuité de décodage et le raccord GTV restent à valider.
