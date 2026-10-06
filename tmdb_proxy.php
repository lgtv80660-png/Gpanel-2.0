<?php
// Only a bounded TMDB detail request; never accepts an arbitrary upstream URL.
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
if (!in_array($type, ['movie', 'tv'], true) || !is_string($id) || !ctype_digit($id) || strlen($id) > 10) { http_response_code(422); exit('{}'); }
$key = $_ENV['TMDB_API_KEY'] ?? $_SERVER['TMDB_API_KEY'] ?? getenv('TMDB_API_KEY');
if (!$key || !function_exists('curl_init')) { http_response_code(503); exit('{}'); }
$ch = curl_init('https://api.themoviedb.org/3/' . $type . '/' . $id . '?api_key=' . rawurlencode($key) . '&language=fr-FR&append_to_response=videos');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
header('Content-Type: application/json; charset=utf-8');
if ($status !== 200 || !is_string($body) || !is_array(json_decode($body, true))) { http_response_code(502); exit('{}'); }
echo $body;
