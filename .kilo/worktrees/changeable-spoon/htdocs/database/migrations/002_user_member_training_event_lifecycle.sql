-- ============================================================================
-- Arnis Connect - Migration 002
-- USER <-> MEMBER <-> TRAINING <-> EVENT LIFECYCLE
--   * six system roles (adds "Head Coach")
--   * three member positions (Head Coach / Coach / Player)
--   * event Lead Coach / Handler assignment (events.handler_id)
--   * needs-replacement flags + previous-assignee memory for trainings/events
--   * connects Pending registration requests to an Applicant login account
--
-- Safe to re-run on MariaDB 10.5+ / 11.x. Additive only: no table is dropped,
-- no existing password is touched, no historical row is deleted.
--
-- Run AFTER migration 001 (uses the users.member_id link).
-- phpMyAdmin -> select the database -> SQL tab -> paste -> Go.
-- ============================================================================

SET @db_name = DATABASE();

-- ----------------------------------------------------------------------------
-- 1. USERS.ROLE  ->  six roles, with "Head Coach" added.
--    Retired roles (Committee / Board Member) are first moved to Member so the
--    ENUM change can never truncate a value.
-- ----------------------------------------------------------------------------
UPDATE `users` SET `role` = 'Member' WHERE `role` IN ('Committee','Board Member');

SET @has_head_coach = (
    SELECT LOCATE("'Head Coach'", column_type)
    FROM information_schema.columns
    WHERE table_schema = @db_name AND table_name = 'users' AND column_name = 'role'
    LIMIT 1
);

SET @sql = IF(
    COALESCE(@has_head_coach, 0) = 0,
    "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin','Head Coach','Coach/Trainer','Officer','Member','Applicant') NOT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. MEMBERS.MEMBER_TYPE  ->  three positions.
--    Legacy Trainer -> Coach, Athlete -> Player (data first, then ENUM).
-- ----------------------------------------------------------------------------
UPDATE `members` SET `member_type` = 'Coach'  WHERE `member_type` = 'Trainer';
UPDATE `members` SET `member_type` = 'Player' WHERE `member_type` = 'Athlete';

SET @has_tight_enums = (
    SELECT LOCATE("'Trainer'", column_type)
    FROM information_schema.columns
    WHERE table_schema = @db_name AND table_name = 'members' AND column_name = 'member_type'
    LIMIT 1
);

SET @sql = IF(
    COALESCE(@has_tight_enums, 0) > 0,
    "ALTER TABLE `members` MODIFY COLUMN `member_type` ENUM('Head Coach','Coach','Player') NOT NULL DEFAULT 'Player'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3. TRAININGS: needs-replacement flag + previous Lead Coach memory.
-- ----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = @db_name AND table_name = 'trainings' AND column_name = 'previous_coach_id'),
    'SELECT 1',
    'ALTER TABLE `trainings` ADD COLUMN `previous_coach_id` INT(11) NULL AFTER `coach_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = @db_name AND table_name = 'trainings' AND column_name = 'needs_coach'),
    'SELECT 1',
    'ALTER TABLE `trainings` ADD COLUMN `needs_coach` TINYINT(1) NOT NULL DEFAULT 0 AFTER `previous_coach_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = @db_name AND table_name = 'trainings' AND index_name = 'fk_trainings_previous_coach_id'),
    'SELECT 1',
    'ALTER TABLE `trainings` ADD KEY `fk_trainings_previous_coach_id` (`previous_coach_id`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.key_column_usage
            WHERE constraint_schema = @db_name AND table_name = 'trainings' AND constraint_name = 'fk_trainings_previous_coach_id'),
    'SELECT 1',
    'ALTER TABLE `trainings` ADD CONSTRAINT `fk_trainings_previous_coach_id` FOREIGN KEY (`previous_coach_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. EVENTS: Lead Coach / Event Handler assignment (+ needs-replacement flag).
--    Distinct from created_by (who filed the event) and from participants.
-- ----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = @db_name AND table_name = 'events' AND column_name = 'handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD COLUMN `handler_id` INT(11) NULL AFTER `updated_by`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = @db_name AND table_name = 'events' AND column_name = 'previous_handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD COLUMN `previous_handler_id` INT(11) NULL AFTER `handler_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = @db_name AND table_name = 'events' AND column_name = 'needs_handler'),
    'SELECT 1',
    'ALTER TABLE `events` ADD COLUMN `needs_handler` TINYINT(1) NOT NULL DEFAULT 0 AFTER `previous_handler_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = @db_name AND table_name = 'events' AND index_name = 'fk_events_handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD KEY `fk_events_handler_id` (`handler_id`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = @db_name AND table_name = 'events' AND index_name = 'fk_events_previous_handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD KEY `fk_events_previous_handler_id` (`previous_handler_id`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.key_column_usage
            WHERE constraint_schema = @db_name AND table_name = 'events' AND constraint_name = 'fk_events_handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD CONSTRAINT `fk_events_handler_id` FOREIGN KEY (`handler_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.key_column_usage
            WHERE constraint_schema = @db_name AND table_name = 'events' AND constraint_name = 'fk_events_previous_handler_id'),
    'SELECT 1',
    'ALTER TABLE `events` ADD CONSTRAINT `fk_events_previous_handler_id` FOREIGN KEY (`previous_handler_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5. CONNECT existing data (by record IDs, never by name).
-- ----------------------------------------------------------------------------

-- 5a. Head Coach members must hold the Head Coach login role (not Coach).
UPDATE `users` u
JOIN `members` m ON m.`member_id` = u.`member_id`
SET u.`role` = 'Head Coach'
WHERE m.`member_type` = 'Head Coach'
  AND u.`role` <> 'Head Coach'
  AND u.`role` IN ('Coach/Trainer','Member');

-- 5b. Coach members must hold Coach/Trainer.
UPDATE `users` u
JOIN `members` m ON m.`member_id` = u.`member_id`
SET u.`role` = 'Coach/Trainer'
WHERE m.`member_type` = 'Coach' AND u.`role` = 'Member';

-- 5c. Every active member without a login gets one (role from position).
INSERT INTO `users`
    (`full_name`, `username`, `email`, `member_id`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`)
SELECT m.`full_name`,
       CONCAT('member.', m.`member_id`),
       m.`email`,
       m.`member_id`,
       '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6',
       CASE m.`member_type`
            WHEN 'Head Coach' THEN 'Head Coach'
            WHEN 'Coach' THEN 'Coach/Trainer'
            ELSE 'Member'
       END,
       CASE WHEN m.`status` = 'Inactive' THEN 0 ELSE 1 END,
       NOW(), NOW()
FROM `members` m
LEFT JOIN `users` u ON u.`member_id` = m.`member_id`
WHERE u.`user_id` IS NULL
  AND m.`is_voided` = 0
  AND (m.`email` IS NULL OR NOT EXISTS (SELECT 1 FROM `users` ux WHERE ux.`email` = m.`email`))
  AND (m.`email` IS NULL OR NOT EXISTS (
        SELECT 1 FROM `members` mx
        WHERE mx.`email` = m.`email` AND mx.`member_id` < m.`member_id`));

-- 5d. Connect Pending registration requests that have no login account.
--     This is the fix for applicants (e.g. Sophia Fernandez) that appeared in
--     "Pending Registration Requests" but never in the Users list.
INSERT INTO `users`
    (`full_name`, `username`, `email`, `member_id`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`)
SELECT r.`full_name`,
       CONCAT('applicant.', r.`request_id`),
       r.`email`,
       NULL,
       '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6',
       'Applicant',
       1,
       COALESCE(r.`submitted_at`, NOW()),
       NOW()
FROM `registration_requests` r
LEFT JOIN `users` u ON u.`user_id` = r.`user_id`
WHERE r.`user_id` IS NULL
  AND r.`status` = 'Pending'
  AND u.`user_id` IS NULL
  AND NOT EXISTS (
        SELECT 1 FROM `users` ux
        WHERE ux.`role` = 'Applicant'
          AND ((r.`email` IS NOT NULL AND ux.`email` = r.`email`)
               OR ux.`username` = CONCAT('applicant.', r.`request_id`)));

UPDATE `registration_requests` r
JOIN `users` u ON u.`username` = CONCAT('applicant.', r.`request_id`)
SET r.`user_id` = u.`user_id`
WHERE r.`user_id` IS NULL AND r.`status` = 'Pending';

-- 5e. Seed an event handler for existing events that never had one, using the
--     event creator's linked member when that member is an eligible coach.
UPDATE `events` e
JOIN `users` u ON u.`user_id` = e.`created_by`
JOIN `members` m ON m.`member_id` = u.`member_id`
SET e.`handler_id` = m.`member_id`
WHERE e.`handler_id` IS NULL
  AND e.`is_voided` = 0
  AND m.`is_voided` = 0
  AND m.`status` = 'Active'
  AND m.`member_type` IN ('Head Coach','Coach');

-- ============================================================================
-- DONE.  Verify with:
--   SELECT role, COUNT(*) FROM users GROUP BY role;
--   SELECT member_type, COUNT(*) FROM members GROUP BY member_type;
--   SELECT r.request_id, r.full_name, r.status, r.user_id, u.username
--     FROM registration_requests r LEFT JOIN users u ON u.user_id = r.user_id
--     WHERE r.status = 'Pending';
-- ============================================================================
