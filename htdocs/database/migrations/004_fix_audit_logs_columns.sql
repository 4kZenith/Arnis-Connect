-- ============================================================================
-- 004 - Align an older audit_logs table with classes/AuditLog.php.
--
-- Databases created before migration 003 have audit_logs(id, target_table,
-- target_id). 003 used CREATE TABLE IF NOT EXISTS, so it never replaced that
-- table and every audit insert failed. AuditLog::ensureSchema() applies this
-- automatically on first use; this file is the manual equivalent.
-- Run once in phpMyAdmin. Skip if audit_logs already has an audit_id column.
-- ============================================================================

ALTER TABLE `audit_logs`
  CHANGE `id` `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD `record_id` INT(11) DEFAULT NULL AFTER `module`,
  ADD `meta_json` TEXT DEFAULT NULL AFTER `description`;

UPDATE `audit_logs` SET `record_id` = `target_id` WHERE `record_id` IS NULL;
