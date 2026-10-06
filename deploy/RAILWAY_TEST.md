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

État à la préparation : syntaxe PHP des scripts vérifiée ; image Docker/Apache et migration MySQL **pas encore exécutées**. Docker n'est pas disponible sur le poste. Attendre un déploiement Railway réussi pour qualifier ces vérifications. Aucun URL panel actif ne peut être annoncé avant ce point.

À terminer : choisir/créer un projet distinct du service existant GTV4.0 ; ajouter MySQL neuf et service source `gpanel-v2` ; saisir les références et secrets ; surveiller les logs d'initialisation/migration ; générer le domaine après santé prête ; vérifier login, API Xtream/M3U et routes Live/VOD/séries avec les fixtures, sans suivre les destinations média ; enregistrer l'URL et les résultats.
