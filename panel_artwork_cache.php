<?php
// Read-only upstream enrichment; filesystem cache only, no catalogue writes.
require_once __DIR__.'/tmdb_public.php';
function gp_artwork(string $type,string $id): array {
 if(!in_array($type,['movie','tv'],true)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))return [];
 $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'gpanel-artwork-v2-'.substr(hash('sha256',__DIR__),0,12);
 $file=$dir.DIRECTORY_SEPARATOR.$type.'-'.$id.'.json';
 if(is_file($file)){$data=json_decode((string)@file_get_contents($file),true);$ttl=!empty($data['backdrop'])?86400:300;if(is_array($data)&&filemtime($file)>time()-$ttl)return $data;}
 $data=panel_tmdb($type.'/'.$id);$result=[];
 foreach(['poster_path'=>'poster','backdrop_path'=>'backdrop'] as $key=>$field)if(preg_match('#^/[A-Za-z0-9_.\-]+$#D',(string)($data[$key]??'')))$result[$field]='https://image.tmdb.org/t/p/'.($field==='poster'?'w500':'w1280').$data[$key];
$fanartKey=$_ENV['FANART_API_KEY']??$_SERVER['FANART_API_KEY']??getenv('FANART_API_KEY');
if(empty($result['backdrop']) && $type==='movie' && is_string($fanartKey) && trim($fanartKey)!=='') {
 $ctx=stream_context_create(['http'=>['timeout'=>4,'follow_location'=>0,'header'=>'api-key: '.str_replace(["\r","\n"],'',trim($fanartKey))."\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $body=@file_get_contents('https://webservice.fanart.tv/v3.2/movies/'.$id,false,$ctx);$fanart=$body===false?[]:json_decode($body,true);
 $art=$fanart['moviebackground'][0]['url']??'';if(is_string($art)&&preg_match('#^https://assets\.fanart\.tv/[A-Za-z0-9_/.\-]+$#D',$art))$result['backdrop']=$art;
}
 if(isset($data['vote_average']))$result['rating']=(float)$data['vote_average'];
 if(!is_dir($dir))@mkdir($dir,0700,true);
 if(is_dir($dir)){$temp=@tempnam($dir,'art');if($temp!==false){if(@file_put_contents($temp,panel_tmdb_json($result),LOCK_EX)!==false)@rename($temp,$file);if(is_file($temp))@unlink($temp);}$files=glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[];if(count($files)>600){usort($files,fn($a,$b)=>filemtime($a)<=>filemtime($b));foreach(array_slice($files,0,count($files)-600) as $old)@unlink($old);}}
 return $result;
}
