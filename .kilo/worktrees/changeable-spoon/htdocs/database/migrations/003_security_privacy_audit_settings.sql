-- ============================================================================
-- Arnis Connect - Migration 003
-- SECURITY, PRIVACY, AUDIT, SETTINGS, ANNOUNCEMENTS
--
--   * users: email-verification token/expiry + privacy-consent timestamp
--   * audit_logs: real append-only audit trail (replaces the derived feed)
--   * settings: System Settings key/value store with safe defaults
--   * announcements: minimal announcement module
--   * supporting indexes
--
-- Safe to re-run on MariaDB 10.5+ / 11.x. Additive only: no table is dropped,
-- no password is touched, no historical row is deleted.
--
-- Run AFTER migrations 001 and 002.
-- phpMyAdmin -> select the database -> SQL tab -> paste -> Go.
-- ============================================================================

SET @db_name = DATABASE();

-- ----------------------------------------------------------------------------
-- 1. USERS: email verification + privacy consent
--    email_verified_at already exists in the base schema; the token is what
--    distinguishes a NEW unverified registration from a grandfathered account.
-- ----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = @db_name AND table_name = 'users'
       AND column_name = 'email_verification_token') = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verification_token` VARCHAR(64) NULL AFTER `email_verified_at`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = @db_name AND table_name = 'users'
       AND column_name = 'email_verification_expires_at') = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verification_expires_at` DATETIME NULL AFTER `email_verification_token`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = @db_name AND table_name = 'users'
       AND column_name = 'privacy_consent_at') = 0,
    'ALTER TABLE `users` ADD COLUMN `privacy_consent_at` DATETIME NULL AFTER `email_verification_expires_at`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db_name AND table_name = 'users'
       AND index_name = 'uq_users_email_verification_token') = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email_verification_token` (`email_verification_token`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. AUDIT LOGS - one intentional row per action, never derived from
--    created_at/updated_at columns. Actor name/role are snapshotted so the
--    trail stays readable even if the account is later renamed.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `actor_name` varchar(100) NOT NULL DEFAULT 'System',
  `actor_role` varchar(50) NOT NULL DEFAULT '',
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `meta_json` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_created_at` (`created_at`),
  KEY `idx_audit_user_id` (`user_id`),
  KEY `idx_audit_module_action` (`module`,`action`),
  KEY `idx_audit_record` (`module`,`record_id`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3. SETTINGS - key/value store used by classes/Settings.php.
--    Insert defaults only when missing so re-running never overwrites choices.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `idx_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('organization_name', 'Arnis Connect'),
  ('contact_email', ''),
  ('from_email', ''),
  ('from_name', 'Arnis Connect'),
  ('allow_registration', '1'),
  ('require_email_verification', '1'),
  ('email_notifications', '1'),
  ('face_match_threshold', '0.60'),
  ('default_geofence_m', '100'),
  ('privacy_contact', '');

-- ----------------------------------------------------------------------------
-- 4. ANNOUNCEMENTS - minimal, email-backed announcements.
--    audience controls both the in-app list and who receives email.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `body` text NOT NULL,
  `audience` enum('All','Members','Coaches','Officers') NOT NULL DEFAULT 'All',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`announcement_id`),
  KEY `idx_announcements_created_at` (`created_at`),
  KEY `idx_announcements_archived` (`is_archived`),
  KEY `idx_announcements_created_by` (`created_by`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 5. SUPPORTING INDEXES (guarded so re-runs are safe)
-- ----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db_name AND table_name = 'registration_requests'
       AND index_name = 'idx_registration_status_submitted') = 0,
    'ALTER TABLE `registration_requests` ADD KEY `idx_registration_status_submitted` (`status`,`submitted_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db_name AND table_name = 'attendance_checkins'
       AND index_name = 'idx_checkins_training_member') = 0,
    'ALTER TABLE `attendance_checkins` ADD KEY `idx_checkins_training_member` (`training_id`,`member_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
