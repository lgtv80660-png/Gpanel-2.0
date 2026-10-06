<?php
// ==========================================
// 0. EN-TÊTES CORS & MÉTHODE OPTIONS (EN PREMIER)
// ==========================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require 'config.php';
require 'db_migrations.php';
ensure_panel_schema($pdo);

header('Content-Type: application/json; charset=utf-8');

$user = isset($_REQUEST['username']) ? trim(strtolower($_REQUEST['username'])) : '';
$pass = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '';
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$cat_id = isset($_REQUEST['category_id']) ? $_REQUEST['category_id'] : '';

// --- AUTHENTIFICATION CLIENT DYNAMIQUE ---
$stmt = $pdo->prepare("SELECT * FROM clients WHERE LOWER(username) = ? AND password = ? AND active = 1");
$stmt->execute([$user, $pass]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

// Si les identifiants sont faux ou le compte inactif
if (!$client) { 
    echo json_encode([
        'user_info' => [
            'auth' => 0,
            'status' => 'Disabled'
        ]
    ]); 
    exit; 
}

function fetch_data_proxy($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IPTVSmartersPro'); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: */*', 'Connection: keep-alive']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function normalize_api_extension($ext) {
    $ext = strtolower(trim((string)$ext));
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    return $ext !== '' ? $ext : 'mp4';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_proxy_url = $scheme . '://' . $_SERVER['HTTP_HOST'];

// ==========================================
// 1. CHAÎNES EN DIRECT (LIVE)
// ==========================================
if ($action === 'get_live_categories') {
    $stmt = $pdo->query("SELECT DISTINCT c.category_id, c.category_name, c.parent_id FROM categories c INNER JOIN streams s ON c.category_id = s.category_id WHERE s.stream_type = 'live' AND c.visible = 1 AND s.visible = 1");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = array_map(function($c) { 
        return [
            'category_id' => (string)$c['category_id'], 
            'category_name' => $c['category_name'], 
            'parent_id' => (int)$c['parent_id']
        ]; 
    }, $categories);
    echo json_encode($result);
} 
elseif ($action === 'get_live_streams') {
    if ($cat_id !== '' && $cat_id !== 'all' && $cat_id !== '0') {
        $stmt = $pdo->prepare("SELECT s.stream_id, s.stream_name, s.stream_icon, s.stream_type, s.category_id FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'live' AND s.visible = 1 AND c.visible = 1 AND s.category_id = ?");
        $stmt->execute([$cat_id]);
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $streams = $pdo->query("SELECT s.stream_id, s.stream_name, s.stream_icon, s.stream_type, s.category_id FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'live' AND s.visible = 1 AND c.visible = 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $result = array_map(function($s) use ($base_proxy_url, $user, $pass) {
        return [
            'num' => 0, 
            'name' => $s['stream_name'], 
            'stream_type' => 'live',
            'stream_id' => (int)$s['stream_id'], 
            'stream_icon' => $s['stream_icon'] ?? '', 
            'category_id' => (string)($s['category_id'] ?? 1), 
            'epg_channel_id' => null, 
            'added' => (string)time(), 
            'custom_sid' => '', 
            'tv_archive' => 0, 
            'direct_source' => $base_proxy_url . '/live.php?username=' . urlencode($user) . '&password=' . urlencode($pass) . '&stream=' . $s['stream_id'] . '&extension=ts', 
            'tv_archive_duration' => 0
        ];
    }, $streams);
    echo json_encode($result);
} 

// ==========================================
// 2. FILMS (VOD)
// ==========================================
elseif ($action === 'get_vod_categories') {
    $stmt = $pdo->query("SELECT DISTINCT c.category_id, c.category_name, c.parent_id FROM categories c INNER JOIN streams s ON c.category_id = s.category_id WHERE s.stream_type = 'movie' AND c.visible = 1 AND s.visible = 1");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = array_map(function($c) { 
        return [
            'category_id' => (string)$c['category_id'], 
            'category_name' => $c['category_name'], 
            'parent_id' => (int)$c['parent_id']
        ]; 
    }, $categories);
    echo json_encode($result);
}
elseif ($action === 'get_vod_streams') {
    if ($cat_id !== '' && $cat_id !== 'all' && $cat_id !== '0') {
        $stmt = $pdo->prepare("SELECT s.* FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'movie' AND s.visible = 1 AND c.visible = 1 AND s.category_id = ? ORDER BY s.stream_id ASC");
        $stmt->execute([$cat_id]);
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $streams = $pdo->query("SELECT s.* FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'movie' AND s.visible = 1 AND c.visible = 1 ORDER BY s.stream_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $result = [];
    foreach ($streams as $i => $s) {
        $ext = strtolower(trim((string)($s['container_extension'] ?? '')));
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
        if ($ext === '') $ext = 'mp4';
        $rating = $s['vod_rating'] !== null && $s['vod_rating'] !== '' ? (string)$s['vod_rating'] : '0';
        $rating5 = $s['vod_rating_5based'] !== null && $s['vod_rating_5based'] !== '' ? (float)$s['vod_rating_5based'] : 0;
        $result[] = [
            'num' => $i + 1,
            'name' => (string)$s['stream_name'],
            'title' => (string)$s['stream_name'],
            'stream_type' => 'movie',
            'stream_id' => (int)$s['stream_id'],
            'stream_icon' => (string)($s['stream_icon'] ?? ''),
            'plot' => (string)($s['vod_plot'] ?? ''),
            'cast' => (string)($s['vod_cast'] ?? ''),
            'director' => (string)($s['vod_director'] ?? ''),
            'genre' => (string)($s['vod_genre'] ?? ''),
            'releaseDate' => (string)($s['vod_release_date'] ?? ''),
            'rating' => $rating,
            'rating_5based' => $rating5,
            'added' => (string)($s['vod_added'] ?? ''),
            'is_adult' => '0',
            'category_id' => (string)($s['category_id'] ?? '1'),
            'container_extension' => $ext,
            'custom_sid' => '',
            'direct_source' => $base_proxy_url . '/vod.php?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&stream=' . rawurlencode((string)$s['stream_id']) . '&extension=' . rawurlencode($ext),
            'backdrop_path' => !empty($s['vod_backdrop']) ? [(string)$s['vod_backdrop']] : [],
            'youtube_trailer' => (string)($s['vod_trailer'] ?? ''),
            'episode_run_time' => (string)($s['vod_runtime'] ?? '')
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
elseif ($action === 'get_vod_info') {
    $localVodId = trim((string)($_REQUEST['vod_id'] ?? $_REQUEST['stream_id'] ?? ''));
    if ($localVodId === '') { echo json_encode(['info'=>[], 'movie_data'=>[]]); exit; }

    $stmt = $pdo->prepare("SELECT s.*, f.url_base, f.user, f.pass, f.type FROM streams s INNER JOIN fournisseurs f ON s.fournisseur_id=f.id WHERE s.stream_id=? AND s.stream_type='movie' AND s.visible=1 LIMIT 1");
    $stmt->execute([$localVodId]);
    $movie = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$movie) { echo json_encode(['info'=>[], 'movie_data'=>[]]); exit; }

    $info = [
        'movie_image' => (string)($movie['stream_icon'] ?? ''),
        'cover_big' => (string)($movie['stream_icon'] ?? ''),
        'plot' => (string)($movie['vod_plot'] ?? ''),
        'cast' => (string)($movie['vod_cast'] ?? ''),
        'director' => (string)($movie['vod_director'] ?? ''),
        'genre' => (string)($movie['vod_genre'] ?? ''),
        'releasedate' => (string)($movie['vod_release_date'] ?? ''),
        'releaseDate' => (string)($movie['vod_release_date'] ?? ''),
        'rating' => (string)($movie['vod_rating'] ?? ''),
        'rating_5based' => $movie['vod_rating_5based'] !== null ? (float)$movie['vod_rating_5based'] : 0,
        'backdrop_path' => !empty($movie['vod_backdrop']) ? [(string)$movie['vod_backdrop']] : [],
        'youtube_trailer' => (string)($movie['vod_trailer'] ?? ''),
        'episode_run_time' => (string)($movie['vod_runtime'] ?? '')
    ];

    if ($movie['type'] === 'xtream' && (trim((string)$movie['vod_plot']) === '' || trim((string)$movie['stream_icon']) === '' || trim((string)$movie['vod_cast']) === '' || trim((string)$movie['vod_director']) === '' || trim((string)$movie['vod_genre']) === '' || trim((string)$movie['vod_release_date']) === '' || trim((string)$movie['vod_backdrop']) === '')) {
        $remoteUrl = rtrim((string)$movie['url_base'], '/') . '/player_api.php?' . http_build_query([
            'username'=>$movie['user'], 'password'=>$movie['pass'], 'action'=>'get_vod_info', 'vod_id'=>$movie['direct_source']
        ], '', '&', PHP_QUERY_RFC3986);
        $remoteJson = fetch_data_proxy($remoteUrl);
        $remote = is_string($remoteJson) ? json_decode($remoteJson, true) : null;
        if (is_array($remote)) {
            $ri = is_array($remote['info'] ?? null) ? $remote['info'] : [];
            $md = is_array($remote['movie_data'] ?? null) ? $remote['movie_data'] : [];
            $icon = (string)($ri['movie_image'] ?? $ri['cover_big'] ?? $md['movie_image'] ?? $movie['stream_icon'] ?? '');
            $plot = (string)($ri['plot'] ?? $movie['vod_plot'] ?? '');
            $cast = (string)($ri['cast'] ?? $movie['vod_cast'] ?? '');
            $director = (string)($ri['director'] ?? $movie['vod_director'] ?? '');
            $genre = (string)($ri['genre'] ?? $movie['vod_genre'] ?? '');
            $release = (string)($ri['releasedate'] ?? $ri['releaseDate'] ?? $movie['vod_release_date'] ?? '');
            $rating = (string)($ri['rating'] ?? $movie['vod_rating'] ?? '');
            $rating5 = isset($ri['rating_5based']) ? (float)$ri['rating_5based'] : ($movie['vod_rating_5based'] !== null ? (float)$movie['vod_rating_5based'] : null);
            $backdrop = $ri['backdrop_path'] ?? $movie['vod_backdrop'] ?? '';
            if (is_array($backdrop)) $backdrop = $backdrop[0] ?? '';
            $trailer = (string)($ri['youtube_trailer'] ?? $movie['vod_trailer'] ?? '');
            $runtime = (string)($ri['episode_run_time'] ?? $ri['duration'] ?? $movie['vod_runtime'] ?? '');
            $added = (string)($md['added'] ?? $movie['vod_added'] ?? '');
            $newExt = strtolower(trim((string)($md['container_extension'] ?? $movie['container_extension'] ?? '')));
            $newExt = preg_replace('/[^a-z0-9]/i', '', $newExt) ?: 'mp4';

            $up = $pdo->prepare("UPDATE streams SET stream_icon=?, vod_plot=?, vod_cast=?, vod_director=?, vod_genre=?, vod_release_date=?, vod_rating=?, vod_rating_5based=?, vod_backdrop=?, vod_trailer=?, vod_runtime=?, vod_added=?, container_extension=? WHERE stream_id=?");
            $up->execute([$icon,$plot,$cast,$director,$genre,$release,$rating,$rating5,$backdrop,$trailer,$runtime,$added,$newExt,$localVodId]);
            $movie['stream_icon']=$icon; $movie['vod_plot']=$plot; $movie['vod_cast']=$cast; $movie['vod_director']=$director; $movie['vod_genre']=$genre; $movie['vod_release_date']=$release; $movie['vod_rating']=$rating; $movie['vod_rating_5based']=$rating5; $movie['vod_backdrop']=$backdrop; $movie['vod_trailer']=$trailer; $movie['vod_runtime']=$runtime; $movie['container_extension']=$newExt;
            $info['movie_image']=$icon; $info['cover_big']=$icon; $info['plot']=$plot; $info['cast']=$cast; $info['director']=$director; $info['genre']=$genre; $info['releasedate']=$release; $info['releaseDate']=$release; $info['rating']=$rating; $info['rating_5based']=$rating5; $info['backdrop_path']=$backdrop!==''?[$backdrop]:[]; $info['youtube_trailer']=$trailer; $info['episode_run_time']=$runtime;
        }
    }

    $ext = normalize_api_extension((string)($movie['container_extension'] ?? 'mp4'));
    echo json_encode([
        'info' => $info,
        'movie_data' => [
            'stream_id' => (int)$movie['stream_id'],
            'name' => (string)$movie['stream_name'],
            'title' => (string)$movie['stream_name'],
            'container_extension' => $ext,
            'category_id' => (string)$movie['category_id'],
            'added' => (string)($movie['vod_added'] ?? ''),
            'direct_source' => $base_proxy_url . '/vod.php?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&stream=' . rawurlencode((string)$movie['stream_id']) . '&extension=' . rawurlencode($ext)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==========================================
// 3. SÉRIES & ÉPISODES
// ==========================================
elseif ($action === 'get_series_categories') {
    $stmt = $pdo->query("SELECT DISTINCT c.category_id, c.category_name, c.parent_id FROM categories c INNER JOIN streams s ON c.category_id = s.category_id WHERE s.stream_type = 'series' AND c.visible = 1 AND s.visible = 1");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = array_map(function($c) { 
        return [
            'category_id' => (string)$c['category_id'], 
            'category_name' => $c['category_name'], 
            'parent_id' => (int)$c['parent_id']
        ]; 
    }, $categories);
    echo json_encode($result);
}
elseif ($action === 'get_series') {
    if ($cat_id !== '' && $cat_id !== 'all' && $cat_id !== '0') {
        $stmt = $pdo->prepare("SELECT s.* FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'series' AND s.visible = 1 AND c.visible = 1 AND s.category_id = ?");
        $stmt->execute([$cat_id]);
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $streams = $pdo->query("SELECT s.* FROM streams s INNER JOIN categories c ON s.category_id = c.category_id WHERE s.stream_type = 'series' AND s.visible = 1 AND c.visible = 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $result = array_map(function($s) {
        return [
            'num' => 0,
            'name' => (string)$s['stream_name'],
            'series_id' => (int)$s['stream_id'],
            'stream_id' => (int)$s['stream_id'],
            'cover' => (string)($s['stream_icon'] ?? ''),
            'stream_icon' => (string)($s['stream_icon'] ?? ''),
            'plot' => (string)($s['vod_plot'] ?? ''),
            'cast' => (string)($s['vod_cast'] ?? ''),
            'director' => (string)($s['vod_director'] ?? ''),
            'genre' => (string)($s['vod_genre'] ?? ''),
            'releaseDate' => (string)($s['vod_release_date'] ?? ''),
            'last_modified' => (string)($s['vod_added'] ?? '0'),
            'rating' => (string)($s['vod_rating'] ?? '0'),
            'rating_5based' => $s['vod_rating_5based'] !== null ? (float)$s['vod_rating_5based'] : 0,
            'backdrop_path' => !empty($s['vod_backdrop']) ? [(string)$s['vod_backdrop']] : [],
            'youtube_trailer' => (string)($s['vod_trailer'] ?? ''),
            'episode_run_time' => (string)($s['vod_runtime'] ?? ''),
            'category_id' => (string)($s['category_id'] ?? 1),
            'added' => (string)($s['vod_added'] ?? '')
        ];
    }, $streams);
    echo json_encode($result);
}
elseif ($action === 'get_series_info') {
    $local_series_id = trim((string)($_REQUEST['series_id'] ?? ''));

    if ($local_series_id === '') {
        echo json_encode(['episodes' => [], 'seasons' => [], 'info' => []]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT s.*, f.url_base, f.user, f.pass, f.type
                           FROM streams s
                           INNER JOIN fournisseurs f ON s.fournisseur_id = f.id
                           WHERE s.stream_id = ? AND s.stream_type = 'series' AND s.visible = 1
                           LIMIT 1");
    $stmt->execute([$local_series_id]);
    $series = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$series || $series['type'] !== 'xtream') {
        echo json_encode(['episodes' => [], 'seasons' => [], 'info' => []]);
        exit;
    }

    $remote_series_id = trim((string)$series['direct_source']);
    $remote_url = rtrim($series['url_base'], '/') . '/player_api.php?' . http_build_query([
        'username' => $series['user'],
        'password' => $series['pass'],
        'action'   => 'get_series_info',
        'series_id'=> $remote_series_id
    ], '', '&', PHP_QUERY_RFC3986);

    $json = fetch_data_proxy($remote_url);
    $data = is_string($json) ? json_decode($json, true) : null;

    if (!is_array($data) || !isset($data['episodes']) || !is_array($data['episodes'])) {
        echo json_encode(['episodes' => [], 'seasons' => [], 'info' => []]);
        exit;
    }

    $seriesInfo = is_array($data['info'] ?? null) ? $data['info'] : [];
    $seriesCover = (string)($seriesInfo['cover'] ?? $seriesInfo['cover_big'] ?? $series['stream_icon'] ?? '');
    $seriesPlot = (string)($seriesInfo['plot'] ?? '');
    $seriesCast = (string)($seriesInfo['cast'] ?? '');
    $seriesDirector = (string)($seriesInfo['director'] ?? '');
    $seriesGenre = (string)($seriesInfo['genre'] ?? '');
    $seriesRelease = (string)($seriesInfo['releaseDate'] ?? $seriesInfo['releasedate'] ?? '');
    $seriesRating = (string)($seriesInfo['rating'] ?? '');
    $seriesRating5 = isset($seriesInfo['rating_5based']) ? (float)$seriesInfo['rating_5based'] : null;
    $seriesBackdrop = $seriesInfo['backdrop_path'] ?? '';
    if (is_array($seriesBackdrop)) $seriesBackdrop = $seriesBackdrop[0] ?? '';
    $seriesTrailer = (string)($seriesInfo['youtube_trailer'] ?? '');
    $seriesRuntime = (string)($seriesInfo['episode_run_time'] ?? '');
    $upSeries = $pdo->prepare("UPDATE streams SET stream_icon=?, vod_plot=?, vod_cast=?, vod_director=?, vod_genre=?, vod_release_date=?, vod_rating=?, vod_rating_5based=?, vod_backdrop=?, vod_trailer=?, vod_runtime=? WHERE stream_id=?");
    $upSeries->execute([$seriesCover,$seriesPlot,$seriesCast,$seriesDirector,$seriesGenre,$seriesRelease,$seriesRating,$seriesRating5,$seriesBackdrop,$seriesTrailer,$seriesRuntime,$local_series_id]);

    $stmt_check = $pdo->prepare("SELECT stream_id, container_extension, visible
                                 FROM streams
                                 WHERE fournisseur_id = ? AND stream_type = 'episode' AND direct_source = ?
                                 LIMIT 1");
    $stmt_insert = $pdo->prepare("INSERT INTO streams
        (fournisseur_id,stream_name,stream_icon,stream_type,category_id,direct_source,container_extension,visible,remote_stream_id)
        VALUES (?,?,?,'episode',?,?,?,?,?)");

    foreach ($data['episodes'] as $season_key => &$episodes_list) {
        if (!is_array($episodes_list)) continue;

        foreach ($episodes_list as $ep_index => &$ep) {
            if (!is_array($ep) || empty($ep['id'])) continue;

            $remote_ep_id = trim((string)$ep['id']);
            $ep_title = (string)($ep['title'] ?? $ep['name'] ?? 'Episode');
            $ep_icon = (string)($ep['info']['movie_image'] ?? $ep['info']['cover_big'] ?? $series['stream_icon'] ?? '');
            $ep_ext = strtolower(trim((string)($ep['container_extension'] ?? $ep['container_ext'] ?? 'mp4')));
            $ep_ext = preg_replace('/[^a-z0-9]/i', '', $ep_ext) ?: 'mp4';

            $stmt_check->execute([$series['fournisseur_id'], $remote_ep_id]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $local_ep_id = (int)$existing['stream_id'];
                if (!empty($existing['container_extension'])) {
                    $ep_ext = strtolower($existing['container_extension']);
                }
                $pdo->prepare("UPDATE streams SET stream_name=?, stream_icon=?, container_extension=?, remote_stream_id=? WHERE stream_id=?")
                    ->execute([$ep_title,$ep_icon,$ep_ext,$remote_ep_id,$local_ep_id]);
            } else {
                $stmt_insert->execute([
                    $series['fournisseur_id'], $ep_title, $ep_icon, $series['category_id'],
                    $remote_ep_id, $ep_ext, 1, $remote_ep_id
                ]);
                $local_ep_id = (int)$pdo->lastInsertId();
            }

            $ep['id'] = (string)$local_ep_id;
            $ep['stream_id'] = (string)$local_ep_id;
            $ep['container_extension'] = $ep_ext;
            $ep['custom_sid'] = '';

            $ep['direct_source'] = $base_proxy_url . '/series/'
                . rawurlencode($user) . '/'
                . rawurlencode($pass) . '/'
                . rawurlencode((string)$local_ep_id) . '.'
                . $ep_ext;
        }
        unset($ep);
    }
    unset($episodes_list);

    if (!isset($data['info']) || !is_array($data['info'])) $data['info'] = [];
    if (!isset($data['info']['name'])) $data['info']['name'] = $series['stream_name'];
    if (!isset($data['info']['cover'])) $data['info']['cover'] = $series['stream_icon'] ?? '';
    if (!isset($data['seasons']) || !is_array($data['seasons'])) $data['seasons'] = [];

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==========================================
// 4. RÉPONSE D'AUTHENTIFICATION COMPATIBLE XTREAM CODES
// ==========================================
else {
    $host = $_SERVER['HTTP_HOST'];

    echo json_encode([
        'user_info' => [
            'username' => (string)$client['username'], 
            'password' => (string)$client['password'], 
            'message' => 'Welcome',
            'auth' => 1, 
            'status' => 'Active', 
            'exp_date' => '1798761600',
            'is_trial' => '0',
            'active_cons' => '0',
            'created_at' => '1600000000',
            'max_connections' => '1', 
            'allowed_output_formats' => ['m3u8', 'ts', 'mp4', 'mkv']
        ], 
        'server_info' => [
            'url' => $host, 
            'port' => '80', 
            'https_port' => '443',
            'server_protocol' => 'https',
            'rtmp_port' => '8880',
            'timezone' => 'Europe/Paris',
            'timestamp_now' => time(),
            'time_now' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
