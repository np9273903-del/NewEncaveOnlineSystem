<?php
// ==========================================
// ENCAVE CAFÉ - EMAIL MAILER (PHPMailer)
// ==========================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

/**
 * Send password reset email with 6-digit OTP and 1-click Reset Link
 *
 * @param string $toEmail Recipient email address
 * @param string $recipientName Recipient display name
 * @param string $otp 6-digit numeric OTP code
 * @param string $token 32-character hex reset token
 * @return array
 */
function sendPasswordResetEmail($toEmail, $recipientName, $otp, $token) {
    // Build reset link dynamically based on host and directory
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
    $resetUrl = $protocol . $host . $scriptDir . '/index.php?token=' . urlencode($token);

    // If SMTP credentials have not been configured yet, return fallback with OTP for testing
    if (!defined('SMTP_USER') || empty(SMTP_USER) || !defined('SMTP_PASS') || empty(SMTP_PASS)) {
        return [
            'sent'            => false,
            'smtp_configured' => false,
            'otp'             => $otp,
            'token'           => $token,
            'reset_url'       => $resetUrl,
            'message'         => 'SMTP credentials not configured in config.php. OTP generated for instant testing.'
        ];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        
        $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
        $mail->SMTPSecure = ($secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;

        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Encave Café Support';
        $mail->setFrom(SMTP_USER, $fromName);
        $mail->addAddress($toEmail, $recipientName ?: 'Valued Customer');
        $mail->isHTML(true);
        $mail->Subject = 'Encave Café - Password Reset OTP & Verification';

        $safeName = htmlspecialchars($recipientName ?: 'Customer');
        $safeUrl  = htmlspecialchars($resetUrl);

        $mail->Body = "
        <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:540px;margin:20px auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.06);'>
            <div style='background:linear-gradient(135deg, #10132B 0%, #1c2145 100%);padding:28px 24px;text-align:center;'>
                <h1 style='color:#ffffff;margin:0;font-size:24px;font-weight:900;letter-spacing:1px;'>☕ ENCAVE CAFÉ</h1>
                <p style='color:#c9a87c;margin:6px 0 0 0;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:2px;'>Password Reset Request</p>
            </div>
            <div style='padding:32px 28px;color:#334155;line-height:1.6;'>
                <p style='font-size:16px;margin:0 0 14px 0;'>Hello <strong>{$safeName}</strong>,</p>
                <p style='font-size:14px;margin:0 0 20px 0;color:#64748b;'>We received a request to reset your Encave Café account password. Use the 6-digit OTP code below to verify your identity:</p>
                
                <div style='text-align:center;margin:28px 0;padding:20px;background:#fbf8f3;border:2px dashed #c9a87c;border-radius:12px;'>
                    <div style='font-size:36px;font-weight:900;letter-spacing:10px;color:#10132B;font-family:monospace;'>
                        {$otp}
                    </div>
                    <p style='font-size:12px;color:#854d0e;margin:10px 0 0 0;font-weight:600;'>⏱️ Valid for 15 minutes</p>
                </div>

                <div style='text-align:center;margin:26px 0 16px 0;'>
                    <p style='font-size:14px;color:#64748b;margin-bottom:12px;'>Or click below to reset your password directly in 1-click:</p>
                    <a href='{$safeUrl}' style='display:inline-block;background:#c9a87c;color:#10132B;font-weight:800;font-size:14px;text-decoration:none;padding:12px 30px;border-radius:8px;box-shadow:0 4px 12px rgba(201,168,124,0.3);'>Reset My Password</a>
                </div>

                <hr style='border:none;border-top:1px solid #f1f5f9;margin:26px 0;'>
                <p style='font-size:12px;color:#94a3b8;margin:0;line-height:1.5;'>If you did not request this password reset, please ignore this email. Your password will remain unchanged and your account is secure.</p>
            </div>
            <div style='background:#f8fafc;padding:16px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;'>
                &copy; " . date('Y') . " Encave Café. Fresh Brews & Delicious Bites.
            </div>
        </div>";

        $mail->AltBody = "Hello {$recipientName},\n\nYour Encave Café password reset OTP code is: {$otp}\nValid for 15 minutes.\n\nOr click this link to reset: {$resetUrl}\n\nIf you did not request this, please ignore this email.";

        $mail->send();
        return ['sent' => true, 'smtp_configured' => true, 'reset_url' => $resetUrl];
    } catch (Exception $e) {
        return [
            'sent'            => false,
            'smtp_configured' => true,
            'error'           => $mail->ErrorInfo ?: $e->getMessage(),
            'otp'             => $otp,
            'token'           => $token,
            'reset_url'       => $resetUrl
        ];
    }
}

