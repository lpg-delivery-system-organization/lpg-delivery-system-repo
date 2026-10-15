-- =============================================================================
-- LPG Delivery System v2 — Full Schema + Seed Data
-- =============================================================================
-- HOW TO IMPORT
--   mysql -u root < database/lpg_delivery_v2.sql
--   ...or paste into phpMyAdmin > SQL (no database selected).
--
-- This file is self-contained: it DROPs and CREATEs the database, creates all
-- tables (in dependency order) and inserts the canonical seed data that the
-- test suites validate (tests/test_db.php imports it as a single batch).
--
-- Default accounts (all password: "password", bcrypt cost 12):
--   admin@lpg.com    Maria Santos       admin
--   rider@lpg.com    Pedro Reyes        rider
--   customer@lpg.com Janister Singson   customer
-- =============================================================================

DROP DATABASE IF EXISTS `lpg_delivery_v2`;
CREATE DATABASE `lpg_delivery_v2` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lpg_delivery_v2`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Table: users
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('customer','admin','rider') NOT NULL DEFAULT 'customer',
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `valid_id_path` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `id_verification_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `id_rejection_reason` varchar(500) DEFAULT NULL,
  `id_reviewed_by` int(11) DEFAULT NULL,
  `id_reviewed_at` datetime DEFAULT NULL,
  `purge_warned_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_verification_reviewed` (`id_verification_status`, `id_reviewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: products
-- -----------------------------------------------------------------------------
CREATE TABLE `products` (
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

-- -----------------------------------------------------------------------------
-- Table: orders (depends on users, products)
-- -----------------------------------------------------------------------------
CREATE TABLE `orders` (
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

-- -----------------------------------------------------------------------------
-- Table: password_resets (depends on users)
-- -----------------------------------------------------------------------------
CREATE TABLE `password_resets` (
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

-- -----------------------------------------------------------------------------
-- Table: chat_messages (depends on orders, users)
-- -----------------------------------------------------------------------------
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_order` (`order_id`),
  KEY `idx_chat_sender` (`sender_id`),
  KEY `idx_chat_created` (`created_at`),
  CONSTRAINT `fk_chat_messages_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: rider_locations (depends on orders, users)
-- -----------------------------------------------------------------------------
CREATE TABLE `rider_locations` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Table: order_items (depends on orders, products)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- Table: cart_items (depends on users, products)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- Table: schema_migrations (migration ledger)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `table_name` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: notifications (depends on orders, users)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(191) NOT NULL,
  `message` varchar(1000) NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_unread` (`user_id`,`is_read`),
  KEY `idx_notif_order` (`order_id`),
  CONSTRAINT `fk_notifications_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- Seed Data
-- =============================================================================

-- Default accounts (password for all three: "password")
INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `phone`, `address`, `status`, `id_verification_status`, `created_at`, `updated_at`) VALUES
(1, 'Maria Santos', 'admin@lpg.com', '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'admin', '09289876543', 'Admin HQ, Quezon City', 'active', 'approved', NOW(), NOW()),
(2, 'Pedro Reyes', 'rider@lpg.com', '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'rider', '09351112222', '456 Mabini Ave, Caloocan City', 'active', 'approved', NOW(), NOW()),
(3, 'Janister Singson', 'customer@lpg.com', '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'customer', '09171234567', '123 Rizal St, Caloocan City', 'active', 'approved', NOW(), NOW());

-- Product catalog
INSERT INTO `products` (`id`, `name`, `brand`, `weight`, `price`, `stock`, `image_url`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Solane 11kg', 'Solane', '11kg', '850.00', 30, 'assets/img/products/1.png', 'active', NOW(), NOW()),
(2, 'Gasul 11kg', 'Gasul', '11kg', '820.00', 30, 'assets/img/products/2.png', 'active', NOW(), NOW()),
(3, 'Total 11kg', 'Total', '11kg', '800.00', 30, 'assets/img/products/3.png', 'active', NOW(), NOW()),
(4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 20, 'assets/img/products/4.png', 'active', NOW(), NOW()),
(5, 'Gasul 50kg', 'Gasul', '50kg', '3800.00', 10, 'assets/img/products/5.png', 'active', NOW(), NOW());

-- Sample orders (deliberately dated outside June 2026 so the admin monthly
-- sales-report tests always start from a clean slate)
INSERT INTO `orders` (`id`, `customer_id`, `product_id`, `rider_id`, `quantity`, `unit_price`, `total_amount`, `payment_method`, `payment_status`, `paid_at`, `status`, `delivery_address`, `contact_phone`, `notes`, `created_at`, `updated_at`, `delivered_at`) VALUES
(1, 3, 1, 2, 2, '850.00', '1700.00', 'cod', 'paid', '2026-09-08 14:20:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-05 10:00:00', '2026-09-08 14:20:00', '2026-09-08 14:20:00'),
(2, 3, 2, NULL, 1, '820.00', '820.00', 'gcash', 'paid', '2026-09-10 09:35:00', 'approved', '123 Rizal St, Caloocan City', '09171234567', 'Leave at door', '2026-09-10 09:30:00', '2026-09-10 09:35:00', NULL),
(3, 3, 1, NULL, 1, '850.00', '850.00', 'cod', 'unpaid', NULL, 'pending', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-12 16:45:00', '2026-09-12 16:45:00', NULL);

-- Additional sample orders: 10 each for August, September and October 2026,
-- each on a distinct day, all delivered + paid, belonging to customer 3
-- (Janister Singson) with rider 2 (Pedro Reyes). IDs start at 13 to avoid
-- clashing with database/seed_september_delivered_orders.sql (ids 4-12).
INSERT INTO `orders` (`id`, `customer_id`, `product_id`, `rider_id`, `quantity`, `unit_price`, `total_amount`, `payment_method`, `payment_status`, `paid_at`, `status`, `delivery_address`, `contact_phone`, `notes`, `created_at`, `updated_at`, `delivered_at`) VALUES
-- August 2026
(13, 3, 1, 2, 1, '850.00',  '850.00',  'cod',  'paid', '2026-08-02 14:20:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-02 09:15:00', '2026-08-02 14:20:00', '2026-08-02 14:20:00'),
(14, 3, 2, 2, 2, '820.00',  '1640.00', 'gcash','paid', '2026-08-04 15:10:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Leave at door', '2026-08-04 10:40:00', '2026-08-04 15:10:00', '2026-08-04 15:10:00'),
(15, 3, 3, 2, 1, '800.00',  '800.00',  'cod',  'paid', '2026-08-06 13:05:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-06 08:30:00', '2026-08-06 13:05:00', '2026-08-06 13:05:00'),
(16, 3, 4, 2, 1, '1650.00', '1650.00', 'gcash','paid', '2026-08-08 16:25:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-08 11:00:00', '2026-08-08 16:25:00', '2026-08-08 16:25:00'),
(17, 3, 5, 2, 1, '3800.00', '3800.00', 'cod',  'paid', '2026-08-11 12:40:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Handle with care', '2026-08-11 07:50:00', '2026-08-11 12:40:00', '2026-08-11 12:40:00'),
(18, 3, 1, 2, 2, '850.00',  '1700.00', 'gcash','paid', '2026-08-13 14:05:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-13 09:50:00', '2026-08-13 14:05:00', '2026-08-13 14:05:00'),
(19, 3, 2, 2, 1, '820.00',  '820.00',  'cod',  'paid', '2026-08-16 17:45:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-16 13:20:00', '2026-08-16 17:45:00', '2026-08-16 17:45:00'),
(20, 3, 4, 2, 2, '1650.00', '3300.00', 'gcash','paid', '2026-08-19 15:35:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-19 10:10:00', '2026-08-19 15:35:00', '2026-08-19 15:35:00'),
(21, 3, 3, 2, 3, '800.00',  '2400.00', 'cod',  'paid', '2026-08-23 12:50:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-08-23 08:05:00', '2026-08-23 12:50:00', '2026-08-23 12:50:00'),
(22, 3, 5, 2, 1, '3800.00', '3800.00', 'gcash','paid', '2026-08-27 16:30:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Call upon arrival', '2026-08-27 12:00:00', '2026-08-27 16:30:00', '2026-08-27 16:30:00'),
-- September 2026
(23, 3, 1, 2, 1, '850.00',  '850.00',  'cod',  'paid', '2026-09-01 13:40:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-01 09:10:00', '2026-09-01 13:40:00', '2026-09-01 13:40:00'),
(24, 3, 2, 2, 2, '820.00',  '1640.00', 'gcash','paid', '2026-09-03 15:00:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-03 10:25:00', '2026-09-03 15:00:00', '2026-09-03 15:00:00'),
(25, 3, 3, 2, 1, '800.00',  '800.00',  'cod',  'paid', '2026-09-06 13:15:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Leave at door', '2026-09-06 08:40:00', '2026-09-06 13:15:00', '2026-09-06 13:15:00'),
(26, 3, 4, 2, 1, '1650.00', '1650.00', 'gcash','paid', '2026-09-08 16:00:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-08 11:15:00', '2026-09-08 16:00:00', '2026-09-08 16:00:00'),
(27, 3, 5, 2, 1, '3800.00', '3800.00', 'cod',  'paid', '2026-09-11 12:30:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Handle with care', '2026-09-11 07:55:00', '2026-09-11 12:30:00', '2026-09-11 12:30:00'),
(28, 3, 1, 2, 2, '850.00',  '1700.00', 'gcash','paid', '2026-09-15 14:10:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-15 09:35:00', '2026-09-15 14:10:00', '2026-09-15 14:10:00'),
(29, 3, 2, 2, 1, '820.00',  '820.00',  'cod',  'paid', '2026-09-18 17:20:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-18 13:05:00', '2026-09-18 17:20:00', '2026-09-18 17:20:00'),
(30, 3, 4, 2, 2, '1650.00', '3300.00', 'gcash','paid', '2026-09-21 15:45:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-21 10:20:00', '2026-09-21 15:45:00', '2026-09-21 15:45:00'),
(31, 3, 3, 2, 3, '800.00',  '2400.00', 'cod',  'paid', '2026-09-24 12:55:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-09-24 08:15:00', '2026-09-24 12:55:00', '2026-09-24 12:55:00'),
(32, 3, 5, 2, 1, '3800.00', '3800.00', 'gcash','paid', '2026-09-27 16:15:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Call upon arrival', '2026-09-27 11:40:00', '2026-09-27 16:15:00', '2026-09-27 16:15:00'),
-- October 2026
(33, 3, 1, 2, 1, '850.00',  '850.00',  'cod',  'paid', '2026-10-01 14:30:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-01 09:15:00', '2026-10-01 14:30:00', '2026-10-01 14:30:00'),
(34, 3, 2, 2, 2, '820.00',  '1640.00', 'gcash','paid', '2026-10-02 15:10:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-02 10:40:00', '2026-10-02 15:10:00', '2026-10-02 15:10:00'),
(35, 3, 3, 2, 1, '800.00',  '800.00',  'cod',  'paid', '2026-10-04 13:00:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Leave at door', '2026-10-04 08:30:00', '2026-10-04 13:00:00', '2026-10-04 13:00:00'),
(36, 3, 4, 2, 1, '1650.00', '1650.00', 'gcash','paid', '2026-10-05 16:20:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-05 11:00:00', '2026-10-05 16:20:00', '2026-10-05 16:20:00'),
(37, 3, 5, 2, 1, '3800.00', '3800.00', 'cod',  'paid', '2026-10-07 14:05:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Handle with care', '2026-10-07 09:50:00', '2026-10-07 14:05:00', '2026-10-07 14:05:00'),
(38, 3, 1, 2, 2, '850.00',  '1700.00', 'gcash','paid', '2026-10-08 17:40:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-08 13:20:00', '2026-10-08 17:40:00', '2026-10-08 17:40:00'),
(39, 3, 3, 2, 3, '800.00',  '2400.00', 'cod',  'paid', '2026-10-09 12:15:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-09 07:45:00', '2026-10-09 12:15:00', '2026-10-09 12:15:00'),
(40, 3, 4, 2, 1, '1650.00', '1650.00', 'gcash','paid', '2026-10-11 15:35:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-11 10:10:00', '2026-10-11 15:35:00', '2026-10-11 15:35:00'),
(41, 3, 2, 2, 1, '820.00',  '820.00',  'cod',  'paid', '2026-10-13 16:50:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', NULL, '2026-10-13 12:00:00', '2026-10-13 16:50:00', '2026-10-13 16:50:00'),
(42, 3, 5, 2, 1, '3800.00', '3800.00', 'gcash','paid', '2026-10-14 13:45:00', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Call upon arrival', '2026-10-14 09:30:00', '2026-10-14 13:45:00', '2026-10-14 13:45:00');

-- Matching line items for the orders above (product snapshots at time of sale).
INSERT INTO `order_items` (`order_id`, `product_id`, `product_name`, `brand`, `weight`, `unit_price`, `quantity`, `subtotal`, `created_at`) VALUES
(13, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  1, '850.00',  '2026-08-02 14:20:00'),
(14, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  2, '1640.00', '2026-08-04 15:10:00'),
(15, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  1, '800.00',  '2026-08-06 13:05:00'),
(16, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 1, '1650.00', '2026-08-08 16:25:00'),
(17, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-08-11 12:40:00'),
(18, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  2, '1700.00', '2026-08-13 14:05:00'),
(19, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  1, '820.00',  '2026-08-16 17:45:00'),
(20, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 2, '3300.00', '2026-08-19 15:35:00'),
(21, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  3, '2400.00', '2026-08-23 12:50:00'),
(22, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-08-27 16:30:00'),
(23, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  1, '850.00',  '2026-09-01 13:40:00'),
(24, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  2, '1640.00', '2026-09-03 15:00:00'),
(25, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  1, '800.00',  '2026-09-06 13:15:00'),
(26, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 1, '1650.00', '2026-09-08 16:00:00'),
(27, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-09-11 12:30:00'),
(28, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  2, '1700.00', '2026-09-15 14:10:00'),
(29, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  1, '820.00',  '2026-09-18 17:20:00'),
(30, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 2, '3300.00', '2026-09-21 15:45:00'),
(31, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  3, '2400.00', '2026-09-24 12:55:00'),
(32, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-09-27 16:15:00'),
(33, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  1, '850.00',  '2026-10-01 14:30:00'),
(34, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  2, '1640.00', '2026-10-02 15:10:00'),
(35, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  1, '800.00',  '2026-10-04 13:00:00'),
(36, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 1, '1650.00', '2026-10-05 16:20:00'),
(37, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-10-07 14:05:00'),
(38, 1, 'Solane 11kg', 'Solane', '11kg', '850.00',  2, '1700.00', '2026-10-08 17:40:00'),
(39, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  3, '2400.00', '2026-10-09 12:15:00'),
(40, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 1, '1650.00', '2026-10-11 15:35:00'),
(41, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  1, '820.00',  '2026-10-13 16:50:00'),
(42, 5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-10-14 13:45:00');

-- Migration ledger (all migrations in database/migrations/)
INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`) VALUES
('2026-09-30_181700_create_schema_migrations_table.sql', 'schema_migrations', 'Ledger of applied migrations'),
('2026-09-30_182932_create_users_table.sql', 'users', 'User accounts for customers, riders and admins'),
('2026-09-30_182933_create_products_table.sql', 'products', 'LPG product catalog'),
('2026-09-30_182934_create_orders_table.sql', 'orders', 'Delivery orders and their payment/refund lifecycle'),
('2026-09-30_182935_create_notifications_table.sql', 'notifications', 'In-app notifications for customers, riders and admins across the order lifecycle.'),
('2026-09-30_182936_create_chat_messages_table.sql', 'chat_messages', 'Order chat messages between customer and rider'),
('2026-09-30_182937_create_rider_locations_table.sql', 'rider_locations', 'Rider GPS location pings while delivering'),
('2026-09-30_182938_create_password_resets_table.sql', 'password_resets', 'Password reset tokens'),
('2026-10-14_090000_create_order_items_table.sql', 'order_items', 'Individual line items for an order, so a single order can contain multiple products with their own quantities.'),
('2026-10-14_090100_create_cart_items_table.sql', 'cart_items', 'Persistent per-customer shopping cart: one row per product with the chosen quantity.');

SET FOREIGN_KEY_CHECKS = 1;
