<?php
/**
 * Centralized, idempotent database migrations for IPTV Panel V5.
 * Safe to call from every PHP endpoint; migrations execute only once per DB version.
 */
function ensure_panel_schema(PDO $pdo): void {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_schema (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        version INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $row = $pdo->query("SELECT version FROM panel_schema WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $version = $row ? (int)$row['version'] : 0;
    if ($version >= 5) return;

    $migrations = [
        1 => [
            "ALTER TABLE categories MODIFY category_id INT AUTO_INCREMENT",
            "ALTER TABLE streams MODIFY stream_id INT AUTO_INCREMENT",
            "ALTER TABLE categories ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE streams ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE streams ADD COLUMN fournisseur_id INT NULL",
            "ALTER TABLE categories ADD COLUMN fournisseur_id INT NULL",
        ],
        2 => [
            "ALTER TABLE streams ADD COLUMN container_extension VARCHAR(20) NULL",
            "ALTER TABLE streams ADD COLUMN remote_stream_id VARCHAR(255) NULL",
            "ALTER TABLE categories ADD COLUMN remote_category_id VARCHAR(255) NULL",
            "ALTER TABLE categories ADD COLUMN content_type VARCHAR(20) NULL",
        ],
        3 => [
            "ALTER TABLE streams MODIFY COLUMN stream_icon TEXT NULL",
            "ALTER TABLE streams MODIFY COLUMN direct_source TEXT NULL",
        ],
        4 => [
            "ALTER TABLE streams ADD COLUMN vod_plot TEXT NULL",
            "ALTER TABLE streams ADD COLUMN vod_cast TEXT NULL",
            "ALTER TABLE streams ADD COLUMN vod_director TEXT NULL",
            "ALTER TABLE streams ADD COLUMN vod_genre VARCHAR(500) NULL",
            "ALTER TABLE streams ADD COLUMN vod_release_date VARCHAR(32) NULL",
            "ALTER TABLE streams ADD COLUMN vod_rating VARCHAR(32) NULL",
            "ALTER TABLE streams ADD COLUMN vod_rating_5based DECIMAL(6,2) NULL",
            "ALTER TABLE streams ADD COLUMN vod_added VARCHAR(32) NULL",
            "ALTER TABLE streams ADD COLUMN vod_backdrop TEXT NULL",
            "ALTER TABLE streams ADD COLUMN vod_trailer TEXT NULL",
            "ALTER TABLE streams ADD COLUMN vod_runtime VARCHAR(32) NULL",
        ],
        5 => [
            "CREATE INDEX idx_streams_provider_type ON streams (fournisseur_id, stream_type)",
            "CREATE INDEX idx_streams_remote ON streams (fournisseur_id, stream_type, remote_stream_id(191))",
            "CREATE INDEX idx_categories_provider_type ON categories (fournisseur_id, content_type)",
            "CREATE INDEX idx_categories_remote ON categories (fournisseur_id, content_type, remote_category_id(191))",
        ],
    ];

    for ($v = $version + 1; $v <= 5; $v++) {
        if (!isset($migrations[$v])) continue;
        foreach ($migrations[$v] as $sql) {
            try { $pdo->exec($sql); }
            catch (Throwable $e) {
                // ADD COLUMN / MODIFY may already exist on older installations.
                // Ignore duplicate/unsupported migration errors and continue.
            }
        }
        $stmt = $pdo->prepare("INSERT INTO panel_schema (id, version) VALUES (1, ?) ON DUPLICATE KEY UPDATE version = VALUES(version)");
        $stmt->execute([$v]);
    }
}


/** V5.1: remove categories whose supplier no longer exists. Safe/idempotent. */
function cleanup_orphan_categories(PDO $pdo): int {
    try {
        $stmt = $pdo->query("DELETE c FROM categories c LEFT JOIN fournisseurs f ON f.id = c.fournisseur_id WHERE f.id IS NULL");
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
