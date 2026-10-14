-- =============================================================================
-- Migration : 2026-10-14_084306_alter_users_table.sql
-- Created   : 2026-10-14 08:43:06 PST
-- Database  : lpg_delivery_v2
-- Table     : users
-- Depends on: (none)
-- Purpose   : Track automatic purge warning for rejected government ID applicants.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-10-14_084306_alter_users_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


ALTER TABLE `users`
    ADD COLUMN `purge_warned_at` datetime DEFAULT NULL AFTER `id_reviewed_at`, ADD INDEX `idx_verification_reviewed` (`id_verification_status`, `id_reviewed_at`);

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-10-14_084306_alter_users_table.sql', 'users', 'Track automatic purge warning for rejected government ID applicants.');
