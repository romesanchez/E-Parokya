<?php
// Everything the admin site needs beyond the basic functions in db.php / security.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const PER_PAGE = 20;
const LOGIN_ACTIONS = ['login', 'login_failed', 'admin_login', 'admin_login_failed', 'admin_2fa_failed', 'logout', 'register', 'admin_register'];

/* ---------- Small helpers ---------- */
function page_param(): int
{
    return max(1, (int)(filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
}

function day_start(string $d): string
{
    return $d . ' 00:00:00';
}

function day_end(string $d): string
{
    return $d . ' 23:59:59';
}

function is_ymd(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

/* ---------- Requests / transactions (with archive) ---------- */

/** $f: q, status, service, method, from, to, archived (0|1). Returns [rows, total]. */
function admin_requests(array $f, int $page = 1, int $per = PER_PAGE): array
{
    $w = [empty($f['archived']) ? 'r.archived_at IS NULL' : 'r.archived_at IS NOT NULL'];
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
        $w[] = '(r.full_name LIKE ? OR r.email LIKE ? OR r.reference_no LIKE ? OR r.phone LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if (!empty($f['from']) && is_ymd($f['from'])) {
        $w[] = 'r.created_at >= ?';
        $p[] = day_start($f['from']);
    }
    if (!empty($f['to']) && is_ymd($f['to'])) {
        $w[] = 'r.created_at <= ?';
        $p[] = day_end($f['to']);
    }
    $where = ' WHERE ' . implode(' AND ', $w);

    $cnt = db()->prepare('SELECT COUNT(*) FROM service_requests r' . $where);
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();

    $offset = max(0, ($page - 1) * $per);
    $order = empty($f['archived']) ? 'r.created_at DESC, r.id DESC' : 'r.archived_at DESC, r.id DESC';
    $st = db()->prepare(REQ_SELECT . $where . " ORDER BY {$order} LIMIT " . (int)$per . ' OFFSET ' . (int)$offset);
    $st->execute($p);
    return [$st->fetchAll(), $total];
}

function archived_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM service_requests WHERE archived_at IS NOT NULL')->fetchColumn();
}

/** Only finished requests (completed / declined) can be archived. */
function archive_request(int $id): bool
{
    $st = db()->prepare("UPDATE service_requests SET archived_at = ? WHERE id = ? AND archived_at IS NULL AND status IN ('completed','declined')");
    $st->execute([date('Y-m-d H:i:s'), $id]);
    return $st->rowCount() > 0;
}

function restore_request(int $id): bool
{
    $st = db()->prepare('UPDATE service_requests SET archived_at = NULL, updated_at = ? WHERE id = ? AND archived_at IS NOT NULL');
    $st->execute([date('Y-m-d H:i:s'), $id]);
    return $st->rowCount() > 0;
}

/** Permanent delete. Only archived requests can be deleted for good. */
function purge_request(int $id): bool
{
    $pdo = db();
    $chk = $pdo->prepare('SELECT id FROM service_requests WHERE id = ? AND archived_at IS NOT NULL');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM schedules WHERE request_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return true;
}

function archive_all_finished(): int
{
    $st = db()->prepare("UPDATE service_requests SET archived_at = ? WHERE archived_at IS NULL AND status = 'completed'");
    $st->execute([date('Y-m-d H:i:s')]);
    return $st->rowCount();
}

/** Returns an error message or null. $d keys: service_type, full_name, email, phone, preferred_date, notes, status */
function validate_request_form(array $d): ?string
{
    if (!isset(SERVICE_LABELS[$d['service_type'] ?? ''])) {
        return 'Please choose a valid service.';
    }
    if (!isset(STATUS_LABELS[$d['status'] ?? ''])) {
        return 'Please choose a valid status.';
    }
    if (mb_strlen($d['full_name']) < 2 || mb_strlen($d['full_name']) > 120) {
        return 'Please enter the full name (2–120 characters).';
    }
    if ($d['email'] === '' && $d['phone'] === '') {
        return 'Please enter at least an email or a contact number.';
    }
    if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 255)) {
        return 'Please enter a valid email address.';
    }
    if (mb_strlen($d['phone']) > 40 || mb_strlen($d['notes']) > 1000) {
        return 'Please shorten the contact number or notes.';
    }
    if ($d['preferred_date'] !== '') {
        $x = DateTime::createFromFormat('Y-m-d', $d['preferred_date']);
        if (!$x || $x->format('Y-m-d') !== $d['preferred_date']) {
            return 'Please enter a valid preferred date.';
        }
    }
    return null;
}

function update_request_details(int $id, array $d): void
{
    $st = db()->prepare('UPDATE service_requests SET service_type = ?, full_name = ?, email = ?, phone = ?, preferred_date = ?, notes = ?, status = ?, updated_at = ? WHERE id = ?');
    $st->execute([
        $d['service_type'], $d['full_name'], $d['email'],
        $d['phone'] !== '' ? $d['phone'] : null,
        $d['preferred_date'] !== '' ? $d['preferred_date'] : null,
        $d['notes'] !== '' ? $d['notes'] : null,
        $d['status'], date('Y-m-d H:i:s'), $id,
    ]);
}

function request_history(string $ref): array
{
    $st = db()->prepare('SELECT * FROM activity_logs WHERE details LIKE ? ORDER BY id DESC LIMIT 30');
    $st->execute(['%' . $ref . '%']);
    return $st->fetchAll();
}

/* ---------- Tracker (board) ---------- */
function tracker_board(string $service = ''): array
{
    $w = ['r.archived_at IS NULL'];
    $p = [];
    if ($service !== '' && isset(SERVICE_LABELS[$service])) {
        $w[] = 'r.service_type = ?';
        $p[] = $service;
    }
    $sql = REQ_SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY r.created_at ASC, r.id ASC';
    $st = db()->prepare($sql);
    $st->execute($p);
    $cols = array_fill_keys(array_keys(STATUS_LABELS), []);
    foreach ($st->fetchAll() as $r) {
        $cols[$r['status']][] = $r;
    }
    return $cols;
}

function age_days(?string $created): int
{
    return $created ? max(0, (int)floor((time() - strtotime($created)) / 86400)) : 0;
}

/* ---------- Dashboard ---------- */
function stale_pending(int $days = 3): array
{
    $st = db()->prepare(REQ_SELECT . " WHERE r.archived_at IS NULL AND r.status = 'pending' AND r.created_at <= ? ORDER BY r.created_at ASC LIMIT 8");
    $st->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
    return $st->fetchAll();
}

function approved_without_schedule(): array
{
    return db()->query(REQ_SELECT . " WHERE r.archived_at IS NULL AND r.status = 'approved' AND s.id IS NULL ORDER BY r.created_at ASC LIMIT 8")->fetchAll();
}

function upcoming_schedule(int $limit = 6): array
{
    $st = db()->prepare('SELECT s.*, r.full_name, r.service_type, r.reference_no FROM schedules s JOIN service_requests r ON r.id = s.request_id
        WHERE r.archived_at IS NULL AND s.event_date >= ? ORDER BY s.event_date ASC, s.event_time ASC LIMIT ' . (int)$limit);
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

function service_breakdown(): array
{
    $out = [];
    foreach (db()->query('SELECT service_type, COUNT(*) AS n FROM service_requests WHERE archived_at IS NULL GROUP BY service_type') as $r) {
        $out[$r['service_type']] = (int)$r['n'];
    }
    arsort($out);
    return $out;
}

function requests_since(string $dt): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM service_requests WHERE created_at >= ?');
    $st->execute([$dt]);
    return (int)$st->fetchColumn();
}

/* ---------- Login activity ---------- */
function count_log(array $actions, string $since): int
{
    $in = implode(',', array_fill(0, count($actions), '?'));
    $st = db()->prepare("SELECT COUNT(*) FROM activity_logs WHERE action IN ({$in}) AND created_at >= ?");
    $st->execute([...$actions, $since]);
    return (int)$st->fetchColumn();
}

function login_stats(): array
{
    $today = day_start(date('Y-m-d'));
    $week = date('Y-m-d H:i:s', time() - 7 * 86400);
    $uniq = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE action IN ('login','admin_login') AND user_id IS NOT NULL AND created_at >= ?");
    $uniq->execute([$week]);
    return [
        'logins_today' => count_log(['login', 'admin_login'], $today),
        'failed_today' => count_log(['login_failed', 'admin_login_failed', 'admin_2fa_failed'], $today),
        'unique_week'  => (int)$uniq->fetchColumn(),
        'signups_week' => count_log(['register'], $week),
    ];
}

/** IPs with 3+ failed attempts in the last 24 hours. */
function suspicious_ips(): array
{
    $st = db()->prepare("SELECT ip, COUNT(*) AS n, MAX(created_at) AS last_at FROM activity_logs
        WHERE action IN ('login_failed','admin_login_failed','admin_2fa_failed') AND created_at >= ? AND ip <> ''
        GROUP BY ip HAVING COUNT(*) >= 3 ORDER BY n DESC LIMIT 10");
    $st->execute([date('Y-m-d H:i:s', time() - 86400)]);
    return $st->fetchAll();
}

/** $f: type (all|success|failed|logout|register), who (user|admin), q, from, to. Returns [rows, total]. */
function login_events(array $f, int $page = 1, int $per = 25): array
{
    $type = $f['type'] ?? 'all';
    $map = [
        'success'  => ['login', 'admin_login'],
        'failed'   => ['login_failed', 'admin_login_failed', 'admin_2fa_failed'],
        'logout'   => ['logout'],
        'register' => ['register', 'admin_register'],
    ];
    $actions = $map[$type] ?? LOGIN_ACTIONS;
    $in = implode(',', array_fill(0, count($actions), '?'));
    $w = ["l.action IN ({$in})"];
    $p = $actions;

    if (($f['who'] ?? '') === 'admin') {
        $w[] = "(l.role = 'admin' OR l.action LIKE 'admin_%')";
    } elseif (($f['who'] ?? '') === 'user') {
        $w[] = "l.role <> 'admin' AND l.action NOT LIKE 'admin_%'";
    }
    if (!empty($f['q'])) {
        $w[] = '(l.actor LIKE ? OR u.email LIKE ? OR l.ip LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like);
    }
    if (!empty($f['from']) && is_ymd($f['from'])) {
        $w[] = 'l.created_at >= ?';
        $p[] = day_start($f['from']);
    }
    if (!empty($f['to']) && is_ymd($f['to'])) {
        $w[] = 'l.created_at <= ?';
        $p[] = day_end($f['to']);
    }
    $from = ' FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $w);

    $cnt = db()->prepare('SELECT COUNT(*)' . $from);
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();

    $st = db()->prepare('SELECT l.*, u.email AS account_email' . $from . ' ORDER BY l.id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)max(0, ($page - 1) * $per));
    $st->execute($p);
    return [$st->fetchAll(), $total];
}

/* ---------- Clients (parishioner accounts) ---------- */
function admin_clients(string $q, int $page = 1, int $per = PER_PAGE): array
{
    security_schema();
    $w = ["u.role = 'user'"];
    $p = [];
    if ($q !== '') {
        $w[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
        array_push($p, "%{$q}%", "%{$q}%");
    }
    $where = ' WHERE ' . implode(' AND ', $w);
    $cnt = db()->prepare('SELECT COUNT(*) FROM users u' . $where);
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();

    $sql = "SELECT u.id, u.full_name, u.email, u.active, u.created_at,
            (SELECT COUNT(*) FROM service_requests r WHERE r.user_id = u.id) AS req_count,
            (SELECT COUNT(*) FROM service_requests r WHERE r.user_id = u.id AND r.status IN ('pending','approved') AND r.archived_at IS NULL) AS open_count,
            (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.id AND l.action = 'login') AS login_count,
            (SELECT MAX(l.created_at) FROM activity_logs l WHERE l.user_id = u.id AND l.action = 'login') AS last_login
        FROM users u{$where} ORDER BY u.id DESC LIMIT " . (int)$per . ' OFFSET ' . (int)max(0, ($page - 1) * $per);
    $st = db()->prepare($sql);
    $st->execute($p);
    return [$st->fetchAll(), $total];
}

function get_client(int $id): ?array
{
    security_schema();
    $st = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'user'");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function update_client(int $id, string $name, string $email): void
{
    db()->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND role = 'user'")->execute([$name, $email, $id]);
}

function set_client_active(int $id, bool $on): void
{
    security_schema();
    db()->prepare("UPDATE users SET active = ? WHERE id = ? AND role = 'user'")->execute([$on ? 1 : 0, $id]);
}

function set_client_password(int $id, string $password): void
{
    db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'user'")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

/** Deletes the account only. Their requests stay (as "no account") until you archive/delete them. */
function delete_client(int $id): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE service_requests SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        $st = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
        $st->execute([$id]);
        $pdo->commit();
        return $st->rowCount() > 0;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function client_logins(int $id, int $limit = 10): array
{
    $st = db()->prepare("SELECT * FROM activity_logs WHERE user_id = ? AND action IN ('login','logout','login_failed','register') ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$id]);
    return $st->fetchAll();
}
