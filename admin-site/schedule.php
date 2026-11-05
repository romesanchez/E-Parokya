<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();

$self = url('admin/schedule.php');
$error = null;
$form = ['event_date' => '', 'event_time' => '', 'venue' => '', 'officiant' => '', 'notes' => ''];
$selId = (int)(filter_var($_GET['request'] ?? null, FILTER_VALIDATE_INT) ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)(filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $selId = $rid;
    $act = $_POST['action'] ?? '';
    $req = $rid ? get_request($rid) : null;

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$req) {
        $error = 'Please choose a request.';
    } elseif ($act === 'remove') {
        delete_schedule($rid);
        log_action('schedule_removed', "#{$rid} {$req['full_name']}");
        redirect($self);
    } elseif ($act === 'save') {
        foreach ($form as $k => $_) {
            $form[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        }
        $d = DateTime::createFromFormat('Y-m-d', $form['event_date']);
        $t = DateTime::createFromFormat('H:i', $form['event_time']);
        if (!$d || $d->format('Y-m-d') !== $form['event_date']) {
            $error = 'Please enter a valid date.';
        } elseif (!$t || $t->format('H:i') !== $form['event_time']) {
            $error = 'Please enter a valid time.';
        } elseif ($form['venue'] === '' || mb_strlen($form['venue']) > 120 || mb_strlen($form['officiant']) > 120 || mb_strlen($form['notes']) > 500) {
            $error = 'Please check the venue, officiant and notes.';
        } elseif ($c = schedule_conflict($form['event_date'], $form['event_time'], $form['venue'], $rid)) {
            $error = 'Conflict: ' . $c['full_name'] . ' (' . (SERVICE_LABELS[$c['service_type']] ?? $c['service_type'])
                . ') is already booked at ' . $form['venue'] . ' on that date and time.';
        } else {
            save_schedule($rid, $form);
            log_action('schedule_set', "#{$rid} {$req['full_name']}: {$form['event_date']} {$form['event_time']} @ {$form['venue']}");
            redirect($self . '?saved=1');
        }
    }
}

$sel = $selId ? get_request($selId) : null;
if ($sel && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form = [
        'event_date' => $sel['event_date'] ?: ($sel['preferred_date'] ?? ''),
        'event_time' => $sel['event_time'] ?? '',
        'venue'      => $sel['venue'] ?? '',
        'officiant'  => $sel['officiant'] ?? '',
        'notes'      => $sel['sched_notes'] ?? '',
    ];
}

$options = list_requests('approved');
if ($sel && !in_array($sel['id'], array_column($options, 'id'))) {
    array_unshift($options, $sel);
}
$upcoming = schedule_list(true);
$past = schedule_list(false);

admin_start('Scheduling', 'schedule');
?>
<div class="container dash wide">
  <h1 class="page-title left">Scheduling</h1>
  <p class="muted small">Give approved requests a date, time, venue and officiant. Parishioners see it under My Requests.</p>
  <?php if (isset($_GET['saved'])): ?><p class="small" style="color:var(--primary);margin-top:.75rem">Schedule saved.</p><?php endif; ?>

  <form method="post" action="<?= e($self) ?>" class="card sched-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field full">
      <label for="request_id">Request</label>
      <select id="request_id" name="request_id" required onchange="location='<?= e($self) ?>?request='+this.value">
        <option value="">— Choose an approved request —</option>
        <?php foreach ($options as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $selId ? 'selected' : '' ?>>
            <?= e($o['full_name']) ?> — <?= e(SERVICE_LABELS[$o['service_type']] ?? $o['service_type']) ?><?= $o['preferred_date'] ? ' (prefers ' . e(fmt_date($o['preferred_date'])) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="event_date">Date</label><input id="event_date" name="event_date" type="date" required value="<?= e($form['event_date']) ?>"></div>
    <div class="field"><label for="event_time">Time</label><input id="event_time" name="event_time" type="time" required value="<?= e($form['event_time']) ?>"></div>
    <div class="field"><label for="venue">Venue</label>
      <input id="venue" name="venue" list="venues" required maxlength="120" value="<?= e($form['venue']) ?>">
      <datalist id="venues"><option value="Main Church"><option value="Side Chapel"><option value="Parish Hall"><option value="Cemetery Chapel"><option value="Family home (off-site)"></datalist>
    </div>
    <div class="field"><label for="officiant">Priest / Officiant <span class="muted normal">(optional)</span></label>
      <input id="officiant" name="officiant" maxlength="120" value="<?= e($form['officiant']) ?>"></div>
    <div class="field full"><label for="notes">Notes <span class="muted normal">(optional)</span></label>
      <textarea id="notes" name="notes" rows="2" maxlength="500"><?= e($form['notes']) ?></textarea></div>
    <?php if ($error): ?><p class="alert-error full" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="full"><button type="submit" class="btn btn-success">Save schedule</button></div>
  </form>

  <?php foreach ([['Upcoming', $upcoming, true], ['Recent past', $past, false]] as [$title, $list, $canEdit]): ?>
    <h2 class="h2"><?= e($title) ?></h2>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Time</th><th>Venue</th><th>Officiant</th><th>Request</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$list): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
        <?php foreach ($list as $s): ?>
          <tr>
            <td><?= e(fmt_date($s['event_date'])) ?></td>
            <td><?= e(fmt_time($s['event_time'])) ?></td>
            <td><?= e($s['venue']) ?></td>
            <td><?= e($s['officiant'] ?? '—') ?></td>
            <td><strong><?= e($s['full_name']) ?></strong><br><span class="muted"><?= e(SERVICE_LABELS[$s['service_type']] ?? $s['service_type']) ?></span></td>
            <?php if ($canEdit): ?>
            <td style="white-space:nowrap">
              <a class="act act-edit" href="<?= e($self . '?request=' . (int)$s['request_id']) ?>">Edit</a>
              <form method="post" action="<?= e($self) ?>" style="display:inline" onsubmit="return confirm('Remove this schedule?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="request_id" value="<?= (int)$s['request_id'] ?>">
                <button class="act act-delete" type="submit">Remove</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>
<?php admin_end();