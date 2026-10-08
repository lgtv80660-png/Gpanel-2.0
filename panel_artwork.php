<?php
// Authenticated, bounded TMDB enrichment. Returns only image URLs and display metadata.
require_once __DIR__.'/panel_security.php';panel_require_admin('panel:read');
require_once __DIR__.'/tmdb_public.php';
header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')panel_fail(405,'method_not_allowed');
$id=(string)($_GET['id']??'');$type=(string)($_GET['type']??'');
if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id)||!in_array($type,['movie','tv'],true)){http_response_code(422);exit('{}');}
// Release session before slow upstream work so concurrent admin requests stay usable.
session_write_close();
$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'gpanel-artwork-'.substr(hash('sha256',__DIR__),0,12);
$file=$dir.DIRECTORY_SEPARATOR.$type.'-'.$id.'.json';
if(is_file($file)&&filemtime($file)>time()-86400){$cached=@file_get_contents($file);if(is_string($cached)&&is_array(json_decode($cached,true))){echo $cached;exit;}}
$data=panel_tmdb($type.'/'.$id);$result=[];
foreach(['poster_path'=>'poster','backdrop_path'=>'backdrop'] as $key=>$field)if(preg_match('#^/[A-Za-z0-9_.\-]+$#D',(string)($data[$key]??'')))$result[$field]='https://image.tmdb.org/t/p/'.($field==='poster'?'w500':'w1280').$data[$key];
$fanartKey=$_ENV['FANART_API_KEY']??$_SERVER['FANART_API_KEY']??getenv('FANART_API_KEY');
if($type==='movie' && is_string($fanartKey) && trim($fanartKey)!=='') {
 $ctx=stream_context_create(['http'=>['timeout'=>4,'follow_location'=>0,'header'=>'api-key: '.str_replace(["\r","\n"],'',trim($fanartKey))."\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $body=@file_get_contents('https://webservice.fanart.tv/v3.2/movies/'.$id,false,$ctx);$fanart=$body===false?[]:json_decode($body,true);
 $art=$fanart['moviebackground'][0]['url']??'';if(is_string($art)&&preg_match('#^https://assets\.fanart\.tv/[A-Za-z0-9_/.\-]+$#D',$art))$result['backdrop']=$art;
}
if(isset($data['vote_average']))$result['rating']=(float)$data['vote_average'];
$body=json_encode($result,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE);
if(!is_dir($dir))@mkdir($dir,0700,true);
if(is_dir($dir)){
 $temp=@tempnam($dir,'art');if($temp!==false){if(@file_put_contents($temp,$body,LOCK_EX)!==false)@rename($temp,$file);if(is_file($temp))@unlink($temp);}
 // Cache is intentionally bounded; no images are downloaded or committed.
 $files=glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[];if(count($files)>600){usort($files,fn($a,$b)=>filemtime($a)<=>filemtime($b));foreach(array_slice($files,0,count($files)-600) as $old)@unlink($old);}
}
echo $body;
