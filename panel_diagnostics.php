<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('panel:read');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') panel_fail(405, 'method_not_allowed');
require __DIR__.'/config.php';
require_once __DIR__.'/panel_layout.php';
try {
    $counts = $pdo->query("SELECT s.stream_type, COUNT(*) AS total, SUM(CASE WHEN s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0) THEN 1 ELSE 0 END) AS available FROM streams s LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id GROUP BY s.stream_type ORDER BY s.stream_type")->fetchAll(PDO::FETCH_ASSOC);
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM streams s LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE c.category_id IS NULL OR (f.id IS NULL AND s.fournisseur_id<>0)')->fetchColumn();
    $duplicates = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT fournisseur_id,stream_type,remote_stream_id FROM streams WHERE remote_stream_id IS NOT NULL AND remote_stream_id<>'' GROUP BY fournisseur_id,stream_type,remote_stream_id HAVING COUNT(*)>1) duplicates")->fetchColumn();
    // Exact-title candidates are advisory; never merge different cuts or languages.
    $candidates = $pdo->query("SELECT stream_type, stream_name, COUNT(*) AS sources, COUNT(DISTINCT fournisseur_id) AS providers FROM streams WHERE stream_type IN ('live','movie','series') AND stream_name IS NOT NULL AND stream_name<>'' GROUP BY stream_type,stream_name HAVING COUNT(DISTINCT fournisseur_id)>1 ORDER BY providers DESC,stream_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $engines = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $engines = $pdo->query("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('streams','categories')")->fetchAll(PDO::FETCH_ASSOC);
    $personal=(int)$pdo->query('SELECT COUNT(*) FROM streams WHERE fournisseur_id=0')->fetchColumn();
    v2_begin('Diagnostic & Santé'); ?>
<p class="v2-muted">État du catalogue local · <?=v2_h(gmdate('Y-m-d H:i'))?> UTC</p>
<div class="v2-grid"><?php foreach(['Contenus conservés'=>array_sum(array_column($counts,'total')),'Contenus personnels'=>$personal,'Contenus orphelins'=>$orphans,'Identités dupliquées'=>$duplicates] as $label=>$number):?><section class="glass-panel v2-stat"><?=v2_h($label)?><strong><?=(int)$number?></strong></section><?php endforeach?></div>
<section class="glass-panel"><h2>Disponibilité dans le catalogue</h2><div class="v2-table"><table><tr><th>Type</th><th>Total conservé</th><th>Disponible</th><th>Masqué / fournisseur inactif</th></tr><?php foreach($counts as $row):?><tr><td><?=v2_h(['live'=>'Live','movie'=>'Films','series'=>'Séries','episode'=>'Épisodes'][$row['stream_type']]??$row['stream_type'])?></td><td><?=(int)$row['total']?></td><td><?=(int)$row['available']?></td><td><?=(int)$row['total']-(int)$row['available']?></td></tr><?php endforeach?></table></div></section>
<section class="glass-panel" style="margin-top:24px"><h2>Stockage & intégrité</h2><p><span class="v2-badge">Base connectée</span><span class="v2-badge"><?=$orphans===0&&$duplicates===0?'Intégrité du catalogue OK':'Vérification nécessaire'?></span></p><?php foreach($engines as $engine):?><p><?=v2_h($engine['name'])?> <span class="v2-badge"><?=v2_h($engine['engine'])?></span></p><?php endforeach?><p class="v2-muted">La disponibilité indique la visibilité locale. Aucun test de lecture ni appel aux fournisseurs n’est effectué.</p><a href="panel_diagnostics_json.php">Diagnostic JSON sécurisé</a> · <a href="live_monitor.php">Monitoring Live</a></section>
<section class="glass-panel" style="margin-top:24px"><h2>Candidats entre fournisseurs</h2><p class="v2-muted">Titres identiques à examiner, sans regroupement automatique.</p><div class="v2-table"><table><tr><th>Titre</th><th>Type</th><th>Fournisseurs</th></tr><?php foreach($candidates as $candidate):?><tr><td><?=v2_h($candidate['stream_name'])?></td><td><?=v2_h($candidate['stream_type'])?></td><td><?=(int)$candidate['providers']?></td></tr><?php endforeach?></table></div></section>
<?php v2_end();
} catch (Throwable $e) { panel_fail(503, 'database_unavailable'); }
