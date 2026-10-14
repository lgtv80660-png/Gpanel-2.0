<?php
require_once __DIR__.'/panel_source_relay.php';
if(!sources_relay_enabled()){http_response_code(404);exit;}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){http_response_code(405);exit;}
require __DIR__.'/config.php';
$token=(string)($_GET['s']??'');$generation=filter_var($_GET['g']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);$resource=(string)($_GET['r']??'root');
if($generation===false||!preg_match('/^(?:root|[a-f0-9]{64})$/D',$resource)){http_response_code(422);exit;}
try {
 $result=sources_relay_session($token,function(&$session)use($pdo,$token,$generation,$resource){
  sources_relay_authorize($pdo,$session);
  // A host-role/channel-policy change invalidates an old playback session.
  $q=$pdo->prepare('SELECT revision FROM source_channel_policy WHERE stream_id=?');$q->execute([$session['stream_id']]);$channel=(int)$q->fetchColumn();
  $global=(int)$pdo->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn();
  if($session['plan']['revision']!==$channel.':'.$global)throw new RuntimeException('Policy changed; new session required');
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   if($generation!==$session['state']['generation']||($_POST['event']??'')!=='client_black_screen')return ['status'=>422,'body'=>'Invalid failure signal'];
   $session['state']=sources_failover($session['plan'],$session['state'],'client_black_screen',time());$session['resources']=[];$session['progress']=[];
   return ['status'=>409,'body'=>'Explicit client failure received','reopen'=>sources_relay_href($token,$session['state']['generation'])];
  }
  return sources_relay_resource($session,$token,$generation,$resource);
 });
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')$result=sources_relay_range($result,(string)($_SERVER['HTTP_RANGE']??''));
 sources_relay_emit($result);
}catch(Throwable $e){http_response_code(403);header('Cache-Control: no-store');echo 'Session unavailable';}
