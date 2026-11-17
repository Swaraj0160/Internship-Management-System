-- Migration 02: AI Service Logs, Drafts, and Community Enhancements
-- InternHub University Platform Transformation

CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `feature` varchar(50) NOT NULL,
  `prompt_length` int(11) DEFAULT 0,
  `response_length` int(11) DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT 'local',
  `model` varchar(50) NOT NULL DEFAULT 'local-rule-v1',
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id_idx` (`user_id`),
  KEY `feature_idx` (`feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_saved_drafts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `draft_type` varchar(50) NOT NULL,
  `draft_title` varchar(255) DEFAULT NULL,
  `draft_content` text NOT NULL,
  `meta_data` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_draft_idx` (`user_id`, `draft_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add attachment_file and tags to community_posts if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_posts' AND COLUMN_NAME='attachment_file');
SET @sql := IF(@exist = 0, 'ALTER TABLE `community_posts` ADD COLUMN `attachment_file` varchar(255) DEFAULT NULL AFTER `content`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_posts' AND COLUMN_NAME='tags');
SET @sql := IF(@exist = 0, 'ALTER TABLE `community_posts` ADD COLUMN `tags` varchar(255) DEFAULT NULL AFTER `attachment_file`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
