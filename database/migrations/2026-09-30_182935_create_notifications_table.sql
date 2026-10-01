-- =============================================================================
-- Migration : 2026-09-30_182935_create_notifications_table.sql
-- Created   : 2026-09-30 18:29:35 PST
-- Database  : lpg_delivery_v2
-- Table     : notifications
-- Depends on: `orders`, `users`
-- Purpose   : In-app notifications for customers, riders and admins across the order lifecycle.
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p lpg_delivery_v2 < 2026-09-30_182935_create_notifications_table.sql
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


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

INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)
VALUES ('2026-09-30_182935_create_notifications_table.sql', 'notifications', 'In-app notifications for customers, riders and admins across the order lifecycle.');
