-- =============================================================================
-- Migration : 2026-10-14_090100_create_cart_items_table.sql
-- Created   : 2026-10-14 09:01:00 PST
-- Database  : lpg_delivery_v2
-- Table     : cart_items
-- Depends on: `users`, `products`
-- Purpose   : Persistent per-customer shopping cart: one row per product with the chosen quantity.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-10-14_090100_create_cart_items_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_customer_product` (`customer_id`,`product_id`),
  KEY `idx_cart_customer` (`customer_id`),
  KEY `idx_cart_product` (`product_id`),
  CONSTRAINT `fk_cart_items_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-10-14_090100_create_cart_items_table.sql', 'cart_items', 'Persistent per-customer shopping cart: one row per product with the chosen quantity.');
