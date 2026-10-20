<?php
// ==========================================
// 1. DATABASE CONNECTION & SELF-HEALING AUTO-INSTALL
// ==========================================
require_once 'config.php';

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
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Self-heal: add missing columns in users if any
    $userCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_admin', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) DEFAULT 0");
    }
    if (!in_array('phone', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NOT NULL DEFAULT ''");
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
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

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

    // ---------- AUTH: FORGOT PASSWORD ----------
    if ($action === 'forgot_password') {
        $email = strtolower(trim($input['email'] ?? ''));
        $pass = (string)($input['password'] ?? '');
        $confirmPass = (string)($input['confirm_password'] ?? '');

        if (!$email || !$pass || !$confirmPass) {
            echo json_encode(['error' => 'Please fill in all fields']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Please enter a valid email address']);
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

        $stmt = $pdo->prepare("SELECT id FROM `users` WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['error' => 'No account found with this email address']);
            exit;
        }

        $newHash = password_hash($pass, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE `users` SET `password` = ? WHERE `id` = ?");
        if ($update->execute([$newHash, $user['id']])) {
            echo json_encode(['success' => true, 'message' => 'Password updated successfully! Please log in.']);
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

    // ---------- CHECKOUT (Logged-in users) ----------
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
        $stmt = $pdo->prepare("INSERT INTO `orders` (`order_code`, `user_id`, `guest_name`, `items_json`, `total`, `status`) VALUES (?, ?, ?, ?, ?, 'Pending')");
        if ($stmt->execute([$code, $userId, $guestName, json_encode($cart), $total])) {
            echo json_encode(['success' => true, 'order_code' => $code]);
        } else {
            echo json_encode(['error' => 'Failed to place order']);
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
        $allowed = ['Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Encave Café</title>
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
        --font-main: 'DM Sans', sans-serif;
        --font-title: 'Bricolage Grotesque', sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background-color: var(--bg);
        color: var(--ink);
        font-family: var(--font-main);
        background-image: radial-gradient(#D3D8F5 1.5px, transparent 1.5px);
        background-size: 24px 24px;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    h1, h2, h3 { font-family: var(--font-title); }

    /* NAVBAR */
    header {
        background: var(--card);
        border-bottom: var(--border);
        padding: 14px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 100;
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
    nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .btn {
        background: var(--accent);
        color: var(--ink);
        border: var(--border);
        padding: 9px 18px;
        border-radius: 12px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        box-shadow: var(--shadow-sm);
        transition: 0.1s;
        font-size: 15px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn:hover { filter: brightness(1.05); }
    .btn:active { transform: translate(2px, 2px); box-shadow: none; }
    .btn.ghost { background: var(--card); }
    .btn.primary { background: var(--pri); color: #fff; }
    .btn.danger { background: var(--danger); color: #fff; }
    .btn.sm { padding: 5px 12px; font-size: 13px; border-radius: 8px; }

    main { max-width: 1100px; margin: 30px auto; padding: 0 20px; width: 100%; flex-grow: 1; }
    .view { display: none; }
    .view.active { display: block; animation: fadeIn 0.25s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

    /* AUTH VIEW */
    .auth-box {
        background: var(--card);
        border: var(--border);
        border-radius: 20px;
        padding: 34px;
        max-width: 440px;
        margin: 40px auto;
        box-shadow: var(--shadow);
    }
    .auth-box h2 { margin-bottom: 20px; text-align: center; font-size: 28px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 700; font-size: 14px; }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px 14px;
        border: var(--border);
        border-radius: 10px;
        font-family: var(--font-main);
        font-size: 15px;
        outline: none;
        background: #FAFAFF;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        background: #FFF;
        box-shadow: 2px 2px 0 var(--pri);
    }
    .auth-toggle { text-align: center; margin-top: 16px; font-size: 14px; }
    .auth-toggle a { color: var(--pri); font-weight: 700; cursor: pointer; text-decoration: underline; }
    .auth-tip {
        margin-top: 18px;
        font-size: 13px;
        color: var(--mute);
        background: #EEF2FF;
        border: 1px dashed var(--pri);
        border-radius: 10px;
        padding: 12px;
        line-height: 1.5;
    }

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
        padding: 10px 16px;
        border: var(--border);
        border-radius: 12px;
        font-family: var(--font-main);
        font-size: 15px;
        box-shadow: var(--shadow-sm);
        outline: none;
    }
    .cats-list { display: flex; gap: 8px; flex-wrap: wrap; }
    .cat-pill {
        padding: 6px 14px;
        border: var(--border);
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        background: var(--card);
        cursor: pointer;
        box-shadow: 2px 2px 0 #10132B;
        transition: 0.1s;
    }
    .cat-pill.active { background: var(--pri); color: #fff; }

    /* MENU GRID */
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 22px; }
    .card {
        background: var(--card);
        border: var(--border);
        border-radius: 18px;
        padding: 16px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: transform 0.15s;
    }
    .card:hover { transform: translateY(-2px); }
    .card-thumb {
        width: 100%;
        height: 130px;
        border-radius: 12px;
        border: var(--border);
        background: #F0F3FF;
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
    .card h3 { font-size: 19px; }
    .card p { color: var(--mute); font-size: 13px; flex-grow: 1; line-height: 1.4; }
    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        border-top: 1px dashed #DADEF3;
        padding-top: 10px;
    }
    .price { font-size: 20px; font-weight: 800; font-family: var(--font-title); }

    /* CART */
    .cart-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
    .cart-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px dashed #ccc;
        gap: 12px;
    }
    .cart-total {
        font-size: 22px;
        font-weight: 800;
        text-align: right;
        margin: 20px 0 10px;
        border-top: 2px solid #10132B;
        padding-top: 14px;
        font-family: var(--font-title);
    }

    /* ADMIN TABS & TABLES */
    .admin-nav { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
    .admin-tab {
        padding: 10px 20px;
        border: var(--border);
        border-radius: 12px;
        background: var(--card);
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
        border-bottom: 1px solid #E5E7EB;
        font-size: 14px;
        vertical-align: middle;
    }
    th {
        background: #EEF2FF;
        font-weight: 800;
        font-family: var(--font-title);
        border-bottom: var(--border);
    }
    tr:hover { background: #F9FAFF; }
    .status-pill {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid #10132B;
        display: inline-block;
    }
    .status-Pending { background: #FEF08A; color: #854D0E; }
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
        background: rgba(16, 19, 43, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 500;
        padding: 20px;
    }
    .modal-card {
        background: var(--card);
        border: var(--border);
        border-radius: 20px;
        padding: 26px;
        max-width: 520px;
        width: 100%;
        box-shadow: var(--shadow);
        max-height: 90vh;
        overflow-y: auto;
    }

    @media (max-width: 768px) {
        .cart-grid { grid-template-columns: 1fr; }
        header { padding: 12px 16px; }
        .logo { font-size: 18px; }
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
                <input type="password" id="login-password" placeholder="Enter your password">
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
                <input type="password" id="signup-password" placeholder="Password">
            </div>
            <button class="btn primary" style="width:100%;justify-content:center;padding:12px;" onclick="doSignup()">Create Account</button>
            <div class="auth-toggle">
                Already have an account? <a onclick="toggleAuth('login')">Log in</a>
            </div>
        </div>

        <div class="auth-box" id="forgot-box" style="display:none;">
            <h2>Reset Password</h2>
            <p style="color:var(--mute);text-align:center;font-size:14px;margin-bottom:18px;">Enter your registered email and choose a new password.</p>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" id="forgot-email" placeholder="Enter your registered email">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" id="forgot-password" placeholder="New password (min 4 characters)">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" id="forgot-confirm-password" placeholder="Confirm new password">
            </div>
            <button class="btn primary" style="width:100%;justify-content:center;padding:12px;" onclick="doForgotPassword()">Reset Password</button>
            <div class="auth-toggle">
                Remembered your password? <a onclick="toggleAuth('login')">Back to Log in</a>
            </div>
        </div>
    </div>

    <!-- 2. HOME VIEW (Menu - only for logged in users) -->
    <div id="view-home" class="view">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <h2 style="font-size:32px;">Fresh Brews & Delicious Bites</h2>
            <button class="btn" onclick="showView('cart')">🛒 View Cart (<span id="cart-btn-count">0</span>)</button>
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
                <h2 style="margin-bottom:18px;">Your Order</h2>
                <div id="cart-items-list"></div>
                <div class="cart-total">Total: ₹<span id="cart-total-price">0</span></div>
            </div>

            <div class="card" style="padding:24px;">
                <h2 style="margin-bottom:16px;">Checkout Summary</h2>
                <p style="color:var(--mute);margin-bottom:16px;font-size:14px;">Orders are prepared fresh at the counter.</p>
                <div class="form-group">
                    <label>Pickup Name</label>
                    <input type="text" id="checkout-name" readonly style="background:#F0F2F9;">
                </div>
                <button class="btn primary" style="width:100%;padding:14px;font-size:16px;justify-content:center;" onclick="checkout()">Place Order</button>
                <button class="btn ghost" style="width:100%;margin-top:10px;justify-content:center;" onclick="showView('home')">← Continue Ordering</button>
            </div>
        </div>
    </div>

    <!-- 4. ADMIN VIEW (Admin user only) -->
    <div id="view-admin" class="view">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <h2>⚙️ Café Admin Dashboard</h2>
            <span style="font-weight:700;color:var(--mute);">Logged in as Administrator</span>
        </div>

        <div class="admin-nav">
            <button class="admin-tab active" onclick="showAdminTab('orders', this)">📋 Incoming Orders</button>
            <button class="admin-tab" onclick="showAdminTab('products', this)">🍽️ Manage Menu & Photos</button>
            <button class="admin-tab" onclick="showAdminTab('add', this)">➕ Add New Product</button>
        </div>

        <!-- Admin Tab: Orders -->
        <div id="admin-orders" class="admin-section active">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3>Customer Orders</h3>
                <button class="btn sm ghost" onclick="loadAdminOrders()">🔄 Refresh Orders</button>
            </div>
            <div style="overflow-x:auto;">
                <table id="orders-table">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Items Ordered</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Ordered Time</th>
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
                <button class="btn sm" onclick="showAdminTab('add', document.querySelectorAll('.admin-tab')[2])">➕ Add Product</button>
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
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="file" id="np-file" accept="image/*" onchange="handleProductUpload(this, 'np-image-preview', 'np-image-val')" style="padding:8px;">
                    </div>
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
// NAVIGATION & ACCESS CONTROL
// ==========================================
function updateNav() {
    const nav = document.getElementById('nav');

    if (currentUser) {
        // User IS logged in: show Menu, Cart, Admin (if admin), User Name, Logout
        const count = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
        let adminHtml = '';
        if (currentUser.is_admin == 1) {
            adminHtml = `<button class="btn ghost sm" onclick="showView('admin')">⚙️ Admin Dashboard</button>`;
        }
        nav.innerHTML = `
            <button class="btn ghost sm" onclick="showView('home')">🍽️ Menu</button>
            <button class="btn ghost sm" onclick="showView('cart')">🛒 Cart (<span id="cart-nav-count">${count}</span>)</button>
            ${adminHtml}
            <span style="font-weight:700;font-size:14px;padding:6px 10px;background:#EEF2FF;border-radius:10px;border:var(--border);">
                ${currentUser.is_admin ? '👑' : '👤'} ${currentUser.name.split(' ')[0]}
            </span>
            <button class="btn sm danger" onclick="doLogout()">Logout</button>
        `;
    } else {
        // User is NOT logged in: strictly show Login & Sign Up
        nav.innerHTML = `
            <button class="btn sm ghost" onclick="showLogin()">Log In</button>
            <button class="btn sm primary" onclick="showSignup()">Sign Up</button>
        `;
    }
}

function showView(viewName) {
    // ENFORCE ACCESS CONTROL:
    // If user is not logged in, they can ONLY see 'auth' view!
    if (!currentUser && viewName !== 'auth') {
        showToast('Please log in to view the menu', false);
        viewName = 'auth';
    }

    // Admin view check:
    if (viewName === 'admin' && (!currentUser || currentUser.is_admin != 1)) {
        showToast('Admin authorization required', false);
        viewName = 'home';
    }

    currentView = viewName;
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    const target = document.getElementById(`view-${viewName}`);
    if (target) target.classList.add('active');

    if (viewName === 'home') {
        loadMenu();
    } else if (viewName === 'cart') {
        renderCart();
        if (currentUser) {
            document.getElementById('checkout-name').value = currentUser.name;
        }
    } else if (viewName === 'admin') {
        loadAdminOrders();
        loadAdminProducts();
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

    // Clear password fields on toggle
    document.getElementById('login-password').value = '';
    document.getElementById('signup-password').value = '';
    document.getElementById('forgot-password').value = '';
    document.getElementById('forgot-confirm-password').value = '';
}

async function doForgotPassword() {
    const email = document.getElementById('forgot-email').value.trim();
    const password = document.getElementById('forgot-password').value;
    const confirmPassword = document.getElementById('forgot-confirm-password').value;

    if (!email || !password || !confirmPassword) {
        return showToast('Please fill out all fields', false);
    }
    if (password !== confirmPassword) {
        return showToast('Passwords do not match', false);
    }
    if (password.length < 4) {
        return showToast('Password must be at least 4 characters', false);
    }

    const res = await api('forgot_password', { email, password, confirm_password: confirmPassword });
    if (res && res.success) {
        showToast(res.message || 'Password reset successfully! Please log in.');
        document.getElementById('forgot-email').value = '';
        document.getElementById('forgot-password').value = '';
        document.getElementById('forgot-confirm-password').value = '';
        toggleAuth('login');
        document.getElementById('login-email').value = email;
        document.getElementById('login-password').value = '';
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

async function doLogout() {
    await api('logout');
    currentUser = null;
    cart = {};
    updateNav();
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
    const cats = ['All', ...new Set(menuData.map(i => i.category || 'Other'))];
    const container = document.getElementById('cats-container');
    container.innerHTML = cats.map(cat => `
        <button class="cat-pill ${cat === activeCategory ? 'active' : ''}" onclick="selectCategory('${cat}')">
            ${cat}
        </button>
    `).join('');
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

        return `
            <div class="card">
                <div class="card-thumb">${imageHtml}</div>
                <h3>${item.name}</h3>
                <p>${item.description || ''}</p>
                <div class="price-row">
                    <span class="price">₹${priceNum.toFixed(0)}</span>
                    <button class="btn sm primary" onclick="addToCart(${item.id})">Add to Cart</button>
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
    showToast(`${item.name} added to cart`);
}

function updateCartCounts() {
    const count = Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
    const navCount = document.getElementById('cart-nav-count');
    if (navCount) navCount.innerText = count;
    const btnCount = document.getElementById('cart-btn-count');
    if (btnCount) btnCount.innerText = count;
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
            <div style="display:flex;align-items:center;gap:10px;">
                <button class="btn sm ghost" onclick="changeQty(${item.id}, -1)">−</button>
                <span style="font-weight:700;min-width:20px;text-align:center;">${item.qty}</span>
                <button class="btn sm ghost" onclick="changeQty(${item.id}, 1)">+</button>
            </div>
        </div>
    `).join('');

    const total = items.reduce((sum, item) => sum + (item.price * item.qty), 0);
    document.getElementById('cart-total-price').innerText = total.toFixed(0);
}

function changeQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) delete cart[id];
    updateCartCounts();
    renderCart();
}

async function checkout() {
    const items = Object.values(cart);
    if (items.length === 0) return showToast('Your cart is empty', false);

    const total = items.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const res = await api('checkout', { cart: items, total });

    if (res && res.success) {
        alert(`🎉 Order Placed Successfully!\n\nYour Order Code: ${res.order_code}\n\nPresent this code at the counter.`);
        cart = {};
        updateCartCounts();
        showView('home');
    }
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

    const tbody = document.querySelector('#orders-table tbody');
    if (!res.orders.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--mute);padding:24px;">No customer orders placed yet.</td></tr>`;
        return;
    }

    const statuses = ['Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled'];
    tbody.innerHTML = res.orders.map(o => {
        let items = [];
        try { items = JSON.parse(o.items_json); } catch (e) {}
        const itemsStr = items.map(i => `• ${i.name} × ${i.qty}`).join('<br>');

        return `
            <tr>
                <td><strong>${o.order_code}</strong></td>
                <td>${o.guest_name || 'Customer'}</td>
                <td style="font-size:13px;line-height:1.4;">${itemsStr}</td>
                <td><strong>₹${parseFloat(o.total).toFixed(0)}</strong></td>
                <td><span class="status-pill status-${o.status}">${o.status}</span></td>
                <td style="font-size:12px;color:var(--mute);">${o.created_at}</td>
                <td>
                    <select onchange="updateOrderStatus(${o.id}, this.value)" style="padding:6px 10px;border:var(--border);border-radius:8px;font-weight:600;">
                        ${statuses.map(s => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${s}</option>`).join('')}
                    </select>
                </td>
            </tr>
        `;
    }).join('');
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

    const tbody = document.querySelector('#products-table tbody');
    tbody.innerHTML = res.items.map(item => {
        const thumb = item.image_url
            ? `<img src="${item.image_url}" style="width:52px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #10132B;">`
            : `<span style="font-size:30px;">${item.emoji || '🍽️'}</span>`;

        return `
            <tr>
                <td><strong>${item.id}</strong></td>
                <td>${thumb}</td>
                <td>
                    <strong>${item.name}</strong><br>
                    <small style="color:var(--mute);">${item.description || ''}</small>
                </td>
                <td><span style="padding:4px 8px;background:#F0F2F9;border-radius:6px;font-size:12px;font-weight:700;">${item.category}</span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span>₹</span>
                        <input type="number" step="1" value="${parseFloat(item.price)}" 
                               style="width:85px;padding:6px;border:var(--border);border-radius:6px;font-weight:700;" 
                               onchange="updateProductField(${item.id}, 'price', parseFloat(this.value))">
                    </div>
                </td>
                <td>
                    <input type="number" value="${item.stock || 50}" 
                           style="width:70px;padding:6px;border:var(--border);border-radius:6px;font-weight:700;" 
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
        // Reset form
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
    const res = await api('me');
    if (res && res.user) {
        currentUser = res.user;
        updateNav();
        // If logged in as admin, default to admin view; else home view
        showView(currentUser.is_admin == 1 ? 'admin' : 'home');
    } else {
        // NOT logged in: strictly show login view and hide member navigation
        currentUser = null;
        updateNav();
        showView('auth');
    }
}

init();
</script>
</body>
</html>