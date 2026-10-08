<?php
// Additive startup migration only. Does not run historical/test initialization.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require __DIR__.'/config.php';
$db=$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!$db || in_array(strtolower($db),['mysql','sys','information_schema','performance_schema'],true)) throw new RuntimeException('Application database required');
$engine=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients'")->fetchColumn();
if(strcasecmp((string)$engine,'InnoDB')!==0) throw new RuntimeException('Transactional clients table required; no automatic conversion performed');
foreach(['clients','streams','categories','content_settings','movie_group_sources'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1");
if((int)$pdo->query("SELECT GET_LOCK('gpanel-v2-migrate',30)")->fetchColumn()!==1) throw new RuntimeException('Migration busy');
try {
    foreach(explode(';',file_get_contents(__DIR__.'/packs.sql')) as $sql) if(trim($sql)!=='') $pdo->exec($sql);
    foreach(explode(';',file_get_contents(__DIR__.'/operations.sql')) as $sql) if(trim($sql)!=='') $pdo->exec($sql);
    echo "Packs schema ready. Existing data untouched.\n";
} finally {$pdo->query("SELECT RELEASE_LOCK('gpanel-v2-migrate')");}
