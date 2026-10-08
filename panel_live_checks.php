<?php
require_once __DIR__.'/panel_operations.php';
require_once __DIR__.'/panel_content.php';
// Resolve and pin public addresses at every redirect. Never follow a URL supplied by POST.
function ops_public_target(string $url): array {
    $p=parse_url($url);
    if(!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])) throw new RuntimeException('unsafe_target');
    $host=trim($p['host'],'[]');$port=$p['port']??(strtolower($p['scheme'])==='https'?443:80);
    if(!in_array($port,[80,443,8080,8443],true)) throw new RuntimeException('unsafe_port');
    if(!filter_var($host,FILTER_VALIDATE_IP)&&!preg_match('/^[a-z0-9.-]+$/iD',$host)) throw new RuntimeException('unsafe_target');
    $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:array_merge(array_column(dns_get_record($host,DNS_A)?:[],'ip'),array_column(dns_get_record($host,DNS_AAAA)?:[],'ipv6'));
    if(!$ips) throw new RuntimeException('dns_failed');
    foreach($ips as $ip) {
        $packed=inet_pton($ip);
        if($packed!==false&&strlen($packed)===16&&substr($packed,0,12)===str_repeat(chr(0),10).chr(255).chr(255)) $ip=inet_ntop(substr($packed,12));
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('private_target');
    }
    $ip=$ips[0];return [$host,$port,str_contains($ip,':')?'['.$ip.']':$ip];
}
function ops_probe(string $url): array {
    $started=microtime(true);$deadline=$started+5;$method='HEAD';$redirects=0;
    while(true) {
        [$host,$port,$ip]=ops_public_target($url);
        $remaining=(int)(($deadline-microtime(true))*1000);if($remaining<=0) throw new RuntimeException('timeout');
        $ch=curl_init($url);$location='';$bytes=0;$capped=false;
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_CONNECTTIMEOUT_MS=>min(1500,$remaining),CURLOPT_TIMEOUT_MS=>$remaining,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'GPanelLiveMonitor/1.0',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$bytes,&$capped){$bytes+=strlen($chunk);if($bytes>=4096){$capped=true;return 0;}return strlen($chunk);}]);
        if($method==='GET') curl_setopt($ch,CURLOPT_RANGE,'0-4095');
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
        if($errno && !($capped && $errno===CURLE_WRITE_ERROR) && !($method==='GET'&&$bytes>0&&$code>=200&&$code<300&&$errno===CURLE_OPERATION_TIMEDOUT)) throw new RuntimeException($errno===CURLE_OPERATION_TIMEDOUT?'timeout':'transport_error');
        if(in_array($code,[301,302,303,307,308],true)&&$location!=='') {
            if(++$redirects>3) throw new RuntimeException('redirect_limit');
            if(str_starts_with($location,'/')) {$p=parse_url($url);$location=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').$location;}
            if(!preg_match('#^https?://#i',$location)) throw new RuntimeException('unsupported_redirect');
            $url=$location;continue;
        }
        if($method==='HEAD'&&in_array($code,[400,403,405,501],true)) {$method='GET';continue;}
        $ms=(int)round((microtime(true)-$started)*1000);
        return ['state'=>$code>=200&&$code<300?($ms>2000?'DEGRADED':'ONLINE'):($code>=500?'DEGRADED':'OFFLINE'),'response_ms'=>$ms,'http_status'=>$code,'error_code'=>$code>=200&&$code<300?null:'http_'.$code];
    }
}
function ops_check_live(PDO $pdo,int $id): bool {
    $q=$pdo->prepare("SELECT s.*,c.visible AS category_visible,COALESCE(f.type,'m3u') AS type,f.url_base,f.user,f.pass,f.mac_address,f.active AS provider_active FROM streams s JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE s.stream_id=? AND s.stream_type='live'");$q->execute([$id]);$row=$q->fetch();if(!$row) return false;
    $ttl=(int)ops_setting($pdo,'monitor_ttl_seconds','300');$token=bin2hex(random_bytes(16));
    $pdo->prepare('INSERT IGNORE INTO panel_live_state (stream_id) VALUES (?)')->execute([$id]);
    $q=$pdo->prepare('UPDATE panel_live_state SET lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 SECOND),lease_token=? WHERE stream_id=? AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP()) AND (checked_at IS NULL OR checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND))');$q->execute([$token,$id,$ttl]);if(!$q->rowCount()) return false;
    try {
        if(!$row['visible']||!$row['category_visible']||((int)$row['fournisseur_id']!==0&&!$row['provider_active'])) throw new RuntimeException('catalogue_disabled');
        $row=content_playback($pdo,$row);
        if($row['type']==='xtream') $url=rtrim((string)$row['url_base'],'/').'/live/'.rawurlencode((string)$row['user']).'/'.rawurlencode((string)$row['pass']).'/'.rawurlencode((string)$row['direct_source']).'.ts';
        elseif($row['type']==='m3u') $url=(string)$row['direct_source'];
        else throw new RuntimeException('provider_not_probeable');
        $result=ops_probe($url);
    } catch(Throwable $e) {
        $safe=['unsafe_target','unsafe_port','dns_failed','private_target','timeout','transport_error','redirect_limit','unsupported_redirect','catalogue_disabled','provider_not_probeable'];
        $code=in_array($e->getMessage(),$safe,true)?$e->getMessage():'check_unavailable';
        $result=['state'=>in_array($code,['timeout','transport_error','dns_failed','catalogue_disabled'],true)?'OFFLINE':'DEGRADED','response_ms'=>null,'http_status'=>null,'error_code'=>$code];
    }
    $pdo->prepare('UPDATE panel_live_state SET state=?,response_ms=?,http_status=?,error_code=?,checked_at=UTC_TIMESTAMP(),lease_until=NULL,lease_token=NULL WHERE stream_id=? AND lease_token=?')->execute([$result['state'],$result['response_ms'],$result['http_status'],$result['error_code'],$id,$token]);return true;
}
function ops_live_filters(): array {
    $where=["s.stream_type='live'"];$params=[];
    foreach(['provider'=>'s.fournisseur_id','category'=>'s.category_id'] as $key=>$col) {
        $v=(string)($_GET[$key]??'');if($v!==''&&ctype_digit($v)) {$where[]="$col=?";$params[]=(int)$v;}
    }
    $state=(string)($_GET['state']??'');if(in_array($state,['ONLINE','OFFLINE','DEGRADED','UNKNOWN'],true)) {$where[]="COALESCE(m.state,'UNKNOWN')=?";$params[]=$state;}
    $search=mb_substr(trim((string)($_GET['search']??'')),0,120);if($search!=='') {$where[]='s.stream_name LIKE ?';$params[]='%'.$search.'%';}
    return [implode(' AND ',$where),$params];
}
