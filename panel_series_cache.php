<?php
// Cache upstream metadata only, never per-client URLs or authentication responses.
function panel_series_cache_path(string $identity): ?string {
    $dir = getenv('PANEL_STATE_DIR');
    $real = $dir ? realpath($dir) : false;
    $web = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: realpath(__DIR__);
    if (!$real || !is_writable($real) || strncmp(strtolower($real.DIRECTORY_SEPARATOR), strtolower($web.DIRECTORY_SEPARATOR), strlen($web.DIRECTORY_SEPARATOR)) === 0) return null;
    return $real.DIRECTORY_SEPARATOR.'series-'.hash('sha256', $identity).'.json';
}
function panel_series_metadata(string $identity, callable $fetch): ?array {
    $path = panel_series_cache_path($identity);
    $cached = $path && is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $valid = function ($data) { return is_array($data) && isset($data['episodes']) && is_array($data['episodes']); };
    $age = is_array($cached) ? time() - (int)($cached['time'] ?? 0) : PHP_INT_MAX;
    if ($age >= 0 && $age < 900 && $valid($cached['data'] ?? null)) return $cached['data'];
    $lock = $path ? @fopen($path.'.lock', 'c') : false;
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $age >= 0 && $age < 86400 && $valid($cached['data'] ?? null) ? $cached['data'] : null;
    }
    try {
        $raw = $fetch();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!$valid($data)) return $age >= 0 && $age < 86400 && $valid($cached['data'] ?? null) ? $cached['data'] : null;
        if ($path) {
            // Random file + rename prevents clients observing a partial JSON write.
            $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            if (@file_put_contents($temp, json_encode(['time'=>time(), 'data'=>$data]), LOCK_EX) !== false) {
                if (!@rename($temp, $path)) @unlink($temp);
            }
        }
        return $data;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
