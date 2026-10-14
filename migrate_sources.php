<?php
// Explicit CLI only; never included in startup or HTTP routes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$apply=in_array('--apply',$argv,true);$down=in_array('--down',$argv,true);
if (!$apply) {echo "Plan: create four additive InnoDB tables. Existing data untouched.\nUse --apply --backup-confirmed on a dedicated local database.\nRollback: --down --apply --backup-confirmed --discard-source-settings (only new tables).\n";exit;}
if (!in_array('--backup-confirmed',$argv,true)) throw new RuntimeException('A verified backup is required');
if ($down && !in_array('--discard-source-settings',$argv,true)) throw new RuntimeException('Explicit confirmation to discard ONLY new source settings required');
require __DIR__.'/config.php';
$db=$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$db || in_array(strtolower($db),['mysql','sys','information_schema','performance_schema'],true)) throw new RuntimeException('Application database required');
foreach (['streams'=>['stream_id','fournisseur_id','stream_type','direct_source','visible'], 'fournisseurs'=>['id','type','active'], 'categories'=>['category_id','visible'], 'content_settings'=>['stream_id','source_override','source_provider','source_remote']] as $table=>$columns) {
 $pdo->query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0');
 $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
 if (strcasecmp((string)$q->fetchColumn(),'InnoDB')!==0) throw new RuntimeException('InnoDB required; no automatic conversion: '.$table);
}
if ((int)$pdo->query("SELECT GET_LOCK('gpanel-source-settings',10)")->fetchColumn()!==1) throw new RuntimeException('Migration busy');
try {
 if ($down) foreach (['source_channel_links','source_channel_policy','source_host_roles','source_policy_version'] as $t) $pdo->exec('DROP TABLE IF EXISTS '.$t);
 else {
  foreach (explode(';',file_get_contents(__DIR__.'/sources.sql')) as $sql) if (trim($sql)!=='') $pdo->exec($sql);
  $pdo->exec('INSERT IGNORE INTO source_policy_version(id,revision) VALUES(1,1)');
 }
 echo $down?"New source tables removed. Catalogue preserved.\n":"Sources schema ready. No host assigned by default.\n";
} finally { $pdo->query("SELECT RELEASE_LOCK('gpanel-source-settings')"); }
