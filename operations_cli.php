<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/config.php';require_once __DIR__.'/panel_operations.php';
if(!ops_ready($pdo)){fwrite(STDERR,"Apply additive migration first.\n");exit(2);}
$action=$argv[1]??'';
if($action==='monitor') {
    require_once __DIR__.'/panel_live_checks.php';$limit=max(1,min(10,(int)($argv[2]??10)));$ttl=(int)ops_setting($pdo,'monitor_ttl_seconds','300');
    $q=$pdo->prepare("SELECT s.stream_id FROM streams s LEFT JOIN panel_live_state m ON m.stream_id=s.stream_id WHERE s.stream_type='live' AND s.visible=1 AND (m.checked_at IS NULL OR m.checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND)) AND (m.lease_until IS NULL OR m.lease_until<UTC_TIMESTAMP()) ORDER BY m.checked_at,s.stream_id LIMIT $limit");$q->execute([$ttl]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$done=0;foreach($ids as $id)$done+=ops_check_live($pdo,(int)$id)?1:0;echo "Checked: $done\n";
} elseif($action==='purge') {
    $days=(int)ops_setting($pdo,'telemetry_retention_days','90');
    $q=$pdo->prepare('DELETE FROM panel_events WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY) LIMIT 5000');$q->execute([$days]);echo 'Expired events removed: '.$q->rowCount()."\n";
    $q=$pdo->prepare('DELETE FROM panel_audit WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) LIMIT 5000');$q->execute();echo 'Expired audit entries removed: '.$q->rowCount()."\n";
} else {fwrite(STDERR,"Usage: php operations_cli.php monitor [1..10] | purge\n");exit(2);}
