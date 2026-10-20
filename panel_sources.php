<?php
// Server-only management/resolution. No network playback and no automatic migration.
function sources_ready(PDO $db): bool {
 try {foreach(['source_host_roles','source_channel_policy','source_channel_links','source_policy_version'] as $t)$db->query('SELECT 1 FROM '.$t.' LIMIT 0');return true;}catch(PDOException $e){return false;}
}
function sources_public_ip(string $ip): bool {
 if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
  $b=array_map('intval',explode('.',$ip));
  if ($b[0]>=224 || ($b[0]===100 && $b[1]>=64 && $b[1]<=127) || ($b[0]===192 && $b[1]===0) || ($b[0]===192 && $b[1]===88 && $b[2]===99) || ($b[0]===198 && in_array($b[1],[18,19],true)) || ($b[0]===198 && $b[1]===51 && $b[2]===100) || ($b[0]===203 && $b[1]===0 && $b[2]===113))return false;
 }
 if (str_contains($ip,':')) {
  // Fail closed for mapped/transition IPv6; allow global unicast only.
  $bin=@inet_pton($ip);
  if ($bin===false || strlen($bin)!==16 || (ord($bin[0]) & 0xe0)!==0x20) return false;
  if (str_starts_with(strtolower($ip),'2001:db8:') || str_starts_with(strtolower($ip),'2002:') || str_starts_with(strtolower($ip),'2001:0:')) return false;
 }
 return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
}
function sources_url(string $raw, ?callable $resolve=null, array $allowedPorts=[80,443]): array {
 $url=trim($raw);$p=parse_url($url);
 if (!$p || strlen($url)>8192 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i',$url) || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) throw new RuntimeException('URL HTTP/HTTPS publique requise, sans userinfo ni fragment.');
 $host=strtolower(trim($p['host'],'[]'));$port=$p['port']??(strtolower($p['scheme'])==='https'?443:80);
 if (!in_array($port,$allowedPorts,true) || str_ends_with($host,'.') || $host==='localhost' || preg_match('/\.(?:localhost|local|internal)$/i',$host)) throw new RuntimeException('Destination ou port non autorisé.');
 if (!filter_var($host,FILTER_VALIDATE_IP) && (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/',$host) || !str_contains($host,'.') || preg_match('/^[0-9.]+$/',$host))) throw new RuntimeException('Nom de destination invalide.');
 $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:($resolve?$resolve($host):array_merge(array_column(dns_get_record($host,DNS_A)?:[],'ip'),array_column(dns_get_record($host,DNS_AAAA)?:[],'ipv6')));
 if (!$ips) throw new RuntimeException('Destination non résolue.');
 foreach($ips as $ip) if (!is_string($ip) || !sources_public_ip($ip)) throw new RuntimeException('Destination privée ou réservée refusée.');
 return ['url'=>$url,'host'=>$host,'port'=>$port,'ips'=>array_values(array_unique($ips))];
}
function sources_redact(string $url): string {
 $p=parse_url($url);return $p && !empty($p['host'])?(($p['scheme']??'https').'://'.$p['host'].'/••• (chemin et paramètres masqués)'):'Source fournisseur (identifiants masqués)';
}
// Optional bounded probe for a FUTURE relay. Validate and pin DNS on every request.
// Redirects are deliberately rejected; never expose response headers/URLs to clients.
function sources_probe(string $url): array {
 $target=sources_url($url);$ch=curl_init($target['url']);$bytes=0;$body='';$limit=262144;
 $ip=$target['ips'][0];$pin=str_contains($ip,':')?'['.$ip.']':$ip;
 curl_setopt_array($ch,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$pin],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static function($curl,$chunk)use(&$bytes,&$body,$limit){$bytes+=strlen($chunk);if($bytes>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
 $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
 // HTTP 200 proves transport only, never decoded video health or black-screen absence.
 return ['transport_ok'=>$ok!==false && $code>=200 && $code<300 && $bytes>0,'http_status'=>$code,'error'=>$error,'bounded_body'=>$body];
}
function sources_name(string $name): array {
 $quality='unknown';
 if (preg_match('/\b(2160p|4k|uhd|1080p|fhd|720p|hd|sd)\b/iu',$name,$m)) $quality=strtoupper($m[1]);
 // Language/country, +1, numbered channels, sports events stay significant.
 $s=mb_strtolower($name,'UTF-8');
 if (class_exists('Normalizer')) $s=Normalizer::normalize($s,Normalizer::FORM_KD);
 $s=preg_replace('/\p{Mn}+/u','',$s);
 $s=preg_replace('/\b(2160p|4k|uhd|1080p|fhd|720p|hd|sd|hevc|h264|h265)\b/u',' ',$s);
 $s=preg_replace('/[^\p{L}\p{N}+]+/u',' ',$s);
 return ['name'=>trim(preg_replace('/\s+/u',' ',$s)),'quality'=>$quality];
}
function sources_row(PDO $db,int $id): array {
 $q=$db->prepare("SELECT s.*,f.type,f.nom,f.active,f.url_base,f.user,f.pass,c.visible AS category_visible FROM streams s LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN categories c ON c.category_id=s.category_id WHERE s.stream_id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if (!$r) throw new RuntimeException('Chaîne absente.');return $r;
}
function sources_available(array $r): bool {return $r['stream_type']==='live' && !empty($r['visible']) && !empty($r['category_visible']) && ((int)$r['fournisseur_id']===0 || !empty($r['active']));}
function sources_candidates(PDO $db,array $row): array {
 $target=sources_name((string)$row['stream_name']);if ($target['name']==='') return [];
 $q=$db->prepare("SELECT s.stream_id,s.stream_name,s.fournisseur_id,s.container_extension,f.nom FROM streams s JOIN fournisseurs f ON f.id=s.fournisseur_id JOIN categories c ON c.category_id=s.category_id WHERE s.stream_type='live' AND s.visible=1 AND c.visible=1 AND f.active=1 AND f.type='xtream' AND s.fournisseur_id<>? ORDER BY f.nom,s.stream_id");$q->execute([$row['fournisseur_id']]);$out=[];
 while($r=$q->fetch(PDO::FETCH_ASSOC)) {
  $n=sources_name((string)$r['stream_name']);if ($n['name']!==$target['name'])continue;
  $out[]=$r+['quality'=>$n['quality'],'quality_diff'=>$n['quality']!==$target['quality']];
 }
 usort($out,fn($a,$b)=>($a['quality_diff']<=>$b['quality_diff'])?:($a['stream_id']<=>$b['stream_id']));return $out;
}
// UI-only key. Never use this relaxed name for inherited roles or playback.
function sources_suggestion_name(string $name): string {
 // Provider language labels are hints for human review, not automatic equivalence.
 $name=preg_replace('/^\s*\|[a-z]{2,3}\|\s*/iu','',$name);
 $name=preg_replace('/\s*\([a-z]{1,3}\)\s*/iu',' ',$name);
 $name=sources_name($name)['name'];
 return preg_replace('/\bsports\b/u','sport',$name);
}
function sources_suggestions(PDO $db,array $row): array {
 $strict=sources_name((string)$row['stream_name']);
 $target=sources_suggestion_name((string)$row['stream_name']);if($target==='')return [];
 $q=$db->prepare("SELECT s.stream_id,s.stream_name,s.fournisseur_id,s.container_extension,f.nom FROM streams s JOIN fournisseurs f ON f.id=s.fournisseur_id JOIN categories c ON c.category_id=s.category_id WHERE s.stream_type='live' AND s.visible=1 AND c.visible=1 AND f.active=1 AND f.type='xtream' AND s.fournisseur_id<>? ORDER BY f.nom,s.stream_id");
 $q->execute([$row['fournisseur_id']]);$out=[];
 while($r=$q->fetch(PDO::FETCH_ASSOC)) {
  $n=sources_name((string)$r['stream_name']);$exact=$n['name']===$strict['name'];
  if(!$exact && sources_suggestion_name((string)$r['stream_name'])!==$target)continue;
  $out[]=$r+['quality'=>$n['quality'],'quality_diff'=>$n['quality']!==$strict['quality'],'manual_confirmation'=>!$exact];
 }
 usort($out,fn($a,$b)=>($a['manual_confirmation']<=>$b['manual_confirmation'])?:($a['quality_diff']<=>$b['quality_diff'])?:($a['stream_id']<=>$b['stream_id']));return $out;
}
function sources_lock(PDO $db): void {
 if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
  $q=$db->query('SELECT revision FROM source_policy_version WHERE id=1 FOR UPDATE');if ($q->fetchColumn()===false) throw new RuntimeException('Migration incomplète.');
 }
}
function sources_save_hosts(PDO $db,array $posts,int $revision): void {
 if ($db->inTransaction()) throw new RuntimeException('Transaction déjà ouverte.');
 $db->beginTransaction();
 try {
  sources_lock($db);$current=(int)$db->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn();if ($current!==$revision) throw new RuntimeException('Les rôles ont changé. Rechargez la page.');
  $known=$db->query("SELECT id FROM fournisseurs WHERE type='xtream'")->fetchAll(PDO::FETCH_COLUMN);$rows=[];$seen=[];
  foreach($known as $fid) foreach(['live','movie','series'] as $kind) {
   $p=$posts[$fid][$kind]??[];$role=$p['role']??'neutral';$order=$role==='backup'?filter_var($p['order']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
   if (!in_array($role,['neutral','primary','backup'],true) || $order===false || ($role==='backup' && $order===null)) throw new RuntimeException('Rôle ou ordre invalide.');
   $key=$kind.':'.($role==='primary'?'primary':$order);if ($role!=='neutral' && isset($seen[$key])) throw new RuntimeException('Un seul PRIMARY et un ordre unique par type requis.');$seen[$key]=true;
   $rows[]=[$fid,$kind,$role,$order,$role==='primary'?1:null];
  }
  $db->exec('DELETE FROM source_host_roles');$q=$db->prepare('INSERT INTO source_host_roles(fournisseur_id,kind,role,backup_order,primary_slot) VALUES(?,?,?,?,?)');foreach($rows as $r)$q->execute($r);
  $db->exec('UPDATE source_policy_version SET revision=revision+1 WHERE id=1');$db->commit();
 } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sources_policy(PDO $db,int $id): array {
 $q=$db->prepare('SELECT * FROM source_channel_policy WHERE stream_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:['mode'=>'inherit','revision'=>0];
}
function sources_links(PDO $db,int $id): array {
 $q=$db->prepare('SELECT * FROM source_channel_links WHERE stream_id=? ORDER BY position');$q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sources_disabled_ready(PDO $db): bool {
 try{$db->query('SELECT 1 FROM source_live_disabled LIMIT 0');return true;}catch(PDOException $e){return false;}
}
function sources_disabled(PDO $db,int $id): array {
 if(!sources_disabled_ready($db))return [];
 $q=$db->prepare('SELECT source_key FROM source_live_disabled WHERE stream_id=?');$q->execute([$id]);return $q->fetchAll(PDO::FETCH_COLUMN);
}
function sources_save_channel(PDO $db,int $id,array $post,?callable $dns=null): void {
 $row=sources_row($db,$id);if ($row['stream_type']!=='live')throw new RuntimeException('Surcharge réservée au Live.');
 $mode=$post['mode']??'inherit';if (!in_array($mode,['inherit','custom'],true))throw new RuntimeException('Mode invalide.');
 $links=[];$seen=[];$disabled=[];
 if ($mode==='custom') {
  $input=$post['links']??[];if(!is_array($input))throw new RuntimeException('Liens invalides.');
  foreach($input as $p) {
   if (!is_array($p))throw new RuntimeException('Lien invalide.');
   $candidate=filter_var($p['candidate']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);$manual=trim((string)($p['url']??''));
   if ($candidate===false)throw new RuntimeException('Identifiant invalide.');
   if (!$candidate && $manual==='')continue;
   if ($candidate && $manual!=='')throw new RuntimeException('Choisir une chaîne ou une URL, pas les deux.');
   if ($candidate) { $r=sources_row($db,$candidate);if(!sources_available($r) || ($r['type']??'')!=='xtream')throw new RuntimeException('Source Xtream active et visible requise.');$key=hash('sha256','stream:'.$candidate); }
   else {$manual=sources_url($manual,$dns)['url'];$key=hash('sha256','url:'.$manual);}
   if(isset($seen[$key]))throw new RuntimeException('Source dupliquée.');$seen[$key]=true;
   if(isset($p['enabled']) && (string)$p['enabled']==='0')$disabled[]=$key;
   $links[]=[$id,count($links),$candidate?:null,$manual?:null,$key];
  }
  if (!$links)throw new RuntimeException('Une source principale est requise en mode personnalisé.');
 }
 $db->beginTransaction();
 try {
  sources_lock($db);$current=sources_policy($db,$id);
  if((int)$current['revision']!==(int)($post['revision']??-1))throw new RuntimeException('La chaîne a changé. Rechargez la page.');
  $db->prepare('DELETE FROM source_channel_links WHERE stream_id=?')->execute([$id]);
  $q=$db->prepare('INSERT INTO source_channel_links(stream_id,position,candidate_id,manual_url,source_key) VALUES(?,?,?,?,?)');foreach($links as $r)$q->execute($r);
  if(sources_disabled_ready($db)){
   $db->prepare('DELETE FROM source_live_disabled WHERE stream_id=?')->execute([$id]);$dq=$db->prepare('INSERT INTO source_live_disabled(stream_id,source_key) VALUES(?,?)');foreach($disabled as $key)$dq->execute([$id,$key]);
  }elseif($disabled)throw new RuntimeException('Migration VOD requise pour conserver les sources Live désactivées.');
  $db->prepare('DELETE FROM source_channel_policy WHERE stream_id=?')->execute([$id]);$db->prepare('INSERT INTO source_channel_policy(stream_id,mode,revision) VALUES(?,?,?)')->execute([$id,$mode,(int)$current['revision']+1]);
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sources_plan(PDO $db,int $id): array {
 $row=sources_row($db,$id);if(!sources_available($row))throw new RuntimeException('Chaîne indisponible.');
 $policy=sources_policy($db,$id);$links=[];$warnings=[];$disabled=sources_disabled($db,$id);
 if ($policy['mode']==='custom')$links=sources_links($db,$id);
 else {
  $candidates=sources_candidates($db,$row);if(($row['type']??'')==='xtream')$candidates[]=['stream_id'=>$id,'fournisseur_id'=>$row['fournisseur_id']];
  $roles=$db->query("SELECT * FROM source_host_roles WHERE kind='live' AND role<>'neutral' ORDER BY CASE WHEN role='primary' THEN 0 ELSE 1 END,backup_order")->fetchAll(PDO::FETCH_ASSOC);
  foreach($roles as $role){$matches=array_values(array_filter($candidates,fn($c)=>(int)$c['fournisseur_id']===(int)$role['fournisseur_id']));
   // No ambiguous match is silently chosen, even when only quality differs.
   if(count($matches)!==1){$warnings[]='Host #'.$role['fournisseur_id'].': correspondance absente ou ambiguë';continue;}
   $links[]=['candidate_id'=>(int)$matches[0]['stream_id'],'manual_url'=>null,'position'=>$role['role']==='primary'?0:(int)$role['backup_order']];
  }
  if(!$links || $links[0]['position']!==0) return ['sources'=>[],'warnings'=>array_merge($warnings,['Aucun PRIMARY non ambigu défini.']),'revision'=>$policy['revision']];
 }
 $out=[];$seen=[];
 foreach($links as $l){
  if(isset($l['source_key']) && in_array($l['source_key'],$disabled,true)){
   if((int)$l['position']===0)return ['sources'=>[],'warnings'=>['PRIMARY désactivé.'],'revision'=>$policy['revision'].':'.(int)$db->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn()];
   $warnings[]='Backup désactivé';continue;
  }
  if($l['candidate_id']){
   $r=sources_row($db,(int)$l['candidate_id']);if(!sources_available($r)||$r['type']!=='xtream'){if((int)$l['position']===0)throw new RuntimeException('PRIMARY indisponible.');$warnings[]='Backup indisponible';continue;}
   $url=rtrim($r['url_base'],'/').'/live/'.rawurlencode($r['user']).'/'.rawurlencode($r['pass']).'/'.rawurlencode($r['direct_source']).'.'.(in_array($r['container_extension'],['ts','m3u8'],true)?$r['container_extension']:'ts');
  } else $url=$l['manual_url'];
  $key=hash('sha256',$url);if(isset($seen[$key]))continue;$seen[$key]=true;
  $out[]=['key'=>$key,'url'=>$url,'position'=>(int)$l['position']];
 }
 $global=(int)$db->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn();
 return ['sources'=>$out,'warnings'=>$warnings,'revision'=>$policy['revision'].':'.$global];
}
// Pure state machine: a relay must store this server-side per authenticated session.
function sources_failover(array $plan,array $state,string $event,int $now): array {
 $valid=['start','ok','connect_error','timeout','http_error','invalid_manifest','segment_error','client_black_screen'];
 if(!in_array($event,$valid,true))throw new InvalidArgumentException('Signal explicite requis.');
 if (($state['revision']??null)!==$plan['revision'])$state=[];
 $state+=['revision'=>$plan['revision'],'index'=>0,'attempted'=>[],'retry_at'=>0,'status'=>'opening','generation'=>0];
 $sources=$plan['sources'];if(!$sources){$state['status']='exhausted';return $state;}
 if($now<$state['retry_at'] || $state['status']==='exhausted')return $state;
 if($event==='ok'){$state['status']='playing';return $state;}
 if($event==='start')return $state;
 $state['attempted'][$sources[$state['index']]['key']]=true;
 $next=$state['index']+1;
 while(isset($sources[$next]) && isset($state['attempted'][$sources[$next]['key']]))$next++;
 if(!isset($sources[$next])){$state['status']='exhausted';return $state;}
 $state['index']=$next;$state['retry_at']=$now+3;$state['generation']++;$state['status']='reopen';return $state;
}
