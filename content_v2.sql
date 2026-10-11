CREATE TABLE IF NOT EXISTS content_settings (
 stream_id INT NOT NULL PRIMARY KEY,
 quality VARCHAR(16) DEFAULT NULL,
 quality_origin VARCHAR(16) DEFAULT NULL,
 source_override TEXT DEFAULT NULL,
 source_provider INT DEFAULT NULL,
 source_remote VARCHAR(255) DEFAULT NULL,
 series_parent INT DEFAULT NULL,
 season_number INT DEFAULT NULL,
 episode_number INT DEFAULT NULL,
 production_ref VARCHAR(128) DEFAULT NULL,
 KEY idx_personal_series (series_parent)
) ENGINE=InnoDB;
