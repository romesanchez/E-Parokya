<?php
// ONE-TIME CLEANUP. Run from the psc-php folder:  php reset-admins.php
// Deletes every admin account (and the leftover staff-invite table) so you can make a fresh admin.
// Parishioner accounts and requests are NOT touched. Delete this file after using it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/db.php';

$pdo = db();
$n = $pdo->exec("DELETE FROM users WHERE role = 'admin'");
$pdo->exec('DROP TABLE IF EXISTS admin_invites');
echo "Deleted {$n} admin account(s). Now open the admin site and use \"Create one\" to make a new admin.\n";