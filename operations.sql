CREATE TABLE IF NOT EXISTS panel_admin_roles (
 admin_id INT NOT NULL PRIMARY KEY,
 role VARCHAR(20) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS panel_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 admin_id INT NULL,
 event VARCHAR(80) NOT NULL,
 target_id INT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_audit_date (created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS panel_settings (
 setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
 setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS panel_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 dedupe_key CHAR(64) CHARACTER SET ascii NOT NULL,
 client_id INT NOT NULL,
 pack_id INT NULL,
 stream_id INT NOT NULL,
 content_type VARCHAR(16) NOT NULL,
 event VARCHAR(24) NOT NULL,
 device VARCHAR(16) NULL,
 duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 UNIQUE KEY idx_event_dedupe (dedupe_key),
 KEY idx_event_date (created_at,event,content_type),
 KEY idx_event_client (client_id,created_at),
 KEY idx_event_pack (pack_id,created_at),
 KEY idx_event_stream (stream_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS panel_live_state (
 stream_id INT NOT NULL PRIMARY KEY,
 state VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
 response_ms INT NULL,
 checked_at DATETIME NULL,
 error_code VARCHAR(64) NULL,
 http_status INT NULL,
 lease_until DATETIME NULL,
 lease_token CHAR(32) NULL,
 backup_stream_id INT NULL,
 KEY idx_live_check (checked_at,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO panel_admin_roles (admin_id,role) SELECT id,'super_admin' FROM admins WHERE NOT EXISTS (SELECT 1 FROM panel_settings WHERE setting_key='rbac_bootstrapped');
INSERT IGNORE INTO panel_settings (setting_key,setting_value) VALUES ('rbac_bootstrapped','1');
