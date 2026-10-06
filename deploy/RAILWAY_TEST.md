# Déploiement Railway de test — point de reprise

Source exclusive : branche `gpanel-v2`, base de reprise `e9aa013`. Aucun déploiement de `main`.

Image officielle `php:8.3-apache`, extensions PDO MySQL/curl/mbstring, Apache rewrite et `.htaccess` à la racine `/`. Le schéma sans données est copié dans `/opt/gpanel` hors racine web. Le contexte Docker exclut `.git`, environnements, dumps de sauvegarde et tests. Aucun secret historique ajouté à l'image.

Variables du service applicatif :

| Variable | Valeur |
|---|---|
| `PANEL_TEST_ENV` | `1` |
| `MYSQLDATABASE` | `gpanel_v2_test` |
| `MYSQLHOST` | référence au service MySQL neuf, réseau privé |
| `MYSQLPORT` | référence MySQL, généralement `3306` |
| `MYSQLUSER` | référence MySQL du service neuf |
| `MYSQLPASSWORD` | référence MySQL du service neuf ; jamais copier dans Git |
| `TEST_ADMIN_PASSWORD` | nouveau secret aléatoire de test, minimum 16 caractères |
| `TEST_CLIENT_PASSWORD` | nouveau secret aléatoire de test différent, minimum 16 caractères |
| `PANEL_STATE_DIR` | `/var/lib/gpanel/state` (hors racine web) |
| `PORT` | fourni par Railway ; `8080` par défaut |

Le démarrage refuse une base au nom différent et une base préexistante non marquée comme fixture. Il crée uniquement un schéma neuf et 1 admin (`gpanel-test-admin`), 1 client (`gpanel-test-client`), 1 fournisseur factice en boucle locale, 3 catégories et 4 contenus synthétiques. Les redémarrages conservent les données. Une initialisation DDL partielle est refusée à la reprise plutôt que d'écraser la base : diagnostiquer cette base de test seulement, ne jamais remplacer une base externe.

Ensuite la migration V2 est exécutée deux fois pour vérifier l'idempotence, puis les moteurs InnoDB, index, ID synthétiques et comptes de test sont contrôlés. Apache n'est lancé qu'après ces contrôles. `/health.php` ne devient prêt qu'après initialisation. Le fournisseur de métadonnées factice est accessible uniquement en boucle locale par Apache ; aucun téléchargement ni fournisseur réel. Les redirections de lecture ne représentent pas un test média réel.

Le cache local est éphémère sans volume : après redéploiement, il sera reconstruit à la demande. La base MySQL doit avoir son propre volume Railway. Aucun besoin de rendre MySQL public pour l'application.

## Résultat vérifié le 6 octobre 2026

URL : https://gpanel-v2-test-test.up.railway.app

Projet neuf `G-PANEL V2 TEST` (`9ac59969-9caa-4ae8-801a-6c5092a809cc`), environnement `test` (`d94029b7-6756-41b3-9417-c930562d9fd0`), application `gpanel-v2-test` (`948b1376-c566-46e4-a45b-1ff140af16a3`), MySQL neuf (`97bd10fb-b3c1-4c48-bdc9-c737b76fe689`) avec volume dédié. Les autres projets sont restés intacts. Déploiement CLI du code local `gpanel-v2` au commit `527e394`, déploiement réussi `3c226723-4893-4469-956a-7bfc2cbac785`. Pas de liaison GitHub ni d'autodéploiement de main.

Railway ne permet plus aux nouveaux services d'activer railway.json. Les paramètres sont donc enregistrés dans ce seul service : Dockerfile autodétecté, contrôle `/health.php`, redémarrage On Failure limité à 3 reprises, port public 8080. La commande Docker démarre le script. Apache utilise explicitement `deploy/httpd.conf`, le handler PHP et un seul MPM prefork ; les réglages sont appliqués au démarrage. Options MultiViews explicitement autorisées pour .htaccess. Aucun changement de fonctionnalité V2.

**Exécuté sur Railway** : construction PHP 8.3/Apache ; initialisation exclusivement synthétique ; migration V2 deux fois ; index InnoDB, ID et authentification contrôlés. Plusieurs redémarrages ont conservé les 4 contenus et 3 catégories de test. Le serveur Apache n'a démarré qu'après la vérification SQL. Réponse PHP de santé JSON validée, pas seulement un statut HTTP.

**Tests HTTP réussis** avec `deploy/smoke_test.py` : routes sensibles refusées, diagnostic anonyme refusé, mauvais identifiants Xtream refusés, connexion admin fictif et diagnostic authentifié, 6 listes Xtream Live/VOD/séries, métadonnées VOD via fournisseur local factice, épisodes à la demande appelés deux fois avec ID 4 stable, playlist M3U de 3 entrées, réécriture Apache Live/VOD/séries avec redirections 302 vers la boucle locale non suivies. Page de connexion également vérifiée dans le navigateur. Le test traite explicitement la redirection de connexion et les en-têtes HTTP sans distinction de casse.

**Non testé / hors périmètre** : lecture média réelle, fournisseur externe, compatibilité de l'app réelle, charge importante, import massif, fonctionnalités V2 encore non implémentées dans le chantier initial. Les 512 contenus du jeu de régression précédent n'ont pas été copiés sur Railway ; les données existantes n'ont pas été touchées. Le dump historique et ses comptes n'ont pas été importés. Aucun service Cloudflare modifié, aucune production déployée.

Accès admin `gpanel-test-admin`, client `gpanel-test-client` : nouveaux secrets indépendants enregistrés dans Railway et remis uniquement dans un fichier local d'accès, jamais dans Git. État/cache hors racine web `/var/lib/gpanel/state` ; cache éphémère, MySQL persistant. Les anciens secrets présents dans l'historique Git restent à renouveler avant tout futur usage réel.

Reprise : utiliser uniquement les identifiants du projet et de l'environnement ci-dessus. Pour un redéploiement, vérifier la branche `gpanel-v2`, conserver les variables et MySQL, lancer `railway up` avec projet/environnement/service explicites puis relancer le smoke test. Ne pas utiliser le dump historique, main ou les anciens services. Les fixtures marquées sont conservées ; une base non marquée est refusée. Aucun autre travail requis pour l'accès de test demandé.
