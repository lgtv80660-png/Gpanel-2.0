<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__.'/config.php';
require_once __DIR__.'/panel_import.php';
try {
    panel_import_require_transactions($pdo);
    if ($pdo->query("SELECT version FROM panel_installation WHERE id=1")->fetchColumn() !== '2.0') throw new RuntimeException('Not initialized');
    echo '{"status":"ready","version":"2.0"}';
} catch (Throwable $e) { http_response_code(503); echo '{"status":"not_ready"}'; }
