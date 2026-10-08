<?php
require_once __DIR__.'/panel_visual.php';
function v2_h($s): string {return gp_escape($s);}
function v2_begin(string $title, array $styles=[]): void { ?>
<!doctype html><html lang="fr" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=v2_h($title)?> — G-PANEL</title><link rel="stylesheet" href="assets/gpanel.css"><link rel="stylesheet" href="assets/content-v2.css"><?php foreach($styles as $style):?><link rel="stylesheet" href="<?=v2_h($style)?>"><?php endforeach?><link rel="stylesheet" href="assets/cinema.css"></head><body class="gp-cinema">
<?php gp_navigation();?><main class="main-content gp-workspace"><?php gp_header($title); ?>
<?php }
function v2_end(): void { ?></main><?=panel_ui_bootstrap()?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><script src="assets/cinema.js" defer></script></body></html><?php }
