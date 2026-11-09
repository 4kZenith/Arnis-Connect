-- Arnis Connect - Database Integrity & Authentication Foundation
-- Apply after the current arnis_connect database export has been imported.
-- This migration is written to be safely re-runnable on MariaDB 10.5+ / 11.x.

SET @db_name = DATABASE();

-- --------------------------------------------------------
-- USERS: authentication/session linkage
-- --------------------------------------------------------

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND column_name = 'member_id'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN member_id INT(11) NULL AFTER email'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND column_name = 'email_verified_at'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER member_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND column_name = 'mobile_verified_at'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN mobile_verified_at DATETIME NULL AFTER email_verified_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND column_name = 'last_login_at'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER mobile_verified_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND column_name = 'session_version'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN session_version INT(11) UNSIGNED NOT NULL DEFAULT 1 AFTER last_login_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the member and applicant roles while preserving every existing role/value.
SET @has_member_role = (
    SELECT LOCATE("'Member'", column_type)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'users'
      AND column_name = 'role'
    LIMIT 1
);

SET @sql = IF(
    COALESCE(@has_member_role, 0) = 0,
    "ALTER TABLE users MODIFY COLUMN role ENUM('Coach/Trainer','Officer','Committee','Board Member','SuperAdmin','Member','Applicant') NOT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- MEMBERS: restore documented created/updated user relations
-- --------------------------------------------------------

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db_name
          AND table_name = 'members'
          AND index_name = 'idx_members_created_by'
    ),
    'SELECT 1',
    'ALTER TABLE members ADD KEY idx_members_created_by (created_by)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db_name
          AND table_name = 'members'
          AND index_name = 'idx_members_updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE members ADD KEY idx_members_updated_by (updated_by)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage
        WHERE constraint_schema = @db_name
          AND table_name = 'members'
          AND constraint_name = 'fk_members_created_by'
    ),
    'SELECT 1',
    'ALTER TABLE members ADD CONSTRAINT fk_members_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage
        WHERE constraint_schema = @db_name
          AND table_name = 'members'
          AND constraint_name = 'fk_members_updated_by'
    ),
    'SELECT 1',
    'ALTER TABLE members ADD CONSTRAINT fk_members_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Link a system user to the official member record representing that person.
SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db_name
          AND table_name = 'users'
          AND index_name = 'idx_users_member_id'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD KEY idx_users_member_id (member_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage
        WHERE constraint_schema = @db_name
          AND table_name = 'users'
          AND constraint_name = 'fk_users_member_id'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD CONSTRAINT fk_users_member_id FOREIGN KEY (member_id) REFERENCES members(member_id) ON UPDATE CASCADE ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- REGISTRATION_REQUESTS: preserve applicant -> member lifecycle
-- --------------------------------------------------------

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = @db_name
          AND table_name = 'registration_requests'
          AND column_name = 'member_id'
    ),
    'SELECT 1',
    'ALTER TABLE registration_requests ADD COLUMN member_id INT(11) NULL AFTER review_notes'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = @db_name
          AND table_name = 'registration_requests'
          AND index_name = 'idx_registration_member_id'
    ),
    'SELECT 1',
    'ALTER TABLE registration_requests ADD KEY idx_registration_member_id (member_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage
        WHERE constraint_schema = @db_name
          AND table_name = 'registration_requests'
          AND constraint_name = 'fk_registration_member_id'
    ),
    'SELECT 1',
    'ALTER TABLE registration_requests ADD CONSTRAINT fk_registration_member_id FOREIGN KEY (member_id) REFERENCES members(member_id) ON UPDATE CASCADE ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure every existing user has a valid session version after the column is introduced.
UPDATE users
SET session_version = 1
WHERE session_version IS NULL OR session_version = 0;
