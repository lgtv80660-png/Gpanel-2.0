<?php
// Progressive VOD only. Redirects, credentials and source headers stay server-side.
require_once __DIR__.'/panel_source_relay.php';
// Operators can explicitly allow provider CDN ports; DNS/IP checks remain mandatory.
// This setting applies only to progressive VOD, including each redirect hop.
function vod_upstream_ports(): array {
    $ports=[80,443];$raw=getenv('PANEL_VOD_UPSTREAM_PORTS');
    if($raw===false||trim($raw)==='')return $ports;
    foreach(explode(',',$raw) as $entry){
        $entry=trim($entry);
        if(!preg_match('/^[0-9]{1,5}$/D',$entry)||(int)$entry<1024||(int)$entry>65535)throw new RuntimeException('Invalid VOD upstream port configuration');
        $ports[]=(int)$entry;
    }
    return array_values(array_unique($ports));
}
function vod_source_url(array $row,string $requested=''): ?string {
    if(($row['type']??'')==='xtream'){
        $ext=preg_replace('/[^a-z0-9]/','',strtolower($row['container_extension']??($requested?:'mp4')))?:'mp4';
        $kind=($row['stream_type']??'movie')==='episode'?'series':'movie';
        return rtrim($row['url_base'],'/').'/'.$kind.'/'.rawurlencode($row['user']).'/'.rawurlencode($row['pass']).'/'.rawurlencode($row['direct_source']).'.'.$ext;
    }
    if(($row['type']??'')!=='m3u')return null;
    $url=(string)$row['direct_source'];$parts=parse_url($url);
    return $parts && in_array(strtolower($parts['scheme']??''),['http','https'],true) && !empty($parts['host']) && !preg_match('/[\x00-\x20\x7f]/',$url)?$url:null;
}
function vod_candidates(PDO $pdo,$id,$explicit=null): array {
    require_once __DIR__.'/panel_vod_sources.php';
    try{return vod_plan($pdo,(int)$id,$explicit===null?null:(int)$explicit)['rows'];}catch(RuntimeException $e){return [];}
}
function vod_range(string $range): ?array {
    if($range==='')return null;
    if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]===''&&$m[2]===''))throw new RuntimeException('Invalid Range');
    foreach([$m[1],$m[2]] as $n)if($n!=='' && (strlen($n)>18 || (int)$n<0))throw new RuntimeException('Invalid Range');
    if($m[1]!=='' && $m[2]!=='' && (int)$m[2]<(int)$m[1])throw new RuntimeException('Invalid Range');
    if($m[1]==='' && (int)$m[2]===0)throw new RuntimeException('Invalid Range');
    return [$m[1]===''?null:(int)$m[1],$m[2]===''?null:(int)$m[2]];
}
function vod_accept_headers(int $status,array $headers,?array $range): ?array {
    if($status!==($range?206:200))return null;
    $type=strtolower(trim(explode(';',$headers['content-type']??'application/octet-stream')[0]));
    if(!preg_match('#^(?:video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/(?:octet-stream|mp4|x-matroska))$#D',$type) || str_contains($type,'mpegurl'))return null;
    if(isset($headers['content-encoding']) && strtolower($headers['content-encoding'])!=='identity')return null;
    $out=['Content-Type'=>$type,'Accept-Ranges'=>'bytes'];
    if(isset($headers['content-length'])){
        if(!preg_match('/^[0-9]{1,18}$/D',$headers['content-length']) || (int)$headers['content-length']<1)return null;
        $out['Content-Length']=$headers['content-length'];
    }
    if($range){
        if(!preg_match('/^bytes (\d{1,18})-(\d{1,18})\/(\d{1,18})$/D',$headers['content-range']??'',$m))return null;
        [$start,$end,$total]=[(int)$m[1],(int)$m[2],(int)$m[3]];
        if($start>$end || $end>=$total || $total<1)return null;
        if($range[0]===null){if($start!==max(0,$total-$range[1]) || $end!==$total-1)return null;}
        else {if($start!==$range[0] || $end!==($range[1]===null?$total-1:min($range[1],$total-1)))return null;}
        if(isset($out['Content-Length']) && (int)$out['Content-Length']!==$end-$start+1)return null;
        $out['Content-Range']='bytes '.$start.'-'.$end.'/'.$total;
    }
    return $out;
}
// One pinned HTTP hop. Callback seams are used only by local simulated tests.
function vod_http_hop(array $target,string $method,string $range,callable $onHeaders,callable $onBytes): array {
    if(!function_exists('curl_init'))return ['ok'=>false,'status'=>0,'headers'=>[]];
    $ch=curl_init($target['url']);$status=0;$headers=[];$headerBytes=0;
    $ip=$target['ips'][0];$pin=str_contains($ip,':')?'['.$ip.']':$ip;
    curl_setopt_array($ch,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$pin],
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_LOW_SPEED_LIMIT=>1,CURLOPT_LOW_SPEED_TIME=>8,CURLOPT_TIMEOUT=>$method==='HEAD'?12:21600,
        CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_HTTPHEADER=>array_merge(['Accept-Encoding: identity'],$range!==''?['Range: '.$range]:[]),
        CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$status,&$headers,&$headerBytes,$onHeaders){
            $headerBytes+=strlen($line);if($headerBytes>65536)return 0;
            if(preg_match('#^HTTP/\S+ (\d+)#',$line,$m)){$status=(int)$m[1];$headers=[];}
            elseif(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$key=strtolower(trim($key));$value=trim($value);if(isset($headers[$key]) && $headers[$key]!==$value)return 0;$headers[$key]=$value;}
            if(trim($line)==='' && $status>=200)$onHeaders($status,$headers);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>function($ch,$bytes)use(&$status,$onBytes){if($status>=300&&$status<400)return 0;return $onBytes($bytes)?strlen($bytes):0;}
    ]);
    $ok=curl_exec($ch)!==false;curl_close($ch);
    if($method==='HEAD'&&in_array($status,[0,404,405,501],true)){
        // Some Xtream gateways close HEAD instead of returning their CDN redirect.
        // Probe one byte with GET, expose metadata only, and never emit its body.
        $accepted=false;
        $r=vod_http_hop($target,'GET',$range!==''?$range:'bytes=0-0',function($code,$values)use($range,$onHeaders,&$accepted){
            if($range===''&&vod_accept_headers($code,$values,[0,0])){
                preg_match('#/(\d+)$#',$values['content-range'],$m);
                $values['content-length']=$m[1];unset($values['content-range']);$code=200;
            }
            $accepted=vod_accept_headers($code,$values,vod_range($range))!==null;
            $onHeaders($code,$values);
        },fn($bytes)=>false);
        if($accepted)$r['ok']=true; // Validated headers suffice for HEAD; body abort is intentional.
        return $r;
    }
    return ['ok'=>$ok,'status'=>$status,'headers'=>$headers];
}
function vod_stream_rows(array $rows,string $extension,string $method,string $range,array &$state,?callable $transport=null,?callable $dns=null,?callable $emit=null): array {
    if(!in_array($method,['GET','HEAD'],true))return ['status'=>405,'started'=>false];
    try{$parsed=vod_range($range);}catch(Throwable $e){return ['status'=>416,'started'=>false];}
    $emit??=function($event,$value){if($event==='headers'){http_response_code($value['status']);foreach($value['headers'] as $k=>$v)header($k.': '.$v);}else{echo $value;flush();}};
    $transport??='vod_http_hop';$started=false;$index=(int)($state['index']??0);
    $upstreamRange=$range;
    if($parsed&&$parsed[0]!==null&&$parsed[1]===null&&isset($state['file_identity']['length'])&&preg_match('/^[0-9]{1,18}$/D',(string)$state['file_identity']['length'])){
        $length=(int)$state['file_identity']['length'];
        if($parsed[0]>=$length)return ['status'=>416,'started'=>false];
        $upstreamRange='bytes='.$parsed[0].'-'.($length-1);
    }
    $locked=!empty($state['pinned']) || ($parsed && $parsed[0]!==0);
    $indices=$locked?[$index]:array_keys($rows);
    foreach($indices as $i){
        if(!isset($rows[$i]))continue;
        $url=vod_source_url($rows[$i],$extension);if(!$url)continue;$visited=[];
        for($hop=0;$hop<4;$hop++){
            $accepted=null;$status=0;$headers=[];$first=true;$preface='';$identity=[];
            try {
                $target=sources_url($url,$dns,vod_upstream_ports());if(isset($visited[$url]))break;$visited[$url]=true;
                $onHeaders=function($code,$values)use(&$accepted,&$status,&$headers,&$identity,&$state,$parsed){
                    // A complete 200 representation exactly satisfies an open range from zero.
                    // Other ignored ranges are rejected: no bytes are ever relabelled as a seek.
                    if($code===200&&$parsed===[0,null]&&!isset($values['content-range'])&&preg_match('/^[0-9]{1,18}$/D',$values['content-length']??'')&&(int)$values['content-length']>0){
                        $code=206;$values['content-range']='bytes 0-'.((int)$values['content-length']-1).'/'.$values['content-length'];
                    }
                    $status=$code;$headers=$values;$accepted=vod_accept_headers($code,$values,$parsed);
                    $length=$values['content-length']??null;if(isset($values['content-range'])&&preg_match('#/(\d+)$#',$values['content-range'],$m))$length=$m[1];
                    $identity=['length'=>$length,'etag'=>$values['etag']??null,'modified'=>$values['last-modified']??null];
                    if(!empty($state['pinned']) && isset($state['file_identity']))foreach($state['file_identity'] as $key=>$value)if($value!==null && ($identity[$key]??null)!==$value)$accepted=null;
                };
                $onBytes=function($bytes,$final=false)use(&$accepted,&$started,&$first,&$preface,&$identity,&$state,$i,$emit,&$status,$parsed){
                    if(!$accepted || connection_aborted())return false;
                    if($first){$preface.=$bytes;if(strlen($preface)<512&&!$final)return true;$bytes=$preface;$preface='';}
                    if($first && (!$parsed || $parsed[0]===0) && preg_match('/^(?:#EXTM3U|<\?xml|<!doctype|<html|\{|\[)/i',ltrim(substr($bytes,0,512)))){$accepted=null;return false;}
                    if($bytes==='')return false;
                    $first=false;
                    if(!$started){$state['index']=$i;$state['pinned']=true;$state['file_identity']=$identity;$emit('headers',['status'=>$status,'headers'=>$accepted]);$started=true;}
                    $emit('bytes',$bytes);return true;
                };
                $r=$transport($target,$method,$upstreamRange,$onHeaders,$onBytes);
                if(!$started && $method==='GET' && !empty($r['ok']) && $preface!=='')$onBytes('',true);
                if($started)return ['status'=>$status,'started'=>true,'ok'=>!empty($r['ok']),'index'=>$i];
                if(in_array($r['status'],[301,302,303,307,308],true)){
                    if(empty($r['headers']['location']))break;
                    $url=sources_relative_url($url,$r['headers']['location']);continue;
                }
                if($method==='HEAD' && !empty($r['ok']) && $accepted){$state['index']=$i;$state['pinned']=true;$state['file_identity']=$identity;$emit('headers',['status'=>$status,'headers'=>$accepted]);return ['status'=>$status,'started'=>false,'ok'=>true,'index'=>$i];}
                if($r['status']===416)return ['status'=>416,'started'=>false];
            }catch(Throwable $e){
                // Exception text and destination URLs may contain credentials.
                $reasons=['Invalid VOD upstream port configuration'=>'upstream_port_configuration',
                    'Destination ou port non autorisé.'=>'destination_port_or_host',
                    'Destination privée ou réservée refusée.'=>'destination_private_ip',
                    'Destination non résolue.'=>'destination_dns'];
                error_log('gpanel_vod_transport '.json_encode(['reason'=>$reasons[$e->getMessage()]??'destination_or_transport_failure','hop'=>$hop,'started'=>$started]));
                if($started)return ['status'=>$status,'started'=>true,'ok'=>false,'index'=>$i];
            }
            break;
        }
    }
    return ['status'=>502,'started'=>false,'ok'=>false];
}
function vod_relay(array $rows,string $extension,string $method,string $range=''): void {
    $state=[];$result=vod_stream_rows($rows,$extension,$method,$range,$state);
    if(!$result['started'] && empty($result['ok']))http_response_code($result['status']);
}
