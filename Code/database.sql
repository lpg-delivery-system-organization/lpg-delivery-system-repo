CREATE DATABASE IF NOT EXISTS lpg_delivery
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE lpg_delivery;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address VARCHAR(255) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer','admin','rider') NOT NULL DEFAULT 'customer',
    valid_id_path VARCHAR(255) NULL,
    status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    size VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    rider_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cod','gcash') NOT NULL DEFAULT 'cod',
    status ENUM(
        'pending',
        'approved',
        'ready_for_delivery',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

INSERT INTO products (name, size, price, stock, status)
SELECT 'LPG Cylinder', '11 kg', 850.00, 20, 'active'
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name='LPG Cylinder' AND size='11 kg');

INSERT INTO products (name, size, price, stock, status)
SELECT 'LPG Cylinder', '7 kg', 600.00, 20, 'active'
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name='LPG Cylinder' AND size='7 kg');

INSERT INTO users (full_name, phone, address, email, password, role, status)
SELECT 'System Admin', '0000000000', 'Admin Office', 'admin@example.com', '$2y$10$lmFYsXD2OJVlyZAklQwMV.BEbZSnSisMyEStacafgYRu1holkbzlG', 'admin', 'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@example.com');

INSERT INTO users (full_name, phone, address, email, password, role, status)
SELECT 'Rider One', '09171234567', 'Rider House, Cebu City', 'rider@example.com', '$2y$10$qSOBh2P6GMgCIjJYY61Pcezcls5CiLGEvoQVLXeClD.jwWQB51uRa', 'rider', 'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'rider@example.com');

-- Default login credentials:
-- Admin: admin@example.com / Admin@123
-- Rider: rider@example.com / Rider@123
