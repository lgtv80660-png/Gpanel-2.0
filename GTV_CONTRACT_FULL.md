# Contrat préparé pour GTV — aucune modification GTV

## Live consolidé

Le relais opt-in garde l’URL publique `/live/<client>/<password>/<id>.m3u8`. Les ressources HLS sont réécrites vers des capacités temporaires et opaques ; les URLs et credentials fournisseur restent côté serveur. Le correctif `X-Panel-Root` du prototype GTV ↔ G-PANEL est repris et exposé par CORS avec `X-Panel-Reopen`, `Retry-After` et `Content-Range`.

En cas d’échec transport, segment, manifest ou playlist média figée, l’état passe au backup. L’ancienne génération et ses ressources deviennent inutilisables. Le client doit arrêter/détruire son moteur HLS, annuler les requêtes, attendre Retry-After puis rouvrir X-Panel-Reopen. Il ne doit jamais substituer un segment d’un backup dans la playlist précédente.

Un écran noir avec HTTP 200 exige un signal explicite du client ; le relais ne prétend pas décoder la vidéo. Le POST existant `event=client_black_screen` utilise la capacité et la génération courantes. Un simple rechargement de l’URL publique crée une nouvelle session et n’équivaut pas à la réouverture de la capacité existante.

Le lot conserve les correctifs de détection de playlist figée, de purge des ressources/progrès au changement d’origine et de temporisation. Tests simulés du relais exécutés ; **aucune intégration réelle au lecteur GTV stable ni lecture décodée validée**.

## VOD

Les entrées publiques `/movie/<client>/<password>/<id>.<ext>` et `/series/<client>/<password>/<id>.<ext>` restent stables. Après les contrôles client/pack existants, elles émettent un 307 vers `/vod_source_media.php?s=<capacité aléatoire>` : cette URL ne contient aucun identifiant de fournisseur.

Le lecteur doit suivre cette redirection et conserver son URL opaque pour les requêtes GET/HEAD/Range suivantes. Une capacité est liée au client, à son état de credentials, à l’ID public canonique et à l’empreinte de la politique résolue. Les droits et disponibilités sont recontrôlés à chaque requête. Les sessions VOD ne sont pas interchangeables avec les sessions Live.

Avant les premiers octets seulement, un échec peut avancer vers le backup. Dès le début de la réponse ou un HEAD accepté, la source est épinglée. Un échec ultérieur ne remplace jamais le fichier ; une nouvelle lecture explicite peut être nécessaire. Un changement de configuration invalide l’ancienne capacité. Les Range multiples, incohérents ou ignorés par l’origine sont refusés.

Ce contrat est préparé côté G-PANEL et testé avec des fixtures. Aucun adaptateur, lecteur, frontend GTV, GTV Engine ou GPlayer n’est livré ou modifié ici. La compatibilité réelle des lecteurs doit être validée avant déploiement.
