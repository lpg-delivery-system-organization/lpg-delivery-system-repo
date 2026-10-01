-- =============================================================================
-- Migration : 2026-09-30_181700_create_schema_migrations_table.sql
-- Created   : 2026-09-30 18:17:00 Asia/Manila
-- Database  : lpg_delivery_v2
-- Table     : schema_migrations
-- Depends on: (none)
-- Purpose   : Ledger that records which migrations have been applied to a
--             database, so you can tell at a glance what is still pending
--             before running any SQL by hand.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_181700_create_schema_migrations_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- This is the FIRST file to run in this folder: every other migration writes a
-- row into this table.
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `table_name` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_181700_create_schema_migrations_table.sql', 'schema_migrations', 'Ledger of applied migrations');
