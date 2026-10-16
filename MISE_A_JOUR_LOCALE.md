# Validation d’une installation existante — copie locale uniquement

Ces instructions ne constituent pas une autorisation de mise en production. Aucune commande de migration décrite ici n’a été exécutée sur une base réelle pendant la mission.

## Préparer la restauration

1. Sauvegarder les fichiers de l’installation et exporter sa base ; vérifier la restauration dans une **base locale dédiée**. Exporter aussi les réglages Sources et groupes films si présents. Ne jamais charger `mysql.sql` sur une installation existante : c’est un schéma pour une base neuve, pas une migration de mise à jour.
2. Extraire le projet dans un nouveau répertoire. Conserver la configuration privée de l’installation et ses assets personnalisés séparément. Ne pas remplacer aveuglément son `config.php` ou son `.env` par ceux du ZIP. Pour ce test, fournir à la copie une configuration explicitement dirigée vers la restauration locale ; ne pas réutiliser des variables de connexion production héritées du terminal.
3. Prérequis : PHP 8.1+ avec PDO MySQL, curl et mbstring ; intl recommandé pour la normalisation Unicode Live. MySQL 8.0.16+ recommandé pour les contraintes CHECK. Les tests livrés utilisent PDO SQLite et Python 3 ; Node sert au contrôle de syntaxe des scripts d’interface.
4. Vérifier la présence et les colonnes de `streams`, `categories`, `fournisseurs`, `clients`, `content_settings`, `movie_groups`, `movie_group_sources`, `movie_identifiers`, packs et tables opérationnelles. Le schéma `content_settings` doit notamment avoir `series_parent`, `season_number` et `episode_number`. Si ces prérequis manquent, comparer les migrations historiques à la restauration avant toute action ; ne pas exécuter les anciens initialiseurs ou scripts correctifs au hasard.

## Migrations proposées

Deux plans CLI sans connexion sont disponibles :

```text
php migrate_sources.php
php migrate_vod_sources.php
```

- Sources existantes : quatre tables additives dans `sources.sql`. Si elles existent déjà, contrôler leurs colonnes, moteurs et contraintes plutôt que les recréer. La ligne `source_policy_version(id=1)` doit être présente.
- Complément FULL : quatre tables additives dans `vod_sources.sql` : `source_vod_policy`, `source_vod_links`, `source_series_matches`, `source_live_disabled`. Aucun ALTER, UPDATE, DELETE ou import du catalogue dans ce fichier.
- Les groupes films et leurs IDs ne sont pas migrés automatiquement. Les rôles des hosts existants ne sont pas réassignés. Aucun rapprochement automatique des épisodes existants.

**Uniquement après restauration et sauvegarde vérifiées, sur la base locale explicitement sélectionnée**, les commandes prévues sont :

```text
php migrate_sources.php --apply --backup-confirmed
php migrate_vod_sources.php --apply --backup-confirmed --local-copy-confirmed
```

Ces drapeaux sont des confirmations opérateur, pas une détection automatique de production. MySQL DDL auto-commit : une interruption peut laisser une partie des tables créées. Contrôler le schéma avant reprise. CREATE IF NOT EXISTS n’adapte pas une table préexistante incompatible ; le complément FULL vérifie ensuite ses colonnes et moteurs. Aucun changement automatique de moteur et aucune FK ajoutée aux tables historiques.

Les références sont validées par l’application. Une source supprimée ou masquée échoue fermée en PRIMARY et est ignorée en BACKUP ; contrôler ces réglages après toute suppression de contenu. Les contraintes et verrous MySQL réels restent à tester sur cette restauration.

## Stockage privé et lecture

Créer manuellement un répertoire de sessions **hors racine web**, inscriptible uniquement par le processus PHP, puis configurer `PANEL_SOURCES_STATE_DIR` dans le seul environnement de validation. Ne pas placer cet état dans le ZIP, un partage public ou un dépôt. Le répertoire doit être commun aux processus PHP servant une même session ; aucun Redis ajouté. Plusieurs instances sans stockage partagé ne sont pas validées.

Le cache de métadonnées utilise le réglage existant `PANEL_STATE_DIR`. La VOD et le relais Live utilisent des capacités aléatoires ; leur possession donne accès temporaire au contenu autorisé du client. Les journaux HTTP ne doivent pas enregistrer les chemins Xtream, query strings ou capacités complètes.

Les sessions Live existantes expirent après 30 minutes et les sessions VOD après six heures. Le stockage existant refuse les nouvelles sessions au plafond technique de 1000 fichiers JSON ; prévoir une purge opérateur des sessions expirées, avec verrouillage et sans effacer une session en cours. Aucune purge automatique n’est installée par ce lot.

Pour tester le Live consolidé localement, activer explicitement `PANEL_SOURCES_LOCAL_HLS=1` dans cet environnement de test. Ne pas modifier la production pour ce test. Le Dockerfile livré prévoit des répertoires privés, mais **le conteneur n’a pas été construit ni lancé**. Son script de démarrage n’applique plus de migration de base ; celles des packs/opérations restent des actions explicites à traiter selon l’état de la restauration.

## Exécuter les contrôles

```text
python tests/run_full_update.py --php CHEMIN_PHP --ext CHEMIN_EXTENSIONS --http
```

Les fixtures sont jetables : SQLite, faux comptes/fournisseurs et HTTP localhost. Elles remplacent uniquement les transports dans leurs copies temporaires. La suite historique a été adaptée aux tables de rôles/packs actuelles, à la garde d’accès existante et au contrat VOD opaque ; elle ne modifie pas le code d’authentification. Les résultats sont écrits dans `test-results/`.

Avant toute décision ultérieure, tester de vrais films/épisodes (plusieurs codecs et audios), le Range/206 sur les fournisseurs réels autorisés, la durée complète d’une lecture, les lecteurs Xtream qui suivent 307, la révocation des droits et le client GTV réellement intégré. Vérifier le Live stable sans activation puis le relais HLS avec activation locale. Aucune réussite de ces lectures réelles n’est affirmée dans ce ZIP.

## Retour arrière de la validation

Arrêter l’usage de la copie de validation, restaurer ses fichiers et sa sauvegarde locale. Les quatre tables FULL peuvent être conservées sans être lues par l’ancienne version. Exporter les configurations avant d’envisager leur suppression : aucune commande destructive de rollback n’est exécutée ou intégrée au démarrage. Un rollback des fichiers suffit à retirer les nouveaux chemins applicatifs ; ne pas effacer des tables historiques ou des données utilisateur.
