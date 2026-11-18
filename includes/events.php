<?php
/**
 * Parish Events Management
 *
 * Events are parish-wide activities (special Masses, fiestas, novenas, seminars, outreach)
 * that are not tied to one parishioner's service request. They sit beside the service
 * schedules (schedules table) and share the same venue conflict check.
 *
 * Table: events  (created automatically by ensure_schema() in db.php)
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

const EVENT_TYPES = [
    'mass'     => 'Special Mass',
    'feast'    => 'Feast / Fiesta',
    'novena'   => 'Novena / Devotion',
    'seminar'  => 'Seminar / Formation',
    'outreach' => 'Outreach / Charity',
    'meeting'  => 'Parish Meeting',
    'other'    => 'Other',
];

const EVENT_STATUS_LABELS = [
    'draft'     => 'Draft',
    'published' => 'Published',
    'cancelled' => 'Cancelled',
];

/** Badge class (from the existing stylesheet) for each event status. */
const EVENT_STATUS_BADGE = [
    'draft'     => 'badge-pending',
    'published' => 'badge-approved',
    'cancelled' => 'badge-declined',
];

/** An event with no end time is treated as lasting this long when checking conflicts. */
const EVENT_DEFAULT_MINUTES = 60;

/* ---------- Conflict detection ---------- */

/** [start, end] in minutes since midnight. A missing or invalid end time falls back to the default length. */
function event_span(string $start, ?string $end): array
{
    $s = time_to_minutes($start);
    $e = ($end !== null && $end !== '') ? time_to_minutes($end) : $s + EVENT_DEFAULT_MINUTES;
    if ($e <= $s) {
        $e = $s + EVENT_DEFAULT_MINUTES;
    }
    return [$s, $e];
}

/**
 * Looks for something already using the same venue at an overlapping time on that date.
 * Checks other events (not cancelled) and the service schedules (baptisms, weddings, ...).
 * Returns ['name' => ..., 'kind' => 'event'|'service', 'label' => ..., 'time' => ...] or null.
 */
function event_conflict(string $date, string $start, ?string $end, string $venue, int $exceptEventId = 0): ?array
{
    [$s, $e] = event_span($start, $end);

    $st = db()->prepare(
        "SELECT title, start_time, end_time FROM events
         WHERE status <> 'cancelled' AND event_date = ? AND LOWER(venue) = LOWER(?) AND id <> ?"
    );
    $st->execute([$date, $venue, $exceptEventId]);
    foreach ($st->fetchAll() as $r) {
        [$s2, $e2] = event_span($r['start_time'], $r['end_time']);
        if ($s < $e2 && $s2 < $e) {
            return ['name' => $r['title'], 'kind' => 'event', 'label' => 'Event', 'time' => fmt_time($r['start_time'])];
        }
    }

    $st = db()->prepare(
        "SELECT r.full_name, r.service_type, s.event_time
         FROM schedules s JOIN service_requests r ON r.id = s.request_id
         WHERE r.archived_at IS NULL AND r.status <> 'declined'
           AND s.event_date = ? AND LOWER(s.venue) = LOWER(?)"
    );
    $st->execute([$date, $venue]);
    foreach ($st->fetchAll() as $r) {
        [$s2, $e2] = event_span($r['event_time'], null);
        if ($s < $e2 && $s2 < $e) {
            return [
                'name'  => $r['full_name'],
                'kind'  => 'service',
                'label' => SERVICE_LABELS[$r['service_type']] ?? $r['service_type'],
                'time'  => fmt_time($r['event_time']),
            ];
        }
    }
    return null;
}

function event_conflict_message(array $c, string $venue): string
{
    return 'Conflict: ' . $c['name'] . ' (' . $c['label'] . ') is already booked at ' . $venue
        . ' around ' . $c['time'] . ' on that date. Choose another time or venue.';
}

/* ---------- Validation ---------- */

/** Returns an error message, or null when $d (trimmed form values) is valid. */
function validate_event(array $d): ?string
{
    if (mb_strlen($d['title']) < 3 || mb_strlen($d['title']) > 150) {
        return 'Please enter an event title (3 to 150 characters).';
    }
    if (!isset(EVENT_TYPES[$d['event_type']])) {
        return 'Please choose an event type.';
    }
    $date = DateTime::createFromFormat('Y-m-d', $d['event_date']);
    if (!$date || $date->format('Y-m-d') !== $d['event_date']) {
        return 'Please enter a valid date.';
    }
    $t = DateTime::createFromFormat('H:i', $d['start_time']);
    if (!$t || $t->format('H:i') !== $d['start_time']) {
        return 'Please enter a valid start time.';
    }
    if ($d['end_time'] !== '') {
        $t2 = DateTime::createFromFormat('H:i', $d['end_time']);
        if (!$t2 || $t2->format('H:i') !== $d['end_time']) {
            return 'Please enter a valid end time.';
        }
        if ($d['end_time'] <= $d['start_time']) {
            return 'The end time must be later than the start time.';
        }
    }
    if ($d['venue'] === '' || mb_strlen($d['venue']) > 120) {
        return 'Please enter a venue (up to 120 characters).';
    }
    if (mb_strlen($d['description']) > 1000) {
        return 'Please shorten the description (up to 1000 characters).';
    }
    if (!in_array($d['status'], ['draft', 'published'], true)) {
        return 'Please choose Draft or Published.';
    }
    return null;
}

/* ---------- CRUD ---------- */

function get_event(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Inserts (when $id is 0) or updates an event. Returns its id. */
function save_event(int $id, array $d, ?int $userId): int
{
    $now = date('Y-m-d H:i:s');
    $end = $d['end_time'] !== '' ? $d['end_time'] : null;
    $desc = $d['description'] !== '' ? $d['description'] : null;

    if ($id > 0) {
        $st = db()->prepare(
            'UPDATE events SET title = ?, event_type = ?, event_date = ?, start_time = ?, end_time = ?,
                    venue = ?, description = ?, status = ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([$d['title'], $d['event_type'], $d['event_date'], $d['start_time'], $end, $d['venue'], $desc, $d['status'], $now, $id]);
        return $id;
    }

    $st = db()->prepare(
        'INSERT INTO events (title, event_type, event_date, start_time, end_time, venue, description, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$d['title'], $d['event_type'], $d['event_date'], $d['start_time'], $end, $d['venue'], $desc, $d['status'], $userId, $now, $now]);
    return (int)db()->lastInsertId();
}

function set_event_status(int $id, string $status): void
{
    $st = db()->prepare('UPDATE events SET status = ?, updated_at = ? WHERE id = ?');
    $st->execute([$status, date('Y-m-d H:i:s'), $id]);
}

function delete_event(int $id): void
{
    $st = db()->prepare('DELETE FROM events WHERE id = ?');
    $st->execute([$id]);
}

/* ---------- Queries ---------- */

/**
 * Admin list. $upcoming = today and later (soonest first), otherwise the 20 most recent past events.
 */
function events_admin_list(bool $upcoming): array
{
    $sql = 'SELECT * FROM events WHERE event_date '
        . ($upcoming ? '>= ? ORDER BY event_date ASC, start_time ASC' : '< ? ORDER BY event_date DESC, start_time DESC LIMIT 20');
    $st = db()->prepare($sql);
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

/**
 * What parishioners see: upcoming events that are published, plus cancelled ones
 * (so people who planned to attend can see the cancellation). Drafts are never shown.
 */
function events_public_upcoming(int $limit = 0): array
{
    $sql = "SELECT * FROM events WHERE status IN ('published', 'cancelled') AND event_date >= ?
            ORDER BY event_date ASC, start_time ASC" . ($limit > 0 ? ' LIMIT ' . (int)$limit : '');
    $st = db()->prepare($sql);
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

/** Upcoming wedding schedules (approved/completed requests that already have a date). */
function weddings_upcoming(int $limit = 0): array
{
    $sql = "SELECT s.id AS schedule_id, s.event_date, s.event_time, s.venue, s.officiant, r.full_name
            FROM schedules s JOIN service_requests r ON r.id = s.request_id
            WHERE r.service_type = 'wedding' AND r.status IN ('approved', 'completed')
              AND r.archived_at IS NULL AND s.event_date >= ?
            ORDER BY s.event_date ASC, s.event_time ASC" . ($limit > 0 ? ' LIMIT ' . (int)$limit : '');
    $st = db()->prepare($sql);
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

function count_upcoming_events(): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM events WHERE status = 'published' AND event_date >= ?");
    $st->execute([date('Y-m-d')]);
    return (int)$st->fetchColumn();
}

/* ---------- Assigned altar servers (shown to logged-in parishioners) ---------- */

/** How altar server names appear to parishioners: 'full' (Juan Dela Cruz) or 'initials' (Juan D.). */
const ALTAR_PUBLIC_NAME_STYLE = 'full';

function altar_public_name(string $fullName): string
{
    $fullName = trim($fullName);
    if (ALTAR_PUBLIC_NAME_STYLE !== 'initials') {
        return $fullName;
    }
    $parts = preg_split('/\s+/', $fullName) ?: [];
    if (count($parts) < 2) {
        return $fullName;
    }
    $last = array_pop($parts);
    return implode(' ', $parts) . ' ' . mb_substr($last, 0, 1) . '.';
}

/**
 * Names of the active altar servers assigned to each activity, names only (no phone or notes):
 * ['event:12' => ['Ana', 'Ben'], 'schedule:5' => [...]].
 */
function altar_servers_public_map(): array
{
    $out = [];
    try {
        $rows = db()->query(
            'SELECT a.source_type, a.source_id, v.full_name
             FROM altar_assignments a JOIN altar_servers v ON v.id = a.server_id
             WHERE v.active = 1 ORDER BY v.full_name ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $r) {
        $out[$r['source_type'] . ':' . $r['source_id']][] = altar_public_name($r['full_name']);
    }
    return $out;
}

/** One line listing the assigned altar servers. Prints nothing when there are none. */
function render_altar_servers_line(array $names): void
{
    if (!$names) {
        return;
    }
    ?>
    <p class="small"><span class="muted">Altar servers:</span> <?= e(implode(', ', $names)) ?></p>
    <?php
}

/* ---------- Shared display ---------- */

function event_time_range(array $ev): string
{
    $t = fmt_time($ev['start_time']);
    return !empty($ev['end_time']) ? $t . ' – ' . fmt_time($ev['end_time']) : $t;
}

/** One event as a card (used on the events page, the home page and My Requests). */
function render_event_card(array $ev): void
{
    $ts = strtotime($ev['event_date']);
    $cancelled = ($ev['status'] ?? '') === 'cancelled';
    ?>
    <article class="card ev<?= $cancelled ? ' is-cancelled' : '' ?>">
      <div class="ev-date" aria-hidden="true">
        <span class="ev-day"><?= e(date('j', $ts)) ?></span>
        <span class="ev-mon"><?= e(date('M', $ts)) ?></span>
      </div>
      <div class="ev-body">
        <div class="ev-head">
          <h3><?= e($ev['title']) ?></h3>
          <span class="badge badge-completed"><?= e(EVENT_TYPES[$ev['event_type']] ?? $ev['event_type']) ?></span>
          <?php if ($cancelled): ?><span class="badge badge-declined">Cancelled</span><?php endif; ?>
        </div>
        <p class="small"><strong><?= e(date('l, F j, Y', $ts)) ?></strong> · <?= e(event_time_range($ev)) ?></p>
        <p class="muted small"><?= e($ev['venue']) ?></p>
        <?php if (!$cancelled && !empty($ev['altar_servers'])) { render_altar_servers_line($ev['altar_servers']); } ?>
        <?php if (!empty($ev['description'])): ?><p class="small ev-desc"><?= nl2br(e($ev['description'])) ?></p><?php endif; ?>
      </div>
    </article>
    <?php
}