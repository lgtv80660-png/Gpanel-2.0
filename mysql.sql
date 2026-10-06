-- Schema only for a NEW empty local database. Never import over an existing database.
-- Data and credentials intentionally excluded. Existing uploaded dump remains in Git history.
SET NAMES utf8mb4;
CREATE TABLE import_exclusions (
  fournisseur_id INT NOT NULL,
  content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  remote_stream_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (fournisseur_id,content_type,remote_stream_id)
) ENGINE=InnoDB;


CREATE TABLE `admins` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `admins`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

CREATE TABLE `categories` (
  `category_id` int NOT NULL,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` int DEFAULT '0',
  `visible` tinyint(1) DEFAULT '1',
  `fournisseur_id` int DEFAULT NULL,
  `remote_category_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD KEY `idx_categories_provider_type` (`fournisseur_id`,`content_type`),
  ADD KEY `idx_categories_remote` (`fournisseur_id`,`content_type`,`remote_category_id`(191));

ALTER TABLE `categories`
  MODIFY `category_id` int NOT NULL AUTO_INCREMENT;

CREATE TABLE `clients` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `clients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

CREATE TABLE `fournisseurs` (
  `id` int NOT NULL,
  `nom` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('xtream','m3u','stalker') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_base` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `pass` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `mac_address` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `proxy` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `fournisseurs`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `fournisseurs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

CREATE TABLE `panel_schema` (
  `id` tinyint UNSIGNED NOT NULL,
  `version` int NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `panel_schema`
  ADD PRIMARY KEY (`id`);

CREATE TABLE `streams` (
  `stream_id` int NOT NULL,
  `category_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fournisseur_id` int NOT NULL,
  `stream_name` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stream_icon` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `stream_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'live',
  `direct_source` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `visible` tinyint(1) DEFAULT '1',
  `container_extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remote_stream_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vod_plot` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vod_cast` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vod_director` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vod_genre` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vod_release_date` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vod_rating` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vod_rating_5based` decimal(6,2) DEFAULT NULL,
  `vod_added` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vod_backdrop` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vod_trailer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vod_runtime` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `streams`
  ADD PRIMARY KEY (`stream_id`),
  ADD KEY `idx_streams_provider_type` (`fournisseur_id`,`stream_type`),
  ADD KEY `idx_streams_remote` (`fournisseur_id`,`stream_type`,`remote_stream_id`(191));

ALTER TABLE `streams`
  MODIFY `stream_id` int NOT NULL AUTO_INCREMENT;
