<?php
// Uses the existing consumer protocol only when explicitly configured.
// Relay v1 remains a separate future integration; no new consumer is activated.
function panel_revalidate() {
    $GLOBALS['panel_revalidation_error'] = 'sync_configuration_missing_or_invalid';
    $url = getenv('PANEL_REVALIDATE_URL');
    $secret = getenv('PANEL_REVALIDATE_SECRET');
    $parts = $url ? parse_url($url) : false;
    if (!$secret || !$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
    if (!function_exists('curl_init')) { $GLOBALS['panel_revalidation_error'] = 'sync_curl_unavailable'; return false; }
    // Legacy query mode requires human opt-in; prefer a receiver accepting a header.
    $mode = getenv('PANEL_REVALIDATE_AUTH') ?: 'header';
    if (!in_array($mode, ['header', 'legacy-query'], true) || preg_match('/[\r\n]/', $secret)) return false;
    $headers = ['Accept: application/json'];
    if ($mode === 'legacy-query') $url .= '?secret=' . rawurlencode($secret);
    else $headers[] = 'Authorization: Bearer ' . $secret;
    $ch = curl_init($url);
    // POST for Bearer protocol; legacy query remains an explicit GET opt-in.
    if ($mode === 'header') curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $headers]);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    $body = is_string($result) ? json_decode($result, true) : null;
    $ok = $status === 200 && is_array($body) && (($body['revalidated'] ?? false) === true || ($body['success'] ?? false) === true);
    $GLOBALS['panel_revalidation_error'] = $ok ? null : ($errno ? 'sync_transport_failed' :
        (in_array($status, [401, 403], true) ? 'sync_auth_rejected' : ($status !== 200 ? 'sync_backend_failed' : 'sync_response_invalid')));
    return $ok;
}
