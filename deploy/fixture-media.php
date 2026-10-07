<?php
// Transport fixture, deliberately not a playable video. Apache restricts to loopback.
if (getenv('PANEL_TEST_ENV') !== '1') { http_response_code(403); exit; }
$data = 'GPANEL-SYNTHETIC-MEDIA-v1';
$length = strlen($data);
header('Content-Type: application/octet-stream');
header('Accept-Ranges: bytes');
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d+)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $m) || (int)$m[1] >= $length) {
        http_response_code(416); header('Content-Range: bytes */'.$length); exit;
    }
    $start = (int)$m[1]; $end = $m[2] === '' ? $length-1 : min($length-1, (int)$m[2]);
    if ($end < $start) { http_response_code(416); exit; }
    http_response_code(206); header("Content-Range: bytes $start-$end/$length");
    $data = substr($data, $start, $end-$start+1);
}
header('Content-Length: '.strlen($data));
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $data;
