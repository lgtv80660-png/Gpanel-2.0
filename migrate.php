<?php
require 'config.php';
require 'db_migrations.php';
ensure_panel_schema($pdo);
header('Content-Type: text/plain; charset=utf-8');
echo "IPTV Panel V5 schema OK\n";
$row = $pdo->query("SELECT version, updated_at FROM panel_schema WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo 'Schema version: '.($row['version'] ?? '?')."\n";
echo 'Updated: '.($row['updated_at'] ?? '?')."\n";
$orphanCategoriesRemoved = cleanup_orphan_categories($pdo);
echo 'Orphan categories removed: '.(int)$orphanCategoriesRemoved."\n";
?>
