-- Seed: 3 new customers + 9 delivered orders (September 2026) + matching order_items
-- Run: mysql -u root lpg_delivery_v2 < database/seed_september_delivered_orders.sql
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `phone`, `address`, `status`, `id_verification_status`, `created_at`, `updated_at`) VALUES
(4, 'Ana Cruz',    'ana@example.com',   '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'customer', '09181112222', '78 Sampaguita St, Quezon City', 'active', 'approved', '2026-08-28 08:00:00', '2026-08-28 08:00:00'),
(5, 'Ben Garcia',  'ben@example.com',   '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'customer', '09193334444', '32 Bonifacio Ave, Makati City',  'active', 'approved', '2026-08-29 08:00:00', '2026-08-29 08:00:00'),
(6, 'Carla Reyes', 'carla@example.com', '$2y$12$onANxxcbIKMXNxoptyE0WuBdIrXixWqXjjbT1XpVZUkTiki4/UJOG', 'customer', '09205556666', '15 Narra St, Pasig City',        'active', 'approved', '2026-08-30 08:00:00', '2026-08-30 08:00:00');

INSERT INTO `orders` (`id`, `customer_id`, `product_id`, `rider_id`, `quantity`, `unit_price`, `total_amount`, `payment_method`, `payment_status`, `paid_at`, `status`, `delivery_address`, `contact_phone`, `notes`, `created_at`, `updated_at`, `delivered_at`) VALUES
(4,  4, 1, 2, 1, '850.00',  '850.00',  'cod',  'paid', '2026-09-03 14:30:00', 'delivered', '78 Sampaguita St, Quezon City', '09181112222', NULL,                              '2026-09-02 09:15:00', '2026-09-03 14:30:00', '2026-09-03 14:30:00'),
(5,  4, 2, 2, 2, '820.00',  '1640.00', 'gcash','paid', '2026-09-07 16:00:00', 'delivered', '78 Sampaguita St, Quezon City', '09181112222', 'Leave at door',                 '2026-09-06 10:00:00', '2026-09-07 16:00:00', '2026-09-07 16:00:00'),
(6,  4, 4, 2, 1, '1650.00', '1650.00', 'cod',  'paid', '2026-09-11 15:10:00', 'delivered', '78 Sampaguita St, Quezon City', '09181112222', NULL,                              '2026-09-10 11:20:00', '2026-09-11 15:10:00', '2026-09-11 15:10:00'),
(7,  5, 3, 2, 3, '800.00',  '2400.00', 'gcash','paid', '2026-09-14 13:00:00', 'delivered', '32 Bonifacio Ave, Makati City', '09193334444', 'Call upon arrival',             '2026-09-13 08:45:00', '2026-09-14 13:00:00', '2026-09-14 13:00:00'),
(8,  5, 1, 2, 2, '850.00',  '1700.00', 'cod',  'paid', '2026-09-17 10:30:00', 'delivered', '32 Bonifacio Ave, Makati City', '09193334444', NULL,                              '2026-09-16 14:00:00', '2026-09-17 10:30:00', '2026-09-17 10:30:00'),
(9,  5, 5, 2, 1, '3800.00', '3800.00', 'gcash','paid', '2026-09-20 11:45:00', 'delivered', '32 Bonifacio Ave, Makati City', '09193334444', 'Handle with care',              '2026-09-19 09:00:00', '2026-09-20 11:45:00', '2026-09-20 11:45:00'),
(10, 6, 2, 2, 1, '820.00',  '820.00',  'cod',  'paid', '2026-09-22 09:20:00', 'delivered', '15 Narra St, Pasig City',       '09205556666', NULL,                              '2026-09-21 15:30:00', '2026-09-22 09:20:00', '2026-09-22 09:20:00'),
(11, 6, 4, 2, 2, '1650.00', '3300.00', 'gcash','paid', '2026-09-25 14:00:00', 'delivered', '15 Narra St, Pasig City',       '09205556666', 'Office delivery, 2nd floor',    '2026-09-24 10:10:00', '2026-09-25 14:00:00', '2026-09-25 14:00:00'),
(12, 6, 3, 2, 1, '800.00',  '800.00',  'cod',  'paid', '2026-09-28 16:20:00', 'delivered', '15 Narra St, Pasig City',       '09205556666', NULL,                              '2026-09-27 13:00:00', '2026-09-28 16:20:00', '2026-09-28 16:20:00');

INSERT INTO `order_items` (`order_id`, `product_id`, `product_name`, `brand`, `weight`, `unit_price`, `quantity`, `subtotal`, `created_at`) VALUES
(4,  1, 'Solane 11kg', 'Solane', '11kg', '850.00',  1, '850.00',  '2026-09-03 14:30:00'),
(5,  2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  2, '1640.00', '2026-09-07 16:00:00'),
(6,  4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 1, '1650.00', '2026-09-11 15:10:00'),
(7,  3, 'Total 11kg',  'Total',  '11kg', '800.00',  3, '2400.00', '2026-09-14 13:00:00'),
(8,  1, 'Solane 11kg', 'Solane', '11kg', '850.00',  2, '1700.00', '2026-09-17 10:30:00'),
(9,  5, 'Gasul 50kg',  'Gasul',  '50kg', '3800.00', 1, '3800.00', '2026-09-20 11:45:00'),
(10, 2, 'Gasul 11kg',  'Gasul',  '11kg', '820.00',  1, '820.00',  '2026-09-22 09:20:00'),
(11, 4, 'Solane 22kg', 'Solane', '22kg', '1650.00', 2, '3300.00', '2026-09-25 14:00:00'),
(12, 3, 'Total 11kg',  'Total',  '11kg', '800.00',  1, '800.00',  '2026-09-28 16:20:00');

SET FOREIGN_KEY_CHECKS = 1;
