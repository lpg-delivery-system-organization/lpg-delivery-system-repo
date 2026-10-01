-- =============================================================================
-- Migration : 2026-09-30_182934_create_orders_table.sql
-- Created   : 2026-09-30 18:29:34 PST
-- Database  : lpg_delivery_v2
-- Table     : orders
-- Depends on: `products`, `users`
-- Purpose   : Orders and their full fulfilment lifecycle, including payment, refund and delivery geolocation columns.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182934_create_orders_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cod','gcash') NOT NULL DEFAULT 'cod',
  `payment_reference` varchar(64) DEFAULT NULL,
  `payment_id` varchar(64) DEFAULT NULL,
  `payment_status` enum('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid',
  `refund_status` enum('none','requested','refunded','failed','rejected') NOT NULL DEFAULT 'none',
  `refund_reference` varchar(64) DEFAULT NULL,
  `refund_reason` varchar(255) DEFAULT NULL,
  `refund_requested_at` datetime DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `status` enum('pending_payment','pending','approved','ready_for_delivery','picked_up','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `delivery_address` text NOT NULL,
  `delivery_latitude` decimal(10,7) DEFAULT NULL,
  `delivery_longitude` decimal(10,7) DEFAULT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_orders_product` (`product_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_rider` (`rider_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_payment_reference` (`payment_reference`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_refund_status` (`refund_status`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_orders_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_182934_create_orders_table.sql', 'orders', 'Orders and their full fulfilment lifecycle, including payment, refund and delivery geolocation columns.');
