-- Migration: Add profile picture support to users table
-- LPG Delivery System v2
-- Adds a profile_picture column storing the relative path (uploads/avatars/<file>)
-- of the uploaded profile photo for customers and riders.
--
-- Safe to re-import: skipped automatically if the column already exists
-- (supported by MariaDB 10.x, which ships with XAMPP).

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `profile_picture` VARCHAR(500) DEFAULT NULL AFTER `valid_id_path`;
