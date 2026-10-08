<?php
// Administrative routes only: never loaded by Xtream or playback routes.
require_once __DIR__ . '/panel_i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
    session_start();
}
header('Cache-Control: no-store');
ini_set('display_errors', '0');
if (isset($_SESSION['panel_last_seen']) && time() - $_SESSION['panel_last_seen'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (empty($_SESSION['panel_csrf'])) $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
function panel_fail($status, $code) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => panel_t($code)]], JSON_UNESCAPED_UNICODE);
    exit;
}
function panel_require_admin($permission) {
    if (($_SESSION['admin_logged'] ?? false) !== true || empty($_SESSION['panel_admin_id'])) panel_fail(401, 'auth_required');
    require_once __DIR__.'/panel_operations.php';
    global $pdo;
    if(!isset($pdo)) require __DIR__.'/config.php';
    try {if(!ops_authorize($pdo,(int)$_SESSION['panel_admin_id'],(string)$permission)) panel_fail(403,'permission_denied');}
    catch(Throwable $e) {panel_fail(503,'database_unavailable');}
    // Existing environment permission maps remain an additional restriction.
    $raw = getenv('PANEL_ADMIN_PERMISSIONS');
    if ($raw !== false && $raw !== '') {
        $map = json_decode($raw, true);
        $allowed = is_array($map) ? ($map[(string)$_SESSION['panel_admin_id']] ?? []) : [];
        if (!is_array($allowed) || !in_array($permission, $allowed, true)) panel_fail(403, 'permission_denied');
    }
    $_SESSION['panel_last_seen'] = time();
    static $auditRegistered=false;
    if(!$auditRegistered && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        $auditRegistered=true;$actor=(int)$_SESSION['panel_admin_id'];
        $route=basename((string)($_SERVER['SCRIPT_FILENAME']??''));
        $routes=['admin.php','packs.php','editor.php','content_edit.php','personal_content.php','importer.php','import_selective.php','movie_duplicates.php','revalidate_proxy.php'];
        if(in_array($route,$routes,true)) register_shutdown_function(function()use($pdo,$actor,$route){
            ops_audit($pdo,'request.'.pathinfo($route,PATHINFO_FILENAME).'.http_'.http_response_code(),null,$actor);
        });
    }
}
function panel_check_csrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['panel_csrf'], $token)) panel_fail(403, 'csrf_invalid');
}
function panel_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['panel_csrf'], ENT_QUOTES, 'UTF-8') . '">';
}
function panel_ui_bootstrap() {
    $config = ['lang' => panel_language(), 'csrf' => $_SESSION['panel_csrf'], 'authenticated' => !empty($_SESSION['panel_admin_id'])];
    return '<script>window.PANEL_CONFIG=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}
