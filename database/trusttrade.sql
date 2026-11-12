-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Nov 12, 2026 at 08:25 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `trusttrade`
--

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `raised_by` int(11) NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  `resolution_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `seller_reply` text DEFAULT NULL,
  `seller_replied_at` datetime DEFAULT NULL,
  `seller_deadline` datetime DEFAULT NULL,
  `evidence_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disputes`
--

INSERT INTO `disputes` (`id`, `order_id`, `raised_by`, `description`, `status`, `resolution_note`, `created_at`, `seller_reply`, `seller_replied_at`, `seller_deadline`, `evidence_photo`) VALUES
(650, 1096, 304, 'The order was damagfed', 'resolved', '', '2026-11-10 10:41:31', NULL, NULL, NULL, NULL),
(697, 1176, 1, 'its damaged', 'resolved', 'Payment refunded to the buyer (simulated).', '2026-11-10 14:13:08', NULL, NULL, '2026-11-13 15:13:08', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `listings`
--

CREATE TABLE `listings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` enum('Textbooks','Electronics','Calculators','Stationery','Other') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','sold','held') NOT NULL DEFAULT 'active',
  `hold_reason` varchar(255) DEFAULT NULL,
  `held_at` datetime DEFAULT NULL,
  `reviewed_ok` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listings`
--

INSERT INTO `listings` (`id`, `user_id`, `title`, `description`, `price`, `category`, `created_at`, `status`, `hold_reason`, `held_at`, `reviewed_ok`) VALUES
(143, 107, 'Calculus textbook', 'Clean copy, used for first year maths', 150.00, 'Textbooks', '2026-11-02 10:07:22', 'sold', NULL, NULL, 0),
(248, 107, 'laptop', 'good condition', 2000.00, 'Electronics', '2026-11-05 13:59:05', 'active', NULL, NULL, 0),
(410, 304, 'Book', 'Used it only 2 times. All the pages are good.', 150.00, 'Textbooks', '2026-11-09 19:02:02', 'sold', NULL, NULL, 0),
(411, 304, 'Casio fx-991EX calculator', 'Scientific calculator in good condition, used for one year of engineering maths. Comes with the cover.', 180.00, 'Calculators', '2026-11-10 09:34:45', 'active', NULL, NULL, 0),
(412, 304, 'Algorithms (CLRS) textbook', 'Fourth edition with a few pencil notes in the first chapters. Good for the algorithms course.', 350.00, 'Textbooks', '2026-11-10 09:35:42', 'active', NULL, NULL, 0),
(413, 303, 'Logitech MX Master 3 mouse', 'Wireless mouse in very good condition. Charger cable and USB receiver included.', 400.00, 'Electronics', '2026-11-10 09:36:56', 'active', NULL, NULL, 0),
(414, 303, 'Anatomy and Physiology textbook', 'Clean copy with no highlighting. Used for one semester.', 280.00, 'Textbooks', '2026-11-10 09:37:36', 'active', NULL, NULL, 0),
(415, 303, 'Notebooks and highlighters bundle', 'Three A4 notebooks, unused, and a pack of six highlighters.', 60.00, 'Stationery', '2026-11-10 09:38:32', 'active', NULL, NULL, 0),
(416, 1, 'Sony WH-1000XM4 headphones', 'Noise-cancelling headphones with good battery life. Case and cable included.', 1200.00, 'Electronics', '2026-11-10 09:39:50', 'sold', NULL, NULL, 0),
(418, 304, 'Macbook 4', 'New and sealed', 30000.00, 'Electronics', '2026-11-10 10:35:09', 'active', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `listing_images`
--

CREATE TABLE `listing_images` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `display_order` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listing_images`
--

INSERT INTO `listing_images` (`id`, `listing_id`, `image`, `display_order`) VALUES
(5, 143, 'listing_143_4c53962a76d1d859.jpg', 0),
(6, 143, 'listing_143_e9cbc0876076b244.jpg', 1),
(7, 248, 'listing_248_c21d2ccbe0b4438c.jpg', 0),
(8, 248, 'listing_248_b012db5355794954.jpg', 1),
(9, 410, 'listing_410_e15db6d8dc26df8a.jpg', 0),
(10, 410, 'listing_410_07f6e4989d8967c2.jpg', 1),
(11, 410, 'listing_410_14b50681a0ee7075.jpg', 2),
(12, 411, 'listing_411_cc0615c61bc56660.jpg', 0),
(13, 411, 'listing_411_5db36d066c8e608c.jpg', 1),
(14, 412, 'listing_412_bdcae2b530640850.jpg', 0),
(15, 412, 'listing_412_01bf175687348930.jpg', 1),
(16, 413, 'listing_413_239a10308fa0d192.jpg', 0),
(17, 413, 'listing_413_c545e2cd3e3c554b.jpg', 1),
(18, 414, 'listing_414_bc25bfc03a55fa29.jpg', 0),
(19, 414, 'listing_414_532d59353f94f948.jpg', 1),
(20, 415, 'listing_415_33279c3451321d5e.jpg', 0),
(21, 415, 'listing_415_4058174c75db1c9b.jpg', 1),
(22, 416, 'listing_416_3b7a785d2bc19043.jpg', 0),
(23, 416, 'listing_416_61ab9328759d6a96.jpg', 1),
(26, 418, 'listing_418_7508904c4e9941a9.jpg', 0),
(27, 418, 'listing_418_29c0e623dcb9d5ad.jpg', 1);

-- --------------------------------------------------------

--
-- Table structure for table `listing_views`
--

CREATE TABLE `listing_views` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `listing_views`
--

INSERT INTO `listing_views` (`id`, `user_id`, `listing_id`, `viewed_at`) VALUES
(82, 108, 143, '2026-11-02 10:13:16'),
(83, 108, 143, '2026-11-02 10:13:23'),
(112, 108, 248, '2026-11-05 13:59:46'),
(113, 108, 248, '2026-11-05 14:00:03'),
(114, 108, 248, '2026-11-05 14:00:13'),
(115, 108, 248, '2026-11-06 13:25:06'),
(116, 108, 248, '2026-11-06 13:25:10'),
(117, 108, 248, '2026-11-06 13:25:20'),
(118, 108, 248, '2026-11-09 10:47:35'),
(119, 108, 248, '2026-11-09 10:47:41'),
(120, 108, 248, '2026-11-09 10:48:05'),
(160, 303, 410, '2026-11-09 19:07:04'),
(161, 303, 410, '2026-11-09 19:07:52'),
(162, 303, 410, '2026-11-09 19:07:56'),
(163, 1, 248, '2026-11-10 09:20:48'),
(164, 1, 248, '2026-11-10 09:21:13'),
(165, 304, 416, '2026-11-10 10:35:44'),
(173, 1, 413, '2026-11-10 14:04:20'),
(174, 1, 415, '2026-11-10 14:04:44'),
(175, 1, 414, '2026-11-10 14:05:00'),
(176, 1, 411, '2026-11-10 14:05:13');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `kind` varchar(10) NOT NULL DEFAULT 'text'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `listing_id`, `sender_id`, `receiver_id`, `body`, `is_read`, `created_at`, `kind`) VALUES
(13, 248, 108, 107, 'hey?', 0, '2026-11-06 13:25:20', 'text'),
(14, 248, 108, 107, 'Ben suggested: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Sat 14 Nov 14:28.', 1, '2026-11-06 13:39:14', 'system'),
(21, 410, 303, 304, 'Anna suggested: Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00. Note: i will be sitting next to the main entrance door.', 1, '2026-11-09 19:22:51', 'system'),
(22, 410, 304, 303, 'Ben accepted the plan: Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00.', 1, '2026-11-09 19:27:58', 'system'),
(23, 416, 304, 1, 'Ben suggested: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37. Note: i will be sitting next to the main entrance door.', 1, '2026-11-10 10:37:29', 'system'),
(24, 416, 1, 304, 'Babita accepted the plan: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37.', 1, '2026-11-10 10:40:34', 'system'),
(26, 411, 1, 304, 'Babita suggested: Meet-up on campus at Library entrance, main building, Wed 11 Nov 02:00.', 1, '2026-11-10 14:07:21', 'system');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(100) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `link`, `is_read`, `created_at`) VALUES
(143, 107, 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php', 1, '2026-11-02 09:54:09'),
(144, 108, 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php', 1, '2026-11-02 10:11:56'),
(145, 107, 'New purchase request for \"Calculus textbook\". Open Orders to accept or decline.', 'orders.php', 1, '2026-11-02 10:13:23'),
(146, 108, 'Your request for \"Calculus textbook\" was accepted. Pay in Orders within 3 days, or the request is cancelled automatically.', 'orders.php', 1, '2026-11-02 10:18:46'),
(147, 108, 'Reminder: pay for \"Calculus textbook\" by 5 Nov 11:18, or the request is cancelled automatically.', 'orders.php', 1, '2026-11-03 12:47:59'),
(148, 107, 'The buyer has paid for \"Calculus textbook\". The money is held by TrustTrade: hand over the item.', 'orders.php', 1, '2026-11-03 12:52:25'),
(149, 107, 'The buyer confirmed receipt of \"Calculus textbook\". The payment has been released to you.', 'orders.php', 1, '2026-11-03 12:54:28'),
(291, 107, 'New purchase request for \"laptop\". Open Orders to accept or decline.', 'orders.php', 1, '2026-11-05 14:00:03'),
(292, 108, 'Your request for \"laptop\" was accepted. Pay in Orders within 3 days, or the request is cancelled automatically.', 'orders.php', 1, '2026-11-05 14:01:46'),
(293, 107, 'The buyer has paid for \"laptop\". The money is held by TrustTrade: hand over the item.', 'orders.php', 0, '2026-11-06 12:43:10'),
(294, 107, 'Ben suggested how to hand over \"laptop\": Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Sat 14 Nov 14:28. Open Orders to accept or suggest something else.', 'orders.php', 0, '2026-11-06 13:28:07'),
(295, 107, 'Ben suggested how to hand over \"laptop\": Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Sat 14 Nov 14:28. Open Orders to accept or suggest something else.', 'orders.php', 0, '2026-11-06 13:39:14'),
(522, 303, 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php', 1, '2026-11-09 18:40:11'),
(523, 304, 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php', 1, '2026-11-09 18:46:55'),
(524, 304, 'New purchase request for \"Book\". Open it to accept or decline.', 'order.php?id=1095', 1, '2026-11-09 19:07:25'),
(525, 303, 'Your request for \"Book\" was accepted. Pay in Orders within 3 days, or the request is cancelled automatically.', 'order.php?id=1095', 1, '2026-11-09 19:13:50'),
(526, 304, 'Anna suggested how to hand over \"Book\": Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00. Open Orders to accept or suggest something else.', 'order.php?id=1095', 1, '2026-11-09 19:22:51'),
(527, 303, 'Ben accepted the delivery plan for \"Book\": Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00.', 'order.php?id=1095', 1, '2026-11-09 19:27:58'),
(528, 304, 'The buyer has paid for \"Book\". The money is held by TrustTrade: hand over the item.', 'orders.php', 1, '2026-11-09 19:35:56'),
(529, 304, 'The buyer confirmed receipt of \"Book\". The payment has been released to you.', 'order.php?id=1095', 1, '2026-11-09 19:38:57'),
(530, 305, 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php', 1, '2026-11-10 09:49:25'),
(531, 305, 'Automatic: your listing \"iPhone 15 Pro 256GB\" was put under review (AI risk score 77: Wording often used in scams; Seller account is new). It is hidden until an administrator checks it.', 'my_listings.php', 1, '2026-11-10 09:51:00'),
(532, 1, 'New purchase request for \"Sony WH-1000XM4 headphones\". Open it to accept or decline.', 'order.php?id=1096', 1, '2026-11-10 10:35:46'),
(533, 304, 'Your request for \"Sony WH-1000XM4 headphones\" was accepted. Pay in Orders within 3 days, or the request is cancelled automatically.', 'order.php?id=1096', 1, '2026-11-10 10:36:25'),
(534, 1, 'Ben suggested how to hand over \"Sony WH-1000XM4 headphones\": Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37. Open Orders to accept or suggest something else.', 'order.php?id=1096', 1, '2026-11-10 10:37:29'),
(535, 1, 'The buyer has paid for \"Sony WH-1000XM4 headphones\". The money is held by TrustTrade: hand over the item.', 'orders.php', 1, '2026-11-10 10:38:31'),
(536, 304, 'Babita accepted the delivery plan for \"Sony WH-1000XM4 headphones\": Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37.', 'order.php?id=1096', 1, '2026-11-10 10:40:34'),
(537, 1, 'The buyer confirmed receipt of \"Sony WH-1000XM4 headphones\". The payment has been released to you.', 'order.php?id=1096', 1, '2026-11-10 10:41:08'),
(538, 1, 'A problem was reported on the order for \"Sony WH-1000XM4 headphones\". An administrator will review it.', 'order.php?id=1096', 1, '2026-11-10 10:41:31'),
(539, 304, 'Your problem report on \"Sony WH-1000XM4 headphones\" was sent to the administrators. You will be notified of the decision.', 'order.php?id=1096', 1, '2026-11-10 10:41:31'),
(540, 304, 'Administrator decision on \"Sony WH-1000XM4 headphones\": the problem report was closed as resolved.', 'orders.php', 1, '2026-11-10 10:42:26'),
(541, 1, 'Administrator decision on \"Sony WH-1000XM4 headphones\": the problem report was closed as resolved.', 'orders.php', 1, '2026-11-10 10:42:26'),
(580, 303, 'New purchase request for \"Notebooks and highlighters bundle\". Open it to accept or decline.', 'order.php?id=1175', 0, '2026-11-10 14:04:46'),
(581, 304, 'New purchase request for \"Casio fx-991EX calculator\". Open it to accept or decline.', 'order.php?id=1176', 1, '2026-11-10 14:05:15'),
(582, 1, 'Your request for \"Casio fx-991EX calculator\" was accepted. Pay in Orders within 3 days, or the request is cancelled automatically.', 'order.php?id=1176', 1, '2026-11-10 14:05:58'),
(583, 304, 'Babita suggested how to hand over \"Casio fx-991EX calculator\": Meet-up on campus at Library entrance, main building, Wed 11 Nov 02:00. Open Orders to accept or suggest something else.', 'order.php?id=1176', 1, '2026-11-10 14:07:21'),
(584, 304, 'The buyer has paid for \"Casio fx-991EX calculator\". The money is held by TrustTrade: hand over the item.', 'orders.php', 1, '2026-11-10 14:10:33'),
(585, 304, 'A problem was reported on the order for \"Casio fx-991EX calculator\". The payment is frozen until it is decided. Reply in Orders within 3 days, or the buyer is refunded automatically.', 'order.php?id=1176', 1, '2026-11-10 14:13:08'),
(586, 1, 'Your problem report on \"Casio fx-991EX calculator\" was sent to the administrators. The payment is frozen until it is decided. You will be notified of the decision.', 'order.php?id=1176', 1, '2026-11-10 14:13:08'),
(587, 1, 'Administrator decision on \"Casio fx-991EX calculator\": Payment refunded to the buyer (simulated).', 'orders.php', 1, '2026-11-10 14:16:11'),
(588, 304, 'Administrator decision on \"Casio fx-991EX calculator\": Payment refunded to the buyer (simulated).', 'orders.php', 0, '2026-11-10 14:16:11');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `type` enum('buy','trade') NOT NULL,
  `offer_text` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_status` enum('unpaid','held','released','refunded') NOT NULL DEFAULT 'unpaid',
  `payment_method` enum('card','mobilepay') DEFAULT NULL,
  `payment_amount` decimal(10,2) DEFAULT NULL,
  `payment_ref` varchar(20) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `auto_release_at` datetime DEFAULT NULL,
  `delivery_method` varchar(10) DEFAULT NULL,
  `delivery_place` varchar(160) DEFAULT NULL,
  `delivery_time` datetime DEFAULT NULL,
  `delivery_note` varchar(255) DEFAULT NULL,
  `delivery_status` varchar(10) NOT NULL DEFAULT 'none',
  `delivery_by` int(11) DEFAULT NULL,
  `tracking_ref` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `listing_id`, `buyer_id`, `seller_id`, `type`, `offer_text`, `status`, `created_at`, `payment_status`, `payment_method`, `payment_amount`, `payment_ref`, `paid_at`, `accepted_at`, `reminder_sent_at`, `auto_release_at`, `delivery_method`, `delivery_place`, `delivery_time`, `delivery_note`, `delivery_status`, `delivery_by`, `tracking_ref`) VALUES
(320, 143, 108, 107, 'buy', NULL, 'completed', '2026-11-02 10:13:23', 'released', 'card', 150.00, 'STRIPE-BXHYFGASJ1', '2026-11-03 13:52:25', '2026-11-02 11:18:46', '2026-11-03 13:47:59', '2026-11-10 13:52:25', NULL, NULL, NULL, NULL, 'none', NULL, NULL),
(629, 248, 108, 107, 'buy', NULL, 'accepted', '2026-11-05 14:00:03', 'held', 'card', 2000.00, 'STRIPE-PEMZJJNTME', '2026-11-06 13:43:10', '2026-11-05 15:01:46', NULL, '2026-11-13 13:43:10', 'post', 'telegrafvej, 5b, 2.27', '2026-11-14 14:28:00', '', 'proposed', 108, NULL),
(1095, 410, 303, 304, 'buy', NULL, 'completed', '2026-11-09 19:07:25', 'released', 'card', 150.00, 'STRIPE-SFQDJWNJBU', '2026-11-09 20:35:56', '2026-11-09 20:13:50', NULL, '2026-11-16 20:35:56', 'meetup', 'Library entrance, main building', '2026-11-10 14:00:00', 'i will be sitting next to the main entrance door.', 'agreed', 303, NULL),
(1096, 416, 304, 1, 'buy', NULL, 'completed', '2026-11-10 10:35:46', 'released', 'card', 1200.00, 'STRIPE-PXGU438JB3', '2026-11-10 11:38:31', '2026-11-10 11:36:25', NULL, '2026-11-17 11:38:31', 'post', 'telegrafvej, 5b, 2.27', '2026-11-11 14:37:00', 'i will be sitting next to the main entrance door.', 'agreed', 304, NULL),
(1175, 415, 1, 303, 'buy', NULL, 'pending', '2026-11-10 14:04:46', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL),
(1176, 411, 1, 304, 'buy', NULL, 'cancelled', '2026-11-10 14:05:15', 'refunded', 'card', 180.00, 'STRIPE-6EKPNUGVFD', '2026-11-10 15:10:33', '2026-11-10 15:05:58', NULL, '2026-11-17 15:10:32', 'meetup', 'Library entrance, main building', '2026-11-11 02:00:00', '', 'proposed', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_events`
--

CREATE TABLE `order_events` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `event` varchar(255) NOT NULL,
  `is_automatic` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_events`
--

INSERT INTO `order_events` (`id`, `order_id`, `event`, `is_automatic`, `created_at`) VALUES
(58, 320, 'Seller accepted the request', 0, '2026-11-02 10:18:46'),
(59, 320, 'Automatic: reminder sent to the buyer to pay', 1, '2026-11-03 12:47:59'),
(60, 320, 'Buyer paid 150.00 DKK through Stripe (test mode). The money is held in escrow', 0, '2026-11-03 12:52:25'),
(61, 320, 'Buyer confirmed receipt. Payment released to the seller', 0, '2026-11-03 12:54:28'),
(123, 629, 'Seller accepted the request', 0, '2026-11-05 14:01:46'),
(124, 629, 'Buyer paid 2,000.00 DKK through Stripe (test mode). The money is held in escrow', 0, '2026-11-06 12:43:10'),
(125, 629, 'Ben suggested delivery: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Sat 14 Nov 14:28', 0, '2026-11-06 13:28:07'),
(126, 629, 'Ben suggested delivery: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Sat 14 Nov 14:28', 0, '2026-11-06 13:39:14'),
(228, 1095, 'Seller accepted the request', 0, '2026-11-09 19:13:50'),
(229, 1095, 'Anna suggested delivery: Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00', 0, '2026-11-09 19:22:51'),
(230, 1095, 'Ben accepted the delivery plan: Meet-up on campus at Library entrance, main building, Tue 10 Nov 14:00', 0, '2026-11-09 19:27:58'),
(231, 1095, 'Buyer paid 150.00 DKK through Stripe (test mode). The money is held in escrow', 0, '2026-11-09 19:35:56'),
(232, 1095, 'Buyer confirmed receipt. Payment released to the seller', 0, '2026-11-09 19:38:57'),
(233, 1096, 'Seller accepted the request', 0, '2026-11-10 10:36:25'),
(234, 1096, 'Ben suggested delivery: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37', 0, '2026-11-10 10:37:29'),
(235, 1096, 'Buyer paid 1,200.00 DKK through Stripe (test mode). The money is held in escrow', 0, '2026-11-10 10:38:31'),
(236, 1096, 'Babita accepted the delivery plan: Sent by post or parcel at telegrafvej, 5b, 2.27, sent by Wed 11 Nov 14:37', 0, '2026-11-10 10:40:34'),
(237, 1096, 'Buyer confirmed receipt. Payment released to the seller', 0, '2026-11-10 10:41:08'),
(238, 1096, 'Buyer reported a problem.', 0, '2026-11-10 10:41:31'),
(256, 1176, 'Seller accepted the request', 0, '2026-11-10 14:05:58'),
(257, 1176, 'Babita suggested delivery: Meet-up on campus at Library entrance, main building, Wed 11 Nov 02:00', 0, '2026-11-10 14:07:21'),
(258, 1176, 'Buyer paid 180.00 DKK through Stripe (test mode). The money is held in escrow', 0, '2026-11-10 14:10:33'),
(259, 1176, 'Buyer reported a problem. The payment is frozen', 0, '2026-11-10 14:13:08'),
(260, 1176, 'Administrator decision: Payment refunded to the buyer (simulated).', 0, '2026-11-10 14:16:11');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `listing_id` int(11) DEFAULT NULL,
  `reported_user_id` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `student_verified` tinyint(1) NOT NULL DEFAULT 0,
  `student_id_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `needs_review` tinyint(1) NOT NULL DEFAULT 0,
  `review_reason` varchar(255) DEFAULT NULL,
  `welcome_seen` tinyint(1) NOT NULL DEFAULT 0,
  `profile_photo` varchar(255) DEFAULT NULL,
  `student_id_expiry` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `student_verified`, `student_id_photo`, `created_at`, `is_admin`, `needs_review`, `review_reason`, `welcome_seen`, `profile_photo`, `student_id_expiry`) VALUES
(1, 'Babita', 'test@example.com', '$2y$10$KiGQFLanKXmxGifwcXFBDuQp6xaBsQBDN9vFGTJdTuisZk2CUFEhi', 1, 'student_1_cd20cd092c6e1e23.jpg', '2026-09-15 13:51:15', 0, 0, NULL, 1, NULL, NULL),
(2, 'John Doe', 'john@example.com', '$2y$10$8PliT.t.VKaQCmwZoIgaTutdogfk7zsGEeDpIrc1JyX1La5q.suVa', 0, '', '2026-09-15 14:06:52', 1, 0, NULL, 0, NULL, NULL),
(3, 'Tirsana', 'tirsana@gmail.com', '$2y$10$M3RnBCADZHqRM.gbp9OOzuFAhchbsvBM52MKXlxcHFNmvi5YSo7kq', 1, 'student_3_8dbc5758bb6cba85.jpg', '2026-10-07 12:12:08', 0, 0, NULL, 0, NULL, NULL),
(107, 'Sara Seller', 'sara@test.dk', '$2y$10$CD7Z/3UlAhm68p/DDHowQu3z7JlbCFKgsspClN1IQbBSwwP.Lirl.', 1, 'student_107_d4ee4b5b457f72d6.png', '2026-11-02 09:50:25', 0, 0, NULL, 1, NULL, NULL),
(108, 'Ben Buyer', 'ben@test.dk', '$2y$10$u4aJeONz/PcM8HGlrw/Tcu84aH0jbBuD2rWTk6hE31UaupEIWI65.', 1, 'student_108_fb485294849c69bc.jpg', '2026-11-02 10:09:46', 0, 0, NULL, 1, NULL, NULL),
(303, 'Anna Demo', 'anna.demo@example.com', '$2y$10$1ONfIl7SHRbfd071toMexuSjjNFUs6yWEYbWH0TS7gRKyFWXjmyum', 1, 'student_303_0fd6638a45862b86.jpg', '2026-11-09 18:28:24', 0, 0, NULL, 1, 'face_303_27769c3dac530803.jpg', '2027-08-31'),
(304, 'Ben Demo', 'ben.demo@example.com', '$2y$10$4XSFX.rSK4ovHNSL8ywtRewh0yP9FPwM9GswziGrs7egXht.EYlYm', 1, 'student_304_9c4e31347f817045.jpg', '2026-11-09 18:45:57', 0, 0, NULL, 1, 'face_304_496c3391b80e7243.jpg', '2027-08-31'),
(305, 'Test Seller', 'test.seller@example.com', '$2y$10$SpaA2gqmbDI3GrdXgLxk9.3P4hSTbwaC2ggO5RJMHbHq9GP/AbSyu', 1, 'student_305_6b1942ffb5093291.jpg', '2026-11-10 09:48:16', 0, 0, NULL, 1, 'face_305_2693bbe0dc1e2791.jpg', '2027-08-11');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `raised_by` (`raised_by`);

--
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Indexes for table `listing_views`
--
ALTER TABLE `listing_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `order_events`
--
ALTER TABLE `order_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `reported_user_id` (`reported_user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1330;

--
-- AUTO_INCREMENT for table `listings`
--
ALTER TABLE `listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=835;

--
-- AUTO_INCREMENT for table `listing_images`
--
ALTER TABLE `listing_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `listing_views`
--
ALTER TABLE `listing_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=272;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1058;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2267;

--
-- AUTO_INCREMENT for table `order_events`
--
ALTER TABLE `order_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=466;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=206;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=604;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `listings`
--
ALTER TABLE `listings`
  ADD CONSTRAINT `listings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD CONSTRAINT `listing_images_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `listing_views`
--
ALTER TABLE `listing_views`
  ADD CONSTRAINT `listing_views_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `listing_views_ibfk_2` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_events`
--
ALTER TABLE `order_events`
  ADD CONSTRAINT `order_events_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `reports_ibfk_3` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
