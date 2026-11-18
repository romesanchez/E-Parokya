<?php
/**
 * Forgot-password / reset-password support for parishioner accounts.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const RESET_TTL_EMAIL = 3600;   // 1 hour
const RESET_TTL_STAFF = 86400;  // 24 hours

function password_reset_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    security_schema(); // makes sure users.active exists

    $c = require __DIR__ . '/config.php';
    $sqlite = $c['driver'] === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id {$pk},
        user_id {$int} NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at {$dt} NOT NULL,
        used_at {$dt} NULL,
        created_by {$int} NULL,
        created_at {$dt} NOT NULL
    ){$tail}");
}

function public_base_url(): string
{
    $c = require __DIR__ . '/config.php';
    $base = rtrim((string)($c['public_url'] ?? ''), '/');
    if ($base !== '') {
        return $base;
    }
    return rtrim(absolute_url(''), '/');
}

function reset_link(string $token): string
{
    return public_base_url() . '/reset-password.php?token=' . $token;
}

function create_password_reset(int $userId, int $ttl = RESET_TTL_EMAIL, ?int $createdBy = null): string
{
    password_reset_schema();
    $pdo = db();
    $now = date('Y-m-d H:i:s');

    $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([$now, $userId]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + $ttl), $createdBy, $now]);

    $pdo->prepare('DELETE FROM password_resets WHERE expires_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);

    return $token;
}

function find_valid_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    password_reset_schema();
    $st = db()->prepare(
        "SELECT pr.*, u.full_name, u.email
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= ?
            AND u.role = 'user' AND u.active = 1"
    );
    $st->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    return $st->fetch() ?: null;
}

function complete_password_reset(array $reset, string $newPassword): bool
{
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $claim->execute([$now, (int)$reset['id']]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'user'")
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([$now, (int)$reset['user_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($ex->getMessage());
        return false;
    }
}

function send_password_reset_email(string $email, string $name, string $link): bool
{
    $c = require __DIR__ . '/config.php';
    $from = (string)($c['mail_from'] ?? 'no-reply@localhost');
    $name = trim(preg_replace('/[\r\n]+/', ' ', $name));

    $subject = 'Reset your St. Joseph Parish password';
    $body = "Hello {$name},\r\n\r\n"
        . "We received a request to reset the password of your St. Joseph Parish account.\r\n"
        . "Open this link to choose a new password (it works once and expires in 1 hour):\r\n\r\n"
        . "{$link}\r\n\r\n"
        . "If you did not ask for this, you can ignore this email. Your password will not change.\r\n\r\n"
        . "St. Joseph Parish Office\r\n";
    $headers = "From: St. Joseph Parish <{$from}>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    return function_exists('mail') && @mail($email, $subject, $body, $headers);
}