<?php
// Usage (from the psc-php folder):  php set-admin-password.php admin@email.com 'NewPassword123'
// Command line only. Delete this file after using it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/db.php';

$email = strtolower(trim($argv[1] ?? ''));
$pass  = $argv[2] ?? '';
if ($email === '' || strlen($pass) < 8 || strlen($pass) > 72) {
    fwrite(STDERR, "Usage: php set-admin-password.php admin@email.com 'NewPassword123'  (password 8-72 characters)\n");
    exit(1);
}

$st = db()->prepare("UPDATE users SET password_hash = ? WHERE email = ? AND role = 'admin'");
$st->execute([password_hash($pass, PASSWORD_DEFAULT), $email]);
echo $st->rowCount() ? "Password updated for {$email}.\n" : "No admin account found with that email.\n";