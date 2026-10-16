<?php
require_once __DIR__.'/panel_vod_session.php';
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range');header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
$method=$_SERVER['REQUEST_METHOD']??'GET';if($method==='OPTIONS'){http_response_code(204);exit;}
if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);exit;}
require __DIR__.'/config.php';
try {
    $result=sources_relay_session((string)($_GET['s']??''),function(&$session,$persist)use($pdo,$method){
        $plan=vod_session_authorize($pdo,$session);
        @set_time_limit(0);
        while(ob_get_level())ob_end_clean();
        $emit=function($event,$value)use($persist){if($event==='headers'){$persist();http_response_code($value['status']);foreach($value['headers'] as $key=>$header)header($key.': '.$header);}else{echo $value;flush();}};
        return vod_stream_rows($plan['rows'],$session['extension'],$method,(string)($_SERVER['HTTP_RANGE']??''),$session['transfer'],null,null,$emit);
    });
    if(!$result['started'] && empty($result['ok']))http_response_code($result['status']);
}catch(Throwable $e){if(!headers_sent()){if($e->getMessage()==='Session busy'){http_response_code(503);header('Retry-After: 1');}else http_response_code(403);echo 'Session unavailable';}}
