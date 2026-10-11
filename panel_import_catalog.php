<?php
// Transport supplied by caller; this module can be tested with fixtures only.
function panel_import_catalog(array $f, string $kind, callable $fetch): array {
    $type = strtolower(trim($f['type']));
    $cats = []; $items = [];
    if ($type === 'xtream') {
        $api = rtrim($f['url_base'], '/').'/player_api.php?username='.rawurlencode($f['user']).'&password='.rawurlencode($f['pass']);
        $actions = ['live'=>['get_live_categories','get_live_streams'], 'movie'=>['get_vod_categories','get_vod_streams'], 'series'=>['get_series_categories','get_series']];
        $cats = json_decode((string)$fetch($api.'&action='.$actions[$kind][0]), true);
        $items = json_decode((string)$fetch($api.'&action='.$actions[$kind][1]), true);
        if (!is_array($cats) || !is_array($items) || isset($items['user_info'])) throw new RuntimeException('Invalid provider response');
    } elseif ($type === 'm3u') {
        if ($kind !== 'live') throw new RuntimeException('M3U: only Live is identified by this provider format');
        $body = $fetch($f['url_base']);
        if (!is_string($body) || stripos($body, '#EXTM3U') === false) throw new RuntimeException('Invalid M3U');
        $current = null;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if (stripos($line, '#EXTINF:') === 0) {
                $current = ['name'=>'', 'icon'=>'', 'group'=>'Général'];
                if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $current['icon'] = $m[1];
                if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $current['group'] = $m[1] ?: 'Général';
                $pos = strrpos($line, ',');
                if ($pos !== false) $current['name'] = trim(substr($line, $pos+1));
            } elseif ($current && $line !== '' && $line[0] !== '#') {
                $current['url'] = $line; $items[] = $current;
                $cats[$current['group']] = ['id'=>$current['group'], 'title'=>$current['group']];
                $current = null;
            }
        }
        $cats = array_values($cats);
    } elseif ($type === 'stalker') {
        $portal = stalker_normalize_portal($f['url_base']);
        $mac = stalker_normalize_mac($f['mac_address'] ?? '');
        $proxy = $f['proxy'] ?? '';
        $hs = stalker_handshake($portal, $mac, $proxy);
        if (!$hs['ok']) throw new RuntimeException('Handshake failed');
        $st = ['live'=>'itv','movie'=>'vod','series'=>'series'][$kind];
        $load = function($action, $params=[]) use ($portal,$mac,$proxy,$hs,$st) {
            $res = stalker_load($portal,$mac,$hs['token'],$st,$action,$params,$hs['path'],$proxy);
            if (!$res['ok']) throw new RuntimeException('Provider page failed');
            return $res;
        };
        $cats = stalker_js_list($load($kind === 'live' ? 'get_genres' : 'get_categories')['data']);
        if ($kind === 'live') $items = stalker_js_list($load('get_all_channels')['data']);
        else foreach ($cats as $cat) {
            $rid = (string)($cat['category_id'] ?? $cat['id'] ?? '');
            $seen = []; $complete = false;
            for ($p=1; $p<=10000; $p++) {
                $res = $load('get_ordered_list', ['category'=>$rid,'p'=>$p]);
                $page = stalker_js_list($res['data']);
                if (!$page && $kind === 'series') $page = stalker_js_list($load('get_series', ['category'=>$rid,'p'=>$p])['data']);
                if (!$page) { $complete = true; break; }
                $new = 0;
                foreach ($page as $item) {
                    $key = panel_import_identity($item, 'stalker');
                    if ($key === '' || isset($seen[$key])) continue;
                    $seen[$key] = true; $new++;
                    if (panel_import_category($item, 'stalker') === '') $item['category_id'] = $rid;
                    $items[] = $item;
                }
                if (!$new) throw new RuntimeException('Repeated provider page; incomplete catalogue');
                $raw = json_decode($res['data'], true);
                $total = $raw['js']['total_items'] ?? null;
                if ($total !== null && count($seen) >= (int)$total) { $complete = true; break; }
            }
            if (!$complete) throw new RuntimeException('Catalogue page limit exceeded');
        }
    } else throw new RuntimeException('Unsupported provider');
    $unique = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $rid = panel_import_identity($item, $type);
        if ($rid !== '' && strlen($rid) <= 255 && !isset($unique[$rid])) $unique[$rid] = $item;
    }
    return ['categories'=>$cats, 'items'=>$unique];
}
function panel_import_page(array $items, string $type, string $category, string $container, string $quality, int $page): array {
    $filtered = [];
    foreach ($items as $rid=>$s) {
        if ($category !== '' && panel_import_category($s,$type) !== $category) continue;
        if ($container !== '' && panel_import_container($s,$type) !== $container) continue;
        if ($quality !== '' && panel_import_quality($s) !== $quality) continue;
        $filtered[$rid] = $s;
    }
    $pages = max(1, (int)ceil(count($filtered)/50));
    $page = max(1,min($pages,$page));
    return ['items'=>array_slice($filtered,($page-1)*50,50,true),'total'=>count($filtered),'pages'=>$pages,'page'=>$page];
}
