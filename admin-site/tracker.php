<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();

$service = get_str('service');
if ($service !== '' && !isset(SERVICE_LABELS[$service])) {
    $service = '';
}
$self = url('admin/tracker.php') . ($service !== '' ? '?service=' . urlencode($service) : '');

// Move a card to another column / archive a finished one.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)(filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $r = $id ? get_request($id) : null;
    if (!csrf_ok()) {
        admin_flash('err', 'Your session expired. Please try again.');
    } elseif (!$r) {
        admin_flash('err', 'Request not found.');
    } elseif ($act === 'move' && isset(STATUS_LABELS[$_POST['status'] ?? ''])) {
        $to = $_POST['status'];
        if ($to !== $r['status']) {
            update_status($id, $to);
            log_action('status_changed', "{$r['reference_no']} {$r['full_name']}: {$r['status']} → {$to}");
        }
        admin_flash('ok', "{$r['full_name']} moved to " . STATUS_LABELS[$to] . '.');
    } elseif ($act === 'archive') {
        if (archive_request($id)) {
            log_action('request_archived', "{$r['reference_no']} {$r['full_name']}");
            admin_flash('ok', "{$r['full_name']} was archived.");
        } else {
            admin_flash('err', 'Only completed or declined requests can be archived.');
        }
    }
    redirect($self);
}

$board = tracker_board($service);
// Next / previous step for the quick buttons.
$next = ['pending' => 'approved', 'approved' => 'completed'];
$prev = ['approved' => 'pending', 'completed' => 'approved'];

admin_start('Tracker', 'tracker');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Request Tracker</h1>
      <p class="muted small">Follow every client from <strong>Pending → Approved → Completed</strong>. Move cards with the buttons; archive finished ones to clear the board.</p>
    </div>
    <form method="get" action="<?= e(url('admin/tracker.php')) ?>" class="toolbar">
      <select name="service" onchange="this.form.submit()" aria-label="Filter by service">
        <option value="">All services</option>
        <?php foreach (SERVICE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $service === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="board">
    <?php foreach ($board as $status => $cards): ?>
      <section class="col" aria-label="<?= e(STATUS_LABELS[$status]) ?>">
        <div class="col-head"><span><?= e(STATUS_LABELS[$status]) ?></span><span class="count"><?= count($cards) ?></span></div>
        <?php if (!$cards): ?><p class="col-empty">Nothing here</p><?php endif; ?>
        <?php foreach ($cards as $r): $age = age_days($r['created_at']); $late = $status === 'pending' && $age >= 3; ?>
          <article class="tcard">
            <p class="ref"><?= e($r['reference_no']) ?> · <?= e(METHOD_LABELS[$r['method']] ?? $r['method']) ?></p>
            <h3><a href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>"><?= e($r['full_name']) ?></a></h3>
            <p class="meta"><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?>
              <?php if ($r['event_date']): ?><br>📅 <?= e(fmt_date($r['event_date'])) ?> <?= e(fmt_time($r['event_time'])) ?> · <?= e($r['venue']) ?>
              <?php elseif ($r['preferred_date']): ?><br>Prefers <?= e(fmt_date($r['preferred_date'])) ?><?php endif; ?></p>
            <p class="meta"><span class="age<?= $late ? ' late' : '' ?>"><?= $age === 0 ? 'Today' : $age . ' day(s) ago' ?></span></p>
            <div class="tcard-actions">
              <?php foreach ([['prev', $prev, '← '], ['next', $next, '']] as [$dir, $map, $pre]): if (isset($map[$status])): ?>
                <form method="post" action="<?= e($self) ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= e($map[$status]) ?>">
                  <button class="btn btn-sm <?= $dir === 'next' ? 'btn-primary' : 'btn-outline' ?>" type="submit"><?= $dir === 'next' ? e(STATUS_LABELS[$map[$status]]) . ' →' : $pre . e(STATUS_LABELS[$map[$status]]) ?></button>
                </form>
              <?php endif; endforeach; ?>
              <?php if ($status === 'pending'): ?>
                <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Decline this request?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="declined">
                  <button class="btn btn-sm btn-outline" type="submit">Decline</button>
                </form>
              <?php endif; ?>
              <?php if ($status === 'approved' && !$r['event_date']): ?>
                <a class="btn btn-sm btn-outline" href="<?= e(url('admin/schedule.php?request=' . (int)$r['id'])) ?>">Set schedule</a>
              <?php endif; ?>
              <?php if (in_array($status, ['completed', 'declined'], true)): ?>
                <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Archive this client? It moves to the Archive page, where you can restore or delete it.')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline" type="submit">Archive</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php admin_end();
