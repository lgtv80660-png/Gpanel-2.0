<?php
require __DIR__.'/../panel_source_relay.php';
$dir=dirname(__DIR__,2).'/session-capacity-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('PANEL_SOURCES_STATE_DIR='.$dir);
$checks=0;$held=null;$admission=null;
function capacity_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function capacity_denied(callable $fn,string $reason):void{try{$fn();}catch(RuntimeException $e){capacity_check($e->getMessage()===$reason,$reason);return;}throw new RuntimeException('Expected rejection: '.$reason);}
$new=str_repeat('f',64);$write=static function(&$s){$s=['expires'=>time()+3600,'media_kind'=>'vod'];};
try{
 for($i=0;$i<1000;$i++)file_put_contents($dir.'/'.sprintf('%064x',$i).'.json',json_encode(['expires'=>time()-1,'media_kind'=>$i%2?'vod':'live']));
 sources_relay_session($new,$write,true);
 capacity_check(count(glob($dir.'/*.json'))===1,'1000 expired sessions reclaimed before admission');
 capacity_check(sources_relay_session($new,fn(&$s)=>$s['media_kind'])==='vod','new session persists');
 unlink($dir.'/'.$new.'.json');
 for($i=0;$i<1000;$i++)file_put_contents($dir.'/'.sprintf('%064x',$i).'.json',json_encode(['expires'=>time()+3600,'media_kind'=>$i%2?'vod':'live']));
 capacity_denied(fn()=>sources_relay_session($new,$write,true),'Session storage capacity reached');
 capacity_check(count(glob($dir.'/*.json'))===1000,'active VOD and Live sessions retained');
 $old=$dir.'/'.sprintf('%064x',0).'.json';file_put_contents($old,json_encode(['expires'=>time()-1]));
 $held=fopen($old,'r+');flock($held,LOCK_EX);
 capacity_denied(fn()=>sources_relay_session($new,$write,true),'Session storage capacity reached');
 capacity_check(is_file($old),'expired but streaming session retained');
 fclose($held);$held=null;
 sources_relay_session($new,$write,true);
 capacity_check(!is_file($old)&&count(glob($dir.'/*.json'))===1000,'unlocked expiry replaced without exceeding capacity');
 unlink($dir.'/'.$new.'.json');
 capacity_denied(fn()=>sources_relay_session($new,static function(&$s){throw new RuntimeException('fixture failure');},true),'fixture failure');
 capacity_check(!file_exists($dir.'/'.$new.'.json'),'failed creation leaves no orphan');
 $admission=fopen($dir.'/.admission.lock','c');flock($admission,LOCK_EX);
 capacity_denied(fn()=>sources_relay_session($new,$write,true),'Session busy');
 capacity_check(sources_relay_session(sprintf('%064x',1),fn(&$s)=>$s['media_kind'])==='vod','existing sessions bypass admission lock');
 fclose($admission);$admission=null;
 sources_relay_session($new,$write,true);
 capacity_check(count(glob($dir.'/*.json'))===1000,'admission lock released after failures');
 echo "$checks session capacity checks passed\n";
}finally{
 if(is_resource($held))fclose($held);if(is_resource($admission))fclose($admission);
 foreach(glob($dir.'/*') as $path)unlink($path);@unlink($dir.'/.admission.lock');rmdir($dir);putenv('PANEL_SOURCES_STATE_DIR');
}
