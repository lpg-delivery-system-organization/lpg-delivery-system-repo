-- =============================================================================
-- Migration : 2026-09-30_182937_create_rider_locations_table.sql
-- Created   : 2026-09-30 18:29:37 PST
-- Database  : lpg_delivery_v2
-- Table     : rider_locations
-- Depends on: `orders`, `users`
-- Purpose   : Live and historical GPS breadcrumbs submitted by riders during delivery.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182937_create_rider_locations_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `rider_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy` float DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_rider` (`rider_id`),
  KEY `idx_recorded` (`recorded_at`),
  CONSTRAINT `fk_rider_locations_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rider_locations_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_182937_create_rider_locations_table.sql', 'rider_locations', 'Live and historical GPS breadcrumbs submitted by riders during delivery.');
