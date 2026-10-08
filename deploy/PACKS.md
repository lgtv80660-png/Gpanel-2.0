# Packs G-PANEL V2

## Mise en service

Branche gpanel-v2. Aucun média, catégorie, source, mot de passe ou client existant n’est réécrit. Aucun pack ni affectation n’est créé automatiquement. IMPORTANT : les clients sans pack ont désormais zéro accès. Avant activation en production, créer les packs et affecter les clients dans les modales existantes ; aucun ancien accès implicite n’est conservé.

Tables additives `packs`, `pack_rules`, `client_packs`. Pas de colonne ajoutée aux tables existantes. Un seul pack par client. FK des règles (cascade) et affectations (restriction) vers packs ; suppression bloquée tant que des clients sont affectés. Les références catalogue restent souples comme les regroupements V2 : une catégorie supprimée n’autorise aucun contenu et les règles sont validées à chaque enregistrement. Suppression client via admin retire son affectation dans la même transaction. Aucun média dupliqué. Duplication du pack crée une copie inactive, sans client.

Migration incluse dans `migrate_v2.php --apply --backup-confirmed`. Pour une installation V2 déjà migrée : `php migrate_packs.php` applique uniquement les trois CREATE TABLE IF NOT EXISTS, sous verrou partagé avec la migration V2. Pas de fixtures, de réinitialisation ou d’écriture de données. Prérequis : base MySQL V2 existante, tables content_settings et movie_group_sources. MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD configurés ; PDO MySQL et mbstring disponibles, table clients InnoDB (vérifiée, sans conversion automatique). Conserver une sauvegarde restaurable avant toute mise en service.

Railway Docker : start.sh exécute migrate_packs.php avant Apache. Procfile : même migration avant le serveur PHP. Le démarrage production n’invoque pas initialize-test.php ; ce dernier reste exclusivement derrière PANEL_TEST_ENV=1 et MYSQLDATABASE=gpanel_v2_test. Une base vide ou V2 incomplète fait échouer le démarrage plutôt que d’importer ou réparer des données. Aucun déploiement réalisé pendant cette implémentation.

## Droits

Les scopes sont live, movie, series et personal (fournisseur_id=0). Le scope personal est indépendant : sélectionner tous les films fournisseur ne donne pas les films personnels. Règles all (inclut les ajouts futurs), category (référence category_id) et group (regroupement VOD existant, scope movie). Union des sélections, sans exclusion item par item ni seconde architecture. Les catégories/bouquets sont ceux du manager existant. Les regroupements VOD listent uniquement leur source principale ; les backups existants restent des transports du même film autorisé, selon les règles du relais V2. Les épisodes héritent de la série par content_settings.series_parent ; un parent interdit ou masqué bloque ses épisodes. Un épisode historique sans parent dépend de sa catégorie séries/personnelle.

Les compteurs Live/Films/Séries incluent les contenus personnels du type correspondant, excluent les sources VOD secondaires et les épisodes. Ils respectent visibilité et sources actives. Le formulaire recalcule ces compteurs sans requête réseau ni exposition d’URL fournisseur. L’édition avertit du nombre de clients impactés et exige une case de confirmation.

Expiration stockée UTC dans client_packs.expires_at, valeur NULL = illimité. À une nouvelle affectation, champ vide + mode durée du pack = maintenant + duration_days. Sur le même pack, ce mode conserve l’expiration actuelle. Champ date explicite = date UTC choisie ; mode sans expiration + champ vide = illimité. Changer la durée par défaut du pack ne réécrit pas les expirations déjà affectées. L’inactivation du pack ou du client, l’expiration et l’absence de pack bloquent les droits.

## Contrat G-TV : API existante

GET /player_api.php?username=...&password=... sans action conserve user_info/server_info et ajoute :

```json
{
  "pack_entitlements": {
    "version": 1,
    "allowed": true,
    "reason": "active",
    "pack": {"id": 12, "name": "Famille", "level": "PREMIUM+", "active": true, "updated_at": "2026-10-08 12:00:00"},
    "expires_at": "2026-11-07T12:00:00Z",
    "rules": [{"scope": "live", "kind": "category", "reference_id": 7}],
    "catalogue_ids": [123, 124]
  }
}
```

Les valeurs de référence SQL peuvent être des chaînes numériques ; catalogue_ids et pack.id sont des entiers. updated_at est un marqueur opaque issu de MySQL, pas une date UTC garantie. allowed indique un abonnement actif, pas l’autorisation universelle : seuls les IDs retournés sont permis. reason : active/no_pack/pack_inactive/expired/client_disabled/migration_required. pack et expires_at peuvent être null ; règles/IDs vides quand l’accès est bloqué. user_info.auth vaut 0 et status Disabled/Expired en absence de droits effectifs ; exp_date est l’expiration Unix ou null pour illimité. Mauvais identifiants : réponse d’authentification historique auth=0.

Les actions get_live_categories/get_live_streams/get_vod_categories/get_vod_streams/get_vod_info/get_series_categories/get_series/get_series_info gardent leurs structures et renvoient seulement le catalogue autorisé. Info interdite = objet vide au format existant. get.php filtre le M3U ; live.php/vod.php/series.php et leurs routes réécrites refusent les IDs interdits (403), indépendamment du filtrage de l’app. Les nouveaux épisodes importés à la lecture d’une série autorisée héritent de son parent. Aucune nouvelle API parallèle.

Après modification d’un pack ou d’une affectation, G-PANEL appelle panel_revalidate(), le mécanisme existant du bouton Synchro app G-TV, si PANEL_REVALIDATE_URL/SECRET sont configurés. Un échec est signalé sans annuler l’enregistrement ; le bouton permet de réessayer. Les droits serveur sont relus à chaque requête ; réponses API privées no-store. Les lecteurs Live/séries existants redirigent vers la source : les packs contrôlent l’ouverture des lectures, sans révoquer une URL fournisseur déjà délivrée ou une lecture en cours.

### Adaptation externe G-TV encore nécessaire

Le code de l’app est hors de ce dépôt et n’a pas été modifié. G-TV doit résoudre chaque client avec ses identifiants, utiliser les actions filtrées ci-dessus et ne pas servir un catalogue global d’un autre compte. Consommer pack_entitlements pour le nom, le badge GOLD, l’expiration et les messages d’accès. Cloisonner tout cache par client et invalider lors de la synchro existante, d’un changement de pack ou de l’expiration ; traiter auth=0/status Disabled/Expired et les 403 de lecture. Ne jamais exposer les secrets de revalidation. Aucune nouvelle intégration ni clé n’est nécessaire.

## Sécurité et validation

Authentification admin panel:read/panel:manage et CSRF existants pour les mutations, POST uniquement, validation serveur des références, requêtes préparées, sorties échappées, transactions, verrouillage pack/client et suppression restreinte. Les trois niveaux sont des skins ; noms personnalisés libres. Aucun secret fournisseur dans les données de prévisualisation.

La migration locale n’a pas été exécutée : l’environnement de cette session ne fournit pas la configuration MYSQL. Vérifications effectuées : syntaxe PHP de tous les fichiers créés/modifiés, diff --check, syntaxe des deux nouveaux JS et du startup shell, contrôle ciblé des routes et schémas, 15 vérifications isolées des droits/compteurs/échappement via PHP en mémoire sans écriture DB ni page de test dans le dépôt. Validation interactive et SQL MySQL en conditions réelles restent à effectuer sur un environnement configuré, sans reset.
