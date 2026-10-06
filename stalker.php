<?php
/**
 * G-PANEL Stalker/MAG integration — V7 diagnostic/compatibility layer.
 * This module only talks to a provider configured by the panel user.
 */

function stalker_normalize_mac($mac) {
    $mac = strtoupper(trim((string)$mac));
    $mac = preg_replace('/[^0-9A-F:]/i', '', $mac);
    if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
        $mac = implode(':', str_split($mac, 2));
    }
    return $mac;
}

function stalker_normalize_portal($portal) {
    $portal = trim((string)$portal);
    if ($portal === '') return '';
    $portal = preg_replace('#\s+#', '', $portal);
    // Preserve scheme/host/port and strip only a known Stalker path.
    $portal = rtrim($portal, '/');
    $portal = preg_replace(
        '#/(?:c|portal\.php|stalker_portal(?:/server/load\.php)?|stb(?:/server/load\.php)?)$#i',
        '',
        $portal
    );
    return rtrim($portal, '/');
}

function stalker_build_url($base, $path, array $params = []) {
    $url = rtrim((string)$base, '/') . '/' . ltrim($path, '/');
    return $params ? $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : $url;
}

function stalker_request($url, array $headers = [], $timeout = 25, $proxy = '') {
    $ch = curl_init($url);
    $defaultHeaders = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Connection: keep-alive',
        'X-User-Agent: Model: MAG250; Link: Ethernet',
        'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 4 rev: 1812 Safari/533.3',
    ];
    
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_ENCODING => '',
    ];

    // Intégration du système de Proxy
    if (trim($proxy) !== '') {
        $parts = explode(':', trim($proxy));
        // Si le format est IP:PORT:USER:PASS
        if (count($parts) === 4) {
            $options[CURLOPT_PROXY] = $parts[0] . ':' . $parts[1];
            $options[CURLOPT_PROXYUSERPWD] = $parts[2] . ':' . $parts[3];
        } else {
            // Si le format est juste IP:PORT
            $options[CURLOPT_PROXY] = trim($proxy);
        }
    }

    curl_setopt_array($ch, $options);
    
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    
    return [
        'ok' => ($body !== false && $err === '' && $http >= 200 && $http < 400),
        'body' => $body === false ? '' : (string)$body,
        'http' => $http,
        'error' => $err,
        'content_type' => $contentType,
        'effective_url' => $effective,
    ];
}

function stalker_headers($mac, $token = '', $portal = '') {
    $mac = stalker_normalize_mac($mac);
    $headers = [
        'Cookie: mac=' . $mac . '; stb_lang=en; timezone=Europe/Paris',
        'X-Device-Mac: ' . $mac,
        'X-Device-Type: MAG250',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ];
    if ($portal !== '') {
        $headers[] = 'Referer: ' . rtrim($portal, '/') . '/c/';
        $headers[] = 'Origin: ' . rtrim($portal, '/');
    }
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    return $headers;
}

function stalker_decode($body) {
    $data = json_decode((string)$body, true);
    if (is_array($data)) return $data;
    $body = (string)$body;
    $start = strpos($body, '{');
    $end = strrpos($body, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $data = json_decode(substr($body, $start, $end - $start + 1), true);
        if (is_array($data)) return $data;
    }
    return null;
}

function stalker_extract_token($data) {
    if (!is_array($data)) return '';
    $candidates = [
        $data['js']['token'] ?? '',
        $data['token'] ?? '',
        $data['js']['data']['token'] ?? '',
        $data['js']['js']['token'] ?? '',
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return '';
}

function stalker_response_preview($body) {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$body)));
    if ($text === '') return '[empty response]';
    return substr($text, 0, 220);
}

function stalker_endpoint_candidates() {
    return [
        '/c/portal.php',
        '/portal.php',
        '/stalker_portal/server/load.php',
        '/stb/server/load.php',
    ];
}

function stalker_handshake_diagnostics($portal, $mac, $proxy = '') {
    $mac = stalker_normalize_mac($mac);
    $portal = stalker_normalize_portal($portal);
    $diagnostics = [];

    if ($portal === '' || $mac === '') {
        return ['ok'=>false,'error'=>'Host ou MAC Stalker manquant.','diagnostics'=>[]];
    }

    $variants = [
        ['name'=>'standard','prehash'=>'0'],
        ['name'=>'prehash-false','prehash'=>'false'],
        ['name'=>'prehash-empty','prehash'=>''],
    ];

    foreach (stalker_endpoint_candidates() as $path) {
        foreach ($variants as $variant) {
            $params = [
                'type'=>'stb',
                'action'=>'handshake',
                'token'=>'',
                'prehash'=>$variant['prehash'],
                'JsHttpRequest'=>'1-xml',
            ];
            $url = stalker_build_url($portal, $path, $params);
            
            // On passe le proxy à la fonction stalker_request
            $r = stalker_request($url, stalker_headers($mac, '', $portal), 20, $proxy);
            $data = stalker_decode($r['body']);
            $token = stalker_extract_token($data);

            $row = [
                'path'=>$path,
                'variant'=>$variant['name'],
                'http'=>$r['http'],
                'content_type'=>$r['content_type'],
                'token_found'=>($token !== ''),
                'preview'=>stalker_response_preview($r['body']),
                'error'=>$r['error'],
            ];
            $diagnostics[] = $row;

            if ($r['ok'] && $token !== '') {
                return [
                    'ok'=>true,
                    'token'=>$token,
                    'path'=>$path,
                    'variant'=>$variant['name'],
                    'data'=>$data,
                    'diagnostics'=>$diagnostics
                ];
            }
        }
    }

    return [
        'ok'=>false,
        'error'=>'Aucun endpoint Stalker n’a fourni de token valide.',
        'diagnostics'=>$diagnostics
    ];
}

function stalker_handshake($portal, $mac, $proxy = '') {
    return stalker_handshake_diagnostics($portal, $mac, $proxy);
}

function stalker_load($portal, $mac, $token, $type, $action, array $extra = [], $preferredPath = '', $proxy = '') {
    $portal = stalker_normalize_portal($portal);
    $paths = [];
    if ($preferredPath !== '') $paths[] = $preferredPath;
    foreach (stalker_endpoint_candidates() as $p) {
        if (!in_array($p, $paths, true)) $paths[] = $p;
    }

    foreach ($paths as $path) {
        $params = array_merge([
            'type'=>$type,
            'action'=>$action,
            'JsHttpRequest'=>'1-xml',
        ], $extra);
        
        // On passe le proxy à la fonction stalker_request
        $r = stalker_request(
            stalker_build_url($portal, $path, $params),
            stalker_headers($mac, $token, $portal),
            25,
            $proxy
        );
        
        if (!$r['ok']) continue;
        $data = stalker_decode($r['body']);
        if (is_array($data)) return ['ok'=>true,'data'=>$data,'path'=>$path];
    }
    return ['ok'=>false,'error'=>'Aucune réponse JSON valide du portail Stalker pour '.$action.'.'];
}

function stalker_js_list($data) {
    if (!is_array($data)) return [];
    $js = $data['js'] ?? $data;
    if (is_array($js) && isset($js['data']) && is_array($js['data'])) return $js['data'];
    if (is_array($js)) {
        $keys = array_keys($js);
        if ($keys === range(0, count($keys)-1)) return $js;
    }
    return [];
}

function stalker_resolve_stream($portal, $mac, $token, $cmd, $preferredPath = '', $proxy = '') {
    $portal = stalker_normalize_portal($portal);
    $cmd = trim((string)$cmd);
    if ($cmd === '') return ['ok'=>false,'error'=>'Commande Stalker vide.'];

    $candidate = preg_replace('/^ff(?:mpeg|rt)\s+/i', '', $cmd);
    if (preg_match('#^https?://#i', $candidate)) {
        return ['ok'=>true,'url'=>$candidate,'raw'=>$candidate];
    }

    $result = stalker_load($portal, $mac, $token, 'itv', 'create_link', [
        'cmd'=>$cmd,
        'series'=>'0',
        'forced_storage'=>'undefined',
        'disable_ad'=>'0',
    ], $preferredPath, $proxy);

    if (!$result['ok']) return $result;

    $js = $result['data']['js'] ?? [];
    $url = '';
    if (is_string($js)) $url=$js;
    elseif (is_array($js)) $url=$js['cmd'] ?? $js['url'] ?? $js['link'] ?? $js['stream_url'] ?? '';
    $url=trim((string)$url);
    $url=preg_replace('/^ff(?:mpeg|rt)\s+/i','',$url);

    if (!preg_match('#^https?://#i',$url) && is_array($js)) {
        foreach (['cmd','url','link','stream_url'] as $k) {
            if (!empty($js[$k])) {
                $tmp=preg_replace('/^ff(?:mpeg|rt)\s+/i','',trim((string)$js[$k]));
                if (preg_match('#^https?://#i',$tmp)) {$url=$tmp;break;}
            }
        }
    }
    if (!preg_match('#^https?://#i',$url)) {
        return ['ok'=>false,'error'=>'create_link Stalker n’a pas retourné une URL vidéo exploitable.','data'=>$result['data']];
    }
    return ['ok'=>true,'url'=>$url,'raw'=>$js];
}
?>
