-- =============================================================================
-- Migration : 2026-10-12_185849_alter_users_table.sql
-- Created   : 2026-10-12 18:58:49 PST
-- Database  : lpg_delivery_v2
-- Table     : users
-- Depends on: (none)
-- Purpose   : Add government ID verification workflow columns (status, rejection reason, reviewer, timestamp).
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-10-12_185849_alter_users_table.sql
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
    ADD COLUMN `id_verification_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER `status`, ADD COLUMN `id_rejection_reason` varchar(500) DEFAULT NULL AFTER `id_verification_status`, ADD COLUMN `id_reviewed_by` int(11) DEFAULT NULL AFTER `id_rejection_reason`, ADD COLUMN `id_reviewed_at` datetime DEFAULT NULL AFTER `id_reviewed_by`;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-10-12_185849_alter_users_table.sql', 'users', 'Add government ID verification workflow columns (status, rejection reason, reviewer, timestamp).');
