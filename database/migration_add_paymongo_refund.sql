-- ============================================================
-- Migration: Add online-payment refund (PayMongo) support to orders
-- LPG Delivery System v2
--
-- Extends the PayMongo payment columns added by
-- migration_add_paymongo_payment.sql to support refunding paid
-- online orders:
--
--   - payment_id            : PayMongo payment id (pay_...) captured from
--                             the checkout_session.payment.paid webhook.
--                             REQUIRED to issue a refund via /v1/refunds.
--   - refund_status         : none | requested | refunded | failed | rejected
--       none       = no refund requested
--       requested  = customer requested a refund (awaiting admin approval)
--       refunded   = admin approved; PayMongo refund succeeded; order cancelled
--       failed     = admin approved but the PayMongo refund call failed
--       rejected   = admin declined the refund request
--   - refund_reference      : PayMongo refund id returned by /v1/refunds
--   - refund_reason         : customer-supplied cancellation/refund reason
--   - refund_requested_at   : when the customer requested the refund
--   - refund_processed_at   : when admin approved/rejected or refund settled
--
-- Apply after migration_add_paymongo_payment.sql (and the base schema):
--   mysql -u root lpg_delivery_v2 < migration_add_paymongo_refund.sql
-- ============================================================

ALTER TABLE `orders`
    ADD COLUMN `payment_id` VARCHAR(64) DEFAULT NULL AFTER `payment_reference`,
    ADD COLUMN `refund_status` ENUM('none','requested','refunded','failed','rejected')
        NOT NULL DEFAULT 'none' AFTER `payment_status`,
    ADD COLUMN `refund_reference` VARCHAR(64) DEFAULT NULL AFTER `refund_status`,
    ADD COLUMN `refund_reason` VARCHAR(255) DEFAULT NULL AFTER `refund_reference`,
    ADD COLUMN `refund_requested_at` DATETIME DEFAULT NULL AFTER `refund_reason`,
    ADD COLUMN `refund_processed_at` DATETIME DEFAULT NULL AFTER `refund_requested_at`;

CREATE INDEX `idx_payment_id` ON `orders` (`payment_id`);
CREATE INDEX `idx_refund_status` ON `orders` (`refund_status`);
