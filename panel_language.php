<?php
require_once __DIR__ . '/panel_security.php';
panel_require_admin('panel:read');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); panel_fail(405, 'method_not_allowed'); }
panel_check_csrf();
$language = $_POST['language'] ?? '';
if (!in_array($language, ['fr', 'en', 'ar'], true)) panel_fail(422, 'invalid_request');
$path = panel_preference_path($_SESSION['panel_admin_id']);
if (!$path) panel_fail(503, 'preference_unavailable');
$temp = tempnam(dirname($path), '.language-');
if (!$temp) panel_fail(503, 'preference_unavailable');
chmod($temp, 0600);
$saved = file_put_contents($temp, json_encode(['language' => $language]), LOCK_EX) !== false && rename($temp, $path);
if (!$saved) { @unlink($temp); panel_fail(503, 'preference_unavailable'); }
$_SESSION['panel_language'] = $language;
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'language' => $language]);
