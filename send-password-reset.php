<?php
// ==========================================
// ENCAVE CAFÉ - SEND PASSWORD RESET (STANDALONE HANDLER)
// ==========================================
require_once 'config.php';
require_once 'mailer.php';

$email = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ''));

if (!$email) {
    die("Please provide an email address. <a href='index.php'>Back to website</a>");
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT id, name, email FROM `users` WHERE LOWER(email) = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $otp = (string)random_int(100000, 999999);
        $token = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $token);
        $expiry = date('Y-m-d H:i:s', time() + 15 * 60);

        $up = $pdo->prepare("UPDATE `users` SET `reset_token_hash` = ?, `reset_token_expires_at` = ?, `reset_otp` = ?, `reset_otp_expires_at` = ? WHERE `id` = ?");
        $up->execute([$tokenHash, $expiry, $otp, $expiry, $user['id']]);

        $res = sendPasswordResetEmail($user['email'], $user['name'], $otp, $token);
    }
} catch (Exception $e) {
    // Fail gracefully
}

// Redirect back to index.php with instruction
header("Location: index.php?forgot_sent=" . urlencode($email));
exit;

