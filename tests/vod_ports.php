<?php
require __DIR__.'/../panel_vod_relay.php';$checks=0;
function port_check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function port_reject(callable $fn,string $why):void{try{$fn();}catch(Throwable $e){port_check(true,$why);return;}throw new RuntimeException($why);}
$dns=fn($host)=>['93.184.216.34'];
$rows=[['type'=>'m3u','direct_source'=>'https://provider.example/start.mp4']];
$run=function(string $redirect)use($rows,$dns){
 $calls=0;$state=[];
 $transport=function($target,$method,$range,$headers,$bytes)use(&$calls,$redirect){
  $calls++;if($calls===1){$headers(302,['location'=>$redirect]);return ['ok'=>true,'status'=>302,'headers'=>['location'=>$redirect]];}
  $headers(200,['content-type'=>'video/mp4','content-length'=>'512']);$bytes(str_repeat("\0",512));return ['ok'=>true,'status'=>200,'headers'=>[]];
 };
 $r=vod_stream_rows($rows,'mp4','GET','',$state,$transport,$dns,fn($event,$value)=>null);
 return [$r,$calls];
};
try{
 putenv('PANEL_VOD_UPSTREAM_PORTS');port_check(vod_upstream_ports()===[80,443],'default remains 80/443');
 [$r,$calls]=$run('http://cdn.example:22541/file.mp4');port_check($r['status']===502&&$calls===1,'unconfigured redirect port blocked before connection');
 putenv('PANEL_VOD_UPSTREAM_PORTS=22541');
 [$r,$calls]=$run('http://cdn.example:22541/file.mp4');port_check($r['started']&&$calls===2,'explicitly configured CDN port streams');
 [$r,$calls]=$run('http://cdn.example:22542/file.mp4');port_check($r['status']===502&&$calls===1,'other high ports remain blocked');
 foreach(['http://127.0.0.1:22541/file','http://169.254.169.254:22541/file','http://[::1]:22541/file','http://localhost:22541/file','http://service.internal:22541/file','http://user:secret@cdn.example:22541/file','file:///etc/passwd'] as $url){
  [$r,$calls]=$run($url);port_check($r['status']===502&&$calls===1,'SSRF redirect rejected on configured port');
 }
 port_reject(fn()=>sources_url('http://cdn.example:22541/file',$dns),'Live/default validator does not inherit VOD ports');
 port_reject(fn()=>sources_url('http://cdn.example:22541/file',fn($host)=>['93.184.216.34','10.0.0.1'],vod_upstream_ports()),'mixed private DNS remains rejected');
 foreach(['0','22','80','65536','22541;443','22541,1','anything'] as $value){putenv('PANEL_VOD_UPSTREAM_PORTS='.$value);port_reject(fn()=>vod_upstream_ports(),'invalid port configuration rejected');}
 echo "$checks VOD port/security checks passed\n";
}finally{putenv('PANEL_VOD_UPSTREAM_PORTS');}
