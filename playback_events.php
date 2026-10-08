<?php
// G-TV telemetry: auth is the existing client/Packs model; no admin session.
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Methods: POST, OPTIONS');header('Access-Control-Allow-Headers: Content-Type');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
$body=file_get_contents('php://input',false,null,0,4097);
if(strlen($body)>4096){http_response_code(413);exit;}
$data=json_decode($body,true);
if(!is_array($data)){http_response_code(400);exit;}
require __DIR__.'/config.php';require_once __DIR__.'/panel_packs.php';require_once __DIR__.'/panel_operations.php';
$q=$pdo->prepare('SELECT id FROM clients WHERE LOWER(username)=? AND password=? AND active=1 LIMIT 1');$q->execute([strtolower(trim((string)($data['username']??''))),(string)($data['password']??'')]);$id=(int)$q->fetchColumn();
if(!$id){http_response_code(401);exit;}
$stream=filter_var($data['stream_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$event=$data['event']??'';
if(!$stream||!in_array($event,['start','heartbeat','error','stop'],true)){http_response_code(422);exit;}
$access=packs_access($pdo,$id);if(!isset($access[$stream])||!in_array($access[$stream]['stream_type'],['live','movie','episode'],true)){http_response_code(403);exit;}
$device=$data['device']??null;if($device!==null&&!in_array($device,['tv','mobile','tablet','desktop','other'],true)){http_response_code(422);exit;}
$seconds=filter_var($data['duration_seconds']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>60]]);if($seconds===false){http_response_code(422);exit;}
if(!ops_ready($pdo)){http_response_code(503);exit;}
ops_event($pdo,$id,$stream,$access[$stream]['stream_type'],$event,'',$device,$event==='heartbeat'?$seconds:0);
http_response_code(202);echo json_encode(['accepted'=>true,'contract_version'=>1]);
