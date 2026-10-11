<?php
require_once __DIR__ . '/panel_security.php';
panel_require_admin('cache:revalidate');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); panel_fail(405, 'method_not_allowed'); }
panel_check_csrf();
require_once __DIR__ . '/panel_revalidation.php';
if (!panel_revalidate()) panel_fail(502, $GLOBALS['panel_revalidation_error'] ?? 'revalidation_failed');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => panel_t('revalidation_ok')], JSON_UNESCAPED_UNICODE);
