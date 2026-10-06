<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Authentification locale
$user = strtolower(trim((string)(
    $_GET['username'] ?? $_GET['user'] ?? ''
)));
$pass = (string)(
    $_GET['password'] ?? $_GET['pass'] ?? ''
);

if ($user === '' || $pass === '') {
    http_response_code(400);
    exit('Username and Password required.');
}

$stmt = $pdo->prepare(
    'SELECT id FROM clients
     WHERE LOWER(username) = ?
       AND password = ?
       AND active = 1
     LIMIT 1'
);
$stmt->execute([$user, $pass]);

if (!$stmt->fetch()) {
    http_response_code(401);
    exit('Authentication failed.');
}

// Adresse locale du panel
$https = !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

$scheme = $https ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Chemin de l'installation
$basePath = rtrim(
    str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])),
    '/'
);

if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

$base = $scheme . '://' . $host . $basePath;

// Encodage des identifiants
$u = rawurlencode($user);
$p = rawurlencode($pass);

// Lecture du catalogue local
$sql = "
    SELECT
        s.stream_id,
        s.stream_name,
        s.stream_icon,
        s.stream_type,
        s.category_id,
        s.container_extension,
        c.category_name
    FROM streams s
    INNER JOIN fournisseurs f
        ON f.id = s.fournisseur_id
    LEFT JOIN categories c
        ON c.fournisseur_id = s.fournisseur_id
        AND c.remote_category_id = s.category_id
        AND (
            (s.stream_type = 'live'
                AND c.content_type = 'live')
            OR
            (s.stream_type = 'movie'
                AND c.content_type = 'movie')
            OR
            (s.stream_type = 'episode'
    AND c.content_type = 'series')
        )
    WHERE s.visible = 1
      AND f.active = 1
      AND s.stream_type IN ('live', 'movie', 'episode')
    ORDER BY
        s.stream_type,
        c.category_name,
        s.stream_name
";

try {
    $stmt = $pdo->query($sql);
} catch (Throwable $e) {
    error_log('G-PANEL M3U SQL error: ' . $e->getMessage());
    http_response_code(500);
    exit('Playlist database error.');
}

// Génération de la playlist
header('Content-Type: audio/x-mpegurl; charset=utf-8');
header(
    'Content-Disposition: attachment; filename="playlist.m3u"'
);

echo "#EXTM3U\n";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['stream_id'];

    $name = trim((string)$row['stream_name']);
    $name = str_replace(["\r", "\n", '"'], ' ', $name);

    $icon = trim((string)($row['stream_icon'] ?? ''));
    $icon = str_replace(["\r", "\n", '"'], '', $icon);

    $category = trim(
        (string)($row['category_name'] ?? '')
    );
    $category = str_replace(
        ["\r", "\n", '"'],
        ' ',
        $category
    );

    $type = (string)$row['stream_type'];

    if ($type === 'live') {
        $path = 'live';
        $ext = 'ts';
        $group = 'LIVE';
    } elseif ($type === 'movie') {
        $path = 'movie';
        $ext = strtolower(
            (string)($row['container_extension'] ?: 'mp4')
        );
        $group = 'FILMS';
} else {
    $path = 'series';
    $ext = strtolower(
        (string)($row['container_extension'] ?: 'mp4')
    );
    $group = 'SÉRIES';
}

    if (!preg_match('/^[a-z0-9]{2,5}$/', $ext)) {
        $ext = 'mp4';
    }

if ($category !== '') {
    $group .= ' | ' . $category;
}

    $url = $base
        . '/' . $path
        . '/' . $u
        . '/' . $p
        . '/' . $id
        . '.' . $ext;

    echo '#EXTINF:-1'
        . ' tvg-id="' . $id . '"'
        . ' tvg-name="' . $name . '"'
        . ' tvg-logo="' . $icon . '"'
        . ' group-title="' . $group . '",'
        . $name . "\n";

    echo $url . "\n";
}