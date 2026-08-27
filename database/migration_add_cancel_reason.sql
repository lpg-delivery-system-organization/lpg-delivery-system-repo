-- ============================================================
-- Migration: Add explicit cancellation reason column to orders
-- LPG Delivery System v2
--
-- Previously the customer/admin cancellation reason was only
-- appended to the `notes` text column (e.g. "\n[Cancelled: ...]"),
-- but the customer and admin pages display the reason by reading a
-- `cancel_reason` column that did not exist. Adding it here lets the
-- portal store and display a dedicated, structured cancellation
-- reason chosen by the customer.
--
-- Apply after the base schema (and the paymongo migrations if used):
--   mysql -u root lpg_delivery_v2 < migration_add_cancel_reason.sql
-- ============================================================

ALTER TABLE `orders`
    ADD COLUMN `cancel_reason` VARCHAR(255) DEFAULT NULL AFTER `refund_processed_at`;
