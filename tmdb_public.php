<?php
function panel_tmdb_key() {
    foreach ([$_ENV['TMDB_API_KEY']??'',$_SERVER['TMDB_API_KEY']??'',getenv('TMDB_API_KEY')] as $key) if(is_string($key)&&trim($key)!=='')return trim($key);
    return '';
}
function panel_tmdb_json($value) {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}
function panel_tmdb($endpoint) {
    $key = panel_tmdb_key();
    if (!$key) return [];
    $url = 'https://api.themoviedb.org/3/' . $endpoint . '?' . http_build_query(['api_key' => $key, 'language' => 'fr-FR', 'page' => 1, 'append_to_response' => 'videos']);
    $context = stream_context_create(['http' => ['timeout' => 4, 'follow_location' => 0], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $response = false;
    if (function_exists('curl_init')) {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $response=curl_exec($curl);if(curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200)$response=false;curl_close($curl);
    } else {$response = @file_get_contents($url, false, $context);}
    $data = $response === false ? null : json_decode($response, true);
    return is_array($data) && !isset($data['status_code']) ? $data : [];
}
