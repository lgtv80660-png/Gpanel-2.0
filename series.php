<?php
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = trim(strtolower((string)($_GET['username'] ?? '')));
$pass = trim((string)($_GET['password'] ?? ''));
$stream_id = trim((string)($_GET['stream'] ?? ''));
$requested_extension = strtolower(trim((string)($_GET['extension'] ?? '')));

$stmt = $pdo->prepare('SELECT * FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1 LIMIT 1');
$stmt->execute([$user, $pass]);
if (!($packClient=$stmt->fetch(PDO::FETCH_ASSOC))) {
    http_response_code(401);
    exit('Authentication failed');
}

require_once __DIR__.'/panel_packs.php';
$packAccess=packs_access($pdo,(int)$packClient['id']);
if(!isset($packAccess[(int)$stream_id]) || $packAccess[(int)$stream_id]['stream_type']!=='episode') {http_response_code(403);exit('Content not authorized');}

$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD, OPTIONS');exit;}
require_once __DIR__.'/panel_vod_session.php';
require_once __DIR__.'/panel_operations.php';
ops_playback_hook($pdo,(int)$packClient['id'],(int)$stream_id,'episode');
vod_session_open($pdo,$packClient,(int)$stream_id,'episode',$requested_extension);
