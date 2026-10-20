-- ============================================================
-- ENCAVE CAFÉ — COMPLETE DATABASE
-- ============================================================
CREATE DATABASE IF NOT EXISTS `encave_nikhil`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `encave_nikhil`;

-- ---------- USERS ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `name`                   VARCHAR(100) NOT NULL,
  `email`                  VARCHAR(100) NOT NULL UNIQUE,
  `phone`                  VARCHAR(20)  NOT NULL,
  `password`               VARCHAR(255) NOT NULL,
  `is_admin`               TINYINT(1)   DEFAULT 0,
  `reset_token_hash`       VARCHAR(64)  DEFAULT NULL,
  `reset_token_expires_at` DATETIME     DEFAULT NULL,
  `reset_otp`              VARCHAR(10)  DEFAULT NULL,
  `reset_otp_expires_at`   DATETIME     DEFAULT NULL,
  `created_at`             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ---------- MENU ----------
CREATE TABLE IF NOT EXISTS `menu` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100)  NOT NULL,
  `description` TEXT,
  `price`       DECIMAL(10,2) NOT NULL,
  `category`    VARCHAR(50)   NOT NULL,
  `emoji`       VARCHAR(10)   DEFAULT '🍽️',
  `image_url`   VARCHAR(500)  DEFAULT NULL,
  `stock`       INT           DEFAULT 50
);

-- ---------- ORDERS ----------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `order_code`        VARCHAR(20)   NOT NULL UNIQUE,
  `user_id`           INT           DEFAULT NULL,
  `guest_name`        VARCHAR(100)  DEFAULT NULL,
  `items_json`        TEXT          NOT NULL,
  `total`             DECIMAL(10,2) NOT NULL,
  `status`            VARCHAR(20)   DEFAULT 'Pending',
  `payment_method`    VARCHAR(30)   DEFAULT 'Cash at Counter',
  `payment_id`        VARCHAR(100)  DEFAULT NULL,
  `razorpay_order_id` VARCHAR(100)  DEFAULT NULL,
  `payment_status`    VARCHAR(50)   DEFAULT 'Pending',
  `created_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);

-- ---------- SEED MENU ----------
INSERT IGNORE INTO `menu`
  (`id`, `name`, `description`, `price`, `category`, `emoji`, `stock`)
VALUES
  (1, 'Espresso',        'Strong and bold',          90.00,  'Coffee',      '☕', 40),
  (2, 'Cappuccino',      'Espresso with milk foam', 140.00,  'Coffee',      '☕', 40),
  (3, 'Masala Chai',     'Indian spiced tea',        60.00,  'Tea',         '🍵', 50),
  (4, 'Cold Coffee',     'Blended with ice cream',  150.00,  'Cold drinks', '🥤', 30),
  (5, 'Veg Sandwich',    'Grilled with chutney',    110.00,  'Snacks',      '🥪', 25),
  (6, 'Peri Peri Fries', 'Crispy and spicy',        120.00,  'Snacks',      '🍟', 25),
  (7, 'Brownie',         'Warm chocolate brownie',  130.00,  'Desserts',    '🍫', 20),
  (8, 'Cheesecake',      'New York style',          180.00,  'Desserts',    '🍰', 15);

-- ---------- SEED ADMIN (Email: admin@encave.com | Password: admin123) ----------
INSERT IGNORE INTO `users`
  (`name`, `email`, `phone`, `password`, `is_admin`)
VALUES
  ('Admin', 'admin@encave.com', '0000000000',
   '$2y$10$GgwH8FdHCstoy2bhcRYK5.KkWYy6Rh3EbcJujnycUX0SffJ0MCspC',
   1);