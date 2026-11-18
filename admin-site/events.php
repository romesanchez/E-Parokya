<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/altar.php';
$me = require_admin();

$self = url('admin/events.php');
$error = null;
$blank = ['title' => '', 'event_type' => 'mass', 'event_date' => '', 'start_time' => '', 'end_time' => '', 'venue' => '', 'description' => '', 'status' => 'published'];
$form = $blank;
$editId = (int)(filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (int)(filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $ev = $id ? get_event($id) : null;

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($act === 'save') {
        $editId = $id;
        foreach ($form as $k => $_) {
            $form[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        }
        if ($id && !$ev) {
            $error = 'That event no longer exists.';
        } elseif ($err = validate_event($form)) {
            $error = $err;
        } elseif ($c = event_conflict($form['event_date'], $form['start_time'], $form['end_time'], $form['venue'], $id)) {
            $error = event_conflict_message($c, $form['venue']);
        } else {
            // Editing a cancelled event keeps it cancelled until it is restored.
            if ($ev && $ev['status'] === 'cancelled') {
                $form['status'] = 'cancelled';
            }
            $newId = save_event($id, $form, (int)$me['id']);
            log_action($id ? 'event_updated' : 'event_created', "#{$newId} {$form['title']}: {$form['event_date']} {$form['start_time']} @ {$form['venue']}");
            admin_flash('ok', $id ? 'Event updated.' : 'Event added.');
                        if ($id && ($problems = altar_recheck_activity('event', $newId))) {
                admin_flash('err', 'Event updated, but altar server conflicts need attention: ' . implode(' ', $problems) . ' Review them under Altar Servers.');
            }
            redirect($self);
        }
    } elseif (!$ev) {
        $error = 'That event no longer exists.';
    } elseif ($act === 'cancel') {
        set_event_status($id, 'cancelled');
        log_action('event_cancelled', "#{$id} {$ev['title']}");
        admin_flash('ok', "\"{$ev['title']}\" was cancelled. Parishioners will see it marked as cancelled.");
        redirect($self);
    } elseif ($act === 'restore') {
        if ($c = event_conflict($ev['event_date'], $ev['start_time'], $ev['end_time'], $ev['venue'], $id)) {
            admin_flash('err', event_conflict_message($c, $ev['venue']));
        } else {
            set_event_status($id, 'published');
            log_action('event_restored', "#{$id} {$ev['title']}");
            admin_flash('ok', "\"{$ev['title']}\" is published again.");
        }
        redirect($self);
    } elseif ($act === 'delete') {
        delete_event($id);
        altar_purge_activity('event', $id);
        log_action('event_deleted', "#{$id} {$ev['title']}");
        admin_flash('ok', 'Event deleted.');
        redirect($self);
    }
}

if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($ev = get_event($editId)) {
        $form = [
            'title' => $ev['title'], 'event_type' => $ev['event_type'], 'event_date' => $ev['event_date'],
            'start_time' => $ev['start_time'], 'end_time' => $ev['end_time'] ?? '', 'venue' => $ev['venue'],
            'description' => $ev['description'] ?? '', 'status' => $ev['status'] === 'cancelled' ? 'published' : $ev['status'],
        ];
    } else {
        $editId = 0;
    }
}

$upcoming = events_admin_list(true);
$past = events_admin_list(false);

// A small POST button used in the tables below.
$btn = function (int $id, string $action, string $label, string $cls, string $confirm = '') use ($self): string {
    return '<form method="post" action="' . e($self) . '" style="display:inline"'
        . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . e($action) . '">'
        . '<button class="act ' . e($cls) . '" type="submit">' . e($label) . '</button></form>';
};

admin_start('Events', 'events');
?>
<div class="container dash wide">
  <h1 class="page-title left">Parish Events</h1>
  <p class="muted small">Add feasts, special Masses, novenas, seminars and other parish activities. Published events appear on the public Events page and in parishioners' accounts. Venue conflicts with other events and with scheduled services are checked automatically.</p>

  <form method="post" action="<?= e($self) ?>" class="card sched-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)$editId ?>">
    <div class="field full"><h2 class="h2" style="margin:0"><?= $editId ? 'Edit event' : 'New event' ?></h2></div>
    <div class="field full"><label for="title">Title</label><input id="title" name="title" required maxlength="150" value="<?= e($form['title']) ?>"></div>
    <div class="field"><label for="event_type">Type</label>
      <select id="event_type" name="event_type">
        <?php foreach (EVENT_TYPES as $k => $label): ?><option value="<?= e($k) ?>" <?= $form['event_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="event_date">Date</label><input id="event_date" name="event_date" type="date" required value="<?= e($form['event_date']) ?>"></div>
    <div class="field"><label for="start_time">Starts</label><input id="start_time" name="start_time" type="time" required value="<?= e($form['start_time']) ?>"></div>
    <div class="field"><label for="end_time">Ends <span class="muted normal">(optional)</span></label><input id="end_time" name="end_time" type="time" value="<?= e($form['end_time']) ?>"></div>
    <div class="field"><label for="venue">Venue</label>
      <input id="venue" name="venue" list="venues" required maxlength="120" value="<?= e($form['venue']) ?>">
      <datalist id="venues"><option value="Main Church"><option value="Side Chapel"><option value="Parish Hall"><option value="Cemetery Chapel"><option value="Church Grounds"></datalist></div>
    <div class="field"><label for="status">Visibility</label>
      <select id="status" name="status">
        <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>Published (visible to parishioners)</option>
        <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>Draft (staff only)</option>
      </select></div>
    <div class="field full"><label for="description">Description <span class="muted normal">(optional)</span></label>
      <textarea id="description" name="description" rows="3" maxlength="1000"><?= e($form['description']) ?></textarea></div>
    <?php if ($error): ?><p class="alert-error full" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="full">
      <button type="submit" class="btn btn-success"><?= $editId ? 'Save changes' : 'Add event' ?></button>
      <?php if ($editId): ?><a class="btn btn-outline" href="<?= e($self) ?>">Cancel editing</a><?php endif; ?>
    </div>
  </form>

  <?php foreach ([['Upcoming events', $upcoming, true], ['Recent past events', $past, false]] as [$heading, $list, $live]): ?>
    <h2 class="h2"><?= e($heading) ?></h2>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Time</th><th>Event</th><th>Venue</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$list): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
        <?php foreach ($list as $r): $i = (int)$r['id']; ?>
          <tr>
            <td><?= e(fmt_date($r['event_date'])) ?></td>
            <td><?= e(event_time_range($r)) ?></td>
            <td><strong><?= e($r['title']) ?></strong><br><span class="muted"><?= e(EVENT_TYPES[$r['event_type']] ?? $r['event_type']) ?></span></td>
            <td><?= e($r['venue']) ?></td>
            <td><span class="badge <?= e(EVENT_STATUS_BADGE[$r['status']] ?? '') ?>"><?= e(EVENT_STATUS_LABELS[$r['status']] ?? $r['status']) ?></span></td>
            <td style="white-space:nowrap">
              <?php if ($live): ?>
                <a class="act act-edit" href="<?= e($self . '?edit=' . $i) ?>">Edit</a>
                <?= $r['status'] === 'cancelled'
                    ? $btn($i, 'restore', 'Restore', 'act-edit')
                    : $btn($i, 'cancel', 'Cancel event', 'act-edit', 'Cancel this event? Parishioners will see it marked as cancelled.') ?>
              <?php endif; ?>
              <?= $btn($i, 'delete', 'Delete', 'act-delete', 'Delete this event permanently?') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>
<?php admin_end();