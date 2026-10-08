<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stalker.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = trim(strtolower((string)($_GET['username'] ?? '')));
$pass = trim((string)($_GET['password'] ?? ''));
$stream_id = trim((string)($_GET['stream'] ?? ''));
$extension = strtolower(trim((string)($_GET['extension'] ?? 'ts')));
$extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'ts';

$stmt = $pdo->prepare('SELECT * FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1 LIMIT 1');
$stmt->execute([$user, $pass]);
if (!($packClient=$stmt->fetch(PDO::FETCH_ASSOC))) { http_response_code(401); exit('Authentication failed'); }

require_once __DIR__.'/panel_packs.php';
$packAccess=packs_access($pdo,(int)$packClient['id']);
if(!isset($packAccess[(int)$stream_id]) || $packAccess[(int)$stream_id]['stream_type']!=='live') {http_response_code(403);exit('Content not authorized');}

$stmt = $pdo->prepare("SELECT s.*, COALESCE(f.type,'m3u') AS type, f.url_base, f.user, f.pass, f.mac_address
                       FROM streams s LEFT JOIN fournisseurs f ON s.fournisseur_id = f.id
                       INNER JOIN categories c ON s.category_id = c.category_id
                       WHERE s.stream_id = ? AND s.stream_type = 'live' AND s.visible = 1 AND c.visible = 1 AND (f.active = 1 OR s.fournisseur_id = 0) LIMIT 1");
$stmt->execute([$stream_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);
if ($data) {require_once __DIR__.'/panel_content.php'; try{$data=content_playback($pdo,$data);}catch(Throwable $e){$data=false;}}
if (!$data) { http_response_code(404); exit('Live stream not found'); }

if ($data['type'] === 'xtream') {
    $url_finale = rtrim($data['url_base'], '/') . '/live/'
        . rawurlencode((string)$data['user']) . '/'
        . rawurlencode((string)$data['pass']) . '/'
        . rawurlencode((string)$data['direct_source']) . '.' . $extension;
} elseif ($data['type'] === 'm3u') {
    $url_finale = $data['direct_source'];
} elseif ($data['type'] === 'stalker') {
    $mac = stalker_normalize_mac($data['mac_address'] ?? '');
    if ($mac === '') { http_response_code(502); exit('Stalker MAC address is missing'); }

    $portal = stalker_normalize_portal((string)$data['url_base']);
    $hs = stalker_handshake($portal, $mac);
    if (!$hs['ok']) { http_response_code(502); exit('Stalker handshake failed: ' . $hs['error']); }

    $resolved = stalker_resolve_stream($portal, $mac, $hs['token'], (string)$data['direct_source'], $hs['path']);
    if (!$resolved['ok']) {
        http_response_code(502);
        exit('Stalker stream resolution failed: ' . ($resolved['error'] ?? 'unknown error'));
    }
    $url_finale = $resolved['url'];
} else {
    http_response_code(502); exit('Unsupported provider type');
}

if (empty($url_finale) || !preg_match('#^https?://#i', $url_finale)) {
    http_response_code(502); exit('Invalid stream URL');
}

require_once __DIR__.'/panel_operations.php';
ops_playback_hook($pdo,(int)$packClient['id'],(int)$stream_id,'live');
header('Location: ' . $url_finale, true, 302);
exit;
?>
