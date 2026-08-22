-- ============================================================
-- Migration: Add delivery GPS coordinates to orders
-- LPG Delivery System v2
--
-- Adds nullable latitude/longitude columns so the exact
-- customer drop-off pin (captured at checkout) can be shared
-- across rider, customer, and admin live tracking maps.
--
-- Run once against an existing database:
--   mysql -u root lpg_delivery_v2 < migration_add_order_coordinates.sql
-- ============================================================

ALTER TABLE `orders`
  ADD COLUMN `delivery_latitude` DECIMAL(10,7) DEFAULT NULL AFTER `delivery_address`,
  ADD COLUMN `delivery_longitude` DECIMAL(10,7) DEFAULT NULL AFTER `delivery_latitude`;
