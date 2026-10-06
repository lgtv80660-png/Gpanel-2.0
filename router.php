<?php
// On récupère le chemin de l'URL demandée
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1. Interception pour le DIRECT (Live)
if (preg_match('#^/live/([^/]+)/([^/]+)/([^/]+)\.(.*)$#i', $path, $matches)) {
    $_GET['username'] = $matches[1];
    $_GET['password'] = $matches[2];
    $_GET['stream']   = $matches[3];
    $_GET['extension']= $matches[4];
    require __DIR__ . '/live.php';
    exit;
}

// 2. Interception pour la VOD (Films)
if (preg_match('#^/movie/([^/]+)/([^/]+)/([^/]+)\.(.*)$#i', $path, $matches)) {
    $_GET['username'] = $matches[1];
    $_GET['password'] = $matches[2];
    $_GET['stream']   = $matches[3];
    $_GET['extension']= $matches[4];
    require __DIR__ . '/vod.php';
    exit;
}

// 3. Interception pour les SÉRIES
if (preg_match('#^/series/([^/]+)/([^/]+)/([^/]+)\.(.*)$#i', $path, $matches)) {
    $_GET['username'] = $matches[1];
    $_GET['password'] = $matches[2];
    $_GET['stream']   = $matches[3];
    $_GET['extension']= $matches[4];
    require __DIR__ . '/series.php';
    exit;
}

// Comportement par défaut : charger le fichier PHP demandé s'il existe
$file = __DIR__ . $path;
if (is_file($file)) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        require $file;
        exit;
    }
    return false; 
}

// Sécurité par défaut : Rediriger vers l'API si le lien n'est pas clair
if (strpos($path, 'player_api.php') !== false) {
    require __DIR__ . '/player_api.php';
    exit;
}

return false;
?>
