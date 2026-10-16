<?php
require_once __DIR__.'/panel_vod_sources.php';
require_once __DIR__.'/panel_vod_relay.php';
require_once __DIR__.'/panel_packs.php';
function vod_session_authorize(PDO $db,array $session): array {
    if(($session['media_kind']??'')!=='vod')throw new RuntimeException('Invalid capability type');
    $q=$db->prepare('SELECT * FROM clients WHERE id=? AND active=1');$q->execute([$session['client_id']]);$client=$q->fetch(PDO::FETCH_ASSOC);
    if(!$client || !hash_equals($session['credential_hash'],hash('sha256',$client['username']."\0".$client['password'])))throw new RuntimeException('Client revoked');
    $access=packs_access($db,(int)$client['id']);$id=(int)$session['stream_id'];
    if(!isset($access[$id]) || $access[$id]['stream_type']!==$session['kind'])throw new RuntimeException('Content not authorized');
    $plan=vod_plan($db,$id,$session['explicit']??null);
    if(!hash_equals($session['revision'],$plan['revision']))throw new RuntimeException('Source configuration changed');
    return $plan;
}
function vod_session_open(PDO $db,array $client,int $id,string $kind,string $extension,?int $explicit=null): void {
    header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    try {
        $range=vod_range((string)($_SERVER['HTTP_RANGE']??''));
        if($range && $range[0]!==0){http_response_code(409);echo 'Open playback before seeking; reuse its opaque session URL.';return;}
        $plan=vod_plan($db,$id,$explicit);if(!$plan['rows'] || $plan['kind']!==$kind){http_response_code(404);return;}
        $token=bin2hex(random_bytes(32));
        sources_relay_session($token,function(&$s)use($client,$id,$kind,$extension,$explicit,$plan){$s=['media_kind'=>'vod','client_id'=>(int)$client['id'],'credential_hash'=>hash('sha256',$client['username']."\0".$client['password']),
            'stream_id'=>$id,'kind'=>$kind,'extension'=>$extension,'explicit'=>$explicit,'revision'=>$plan['revision'],'expires'=>time()+21600,'transfer'=>[]];},true);
        header('Location: /vod_source_media.php?s='.$token,true,307);
    }catch(Throwable $e){http_response_code(503);echo 'VOD session unavailable';}
}
