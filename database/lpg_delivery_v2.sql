-- ==========================================================
-- LPG Delivery System v2 - Database Schema & Seed Data
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `lpg_delivery_v2`
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `lpg_delivery_v2`;

-- ----------------------------------------------------------
-- 1. Users Table
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('customer', 'admin', 'rider') NOT NULL DEFAULT 'customer',
    `phone` VARCHAR(20) NOT NULL,
    `address` TEXT NOT NULL,
    `valid_id_path` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Products Table
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `brand` VARCHAR(255) NOT NULL,
    `weight` VARCHAR(50) NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `image_url` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_brand` (`brand`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Orders Table
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `rider_id` INT DEFAULT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL,
    `payment_method` ENUM('cod', 'gcash') NOT NULL DEFAULT 'cod',
    `status` ENUM('pending', 'approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    `delivery_address` TEXT NOT NULL,
    `contact_phone` VARCHAR(20) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_rider` (`rider_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Password Resets Table
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_expires` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- Seed Data
-- ==========================================================

-- Seed Users (Bcrypt cost 12 hashes)
-- Admin: admin@lpg.com / Admin@2026!
-- Rider: rider@lpg.com / Rider@2026!
-- Customer: customer@lpg.com / Customer@2026
INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `phone`, `address`, `valid_id_path`, `status`) VALUES
(1, 'Maria Santos', 'admin@lpg.com', '$2y$12$y03f/8WkJD1Btk2aWDWiA.EUtcaJ5OE4TgUfdsKQ5dCs68p1M4EvO', 'admin', '09289876543', 'Admin HQ, Quezon City', NULL, 'active'),
(2, 'Pedro Reyes', 'rider@lpg.com', '$2y$12$v9M6iwKvrWDa2dY6AC1yX.Kmqf3W7VVnAu.GmLT2os4gfslMCS2Xu', 'rider', '09351112222', '456 Mabini Ave, Caloocan City', NULL, 'active'),
(3, 'Janister Singson', 'customer@lpg.com', '$2y$12$4WPd8RTR/0mnPWWZmoRggu4zNAgw.mSEXwkGPkedctgvFjOnB7s1S', 'customer', '09171234567', '123 Rizal St, Caloocan City', NULL, 'active')
ON DUPLICATE KEY UPDATE
    `full_name` = VALUES(`full_name`),
    `password` = VALUES(`password`),
    `role` = VALUES(`role`),
    `phone` = VALUES(`phone`),
    `address` = VALUES(`address`),
    `status` = VALUES(`status`);

-- Seed Products
INSERT INTO `products` (`id`, `name`, `brand`, `weight`, `price`, `stock`, `image_url`, `status`) VALUES
(1, 'Solane 11kg', 'Solane', '11kg', 850.00, 45, 'assets/img/products/solane-11kg.png', 'active'),
(2, 'Gasul 11kg', 'Gasul', '11kg', 820.00, 30, 'assets/img/products/gasul-11kg.png', 'active'),
(3, 'Total 11kg', 'Total', '11kg', 800.00, 20, 'assets/img/products/total-11kg.png', 'active'),
(4, 'Solane 22kg', 'Solane', '22kg', 1650.00, 15, 'assets/img/products/solane-22kg.png', 'active'),
(5, 'Gasul 50kg', 'Gasul', '50kg', 3800.00, 8, 'assets/img/products/gasul-50kg.png', 'active')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `brand` = VALUES(`brand`),
    `weight` = VALUES(`weight`),
    `price` = VALUES(`price`),
    `stock` = VALUES(`stock`),
    `image_url` = VALUES(`image_url`),
    `status` = VALUES(`status`);

-- Seed Sample Orders
INSERT INTO `orders` (`id`, `customer_id`, `product_id`, `rider_id`, `quantity`, `unit_price`, `total_amount`, `payment_method`, `status`, `delivery_address`, `contact_phone`, `notes`, `created_at`, `delivered_at`) VALUES
(1001, 3, 1, 2, 2, 850.00, 1700.00, 'cod', 'delivered', '123 Rizal St, Caloocan City', '09171234567', 'Please ring the doorbell.', '2026-08-18 09:00:00', '2026-08-18 11:30:00'),
(1002, 3, 2, 2, 1, 820.00, 820.00, 'cod', 'out_for_delivery', '123 Rizal St, Caloocan City', '09171234567', 'Leave at the gate if not available.', '2026-08-20 08:00:00', NULL),
(1003, 3, 3, NULL, 1, 800.00, 800.00, 'cod', 'pending', '123 Rizal St, Caloocan City', '09171234567', 'Urgent delivery needed.', '2026-08-20 10:00:00', NULL)
ON DUPLICATE KEY UPDATE
    `customer_id` = VALUES(`customer_id`),
    `product_id` = VALUES(`product_id`),
    `rider_id` = VALUES(`rider_id`),
    `quantity` = VALUES(`quantity`),
    `unit_price` = VALUES(`unit_price`),
    `total_amount` = VALUES(`total_amount`),
    `payment_method` = VALUES(`payment_method`),
    `status` = VALUES(`status`),
    `delivery_address` = VALUES(`delivery_address`),
    `contact_phone` = VALUES(`contact_phone`),
    `notes` = VALUES(`notes`);
