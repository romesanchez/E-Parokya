<?php
require_once __DIR__ . '/services.php';

const LOG_LABELS = [
    'admin_2fa_failed'      => 'Failed 2FA code',
    'admin_2fa_enabled'     => 'Two-factor turned on',
    'admin_invite_failed'   => 'Invalid staff link',
    'staff_invited'         => 'Staff link created',
    'staff_joined'          => 'Staff account created',
    'staff_revoked'         => 'Staff access revoked',
    'staff_restored'        => 'Staff access restored',
    'staff_2fa_reset'       => 'Staff 2FA reset',
    'register'              => 'Account created',
    'login'                 => 'Login',
    'login_failed'          => 'Failed login',
    'logout'                => 'Logout',
    'admin_register'        => 'Admin account created',
    'admin_register_failed' => 'Failed admin sign-up',
    'admin_login'           => 'Admin login',
    'admin_login_failed'    => 'Failed admin login',
    'request_created'       => 'Request submitted (online)',
    'request_assisted'      => 'Request recorded (assisted)',
    'status_changed'        => 'Status changed',
    'schedule_set'          => 'Schedule saved',
    'schedule_removed'      => 'Schedule removed',
    'request_updated'       => 'Request edited',
    'request_archived'      => 'Request archived',
    'request_restored'      => 'Request restored',
    'request_deleted'       => 'Request deleted permanently',
    'client_updated'        => 'Client account edited',
    'client_disabled'       => 'Client account disabled',
    'client_enabled'        => 'Client account enabled',
    'client_deleted'        => 'Client account deleted',
    'document_issued'       => 'Document issued',
    'admin_password_changed' => 'Admin password changed',
    'admin_password_failed'  => 'Failed password change',
];

const METHOD_LABELS = [
    'online'   => 'Online',
    'assisted' => 'Assisted',
];

function make_reference(int $id, ?string $createdAt = null): string
{
    $year = $createdAt ? date('Y', strtotime($createdAt)) : date('Y');
    return sprintf('EP-%s-%06d', $year, $id);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = require __DIR__ . '/config.php';
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if ($c['driver'] === 'sqlite') {
        $fresh = !file_exists($c['sqlite']);
        $pdo = new PDO('sqlite:' . $c['sqlite'], null, null, $opts);
        if ($fresh) {
            $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sqlite.sql'));
        }
    } else {
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    }
    ensure_schema($pdo, $c['driver']);
    return $pdo;
}

/** Creates the new tables / columns on an existing database. Safe to run every request. */
function ensure_schema(PDO $pdo, string $driver): void
{
    $sqlite = $driver === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $d    = $sqlite ? 'TEXT' : 'DATE';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id {$pk},
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'user',
        created_at {$dt} NOT NULL
    ){$tail}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id {$pk},
        user_id {$int} NULL,
        actor VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL,
        action VARCHAR(40) NOT NULL,
        details VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at {$dt} NOT NULL
    ){$tail}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schedules (
        id {$pk},
        request_id {$int} NOT NULL UNIQUE,
        event_date {$d} NOT NULL,
        event_time VARCHAR(5) NOT NULL,
        venue VARCHAR(120) NOT NULL,
        officiant VARCHAR(120) NULL,
        notes VARCHAR(500) NULL,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL
    ){$tail}");

    $cols = $sqlite
        ? array_column($pdo->query('PRAGMA table_info(service_requests)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM service_requests')->fetchAll(), 'Field');

    $add = [
        'user_id'      => "{$int} NULL",
        'reference_no' => 'VARCHAR(20) NULL',
        'method'       => "VARCHAR(10) NOT NULL DEFAULT 'online'",
        'created_by'   => "{$int} NULL",
        'archived_at'  => "{$dt} NULL",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE service_requests ADD COLUMN {$col} {$def}");
        }
    }

    // Give every older request a reference number.
    $old = $pdo->query('SELECT id, created_at FROM service_requests WHERE reference_no IS NULL')->fetchAll();
    if ($old) {
        $up = $pdo->prepare('UPDATE service_requests SET reference_no = ? WHERE id = ?');
        foreach ($old as $r) {
            $up->execute([make_reference((int)$r['id'], $r['created_at']), $r['id']]);
        }
    }

    if ($sqlite) {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_requests_ref ON service_requests (reference_no)');
    } else {
        try {
            $pdo->exec('CREATE UNIQUE INDEX idx_requests_ref ON service_requests (reference_no)');
        } catch (Throwable $e) {
            // index already exists
        }
    }
}

/* ---------- Users ---------- */
function find_user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function create_user(string $name, string $email, string $password, string $role = 'user'): int
{
    $st = db()->prepare('INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

function count_users(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
}

/* ---------- Requests ---------- */
const REQ_SELECT = 'SELECT r.*, s.event_date, s.event_time, s.venue, s.officiant, s.notes AS sched_notes,
        u.email AS account_email, c.full_name AS staff_name
    FROM service_requests r
    LEFT JOIN schedules s ON s.request_id = r.id
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN users c ON c.id = r.created_by';

function search_requests(array $f = []): array
{
    $w = ['r.archived_at IS NULL'];
    $p = [];
    if (!empty($f['status']) && isset(STATUS_LABELS[$f['status']])) {
        $w[] = 'r.status = ?';
        $p[] = $f['status'];
    }
    if (!empty($f['service']) && isset(SERVICE_LABELS[$f['service']])) {
        $w[] = 'r.service_type = ?';
        $p[] = $f['service'];
    }
    if (!empty($f['method']) && isset(METHOD_LABELS[$f['method']])) {
        $w[] = 'r.method = ?';
        $p[] = $f['method'];
    }
    if (!empty($f['q'])) {
        $w[] = '(r.full_name LIKE ? OR r.email LIKE ? OR r.reference_no LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like);
    }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
        $w[] = 'DATE(r.created_at) >= ?';
        $p[] = $f['from'];
    }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
        $w[] = 'DATE(r.created_at) <= ?';
        $p[] = $f['to'];
    }
    $sql = REQ_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 500';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function list_requests(?string $status = null): array
{
    return search_requests(['status' => $status]);
}

function get_request(int $id): ?array
{
    $st = db()->prepare(REQ_SELECT . ' WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function requests_for_user(int $uid): array
{
    $st = db()->prepare(REQ_SELECT . ' WHERE r.user_id = ? ORDER BY r.created_at DESC, r.id DESC');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function status_counts(): array
{
    $counts = array_fill_keys(array_keys(STATUS_LABELS), 0);
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM service_requests WHERE archived_at IS NULL GROUP BY status') as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

function create_request(array $d): int
{
    $st = db()->prepare(
        'INSERT INTO service_requests (user_id, service_type, full_name, email, phone, preferred_date, notes, status, method, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?)'
    );
    $now = date('Y-m-d H:i:s');
    $method = isset(METHOD_LABELS[$d['method'] ?? '']) ? $d['method'] : 'online';
    $st->execute([
        $d['user_id'] ?? null, $d['service_type'], $d['full_name'], $d['email'], $d['phone'],
        $d['preferred_date'], $d['notes'], $method, $d['created_by'] ?? null, $now, $now,
    ]);
    $id = (int)db()->lastInsertId();
    $up = db()->prepare('UPDATE service_requests SET reference_no = ? WHERE id = ?');
    $up->execute([make_reference($id, $now), $id]);
    return $id;
}

function update_status(int $id, string $status): void
{
    $st = db()->prepare('UPDATE service_requests SET status = ?, updated_at = ? WHERE id = ?');
    $st->execute([$status, date('Y-m-d H:i:s'), $id]);
}

/* ---------- Scheduling ---------- */
function schedule_conflict(string $date, string $time, string $venue, int $exceptRequest): ?array
{
    $st = db()->prepare(
        'SELECT r.full_name, r.service_type FROM schedules s
         JOIN service_requests r ON r.id = s.request_id
         WHERE s.event_date = ? AND s.event_time = ? AND LOWER(s.venue) = LOWER(?) AND s.request_id <> ? LIMIT 1'
    );
    $st->execute([$date, $time, $venue, $exceptRequest]);
    return $st->fetch() ?: null;
}

function save_schedule(int $rid, array $d): void
{
    $now = date('Y-m-d H:i:s');
    $ex = db()->prepare('SELECT id FROM schedules WHERE request_id = ?');
    $ex->execute([$rid]);
    $off = $d['officiant'] !== '' ? $d['officiant'] : null;
    $notes = $d['notes'] !== '' ? $d['notes'] : null;
    if ($ex->fetch()) {
        $st = db()->prepare('UPDATE schedules SET event_date = ?, event_time = ?, venue = ?, officiant = ?, notes = ?, updated_at = ? WHERE request_id = ?');
        $st->execute([$d['event_date'], $d['event_time'], $d['venue'], $off, $notes, $now, $rid]);
    } else {
        $st = db()->prepare('INSERT INTO schedules (request_id, event_date, event_time, venue, officiant, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$rid, $d['event_date'], $d['event_time'], $d['venue'], $off, $notes, $now, $now]);
    }
}

function delete_schedule(int $rid): void
{
    $st = db()->prepare('DELETE FROM schedules WHERE request_id = ?');
    $st->execute([$rid]);
}

function schedule_list(bool $upcoming): array
{
    $sql = 'SELECT s.*, r.full_name, r.service_type, r.status FROM schedules s JOIN service_requests r ON r.id = s.request_id WHERE r.archived_at IS NULL AND s.event_date '
        . ($upcoming ? '>= ? ORDER BY s.event_date ASC, s.event_time ASC' : '< ? ORDER BY s.event_date DESC, s.event_time DESC LIMIT 20');
    $st = db()->prepare($sql);
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

function count_upcoming(): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM schedules WHERE event_date >= ?');
    $st->execute([date('Y-m-d')]);
    return (int)$st->fetchColumn();
}

/* ---------- Activity logs ---------- */
function log_action(string $action, string $details = '', ?string $actor = null, ?int $uid = null, ?string $role = null): void
{
    try {
        $st = db()->prepare('INSERT INTO activity_logs (user_id, actor, role, action, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $uid ?? ($_SESSION['uid'] ?? null),
            $actor ?? ($_SESSION['name'] ?? 'guest'),
            $role ?? ($_SESSION['role'] ?? 'guest'),
            $action,
            mb_substr($details, 0, 500),
            $_SERVER['REMOTE_ADDR'] ?? '',
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
    }
}

/** 5+ failures of this kind in 15 minutes from the same IP or email = locked out. */
function too_many_failures(string $email, string $action): bool
{
    $since = date('Y-m-d H:i:s', time() - 900);
    $st = db()->prepare('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND created_at >= ? AND (ip = ? OR actor = ?)');
    $st->execute([$action, $since, $_SERVER['REMOTE_ADDR'] ?? '', $email]);
    return (int)$st->fetchColumn() >= 5;
}

function search_logs(array $f): array
{
    $w = [];
    $p = [];
    if (!empty($f['action'])) {
        $w[] = 'action = ?';
        $p[] = $f['action'];
    }
    if (!empty($f['role'])) {
        $w[] = 'role = ?';
        $p[] = $f['role'];
    }
    if (!empty($f['q'])) {
        $w[] = '(actor LIKE ? OR details LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like);
    }
    $sql = 'SELECT * FROM activity_logs' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}