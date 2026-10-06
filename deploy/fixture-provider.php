<?php
// Apache permits loopback only; never a real provider or media endpoint.
if (getenv('PANEL_TEST_ENV') !== '1' || ($_GET['username']??'') !== 'fixture' || ($_GET['password']??'') !== 'fixture-only') { http_response_code(403);exit('{}'); }
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';
if ($action === 'get_series_info') {
    echo json_encode(['info'=>['name'=>'TEST SERIES','plot'=>'Synthetic metadata'], 'seasons'=>[], 'episodes'=>['1'=>[['id'=>'fixture-episode','title'=>'TEST EPISODE','container_extension'=>'mkv','episode_num'=>1,'season'=>1]]]]);
} elseif ($action === 'get_vod_info') {
    echo json_encode(['info'=>['plot'=>'Synthetic metadata','cast'=>'Test','director'=>'Test','genre'=>'Test','releasedate'=>'2026-01-01'], 'movie_data'=>['name'=>'TEST MOVIE','container_extension'=>'mp4']]);
} else echo '[]';
