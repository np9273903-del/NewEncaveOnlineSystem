<?php
// ==========================================
// ENCAVE CAFÉ - CONFIGURATION
// ==========================================

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'pravin807');              // XAMPP default = empty. If you set a password, put it here.
define('DB_NAME', 'encave_nikhil');

// Admin credentials
define('ADMIN_EMAIL', 'admin@encave.com');
define('ADMIN_PASSWORD', 'admin123');        // Change this after first login

// ==========================================
// SMTP / EMAIL SETTINGS (For Forgot Password OTP & Reset Links)
// ==========================================
// To send real OTP emails from your Gmail account:
// 1. Turn on 2-Step Verification in your Google Account.
// 2. Go to: https://myaccount.google.com/apppasswords
// 3. Create an App Password (select 'Mail' / 'Other', name it 'Encave').
// 4. Copy the generated 16-character password into SMTP_PASS below.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');                // 'tls' (port 587) or 'ssl' (port 465)
define('SMTP_USER', 'np9273903@gmail.com');
define('SMTP_PASS', 'ekvprlpujndqnwvn');
define('SMTP_FROM_NAME', 'Encave Café Support');

// ==========================================
// RAZORPAY PAYMENT GATEWAY (UPI, Cards, Netbanking)
// ==========================================
define('RAZORPAY_KEY_ID', 'rzp_test_Tq7DBRXisCQL81');
define('RAZORPAY_KEY_SECRET', 'MeWOgCAYT9tDYKaHFyEMEocg');
define('RAZORPAY_CURRENCY', 'INR');
?>
