-- =============================================================================
-- Migration : 2026-09-30_182933_create_products_table.sql
-- Created   : 2026-09-30 18:29:33 PST
-- Database  : lpg_delivery_v2
-- Table     : products
-- Depends on: (none)
-- Purpose   : LPG product catalogue, pricing and stock levels.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182933_create_products_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `brand` varchar(255) NOT NULL,
  `weight` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_brand` (`brand`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_182933_create_products_table.sql', 'products', 'LPG product catalogue, pricing and stock levels.');
