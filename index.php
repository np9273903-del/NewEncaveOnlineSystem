<?php
date_default_timezone_set('Asia/Kolkata');
// ==========================================
// 1. DATABASE CONNECTION & SELF-HEALING AUTO-INSTALL
// ==========================================
require_once 'config.php';
require_once 'mailer.php';

try {
    // 1. Connect to MySQL server
    $pdo = new PDO("mysql:host=".DB_HOST.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Ensure database exists and select it
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `".DB_NAME."`");

    // 3. Ensure USERS table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `phone` VARCHAR(20) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `is_admin` TINYINT(1) DEFAULT 0,
        `reset_token_hash` VARCHAR(64) DEFAULT NULL,
        `reset_token_expires_at` DATETIME DEFAULT NULL,
        `reset_otp` VARCHAR(10) DEFAULT NULL,
        `reset_otp_expires_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Self-heal: add missing columns in users if any
    $userCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    $neededUserCols = [
        'is_admin'               => "TINYINT(1) DEFAULT 0",
        'phone'                  => "VARCHAR(20) NOT NULL DEFAULT ''",
        'reset_token_hash'       => "VARCHAR(64) DEFAULT NULL",
        'reset_token_expires_at' => "DATETIME DEFAULT NULL",
        'reset_otp'              => "VARCHAR(10) DEFAULT NULL",
        'reset_otp_expires_at'   => "DATETIME DEFAULT NULL"
    ];
    foreach ($neededUserCols as $col => $def) {
        if (!in_array($col, $userCols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $def");
        }
    }

    // 4. Ensure MENU table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `menu` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT,
        `price` DECIMAL(10,2) NOT NULL,
        `category` VARCHAR(50) NOT NULL,
        `emoji` VARCHAR(10) DEFAULT '🍽️',
        `image_url` VARCHAR(500) DEFAULT NULL,
        `stock` INT DEFAULT 50
    )");

    // Self-heal: add missing columns in menu if any
    $menuCols = $pdo->query("SHOW COLUMNS FROM `menu`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('image_url', $menuCols)) {
        $pdo->exec("ALTER TABLE `menu` ADD COLUMN `image_url` VARCHAR(500) DEFAULT NULL");
    }
    if (!in_array('emoji', $menuCols)) {
        $pdo->exec("ALTER TABLE `menu` ADD COLUMN `emoji` VARCHAR(10) DEFAULT '🍽️'");
    }
    if (!in_array('stock', $menuCols)) {
        $pdo->exec("ALTER TABLE `menu` ADD COLUMN `stock` INT DEFAULT 50");
    }

    // 5. Ensure ORDERS table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_code` VARCHAR(20) NOT NULL UNIQUE,
        `user_id` INT DEFAULT NULL,
        `guest_name` VARCHAR(100) DEFAULT NULL,
        `items_json` TEXT NOT NULL,
        `total` DECIMAL(10,2) NOT NULL,
        `status` VARCHAR(20) DEFAULT 'Pending',
        `payment_method` VARCHAR(30) DEFAULT 'Cash at Counter',
        `payment_id` VARCHAR(100) DEFAULT NULL,
        `razorpay_order_id` VARCHAR(100) DEFAULT NULL,
        `payment_status` VARCHAR(50) DEFAULT 'Pending',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Self-heal: add missing columns in orders if any
    $orderCols = $pdo->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_COLUMN);
    $neededOrderCols = [
        'payment_method'    => "VARCHAR(30) NOT NULL DEFAULT 'Cash at Counter'",
        'payment_id'        => "VARCHAR(100) DEFAULT NULL",
        'razorpay_order_id' => "VARCHAR(100) DEFAULT NULL",
        'payment_status'    => "VARCHAR(50) NOT NULL DEFAULT 'Pending'"
    ];
    foreach ($neededOrderCols as $col => $def) {
        if (!in_array($col, $orderCols)) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `$col` $def");
        }
    }

    // 6. Seed initial menu if empty
    if ($pdo->query("SELECT COUNT(*) FROM `menu`")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `menu` (`name`, `description`, `price`, `category`, `emoji`, `stock`) VALUES
        ('Espresso', 'Strong and bold double shot', 90.00, 'Coffee', '☕', 40),
        ('Cappuccino', 'Espresso with rich velvety foam', 140.00, 'Coffee', '☕', 40),
        ('Masala Chai', 'Traditional Indian spiced tea', 60.00, 'Tea', '🍵', 50),
        ('Cold Coffee', 'Creamy coffee blended with ice cream', 150.00, 'Cold drinks', '🥤', 30),
        ('Veg Sandwich', 'Grilled sandwich with mint chutney', 110.00, 'Snacks', '🥪', 25),
        ('Peri Peri Fries', 'Crispy french fries with spicy seasoning', 120.00, 'Snacks', '🍟', 25),
        ('Brownie', 'Warm gooey chocolate brownie', 130.00, 'Desserts', '🍫', 20),
        ('Cheesecake', 'Classic New York style slice', 180.00, 'Desserts', '🍰', 15)");
    }

    // 7. Guarantee Admin user always exists and password matches config.php
    $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@encave.com';
    $adminPass  = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'admin123';
    $adminHash  = password_hash($adminPass, PASSWORD_DEFAULT);

    $adminCheck = $pdo->prepare("SELECT id, password FROM `users` WHERE LOWER(email) = LOWER(?) OR LOWER(name) = 'admin' LIMIT 1");
    $adminCheck->execute([$adminEmail]);
    $adminRow = $adminCheck->fetch(PDO::FETCH_ASSOC);

    if (!$adminRow) {
        $pdo->prepare("INSERT INTO `users` (`name`, `email`, `phone`, `password`, `is_admin`) VALUES ('Admin', ?, '0000000000', ?, 1)")
            ->execute([$adminEmail, $adminHash]);
    } else {
        // Always sync password and guarantee is_admin = 1
        $pdo->prepare("UPDATE `users` SET `password` = ?, `is_admin` = 1 WHERE `id` = ?")
            ->execute([$adminHash, $adminRow['id']]);
    }

} catch (PDOException $e) {
    die("<h1 style='color:#FF4D4D;text-align:center;margin-top:50px;font-family:sans-serif;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</h1>");
}

session_start();

// ==========================================
// 2. IMAGE UPLOAD HANDLER
// ==========================================
if (isset($_GET['upload'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_admin'])) {
        echo json_encode(['error' => 'Admin authorization required']);
        exit;
    }
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Please select a valid image file']);
        exit;
    }

    $f = $_FILES['image'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        echo json_encode(['error' => 'Only JPG, PNG, WEBP, or GIF allowed']);
        exit;
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'Image size must be less than 5MB']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $ext = $allowed[$mime];
    $filename = 'product_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (move_uploaded_file($f['tmp_name'], $targetPath)) {
        echo json_encode(['success' => true, 'url' => 'uploads/' . $filename]);
    } else {
        echo json_encode(['error' => 'Failed to save uploaded file']);
    }
    exit;
}

// ==========================================
// 3. API ROUTER
// ==========================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    function requireUser() {
        if (empty($_SESSION['user']['id'])) {
            echo json_encode(['error' => 'Please log in to continue']);
            exit;
        }
    }

    function requireAdminUser() {
        if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['is_admin'])) {
            echo json_encode(['error' => 'Admin privileges required']);
            exit;
        }
    }

    // ---------- AUTH: SIGNUP ----------
    if ($action === 'signup') {
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $pass = $input['password'] ?? '';

        if (!$name || !$email || !$phone || !$pass) {
            echo json_encode(['error' => 'All fields are required']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Please enter a valid email address']);
            exit;
        }
        if (strlen($pass) < 4) {
            echo json_encode(['error' => 'Password must be at least 4 characters']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM `users` WHERE LOWER(email) = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'This email is already registered. Please log in.']);
            exit;
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `phone`, `password`, `is_admin`) VALUES (?, ?, ?, ?, 0)");
        if ($stmt->execute([$name, $email, $phone, $hash])) {
            $newId = $pdo->lastInsertId();
            $_SESSION['user'] = [
                'id'       => (int)$newId,
                'name'     => $name,
                'email'    => $email,
                'is_admin' => 0
            ];
            echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            echo json_encode(['error' => 'Registration failed. Please try again.']);
        }
        exit;
    }

    // ---------- AUTH: LOGIN ----------
    if ($action === 'login') {
        $loginId = trim($input['email'] ?? $input['id'] ?? '');
        $pass = (string)($input['password'] ?? $input['pass'] ?? '');

        if (!$loginId || !$pass) {
            echo json_encode(['error' => 'Please enter your email/username and password']);
            exit;
        }

        // Support login by email OR username (e.g. 'admin' or 'admin@encave.com')
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE LOWER(email) = LOWER(?) OR LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$loginId, $loginId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $valid = false;
        if ($user) {
            if (password_verify($pass, $user['password'])) {
                $valid = true;
            } else if (!empty($user['is_admin']) && defined('ADMIN_PASSWORD') && $pass === ADMIN_PASSWORD) {
                // Self-healing: if admin password in config was changed, accept and rehash
                $valid = true;
                $newHash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE `users` SET `password` = ? WHERE `id` = ?")->execute([$newHash, $user['id']]);
            }
        } else if (defined('ADMIN_EMAIL') && defined('ADMIN_PASSWORD') && 
                  (strtolower($loginId) === strtolower(ADMIN_EMAIL) || strtolower($loginId) === 'admin') && 
                  $pass === ADMIN_PASSWORD) {
            // Admin auto-creation fallback
            $newHash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO `users` (`name`, `email`, `phone`, `password`, `is_admin`) VALUES ('Admin', ?, '0000000000', ?, 1)")
                ->execute([ADMIN_EMAIL, $newHash]);
            $user = [
                'id'       => (int)$pdo->lastInsertId(),
                'name'     => 'Admin',
                'email'    => ADMIN_EMAIL,
                'is_admin' => 1
            ];
            $valid = true;
        }

        if ($valid && $user) {
            $_SESSION['user'] = [
                'id'       => (int)$user['id'],
                'name'     => $user['name'],
                'email'    => $user['email'],
                'is_admin' => (int)($user['is_admin'] ?? 0)
            ];
            echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            echo json_encode(['error' => 'Invalid email or password']);
        }
        exit;
    }

    // ---------- AUTH: SEND PASSWORD RESET OTP & LINK ----------
    if ($action === 'send_reset_otp') {
        $email = strtolower(trim($input['email'] ?? ''));

        if (!$email) {
            echo json_encode(['error' => 'Please enter your registered email address']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Please enter a valid email address']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, email FROM `users` WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['error' => 'No account found with this email address']);
            exit;
        }

        // Generate 6-digit numeric OTP and 32-character hex token
        $otp = (string)random_int(100000, 999999);
        $token = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $token);
        $expiry = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes validity

        $update = $pdo->prepare("UPDATE `users` SET 
            `reset_token_hash` = ?, 
            `reset_token_expires_at` = DATE_ADD(NOW(), INTERVAL 15 MINUTE), 
            `reset_otp` = ?, 
            `reset_otp_expires_at` = DATE_ADD(NOW(), INTERVAL 15 MINUTE) 
            WHERE `id` = ?");
        $update->execute([$tokenHash, $otp, $user['id']]);

        // Dispatch email via PHPMailer
        $mailRes = sendPasswordResetEmail($user['email'], $user['name'], $otp, $token);

        if ($mailRes['sent']) {
            echo json_encode([
                'success' => true,
                'sent'    => true,
                'email'   => $user['email'],
                'message' => 'Verification code sent to ' . $user['email'] . '! Please check your email inbox (and spam folder).'
            ]);
        } else {
            // Friendly dev mode notice if SMTP is not yet filled in config.php
            $isConfigured = !empty($mailRes['smtp_configured']);
            $msg = $isConfigured
                ? 'Email sending failed: ' . ($mailRes['error'] ?? 'SMTP Error') . ' | Dev OTP: ' . $otp
                : 'Verification code generated! (Dev mode OTP: ' . $otp . '). Configure SMTP in config.php to send real emails to your inbox.';

            echo json_encode([
                'success'         => true,
                'sent'            => false,
                'smtp_configured' => $isConfigured,
                'dev_otp'         => $otp,
                'dev_token'       => $token,
                'email'           => $user['email'],
                'message'         => $msg
            ]);
        }
        exit;
    }

    // ---------- AUTH: VERIFY RESET TOKEN (When clicking link from email) ----------
    if ($action === 'verify_reset_token') {
        $token = trim($input['token'] ?? '');
        if (!$token) {
            echo json_encode(['error' => 'Reset token is required']);
            exit;
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT id, email FROM `users` WHERE `reset_token_hash` = ? AND `reset_token_expires_at` >= NOW() LIMIT 1");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['error' => 'This password reset link is invalid or has expired']);
            exit;
        }

        echo json_encode(['success' => true, 'email' => $user['email']]);
        exit;
    }

    // ---------- AUTH: VERIFY RESET OTP ONLY ----------
    if ($action === 'verify_reset_otp') {
        $email = strtolower(trim($input['email'] ?? ''));
        $otp = trim($input['otp'] ?? '');

        if (!$email || !$otp) {
            echo json_encode(['error' => 'Please provide both email and OTP code']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM `users` WHERE LOWER(email) = ? AND `reset_otp` = ? AND `reset_otp_expires_at` >= NOW() LIMIT 1");
        $stmt->execute([$email, $otp]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['error' => 'Invalid or expired OTP code. Please check your email and try again']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- AUTH: RESET PASSWORD WITH OTP OR TOKEN ----------
    if ($action === 'reset_password_with_otp' || $action === 'forgot_password') {
        $email = strtolower(trim($input['email'] ?? ''));
        $otp = trim($input['otp'] ?? '');
        $token = trim($input['token'] ?? '');
        $pass = (string)($input['password'] ?? '');
        $confirmPass = (string)($input['confirm_password'] ?? '');

        if (!$pass || !$confirmPass) {
            echo json_encode(['error' => 'Please enter and confirm your new password']);
            exit;
        }
        if ($pass !== $confirmPass) {
            echo json_encode(['error' => 'Passwords do not match']);
            exit;
        }
        if (strlen($pass) < 4) {
            echo json_encode(['error' => 'Password must be at least 4 characters']);
            exit;
        }

        $user = null;

        // Path A: Verified via Direct Link Token
        if ($token) {
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare("SELECT id, email FROM `users` WHERE `reset_token_hash` = ? AND `reset_token_expires_at` >= NOW() LIMIT 1");
            $stmt->execute([$tokenHash]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['error' => 'Invalid or expired reset link. Please request a new verification code.']);
                exit;
            }
        } 
        // Path B: Verified via Email + 6-digit OTP
        else if ($email && $otp) {
            $stmt = $pdo->prepare("SELECT id, email FROM `users` WHERE LOWER(email) = ? AND `reset_otp` = ? AND `reset_otp_expires_at` >= NOW() LIMIT 1");
            $stmt->execute([$email, $otp]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['error' => 'Invalid or expired OTP code. Please check your email or request a new code.']);
                exit;
            }
        } 
        else {
            echo json_encode(['error' => 'A valid OTP verification code or reset link is required to change password']);
            exit;
        }

        // Apply new hashed password and invalidate used tokens/OTPs
        $newHash = password_hash($pass, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE `users` SET 
            `password` = ?, 
            `reset_token_hash` = NULL, 
            `reset_token_expires_at` = NULL, 
            `reset_otp` = NULL, 
            `reset_otp_expires_at` = NULL 
            WHERE `id` = ?");

        if ($update->execute([$newHash, $user['id']])) {
            echo json_encode(['success' => true, 'message' => 'Password reset successfully! You can now log in with your new password.']);
        } else {
            echo json_encode(['error' => 'Failed to update password. Please try again.']);
        }
        exit;
    }

    // ---------- AUTH: LOGOUT ----------
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- AUTH: CURRENT USER ----------
    if ($action === 'me') {
        echo json_encode(['user' => $_SESSION['user'] ?? null]);
        exit;
    }

    // ---------- MENU (Logged-in users) ----------
    if ($action === 'menu') {
        requireUser();
        $items = $pdo->query("SELECT * FROM `menu` ORDER BY `category`, `name`")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['items' => $items]);
        exit;
    }

    // ---------- CUSTOMER: MY ORDERS ----------
    if ($action === 'my_orders') {
        requireUser();
        $userId = $_SESSION['user']['id'];
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 25");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['orders' => $orders]);
        exit;
    }

    // ---------- PAYMENT: CREATE RAZORPAY ORDER ----------
    if ($action === 'create_razorpay_order') {
        requireUser();
        $total = (float)($input['total'] ?? 0);
        if ($total <= 0) {
            echo json_encode(['error' => 'Invalid order amount']);
            exit;
        }

        $amountInPaise = (int)round($total * 100);
        $receipt = 'rcpt_' . rand(1000, 9999) . '_' . time();

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'amount'          => $amountInPaise,
            'currency'        => defined('RAZORPAY_CURRENCY') ? RAZORPAY_CURRENCY : 'INR',
            'receipt'         => $receipt,
            'payment_capture' => 1
        ]));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $rzOrder = json_decode($response, true);
            echo json_encode([
                'success'           => true,
                'razorpay_order_id' => $rzOrder['id'],
                'amount'            => $rzOrder['amount'],
                'currency'          => $rzOrder['currency'],
                'key'               => RAZORPAY_KEY_ID
            ]);
        } else {
            $errObj = json_decode($response, true);
            $errMsg = $errObj['error']['description'] ?? $curlErr ?: 'Failed to create Razorpay order';
            echo json_encode(['error' => $errMsg]);
        }
        exit;
    }

    // ---------- PAYMENT: VERIFY RAZORPAY PAYMENT & PLACE ORDER ----------
    if ($action === 'verify_razorpay_payment') {
        requireUser();
        $rzpOrderId = trim($input['razorpay_order_id'] ?? '');
        $rzpPaymentId = trim($input['razorpay_payment_id'] ?? '');
        $rzpSignature = trim($input['razorpay_signature'] ?? '');
        $cart = $input['cart'] ?? [];
        $total = (float)($input['total'] ?? 0);
        $userId = $_SESSION['user']['id'];
        $guestName = $_SESSION['user']['name'] ?? 'Customer';

        if (!$rzpOrderId || !$rzpPaymentId || !$rzpSignature) {
            echo json_encode(['error' => 'Payment verification details missing']);
            exit;
        }
        if (empty($cart)) {
            echo json_encode(['error' => 'Cart is empty']);
            exit;
        }

        // Verify Razorpay HMAC SHA256 Signature
        $expectedSignature = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, RAZORPAY_KEY_SECRET);
        if (!hash_equals($expectedSignature, $rzpSignature)) {
            echo json_encode(['error' => 'Payment signature verification failed. Please contact counter with ID: ' . $rzpPaymentId]);
            exit;
        }

        $code = 'ENC-' . rand(1000, 9999);
        $stmt = $pdo->prepare("INSERT INTO `orders` 
            (`order_code`, `user_id`, `guest_name`, `items_json`, `total`, `status`, `payment_method`, `payment_id`, `razorpay_order_id`, `payment_status`) 
            VALUES (?, ?, ?, ?, ?, 'Preparing', 'Razorpay (Online)', ?, ?, 'Paid')");

        if ($stmt->execute([$code, $userId, $guestName, json_encode($cart), $total, $rzpPaymentId, $rzpOrderId])) {
            // Decrement menu stock
            foreach ($cart as $item) {
                if (!empty($item['id']) && !empty($item['qty'])) {
                    $pdo->prepare("UPDATE `menu` SET `stock` = GREATEST(0, `stock` - ?) WHERE `id` = ?")
                        ->execute([(int)$item['qty'], (int)$item['id']]);
                }
            }

            echo json_encode([
                'success'        => true,
                'order_code'     => $code,
                'payment_id'     => $rzpPaymentId,
                'payment_method' => 'Razorpay (Online)',
                'payment_status' => 'Paid',
                'status'         => 'Preparing',
                'total'          => $total,
                'items'          => $cart
            ]);
        } else {
            echo json_encode(['error' => 'Payment received (' . $rzpPaymentId . ') but failed to save order. Please show this to counter.']);
        }
        exit;
    }

    // ---------- CHECKOUT (Cash on Counter) ----------
    if ($action === 'checkout') {
        requireUser();
        $cart = $input['cart'] ?? [];
        $total = (float)($input['total'] ?? 0);
        $userId = $_SESSION['user']['id'];
        $guestName = $_SESSION['user']['name'] ?? 'Customer';

        if (empty($cart)) {
            echo json_encode(['error' => 'Your cart is empty']);
            exit;
        }

        $code = 'ENC-' . rand(1000, 9999);
        $stmt = $pdo->prepare("INSERT INTO `orders` 
            (`order_code`, `user_id`, `guest_name`, `items_json`, `total`, `status`, `payment_method`, `payment_status`) 
            VALUES (?, ?, ?, ?, ?, 'Awaiting Cash', 'Cash at Counter', 'Unpaid (Awaiting Cash)')");

        if ($stmt->execute([$code, $userId, $guestName, json_encode($cart), $total])) {
            echo json_encode([
                'success'        => true,
                'order_code'     => $code,
                'payment_method' => 'Cash at Counter',
                'payment_status' => 'Unpaid (Awaiting Cash)',
                'status'         => 'Awaiting Cash',
                'total'          => $total,
                'items'          => $cart
            ]);
        } else {
            echo json_encode(['error' => 'Failed to place order']);
        }
        exit;
    }

    // ---------- ADMIN: CONFIRM CASH PAYMENT & START PREPARING ----------
    if ($action === 'admin_confirm_cash') {
        requireAdminUser();
        $orderId = (int)($input['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        $up = $pdo->prepare("UPDATE `orders` SET `status` = 'Preparing', `payment_status` = 'Paid (Cash)' WHERE `id` = ?");
        if ($up->execute([$orderId])) {
            // Decrement menu item stock now that payment is confirmed
            $cart = json_decode($order['items_json'], true) ?? [];
            foreach ($cart as $item) {
                if (!empty($item['id']) && !empty($item['qty'])) {
                    $pdo->prepare("UPDATE `menu` SET `stock` = GREATEST(0, `stock` - ?) WHERE `id` = ?")
                        ->execute([(int)$item['qty'], (int)$item['id']]);
                }
            }

            echo json_encode(['success' => true, 'order_code' => $order['order_code'], 'total' => $order['total']]);
        } else {
            echo json_encode(['error' => 'Failed to confirm cash collection']);
        }
        exit;
    }

    // ---------- ADMIN: REJECT CASH ORDER (Payment Not Received) ----------
    if ($action === 'admin_reject_cash') {
        requireAdminUser();
        $orderId = (int)($input['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        $up = $pdo->prepare("UPDATE `orders` SET `status` = 'Cancelled', `payment_status` = 'Not Collected (Cancelled)' WHERE `id` = ?");
        if ($up->execute([$orderId])) {
            echo json_encode(['success' => true, 'order_code' => $order['order_code']]);
        } else {
            echo json_encode(['error' => 'Failed to reject order']);
        }
        exit;
    }

    // ---------- ADMIN: VIEW ORDERS ----------
    if ($action === 'admin_orders') {
        requireAdminUser();
        $orders = $pdo->query("SELECT * FROM `orders` ORDER BY `created_at` DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['orders' => $orders]);
        exit;
    }

    // ---------- ADMIN: UPDATE ORDER STATUS ----------
    if ($action === 'admin_order_status') {
        requireAdminUser();
        $id = (int)($input['id'] ?? 0);
        $status = $input['status'] ?? 'Pending';
        $allowed = ['Pending', 'Awaiting Cash', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
        if (!in_array($status, $allowed)) {
            echo json_encode(['error' => 'Invalid status']);
            exit;
        }
        $pdo->prepare("UPDATE `orders` SET `status` = ? WHERE `id` = ?")->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- ADMIN: ADD NEW PRODUCT ----------
    if ($action === 'admin_add_product') {
        requireAdminUser();
        $name = trim($input['name'] ?? '');
        $desc = trim($input['description'] ?? '');
        $price = (float)($input['price'] ?? 0);
        $cat = trim($input['category'] ?? 'Misc');
        $emoji = trim($input['emoji'] ?? '🍽️');
        $img = trim($input['image_url'] ?? '');
        $stock = (int)($input['stock'] ?? 50);

        if (!$name || $price <= 0) {
            echo json_encode(['error' => 'Product name and valid price are required']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO `menu` (`name`, `description`, `price`, `category`, `emoji`, `image_url`, `stock`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $desc, $price, $cat, $emoji, $img, $stock]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // ---------- ADMIN: UPDATE PRODUCT ----------
    if ($action === 'admin_update_product') {
        requireAdminUser();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid product ID']);
            exit;
        }

        $allowedFields = ['name', 'description', 'price', 'category', 'emoji', 'image_url', 'stock'];
        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = "`$field` = ?";
                $params[] = $input[$field];
            }
        }

        if (empty($sets)) {
            echo json_encode(['error' => 'No fields to update']);
            exit;
        }

        $params[] = $id;
        $sql = "UPDATE `menu` SET " . implode(', ', $sets) . " WHERE `id` = ?";
        $pdo->prepare($sql)->execute($params);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- ADMIN: DELETE PRODUCT ----------
    if ($action === 'admin_delete_product') {
        requireAdminUser();
        $id = (int)($input['id'] ?? 0);
        $pdo->prepare("DELETE FROM `menu` WHERE `id` = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Encave Café</title>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg: #F4F6FF;
        --card: #FFFFFF;
        --ink: #10132B;
        --mute: #5B6085;
        --pri: #2F4BFF;
        --pri-hover: #1E37E0;
        --accent: #FFD23F;
        --danger: #FF4D4D;
        --ok: #10B981;
        --border: 2px solid #10132B;
        --shadow: 4px 4px 0 #10132B;
        --shadow-sm: 2px 2px 0 #10132B;
        --tag-bg: #EEF2FF;
        --input-bg: #FAFAFF;
        --font-main: 'DM Sans', sans-serif;
        --font-title: 'Bricolage Grotesque', sans-serif;
    }

    [data-theme="dark"] {
        --bg: #0D0F1D;
        --card: #16192E;
        --ink: #F4F6FF;
        --mute: #8E95C2;
        --pri: #4F6BFF;
        --pri-hover: #3855F7;
        --accent: #FFD23F;
        --danger: #FF5A5A;
        --ok: #10B981;
        --border: 2px solid #2B2F52;
        --shadow: 4px 4px 0 #000000;
        --shadow-sm: 2px 2px 0 #000000;
        --tag-bg: #222642;
        --input-bg: #1A1E36;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background-color: var(--bg);
        color: var(--ink);
        font-family: var(--font-main);
        background-image: radial-gradient(color-mix(in srgb, var(--ink) 12%, transparent) 1.5px, transparent 1.5px);
        background-size: 24px 24px;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        transition: background-color 0.2s, color 0.2s;
    }
    h1, h2, h3 { font-family: var(--font-title); letter-spacing: -0.01em; }

    /* NAVBAR */
    header {
        background: var(--card);
        border-bottom: var(--border);
        padding: 12px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 100;
        gap: 12px;
        flex-wrap: wrap;
    }
    .logo {
        font-size: 22px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        user-select: none;
    }
    .logo img {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        object-fit: cover;
        border: var(--border);
        box-shadow: 2px 2px 0 #10132B;
    }
    nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .btn {
        background: var(--accent);
        color: #10132B;
        border: var(--border);
        padding: 8px 16px;
        border-radius: 12px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        box-shadow: var(--shadow-sm);
        transition: 0.1s;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        user-select: none;
    }
    .btn:hover { filter: brightness(1.05); }
    .btn:active { transform: translate(2px, 2px); box-shadow: none; }
    .btn.ghost { background: var(--card); color: var(--ink); }
    .btn.primary { background: var(--pri); color: #fff; }
    .btn.danger { background: var(--danger); color: #fff; }
    .btn.sm { padding: 5px 12px; font-size: 13px; border-radius: 8px; }

    main { max-width: 1100px; margin: 24px auto; padding: 0 20px; width: 100%; flex-grow: 1; }
    .view { display: none; }
    .view.active { display: block; animation: fadeIn 0.25s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

    /* HERO BANNER */
    .hero-banner {
        background: linear-gradient(135deg, var(--card) 0%, color-mix(in srgb, var(--pri) 12%, var(--card)) 100%);
        border: var(--border);
        border-radius: 22px;
        padding: 26px 30px;
        box-shadow: var(--shadow);
        margin-bottom: 26px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }
    .hero-content { flex: 1; min-width: 260px; }
    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 99px;
        background: var(--tag-bg);
        border: var(--border);
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 12px;
    }
    .pulse-dot {
        width: 8px;
        height: 8px;
        background: var(--ok);
        border-radius: 50%;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
        animation: pulse 1.8s infinite;
    }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
    .hero-banner h2 { font-size: clamp(24px, 4vw, 34px); margin-bottom: 8px; }
    .hero-banner p { color: var(--mute); font-size: 15px; max-width: 48ch; line-height: 1.4; }
    .hero-stats { display: flex; gap: 12px; }
    .hero-stat-card {
        background: var(--card);
        border: var(--border);
        border-radius: 14px;
        padding: 12px 18px;
        box-shadow: var(--shadow-sm);
        text-align: center;
        min-width: 105px;
    }
    .hero-stat-card .stat-num { font-size: 18px; font-weight: 800; font-family: var(--font-title); display: block; }
    .hero-stat-card .stat-lbl { font-size: 11px; color: var(--mute); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

    /* AUTH VIEW */
    .auth-box {
        background: var(--card);
        border: var(--border);
        border-radius: 22px;
        padding: 34px;
        max-width: 440px;
        margin: 30px auto;
        box-shadow: var(--shadow);
    }
    .auth-box h2 { margin-bottom: 20px; text-align: center; font-size: 28px; }
    .form-group { margin-bottom: 16px; position: relative; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 700; font-size: 14px; }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px 14px;
        border: var(--border);
        border-radius: 12px;
        font-family: var(--font-main);
        font-size: 15px;
        outline: none;
        background: var(--input-bg);
        color: var(--ink);
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        box-shadow: 2px 2px 0 var(--pri);
    }
    .pw-wrap { position: relative; }
    .pw-toggle-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        opacity: 0.65;
        padding: 4px;
        line-height: 1;
    }
    .pw-toggle-btn:hover { opacity: 1; }
    .auth-toggle { text-align: center; margin-top: 16px; font-size: 14px; }
    .auth-toggle a { color: var(--pri); font-weight: 700; cursor: pointer; text-decoration: underline; }

    /* SEARCH & CATEGORIES */
    .filter-bar {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
    }
    .search-input {
        flex: 1;
        min-width: 240px;
        padding: 11px 16px;
        border: var(--border);
        border-radius: 14px;
        font-family: var(--font-main);
        font-size: 15px;
        box-shadow: var(--shadow-sm);
        outline: none;
        background: var(--card);
        color: var(--ink);
    }
    .search-input:focus { box-shadow: 2px 2px 0 var(--pri); }
    .cats-list { display: flex; gap: 8px; flex-wrap: wrap; }
    .cat-pill {
        padding: 7px 15px;
        border: var(--border);
        border-radius: 99px;
        font-size: 14px;
        font-weight: 700;
        background: var(--card);
        color: var(--ink);
        cursor: pointer;
        box-shadow: 2px 2px 0 var(--ink);
        transition: 0.1s;
    }
    .cat-pill.active { background: var(--pri); color: #fff; }

    /* MENU GRID */
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 20px; }
    .card {
        background: var(--card);
        border: var(--border);
        border-radius: 18px;
        padding: 16px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: transform 0.15s, box-shadow 0.15s;
    }
    .card:hover { transform: translateY(-2px); }
    .card-thumb {
        width: 100%;
        height: 135px;
        border-radius: 12px;
        border: var(--border);
        background: var(--tag-bg);
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
    }
    .card-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .card h3 { font-size: 18px; line-height: 1.25; }
    .card p { color: var(--mute); font-size: 13px; flex-grow: 1; line-height: 1.4; }
    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        border-top: 1px dashed color-mix(in srgb, var(--ink) 20%, transparent);
        padding-top: 10px;
    }
    .price { font-size: 20px; font-weight: 800; font-family: var(--font-title); }

    /* CARD STEPPER */
    .card-stepper {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--card);
        border: var(--border);
        border-radius: 10px;
        padding: 3px 6px;
        box-shadow: var(--shadow-sm);
    }
    .card-stepper button {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        border: 1.5px solid var(--ink);
        background: var(--accent);
        color: #10132B;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }
    .card-stepper button:active { transform: scale(0.92); }
    .card-stepper span {
        font-weight: 800;
        min-width: 22px;
        text-align: center;
        font-size: 14px;
    }

    /* CART */
    .cart-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
    .cart-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px dashed color-mix(in srgb, var(--ink) 20%, transparent);
        gap: 12px;
    }
    .cart-total {
        font-size: 22px;
        font-weight: 800;
        text-align: right;
        margin: 20px 0 10px;
        border-top: 2px solid var(--ink);
        padding-top: 14px;
        font-family: var(--font-title);
    }

    /* FLOATING CART BAR */
    .floating-cart-bar {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: var(--pri);
        color: #fff;
        border: var(--border);
        border-radius: 99px;
        padding: 12px 22px;
        box-shadow: var(--shadow);
        display: none;
        align-items: center;
        gap: 12px;
        font-weight: 800;
        cursor: pointer;
        z-index: 90;
        transition: transform 0.15s;
    }
    .floating-cart-bar:hover { transform: scale(1.03); }
    .floating-cart-bar:active { transform: scale(0.98); }

    /* ADMIN KPI CARDS */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .kpi-card {
        background: var(--card);
        border: var(--border);
        border-radius: 16px;
        padding: 16px 20px;
        box-shadow: var(--shadow-sm);
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .kpi-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: var(--tag-bg);
        border: var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .kpi-num { font-size: 24px; font-weight: 800; font-family: var(--font-title); line-height: 1.1; }
    .kpi-lbl { font-size: 12px; color: var(--mute); font-weight: 700; text-transform: uppercase; }

    /* ADMIN TABS & TABLES */
    .admin-nav { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
    .admin-tab {
        padding: 10px 20px;
        border: var(--border);
        border-radius: 12px;
        background: var(--card);
        color: var(--ink);
        cursor: pointer;
        font-weight: 700;
        box-shadow: var(--shadow-sm);
        font-size: 15px;
    }
    .admin-tab.active { background: var(--pri); color: #fff; }
    .admin-section { display: none; }
    .admin-section.active { display: block; animation: fadeIn 0.2s ease-out; }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--card);
        border: var(--border);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: var(--shadow);
    }
    th, td {
        padding: 12px 14px;
        text-align: left;
        border-bottom: 1px solid color-mix(in srgb, var(--ink) 12%, transparent);
        font-size: 14px;
        vertical-align: middle;
    }
    th {
        background: var(--tag-bg);
        font-weight: 800;
        font-family: var(--font-title);
        border-bottom: var(--border);
    }
    tr:hover { background: color-mix(in srgb, var(--pri) 4%, var(--card)); }
    .status-pill {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid var(--border);
        display: inline-block;
    }
    .status-Pending { background: #FEF08A; color: #854D0E; }
    .status-Awaiting, .status-Awaiting-Cash { background: #FED7AA; color: #9A3412; border-color: #EA580C; }
    .status-Preparing { background: #BAE6FD; color: #0369A1; }
    .status-Ready { background: #BBF7D0; color: #166534; }
    .status-Completed { background: #E0E7FF; color: #3730A3; }
    .status-Cancelled { background: #FECDD3; color: #9F1239; }

    /* TOAST */
    #toast {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--accent);
        color: #10132B;
        padding: 12px 26px;
        border: var(--border);
        border-radius: 12px;
        font-weight: 700;
        box-shadow: var(--shadow);
        display: none;
        z-index: 1000;
        font-size: 15px;
    }

    /* MODAL */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(16, 19, 43, 0.65);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 500;
        padding: 20px;
        backdrop-filter: blur(2px);
    }
    .modal-card {
        background: var(--card);
        border: var(--border);
        border-radius: 22px;
        padding: 28px;
        max-width: 520px;
        width: 100%;
        box-shadow: var(--shadow);
        max-height: 90vh;
        overflow-y: auto;
        animation: scaleIn 0.2s ease-out;
    }
    @keyframes scaleIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    /* ORDER CONFIRMATION MODAL SPECIFIC */
    .order-code-badge {
        background: var(--accent);
        color: #10132B;
        border: var(--border);
        border-radius: 12px;
        padding: 12px 20px;
        font-size: 28px;
        font-weight: 800;
        font-family: var(--font-title);
        letter-spacing: 1px;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        margin: 14px 0;
    }
    .status-steps {
        display: flex;
        justify-content: space-between;
        margin: 20px 0;
        position: relative;
    }
    .status-steps::before {
        content: '';
        position: absolute;
        top: 15px;
        left: 30px;
        right: 30px;
        height: 3px;
        background: color-mix(in srgb, var(--ink) 20%, transparent);
        z-index: 1;
    }
    .step-node {
        position: relative;
        z-index: 2;
        text-align: center;
        font-size: 12px;
        font-weight: 700;
        color: var(--mute);
    }
    .step-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--card);
        border: 2px solid var(--mute);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 6px;
        font-size: 14px;
    }
    .step-node.active { color: var(--pri); }
    .step-node.active .step-circle {
        border-color: var(--pri);
        background: var(--pri);
        color: #fff;
    }

    @media (max-width: 768px) {
        .cart-grid { grid-template-columns: 1fr; }
        header { padding: 10px 16px; }
        .logo { font-size: 18px; }
        .hero-banner { padding: 20px; }
        .floating-cart-bar { bottom: 16px; right: 16px; padding: 10px 18px; font-size: 13px; }
    }
</style>
</head>
<body>

<header>
    <div class="logo" onclick="goHome()">
        <img src="encave.jpg" alt="Encave Café">
        Encave Café
    </div>
    <nav id="nav">
        <!-- Injected via JS based on login state -->
    </nav>
</header>

<main>
    <!-- 1. AUTH VIEW (Shown first if not logged in) -->
    <div id="view-auth" class="view">
        <div class="auth-box" id="login-box">
            <h2>Welcome to Encave</h2>
            <div class="form-group">
                <label>Email or Username</label>
                <input type="text" id="login-email" placeholder="Enter your email or username">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="pw-wrap">
                    <input type="password" id="login-password" placeholder="Enter your password">
                    <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('login-password', this)">👁️</button>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:-6px;margin-bottom:14px;">
                <a onclick="toggleAuth('forgot')" style="font-size:13px;color:var(--pri);font-weight:700;cursor:pointer;text-decoration:none;">Forgot Password?</a>
            </div>
            <button class="btn primary" style="width:100%;justify-content:center;padding:12px;" onclick="doLogin()">Log In</button>
            <div class="auth-toggle">
                Don't have an account? <a onclick="toggleAuth('signup')">Sign up here</a>
            </div>
        </div>

        <div class="auth-box" id="signup-box" style="display:none;">
            <h2>Create an Account</h2>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" id="signup-name" placeholder="Full Name">
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" id="signup-email" placeholder="Email Address">
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" id="signup-phone" placeholder="Phone Number">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="pw-wrap">
                    <input type="password" id="signup-password" placeholder="Password (min 4 characters)">
                    <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('signup-password', this)">👁️</button>
                </div>
            </div>
            <button class="btn primary" style="width:100%;justify-content:center;padding:12px;" onclick="doSignup()">Create Account</button>
            <div class="auth-toggle">
                Already have an account? <a onclick="toggleAuth('login')">Log in</a>
            </div>
        </div>

        <div class="auth-box" id="forgot-box" style="display:none;">
            <!-- STEP 1: Enter email to request OTP -->
            <div id="forgot-step-1">
                <h2>Reset Password</h2>
                <p style="color:var(--mute);text-align:center;font-size:14px;margin-bottom:18px;">
                    Enter your registered email address. We will send you a 6-digit verification OTP and a 1-click reset link.
                </p>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="forgot-email" placeholder="e.g. user@example.com" autocomplete="email">
                </div>
                <button class="btn primary" id="btn-send-otp" style="width:100%;justify-content:center;padding:12px;" onclick="requestResetOtp()">
                    📩 Send Verification Code
                </button>
                <div class="auth-toggle">
                    Remembered your password? <a onclick="toggleAuth('login')">Back to Log in</a>
                </div>
            </div>

            <!-- STEP 2: Enter OTP & New Password -->
            <div id="forgot-step-2" style="display:none;">
                <h2>Verify & Set Password</h2>
                <div style="background:var(--bg-warm);border:var(--border);border-radius:10px;padding:12px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;font-weight:700;color:var(--mute);letter-spacing:0.5px;">Verification for</div>
                        <div style="font-weight:700;font-size:14px;word-break:break-all;" id="forgot-sent-email">user@email.com</div>
                    </div>
                    <button class="btn sm ghost" onclick="backToForgotStep1()" title="Change Email" style="white-space:nowrap;">Change</button>
                </div>

                <!-- Hidden token if user arrived via email link -->
                <input type="hidden" id="forgot-token-val" value="">

                <!-- Dev Mode OTP Notice (shown when SMTP credentials are not yet added in config.php) -->
                <div id="otp-dev-notice" style="display:none;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:12px;border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.5;">
                    <div style="display:flex;align-items:center;gap:6px;font-weight:800;margin-bottom:4px;color:#b45309;">
                        <span>⚡ Local Test Mode (SMTP not configured)</span>
                    </div>
                    <div>To send real emails to your Gmail inbox, add your Gmail &amp; App Password in <code>config.php</code>.</div>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #fcd34d;display:flex;align-items:center;justify-content:space-between;">
                        <span>Your OTP Code: <strong style="font-size:17px;letter-spacing:2px;color:#11142b;font-family:monospace;" id="dev-otp-code">------</strong></span>
                        <button type="button" class="btn sm primary" onclick="autoFillOtp()" style="padding:4px 10px;font-size:11px;">⚡ Auto-Fill</button>
                    </div>
                </div>

                <div class="form-group" id="forgot-otp-group">
                    <label style="display:flex;justify-content:space-between;align-items:center;">
                        <span>6-Digit OTP Code</span>
                        <span style="font-size:11px;color:var(--mute);font-weight:normal;" id="otp-timer-text">Valid for 15m</span>
                    </label>
                    <input type="text" id="forgot-otp" maxlength="6" placeholder="• • • • • •" 
                           style="letter-spacing:8px;font-size:22px;text-align:center;font-weight:800;font-family:monospace;"
                           inputmode="numeric" autocomplete="one-time-code">
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="forgot-password" placeholder="New password (min 4 characters)">
                        <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('forgot-password', this)">👁️</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="forgot-confirm-password" placeholder="Confirm new password">
                        <button type="button" class="pw-toggle-btn" onclick="togglePasswordVisibility('forgot-confirm-password', this)">👁️</button>
                    </div>
                </div>

                <button class="btn primary" id="btn-reset-pw" style="width:100%;justify-content:center;padding:12px;" onclick="doResetPasswordWithOtp()">
                    🔒 Reset & Save Password
                </button>

                <div id="forgot-resend-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:13px;">
                    <span style="color:var(--mute);">Didn't receive code?</span>
                    <a onclick="resendResetOtp()" style="color:var(--pri);font-weight:700;cursor:pointer;text-decoration:none;">Resend OTP</a>
                </div>

                <div class="auth-toggle" style="margin-top:16px;">
                    <a onclick="toggleAuth('login')">← Back to Log in</a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. HOME VIEW (Menu - only for logged in users) -->
    <div id="view-home" class="view">
        <!-- Hero Banner -->
        <div class="hero-banner">
            <div class="hero-content">
                <div class="hero-badge">
                    <span class="pulse-dot"></span>
                    <span>Counter Open • Fresh Handcrafted Orders</span>
                </div>
                <h2 id="hero-greeting">Fresh Brews & Delicious Bites</h2>
                <p>Skip the counter queue by ordering ahead. Made fresh on order with signature beans & ingredients.</p>
            </div>
            <div class="hero-stats">
                <div class="hero-stat-card">
                    <span class="stat-num">⚡ 5-10m</span>
                    <span class="stat-lbl">Avg. Pickup</span>
                </div>
                <div class="hero-stat-card">
                    <span class="stat-num">⭐ 4.9</span>
                    <span class="stat-lbl">Rating</span>
                </div>
            </div>
        </div>

        <div class="filter-bar">
            <input type="text" class="search-input" id="search-input" placeholder="🔍 Search coffee, snacks, desserts..." oninput="filterMenu()">
            <div class="cats-list" id="cats-container">
                <!-- Injected via JS -->
            </div>
        </div>

        <div class="grid" id="menu-grid">
            <!-- Menu cards injected via JS -->
        </div>
    </div>

    <!-- 3. CART VIEW (Logged in users) -->
    <div id="view-cart" class="view">
        <div class="cart-grid">
            <div class="card" style="padding:24px;">
                <h2 style="margin-bottom:18px;">🛒 Your Order</h2>
                <div id="cart-items-list"></div>
                <div class="cart-total">Total: ₹<span id="cart-total-price">0</span></div>
            </div>

            <div class="card" style="padding:24px;">
                <h2 style="margin-bottom:14px;">Checkout &amp; Payment</h2>
                <div class="form-group" style="margin-bottom:14px;">
                    <label>Pickup Customer Name</label>
                    <input type="text" id="checkout-name" readonly style="background:var(--tag-bg);font-weight:700;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="margin-bottom:8px;display:block;">Select Payment Method</label>
                    
                    <!-- Option 1: Razorpay Online -->
                    <div class="pay-option active" id="pay-opt-razorpay" onclick="selectPaymentMethod('razorpay')" 
                         style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:2px solid var(--pri);border-radius:12px;cursor:pointer;margin-bottom:10px;background:var(--card);transition:all 0.15s;">
                        <input type="radio" name="pay_choice" id="pay-radio-razorpay" value="razorpay" checked style="margin-top:3px;accent-color:var(--pri);">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <strong style="font-size:14px;color:var(--ink);">💳 Pay Online (Razorpay)</strong>
                                <span style="background:#10b981;color:#fff;font-size:10px;padding:2px 6px;border-radius:4px;font-weight:800;">INSTANT</span>
                            </div>
                            <div style="font-size:12px;color:var(--mute);margin-top:2px;">UPI, GPay, PhonePe, Paytm, QR, Cards, Netbanking</div>
                        </div>
                    </div>

                    <!-- Option 2: Cash at Counter -->
                    <div class="pay-option" id="pay-opt-cash" onclick="selectPaymentMethod('cash')" 
                         style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:12px;cursor:pointer;background:var(--card);transition:all 0.15s;">
                        <input type="radio" name="pay_choice" id="pay-radio-cash" value="cash" style="margin-top:3px;accent-color:var(--pri);">
                        <div style="flex:1;">
                            <strong style="font-size:14px;color:var(--ink);">💵 Pay Cash at Counter</strong>
                            <div style="font-size:12px;color:var(--mute);margin-top:2px;">Pay with cash when collecting your order at the counter</div>
                        </div>
                    </div>
                </div>

                <button class="btn primary" id="btn-place-order" style="width:100%;padding:14px;font-size:16px;justify-content:center;" onclick="handleCheckout()">
                    💳 Pay Online (₹<span id="btn-pay-total">0</span>)
                </button>
                <button class="btn ghost" style="width:100%;margin-top:10px;justify-content:center;" onclick="showView('home')">← Continue Ordering</button>
            </div>
        </div>
    </div>

    <!-- 4. MY ORDERS VIEW (Customer order history & tracker) -->
    <div id="view-my-orders" class="view">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <h2>📜 My Order History</h2>
            <div style="display:flex;gap:10px;">
                <button class="btn sm ghost" onclick="loadMyOrders()">🔄 Refresh Status</button>
                <button class="btn sm primary" onclick="showView('home')">🍽️ Order Again</button>
            </div>
        </div>
        <div id="my-orders-list">
            <!-- Loaded via JS -->
        </div>
    </div>

    <!-- 5. ADMIN VIEW (Admin user only) -->
    <div id="view-admin" class="view">
        <!-- KPI Stat Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon">📦</div>
                <div>
                    <div class="kpi-num" id="kpi-total-orders">0</div>
                    <div class="kpi-lbl">Total Orders</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">⏳</div>
                <div>
                    <div class="kpi-num" id="kpi-active-orders">0</div>
                    <div class="kpi-lbl">In Kitchen / Active</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">💰</div>
                <div>
                    <div class="kpi-num" id="kpi-revenue">₹0</div>
                    <div class="kpi-lbl">Total Revenue</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">🍽️</div>
                <div>
                    <div class="kpi-num" id="kpi-menu-count">0</div>
                    <div class="kpi-lbl">Menu Items</div>
                </div>
            </div>
        </div>

        <div class="admin-nav">
            <button class="admin-tab active" onclick="showAdminTab('orders', this)">📋 Customer Orders</button>
            <button class="admin-tab" onclick="showAdminTab('products', this)">🍽️ Manage Menu & Photos</button>
            <button class="admin-tab" onclick="showAdminTab('add', this)">➕ Add New Product</button>
        </div>

        <!-- Admin Tab: Orders -->
        <div id="admin-orders" class="admin-section active">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;" id="admin-order-filters">
                    <button class="cat-pill active" onclick="filterAdminOrders('All', this)">All</button>
                    <button class="cat-pill" onclick="filterAdminOrders('Awaiting Cash', this)">💵 Awaiting Cash</button>
                    <button class="cat-pill" onclick="filterAdminOrders('Preparing', this)">Preparing</button>
                    <button class="cat-pill" onclick="filterAdminOrders('Ready', this)">Ready</button>
                    <button class="cat-pill" onclick="filterAdminOrders('Completed', this)">Completed</button>
                    <button class="cat-pill" onclick="filterAdminOrders('Cancelled', this)">Cancelled</button>
                </div>
                <div style="display:flex;gap:10px;">
                    <input type="text" id="admin-order-search" placeholder="Search order code or name..." 
                           oninput="filterAdminOrdersList()" style="padding:6px 12px;border:var(--border);border-radius:10px;font-size:13px;width:220px;">
                    <button class="btn sm ghost" onclick="loadAdminOrders()">🔄 Refresh</button>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table id="orders-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Payment</th>
                            <th>Items Ordered</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Update Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- Admin Tab: Manage Menu & Photos -->
        <div id="admin-products" class="admin-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <h3>Menu Inventory & Pricing</h3>
                <button class="btn sm primary" onclick="showAdminTab('add', document.querySelectorAll('.admin-tab')[2])">➕ Add Product</button>
            </div>
            <div style="overflow-x:auto;">
                <table id="products-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Photo / Emoji</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price (₹)</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- Admin Tab: Add Product -->
        <div id="admin-add" class="admin-section">
            <div class="card" style="padding:28px;max-width:620px;">
                <h3 style="margin-bottom:18px;">➕ Add New Product to Menu</h3>
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" id="np-name" placeholder="e.g. Caramel Macchiato">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="np-desc" rows="2" placeholder="Rich espresso with vanilla syrup and caramel drizzle"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Price (₹) *</label>
                        <input type="number" id="np-price" step="0.01" placeholder="150.00">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select id="np-category">
                            <option value="Coffee">Coffee</option>
                            <option value="Tea">Tea</option>
                            <option value="Cold drinks">Cold drinks</option>
                            <option value="Snacks">Snacks</option>
                            <option value="Desserts">Desserts</option>
                            <option value="Special">Special</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Fallback Emoji</label>
                        <input type="text" id="np-emoji" value="☕">
                    </div>
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" id="np-stock" value="50">
                    </div>
                </div>
                <div class="form-group">
                    <label>Product Photo (Upload File or Enter URL)</label>
                    <input type="file" id="np-file" accept="image/*" onchange="handleProductUpload(this, 'np-image-preview', 'np-image-val')" style="padding:8px;">
                    <input type="text" id="np-image-val" placeholder="Or enter image URL (https://...)" style="margin-top:8px;">
                    <div id="np-image-preview" style="margin-top:10px;display:none;">
                        <img src="" style="width:90px;height:70px;object-fit:cover;border-radius:8px;border:var(--border);">
                    </div>
                </div>
                <button class="btn primary" style="width:100%;padding:14px;margin-top:10px;justify-content:center;font-size:16px;" onclick="addProduct()">Save Product to Menu</button>
            </div>
        </div>
    </div>
</main>

<!-- FLOATING CART BUTTON -->
<div class="floating-cart-bar" id="floating-cart-bar" onclick="showView('cart')">
    <span>🛒 Cart</span>
    <span id="floating-cart-count" style="background:#fff;color:var(--pri);padding:2px 8px;border-radius:99px;font-size:12px;">0</span>
    <span>•</span>
    <span>₹<span id="floating-cart-total">0</span></span>
</div>

<!-- ORDER SUCCESS & RECEIPT MODAL -->
<div class="modal-overlay" id="order-success-modal">
    <div class="modal-card" style="text-align:center;">
        <div style="font-size:52px;margin-bottom:10px;" id="modal-icon">🎉</div>
        <h2 style="font-size:26px;" id="modal-title">Order Placed Successfully!</h2>
        <p style="color:var(--mute);margin-top:6px;" id="modal-subtitle">Show this pickup code at the counter when collecting.</p>
        
        <div>
            <div class="order-code-badge">
                <span id="modal-order-code">ENC-0000</span>
                <button class="btn sm ghost" onclick="copyOrderCode()" title="Copy code" style="padding:4px 8px;font-size:14px;">📋 Copy</button>
            </div>
            <div id="modal-payment-pill" style="margin-top:8px;"></div>
        </div>

        <!-- Live Status Tracker Bar -->
        <div class="status-steps" id="modal-status-steps">
            <div class="step-node active" id="modal-step-1">
                <div class="step-circle" id="modal-step-1-circle">✓</div>
                <span id="modal-step-1-label">Placed</span>
            </div>
            <div class="step-node active" id="modal-step-2">
                <div class="step-circle" id="modal-step-2-circle">☕</div>
                <span id="modal-step-2-label">Preparing</span>
            </div>
            <div class="step-node" id="modal-step-3">
                <div class="step-circle" id="modal-step-3-circle">🔔</div>
                <span id="modal-step-3-label">Ready</span>
            </div>
        </div>

        <div style="background:var(--tag-bg);border:var(--border);border-radius:14px;padding:14px;text-align:left;margin-bottom:20px;">
            <div style="font-weight:700;font-size:13px;color:var(--mute);text-transform:uppercase;margin-bottom:8px;">Order Summary</div>
            <div id="modal-order-items" style="font-size:14px;line-height:1.6;"></div>
            <div style="border-top:1px dashed var(--ink);margin-top:10px;padding-top:8px;display:flex;justify-content:space-between;font-weight:800;">
                <span>Total Amount:</span>
                <span>₹<span id="modal-order-total">0</span></span>
            </div>
        </div>

        <div style="display:flex;gap:10px;">
            <button class="btn primary" style="flex:1;justify-content:center;" onclick="closeSuccessModalAndTrack()">📜 Track in My Orders</button>
            <button class="btn ghost" style="flex:1;justify-content:center;" onclick="closeSuccessModal()">Order More</button>
        </div>
    </div>
</div>

<!-- EDIT PRODUCT MODAL -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3>Edit Product Details</h3>
            <button class="btn sm ghost" onclick="closeEditModal()">✕</button>
        </div>
        <input type="hidden" id="edit-id">
        <div class="form-group">
            <label>Name</label>
            <input type="text" id="edit-name">
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea id="edit-desc" rows="2"></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>Price (₹)</label>
                <input type="number" id="edit-price" step="0.01">
            </div>
            <div class="form-group">
                <label>Category</label>
                <input type="text" id="edit-category">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>Emoji</label>
                <input type="text" id="edit-emoji">
            </div>
            <div class="form-group">
                <label>Stock</label>
                <input type="number" id="edit-stock">
            </div>
        </div>
        <div class="form-group">
            <label>Product Photo</label>
            <input type="file" accept="image/*" onchange="handleProductUpload(this, 'edit-preview-box', 'edit-image-val')" style="padding:6px;margin-bottom:6px;">
            <input type="text" id="edit-image-val" placeholder="Image URL">
            <div id="edit-preview-box" style="margin-top:8px;">
                <img id="edit-preview-img" src="" style="width:80px;height:60px;object-fit:cover;border-radius:8px;border:var(--border);display:none;">
            </div>
        </div>
        <div style="display:flex;gap:10px;margin-top:20px;">
            <button class="btn primary" style="flex:1;justify-content:center;" onclick="saveEditProduct()">Save Changes</button>
            <button class="btn ghost" onclick="closeEditModal()">Cancel</button>
        </div>
    </div>
</div>

<div id="toast">Notification</div>

<script>
// ==========================================
// CLIENT STATE
// ==========================================
let menuData = [];
let cart = {};
let currentUser = null;
let currentView = 'auth';
let activeCategory = 'All';
let adminOrdersData = [];
let adminActiveFilter = 'All';

// ==========================================
// API HELPER
// ==========================================
async function api(action, data = {}) {
    try {
        const res = await fetch(`?api=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const text = await res.text();
        let json = null;
        try {
            json = JSON.parse(text);
        } catch (e) {
            const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(clean || 'Server returned invalid response');
        }
        if (json.error) throw new Error(json.error);
        return json;
    } catch (err) {
        showToast(err.message, false);
        return null;
    }
}

function showToast(msg, ok = true) {
    const t = document.getElementById('toast');
    t.innerText = msg;
    t.style.background = ok ? 'var(--accent)' : 'var(--danger)';
    t.style.color = ok ? '#10132B' : '#FFFFFF';
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3200);
}

// ==========================================
// THEME SUPPORT (Dark / Light)
// ==========================================
function initTheme() {
    const saved = localStorage.getItem('encave_theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeBtn();
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('encave_theme', next);
    updateThemeBtn();
}

function updateThemeBtn() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const btn = document.getElementById('theme-toggle-btn');
    if (btn) btn.innerHTML = isDark ? '☀️' : '🌙';
}

function togglePasswordVisibility(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    if (field.type === 'password') {
        field.type = 'text';
        btn.innerText = '🙈';
    } else {
        field.type = 'password';
        btn.innerText = '👁️';
    }
}

// ==========================================
// NAVIGATION & ACCESS CONTROL
// ==========================================
function updateNav() {
    const nav = document.getElementById('nav');
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const themeBtnHtml = `<button class="btn sm ghost" onclick="toggleTheme()" id="theme-toggle-btn" title="Toggle theme" style="padding:6px 10px;">${isDark ? '☀️' : '🌙'}</button>`;

    if (currentUser) {
        const count = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
        let adminHtml = '';
        if (currentUser.is_admin == 1) {
            adminHtml = `<button class="btn ghost sm" onclick="showView('admin')">⚙️ Admin Dashboard</button>`;
        }
        nav.innerHTML = `
            <button class="btn ghost sm" onclick="showView('home')">🍽️ Menu</button>
            <button class="btn ghost sm" onclick="showView('cart')">🛒 Cart (<span id="cart-nav-count">${count}</span>)</button>
            <button class="btn ghost sm" onclick="showView('my-orders')">📜 My Orders</button>
            ${adminHtml}
            ${themeBtnHtml}
            <span style="font-weight:700;font-size:13px;padding:6px 10px;background:var(--tag-bg);border-radius:10px;border:var(--border);">
                ${currentUser.is_admin ? '👑' : '👤'} ${currentUser.name.split(' ')[0]}
            </span>
            <button class="btn sm danger" onclick="doLogout()">Logout</button>
        `;
    } else {
        nav.innerHTML = `
            ${themeBtnHtml}
            <button class="btn sm ghost" onclick="showLogin()">Log In</button>
            <button class="btn sm primary" onclick="showSignup()">Sign Up</button>
        `;
    }
}

function showView(viewName) {
    if (!currentUser && viewName !== 'auth') {
        showToast('Please log in to view the menu', false);
        viewName = 'auth';
    }

    if (viewName === 'admin' && (!currentUser || currentUser.is_admin != 1)) {
        showToast('Admin authorization required', false);
        viewName = 'home';
    }

    currentView = viewName;
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    const target = document.getElementById(`view-${viewName}`);
    if (target) target.classList.add('active');

    // Update greeting on Home
    if (viewName === 'home') {
        updateHeroGreeting();
        loadMenu();
    } else if (viewName === 'cart') {
        renderCart();
        if (currentUser) {
            document.getElementById('checkout-name').value = currentUser.name;
        }
    } else if (viewName === 'my-orders') {
        loadMyOrders();
    } else if (viewName === 'admin') {
        loadAdminOrders();
        loadAdminProducts();
    }

    updateFloatingCart();
}

function updateHeroGreeting() {
    if (!currentUser) return;
    const hour = new Date().getHours();
    let timeGreeting = 'Good day';
    if (hour < 12) timeGreeting = 'Good morning';
    else if (hour < 17) timeGreeting = 'Good afternoon';
    else timeGreeting = 'Good evening';

    const el = document.getElementById('hero-greeting');
    if (el) {
        el.innerText = `${timeGreeting}, ${currentUser.name.split(' ')[0]}! ☕`;
    }
}

function goHome() {
    if (currentUser) {
        showView(currentUser.is_admin == 1 ? 'admin' : 'home');
    } else {
        showView('auth');
    }
}

function showLogin() {
    showView('auth');
    toggleAuth('login');
}

function showSignup() {
    showView('auth');
    toggleAuth('signup');
}

function toggleAuth(type) {
    const loginBox = document.getElementById('login-box');
    const signupBox = document.getElementById('signup-box');
    const forgotBox = document.getElementById('forgot-box');

    loginBox.style.display = type === 'login' ? 'block' : 'none';
    signupBox.style.display = type === 'signup' ? 'block' : 'none';
    forgotBox.style.display = type === 'forgot' ? 'block' : 'none';

    if (type === 'forgot') {
        backToForgotStep1();
    }

    document.getElementById('login-password').value = '';
    document.getElementById('signup-password').value = '';
    document.getElementById('forgot-password').value = '';
    document.getElementById('forgot-confirm-password').value = '';
    if (document.getElementById('forgot-otp')) {
        document.getElementById('forgot-otp').value = '';
    }
}

// ==========================================
// AUTHENTICATION
// ==========================================
async function doSignup() {
    const name = document.getElementById('signup-name').value.trim();
    const email = document.getElementById('signup-email').value.trim();
    const phone = document.getElementById('signup-phone').value.trim();
    const password = document.getElementById('signup-password').value;

    if (!name || !email || !phone || !password) {
        return showToast('Please fill out all fields', false);
    }

    const res = await api('signup', { name, email, phone, password });
    if (res && res.success) {
        currentUser = res.user;
        updateNav();
        showToast('Account created successfully! Welcome!');
        showView('home');
    }
}

async function doLogin() {
    const email = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;

    if (!email || !password) {
        return showToast('Please enter your email/username and password', false);
    }

    const res = await api('login', { email, password });
    if (res && res.success) {
        currentUser = res.user;
        updateNav();
        if (currentUser.is_admin == 1) {
            showToast('Welcome, Administrator!');
            showView('admin');
        } else {
            showToast('Welcome back, ' + currentUser.name + '!');
            showView('home');
        }
    }
}

let currentResetEmail = '';

function backToForgotStep1() {
    const s1 = document.getElementById('forgot-step-1');
    const s2 = document.getElementById('forgot-step-2');
    if (s1) s1.style.display = 'block';
    if (s2) s2.style.display = 'none';
    const otpGroup = document.getElementById('forgot-otp-group');
    if (otpGroup) otpGroup.style.display = 'block';
    const resendRow = document.getElementById('forgot-resend-row');
    if (resendRow) resendRow.style.display = 'flex';
    const devNotice = document.getElementById('otp-dev-notice');
    if (devNotice) devNotice.style.display = 'none';
    const tokenVal = document.getElementById('forgot-token-val');
    if (tokenVal) tokenVal.value = '';
}

function autoFillOtp() {
    const code = document.getElementById('dev-otp-code')?.innerText;
    if (code && code !== '------') {
        const input = document.getElementById('forgot-otp');
        if (input) {
            input.value = code;
            input.focus();
            showToast('OTP auto-filled! Now enter your new password.');
        }
    }
}

async function requestResetOtp() {
    const email = document.getElementById('forgot-email').value.trim();
    if (!email) {
        return showToast('Please enter your registered email address', false);
    }

    const btn = document.getElementById('btn-send-otp');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Sending code...';

    const res = await api('send_reset_otp', { email });
    btn.disabled = false;
    btn.innerHTML = oldText;

    if (res && res.success) {
        currentResetEmail = res.email || email;
        document.getElementById('forgot-sent-email').innerText = currentResetEmail;
        document.getElementById('forgot-step-1').style.display = 'none';
        document.getElementById('forgot-step-2').style.display = 'block';
        document.getElementById('forgot-otp-group').style.display = 'block';
        document.getElementById('forgot-resend-row').style.display = 'flex';
        document.getElementById('forgot-token-val').value = '';

        const devNotice = document.getElementById('otp-dev-notice');
        if (res.dev_otp) {
            if (devNotice) {
                devNotice.style.display = 'block';
                document.getElementById('dev-otp-code').innerText = res.dev_otp;
            }
            showToast('OTP code generated: ' + res.dev_otp);
        } else {
            if (devNotice) devNotice.style.display = 'none';
            showToast('Verification code sent to your email inbox!');
        }

        setTimeout(() => {
            const otpInput = document.getElementById('forgot-otp');
            if (otpInput) otpInput.focus();
        }, 150);
    } else {
        showToast(res.error || 'Failed to send verification code', false);
    }
}

async function resendResetOtp() {
    const email = currentResetEmail || document.getElementById('forgot-email').value.trim();
    if (!email) {
        return backToForgotStep1();
    }
    showToast('Sending new verification code...');
    const res = await api('send_reset_otp', { email });
    if (res && res.success) {
        const devNotice = document.getElementById('otp-dev-notice');
        if (res.dev_otp) {
            if (devNotice) {
                devNotice.style.display = 'block';
                document.getElementById('dev-otp-code').innerText = res.dev_otp;
            }
            showToast('New OTP generated: ' + res.dev_otp);
        } else {
            if (devNotice) devNotice.style.display = 'none';
            showToast('New verification code sent to your email inbox!');
        }
    } else {
        showToast(res.error || 'Failed to resend code', false);
    }
}

async function doResetPasswordWithOtp() {
    const email = currentResetEmail || document.getElementById('forgot-email').value.trim();
    const token = (document.getElementById('forgot-token-val')?.value || '').trim();
    const otp = (document.getElementById('forgot-otp')?.value || '').trim();
    const password = document.getElementById('forgot-password').value;
    const confirmPassword = document.getElementById('forgot-confirm-password').value;

    if (!token && !otp) {
        return showToast('Please enter the 6-digit OTP verification code', false);
    }
    if (!password || !confirmPassword) {
        return showToast('Please enter and confirm your new password', false);
    }
    if (password !== confirmPassword) {
        return showToast('Passwords do not match', false);
    }
    if (password.length < 4) {
        return showToast('Password must be at least 4 characters', false);
    }

    const btn = document.getElementById('btn-reset-pw');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Updating password...';

    const payload = {
        email,
        otp,
        token,
        password,
        confirm_password: confirmPassword
    };

    const res = await api('reset_password_with_otp', payload);
    btn.disabled = false;
    btn.innerHTML = oldText;

    if (res && res.success) {
        showToast(res.message || 'Password reset successfully! Please log in.');
        document.getElementById('forgot-email').value = '';
        if (document.getElementById('forgot-otp')) document.getElementById('forgot-otp').value = '';
        document.getElementById('forgot-password').value = '';
        document.getElementById('forgot-confirm-password').value = '';
        document.getElementById('forgot-token-val').value = '';
        backToForgotStep1();
        toggleAuth('login');
        document.getElementById('login-email').value = email;
        document.getElementById('login-password').value = '';
        setTimeout(() => {
            document.getElementById('login-password').focus();
        }, 150);
    } else {
        showToast(res.error || 'Failed to reset password', false);
    }
}

async function doLogout() {
    await api('logout');
    currentUser = null;
    cart = {};
    updateNav();
    updateFloatingCart();
    showToast('You have been logged out');
    showView('auth');
}

// ==========================================
// MENU & CART (CUSTOMER VIEW)
// ==========================================
async function loadMenu() {
    const res = await api('menu');
    if (res && res.items) {
        menuData = res.items;
        renderCategories();
        filterMenu();
    }
}

function renderCategories() {
    // Collect categories with item counts
    const counts = {};
    menuData.forEach(item => {
        const cat = item.category || 'Other';
        counts[cat] = (counts[cat] || 0) + 1;
    });

    const categoryEmojis = {
        'Coffee': '☕',
        'Tea': '🍵',
        'Cold drinks': '🥤',
        'Snacks': '🥪',
        'Desserts': '🍫',
        'Special': '⭐'
    };

    const cats = ['All', ...Object.keys(counts)];
    const container = document.getElementById('cats-container');
    container.innerHTML = cats.map(cat => {
        const emoji = categoryEmojis[cat] ? categoryEmojis[cat] + ' ' : '';
        const countStr = cat === 'All' ? `(${menuData.length})` : `(${counts[cat]})`;
        return `
            <button class="cat-pill ${cat === activeCategory ? 'active' : ''}" onclick="selectCategory('${cat}')">
                ${emoji}${cat} ${countStr}
            </button>
        `;
    }).join('');
}

function selectCategory(cat) {
    activeCategory = cat;
    renderCategories();
    filterMenu();
}

function filterMenu() {
    const query = (document.getElementById('search-input')?.value || '').toLowerCase().trim();
    const filtered = menuData.filter(item => {
        const matchesCat = (activeCategory === 'All' || item.category === activeCategory);
        const matchesQuery = item.name.toLowerCase().includes(query) || (item.description || '').toLowerCase().includes(query);
        return matchesCat && matchesQuery;
    });
    renderMenuGrid(filtered);
}

function renderMenuGrid(items) {
    const grid = document.getElementById('menu-grid');
    if (!items.length) {
        grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--mute);">No matching items found.</div>`;
        return;
    }

    grid.innerHTML = items.map(item => {
        const priceNum = parseFloat(item.price);
        const imageHtml = item.image_url
            ? `<img src="${item.image_url}" alt="${item.name}" onerror="this.style.display='none';this.parentElement.innerText='${item.emoji||'🍽️'}';">`
            : (item.emoji || '🍽️');

        const inCartQty = cart[item.id] ? cart[item.id].qty : 0;
        let actionBtnHtml = '';

        if (inCartQty > 0) {
            actionBtnHtml = `
                <div class="card-stepper">
                    <button onclick="changeQty(${item.id}, -1)">−</button>
                    <span>${inCartQty}</span>
                    <button onclick="changeQty(${item.id}, 1)">+</button>
                </div>
            `;
        } else {
            actionBtnHtml = `
                <button class="btn sm primary" onclick="addToCart(${item.id})">Add to Cart</button>
            `;
        }

        return `
            <div class="card">
                <div class="card-thumb">${imageHtml}</div>
                <h3>${item.name}</h3>
                <p>${item.description || ''}</p>
                <div class="price-row">
                    <span class="price">₹${priceNum.toFixed(0)}</span>
                    <div id="card-action-${item.id}">${actionBtnHtml}</div>
                </div>
            </div>
        `;
    }).join('');
}

function addToCart(id) {
    const item = menuData.find(i => i.id == id);
    if (!item) return;
    if (!cart[id]) cart[id] = { ...item, qty: 0 };
    cart[id].qty++;
    updateCartCounts();
    updateCardAction(id);
    showToast(`${item.name} added to cart`);
}

function changeQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) delete cart[id];
    updateCartCounts();
    updateCardAction(id);
    if (currentView === 'cart') renderCart();
}

function updateCardAction(id) {
    const container = document.getElementById(`card-action-${id}`);
    if (!container) return;
    const inCartQty = cart[id] ? cart[id].qty : 0;
    if (inCartQty > 0) {
        container.innerHTML = `
            <div class="card-stepper">
                <button onclick="changeQty(${id}, -1)">−</button>
                <span>${inCartQty}</span>
                <button onclick="changeQty(${id}, 1)">+</button>
            </div>
        `;
    } else {
        container.innerHTML = `
            <button class="btn sm primary" onclick="addToCart(${id})">Add to Cart</button>
        `;
    }
}

function updateCartCounts() {
    const count = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
    const navCount = document.getElementById('cart-nav-count');
    if (navCount) navCount.innerText = count;
    updateFloatingCart();
}

function updateFloatingCart() {
    const bar = document.getElementById('floating-cart-bar');
    if (!bar) return;
    const count = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
    if (count > 0 && currentView === 'home') {
        const total = Object.values(cart).reduce((sum, item) => sum + (item.price * item.qty), 0);
        document.getElementById('floating-cart-count').innerText = count;
        document.getElementById('floating-cart-total').innerText = total.toFixed(0);
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
}

function renderCart() {
    const list = document.getElementById('cart-items-list');
    const items = Object.values(cart);

    if (items.length === 0) {
        list.innerHTML = `<p style="color:var(--mute);padding:14px 0;">Your cart is empty.</p>`;
        document.getElementById('cart-total-price').innerText = '0';
        return;
    }

    list.innerHTML = items.map(item => `
        <div class="cart-item">
            <div>
                <strong>${item.name}</strong><br>
                <span style="font-size:13px;color:var(--mute);">₹${item.price} × ${item.qty}</span>
            </div>
            <div class="card-stepper">
                <button onclick="changeQty(${item.id}, -1)">−</button>
                <span>${item.qty}</span>
                <button onclick="changeQty(${item.id}, 1)">+</button>
            </div>
        </div>
    `).join('');

    const total = items.reduce((sum, item) => sum + (item.price * item.qty), 0);
    document.getElementById('cart-total-price').innerText = total.toFixed(0);
    const payTotalEl = document.getElementById('btn-pay-total');
    if (payTotalEl) payTotalEl.innerText = total.toFixed(0);
    selectPaymentMethod(selectedPaymentMethod);
}

let selectedPaymentMethod = 'razorpay';

function selectPaymentMethod(method) {
    selectedPaymentMethod = method;
    const rRadio = document.getElementById('pay-radio-razorpay');
    const cRadio = document.getElementById('pay-radio-cash');
    const rOpt = document.getElementById('pay-opt-razorpay');
    const cOpt = document.getElementById('pay-opt-cash');
    const btn = document.getElementById('btn-place-order');

    const items = Object.values(cart);
    const total = items.reduce((sum, item) => sum + (item.price * item.qty), 0);

    if (method === 'razorpay') {
        if (rRadio) rRadio.checked = true;
        if (cRadio) cRadio.checked = false;
        if (rOpt) { rOpt.style.borderColor = 'var(--pri)'; rOpt.style.borderWidth = '2px'; }
        if (cOpt) { cOpt.style.borderColor = 'var(--border)'; cOpt.style.borderWidth = '1px'; }
        if (btn) btn.innerHTML = `💳 Pay Online (₹${total.toFixed(0)}) via Razorpay`;
    } else {
        if (rRadio) rRadio.checked = false;
        if (cRadio) cRadio.checked = true;
        if (rOpt) { rOpt.style.borderColor = 'var(--border)'; rOpt.style.borderWidth = '1px'; }
        if (cOpt) { cOpt.style.borderColor = 'var(--pri)'; cOpt.style.borderWidth = '2px'; }
        if (btn) btn.innerHTML = `💵 Place Order (Pay ₹${total.toFixed(0)} at Counter)`;
    }
}

async function handleCheckout() {
    const items = Object.values(cart);
    if (items.length === 0) return showToast('Your cart is empty', false);

    const total = items.reduce((sum, item) => sum + (item.price * item.qty), 0);

    if (selectedPaymentMethod === 'cash') {
        const btn = document.getElementById('btn-place-order');
        btn.disabled = true;
        btn.innerHTML = '⏳ Placing order...';

        const res = await api('checkout', { cart: items, total });
        btn.disabled = false;
        renderCart();

        if (res && res.success) {
            showOrderSuccessModal(res.order_code, items, total, 'Cash at Counter', null, 'Pending');
            cart = {};
            updateCartCounts();
            filterMenu();
        } else {
            showToast(res?.error || 'Failed to place order', false);
        }
        return;
    }

    // Razorpay Online Payment Flow
    const btn = document.getElementById('btn-place-order');
    btn.disabled = true;
    btn.innerHTML = '⏳ Opening Razorpay...';

    const orderRes = await api('create_razorpay_order', { total });
    btn.disabled = false;
    renderCart();

    if (!orderRes || !orderRes.success) {
        return showToast(orderRes?.error || 'Failed to initialize Razorpay payment', false);
    }

    const options = {
        key: orderRes.key,
        amount: orderRes.amount,
        currency: orderRes.currency || 'INR',
        name: 'Encave Café',
        description: 'Order Payment',
        image: 'encave.jpg',
        order_id: orderRes.razorpay_order_id,
        prefill: {
            name: currentUser ? currentUser.name : '',
            email: currentUser ? currentUser.email : '',
            contact: currentUser ? (currentUser.phone || '') : ''
        },
        notes: {
            café: 'Encave Café'
        },
        theme: {
            color: '#10132B'
        },
        handler: async function (response) {
            showToast('Payment successful! Finalizing order...');
            const verifyRes = await api('verify_razorpay_payment', {
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature,
                cart: items,
                total: total
            });

            if (verifyRes && verifyRes.success) {
                showOrderSuccessModal(
                    verifyRes.order_code,
                    items,
                    total,
                    'Razorpay (Online)',
                    response.razorpay_payment_id,
                    'Paid'
                );
                cart = {};
                updateCartCounts();
                filterMenu();
            } else {
                showToast(verifyRes?.error || 'Payment recorded with gateway, verifying...', false);
            }
        },
        modal: {
            ondismiss: function () {
                showToast('Payment window cancelled');
            }
        }
    };

    try {
        const rzp = new Razorpay(options);
        rzp.on('payment.failed', function (response) {
            showToast('Payment Failed: ' + (response.error.description || 'Unknown error'), false);
        });
        rzp.open();
    } catch (err) {
        showToast('Razorpay failed to open: ' + err.message, false);
    }
}

// Fallback alias for direct checkout call
async function checkout() {
    return handleCheckout();
}

function showOrderSuccessModal(code, items, total, paymentMethod = 'Cash at Counter', paymentId = null, paymentStatus = 'Pending') {
    document.getElementById('modal-order-code').innerText = code;
    document.getElementById('modal-order-total').innerText = total.toFixed(0);

    const iconEl = document.getElementById('modal-icon');
    const titleEl = document.getElementById('modal-title');
    const subtitleEl = document.getElementById('modal-subtitle');
    const paymentPill = document.getElementById('modal-payment-pill');

    const step1 = document.getElementById('modal-step-1');
    const step1Circle = document.getElementById('modal-step-1-circle');
    const step1Label = document.getElementById('modal-step-1-label');

    const step2 = document.getElementById('modal-step-2');
    const step2Circle = document.getElementById('modal-step-2-circle');
    const step2Label = document.getElementById('modal-step-2-label');

    const step3 = document.getElementById('modal-step-3');
    const step3Circle = document.getElementById('modal-step-3-circle');
    const step3Label = document.getElementById('modal-step-3-label');

    const isOnline = (paymentMethod || '').includes('Razorpay');

    if (isOnline) {
        if (iconEl) iconEl.innerText = '🎉';
        if (titleEl) {
            titleEl.innerHTML = 'Order Placed &amp; Paid!';
            titleEl.style.color = 'var(--ink)';
        }
        if (subtitleEl) subtitleEl.innerText = 'Your payment was successful and the kitchen has started preparing your order.';

        // Step 1: Placed (Done)
        step1.classList.add('active');
        step1Circle.innerText = '✓';
        step1Circle.style.background = '';
        step1Circle.style.color = '';
        step1Circle.style.borderColor = '';
        step1Label.innerText = 'Paid Online';

        // Step 2: Preparing (Active)
        step2.classList.add('active');
        step2Circle.innerText = '☕';
        step2Circle.style.background = '';
        step2Circle.style.color = '';
        step2Circle.style.borderColor = '';
        step2Label.innerText = 'Preparing';

        // Step 3: Ready
        step3.classList.remove('active');
        step3Circle.innerText = '🔔';
        step3Label.innerText = 'Ready';

        if (paymentPill) {
            paymentPill.innerHTML = `
                <span style="display:inline-block;padding:6px 16px;background:#d1fae5;color:#065f46;border-radius:20px;font-size:13px;font-weight:800;">
                    ✓ Paid Online via Razorpay (${paymentId || 'Success'})
                </span>
            `;
        }
    } else {
        // CASH AT COUNTER (Waiting Payment Confirmation)
        if (iconEl) iconEl.innerText = '⏳';
        if (titleEl) {
            titleEl.innerHTML = '<span style="color:#c2410c;">Order Waiting for Cash Payment</span>';
        }
        if (subtitleEl) {
            subtitleEl.innerHTML = 'Your order is pending. Please pay cash at the counter to start preparation.';
        }

        // Step 1: Awaiting Cash (Active warning)
        step1.classList.add('active');
        step1Circle.innerText = '💵';
        step1Circle.style.background = '#fed7aa';
        step1Circle.style.color = '#9a3412';
        step1Circle.style.borderColor = '#ea580c';
        step1Label.innerHTML = '<strong>Awaiting Cash</strong>';

        // Step 2: Preparing (NOT active yet because cash is not collected!)
        step2.classList.remove('active');
        step2Circle.innerText = '☕';
        step2Circle.style.background = 'var(--tag-bg)';
        step2Circle.style.color = 'var(--mute)';
        step2Circle.style.borderColor = 'var(--mute)';
        step2Label.innerText = 'Waiting Payment';

        // Step 3: Ready
        step3.classList.remove('active');
        step3Circle.innerText = '🔔';
        step3Label.innerText = 'Ready';

        if (paymentPill) {
            paymentPill.innerHTML = `
                <div style="background:#fff7ed;border:1.5px dashed #f97316;padding:12px;border-radius:12px;margin-top:8px;text-align:center;">
                    <div style="font-size:15px;font-weight:800;color:#c2410c;">
                        💵 Cash to Pay at Counter: ₹${total.toFixed(0)}
                    </div>
                    <div style="font-size:12px;color:#9a3412;margin-top:4px;font-weight:600;line-height:1.4;">
                        Please pay ₹${total.toFixed(0)} cash to the cashier at the counter.<br>
                        Once cash is collected by the admin, your order will be prepared!
                    </div>
                </div>
            `;
        }
    }

    document.getElementById('modal-order-items').innerHTML = items.map(i => `
        <div style="display:flex;justify-content:space-between;padding:2px 0;">
            <span>${i.name} × ${i.qty}</span>
            <span>₹${(i.price * i.qty).toFixed(0)}</span>
        </div>
    `).join('');
    document.getElementById('order-success-modal').style.display = 'flex';
}

function closeSuccessModal() {
    document.getElementById('order-success-modal').style.display = 'none';
    showView('home');
}

function closeSuccessModalAndTrack() {
    document.getElementById('order-success-modal').style.display = 'none';
    showView('my-orders');
}

function copyOrderCode() {
    const code = document.getElementById('modal-order-code').innerText;
    navigator.clipboard.writeText(code).then(() => {
        showToast('Order code copied: ' + code);
    }).catch(() => {
        showToast(code);
    });
}

// ==========================================
// CUSTOMER: MY ORDERS VIEW
// ==========================================
async function loadMyOrders() {
    const res = await api('my_orders');
    const container = document.getElementById('my-orders-list');
    if (!res || !res.orders || !res.orders.length) {
        container.innerHTML = `
            <div class="card" style="padding:40px;text-align:center;color:var(--mute);">
                <div style="font-size:44px;margin-bottom:10px;">☕</div>
                <h3>No orders yet</h3>
                <p style="margin-top:4px;">You haven't placed any orders yet. Check out the menu and treat yourself!</p>
                <button class="btn primary sm" style="margin-top:16px;" onclick="showView('home')">Browse Menu</button>
            </div>
        `;
        return;
    }

    container.innerHTML = res.orders.map(o => {
        let items = [];
        try { items = JSON.parse(o.items_json); } catch(e){}
        const itemsList = items.map(i => `• ${i.name} × ${i.qty}`).join('<br>');

        const isOnline = (o.payment_method || '').includes('Razorpay');
        let payBadge = '';
        if (isOnline) {
            payBadge = `<span style="font-size:11px;padding:3px 8px;border-radius:6px;background:#d1fae5;color:#065f46;font-weight:700;">💳 Paid Online (Razorpay)</span>`;
        } else if (o.payment_status === 'Paid (Cash)') {
            payBadge = `<span style="font-size:11px;padding:3px 8px;border-radius:6px;background:#d1fae5;color:#065f46;font-weight:700;">✓ Cash Paid at Counter</span>`;
        } else if (o.status === 'Cancelled' || o.payment_status === 'Not Collected (Cancelled)') {
            payBadge = `<span style="font-size:11px;padding:3px 8px;border-radius:6px;background:#fecdd3;color:#9f1239;font-weight:700;">✕ Cash Not Received</span>`;
        } else {
            payBadge = `<span style="font-size:11px;padding:3px 8px;border-radius:6px;background:#fed7aa;color:#9a3412;font-weight:700;">⏳ Pay ₹${parseFloat(o.total).toFixed(0)} at Counter</span>`;
        }
        const safeStatus = (o.status || 'Pending').replace(/\s+/g, '-');

        return `
            <div class="card" style="margin-bottom:14px;padding:20px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <div>
                        <span style="font-family:var(--font-title);font-size:20px;font-weight:800;color:var(--pri);">${o.order_code}</span>
                        <span style="font-size:12px;color:var(--mute);margin-left:10px;">${o.created_at}</span>
                        <div style="margin-top:4px;">${payBadge}</div>
                    </div>
                    <span class="status-pill status-${safeStatus}">${o.status}</span>
                </div>
                <div style="margin:12px 0;font-size:14px;line-height:1.5;">${itemsList}</div>
                <div style="border-top:1px dashed color-mix(in srgb, var(--ink) 20%, transparent);padding-top:10px;display:flex;justify-content:space-between;font-weight:800;">
                    <span>Total Amount</span>
                    <span style="font-size:18px;">₹${parseFloat(o.total).toFixed(0)}</span>
                </div>
            </div>
        `;
    }).join('');
}

// ==========================================
// ADMIN DASHBOARD FUNCTIONS
// ==========================================
function showAdminTab(name, el) {
    document.querySelectorAll('.admin-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`admin-${name}`).classList.add('active');
    if (el) el.classList.add('active');

    if (name === 'orders') loadAdminOrders();
    if (name === 'products') loadAdminProducts();
}

async function loadAdminOrders() {
    const res = await api('admin_orders');
    if (!res || !res.orders) return;
    adminOrdersData = res.orders;

    // Update KPI Stats
    document.getElementById('kpi-total-orders').innerText = adminOrdersData.length;
    const activeCount = adminOrdersData.filter(o => o.status === 'Pending' || o.status === 'Preparing').length;
    document.getElementById('kpi-active-orders').innerText = activeCount;
    const revenue = adminOrdersData
        .filter(o => o.status !== 'Cancelled')
        .reduce((sum, o) => sum + parseFloat(o.total || 0), 0);
    document.getElementById('kpi-revenue').innerText = '₹' + revenue.toFixed(0);

    filterAdminOrdersList();
}

function filterAdminOrders(status, btn) {
    adminActiveFilter = status;
    document.querySelectorAll('#admin-order-filters .cat-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterAdminOrdersList();
}

function filterAdminOrdersList() {
    const query = (document.getElementById('admin-order-search')?.value || '').toLowerCase().trim();
    const filtered = adminOrdersData.filter(o => {
        const matchesStatus = (adminActiveFilter === 'All' || o.status === adminActiveFilter);
        const matchesQuery = o.order_code.toLowerCase().includes(query) || (o.guest_name || '').toLowerCase().includes(query);
        return matchesStatus && matchesQuery;
    });

    const tbody = document.querySelector('#orders-table tbody');
    if (!filtered.length) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--mute);padding:24px;">No matching orders found.</td></tr>`;
        return;
    }

    const statuses = ['Pending', 'Awaiting Cash', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
    tbody.innerHTML = filtered.map(o => {
        let items = [];
        try { items = JSON.parse(o.items_json); } catch (e) {}
        const itemsStr = items.map(i => `• ${i.name} × ${i.qty}`).join('<br>');

        const isOnline = (o.payment_method || '').includes('Razorpay');
        let payCol = '';

        if (isOnline) {
            payCol = `<span class="status-pill" style="background:#d1fae5;color:#065f46;font-size:11px;">💳 Paid (Razorpay)</span><br><small style="font-family:monospace;font-size:10px;color:var(--mute);">${o.payment_id || ''}</small>`;
        } else {
            if (o.payment_status === 'Paid (Cash)') {
                payCol = `<span class="status-pill" style="background:#d1fae5;color:#065f46;font-size:11px;">✓ Cash Collected</span>`;
            } else if (o.status === 'Cancelled' || o.payment_status === 'Not Collected (Cancelled)') {
                payCol = `<span class="status-pill" style="background:#fecdd3;color:#9f1239;font-size:11px;">✕ Cash Not Paid</span>`;
            } else {
                payCol = `
                    <span class="status-pill" style="background:#fed7aa;color:#9a3412;font-size:11px;font-weight:800;">
                        ⏳ Awaiting ₹${parseFloat(o.total).toFixed(0)}
                    </span>
                    <div style="display:flex;gap:4px;margin-top:6px;">
                        <button class="btn sm primary" onclick="adminConfirmCash(${o.id}, '${o.order_code}', ${o.total})" 
                                style="padding:4px 8px;font-size:11px;background:#10b981;border-color:#059669;" title="Cash collected - Send to Kitchen">
                            ✓ Collect Cash
                        </button>
                        <button class="btn sm danger" onclick="adminRejectCash(${o.id}, '${o.order_code}')" 
                                style="padding:4px 8px;font-size:11px;" title="Customer did not pay cash">
                            ✕ Reject
                        </button>
                    </div>
                `;
            }
        }

        const safeStatus = (o.status || 'Pending').replace(/\s+/g, '-');

        return `
            <tr>
                <td><strong>${o.order_code}</strong></td>
                <td>${o.guest_name || 'Customer'}</td>
                <td>${payCol}</td>
                <td style="font-size:13px;line-height:1.4;">${itemsStr}</td>
                <td><strong>₹${parseFloat(o.total).toFixed(0)}</strong></td>
                <td><span class="status-pill status-${safeStatus}">${o.status}</span></td>
                <td style="font-size:12px;color:var(--mute);">${o.created_at}</td>
                <td>
                    <select onchange="updateOrderStatus(${o.id}, this.value)" style="padding:6px 10px;border:var(--border);border-radius:8px;font-weight:600;background:var(--card);color:var(--ink);">
                        ${statuses.map(s => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${s}</option>`).join('')}
                    </select>
                </td>
            </tr>
        `;
    }).join('');
}

async function adminConfirmCash(id, code, total) {
    if (!confirm(`Confirm cash payment of ₹${total} collected for Order ${code}?\n\nThis will mark the order as Paid and send it to the kitchen for preparation.`)) {
        return;
    }
    const res = await api('admin_confirm_cash', { id });
    if (res && res.success) {
        showToast(`Cash collected for ${code}! Order sent to kitchen.`);
        loadAdminOrders();
    } else {
        showToast(res?.error || 'Failed to confirm cash collection', false);
    }
}

async function adminRejectCash(id, code) {
    if (!confirm(`Cash was not collected for Order ${code}?\n\nThis will cancel the order.`)) {
        return;
    }
    const res = await api('admin_reject_cash', { id });
    if (res && res.success) {
        showToast(`Order ${code} cancelled (Cash not collected).`);
        loadAdminOrders();
    } else {
        showToast(res?.error || 'Failed to reject order', false);
    }
}

async function updateOrderStatus(id, status) {
    const res = await api('admin_order_status', { id, status });
    if (res && res.success) {
        showToast('Order status updated to ' + status);
        loadAdminOrders();
    }
}

async function loadAdminProducts() {
    const res = await api('menu');
    if (!res || !res.items) return;
    menuData = res.items;

    document.getElementById('kpi-menu-count').innerText = menuData.length;

    const tbody = document.querySelector('#products-table tbody');
    tbody.innerHTML = res.items.map(item => {
        const thumb = item.image_url
            ? `<img src="${item.image_url}" style="width:52px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #10132B;">`
            : `<span style="font-size:28px;">${item.emoji || '🍽️'}</span>`;

        return `
            <tr>
                <td><strong>${item.id}</strong></td>
                <td>${thumb}</td>
                <td>
                    <strong>${item.name}</strong><br>
                    <small style="color:var(--mute);">${item.description || ''}</small>
                </td>
                <td><span style="padding:4px 8px;background:var(--tag-bg);border-radius:6px;font-size:12px;font-weight:700;">${item.category}</span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span>₹</span>
                        <input type="number" step="1" value="${parseFloat(item.price)}" 
                               style="width:85px;padding:6px;border:var(--border);border-radius:6px;font-weight:700;background:var(--card);color:var(--ink);" 
                               onchange="updateProductField(${item.id}, 'price', parseFloat(this.value))">
                    </div>
                </td>
                <td>
                    <input type="number" value="${item.stock || 50}" 
                           style="width:70px;padding:6px;border:var(--border);border-radius:6px;font-weight:700;background:var(--card);color:var(--ink);" 
                           onchange="updateProductField(${item.id}, 'stock', parseInt(this.value))">
                </td>
                <td style="white-space:nowrap;">
                    <button class="btn sm ghost" title="Edit Details & Photo" onclick="openEditModal(${item.id})">✏️ Edit</button>
                    <button class="btn sm danger" title="Delete Product" onclick="deleteProduct(${item.id})">🗑️</button>
                </td>
            </tr>
        `;
    }).join('');
}

async function updateProductField(id, field, value) {
    const payload = { id };
    payload[field] = value;
    const res = await api('admin_update_product', payload);
    if (res && res.success) {
        showToast('Updated successfully!');
        loadMenu();
    }
}

function openEditModal(id) {
    const item = menuData.find(i => i.id == id);
    if (!item) return;

    document.getElementById('edit-id').value = item.id;
    document.getElementById('edit-name').value = item.name;
    document.getElementById('edit-desc').value = item.description || '';
    document.getElementById('edit-price').value = item.price;
    document.getElementById('edit-category').value = item.category || 'Coffee';
    document.getElementById('edit-emoji').value = item.emoji || '☕';
    document.getElementById('edit-stock').value = item.stock || 50;
    document.getElementById('edit-image-val').value = item.image_url || '';

    const preview = document.getElementById('edit-preview-img');
    if (item.image_url) {
        preview.src = item.image_url;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }

    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

async function saveEditProduct() {
    const id = parseInt(document.getElementById('edit-id').value);
    const data = {
        id,
        name: document.getElementById('edit-name').value.trim(),
        description: document.getElementById('edit-desc').value.trim(),
        price: parseFloat(document.getElementById('edit-price').value),
        category: document.getElementById('edit-category').value.trim() || 'Misc',
        emoji: document.getElementById('edit-emoji').value.trim() || '🍽️',
        stock: parseInt(document.getElementById('edit-stock').value) || 50,
        image_url: document.getElementById('edit-image-val').value.trim()
    };

    if (!data.name || isNaN(data.price)) {
        return showToast('Product name and valid price are required', false);
    }

    const res = await api('admin_update_product', data);
    if (res && res.success) {
        showToast('Product updated successfully!');
        closeEditModal();
        loadMenu();
        loadAdminProducts();
    }
}

async function deleteProduct(id) {
    if (!confirm('Are you sure you want to remove this product from the menu?')) return;
    const res = await api('admin_delete_product', { id });
    if (res && res.success) {
        showToast('Product deleted from menu');
        loadMenu();
        loadAdminProducts();
    }
}

async function addProduct() {
    const data = {
        name: document.getElementById('np-name').value.trim(),
        description: document.getElementById('np-desc').value.trim(),
        price: parseFloat(document.getElementById('np-price').value),
        category: document.getElementById('np-category').value || 'Coffee',
        emoji: document.getElementById('np-emoji').value.trim() || '☕',
        stock: parseInt(document.getElementById('np-stock').value) || 50,
        image_url: document.getElementById('np-image-val').value.trim()
    };

    if (!data.name || isNaN(data.price) || data.price <= 0) {
        return showToast('Please enter a valid product name and price', false);
    }

    const res = await api('admin_add_product', data);
    if (res && res.success) {
        showToast('Product added to menu successfully!');
        ['np-name', 'np-desc', 'np-price', 'np-image-val'].forEach(id => {
            document.getElementById(id).value = '';
        });
        document.getElementById('np-stock').value = '50';
        document.getElementById('np-image-preview').style.display = 'none';

        loadMenu();
        loadAdminProducts();
        showAdminTab('products', document.querySelectorAll('.admin-tab')[1]);
    }
}

async function handleProductUpload(input, previewBoxId, targetInputId) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('image', file);

    try {
        showToast('Uploading image...');
        const res = await fetch('?upload=1', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (json.success && json.url) {
            document.getElementById(targetInputId).value = json.url;
            const previewBox = document.getElementById(previewBoxId);
            if (previewBox) {
                previewBox.style.display = 'block';
                const img = previewBox.querySelector('img') || previewBox;
                img.src = json.url;
                img.style.display = 'block';
            }
            showToast('Image uploaded successfully!');
        } else {
            showToast(json.error || 'Failed to upload image', false);
        }
    } catch (err) {
        showToast('Upload error: ' + err.message, false);
    }
}

// ==========================================
// INITIALIZATION
// ==========================================
async function init() {
    initTheme();

    // Check if user clicked a direct password reset link from email (e.g. ?token=XYZ)
    const urlParams = new URLSearchParams(window.location.search);
    const resetToken = urlParams.get('token');
    const forgotSentEmail = urlParams.get('forgot_sent');

    if (resetToken) {
        showView('auth');
        toggleAuth('forgot');
        document.getElementById('forgot-token-val').value = resetToken;
        
        // Verify token validity with server
        api('verify_reset_token', { token: resetToken }).then(res => {
            if (res && res.success) {
                currentResetEmail = res.email;
                document.getElementById('forgot-step-1').style.display = 'none';
                document.getElementById('forgot-step-2').style.display = 'block';
                document.getElementById('forgot-sent-email').innerText = res.email || 'Verified Account';
                document.getElementById('forgot-otp-group').style.display = 'none'; // direct link doesn't require typing OTP
                document.getElementById('forgot-resend-row').style.display = 'none';
                showToast('Reset token verified! Please set your new password.');
                setTimeout(() => {
                    const pwInput = document.getElementById('forgot-password');
                    if (pwInput) pwInput.focus();
                }, 150);
            } else {
                showToast(res.error || 'This reset link has expired or is invalid. Please request a new code.', false);
                backToForgotStep1();
            }
        });
        return;
    } else if (forgotSentEmail) {
        showView('auth');
        toggleAuth('forgot');
        document.getElementById('forgot-email').value = forgotSentEmail;
        requestResetOtp();
    }

    const res = await api('me');
    if (res && res.user) {
        currentUser = res.user;
        updateNav();
        showView(currentUser.is_admin == 1 ? 'admin' : 'home');
    } else {
        currentUser = null;
        updateNav();
        showView('auth');
    }
}

init();
</script>
</body>
</html>