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
    'service_created'       => 'Service created',
    'service_updated'       => 'Service updated',
    'service_enabled'       => 'Service enabled',
    'service_disabled'      => 'Service disabled',
    'service_archived'      => 'Service archived',
    'service_restored'      => 'Service restored',
    'service_field_added'   => 'Service field added',
    'service_field_updated' => 'Service field updated',
    'service_field_enabled' => 'Service field enabled',
    'service_field_disabled'=> 'Service field disabled',
    'event_created'         => 'Event created',
    'event_updated'         => 'Event edited',
    'event_cancelled'       => 'Event cancelled',
    'event_restored'        => 'Event restored',
    'event_deleted'         => 'Event deleted',
    'admin_password_changed' => 'Admin password changed',
    'admin_password_failed'  => 'Failed password change',
    'reset_requested'        => 'Password reset requested',
    'password_reset'         => 'Password reset completed',
    'password_reset_failed'  => 'Invalid password reset link',
    'client_reset_link'      => 'Reset link created by staff',
];

const METHOD_LABELS = [
    'online'   => 'Online',
    'assisted' => 'Assisted',
];

/** "14:30" -> 870 (minutes since midnight). */
function time_to_minutes(string $t): int
{
    [$h, $m] = array_pad(array_map('intval', explode(':', $t)), 2, 0);
    return $h * 60 + $m;
}

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
        is_public {$int} NOT NULL DEFAULT 0,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL
    ){$tail}");

    // Add the public listing flag to existing schedules.
    $scheduleCols = $sqlite
        ? array_column($pdo->query('PRAGMA table_info(schedules)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM schedules')->fetchAll(), 'Field');

    if (!in_array('is_public', $scheduleCols, true)) {
        $pdo->exec("ALTER TABLE schedules ADD COLUMN is_public {$int} NOT NULL DEFAULT 0");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id {$pk},
        title VARCHAR(150) NOT NULL,
        event_type VARCHAR(20) NOT NULL DEFAULT 'other',
        event_date {$d} NOT NULL,
        start_time VARCHAR(5) NOT NULL,
        end_time VARCHAR(5) NULL,
        venue VARCHAR(120) NOT NULL,
        description VARCHAR(1000) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'published',
        created_by {$int} NULL,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS altar_servers (
        id {$pk},
        full_name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NULL,
        notes VARCHAR(300) NULL,
        active {$int} NOT NULL DEFAULT 1,
        created_at {$dt} NOT NULL
    ){$tail}");

    // source_type is 'schedule' (schedules.id) or 'event' (events.id).
    $pdo->exec("CREATE TABLE IF NOT EXISTS altar_assignments (
        id {$pk},
        server_id {$int} NOT NULL,
        source_type VARCHAR(10) NOT NULL,
        source_id {$int} NOT NULL,
        created_at {$dt} NOT NULL,
        UNIQUE (server_id, source_type, source_id)
    ){$tail}");

    // Public Mass and Confession schedules.
    $pdo->exec("CREATE TABLE IF NOT EXISTS parish_schedules (
        id {$pk},
        schedule_type VARCHAR(20) NOT NULL DEFAULT 'mass',
        day_label VARCHAR(80) NOT NULL,
        schedule_details VARCHAR(255) NOT NULL,
        sort_order {$int} NOT NULL DEFAULT 0,
        is_active {$int} NOT NULL DEFAULT 1,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL
    ){$tail}");
    
    $cols = $sqlite
        ? array_column($pdo->query('PRAGMA table_info(service_requests)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM service_requests')->fetchAll(), 'Field');

    $add = [
        'user_id'                     => "{$int} NULL",
        'reference_no'                => 'VARCHAR(20) NULL',
        'method'                      => "VARCHAR(10) NOT NULL DEFAULT 'online'",
        'created_by'                  => "{$int} NULL",
        'archived_at'                 => "{$dt} NULL",
        'public_schedule_consent'     => "{$int} NOT NULL DEFAULT 0",
        'public_schedule_consent_at'  => "{$dt} NULL",
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

    // New service keys are stored in a catalogue, so the old seven-value
    // service_type CHECK constraint must be removed while preserving records.
    migrate_service_type_constraint($pdo, $sqlite);

    // Dynamic service catalogue, form definitions, answer snapshots and private uploads.
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id {$pk},
        service_key VARCHAR(32) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(500) NULL,
        icon VARCHAR(32) NOT NULL DEFAULT 'church',
        is_active {$int} NOT NULL DEFAULT 1,
        is_archived {$int} NOT NULL DEFAULT 0,
        sort_order {$int} NOT NULL DEFAULT 0,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL
    ){$tail}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_form_fields (
        id {$pk},
        service_id {$int} NOT NULL,
        field_key VARCHAR(64) NOT NULL,
        label VARCHAR(120) NOT NULL,
        field_type VARCHAR(20) NOT NULL,
        is_required {$int} NOT NULL DEFAULT 0,
        placeholder VARCHAR(160) NULL,
        help_text VARCHAR(255) NULL,
        options_json TEXT NULL,
        sort_order {$int} NOT NULL DEFAULT 0,
        is_active {$int} NOT NULL DEFAULT 1,
        created_at {$dt} NOT NULL,
        updated_at {$dt} NOT NULL,
        UNIQUE (service_id, field_key)
    ){$tail}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_request_answers (
        id {$pk},
        request_id {$int} NOT NULL,
        field_key VARCHAR(64) NOT NULL,
        field_label VARCHAR(120) NOT NULL,
        field_type VARCHAR(20) NOT NULL,
        answer_text TEXT NULL,
        created_at {$dt} NOT NULL,
        UNIQUE (request_id, field_key)
    ){$tail}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_request_uploads (
        id {$pk},
        request_id {$int} NOT NULL,
        field_key VARCHAR(64) NOT NULL,
        field_label VARCHAR(120) NOT NULL,
        original_name VARCHAR(120) NOT NULL,
        stored_path VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size {$int} NOT NULL,
        uploaded_at {$dt} NOT NULL,
        UNIQUE (request_id, field_key)
    ){$tail}");

    // Seed the existing seven service types without overwriting any edits.
    $seed = $pdo->prepare('SELECT id FROM services WHERE service_key = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO services (service_key, name, description, icon, is_active, is_archived, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)');
    $legacyIcons = ['baptism' => 'water', 'wedding' => 'ring', 'funeral' => 'cross', 'blessing' => 'house', 'first_communion' => 'book', 'confirmation' => 'dove', 'mass_intention' => 'calendar'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $order = 0;
    foreach (SERVICE_LABELS as $key => $label) {
        $seed->execute([$key]);
        if (!$seed->fetchColumn()) {
            $insert->execute([$key, $label, SERVICE_DESCRIPTIONS[$key] ?? '', $legacyIcons[$key] ?? 'church', $order, $now, $now]);
        }
        $order += 10;
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

/** Remove only the legacy service-type constraint; retain all existing request data. */
function migrate_service_type_constraint(PDO $pdo, bool $sqlite): void
{
    if ($sqlite) {
        $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'service_requests'")->fetchColumn();
        if (!$sql || !preg_match('/CHECK\s*\(\s*service_type\s+IN\s*\(/i', $sql)) {
            return;
        }
        $newSql = preg_replace('/\s+CHECK\s*\(\s*service_type\s+IN\s*\([^)]*\)\s*\)/i', '', $sql, 1);
        if (!$newSql || $newSql === $sql) {
            throw new RuntimeException('Could not prepare the service catalogue database migration. Back up the SQLite database before updating.');
        }
        $newSql = preg_replace('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(?:"service_requests"|`service_requests`|\[service_requests\]|service_requests)/i', 'CREATE TABLE service_requests_dynamic_migration', $newSql, 1);
        if (!$newSql) {
            throw new RuntimeException('Could not rename the SQLite service request table for migration.');
        }
        // ALTER TABLE-added columns are not reflected in sqlite_master's original
        // CREATE SQL. Re-add every such column before copying all rows.
        $tableInfo = $pdo->query('PRAGMA table_info(service_requests)')->fetchAll();
        $extraDefs = [];
        foreach ($tableInfo as $column) {
            $columnName = (string)$column['name'];
            if (preg_match('/\b' . preg_quote($columnName, '/') . '\b/i', $sql)) {
                continue;
            }
            $definition = '"' . str_replace('"', '""', $columnName) . '" ' . ($column['type'] ?: 'TEXT');
            if ((int)$column['notnull'] === 1) $definition .= ' NOT NULL';
            if ($column['dflt_value'] !== null) $definition .= ' DEFAULT ' . $column['dflt_value'];
            $extraDefs[] = $definition;
        }
        if ($extraDefs) {
            $close = strrpos($newSql, ')');
            if ($close === false) throw new RuntimeException('Could not rebuild the SQLite request table definition.');
            $newSql = substr($newSql, 0, $close) . ', ' . implode(', ', $extraDefs) . substr($newSql, $close);
        }
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            if ($pdo->inTransaction()) throw new RuntimeException('Database migration cannot run inside another transaction.');
            $pdo->beginTransaction();
            $pdo->exec('DROP TABLE IF EXISTS service_requests_dynamic_migration');
            $pdo->exec($newSql);
            $pdo->exec('INSERT INTO service_requests_dynamic_migration SELECT * FROM service_requests');
            $pdo->exec('DROP TABLE service_requests');
            $pdo->exec('ALTER TABLE service_requests_dynamic_migration RENAME TO service_requests');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        return;
    }

    try {
        // Discover the check by its expression, not only by one constraint name.
        // Older deployments may have a database-generated name for the whitelist.
        $checks = $pdo->query("SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
            INNER JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
                ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
               AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
              AND tc.TABLE_NAME = 'service_requests'
              AND tc.CONSTRAINT_TYPE = 'CHECK'")->fetchAll();

        foreach ($checks as $check) {
            $name = (string)($check['CONSTRAINT_NAME'] ?? '');
            $clause = (string)($check['CHECK_CLAUSE'] ?? '');
            if ($name === '' || !preg_match('/\bservice_type\b/i', $clause)) {
                continue;
            }
            $quotedName = '`' . str_replace('`', '``', $name) . '`';
            try {
                $pdo->exec('ALTER TABLE service_requests DROP CHECK ' . $quotedName);
            } catch (Throwable $first) {
                try {
                    $pdo->exec('ALTER TABLE service_requests DROP CONSTRAINT ' . $quotedName);
                } catch (Throwable $second) {
                    throw new RuntimeException('The database still restricts service_type to the original service list. Back up the database and remove the service_type CHECK constraint before using custom services.', 0, $second);
                }
            }
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Some older MySQL/MariaDB versions do not expose enforced CHECK metadata.
        // MySQL versions that ignore CHECK constraints need no migration here.
        try {
            $legacy = $pdo->prepare("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_service'");
            $legacy->execute();
            if ($legacy->fetchColumn()) {
                try {
                    $pdo->exec('ALTER TABLE service_requests DROP CHECK chk_service');
                } catch (Throwable $first) {
                    $pdo->exec('ALTER TABLE service_requests DROP CONSTRAINT chk_service');
                }
            }
        } catch (Throwable $fallbackError) {
            throw new RuntimeException('Could not verify or remove the old service_type CHECK constraint. Custom services may not save until the database schema is updated.', 0, $fallbackError);
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
const REQ_SELECT = 'SELECT r.*, s.is_public, s.event_date, s.event_time, s.venue, s.officiant, s.notes AS sched_notes,
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
    if (!empty($f['service']) && service_key_exists((string)$f['service'])) {
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
    $now = (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Manila')
    ))->format('Y-m-d H:i:s');

    $method = isset(METHOD_LABELS[$d['method'] ?? ''])
        ? $d['method']
        : 'online';

    $publicConsent = isset($d['public_schedule_consent'])
        && (string)$d['public_schedule_consent'] === '1';

    $publicConsentAt = $publicConsent ? $now : null;

    $st = db()->prepare(
        'INSERT INTO service_requests (
            user_id, service_type, full_name, email, phone,
            preferred_date, notes, public_schedule_consent,
            public_schedule_consent_at, status, method,
            created_by, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?)'
    );

    $st->execute([
        $d['user_id'] ?? null,
        $d['service_type'],
        $d['full_name'],
        $d['email'],
        $d['phone'],
        $d['preferred_date'],
        $d['notes'],
        $publicConsent ? 1 : 0,
        $publicConsentAt,
        $method,
        $d['created_by'] ?? null,
        $now,
        $now,
    ]);

    $id = (int)db()->lastInsertId();

    $up = db()->prepare(
        'UPDATE service_requests SET reference_no = ? WHERE id = ?'
    );
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
    if ($row = $st->fetch()) {
        return $row;
    }

    // Parish events (events table) use the same venues, so they count as conflicts too.
    $ev = db()->prepare("SELECT title, start_time, end_time FROM events WHERE status <> 'cancelled' AND event_date = ? AND LOWER(venue) = LOWER(?)");
    $ev->execute([$date, $venue]);
    $s = time_to_minutes($time);
    foreach ($ev->fetchAll() as $r) {
        $s2 = time_to_minutes($r['start_time']);
        $e2 = $r['end_time'] ? time_to_minutes($r['end_time']) : $s2 + 60;
        if ($s < $e2 && $s2 < $s + 60) {
            return ['full_name' => $r['title'], 'service_type' => 'event'];
        }
    }
    return null;
}

function save_schedule(int $rid, array $d): void
{
    $now = (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Manila')
    ))->format('Y-m-d H:i:s');

    // Verify consent and approval directly from the database.
    // Never rely only on the checkbox submitted by the browser.
    $isPublic = !empty($d['is_public']) ? 1 : 0;

    if ($isPublic === 1) {
        $consent = db()->prepare(
            "SELECT public_schedule_consent
             FROM service_requests
             WHERE id = ?
               AND status = 'approved'
               AND archived_at IS NULL
               AND public_schedule_consent_at IS NOT NULL"
        );

        $consent->execute([$rid]);

        $isPublic = ((int)$consent->fetchColumn() === 1) ? 1 : 0;
    }

    $ex = db()->prepare(
        'SELECT id FROM schedules WHERE request_id = ?'
    );
    $ex->execute([$rid]);

    $off = $d['officiant'] !== '' ? $d['officiant'] : null;
    $notes = $d['notes'] !== '' ? $d['notes'] : null;

    if ($ex->fetch()) {
        $st = db()->prepare(
            'UPDATE schedules
             SET event_date = ?, event_time = ?, venue = ?,
                 officiant = ?, notes = ?, is_public = ?, updated_at = ?
             WHERE request_id = ?'
        );

        $st->execute([
            $d['event_date'],
            $d['event_time'],
            $d['venue'],
            $off,
            $notes,
            $isPublic,
            $now,
            $rid,
        ]);
    } else {
        $st = db()->prepare(
            'INSERT INTO schedules (
                request_id, event_date, event_time, venue,
                officiant, notes, is_public, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $st->execute([
            $rid,
            $d['event_date'],
            $d['event_time'],
            $d['venue'],
            $off,
            $notes,
            $isPublic,
            $now,
            $now,
        ]);
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