CREATE DATABASE IF NOT EXISTS sia_project_db;
USE sia_project_db;

CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL,
  address TEXT NOT NULL,
  phone VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  brand VARCHAR(255) NOT NULL,
  weight VARCHAR(50) NOT NULL,
  price INT NOT NULL,
  stock INT NOT NULL,
  image VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT PRIMARY KEY,
  customerId INT NOT NULL,
  customerName VARCHAR(255) NOT NULL,
  customerAddress TEXT NOT NULL,
  customerPhone VARCHAR(50) NOT NULL,
  productId INT NOT NULL,
  productName VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  total INT NOT NULL,
  payment VARCHAR(50) DEFAULT NULL,
  status VARCHAR(50) NOT NULL,
  riderId INT DEFAULT NULL,
  riderName VARCHAR(255) DEFAULT NULL,
  createdAt VARCHAR(255) NOT NULL,
  deliveredAt VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  code VARCHAR(10) NOT NULL,
  expires_at VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (id, name, email, password, role, address, phone) VALUES
(1, 'Janister Singson', 'customer@lpg.com', 'Customer@2026', 'customer', '123 Rizal St, Caloocan City', '09171234567'),
(2, 'Maria Santos', 'admin@lpg.com', 'Admin@2026!', 'admin', 'Admin HQ, Quezon City', '09289876543'),
(3, 'Pedro Reyes', 'rider@lpg.com', 'Rider@2026!', 'rider', '456 Mabini Ave, Caloocan City', '09351112222')
ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), password=VALUES(password), role=VALUES(role), address=VALUES(address), phone=VALUES(phone);

INSERT INTO products (id, name, brand, weight, price, stock, image) VALUES
(1, 'Solane 11kg', 'Solane', '11kg', 850, 45, '🔵'),
(2, 'Gasul 11kg', 'Gasul', '11kg', 820, 30, '🔴'),
(3, 'Total 11kg', 'Total', '11kg', 800, 20, '🟡'),
(4, 'Solane 22kg', 'Solane', '22kg', 1650, 15, '🔵'),
(5, 'Gasul 50kg', 'Gasul', '50kg', 3800, 8, '🔴')
ON DUPLICATE KEY UPDATE name=VALUES(name), brand=VALUES(brand), weight=VALUES(weight), price=VALUES(price), stock=VALUES(stock), image=VALUES(image);

INSERT INTO orders (id, customerId, customerName, customerAddress, customerPhone, productId, productName, quantity, total, payment, status, riderId, riderName, createdAt, deliveredAt) VALUES
(1001, 1, 'Janister Singson', '123 Rizal St, Caloocan City', '09171234567', 1, 'Solane 11kg', 2, 1700, 'cod', 'delivered', 3, 'Pedro Reyes', '2025-04-18T09:00:00', '2025-04-18T11:30:00'),
(1002, 1, 'Janister Singson', '123 Rizal St, Caloocan City', '09171234567', 2, 'Gasul 11kg', 1, 820, 'cod', 'in-transit', 3, 'Pedro Reyes', '2025-04-22T08:00:00', NULL),
(1003, 1, 'Janister Singson', '123 Rizal St, Caloocan City', '09171234567', 3, 'Total 11kg', 1, 800, 'cod', 'pending', NULL, NULL, '2025-04-22T10:00:00', NULL)
ON DUPLICATE KEY UPDATE customerId=VALUES(customerId), customerName=VALUES(customerName), customerAddress=VALUES(customerAddress), customerPhone=VALUES(customerPhone), productId=VALUES(productId), productName=VALUES(productName), quantity=VALUES(quantity), total=VALUES(total), payment=VALUES(payment), status=VALUES(status), riderId=VALUES(riderId), riderName=VALUES(riderName), createdAt=VALUES(createdAt), deliveredAt=VALUES(deliveredAt);
