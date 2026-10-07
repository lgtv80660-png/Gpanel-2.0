<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('panel:read');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') panel_fail(405, 'method_not_allowed');
require __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $counts = $pdo->query("SELECT s.stream_type, COUNT(*) AS total, SUM(CASE WHEN s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0) THEN 1 ELSE 0 END) AS available FROM streams s LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id GROUP BY s.stream_type ORDER BY s.stream_type")->fetchAll(PDO::FETCH_ASSOC);
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM streams s LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE c.category_id IS NULL OR (f.id IS NULL AND s.fournisseur_id<>0)')->fetchColumn();
    $duplicates = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT fournisseur_id,stream_type,remote_stream_id FROM streams WHERE fournisseur_id<>0 AND remote_stream_id IS NOT NULL AND remote_stream_id<>'' GROUP BY fournisseur_id,stream_type,remote_stream_id HAVING COUNT(*)>1) duplicates")->fetchColumn();
    // Exact-title candidates are advisory; never merge different cuts or languages.
    $candidates = $pdo->query("SELECT stream_type, stream_name, COUNT(*) AS sources, COUNT(DISTINCT fournisseur_id) AS providers FROM streams WHERE stream_type IN ('live','movie','series') AND stream_name IS NOT NULL AND stream_name<>'' GROUP BY stream_type,stream_name HAVING COUNT(DISTINCT fournisseur_id)>1 ORDER BY providers DESC,stream_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $engines = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $engines = $pdo->query("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('streams','categories','content_settings','import_exclusions')")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['catalogue'=>$counts,'orphan_streams'=>$orphans,'duplicate_remote_identities'=>$duplicates,'cross_provider_candidates'=>$candidates,'table_engines'=>$engines,'scope'=>'local catalogue only; no playback or provider health probe'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { panel_fail(503, 'database_unavailable'); }
