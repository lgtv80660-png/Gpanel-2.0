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

$stmt = $pdo->prepare('SELECT id FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1 LIMIT 1');
$stmt->execute([$user, $pass]);
if (!$stmt->fetch()) { http_response_code(401); exit('Authentication failed'); }

require_once __DIR__.'/panel_movie_groups.php';
$data = movie_resolve($pdo, $stream_id, isset($_GET['backup_stream']) ? (string)$_GET['backup_stream'] : null);
if (!$data) { http_response_code(404); exit('Movie not found'); }

if ($data['type'] === 'xtream') {
    // The stored provider extension is authoritative. This prevents a client
    // forcing .mp4 when the real file is .mkv/.avi/etc.
    $extension = strtolower(trim((string)($data['container_extension'] ?? '')));
    $extension = preg_replace('/[^a-z0-9]/i', '', $extension);
    if ($extension === '') $extension = $requested_extension ?: 'mp4';
    $url_finale = rtrim($data['url_base'], '/') . '/movie/'
        . rawurlencode((string)$data['user']) . '/'
        . rawurlencode((string)$data['pass']) . '/'
        . rawurlencode((string)$data['direct_source']) . '.' . $extension;
} elseif ($data['type'] === 'm3u') {
    $url_finale = $data['direct_source'];
} else {
    http_response_code(502); exit('Unsupported provider type');
}

if (!preg_match('#^https?://#i', (string)$url_finale) || preg_match('/[\r\n]/', (string)$url_finale)) { http_response_code(502); exit('Invalid movie URL'); }
header('Location: ' . $url_finale, true, 302);
exit;
?>
