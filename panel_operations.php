<?php
// Shared services; no schema mutation in HTTP requests.
function ops_ready(PDO $pdo): bool {
    static $ready=[];$key=spl_object_id($pdo);
    if(!array_key_exists($key,$ready)) {
        try {foreach(['panel_admin_roles','panel_audit','panel_settings','panel_events','panel_live_state'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1");$ready[$key]=true;}
        catch(PDOException $e) {if($e->getCode()!=='42S02') throw $e;$ready[$key]=false;}
    }
    return $ready[$key];
}
function ops_roles(): array {
    return [
        'super_admin'=>['*'],
        'admin'=>['panel:read','panel:manage','catalog:import','catalog:edit','catalog:delete','cache:revalidate','statistics:read','monitor:read','monitor:check','system:read','settings:manage'],
        'operator'=>['panel:read','catalog:import','catalog:edit','cache:revalidate','statistics:read','monitor:read','monitor:check'],
        'viewer'=>['panel:read','statistics:read','monitor:read']
    ];
}
function ops_authorize(PDO $pdo,int $id,string $permission): bool {
    // Until additive migration is applied, preserve historical login/permissions.
    try {$q=$pdo->prepare('SELECT r.role,r.enabled FROM panel_admin_roles r JOIN admins a ON a.id=r.admin_id WHERE r.admin_id=?');$q->execute([$id]);$row=$q->fetch();}
    catch(PDOException $e) {if($e->getCode()==='42S02') return !in_array($permission,['system:read','admins:manage','settings:manage','monitor:check'],true);throw $e;}
    if(!$row || !(int)$row['enabled']) return false;
    $allowed=ops_roles()[$row['role']]??[];
    return in_array('*',$allowed,true)||in_array($permission,$allowed,true);
}
function ops_audit(PDO $pdo,string $event,?int $target=null,?int $actor=null): void {
    // Only developer-selected event codes and numeric IDs, never request bodies/URLs.
    try {$pdo->prepare('INSERT INTO panel_audit (admin_id,event,target_id,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute([$actor??($_SESSION['panel_admin_id']??null),substr($event,0,80),$target]);}
    catch(Throwable $e) {error_log('Panel audit storage unavailable');}
}
function ops_setting(PDO $pdo,string $key,string $default): string {
    static $cache=[];$id=spl_object_id($pdo).':'.$key;
    if(isset($cache[$id])) return $cache[$id];
    $q=$pdo->prepare('SELECT setting_value FROM panel_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $cache[$id]=$v===false?$default:(string)$v;
}
function ops_event(PDO $pdo,int $clientId,int $streamId,string $type,string $event,string $session='',?string $device=null,int $seconds=0): void {
    // Unique minute buckets absorb range retries and refreshes; no HLS segment hook.
    // Storage failure never alters playback status/headers.
    try {
        $bucket=intdiv(time(),60);$key=hash('sha256',implode(':',[$clientId,$streamId,$event,$session,$bucket]));
        if(!ops_event_gate($key)) return;
        $q=$pdo->prepare('INSERT IGNORE INTO panel_events (dedupe_key,client_id,pack_id,stream_id,content_type,event,device,duration_seconds,created_at) SELECT ?,?,cp.pack_id,?,?,?,?,?,UTC_TIMESTAMP() FROM clients c LEFT JOIN client_packs cp ON cp.client_id=c.id WHERE c.id=?');
        $q->execute([$key,$clientId,$streamId,$type,$event,$device,$seconds,$clientId]);
    } catch(Throwable $e) {error_log('Panel telemetry unavailable');}
}
function ops_event_gate(string $key): bool {
    if(function_exists('apcu_add')&&filter_var(ini_get('apc.enabled'),FILTER_VALIDATE_BOOLEAN)) return apcu_add('gpanel-event-'.$key,1,60);
    // Fixed shard count and bounded entries; private temp path never contains a URL.
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'gpanel-events-'.substr(hash('sha256',__DIR__),0,16);
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) return true;
    if(is_link($dir)) return false;
    $path=$dir.DIRECTORY_SEPARATOR.substr($key,0,2).'.json';if(is_link($path)) return false;
    $f=@fopen($path,'c+');if(!$f) return true;@chmod($path,0600);
    if(!flock($f,LOCK_EX|LOCK_NB)){fclose($f);return false;}
    $entries=json_decode(stream_get_contents($f,262144),true)?:[];$now=time();$allowed=!isset($entries[$key]);
    foreach($entries as $k=>$expires) if($expires<=$now) unset($entries[$k]);
    if($allowed){if(count($entries)>2048)$entries=[];$entries[$key]=($now-$now%60)+60;rewind($f);ftruncate($f,0);fwrite($f,json_encode($entries));}
    flock($f,LOCK_UN);fclose($f);return $allowed;
}
function ops_playback_hook(PDO $pdo,int $clientId,int $streamId,string $type): void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') return;
    // One shutdown event for this authorized content request, before redirect or relay.
    // Actual player errors/durations are collected only through the explicit G-TV API.
    ops_event($pdo,$clientId,$streamId,$type,'request');
}
function ops_page(string $title): PDO {
    require_once __DIR__.'/panel_security.php';
    panel_require_admin(['Statistiques'=>'statistics:read','Monitoring Live'=>'monitor:read','Administration système'=>'system:read'][$title]);
    require_once __DIR__.'/panel_layout.php';
    global $pdo;
    if(!ops_ready($pdo)) {http_response_code(503);v2_begin($title);echo '<p class="v2-notice">Migration additive requise : php migrate_packs.php (CLI).</p>';v2_end();exit;}
    return $pdo;
}
function ops_links(): string {
    $out='<nav class="v2-form" aria-label="Pilotage">';
    foreach(['statistics.php'=>'Statistiques','live_monitor.php'=>'Monitoring Live','system_admin.php'=>'Administration système'] as $url=>$title) {
        $path=['statistics.php'=>'M4 19V5m0 14h16M8 15v-4m5 4V7m5 8v-6','live_monitor.php'=>'M3 5h18v12H3zM8 21h8m-4-4v4M5 11h3l2-3 3 6 2-3h4','system_admin.php'=>'M12 3l8 4v5c0 5-8 9-8 9s-8-4-8-9V7zM8 12l3 3 5-6'][$url];
        $out.='<a class="v2-badge" href="'.$url.'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="'.$path.'"/></svg> '.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</a>';
    }return $out.'</nav>';
}
