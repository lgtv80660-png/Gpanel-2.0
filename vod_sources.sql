-- Additive configuration only. Never executed by a web request.
CREATE TABLE IF NOT EXISTS source_vod_policy (
 stream_id INT NOT NULL PRIMARY KEY,
 mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INT NOT NULL DEFAULT 1,
 CHECK (mode IN ('custom','hosts','legacy')),
 CHECK (revision>0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_vod_links (
 stream_id INT NOT NULL,
 position INT NOT NULL,
 enabled INT NOT NULL DEFAULT 1,
 candidate_id INT DEFAULT NULL,
 manual_url TEXT DEFAULT NULL,
 variant_note VARCHAR(255) NOT NULL DEFAULT '',
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (stream_id,position),
 UNIQUE KEY unique_vod_source (stream_id,source_key),
 CHECK (position>=0),
 CHECK (enabled IN (0,1)),
 CHECK ((candidate_id IS NOT NULL AND manual_url IS NULL) OR (candidate_id IS NULL AND manual_url IS NOT NULL))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_series_matches (
 series_a INT NOT NULL,
 series_b INT NOT NULL,
 PRIMARY KEY (series_a,series_b),
 CHECK (series_a<series_b)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS source_live_disabled (
 stream_id INT NOT NULL,
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (stream_id,source_key)
) ENGINE=InnoDB;
