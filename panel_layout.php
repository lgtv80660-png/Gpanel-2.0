<?php
function v2_h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function v2_begin(string $title): void { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=v2_h($title)?> — G-PANEL V2</title><link rel="stylesheet" href="assets/gpanel.css"><link rel="stylesheet" href="assets/content-v2.css"></head><body>
<aside class="sidebar"><div class="brand"><img class="brand-full" src="assets/g-panel-logo-dark.png" alt="G-PANEL V2"></div><div class="gp-section-label">Administration V2</div><?php foreach(['admin.php'=>'Tableau de bord','packs.php'=>'Gestion des Packs','panel_diagnostics.php'=>'Diagnostic & Santé','import_selective.php'=>'Import & gestion fournisseur','personal_content.php'=>'Contenus personnels','editor.php'=>'Gestion des bouquets','movie_duplicates.php'=>'Regroupement VOD'] as $url=>$label):?><a href="<?=$url?>"><?=v2_h($label)?></a><?php endforeach?></aside><main class="main-content gp-workspace"><header class="top-header"><span class="gp-section-label">G-PANEL V2</span><h1><?=v2_h($title)?></h1></header>
<?php }
function v2_end(): void { ?></main><?=panel_ui_bootstrap()?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script></body></html><?php }
