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

$stmt = $pdo->prepare('SELECT id FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1 LIMIT 1');
$stmt->execute([$user, $pass]);
if (!$stmt->fetch()) {
    http_response_code(401);
    exit('Authentication failed');
}

$stmt = $pdo->prepare("SELECT s.*, COALESCE(f.type,'m3u') AS type, f.url_base, f.user, f.pass
                       FROM streams s
                       LEFT JOIN fournisseurs f ON s.fournisseur_id = f.id
                       INNER JOIN categories c ON s.category_id = c.category_id
                       WHERE s.stream_id = ? AND s.stream_type = 'episode' AND s.visible = 1 AND c.visible = 1 AND (f.active = 1 OR s.fournisseur_id = 0)
                       LIMIT 1");
$stmt->execute([$stream_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);
if ($data) {require_once __DIR__.'/panel_content.php'; try{$data=content_playback($pdo,$data);}catch(Throwable $e){$data=false;} if ($data && !content_episode_allowed($pdo,(int)$data['stream_id'])) $data=false;}

if (!$data) {
    http_response_code(404);
    exit('Episode not found');
}

$extension = strtolower(trim((string)($data['container_extension'] ?? '')));
$extension = preg_replace('/[^a-z0-9]/i', '', $extension);
if ($extension === '') $extension = $requested_extension ?: 'mp4';

if ($data['type'] === 'xtream') {
    $base = rtrim($data['url_base'], '/');
    $url_finale = $base . '/series/'
        . rawurlencode((string)$data['user']) . '/'
        . rawurlencode((string)$data['pass']) . '/'
        . rawurlencode((string)$data['direct_source']) . '.'
        . $extension;
} elseif ($data['type'] === 'm3u') {
    $url_finale = $data['direct_source'];
} else {
    http_response_code(502);
    exit('Unsupported provider type');
}

if (!$url_finale) {
    http_response_code(502);
    exit('Stream URL unavailable');
}

// Do NOT proxy the media through PHP. Xtream clients need the provider's
// native response headers, redirects and Range/206 handling.
header('Location: ' . $url_finale, true, 302);
exit;
?>
