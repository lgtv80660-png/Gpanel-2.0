<?php
require_once __DIR__.'/panel_sources.php';
// Opt-in local HLS relay. Never include credentials in response URLs or errors.
function sources_relay_enabled(): bool {return getenv('PANEL_SOURCES_LOCAL_HLS')==='1';}
function sources_relative_url(string $base,string $uri): string {
 $uri=trim($uri);if($uri===''||preg_match('/[\x00-\x20\x7f\\\\]/',$uri))throw new RuntimeException('Invalid media URI');
 if(preg_match('#^[a-z][a-z0-9+.-]*:#i',$uri))return $uri;
 $b=parse_url($base);$origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
 if(str_starts_with($uri,'//'))return $b['scheme'].':'.$uri;
 if($uri[0]==='?')return $origin.($b['path']??'/').$uri;
 $parts=explode('?', $uri,2);$path=$parts[0];if(!str_starts_with($path,'/'))$path=substr($b['path']??'/',0,strrpos($b['path']??'/','/')+1).$path;
 $stack=[];foreach(explode('/',$path) as $part){if($part==='..')array_pop($stack);elseif($part!=='.' && $part!=='')$stack[]=$part;}
 return $origin.'/'.implode('/',$stack).(str_ends_with($path,'/')?'/':'').(isset($parts[1])?'?'.$parts[1]:'');
}
function sources_relay_fetch(string $url,int $limit,?callable $transport=null,?callable $dns=null): array {
 $visited=[];
 for($hop=0;$hop<4;$hop++){
  $target=sources_url($url,$dns);if(isset($visited[$url]))throw new RuntimeException('Redirect loop');$visited[$url]=true;
  if($transport)$r=$transport($target,$limit);
  else {
   $body='';$headers=[];$overflow=false;$ch=curl_init($url);$ip=$target['ips'][0];$pin=str_contains($ip,':')?'['.$ip.']':$ip;
   curl_setopt_array($ch,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$pin],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_ENCODING=>'',CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){if(str_starts_with($line,'HTTP/'))$headers=[];elseif(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body,&$overflow,$limit){if(strlen($body)+strlen($chunk)>$limit){$overflow=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
   $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
   $r=['status'=>$status,'headers'=>$headers,'body'=>$body,'ok'=>$ok!==false&&!$overflow];
  }
  if(in_array($r['status'],[301,302,303,307,308],true)){
   if(empty($r['headers']['location']))throw new RuntimeException('Invalid redirect');$url=sources_relative_url($url,$r['headers']['location']);continue;
  }
  if(empty($r['ok'])||$r['status']<200||$r['status']>=300||$r['body']===''||strlen($r['body'])>$limit)throw new RuntimeException('Upstream transport failure');
  return $r+['url'=>$url];
 }
 throw new RuntimeException('Too many redirects');
}
function sources_relay_dir(): string {
 $raw=getenv('PANEL_SOURCES_STATE_DIR');$dir=$raw?realpath($raw):false;$web=realpath(__DIR__);
 if(!$dir||!is_dir($dir)||!is_writable($dir)||is_link($raw))throw new RuntimeException('Private state directory required');
 $d=strtolower(str_replace('\\','/',$dir));$w=strtolower(str_replace('\\','/',$web));
 if($d===$w||str_starts_with($d,$w.'/'))throw new RuntimeException('State directory must be outside web root');
 return $dir;
}
function sources_relay_session(string $token,callable $operation,bool $create=false) {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Invalid session');
 $dir=sources_relay_dir();
 if($create && count(glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[])>=1000)throw new RuntimeException('Session storage capacity reached');
 $path=$dir.DIRECTORY_SEPARATOR.$token.'.json';if(is_link($path)||(!$create&&!is_file($path)))throw new RuntimeException('Session unavailable');
 $f=fopen($path,$create?'x+':'r+');if(!$f)throw new RuntimeException('Session unavailable');@chmod($path,0600);
 try {
  if(!flock($f,LOCK_EX|LOCK_NB))throw new RuntimeException('Session busy');
  $raw=stream_get_contents($f,2097152);$state=$create?[]:json_decode($raw,true);
  if(!is_array($state)||(!$create&&($state['expires']??0)<time()))throw new RuntimeException('Session expired');
  $result=$operation($state);$encoded=json_encode($state,JSON_THROW_ON_ERROR);if(strlen($encoded)>2097152)throw new RuntimeException('Session size limit');
  rewind($f);if(!ftruncate($f,0)||fwrite($f,$encoded)!==strlen($encoded))throw new RuntimeException('State storage failed');fflush($f);return $result;
 }finally{flock($f,LOCK_UN);fclose($f);}
}
function sources_relay_href(string $token,int $generation,string $resource='root'): string {
 return '/live_source_media.php?s='.$token.'&g='.$generation.'&r='.$resource;
}
function sources_relay_manifest(string $body,string $base,string $token,array &$session): string {
 if(!str_starts_with(ltrim($body), '#EXTM3U'))throw new RuntimeException('Invalid manifest');
 $generation=$session['state']['generation'];$lines=[];
 $map=function(string $uri)use($base,$token,&$session,$generation):string{
  $url=sources_relative_url($base,$uri);$p=parse_url($url);
  if(!$p||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Invalid media URI');
  $key=hash('sha256',$url);if(count($session['resources'])>=2000&&!isset($session['resources'][$key]))throw new RuntimeException('Playlist resource limit');
  $session['resources'][$key]=['url'=>$url,'generation'=>$generation];return sources_relay_href($token,$generation,$key);
 };
 foreach(preg_split('/\r\n|\r|\n/',$body) as $line){
  $line=trim($line);if($line==='')continue;
  if($line[0]!=='#')$line=$map($line);
  elseif(preg_match('/\bURI=/i',$line)){
   $line=preg_replace_callback('/\bURI="([^"]*)"/i',fn($m)=>'URI="'.$map($m[1]).'"',$line);
   if(preg_match('/\bURI=(?!")/i',$line))throw new RuntimeException('Unquoted media URI');
  }
  // Unknown provider annotations containing URLs are never sent to the client.
  if($line[0]==='#'&&preg_match('#https?://#i',$line))continue;
  $lines[]=$line;
 }
 return implode("\n",$lines)."\n";
}
function sources_relay_authorize(PDO $db,array $session): void {
 $q=$db->prepare('SELECT active FROM clients WHERE id=?');$q->execute([$session['client_id']]);if(!$q->fetchColumn())throw new RuntimeException('Client inactive');
 require_once __DIR__.'/panel_packs.php';$access=packs_access($db,(int)$session['client_id']);
 if(!isset($access[$session['stream_id']])||$access[$session['stream_id']]['stream_type']!=='live')throw new RuntimeException('Content not authorized');
 if(!sources_available(sources_row($db,(int)$session['stream_id'])))throw new RuntimeException('Content unavailable');
}
// Observe each media playlist separately; master playlists and ended media are not stalls.
function sources_relay_progress(string $body,string $url,array &$session,int $now): void {
 if(!preg_match('/^#EXT-X-TARGETDURATION:(\d+)\s*$/m',$body,$target)||!preg_match('/^#EXTINF:/m',$body)||str_contains($body,'#EXT-X-ENDLIST'))return;
 $segments=[];foreach(preg_split('/\r\n|\r|\n/',$body) as $line){$line=trim($line);if($line!==''&&$line[0]!=='#')$segments[]=$line;}
 if(!$segments)return;
 preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)\s*$/m',$body,$sequence);
 // Hash media identity, not incidental key rotation or provider annotations.
 $fingerprint=hash('sha256',($sequence[1]??'0')."\n".implode("\n",$segments));$key=hash('sha256',$url);
 $observations=$session['progress']??[];$old=$observations[$key]??null;
 if($old&&$old['fingerprint']===$fingerprint){
  if($now-$old['since']>=max(30,3*(int)$target[1]))throw new RuntimeException('Live playlist stalled');
 }else{
  if(count($observations)>=32&&!isset($observations[$key]))array_shift($observations);
  $observations[$key]=['fingerprint'=>$fingerprint,'since'=>$now];
 }
 $session['progress']=$observations;
}
function sources_relay_resource(array &$session,string $token,int $generation,string $resource,?callable $transport=null,?callable $dns=null,?int $now=null): array {
 $now??=time();$state=$session['state'];
 if($generation!==$state['generation']||$state['status']==='exhausted')return ['status'=>409,'reopen'=>sources_relay_href($token,$state['generation']),'body'=>'Session reopen required'];
 if($now<$state['retry_at'])return ['status'=>503,'reopen'=>sources_relay_href($token,$state['generation']),'body'=>'Retry after cooldown'];
 $root=$resource==='root';
 if($root)$url=$session['plan']['sources'][$state['index']]['url'];
 else { $item=$session['resources'][$resource]??null;if(!$item||$item['generation']!==$generation)return ['status'=>404,'body'=>'Resource unavailable'];$url=$item['url']; }
 try {
  $result=sources_relay_fetch($url,$root?1048576:16777216,$transport,$dns);
  $body=$result['body'];$manifest=str_starts_with(ltrim($body),'#EXTM3U');
  if($root&&!$manifest)throw new RuntimeException('HLS manifest required');
  if($manifest){sources_relay_progress($body,$result['url'],$session,$now);$body=sources_relay_manifest($body,$result['url'],$token,$session);}
  $session['state']=sources_failover($session['plan'],$session['state'],'ok',$now);
  return ['status'=>200,'body'=>$body,'type'=>$manifest?'application/vnd.apple.mpegurl':'application/octet-stream'];
 }catch(Throwable $e){
  $session['state']=sources_failover($session['plan'],$session['state'],$root?'invalid_manifest':'segment_error',$now);
  // Old resources can never serve bytes from a new origin.
  if($session['state']['generation']!==$generation){$session['resources']=[];$session['progress']=[];}
  return ['status'=>502,'body'=>'Source failed; reopen session','reopen'=>sources_relay_href($token,$session['state']['generation'])];
 }
}
function sources_relay_range(array $result,string $range): array {
 if($range===''||$result['status']!==200)return $result;
 $size=strlen($result['body']);
 if(($result['type']??'')==='application/vnd.apple.mpegurl'||!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]===''))return ['status'=>416,'body'=>'Invalid range','content_range'=>'bytes */'.$size];
 if($m[1]===''){$start=max(0,$size-(int)$m[2]);$end=$size-1;}else{$start=(int)$m[1];$end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);}
 if($start>$end||$start>=$size)return ['status'=>416,'body'=>'Invalid range','content_range'=>'bytes */'.$size];
 $result['body']=substr($result['body'],$start,$end-$start+1);$result['status']=206;$result['content_range']='bytes '.$start.'-'.$end.'/'.$size;return $result;
}
function sources_relay_emit(array $result): void {
 http_response_code($result['status']);header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
 if(isset($result['root']))header('X-Panel-Root: '.$result['root']);
 if(isset($result['reopen'])){header('X-Panel-Reopen: '.$result['reopen']);header('Retry-After: 3');}
 header('Access-Control-Expose-Headers: X-Panel-Reopen, Retry-After, Content-Range');
 if(isset($result['content_range']))header('Content-Range: '.$result['content_range']);
 header('Accept-Ranges: bytes');
 header('Content-Type: '.($result['type']??'text/plain; charset=utf-8'));echo $result['body'];
}
function sources_relay_open(PDO $db,int $client,int $stream,string $extension): void {
 if($extension!=='m3u8'){http_response_code(501);exit('Local Sources relay currently requires HLS (.m3u8)');}
 try {
  $plan=sources_plan($db,$stream);if(!$plan['sources'])throw new RuntimeException('No primary');
  // Request .m3u8 on Xtream hosts even when the stored catalogue format is TS.
  foreach($plan['sources'] as &$s)if(preg_match('#/live/[^/]+/[^/]+/[^/?]+\.ts$#',$s['url'])){$s['url']=substr($s['url'],0,-3).'.m3u8';$s['key']=hash('sha256',$s['url']);}unset($s);
  $token=bin2hex(random_bytes(32));
  $result=sources_relay_session($token,function(&$session)use($db,$client,$stream,$plan,$token){
   $session=['client_id'=>$client,'stream_id'=>$stream,'expires'=>time()+1800,'plan'=>$plan,'state'=>sources_failover($plan,[],'start',time()),'resources'=>[]];
   sources_relay_authorize($db,$session);
   return sources_relay_resource($session,$token,0,'root');
  },true);
  $result['root']=$result['reopen']??sources_relay_href($token,0);
  sources_relay_emit($result);
 }catch(Throwable $e){http_response_code(503);header('Cache-Control: no-store');echo 'Sources relay unavailable';}
 exit;
}
