<?php
/**
 * Altar Server Management: roster, availability, assignments, auto-assignment and conflict detection.
 *
 * An "activity" is anything an altar server can be assigned to:
 *   - a service schedule (schedules table)  -> key "schedule:<schedules.id>"
 *   - a parish event      (events table)    -> key "event:<events.id>"
 *
 * A server can be assigned to an activity only when ALL of these are true:
 *   1. The server is active.
 *   2. The server is not on leave (altar_time_off) on that date.
 *   3. The server's weekly availability (altar_availability) covers the activity. A server with
 *      no weekly availability saved is treated as available any day, any time.
 *   4. The server has no other activity on the same date whose time range overlaps.
 *
 * Auto-assignment picks, for each activity, the eligible servers with the fewest upcoming
 * assignments so the workload is shared fairly.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/events.php';

/** Minimum gap (minutes) between two assignments of the same server. 0 = back-to-back is allowed. */
const ALTAR_BUFFER_MINUTES = 0;

/** Default length (minutes) of a service schedule, which only has a start time. */
const ALTAR_SERVICE_MINUTES = 60;

/** How many altar servers an activity needs when auto-assigning (can be changed in the form). */
const ALTAR_DEFAULT_SLOTS = 2;

const ALTAR_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/* ---------- Schema (availability + leave) ---------- */

/** Creates the availability tables on first use. Safe to run on every request. */
function altar_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $c = require __DIR__ . '/config.php';
    $sqlite = $c['driver'] === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $d    = $sqlite ? 'TEXT' : 'DATE';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    // One row per weekday the server can serve (0 = Sunday ... 6 = Saturday), with a time window.
    db()->exec("CREATE TABLE IF NOT EXISTS altar_availability (
        id {$pk},
        server_id {$int} NOT NULL,
        weekday {$int} NOT NULL,
        start_time VARCHAR(5) NOT NULL,
        end_time VARCHAR(5) NOT NULL,
        UNIQUE (server_id, weekday)
    ){$tail}");

    // Dates the server is away (whole days, inclusive).
    db()->exec("CREATE TABLE IF NOT EXISTS altar_time_off (
        id {$pk},
        server_id {$int} NOT NULL,
        date_from {$d} NOT NULL,
        date_to {$d} NOT NULL,
        reason VARCHAR(200) NULL,
        created_at {$dt} NOT NULL
    ){$tail}");
}

/* ---------- Roster ---------- */

function altar_servers(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM altar_servers' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, full_name ASC';
    return db()->query($sql)->fetchAll();
}

function altar_get_server(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM altar_servers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function altar_validate_server(array $d): ?string
{
    if (mb_strlen($d['full_name']) < 2 || mb_strlen($d['full_name']) > 120) {
        return 'Please enter the altar server\'s name (2 to 120 characters).';
    }
    if ($d['phone'] !== '' && mb_strlen($d['phone']) > 40) {
        return 'Please shorten the phone number.';
    }
    if ($d['notes'] !== '' && mb_strlen($d['notes']) > 300) {
        return 'Please shorten the notes (up to 300 characters).';
    }
    return null;
}

function altar_save_server(int $id, array $d): int
{
    $phone = $d['phone'] !== '' ? $d['phone'] : null;
    $notes = $d['notes'] !== '' ? $d['notes'] : null;
    if ($id > 0) {
        $st = db()->prepare('UPDATE altar_servers SET full_name = ?, phone = ?, notes = ? WHERE id = ?');
        $st->execute([$d['full_name'], $phone, $notes, $id]);
        return $id;
    }
    $st = db()->prepare('INSERT INTO altar_servers (full_name, phone, notes, active, created_at) VALUES (?, ?, ?, 1, ?)');
    $st->execute([$d['full_name'], $phone, $notes, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

function altar_set_active(int $id, bool $active): void
{
    $st = db()->prepare('UPDATE altar_servers SET active = ? WHERE id = ?');
    $st->execute([$active ? 1 : 0, $id]);
}

function altar_delete_server(int $id): void
{
    altar_schema();
    foreach (['altar_assignments', 'altar_availability', 'altar_time_off'] as $table) {
        $st = db()->prepare("DELETE FROM {$table} WHERE server_id = ?");
        $st->execute([$id]);
    }
    $st = db()->prepare('DELETE FROM altar_servers WHERE id = ?');
    $st->execute([$id]);
    altar_avail_data(true);
}

/* ---------- Availability: weekly windows ---------- */

/**
 * All availability data, loaded once per request:
 *   ['weekly' => [server_id => [weekday => [start, end]]], 'off' => [server_id => [rows...]]]
 */
function altar_avail_data(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    altar_schema();
    $cache = ['weekly' => [], 'off' => []];
    foreach (db()->query('SELECT * FROM altar_availability ORDER BY weekday ASC')->fetchAll() as $r) {
        $cache['weekly'][(int)$r['server_id']][(int)$r['weekday']] = [$r['start_time'], $r['end_time']];
    }
    foreach (db()->query('SELECT * FROM altar_time_off ORDER BY date_from ASC, id ASC')->fetchAll() as $r) {
        $cache['off'][(int)$r['server_id']][] = $r;
    }
    return $cache;
}

/** Weekly windows of one server: [weekday => [start, end]]. Empty = available any day. */
function altar_server_weekly(int $serverId): array
{
    return altar_avail_data()['weekly'][$serverId] ?? [];
}

/**
 * Reads the posted availability form (avail[0..6][on|from|to]).
 * Returns [days, error]. days = [weekday => [start, end]]. No days ticked = no restriction.
 */
function altar_parse_availability(array $post): array
{
    $days = [];
    $a = is_array($post['avail'] ?? null) ? $post['avail'] : [];
    for ($w = 0; $w <= 6; $w++) {
        $row = is_array($a[$w] ?? null) ? $a[$w] : [];
        if (empty($row['on'])) {
            continue;
        }
        $from = is_string($row['from'] ?? null) ? trim($row['from']) : '';
        $to   = is_string($row['to'] ?? null) ? trim($row['to']) : '';
        $okTime = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($okTime, $from) || !preg_match($okTime, $to)) {
            return [[], 'Please enter a start and end time for ' . ALTAR_DAYS[$w] . '.'];
        }
        if (time_to_minutes($from) >= time_to_minutes($to)) {
            return [[], 'On ' . ALTAR_DAYS[$w] . ' the end time must be after the start time.'];
        }
        $days[$w] = [$from, $to];
    }
    return [$days, null];
}

function altar_save_availability(int $serverId, array $days): void
{
    altar_schema();
    $del = db()->prepare('DELETE FROM altar_availability WHERE server_id = ?');
    $del->execute([$serverId]);
    $ins = db()->prepare('INSERT INTO altar_availability (server_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)');
    foreach ($days as $w => [$from, $to]) {
        $ins->execute([$serverId, (int)$w, $from, $to]);
    }
    altar_avail_data(true);
}

/* ---------- Availability: time off ---------- */

function altar_valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** Returns an error message, or null on success. */
function altar_add_time_off(int $serverId, string $from, string $to, string $reason): ?string
{
    altar_schema();
    if (!altar_valid_date($from) || !altar_valid_date($to)) {
        return 'Please choose a valid start and end date.';
    }
    if ($to < $from) {
        return 'The end date cannot be before the start date.';
    }
    if (mb_strlen($reason) > 200) {
        return 'Please shorten the reason (up to 200 characters).';
    }
    $st = db()->prepare('INSERT INTO altar_time_off (server_id, date_from, date_to, reason, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$serverId, $from, $to, $reason !== '' ? $reason : null, date('Y-m-d H:i:s')]);
    altar_avail_data(true);
    return null;
}

/** Deletes one leave entry; returns the deleted row or null. */
function altar_delete_time_off(int $id): ?array
{
    altar_schema();
    $st = db()->prepare('SELECT * FROM altar_time_off WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $d = db()->prepare('DELETE FROM altar_time_off WHERE id = ?');
        $d->execute([$id]);
        altar_avail_data(true);
    }
    return $row ?: null;
}

/** Leave entries of one server. Past entries are hidden unless $all is true. */
function altar_time_off_list(int $serverId, bool $all = false): array
{
    $out = [];
    $today = date('Y-m-d');
    foreach (altar_avail_data()['off'][$serverId] ?? [] as $r) {
        if ($all || $r['date_to'] >= $today) {
            $out[] = $r;
        }
    }
    return $out;
}

/** Short text for the roster table, e.g. "Sun 06:00 AM – 12:00 PM · Sat 05:00 PM – 09:00 PM". */
function altar_availability_summary(int $serverId): string
{
    $weekly = altar_server_weekly($serverId);
    if (!$weekly) {
        return 'Any day, any time';
    }
    $parts = [];
    foreach ($weekly as $w => [$from, $to]) {
        $parts[] = substr(ALTAR_DAYS[$w], 0, 3) . ' ' . fmt_time($from) . ' – ' . fmt_time($to);
    }
    return implode(' · ', $parts);
}

/**
 * Why a server cannot take an activity because of leave or weekly availability.
 * Returns a phrase that follows "<name> is ..." (e.g. "on leave (Exams)"), or null if available.
 */
function altar_availability_reason(int $serverId, array $act): ?string
{
    $data = altar_avail_data();

    foreach ($data['off'][$serverId] ?? [] as $o) {
        if ($act['date'] >= $o['date_from'] && $act['date'] <= $o['date_to']) {
            $why = trim((string)($o['reason'] ?? ''));
            return 'on leave' . ($why !== '' ? ' (' . $why . ')' : '') . ' on that date';
        }
    }

    $weekly = $data['weekly'][$serverId] ?? [];
    if (!$weekly) {
        return null;
    }
    $w = (int)date('w', strtotime($act['date']));
    if (!isset($weekly[$w])) {
        return 'not available on ' . ALTAR_DAYS[$w] . 's';
    }
    [$from, $to] = $weekly[$w];
    [$s, $e] = altar_span($act);
    if ($s < time_to_minutes($from) || $e > time_to_minutes($to)) {
        return 'only available ' . fmt_time($from) . ' – ' . fmt_time($to) . ' on ' . ALTAR_DAYS[$w] . 's';
    }
    return null;
}

/* ---------- Activities ---------- */

function altar_span(array $a): array
{
    if ($a['type'] === 'event') {
        return event_span($a['start'], $a['end']);
    }
    $s = time_to_minutes($a['start']);
    return [$s, $s + ALTAR_SERVICE_MINUTES];
}

function altar_normalize_schedule(array $r): array
{
    return [
        'key'   => 'schedule:' . $r['src_id'],
        'type'  => 'schedule',
        'id'    => (int)$r['src_id'],
        'date'  => $r['event_date'],
        'start' => $r['event_time'],
        'end'   => null,
        'venue' => $r['venue'],
        'title' => $r['full_name'],
        'label' => SERVICE_LABELS[$r['service_type']] ?? $r['service_type'],
    ];
}

function altar_normalize_event(array $r): array
{
    return [
        'key'   => 'event:' . $r['id'],
        'type'  => 'event',
        'id'    => (int)$r['id'],
        'date'  => $r['event_date'],
        'start' => $r['start_time'],
        'end'   => $r['end_time'] ?? null,
        'venue' => $r['venue'],
        'title' => $r['title'],
        'label' => EVENT_TYPES[$r['event_type']] ?? $r['event_type'],
    ];
}

const ALTAR_SCHEDULE_SQL =
    "SELECT s.id AS src_id, s.event_date, s.event_time, s.venue, r.full_name, r.service_type
     FROM schedules s JOIN service_requests r ON r.id = s.request_id
     WHERE r.archived_at IS NULL AND r.status <> 'declined'";

function altar_activity(string $type, int $id): ?array
{
    if ($type === 'schedule') {
        $st = db()->prepare(ALTAR_SCHEDULE_SQL . ' AND s.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? altar_normalize_schedule($r) : null;
    }
    if ($type === 'event') {
        $st = db()->prepare("SELECT * FROM events WHERE id = ? AND status <> 'cancelled'");
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? altar_normalize_event($r) : null;
    }
    return null;
}

/** All activities from $from (Y-m-d) onwards, soonest first, each with its assigned servers. */
function altar_upcoming_activities(string $from, int $limit = 60): array
{
    $list = [];
    $st = db()->prepare(ALTAR_SCHEDULE_SQL . ' AND s.event_date >= ?');
    $st->execute([$from]);
    foreach ($st->fetchAll() as $r) {
        $list[] = altar_normalize_schedule($r);
    }
    $st = db()->prepare("SELECT * FROM events WHERE status <> 'cancelled' AND event_date >= ?");
    $st->execute([$from]);
    foreach ($st->fetchAll() as $r) {
        $list[] = altar_normalize_event($r);
    }
    usort($list, fn($a, $b) => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);
    $list = array_slice($list, 0, $limit);

    $by = altar_assigned_by_activity();
    foreach ($list as &$a) {
        $a['servers'] = $by[$a['key']] ?? [];
    }
    return $list;
}

function altar_assigned_by_activity(): array
{
    $rows = db()->query(
        'SELECT a.id AS assignment_id, a.source_type, a.source_id, a.server_id, v.full_name, v.active
         FROM altar_assignments a JOIN altar_servers v ON v.id = a.server_id
         ORDER BY v.full_name ASC'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['source_type'] . ':' . $r['source_id']][] = $r;
    }
    return $out;
}

/* ---------- Conflict detection ---------- */

/** Every live assignment (any server) on a date, as activity + server_id + server name. */
function altar_assignments_on(string $date): array
{
    $out = [];

    $st = db()->prepare(
        "SELECT a.server_id, v.full_name AS server_name, s.id AS src_id, s.event_date, s.event_time, s.venue, r.full_name, r.service_type
         FROM altar_assignments a
         JOIN altar_servers v ON v.id = a.server_id
         JOIN schedules s ON a.source_type = 'schedule' AND s.id = a.source_id
         JOIN service_requests r ON r.id = s.request_id
         WHERE s.event_date = ? AND r.archived_at IS NULL AND r.status <> 'declined'"
    );
    $st->execute([$date]);
    foreach ($st->fetchAll() as $r) {
        $out[] = ['server_id' => (int)$r['server_id'], 'server_name' => $r['server_name']] + altar_normalize_schedule($r);
    }

    $st = db()->prepare(
        "SELECT a.server_id, v.full_name AS server_name, e.*
         FROM altar_assignments a
         JOIN altar_servers v ON v.id = a.server_id
         JOIN events e ON a.source_type = 'event' AND e.id = a.source_id
         WHERE e.event_date = ? AND e.status <> 'cancelled'"
    );
    $st->execute([$date]);
    foreach ($st->fetchAll() as $r) {
        $out[] = ['server_id' => (int)$r['server_id'], 'server_name' => $r['server_name']] + altar_normalize_event($r);
    }
    return $out;
}

function altar_overlap(array $a, array $b): bool
{
    if ($a['date'] !== $b['date']) {
        return false;
    }
    [$s1, $e1] = altar_span($a);
    [$s2, $e2] = altar_span($b);
    $buf = ALTAR_BUFFER_MINUTES;
    return $s1 < $e2 + $buf && $s2 < $e1 + $buf;
}

/**
 * Returns the clashing activity (with 'server_name') or null. The activity itself is ignored.
 * Pass $rows (from altar_assignments_on for that date) to avoid a query per call.
 */
function altar_server_conflict(int $serverId, array $activity, ?array $rows = null): ?array
{
    $rows ??= altar_assignments_on($activity['date']);
    foreach ($rows as $row) {
        if ($row['server_id'] === $serverId && $row['key'] !== $activity['key'] && altar_overlap($activity, $row)) {
            return $row;
        }
    }
    return null;
}

function altar_conflict_message(array $c): string
{
    return $c['server_name'] . ' is already assigned to ' . $c['title'] . ' (' . $c['label'] . ') at '
        . fmt_time($c['start']) . ($c['end'] ? ' – ' . fmt_time($c['end']) : '') . ' at ' . $c['venue'] . ' on that date.';
}

/**
 * Whether one server can take one activity. Returns null when they can, otherwise a short reason
 * (leave, weekly availability, or a clash with another assignment).
 */
function altar_block_reason(array $server, array $act, ?array $rows = null): ?string
{
    $sid = (int)$server['id'];
    if ($why = altar_availability_reason($sid, $act)) {
        return $why;
    }
    if ($c = altar_server_conflict($sid, $act, $rows)) {
        return 'already assigned to ' . $c['title'] . ' at ' . fmt_time($c['start']);
    }
    return null;
}

/** Re-checks servers already assigned to one activity after its date/time/venue changed. */
function altar_recheck_activity(string $type, int $id): array
{
    $act = altar_activity($type, $id);
    if (!$act) {
        return [];
    }
    $rows = altar_assignments_on($act['date']);
    $problems = [];
    foreach ($rows as $row) {
        if ($row['key'] !== $act['key']) {
            continue;
        }
        if ($c = altar_server_conflict($row['server_id'], $act, $rows)) {
            $problems['c' . $row['server_id']] = altar_conflict_message($c);
        }
        if ($why = altar_availability_reason($row['server_id'], $act)) {
            $problems['a' . $row['server_id']] = $row['server_name'] . ' is ' . $why . '.';
        }
    }
    return array_values($problems);
}

/** Same, for a service schedule looked up by request id. */
function altar_recheck_request(int $requestId): array
{
    $st = db()->prepare('SELECT id FROM schedules WHERE request_id = ?');
    $st->execute([$requestId]);
    $sid = (int)$st->fetchColumn();
    return $sid ? altar_recheck_activity('schedule', $sid) : [];
}

/** Upcoming assignments of one server that now clash with their leave or weekly availability. */
function altar_server_problems(int $serverId): array
{
    $srv = altar_get_server($serverId);
    if (!$srv) {
        return [];
    }
    $out = [];
    foreach (altar_server_upcoming($serverId, date('Y-m-d')) as $a) {
        if ($why = altar_availability_reason($serverId, $a)) {
            $out[] = $srv['full_name'] . ' is ' . $why . ' but is assigned to ' . $a['title'] . ' on ' . fmt_date($a['date']) . '.';
        }
    }
    return $out;
}

/* ---------- Assign / unassign ---------- */

/** Returns an error message, or null on success. */
function altar_assign(int $serverId, string $type, int $sourceId): ?string
{
    $server = altar_get_server($serverId);
    if (!$server) {
        return 'Please choose an altar server.';
    }
    if (!(int)$server['active']) {
        return $server['full_name'] . ' is marked inactive. Reactivate them first.';
    }
    $act = altar_activity($type, $sourceId);
    if (!$act) {
        return 'That activity no longer exists or was cancelled.';
    }
    $dup = db()->prepare('SELECT id FROM altar_assignments WHERE server_id = ? AND source_type = ? AND source_id = ?');
    $dup->execute([$serverId, $type, $sourceId]);
    if ($dup->fetch()) {
        return $server['full_name'] . ' is already assigned to this activity.';
    }
    if ($why = altar_availability_reason($serverId, $act)) {
        return 'Unavailable: ' . $server['full_name'] . ' is ' . $why . '.';
    }
    if ($c = altar_server_conflict($serverId, $act)) {
        return 'Conflict: ' . altar_conflict_message($c);
    }
    $st = db()->prepare('INSERT INTO altar_assignments (server_id, source_type, source_id, created_at) VALUES (?, ?, ?, ?)');
    $st->execute([$serverId, $type, $sourceId, date('Y-m-d H:i:s')]);
    return null;
}

function altar_unassign(int $assignmentId): ?array
{
    $st = db()->prepare(
        'SELECT a.id, a.source_type, a.source_id, v.full_name FROM altar_assignments a
         JOIN altar_servers v ON v.id = a.server_id WHERE a.id = ?'
    );
    $st->execute([$assignmentId]);
    $row = $st->fetch();
    if ($row) {
        $d = db()->prepare('DELETE FROM altar_assignments WHERE id = ?');
        $d->execute([$assignmentId]);
    }
    return $row ?: null;
}

/** Call after deleting a schedule or event. */
function altar_purge_activity(string $type, int $sourceId): void
{
    $st = db()->prepare('DELETE FROM altar_assignments WHERE source_type = ? AND source_id = ?');
    $st->execute([$type, $sourceId]);
}

function altar_server_upcoming(int $serverId, string $from): array
{
    $out = [];
    foreach (altar_upcoming_activities($from, 500) as $a) {
        foreach ($a['servers'] as $s) {
            if ((int)$s['server_id'] === $serverId) {
                $out[] = $a;
            }
        }
    }
    return $out;
}

/* ---------- Auto-assignment ---------- */

/** How many upcoming assignments each server already has: [server_id => count]. */
function altar_load_counts(string $from): array
{
    $load = [];
    $queries = [
        "SELECT a.server_id, COUNT(*) AS n FROM altar_assignments a
         JOIN schedules s ON a.source_type = 'schedule' AND s.id = a.source_id
         WHERE s.event_date >= ? GROUP BY a.server_id",
        "SELECT a.server_id, COUNT(*) AS n FROM altar_assignments a
         JOIN events e ON a.source_type = 'event' AND e.id = a.source_id
         WHERE e.event_date >= ? AND e.status <> 'cancelled' GROUP BY a.server_id",
    ];
    foreach ($queries as $sql) {
        $st = db()->prepare($sql);
        $st->execute([$from]);
        foreach ($st->fetchAll() as $r) {
            $load[(int)$r['server_id']] = ($load[(int)$r['server_id']] ?? 0) + (int)$r['n'];
        }
    }
    return $load;
}

/**
 * Fills one activity up to $slots servers. $load is shared between calls so a batch run spreads
 * the work. Returns ['assigned' => [names], 'short' => servers still missing, 'error' => ?string].
 */
function altar_auto_assign_activity(array $act, int $slots, array &$load): array
{
    $already = altar_assigned_by_activity()[$act['key']] ?? [];
    $need = $slots - count($already);
    if ($need <= 0) {
        return ['assigned' => [], 'short' => 0, 'error' => null];
    }

    $rows = altar_assignments_on($act['date']);
    $taken = array_map(fn($r) => (int)$r['server_id'], $already);

    $candidates = [];
    foreach (altar_servers(true) as $s) {
        if (in_array((int)$s['id'], $taken, true)) {
            continue;
        }
        if (altar_block_reason($s, $act, $rows) === null) {
            $candidates[] = $s;
        }
    }
    usort($candidates, fn($a, $b) => [$load[(int)$a['id']] ?? 0, $a['full_name']] <=> [$load[(int)$b['id']] ?? 0, $b['full_name']]);

    $names = [];
    foreach ($candidates as $s) {
        if (count($names) >= $need) {
            break;
        }
        if (altar_assign((int)$s['id'], $act['type'], $act['id']) === null) {
            $names[] = $s['full_name'];
            $load[(int)$s['id']] = ($load[(int)$s['id']] ?? 0) + 1;
        }
    }
    return ['assigned' => $names, 'short' => max(0, $need - count($names)), 'error' => null];
}

/** Auto-assigns one activity. */
function altar_auto_assign(string $type, int $sourceId, int $slots): array
{
    $act = altar_activity($type, $sourceId);
    if (!$act) {
        return ['assigned' => [], 'short' => 0, 'error' => 'That activity no longer exists or was cancelled.'];
    }
    $load = altar_load_counts(date('Y-m-d'));
    return altar_auto_assign_activity($act, max(1, min(10, $slots)), $load);
}

/**
 * Auto-assigns every upcoming activity that has fewer than $slots servers.
 * Returns ['activities' => n filled or touched, 'assigned' => total assignments, 'unfilled' => [titles]].
 */
function altar_auto_assign_all(int $slots, int $limit = 60): array
{
    $slots = max(1, min(10, $slots));
    $load = altar_load_counts(date('Y-m-d'));
    $touched = 0;
    $total = 0;
    $unfilled = [];
    foreach (altar_upcoming_activities(date('Y-m-d'), $limit) as $act) {
        $r = altar_auto_assign_activity($act, $slots, $load);
        if ($r['assigned']) {
            $touched++;
            $total += count($r['assigned']);
        }
        if ($r['short'] > 0) {
            $unfilled[] = $act['title'] . ' (' . fmt_date($act['date']) . ')';
        }
    }
    return ['activities' => $touched, 'assigned' => $total, 'unfilled' => $unfilled];
}