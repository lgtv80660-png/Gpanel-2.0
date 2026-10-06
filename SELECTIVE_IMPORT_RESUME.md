# Import sélectif — reprise

Base : branche `gpanel-v2`, commit `62e79dd`. Aucun déploiement ni accès aux fournisseurs, Railway, Cloudflare ou données de production pendant ce chantier.

## Achevé

- Écran `import_selective.php`, accessible depuis le choix du fournisseur dans `importer.php` : fournisseur actif, Live/films/séries, catégorie, conteneur, qualité, pagination de 50 contenus.
- Catalogue conservé côté serveur dans la session pendant 30 minutes. Navigation et application des décisions sans nouveau téléchargement du fournisseur. Un seul catalogue actif par session.
- Chaque contenu propose aucune modification, importer/lever son exclusion, ou exclure. Aucun contenu n'est sélectionné par défaut. Seuls les choix de la page filtrée sont acceptés ; valider avant de changer de page.
- Exclusions persistantes par fournisseur/type/identité distante, prises en compte par l'import sélectif ET l'import historique par catégories. Exclure ne supprime ni ne masque des lignes déjà présentes.
- Transactions et verrou fournisseur ; import incrémental existant conservé. Aucun changement des IDs, titres locaux, icônes, catégories locales, visibilités ou métadonnées personnalisées des contenus existants.
- Conteneurs explicites ou extension URL reconnue ; codecs ignorés. Qualité issue uniquement des champs de résolution/hauteur explicites, sinon inconnue.

## Migration préalable, non exécutée ici

`migrate_v2.php` ajoute `import_exclusions` sans réécrire les lignes existantes. L'import exige les trois tables InnoDB. Une base existante doit recevoir cette migration avec sauvegarde vérifiée avant utilisation. `mysql.sql` contient aussi la table pour une nouvelle base vide. Aucune migration de production effectuée.

## Tests locaux exécutés

- `tests/import_selective.php` avec PHP, PDO SQLite et mbstring : import V2 existant, stabilité des IDs/personnalisations/visibilités, rollback, conteneur versus codec, résolution inconnue, pagination, filtres, exclusions persistantes et isolation fournisseur/type, levée explicite, identités M3U/Stalker, catalogues Xtream/M3U et pagination Stalker avec transport fictif.
- `tests/run_regression.py --selective` : serveur HTTP local et base SQLite jetable, accès anonyme/permissions/CSRF, instantané invalide, sélection forgée ou hors filtre, rendu des filtres, application des décisions, préservation des données et réimport idempotent. Transport du fournisseur remplacé par des fixtures.
- Vérification syntaxique des fichiers PHP, sans erreur.

## Limites ciblées

- MySQL et vrais fournisseurs non testés ; migration MySQL non exécutée. Aucun test de lecture média ni validation visuelle navigateur.
- Xtream charge le catalogue du type choisi avant la pagination locale. Stalker charge ses pages ; une réponse répétée/incomplète échoue sans écrire. M3U reste classé Live car la source ne fournit pas ici de distinction fiable films/séries.
- Les séries sont sélectionnées au niveau de leur fiche, pas épisode par épisode. Résolution et conteneur souvent inconnus dans les listes de séries ; aucune requête supplémentaire par épisode pour les inventer.
- Pas de travail sur doublons multi-fournisseurs ni autres options. Aucune exclusion implicite des contenus non cochés.
