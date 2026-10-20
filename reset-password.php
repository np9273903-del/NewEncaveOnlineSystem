<?php
// ==========================================
// ENCAVE CAFÉ - RESET PASSWORD FORWARDER
// ==========================================
$token = $_GET['token'] ?? '';
header("Location: index.php?token=" . urlencode($token));
exit;

