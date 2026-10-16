<?php
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = trim(strtolower((string)($_GET['username'] ?? '')));
$pass = trim((string)($_GET['password'] ?? ''));
$stream_id = trim((string)($_GET['stream'] ?? ''));
$requested_extension = strtolower(trim((string)($_GET['extension'] ?? '')));
$requested_extension = preg_replace('/[^a-z0-9]/i', '', $requested_extension);

$stmt = $pdo->prepare('SELECT * FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1 LIMIT 1');
$stmt->execute([$user, $pass]);
if (!($packClient=$stmt->fetch(PDO::FETCH_ASSOC))) { http_response_code(401); exit('Authentication failed'); }

require_once __DIR__.'/panel_packs.php';
$packAccess=packs_access($pdo,(int)$packClient['id']);
if(!isset($packAccess[(int)$stream_id]) || $packAccess[(int)$stream_id]['stream_type']!=='movie') {http_response_code(403);exit('Content not authorized');}

require_once __DIR__.'/panel_movie_groups.php';
require_once __DIR__.'/panel_vod_relay.php';
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET','HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD, OPTIONS'); exit; }
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/D', $range)) { http_response_code(416); exit; }
// Selection is retained in a private, short-lived opaque session.
require_once __DIR__.'/panel_vod_session.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
while (ob_get_level()) ob_end_clean();
require_once __DIR__.'/panel_operations.php';
ops_playback_hook($pdo,(int)$packClient['id'],(int)$stream_id,'movie');
vod_session_open($pdo,$packClient,(int)$stream_id,'movie',$requested_extension,isset($_GET['backup_stream'])?(int)$_GET['backup_stream']:null);