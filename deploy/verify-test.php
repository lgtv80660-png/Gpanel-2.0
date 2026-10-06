<?php
if (PHP_SAPI !== 'cli' || getenv('PANEL_TEST_ENV') !== '1' || getenv('MYSQLDATABASE') !== 'gpanel_v2_test') exit(2);
require '/var/www/html/config.php';
require '/var/www/html/panel_import.php';
try {
    panel_import_require_transactions($pdo);
    $names = array_column($pdo->query('SHOW INDEX FROM streams')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
    foreach (['idx_v2_catalog','idx_v2_identity','idx_v2_episode_transport'] as $index) if (!in_array($index,$names,true)) throw new RuntimeException('Missing migration index');
    $admin = $pdo->query('SELECT password FROM admins WHERE id=1')->fetchColumn();
    if (!$admin || !password_verify((string)getenv('TEST_ADMIN_PASSWORD'),$admin)) throw new RuntimeException('Test admin missing');
    if ($pdo->query('SELECT password FROM clients WHERE id=1')->fetchColumn() !== getenv('TEST_CLIENT_PASSWORD')) throw new RuntimeException('Test client missing');
    $provider = $pdo->query('SELECT url_base FROM fournisseurs WHERE id=1')->fetchColumn();
    if (parse_url((string)$provider,PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Synthetic provider changed');
    foreach ([1=>'live',2=>'movie',3=>'series',4=>'episode'] as $id=>$type) {
        $stmt=$pdo->prepare('SELECT stream_type FROM streams WHERE stream_id=?');$stmt->execute([$id]);
        if ($stmt->fetchColumn() !== $type) throw new RuntimeException('Fixture ID changed');
    }
    echo "PASS MySQL V2 indexes, InnoDB, preserved synthetic IDs and test authentication.\n";
} catch (Throwable $e) { fwrite(STDERR,"Test verification failed; panel will not start.\n");exit(1); }
