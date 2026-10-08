<?php
require_once __DIR__ . '/tmdb_public.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '{"error":"method_not_allowed"}';
    exit;
}
if (!is_string($type) || !in_array($type, ['movie', 'tv'], true) || !is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
    http_response_code(400);
    echo '{"error":"invalid_request"}';
    exit;
}
$data = panel_tmdb($type . '/' . $id);
if (!$data) {
    http_response_code(503);
    echo '{"error":"temporarily_unavailable"}';
    exit;
}
$videos = [];
foreach (($data['videos']['results'] ?? []) as $video) {
    if (($video['site'] ?? '') === 'YouTube' && in_array($video['type'] ?? '', ['Trailer', 'Teaser'], true) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $video['key'] ?? '')) {
        $videos[] = ['site' => 'YouTube', 'type' => $video['type'], 'key' => $video['key']];
    }
}
echo panel_tmdb_json(['title' => $data['title'] ?? '', 'name' => $data['name'] ?? '', 'overview' => $data['overview'] ?? '', 'backdrop_path' => $data['backdrop_path'] ?? null, 'videos' => $videos]);
