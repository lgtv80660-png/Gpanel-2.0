<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--apply', $argv, true) || !in_array('--backup-confirmed', $argv, true)) {
    fwrite(STDERR, "Usage: php migrate_v2.php --apply --backup-confirmed\nVerify a restorable backup first. Never run on a system schema.\n");
    exit(2);
}
require __DIR__.'/config.php';
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('MySQL required');
$database = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$database || in_array(strtolower($database), ['mysql','sys','information_schema','performance_schema'], true)) throw new RuntimeException('Use a dedicated application database');
if ((int)$pdo->query("SELECT GET_LOCK('gpanel-v2-migrate', 0)")->fetchColumn() !== 1) throw new RuntimeException('Migration already running');
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_exclusions (fournisseur_id INT NOT NULL, content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, remote_stream_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, PRIMARY KEY (fournisseur_id,content_type,remote_stream_id)) ENGINE=InnoDB");
    foreach (explode(';', file_get_contents(__DIR__.'/content_v2.sql')) as $ddl) { if (trim($ddl) !== '') $pdo->exec($ddl); }
    foreach (explode(';', file_get_contents(__DIR__.'/movie_groups.sql')) as $ddl) { if (trim($ddl) !== '') $pdo->exec($ddl); }
    foreach (['streams','categories'] as $table) {
        $before = (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        $stmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $engine = $stmt->fetchColumn();
        if (!$engine) throw new RuntimeException('Missing application table');
        if (strcasecmp($engine, 'InnoDB') !== 0) $pdo->exec('ALTER TABLE '.$table.' ENGINE=InnoDB');
        if ($before !== (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()) throw new RuntimeException('Row count changed; restore backup');
        echo $table." count preserved: ".$before."\n";
    }
    $indexes = [
        'streams' => ['idx_v2_catalog' => '(stream_type, visible, category_id)', 'idx_v2_identity' => '(fournisseur_id, stream_type, remote_stream_id)', 'idx_v2_episode_transport' => '(fournisseur_id, stream_type, direct_source(191))'],
        'categories' => ['idx_v2_category_identity' => '(fournisseur_id, content_type, remote_category_id)']
    ];
    foreach ($indexes as $table => $definitions) {
        $names = array_column($pdo->query('SHOW INDEX FROM '.$table)->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
        foreach ($definitions as $name => $columns) if (!in_array($name, $names, true)) $pdo->exec('CREATE INDEX '.$name.' ON '.$table.' '.$columns);
    }
    echo "V2 migration complete. No IDs, credentials or rows rewritten.\n";
} finally {
    $pdo->query("SELECT RELEASE_LOCK('gpanel-v2-migrate')");
}
