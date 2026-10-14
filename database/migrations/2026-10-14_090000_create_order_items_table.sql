-- =============================================================================
-- Migration : 2026-10-14_090000_create_order_items_table.sql
-- Created   : 2026-10-14 09:00:00 PST
-- Database  : lpg_delivery_v2
-- Table     : order_items
-- Depends on: `orders`, `products`
-- Purpose   : Individual line items for an order, so a single order can contain multiple products with their own quantities.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-10-14_090000_create_order_items_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `brand` varchar(255) DEFAULT NULL,
  `weight` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_product` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-10-14_090000_create_order_items_table.sql', 'order_items', 'Individual line items for an order, so a single order can contain multiple products with their own quantities.');
