<?php
require_once __DIR__ . '/db.php';

/** Makes sure the users table has the "active" column (used to disable parishioner accounts). Safe to run on every request. */
function security_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = db();
    $c = require __DIR__ . '/config.php';
    $sqlite = $c['driver'] === 'sqlite';

    $cols = $sqlite
        ? array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');

    if (!in_array('active', $cols, true)) {
        $flag = $sqlite ? 'INTEGER' : 'TINYINT';
        $pdo->exec("ALTER TABLE users ADD COLUMN active {$flag} NOT NULL DEFAULT 1");
    }
}

function find_user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ---------- IP allow-list for the admin site (optional, set in config.php) ---------- */
function admin_ip_allowed(): bool
{
    $c = require __DIR__ . '/config.php';
    $list = $c['admin_allowed_ips'] ?? [];
    if (!$list) {
        return true;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($list as $rule) {
        if (str_contains($rule, '/')) {
            [$net, $bits] = explode('/', $rule, 2);
            $a = ip2long($ip);
            $b = ip2long($net);
            $bits = (int)$bits;
            if ($a === false || $b === false || $bits < 0 || $bits > 32) {
                continue;
            }
            $mask = $bits === 0 ? 0 : ((-1 << (32 - $bits)) & 0xFFFFFFFF);
            if (($a & $mask) === ($b & $mask)) {
                return true;
            }
        } elseif ($rule === $ip) {
            return true;
        }
    }
    return false;
}