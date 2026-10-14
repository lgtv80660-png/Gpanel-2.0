-- Additive only: no changes to catalogue, provider credentials or existing overrides.
CREATE TABLE IF NOT EXISTS source_host_roles (
 fournisseur_id INT NOT NULL,
 kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 backup_order INT DEFAULT NULL,
 primary_slot INT DEFAULT NULL,
 PRIMARY KEY (fournisseur_id,kind),
 UNIQUE KEY one_primary (kind,primary_slot),
 UNIQUE KEY backup_position (kind,backup_order),
 CHECK (kind IN ('live','movie','series')),
 CHECK ((role='primary' AND primary_slot IS NOT NULL AND primary_slot=1 AND backup_order IS NULL) OR
        (role='backup' AND primary_slot IS NULL AND backup_order IS NOT NULL AND backup_order>0) OR
        (role='neutral' AND primary_slot IS NULL AND backup_order IS NULL))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_channel_policy (
 stream_id INT NOT NULL PRIMARY KEY,
 mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INT NOT NULL DEFAULT 1,
 CHECK (mode IN ('inherit','custom'))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_channel_links (
 stream_id INT NOT NULL,
 position INT NOT NULL,
 candidate_id INT DEFAULT NULL,
 manual_url TEXT DEFAULT NULL,
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (stream_id,position),
 UNIQUE KEY unique_source (stream_id,source_key),
 CHECK (position>=0),
 CHECK ((candidate_id IS NOT NULL AND manual_url IS NULL) OR
        (candidate_id IS NULL AND manual_url IS NOT NULL))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_policy_version (
 id INT NOT NULL PRIMARY KEY,
 revision INT NOT NULL
) ENGINE=InnoDB;
