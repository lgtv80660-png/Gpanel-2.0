<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__.'/config.php';
require_once __DIR__.'/panel_import.php';
try {
    panel_import_require_transactions($pdo);
    if ($pdo->query("SELECT marker FROM panel_test_bootstrap WHERE id=1")->fetchColumn() !== 'gpanel-v2-synthetic-test-v1') throw new RuntimeException('Not initialized');
    echo '{"status":"ready","environment":"test"}';
} catch (Throwable $e) { http_response_code(503); echo '{"status":"not_ready"}'; }
