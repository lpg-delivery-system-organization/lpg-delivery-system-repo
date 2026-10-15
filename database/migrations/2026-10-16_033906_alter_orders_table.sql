-- =============================================================================
-- Migration : 2026-10-16_033906_alter_orders_table.sql
-- Created   : 2026-10-16 03:39:06 +08
-- Database  : lpg_delivery_v2
-- Table     : orders
-- Depends on: `products`, `users`
-- Purpose   : Add proof-of-delivery photo path, timestamp, and rider note to orders.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-10-16_033906_alter_orders_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


ALTER TABLE `orders`
    ADD COLUMN `delivery_proof_path` varchar(255) DEFAULT NULL AFTER `delivered_at`, ADD COLUMN `delivery_proof_at` datetime DEFAULT NULL AFTER `delivery_proof_path`, ADD COLUMN `delivery_note` varchar(255) DEFAULT NULL AFTER `delivery_proof_at`;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-10-16_033906_alter_orders_table.sql', 'orders', 'Add proof-of-delivery photo path, timestamp, and rider note to orders.');
