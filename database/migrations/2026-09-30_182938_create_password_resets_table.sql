-- =============================================================================
-- Migration : 2026-09-30_182938_create_password_resets_table.sql
-- Created   : 2026-09-30 18:29:38 PST
-- Database  : lpg_delivery_v2
-- Table     : password_resets
-- Depends on: `users`
-- Purpose   : Single-use tokens backing the forgot-password flow.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182938_create_password_resets_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_182938_create_password_resets_table.sql', 'password_resets', 'Single-use tokens backing the forgot-password flow.');
