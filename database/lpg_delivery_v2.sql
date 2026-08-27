-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 26, 2026 at 08:12 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lpg_delivery_v2`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `order_id`, `sender_id`, `message`, `created_at`) VALUES
(1, 1402, 2, 'hoy', '2026-08-23 13:34:29'),
(2, 1402, 2, 'dito nako', '2026-08-23 13:34:31'),
(3, 1402, 2, 'baha nna', '2026-08-23 13:34:33'),
(4, 1402, 3, 'san kana', '2026-08-23 13:35:08'),
(5, 1402, 3, 'hwllo', '2026-08-23 13:35:14'),
(6, 1402, 3, 'hinahanap ka na ni riaNNE WINLAB', '2026-08-23 13:35:31'),
(7, 1402, 3, 'SISR MAY JATOT PO BA', '2026-08-23 13:35:42'),
(8, 1404, 3, 'kuya baha dito gamit ka eroplano', '2026-08-25 11:48:51'),
(9, 1404, 2, 'sige hagis ko nalang lpg', '2026-08-25 11:49:05'),
(10, 1428, 3, 'hello', '2026-08-26 03:46:45'),
(11, 1428, 3, 'hoy', '2026-08-26 03:46:48');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cod','gcash') NOT NULL DEFAULT 'cod',
  `payment_reference` varchar(64) DEFAULT NULL,
  `payment_id` varchar(64) DEFAULT NULL,
  `payment_status` enum('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid',
  `refund_status` enum('none','requested','refunded','failed','rejected') NOT NULL DEFAULT 'none',
  `refund_reference` varchar(64) DEFAULT NULL,
  `refund_reason` varchar(255) DEFAULT NULL,
  `refund_requested_at` datetime DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `status` enum('pending_payment','pending','approved','ready_for_delivery','picked_up','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `delivery_address` text NOT NULL,
  `delivery_latitude` decimal(10,7) DEFAULT NULL,
  `delivery_longitude` decimal(10,7) DEFAULT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `customer_id`, `product_id`, `rider_id`, `quantity`, `unit_price`, `total_amount`, `payment_method`, `status`, `delivery_address`, `delivery_latitude`, `delivery_longitude`, `contact_phone`, `notes`, `created_at`, `updated_at`, `delivered_at`) VALUES
(1402, 3, 1, 2, 1, 975.50, 975.50, 'cod', 'cancelled', ', Emerald Tower, Ortigas Center, Pasig City', NULL, NULL, '09228889900', '\n[Cancelled: wala lang]', '2026-08-23 13:33:40', '2026-08-25 09:41:51', NULL),
(1403, 3, 1, NULL, 1, 975.50, 975.50, 'gcash', 'cancelled', 'Unit 201, Emerald Tower, Ortigas Center, Pasig City', 14.5995000, 120.9842000, '09228889900', '\n[Cancelled: Cancelled by customer via order details]', '2026-08-25 10:46:56', '2026-08-25 10:47:18', NULL),
(1404, 3, 1, 2, 1, 975.50, 975.50, 'cod', 'picked_up', 'Unit 201, Emerald Tower, Ortigas Center, Pasig City', 14.5982388, 120.9786869, '09228889900', '', '2026-08-25 11:46:12', '2026-08-25 11:47:03', NULL),
(1405, 3, 1, NULL, 2, 975.50, 1951.00, 'gcash', 'pending', '123 Test Ave, Pasig City', NULL, NULL, '09181234567', 'Handle with care', '2026-08-26 01:09:03', '2026-08-26 01:09:03', NULL),
(1406, 3, 1, NULL, 3, 975.50, 2926.50, 'cod', 'cancelled', 'Cancel Test St', NULL, NULL, '09171112233', '\n[Cancelled: Cancelled by customer via portal]', '2026-08-26 01:09:05', '2026-08-26 01:09:05', NULL),
(1407, 3, 1, NULL, 1, 975.50, 975.50, 'cod', 'out_for_delivery', 'Test Address', 6.5244000, 3.3792000, '09171112233', NULL, '2026-08-26 01:09:08', '2026-08-26 01:09:08', NULL),
(1409, 3, 1, 2, 1, 975.50, 975.50, 'cod', 'delivered', 'Test Approve Address', NULL, NULL, '09171234567', NULL, '2026-08-26 01:09:16', '2026-08-26 01:09:16', '2026-08-26 01:09:16'),
(1410, 3, 1, NULL, 4, 975.50, 3902.00, 'cod', 'cancelled', 'Cancel Order St', NULL, NULL, '09171234567', '\n[Cancelled: Admin test cancellation]', '2026-08-26 01:09:20', '2026-08-26 01:09:20', NULL),
(1412, 3, 1, 2, 1, 850.00, 850.00, 'cod', 'delivered', '456 Test Street, Caloocan', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', '2026-08-26 01:09:27'),
(1413, 3, 1, 50, 1, 850.00, 850.00, 'cod', 'picked_up', 'Other Rider St', NULL, NULL, '09172223344', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', NULL),
(1414, 3, 1, 2, 1, 850.00, 850.00, 'cod', 'picked_up', 'State Test St', NULL, NULL, '09173334455', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', NULL),
(1415, 3, 1, NULL, 1, 850.00, 850.00, 'cod', 'approved', 'Available Test St, Pasig City', NULL, NULL, '09179998888', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', NULL),
(1416, 3, 1, 2, 2, 850.00, 1700.00, 'gcash', 'picked_up', 'Claim Test St', NULL, NULL, '09175556677', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', NULL),
(1417, 3, 1, 2, 1, 850.00, 850.00, 'cod', 'picked_up', 'Concurrency Race St', NULL, NULL, '09176667788', NULL, '2026-08-26 01:09:27', '2026-08-26 01:09:27', NULL),
(1419, 3, 1, 50, 1, 920.50, 920.50, 'cod', 'picked_up', 'Rider Isolation Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:33', '2026-08-26 01:09:33', NULL),
(1420, 3, 1, NULL, 1, 920.50, 920.50, 'cod', 'pending', 'Customer Privacy Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:35', '2026-08-26 01:09:35', NULL),
(1421, 3, 1, NULL, 1, 920.50, 920.50, 'cod', 'approved', 'Admin Status Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:38', '2026-08-26 01:09:38', NULL),
(1422, 3, 1, 2, 1, 920.50, 920.50, 'cod', 'picked_up', 'Admin Assign Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:40', '2026-08-26 01:09:40', NULL),
(1423, 3, 1, 2, 1, 920.50, 920.50, 'cod', 'picked_up', 'Rider Claim Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:44', '2026-08-26 01:09:44', NULL),
(1424, 3, 1, 2, 1, 920.50, 920.50, 'cod', 'delivered', 'Rider Advance Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:46', '2026-08-26 01:09:46', '2026-08-26 01:09:46'),
(1425, 3, 1, NULL, 3, 920.50, 2761.50, 'cod', 'cancelled', 'Customer Cancel Test', NULL, NULL, '09171112233', '\n[Cancelled: Changed delivery time preference]', '2026-08-26 01:09:49', '2026-08-26 01:09:49', NULL),
(1426, 3, 1, NULL, 1, 920.50, 920.50, 'cod', 'pending', 'Admin Get Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:53', '2026-08-26 01:09:53', NULL),
(1427, 3, 1, NULL, 1, 975.50, 975.50, 'cod', 'pending', 'Invalid Transition Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:55', '2026-08-26 01:09:55', NULL),
(1428, 3, 1, 2, 1, 975.50, 975.50, 'cod', 'picked_up', 'Claim Conflict Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:09:57', '2026-08-26 01:09:57', NULL),
(1429, 3, 1, 2, 1, 975.50, 975.50, 'cod', 'delivered', 'Cancel Delivered Test', NULL, NULL, '09171112233', NULL, '2026-08-26 01:10:01', '2026-08-26 01:10:01', '2026-08-26 01:10:01');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 4, '742fc7780e5fd77f30cbf506e06dd2c2aea5ff5d74b8c24fdc9d82bff82bbb3e', '2026-08-20 08:35:28', 1, '2026-08-20 08:35:28'),
(2, 5, '34c247e52948affd7b5b191e51e02ffb644ae1fd1434c125115c3440844de4a5', '2026-08-20 08:40:26', 1, '2026-08-20 08:40:26'),
(3, 6, 'bb79d267ef57285c3fc6486487c759f679d68600fd81e5d4c300ab11163a6588', '2026-08-20 08:41:02', 1, '2026-08-20 08:41:02'),
(4, 7, '84867c6ac683c464020ff4d5378e82b81fafe455d8db40fa93744baa797d8ed4', '2026-08-20 08:44:55', 1, '2026-08-20 08:44:55'),
(5, 8, '0e6b8ec59bf03646e9e8c259ab6d2bb99b2d4fe427c12bd4630e9e450b46358c', '2026-08-20 08:46:41', 1, '2026-08-20 08:46:41'),
(6, 9, '8f1abff5aba71f1eb52e727c6204def5efd54e198c12b92dda258c171a9f3f95', '2026-08-20 08:48:26', 1, '2026-08-20 08:48:26'),
(7, 3, '32e2bb374c32dffded14040c7d3d4fc4d227431470189a0cfadef403167410fb', '2026-08-20 09:50:06', 0, '2026-08-20 08:50:06'),
(8, 3, 'e692a363f0f8395d25eed81b94be7bef82035793423b770c728f3865147c2452', '2026-08-20 08:50:06', 1, '2026-08-20 08:50:06'),
(9, 3, '1b3cd77bd81e89397374a96207c3c9ee921d7a38f83327281f1c59603b815029', '2026-08-20 08:40:06', 0, '2026-08-20 07:40:06'),
(11, 3, '640c4e98548bce32e2bd9003f8a62568f694dbd6b7382b17be51d49ab5f22d82', '2026-08-20 09:50:07', 0, '2026-08-20 08:50:07'),
(12, 14, '22764145c8f5c622f04d01d473e1d83c6e9b4de8c9a6217298b82f39e052bcff', '2026-08-20 08:50:12', 1, '2026-08-20 08:50:12'),
(13, 3, '88e20aea5c173db79eccbb5e896d3eaef03191c1afaed506c2687456ae266a35', '2026-08-20 09:50:14', 0, '2026-08-20 08:50:14'),
(14, 3, '92b1960b3ed8d409d5e360baecfbd70e85ae00071d130154ebb0ddffba4c8a5f', '2026-08-20 08:50:14', 1, '2026-08-20 08:50:14'),
(15, 3, '9d0b1888f92e55c582d73c34b5a7e781f4fdabaf01879fd2482f4b38c6b6cabd', '2026-08-20 08:40:14', 0, '2026-08-20 07:40:14'),
(17, 3, 'cde382cfbeee04a121f397d33e682009cfc67f83b698206d2fc745e4c34423d7', '2026-08-20 09:50:15', 0, '2026-08-20 08:50:15'),
(18, 19, '2fddaf1dda3bd9ba1a3eb71f1d93069aa9673c4eeaffdd5be4c83f0328a8c084', '2026-08-20 08:52:25', 1, '2026-08-20 08:52:25'),
(19, 3, '6274278841fad06d4113fb258a3412ae5719e21995d87531403e996ba850ae98', '2026-08-20 09:52:26', 0, '2026-08-20 08:52:26'),
(20, 3, 'fabd024dc369511ba9e11c09f56240def31af1b001692052195ba023745c50b0', '2026-08-20 08:52:26', 1, '2026-08-20 08:52:26'),
(21, 3, '05848be3989c8629acc1de6df1cc1a3f5a2eb45e71ef1ad8da07babe4fd11dd1', '2026-08-20 08:42:26', 0, '2026-08-20 07:42:26'),
(23, 3, '61994ae86fe5daa4ca027f9d53d1f53e6ea6e230a46a978981b94872a12a9b01', '2026-08-20 09:52:27', 0, '2026-08-20 08:52:27'),
(24, 30, '23974801fafa1817a9aa724cb3d95bd5f0900810e05431eb882e9f62cda903e4', '2026-08-20 08:55:17', 1, '2026-08-20 08:55:17'),
(25, 3, '7b7d61ed23a2cdb9003cfb4e5feef0eaf0139fb0b89b7f5eccef43d87094a7ba', '2026-08-20 09:55:19', 0, '2026-08-20 08:55:19'),
(26, 3, '354d4b6edb91a15ff7b0512f9ca4de8be23af083249317da0235fae1ab66946d', '2026-08-20 08:55:19', 1, '2026-08-20 08:55:19'),
(27, 3, 'c9bb9f13c4acba1fae2ed30c2d7a03c2f397feaeb17a0da285d4a3fe0b8a75db', '2026-08-20 08:45:19', 0, '2026-08-20 07:45:19'),
(29, 3, '8ad250f22d1242a481bda35fb82b7e6bca00aaa4c82a28cf809f6d3a7d9a8884', '2026-08-20 09:55:20', 0, '2026-08-20 08:55:20'),
(30, 40, '1db9a91d838be7de8541156219080e153b3cd92ff695b5b3c667c04b6a02e680', '2026-08-20 09:00:11', 1, '2026-08-20 09:00:11'),
(31, 3, '5f33bfd0f3e66c5f898f50eddab51cd626a4e43439b839f645adb43b38c7d531', '2026-08-20 10:00:13', 0, '2026-08-20 09:00:13'),
(32, 3, '7864820ffd406f4ce276fa97f4f7dc3e2ff30f0a2f9786552221cca443466a31', '2026-08-20 09:00:13', 1, '2026-08-20 09:00:13'),
(33, 3, 'af4072e66bf7d5f01bba1e4311da8e051d362fcbe9b43e35c0b57d0600ad859d', '2026-08-20 08:50:13', 0, '2026-08-20 07:50:13'),
(35, 3, '5cc0e7d7a2378daffaa1b0e282b07549b4c3456b9821c475f80a048abea7de9a', '2026-08-20 10:00:14', 0, '2026-08-20 09:00:14'),
(36, 3, 'e467aeaae7b806049b220c600d37bd8a61d8e8f0faf8234b935ea342ef110343', '2026-08-20 10:05:30', 0, '2026-08-20 09:05:30'),
(37, 3, '0c478defa4b5d2c74fe1eec56745d3680e9cfcf0958cef1a130216122a9243a0', '2026-08-20 09:05:30', 1, '2026-08-20 09:05:30'),
(38, 3, 'a3e52040fb6a63d9b48a36a2d3247c383e546017f1f0f63794ef4dc13d7a57bc', '2026-08-20 08:55:30', 0, '2026-08-20 07:55:30'),
(40, 3, 'b99853b8bbab6e6f7a307c36df1777256a13fa900912e4fc2851ffab852a335b', '2026-08-20 10:05:31', 0, '2026-08-20 09:05:31'),
(41, 61, '6eb63052e931109a8a90feb7d743c657520fa6e4029ee62044855cec5bb4751d', '2026-08-20 09:05:35', 1, '2026-08-20 09:05:35'),
(42, 3, '7e1184d8ce0d4096cb0f3128e2afdec75831fed86afce1977e4ab4365459eff3', '2026-08-20 10:10:03', 0, '2026-08-20 09:10:03'),
(43, 3, '1ca4fc9729c011f97421032a1dba8f1cf34d114cf071f30b845373b74af7466e', '2026-08-20 09:10:03', 1, '2026-08-20 09:10:03'),
(44, 3, '47dc81e933537688f3dc808cb4b19491c972600e0fd894c2d1b5514f2e135a58', '2026-08-20 09:00:03', 0, '2026-08-20 08:00:03'),
(46, 3, '579eb913ae15fd8520a7651189437b6b0f1d85cda01be52d44ab99d5af6d24cd', '2026-08-20 10:10:04', 0, '2026-08-20 09:10:04'),
(47, 76, 'c45b411caf01971ceae2523761641bf58c6931913908c1d2539107894029d5a2', '2026-08-20 09:10:08', 1, '2026-08-20 09:10:08'),
(48, 3, 'e3d1825d00498d35cf238851de6b3b6fd96b6016b20620eb643251a3127d90ea', '2026-08-20 10:12:28', 0, '2026-08-20 09:12:28'),
(49, 3, 'dd1f7f4c4a799a609fcffb48d2425f4ef8ddb8d334c1c8d8365fa2ef005d08aa', '2026-08-20 09:12:28', 1, '2026-08-20 09:12:28'),
(50, 3, 'be345614dddb333c54c65faf11be15f0077ae2cefefc1ec9955b2df5c5eccdc3', '2026-08-20 09:02:28', 0, '2026-08-20 08:02:28'),
(52, 3, '8c7aebe1e17324ab1fbbfd3a6f5a31e06f123c705568d3220be9608abec46680', '2026-08-20 10:12:29', 0, '2026-08-20 09:12:29'),
(53, 87, 'f961fed3273ce6f11e244c4ec78793620066c6bd8fd6b5f12ebb3f9ab8d801e2', '2026-08-20 09:12:33', 1, '2026-08-20 09:12:33'),
(54, 3, 'c5a21794f84faac28c70810688745a8b9549c6773982c885713fa438795736ac', '2026-08-20 10:12:42', 0, '2026-08-20 09:12:42'),
(55, 3, '323b283c0702bcb9288323b8cbf0dc348efd25d05d8578fe647d3905f82d5a6a', '2026-08-20 09:12:42', 1, '2026-08-20 09:12:42'),
(56, 3, 'eba0b1d86bff9d18eb6160f94b57f9b5f1d699cdb75192f97f5690dbebce75ed', '2026-08-20 09:02:42', 0, '2026-08-20 08:02:42'),
(58, 3, '73dbda65bd75f4e63a8171b0a7481916eb4b0e9c5c50ea0bfe259e4cbe584948', '2026-08-20 10:12:43', 0, '2026-08-20 09:12:43'),
(59, 98, '54352af57d2ddf2fdf0188b95e04859699ee64dc4a276628204493e066e058f1', '2026-08-20 09:12:47', 1, '2026-08-20 09:12:47'),
(60, 100, '9857413f7553a1b03297ba36332ac5531361f12f1aba73489b7ab7eccade9a20', '2026-08-20 09:13:47', 1, '2026-08-20 09:13:47'),
(61, 3, 'ae8c630b38cfa36d80deed0b9e19cb27019c5ce0e2d08f7194aa78e01616dcaf', '2026-08-20 10:13:48', 0, '2026-08-20 09:13:48'),
(62, 3, 'ae2895e12f463119036608241d8ab1fcc9f43fc39bed568e6515a38b3802da32', '2026-08-20 09:13:48', 1, '2026-08-20 09:13:48'),
(63, 3, 'e349b034647241f8160b72d1ee761a535f50b2f32b40e2fbbff4f8c582b00145', '2026-08-20 09:03:48', 0, '2026-08-20 08:03:48'),
(65, 3, '4457efd25bd6726a400d24a80413749fea399ca46dd894c337f26f66209b7143', '2026-08-20 10:13:49', 0, '2026-08-20 09:13:49'),
(66, 111, '37f9f5ea0f3b233a8cdbb10e6d55ac06a0e12b1a8df88d81f30c062f9cd727be', '2026-08-20 09:14:30', 1, '2026-08-20 09:14:30'),
(67, 3, '6bb6f85016fd92894cead54a73e1f60800cdf4d5d22c7f41b403ce6aac5f62e3', '2026-08-20 10:14:31', 0, '2026-08-20 09:14:31'),
(68, 3, '2310d0161f6c304aee8918963c3c8f34acb28f8346e4653127ffbcf6202f48fd', '2026-08-20 09:14:31', 1, '2026-08-20 09:14:31'),
(69, 3, '70585e4ca106c6ea4f213c199815ed4cfe4b900c2f596cc889e9cca2b9d24949', '2026-08-20 09:04:31', 0, '2026-08-20 08:04:31'),
(71, 3, '9478e9b8c7dd485232cdf555bfaa19a3746a3ec6075b98a15e696b15ad57be64', '2026-08-20 10:14:32', 0, '2026-08-20 09:14:32'),
(72, 122, 'a25d08a08ac4a6264a6ea6e8a27815f914520a34b0275b6f0339b17ed48dc6a7', '2026-08-20 09:15:11', 1, '2026-08-20 09:15:11'),
(73, 3, '7b43ed6a54c50901f7a32816c128da0a2b1081c57c37a5cd1c4a0967fc7126bc', '2026-08-20 10:15:13', 0, '2026-08-20 09:15:13'),
(74, 3, '91f5d59514906decd188bae9b97872cc3c8f8d7a0ebc6f9f1c0c0bf7428a46fc', '2026-08-20 09:15:13', 1, '2026-08-20 09:15:13'),
(75, 3, '74b90958fcfd127fad6b653d501b3ae8a162ec6d0c7c4018345233e4e997c8fe', '2026-08-20 09:05:13', 0, '2026-08-20 08:05:13'),
(77, 3, '201b9a07fa1ce855ac021c470d04448d4fd3e5b456477323a758fa9b030563d3', '2026-08-20 10:15:14', 0, '2026-08-20 09:15:14'),
(78, 133, '6600bb7a045d28b3225777d1ceeb4626a034b13b17957c766f996675e3be2446', '2026-08-20 09:16:25', 1, '2026-08-20 09:16:25'),
(79, 3, 'e98d00f7c0e87afe2ccdf1cc8568c08e40d42e1ebb3b7bc1af6610d57cbbef9c', '2026-08-20 10:16:27', 0, '2026-08-20 09:16:27'),
(80, 3, 'e615f1ecd57365ddf4029b9e3c30e3fa1a2079c356c57ae0c1b2324aeb11c7b9', '2026-08-20 09:16:27', 1, '2026-08-20 09:16:27'),
(81, 3, 'dc0e72baca13d22e4277a954dcb3a60c5b2323766866d5de68ef11d0755a470b', '2026-08-20 09:06:27', 0, '2026-08-20 08:06:27'),
(83, 3, '09a2c8c4b2d4447a6b1efc8539d73cc70639abbfb95966736262d7396f72770d', '2026-08-20 10:16:28', 0, '2026-08-20 09:16:28'),
(84, 144, '6a4a47cc5afb5c4442a1b0cf96b4cda7ba9db4184efa53ee876e39f9cc21523f', '2026-08-20 09:23:38', 1, '2026-08-20 09:23:38'),
(85, 3, '868f3941e1e681a2be56f37564449f889c19c133693c18adc754288939ba8142', '2026-08-20 10:23:40', 0, '2026-08-20 09:23:40'),
(86, 3, 'a78d3f8b11dc3e96ff59f71ee712b067dca2eee72a2e3203360e6b7e703527e4', '2026-08-20 09:23:40', 1, '2026-08-20 09:23:40'),
(87, 3, '724dfc349329203f7474410aeedd7d97c48c6682985ce434bb0bb36206db00f7', '2026-08-20 09:13:40', 0, '2026-08-20 08:13:40'),
(89, 3, '791f07da39d239cb323e140dd0327562cd7723876627dfc498345052740d8273', '2026-08-20 10:23:41', 0, '2026-08-20 09:23:41'),
(90, 155, 'f27b3121dfa6b1d9e9f8a1df2d1863e860b2e61666da1b03aea0e2d05348f1a1', '2026-08-20 09:28:57', 1, '2026-08-20 09:28:57'),
(91, 3, 'd8dde5c5b7259d210a05802979a590010721cd2e51d3b02403a8bfdbe0c3a90b', '2026-08-20 10:28:59', 0, '2026-08-20 09:28:59'),
(92, 3, '133ecc26837036faf4aee48d6fae2b1c8cc830072d75f9a5c8bc7a590bf0f33c', '2026-08-20 09:28:59', 1, '2026-08-20 09:28:59'),
(93, 3, 'a59c18d5ed2080d45a4988a5ff2b9788387363e2355b468207e2d8d36874b612', '2026-08-20 09:18:59', 0, '2026-08-20 08:18:59'),
(95, 3, 'b6107cdfb19ebad44e829491a8158a81f0f7955458b73807133ea5ea9d2f527e', '2026-08-20 10:29:00', 0, '2026-08-20 09:29:00'),
(96, 166, '0c9ccb7a894b3b825ab95ab099df9e1a55b2afeb8e33e019b18ae9f538f07030', '2026-08-20 09:36:21', 1, '2026-08-20 09:36:21'),
(97, 3, '6ed4bd135b51a20e0caa2396f837bb0c8eabd2bb8bcc63231ff015abdc1f3391', '2026-08-20 10:36:23', 0, '2026-08-20 09:36:23'),
(98, 3, 'f6db2405d8a742a3f993f4c02ba4f2e5f64cc5103e90726ab5f99a0a97cdf0c9', '2026-08-20 09:36:23', 1, '2026-08-20 09:36:23'),
(99, 3, '8acd0984951b91ca436bfd17547965a645a0a2fcff3e56d95377b86bbc838457', '2026-08-20 09:26:23', 0, '2026-08-20 08:26:23'),
(101, 3, '2a9a97edcbffeb4f989ad42cd75ff856e71bfc62e7c15c692fbf2c4f5b225525', '2026-08-20 10:36:24', 0, '2026-08-20 09:36:24'),
(102, 177, '9e6bb1e7d8a27e403f251b142f14e5c73fdfbf30bf20cf6cf48f8be7823bed15', '2026-08-20 09:42:32', 1, '2026-08-20 09:42:32'),
(103, 3, '20738a5ff453d8c49d520fa96c56ffafcd3a1def53d53df4a2445aee99fcf3bb', '2026-08-20 10:42:34', 0, '2026-08-20 09:42:34'),
(104, 3, '28406cc57f8b9ca4b279d15d6c017598c195faa9588e19d222b0dd9d61d66f22', '2026-08-20 09:42:34', 1, '2026-08-20 09:42:34'),
(105, 3, 'f48208b32b1f69ecd24513c533d35ce05eebd90742824978a4103151e8b04d40', '2026-08-20 09:32:34', 0, '2026-08-20 08:32:34'),
(107, 3, 'caf707ae236afd0f3187bcc2ef1ce2bb16d8e2c9289c797b43e73db34f759ab1', '2026-08-20 10:42:35', 0, '2026-08-20 09:42:35'),
(108, 188, '5892f54ee4424ce42cb195d46610bba512d66018b04e8b0e79910ac019704ad4', '2026-08-26 01:08:39', 1, '2026-08-26 01:08:39'),
(109, 3, '6ac3579736b9e9167323bc02dae280c0510771a30753645db16209544547e2b8', '2026-08-26 02:08:54', 0, '2026-08-26 01:08:54'),
(110, 3, '41789f71ccc5e2b9a02ebcac24404867a52633c0b154ec93b207aa8c1d869825', '2026-08-26 01:08:54', 1, '2026-08-26 01:08:54'),
(111, 3, '9a37cfe27544685711d93dc12a5631fce7d61762b93756dee1289fbd990fd674', '2026-08-26 00:58:54', 0, '2026-08-25 23:58:54'),
(113, 3, '43880432ca4d3b9b31f27dc467fa4177e4d8267997c0ab908c816605895b9113', '2026-08-26 02:08:56', 0, '2026-08-26 01:08:56');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `brand` varchar(255) NOT NULL,
  `weight` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `brand`, `weight`, `price`, `stock`, `image_url`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Gasul 11kg ', 'Gasul', '11kg', 975.50, 62, 'assets/img/products/1.png', 'active', '2026-08-20 08:32:06', '2026-08-26 01:10:01'),
(2, 'Solane 11kg', 'Solane', '11kg', 820.00, 30, 'assets/img/products/2.png', 'active', '2026-08-20 08:32:06', '2026-08-20 09:36:19'),
(3, 'Solane 22kg', 'Solane', '22kg', 800.00, 20, 'assets/img/products/3.png', 'active', '2026-08-20 08:32:06', '2026-08-20 08:32:06'),
(4, 'Phoenix LPG Super Test 11kg', 'Phoenix', '11kg', 1650.00, 15, 'assets/img/products/4.png', 'active', '2026-08-20 08:32:06', '2026-08-20 08:32:06'),
(5, 'Gasul 50kg', 'Gasul', '50kg', 3800.00, 8, 'assets/img/products/5.png', 'active', '2026-08-20 08:32:06', '2026-08-20 08:32:06'),
(6, 'Regasco 11kg', 'Regasco', '11kg', 835.50, 43, 'assets/img/products/6.png', 'active', '2026-08-20 08:35:28', '2026-08-20 08:35:29'),
(7, 'Super Kalan LPG 11kg', 'Super Kalan', '11kg', 835.50, 43, 'assets/img/products/7.png', 'active', '2026-08-20 08:40:26', '2026-08-20 08:40:26'),
(8, 'Brent Gas 11kg', 'Brent', '11kg', 835.50, 43, 'assets/img/products/8.png', 'active', '2026-08-20 08:41:02', '2026-08-20 08:41:02'),
(9, 'Petron Gasul 11kg', 'Petron', '11kg', 835.50, 43, 'assets/img/products/9.png', 'active', '2026-08-20 08:44:55', '2026-08-20 08:44:55'),
(10, 'Total Gaz 15kg', 'Total', '15kg', 835.50, 43, 'assets/img/products/10.png', 'active', '2026-08-20 08:46:41', '2026-08-20 08:46:41'),
(59, 'Phoenix Super LPG 413af9', 'Phoenix', '11kg', 860.00, 30, 'assets/img/products/phoenix-11kg.png', 'active', '2026-08-26 01:09:20', '2026-08-26 01:09:20');

-- --------------------------------------------------------

--
-- Table structure for table `rider_locations`
--

CREATE TABLE `rider_locations` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy` float DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('customer','admin','rider') NOT NULL DEFAULT 'customer',
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `valid_id_path` varchar(500) DEFAULT NULL,
  `profile_picture` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `phone`, `address`, `valid_id_path`, `profile_picture`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Maria Santos', 'admin@lpg.com', '$2y$10$Ujna0yVwceUnns86lCb8SuZ3y87FWmoLrqoerZ6P0qeH2mWIUfHWu', 'admin', '09289876543', 'Admin HQ, Quezon City', NULL, NULL, 'active', '2026-08-20 08:32:06', '2026-08-20 10:16:26'),
(2, 'Pedro Updated Reyes', 'rider@lpg.com', '$2y$12$uOK9OxjyKmk/Ky1RJzs62u0cymThR882E.u0mZj.yuZutltJwyJ3a', 'rider', '09358887766', 'Unit 102, Sunrise Condominium, Quezon City', NULL, NULL, 'active', '2026-08-20 08:32:06', '2026-08-26 01:09:29'),
(3, 'Janister Updated Singson', 'customer@lpg.com', '$2y$12$TgddvAtJShzP.QJsEwREbONmsPPBrVRVyr7..S4rbu4PNsA8yth36', 'customer', '09228889900', 'Unit 201, Emerald Tower, Ortigas Center, Pasig City', NULL, 'uploads/avatars/avatar_4108279c0fbf46944f68c98b55ef96bc.jpg', 'active', '2026-08-20 08:32:06', '2026-08-26 01:14:23'),
(4, 'Updated User Name', 'test_user_1787214927@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:35:28', '2026-08-20 09:27:12'),
(5, 'Updated User Name', 'test_user_1787215224@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:40:25', '2026-08-20 09:27:12'),
(6, 'Updated User Name', 'test_user_1787215260@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:41:01', '2026-08-20 09:27:12'),
(7, 'Updated User Name', 'test_user_1787215494@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:44:54', '2026-08-20 09:27:12'),
(8, 'Updated User Name', 'test_user_1787215600@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:46:40', '2026-08-20 09:27:12'),
(9, 'Updated User Name', 'test_user_1787215705@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:48:25', '2026-08-20 09:27:12'),
(14, 'Updated User Name', 'test_user_1787215811@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:50:11', '2026-08-20 09:27:12'),
(19, 'Updated User Name', 'test_user_1787215943@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:52:24', '2026-08-20 09:27:12'),
(26, 'Other Customer', 'other_cust_12d4b69e@test.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09170000000', 'Other Address', NULL, NULL, 'active', '2026-08-20 08:54:50', '2026-08-20 09:27:12'),
(30, 'Updated User Name', 'test_user_1787216116@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 08:55:16', '2026-08-20 09:27:12'),
(40, 'Updated User Name', 'test_user_1787216410@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:00:10', '2026-08-20 09:27:12'),
(50, 'Juan Dela Cruz', 'rider2@lpg.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'rider', '09359998888', '789 Rizal Ave, Manila', NULL, NULL, 'active', '2026-08-20 09:05:23', '2026-08-20 09:27:12'),
(61, 'Updated User Name', 'test_user_1787216734@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:05:34', '2026-08-20 09:27:12'),
(66, 'Maria Customer Two', 'customer2@lpg.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09177778888', '555 Taft Ave, Manila', NULL, NULL, 'active', '2026-08-20 09:09:40', '2026-08-20 09:27:12'),
(76, 'Updated User Name', 'test_user_1787217007@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:10:07', '2026-08-20 09:27:12'),
(87, 'Updated User Name', 'test_user_1787217152@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:12:32', '2026-08-20 09:27:12'),
(98, 'Updated User Name', 'test_user_1787217166@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:12:46', '2026-08-20 09:27:12'),
(100, 'Updated User Name', 'test_user_1787217225@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:13:46', '2026-08-20 09:27:12'),
(111, 'Updated User Name', 'test_user_1787217268@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:14:29', '2026-08-20 09:27:12'),
(122, 'Updated User Name', 'test_user_1787217310@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:15:10', '2026-08-20 09:27:12'),
(133, 'Updated User Name', 'test_user_1787217384@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:16:24', '2026-08-20 09:27:12'),
(144, 'Updated User Name', 'test_user_1787217817@example.com', '$2y$12$majJ5lylFF710.nyKEmOourpeRJ1hD9UEmyoEr3jg4eaYbGRdUvcm', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:23:37', '2026-08-20 09:27:12'),
(155, 'Updated User Name', 'test_user_1787218136@example.com', '$2y$12$CMF3r3dEcHnC7UXlXQ/uhOCGSWKvkqRishYWXUBYbWv0aFdshZ7GC', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:28:56', '2026-08-20 09:28:57'),
(166, 'Updated User Name', 'test_user_1787218580@example.com', '$2y$12$68sHnCkUtg3c9GwC6sR1PeCDlP/EjdhZ57NbAVvDclqLyYfLHrGmK', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:36:20', '2026-08-20 09:36:21'),
(177, 'Updated User Name', 'test_user_1787218951@example.com', '$2y$12$8vc5V0oXD3VhX4yz1lT0c.9MIfNkiZ2Oa6MoOl7/5i82tkO6munve', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-20 09:42:31', '2026-08-25 16:19:30'),
(188, 'Updated User Name', 'test_user_1787706516@example.com', '$2y$12$GitIoSicrtYQ71G636SQ4e.eQYJ2jdbBcH26o19TJXlgAY/5qsuxK', 'customer', '09199998888', '999 New Street, Quezon City', NULL, NULL, 'active', '2026-08-26 01:08:37', '2026-08-26 01:08:39');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_order` (`order_id`),
  ADD KEY `idx_chat_sender` (`sender_id`),
  ADD KEY `idx_chat_created` (`created_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orders_product` (`product_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_rider` (`rider_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_payment_reference` (`payment_reference`),
  ADD KEY `idx_payment_id` (`payment_id`),
  ADD KEY `idx_refund_status` (`refund_status`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`user_id`,`expires_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_brand` (`brand`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `rider_locations`
--
ALTER TABLE `rider_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_rider` (`rider_id`),
  ADD KEY `idx_recorded` (`recorded_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1430;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `rider_locations`
--
ALTER TABLE `rider_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=199;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_chat_messages_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chat_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_orders_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rider_locations`
--
ALTER TABLE `rider_locations`
  ADD CONSTRAINT `fk_rider_locations_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rider_locations_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
