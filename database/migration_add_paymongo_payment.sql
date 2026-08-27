-- ============================================================
-- Migration: Add online payment support (PayMongo) to orders
-- LPG Delivery System v2
--
-- 1. Adds a new order status 'pending_payment' so an order placed
--    with an online payment method can be created WITHOUT reserving
--    stock until payment is confirmed by the PayMongo webhook.
-- 2. Adds columns to track the PayMongo transaction on the order:
--      - payment_reference : PayMongo checkout session / intent id
--      - payment_status    : unpaid | paid | failed
--      - paid_at           : timestamp when payment was confirmed
--
-- Apply after importing the base schema:
--   mysql -u root lpg_delivery_v2 < migration_add_paymongo_payment.sql
-- ============================================================

ALTER TABLE `orders`
    MODIFY COLUMN `status`
        ENUM('pending_payment','pending','approved','ready_for_delivery','picked_up','out_for_delivery','delivered','cancelled')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN `payment_reference` VARCHAR(64) DEFAULT NULL AFTER `payment_method`,
    ADD COLUMN `payment_status` ENUM('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid' AFTER `payment_reference`,
    ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `payment_status`;

CREATE INDEX `idx_payment_reference` ON `orders` (`payment_reference`);
