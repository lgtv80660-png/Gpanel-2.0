<?php
// No upstream URL, cookie, authentication header or redirect reaches the client.
function vod_source_url(array $row, string $requested = ''): ?string {
    if ($row['type'] === 'xtream') {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($row['container_extension'] ?? $requested ?: 'mp4')) ?: 'mp4';
        $url = rtrim($row['url_base'], '/').'/movie/'.rawurlencode($row['user']).'/'.rawurlencode($row['pass']).'/'.rawurlencode($row['direct_source']).'.'.$ext;
    } elseif ($row['type'] === 'm3u') $url = $row['direct_source'];
    else return null;
    $parts = parse_url($url);
    return $parts && in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) && !empty($parts['host']) && !preg_match('/[\x00-\x20\x7f]/', $url) ? $url : null;
}
function vod_candidates(PDO $pdo, $id, $explicit = null): array {
    $first = movie_resolve($pdo, $id, $explicit);
    if (!$first) return [];
    $rows = [$first];
    if ($explicit !== null || !movie_groups_ready($pdo)) return $rows;
    $q = $pdo->prepare("SELECT b.stream_id FROM movie_group_sources a JOIN movie_group_sources b ON a.group_id=b.group_id WHERE a.stream_id=? AND a.role='primary' AND b.role='backup' ORDER BY b.stream_id LIMIT 4");
    $q->execute([$first['stream_id']]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $backup) {
        $row = movie_resolve($pdo, $first['stream_id'], $backup);
        if ($row) $rows[] = $row;
    }
    return $rows;
}
function vod_relay(array $rows, string $extension, string $method, string $range = ''): void {
    if (!function_exists('curl_init')) { http_response_code(503); return; }
    $started = false;
    foreach ($rows as $row) {
        $url = vod_source_url($row, $extension);
        if (!$url) continue;
        $status = 0; $headers = []; $accepted = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_TIMEOUT => $method === 'HEAD' ? 8 : 0,
            CURLOPT_HTTPHEADER => $range !== '' ? ['Range: '.$range] : [],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status, &$headers, &$accepted) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) { $status = (int)$m[1]; $headers = []; }
                elseif (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $key = strtolower(trim($key));
                    if (in_array($key, ['content-type','content-length','content-range','accept-ranges'], true)) $headers[$key] = trim($value);
                }
                if (trim($line) === '') {
                    $type = strtolower($headers['content-type'] ?? '');
                    $accepted = in_array($status, [200,206], true) && !preg_match('#text/|json|xml|mpegurl#', $type);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $bytes) use (&$started, &$accepted, &$status, &$headers) {
                if ($status >= 300 && $status < 400) return strlen($bytes);
                if (!$accepted || connection_aborted()) return 0;
                if (!$started) {
                    http_response_code($status);
                    foreach ($headers as $key => $value) header($key.': '.$value);
                    $started = true;
                }
                echo $bytes; flush(); return strlen($bytes);
            }
        ]);
        $ok = curl_exec($ch); curl_close($ch);
        // Once bytes were sent, a second file cannot safely replace the first.
        if ($started) return;
        if ($status === 416) { http_response_code(416); return; }
        if ($method === 'HEAD' && $ok && $accepted) {
            http_response_code($status);
            foreach ($headers as $key => $value) header($key.': '.$value);
            return;
        }
    }
    http_response_code(502);
}
