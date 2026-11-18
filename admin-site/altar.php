<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/altar.php';
$me = require_admin();

$self = url('admin/altar.php');
$error = null;
$availError = null;
$offError = null;
$form = ['full_name' => '', 'phone' => '', 'notes' => ''];
$editId = (int)(filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0);
$availForm = null; // posted availability, kept when validation fails

/** Default form state: nothing ticked, sensible start/end times. */
function altar_blank_avail(): array
{
    $out = [];
    for ($w = 0; $w <= 6; $w++) {
        $out[$w] = ['on' => false, 'from' => '06:00', 'to' => '21:00'];
    }
    return $out;
}

/** Slots field from a POST (1 to 10). */
function altar_post_slots(): int
{
    $n = (int)(filter_var($_POST['slots'] ?? null, FILTER_VALIDATE_INT) ?: ALTAR_DEFAULT_SLOTS);
    return max(1, min(10, $n));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (int)(filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0);

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($act === 'save_server') {
        $editId = $id;
        foreach ($form as $k => $_) {
            $form[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        }
        if ($id && !altar_get_server($id)) {
            $error = 'That altar server no longer exists.';
        } elseif ($err = altar_validate_server($form)) {
            $error = $err;
        } else {
            $newId = altar_save_server($id, $form);
            log_action($id ? 'altar_server_updated' : 'altar_server_added', "#{$newId} {$form['full_name']}");
            admin_flash('ok', $id ? 'Altar server updated.' : 'Altar server added. You can now set their availability below.');
            redirect($id ? $self : $self . '?edit=' . $newId);
        }
    } elseif ($act === 'set_active') {
        $srv = altar_get_server($id);
        if (!$srv) {
            $error = 'That altar server no longer exists.';
        } else {
            $on = !empty($_POST['active']);
            altar_set_active($id, $on);
            log_action($on ? 'altar_server_activated' : 'altar_server_deactivated', "#{$id} {$srv['full_name']}");
            admin_flash('ok', $srv['full_name'] . ($on ? ' is active again.' : ' was marked inactive.'));
            redirect($self);
        }
    } elseif ($act === 'delete_server') {
        $srv = altar_get_server($id);
        if (!$srv) {
            $error = 'That altar server no longer exists.';
        } else {
            altar_delete_server($id);
            log_action('altar_server_deleted', "#{$id} {$srv['full_name']}");
            admin_flash('ok', $srv['full_name'] . ' was removed from the roster.');
            redirect($self);
        }
    } elseif ($act === 'save_availability') {
        $editId = $id;
        $srv = altar_get_server($id);
        if (!$srv) {
            $availError = 'That altar server no longer exists.';
        } else {
            [$days, $err] = altar_parse_availability($_POST);
            if ($err) {
                $availError = $err;
                $availForm = altar_blank_avail();
                foreach ((array)($_POST['avail'] ?? []) as $w => $row) {
                    if (isset($availForm[(int)$w]) && is_array($row)) {
                        $availForm[(int)$w] = [
                            'on'   => !empty($row['on']),
                            'from' => is_string($row['from'] ?? null) ? $row['from'] : '06:00',
                            'to'   => is_string($row['to'] ?? null) ? $row['to'] : '21:00',
                        ];
                    }
                }
            } else {
                altar_save_availability($id, $days);
                log_action('altar_availability_saved', "#{$id} {$srv['full_name']}: " . ($days ? count($days) . ' day(s)' : 'any time'));
                $problems = altar_server_problems($id);
                if ($problems) {
                    admin_flash('err', 'Availability saved, but some existing assignments no longer fit: ' . implode(' ', $problems));
                } else {
                    admin_flash('ok', 'Availability saved for ' . $srv['full_name'] . '.');
                }
                redirect($self . '?edit=' . $id);
            }
        }
    } elseif ($act === 'add_time_off') {
        $editId = $id;
        $srv = altar_get_server($id);
        $from = is_string($_POST['date_from'] ?? null) ? trim($_POST['date_from']) : '';
        $to = is_string($_POST['date_to'] ?? null) ? trim($_POST['date_to']) : '';
        if ($to === '') {
            $to = $from;
        }
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        if (!$srv) {
            $offError = 'That altar server no longer exists.';
        } elseif ($err = altar_add_time_off($id, $from, $to, $reason)) {
            $offError = $err;
        } else {
            log_action('altar_time_off_added', "#{$id} {$srv['full_name']}: {$from} to {$to}");
            $problems = altar_server_problems($id);
            if ($problems) {
                admin_flash('err', 'Leave saved, but some existing assignments now clash: ' . implode(' ', $problems));
            } else {
                admin_flash('ok', 'Leave saved for ' . $srv['full_name'] . '.');
            }
            redirect($self . '?edit=' . $id);
        }
    } elseif ($act === 'delete_time_off') {
        $row = altar_delete_time_off((int)(filter_var($_POST['off_id'] ?? null, FILTER_VALIDATE_INT) ?: 0));
        if ($row) {
            log_action('altar_time_off_removed', "#{$row['server_id']}: {$row['date_from']} to {$row['date_to']}");
            admin_flash('ok', 'Leave entry removed.');
            redirect($self . '?edit=' . (int)$row['server_id']);
        }
        admin_flash('err', 'That leave entry no longer exists.');
        redirect($self);
    } elseif ($act === 'assign') {
        $serverId = (int)(filter_var($_POST['server_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        $type = is_string($_POST['source_type'] ?? null) ? $_POST['source_type'] : '';
        $sourceId = (int)(filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if (!in_array($type, ['schedule', 'event'], true) || !$sourceId) {
            admin_flash('err', 'Invalid activity.');
        } elseif ($msg = altar_assign($serverId, $type, $sourceId)) {
            admin_flash('err', $msg);
        } else {
            $srv = altar_get_server($serverId);
            log_action('altar_assigned', "{$srv['full_name']} -> {$type}#{$sourceId}");
            admin_flash('ok', $srv['full_name'] . ' was assigned.');
        }
        redirect($self);
    } elseif ($act === 'auto_assign') {
        $type = is_string($_POST['source_type'] ?? null) ? $_POST['source_type'] : '';
        $sourceId = (int)(filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if (!in_array($type, ['schedule', 'event'], true) || !$sourceId) {
            admin_flash('err', 'Invalid activity.');
        } else {
            $r = altar_auto_assign($type, $sourceId, altar_post_slots());
            if ($r['error']) {
                admin_flash('err', $r['error']);
            } elseif ($r['assigned']) {
                log_action('altar_auto_assigned', implode(', ', $r['assigned']) . " -> {$type}#{$sourceId}");
                $msg = 'Auto-assigned: ' . implode(', ', $r['assigned']) . '.';
                if ($r['short'] > 0) {
                    $msg .= ' Still short by ' . $r['short'] . ': no more servers are available at that time.';
                }
                admin_flash($r['short'] > 0 ? 'err' : 'ok', $msg);
            } elseif ($r['short'] > 0) {
                admin_flash('err', 'No altar server is available for that activity (everyone is on leave, outside their availability, or already busy).');
            } else {
                admin_flash('ok', 'That activity already has enough servers.');
            }
        }
        redirect($self);
    } elseif ($act === 'auto_assign_all') {
        $r = altar_auto_assign_all(altar_post_slots());
        if ($r['assigned']) {
            log_action('altar_auto_assigned', "Bulk: {$r['assigned']} assignment(s) on {$r['activities']} activit(ies)");
        }
        $msg = $r['assigned']
            ? "Auto-assigned {$r['assigned']} server slot(s) across {$r['activities']} activity(ies)."
            : 'Nothing to assign.';
        if ($r['unfilled']) {
            $msg .= ' Not fully staffed (no available servers): ' . implode('; ', array_slice($r['unfilled'], 0, 8))
                . (count($r['unfilled']) > 8 ? '; and ' . (count($r['unfilled']) - 8) . ' more' : '') . '.';
        }
        admin_flash($r['unfilled'] ? 'err' : 'ok', $msg);
        redirect($self);
    } elseif ($act === 'unassign') {
        $aid = (int)(filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if ($row = altar_unassign($aid)) {
            log_action('altar_unassigned', "{$row['full_name']} x {$row['source_type']}#{$row['source_id']}");
            admin_flash('ok', $row['full_name'] . ' was unassigned.');
        } else {
            admin_flash('err', 'That assignment no longer exists.');
        }
        redirect($self);
    }
}

if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($srv = altar_get_server($editId)) {
        $form = ['full_name' => $srv['full_name'], 'phone' => $srv['phone'] ?? '', 'notes' => $srv['notes'] ?? ''];
    } else {
        $editId = 0;
    }
}

// Availability form for the server being edited.
$editServer = $editId ? altar_get_server($editId) : null;
if ($editServer && $availForm === null) {
    $availForm = altar_blank_avail();
    foreach (altar_server_weekly($editId) as $w => [$from, $to]) {
        $availForm[$w] = ['on' => true, 'from' => $from, 'to' => $to];
    }
}
$timeOff = $editServer ? altar_time_off_list($editId) : [];

$servers = altar_servers();
$activeServers = array_values(array_filter($servers, fn($s) => (int)$s['active'] === 1));
$activities = altar_upcoming_activities(date('Y-m-d'), 60);

// One query per date instead of one per server and activity.
$rowsByDate = [];
foreach ($activities as $a) {
    $rowsByDate[$a['date']] ??= altar_assignments_on($a['date']);
}

// Small POST button used in the tables below.
$btn = function (array $fields, string $label, string $cls, string $confirm = '') use ($self): string {
    $hidden = '';
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    return '<form method="post" action="' . e($self) . '" style="display:inline"'
        . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>'
        . csrf_field() . $hidden
        . '<button class="act ' . e($cls) . '" type="submit">' . e($label) . '</button></form>';
};

admin_start('Altar Servers', 'altar');
?>
<div class="container dash wide">
  <h1 class="page-title left">Altar Servers</h1>
  <p class="muted small">Keep the roster of altar servers, set when each one is available, and assign them to scheduled services and parish events. A server is only offered an activity when they are active, not on leave, within their weekly availability, and free of any overlapping activity on the same date, whatever the venue.</p>

  <form method="post" action="<?= e($self) ?>" class="card sched-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_server">
    <input type="hidden" name="id" value="<?= (int)$editId ?>">
    <div class="field full"><h2 class="h2" style="margin:0"><?= $editId ? 'Edit altar server' : 'Add altar server' ?></h2></div>
    <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" value="<?= e($form['full_name']) ?>"></div>
    <div class="field"><label for="phone">Phone <span class="muted normal">(optional)</span></label><input id="phone" name="phone" maxlength="40" value="<?= e($form['phone']) ?>"></div>
    <div class="field full"><label for="notes">Notes <span class="muted normal">(optional)</span></label><input id="notes" name="notes" maxlength="300" value="<?= e($form['notes']) ?>"></div>
    <?php if ($error): ?><p class="alert-error full" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="full">
      <button type="submit" class="btn btn-success"><?= $editId ? 'Save changes' : 'Add server' ?></button>
      <?php if ($editId): ?><a class="btn btn-outline" href="<?= e($self) ?>">Cancel editing</a><?php endif; ?>
    </div>
  </form>

  <?php if ($editServer): ?>
  <form method="post" action="<?= e($self) ?>" class="card sched-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_availability">
    <input type="hidden" name="id" value="<?= (int)$editId ?>">
    <div class="field full">
      <h2 class="h2" style="margin:0">Weekly availability — <?= e($editServer['full_name']) ?></h2>
      <p class="muted small" style="margin:.35rem 0 0">Tick the days this server can serve and the hours they can be on duty. An activity must fit entirely inside the window. Leave every day unticked if they are available any day, any time.</p>
    </div>
    <div class="full table-wrap">
      <table class="tbl">
        <thead><tr><th>Available</th><th>Day</th><th>From</th><th>Until</th></tr></thead>
        <tbody>
        <?php foreach (ALTAR_DAYS as $w => $dayName): $row = $availForm[$w]; ?>
          <tr>
            <td><input type="checkbox" id="av_on_<?= $w ?>" name="avail[<?= $w ?>][on]" value="1" <?= $row['on'] ? 'checked' : '' ?> aria-label="Available on <?= e($dayName) ?>"></td>
            <td><label for="av_on_<?= $w ?>"><?= e($dayName) ?></label></td>
            <td><input type="time" name="avail[<?= $w ?>][from]" value="<?= e($row['from']) ?>" aria-label="<?= e($dayName) ?> from"></td>
            <td><input type="time" name="avail[<?= $w ?>][to]" value="<?= e($row['to']) ?>" aria-label="<?= e($dayName) ?> until"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($availError): ?><p class="alert-error full" role="alert"><?= e($availError) ?></p><?php endif; ?>
    <div class="full"><button type="submit" class="btn btn-success">Save availability</button></div>
  </form>

  <div class="card sched-form">
    <div class="field full">
      <h2 class="h2" style="margin:0">Leave / unavailable dates — <?= e($editServer['full_name']) ?></h2>
      <p class="muted small" style="margin:.35rem 0 0">Whole days when this server cannot serve (exams, trips, illness). They will not be offered or auto-assigned on these dates.</p>
    </div>
    <?php if ($timeOff): ?>
    <div class="full table-wrap">
      <table class="tbl">
        <thead><tr><th>From</th><th>To</th><th>Reason</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($timeOff as $o): ?>
          <tr>
            <td><?= e(fmt_date($o['date_from'])) ?></td>
            <td><?= e(fmt_date($o['date_to'])) ?></td>
            <td><?= e($o['reason'] ?? '') ?></td>
            <td><?= $btn(['action' => 'delete_time_off', 'off_id' => (int)$o['id']], 'Remove', 'act-delete') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="muted small full">No upcoming leave entered.</p>
    <?php endif; ?>
    <form method="post" action="<?= e($self) ?>" class="full" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_time_off">
      <input type="hidden" name="id" value="<?= (int)$editId ?>">
      <div class="field"><label for="date_from">From</label><input id="date_from" name="date_from" type="date" required min="<?= e(date('Y-m-d')) ?>"></div>
      <div class="field"><label for="date_to">To <span class="muted normal">(optional)</span></label><input id="date_to" name="date_to" type="date" min="<?= e(date('Y-m-d')) ?>"></div>
      <div class="field"><label for="reason">Reason <span class="muted normal">(optional)</span></label><input id="reason" name="reason" maxlength="200"></div>
      <button type="submit" class="btn btn-success">Add leave</button>
    </form>
    <?php if ($offError): ?><p class="alert-error full" role="alert"><?= e($offError) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <h2 class="h2">Roster</h2>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Name</th><th>Phone</th><th>Availability</th><th>Notes</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$servers): ?><tr><td colspan="6" class="muted">No altar servers yet. Add one above.</td></tr><?php endif; ?>
      <?php foreach ($servers as $s): $i = (int)$s['id']; $on = (int)$s['active'] === 1; $leave = count(altar_time_off_list($i)); ?>
        <tr>
          <td><strong><?= e($s['full_name']) ?></strong></td>
          <td><?= e($s['phone'] ?? '') ?></td>
          <td class="small">
            <?= e(altar_availability_summary($i)) ?>
            <?php if ($leave): ?><br><span class="muted"><?= $leave ?> leave entr<?= $leave === 1 ? 'y' : 'ies' ?></span><?php endif; ?>
          </td>
          <td><?= e($s['notes'] ?? '') ?></td>
          <td><span class="badge"><?= $on ? 'Active' : 'Inactive' ?></span></td>
          <td style="white-space:nowrap">
            <a class="act act-edit" href="<?= e($self . '?edit=' . $i) ?>">Edit / availability</a>
            <?= $btn(['action' => 'set_active', 'id' => $i, 'active' => $on ? 0 : 1], $on ? 'Deactivate' : 'Reactivate', 'act-edit') ?>
            <?= $btn(['action' => 'delete_server', 'id' => $i], 'Delete', 'act-delete', 'Remove this altar server and all of their assignments?') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 class="h2">Upcoming activities</h2>
  <?php if ($activeServers && $activities): ?>
  <form method="post" action="<?= e($self) ?>" class="card" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem"
        onsubmit="return confirm('Automatically fill every upcoming activity that has too few servers?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="auto_assign_all">
    <label for="slots_all"><strong>Auto-assign all:</strong> servers needed per activity</label>
    <input id="slots_all" name="slots" type="number" min="1" max="10" value="<?= (int)ALTAR_DEFAULT_SLOTS ?>" style="width:5rem">
    <button type="submit" class="btn btn-success">Fill all unstaffed activities</button>
    <span class="muted small">Picks available servers with the fewest upcoming assignments. Existing assignments are kept.</span>
  </form>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Venue</th><th>Assigned servers</th><th>Assign</th></tr></thead>
      <tbody>
      <?php if (!$activities): ?><tr><td colspan="6" class="muted">No upcoming services or events.</td></tr><?php endif; ?>
      <?php foreach ($activities as $a):
          $rows = $rowsByDate[$a['date']] ?? [];
          $assignedIds = array_map(fn($sv) => (int)$sv['server_id'], $a['servers']);
          $free = [];
          $blocked = [];
          foreach ($activeServers as $s) {
              if (in_array((int)$s['id'], $assignedIds, true)) {
                  continue;
              }
              $why = altar_block_reason($s, $a, $rows);
              if ($why === null) {
                  $free[] = $s;
              } else {
                  $blocked[] = [$s, $why];
              }
          }
      ?>
        <tr>
          <td><?= e(fmt_date($a['date'])) ?></td>
          <td><?= e(fmt_time($a['start'])) ?><?= $a['end'] ? e(' – ' . fmt_time($a['end'])) : '' ?></td>
          <td><strong><?= e($a['title']) ?></strong><br><span class="muted"><?= e($a['label']) ?></span></td>
          <td><?= e($a['venue']) ?></td>
          <td>
            <?php if (!$a['servers']): ?><span class="muted">None yet</span><?php endif; ?>
            <?php foreach ($a['servers'] as $sv): $why = (int)$sv['active'] ? altar_availability_reason((int)$sv['server_id'], $a) : null; ?>
              <div>
                <?= e($sv['full_name']) ?><?= (int)$sv['active'] ? '' : ' <span class="muted">(inactive)</span>' ?>
                <?php if ($why): ?><span class="muted" title="<?= e($why) ?>">(⚠ <?= e($why) ?>)</span><?php endif; ?>
                <?= $btn(['action' => 'unassign', 'assignment_id' => (int)$sv['assignment_id']], 'Remove', 'act-delete') ?>
              </div>
            <?php endforeach; ?>
          </td>
          <td>
            <?php if ($activeServers): ?>
            <form method="post" action="<?= e($self) ?>" style="display:flex;gap:.4rem;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="assign">
              <input type="hidden" name="source_type" value="<?= e($a['type']) ?>">
              <input type="hidden" name="source_id" value="<?= (int)$a['id'] ?>">
              <select name="server_id" aria-label="Altar server" required>
                <option value=""><?= $free ? 'Choose (' . count($free) . ' available)…' : 'No one available' ?></option>
                <?php foreach ($free as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?>
                <?php if ($blocked): ?>
                  <optgroup label="Not available">
                    <?php foreach ($blocked as [$s, $why]): ?><option value="" disabled><?= e($s['full_name'] . ' — ' . $why) ?></option><?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              </select>
              <button class="act act-edit" type="submit">Assign</button>
            </form>
            <form method="post" action="<?= e($self) ?>" style="display:flex;gap:.4rem;align-items:center;margin-top:.35rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="auto_assign">
              <input type="hidden" name="source_type" value="<?= e($a['type']) ?>">
              <input type="hidden" name="source_id" value="<?= (int)$a['id'] ?>">
              <input name="slots" type="number" min="1" max="10" value="<?= (int)max(ALTAR_DEFAULT_SLOTS, count($a['servers'])) ?>" style="width:4rem" aria-label="Servers needed for this activity" title="Servers needed">
              <button class="act act-edit" type="submit">Auto-assign</button>
            </form>
            <?php else: ?><span class="muted">Add a server first</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_end();