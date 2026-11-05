<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();

$service = get_str('service');
if ($service !== '' && !isset(SERVICE_LABELS[$service])) {
    $service = '';
}

// Keep the current tab (and service filter) after moving / archiving a request.
$tabNow = get_str('tab');
$self = url('admin/tracker.php') . '?' . http_build_query(array_filter([
    'service' => $service,
    'tab'     => isset(STATUS_LABELS[$tabNow]) ? $tabNow : '',
], fn($v) => $v !== ''));

// Move a request to another status / archive a finished one.
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

// Small icon in front of each status label (color comes from the CSS).
$icons = ['pending' => '!', 'approved' => '→', 'completed' => '✓', 'declined' => '✕'];

// Which tab is open (defaults to Pending).
$tab = get_str('tab');
if (!isset($board[$tab])) {
    $tab = 'pending';
}
$cards = $board[$tab];
$qs = fn(array $extra) => '?' . http_build_query(array_filter($extra + ['service' => $service], fn($v) => $v !== ''));

// Builds one small POST form (move / archive button).
$form = function (int $id, string $action, string $status, string $label, string $cls, string $confirm = '') use ($self): string {
    $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '';
    return '<form method="post" action="' . e($self) . '"' . $onsubmit . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . ($status !== '' ? '<input type="hidden" name="status" value="' . e($status) . '">' : '')
        . '<button class="btn btn-sm ' . e($cls) . '" type="submit">' . e($label) . '</button>'
        . '</form>';
};

admin_start('Tracker', 'tracker');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Request Tracker</h1>
      <p class="muted small">Follow every client from <strong>Pending → Approved → Completed</strong>. Pick a tab to see its requests; archive finished ones to clear the list.</p>
    </div>
    <form method="get" action="<?= e(url('admin/tracker.php')) ?>" class="toolbar">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select name="service" onchange="this.form.submit()" aria-label="Filter by service">
        <option value="">All services</option>
        <?php foreach (SERVICE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $service === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <nav class="trk-tabs" aria-label="Request status">
    <?php foreach ($board as $status => $list): ?>
      <a class="trk-tab s-<?= e($status) ?><?= $tab === $status ? ' active' : '' ?>"
         href="<?= e(url('admin/tracker.php') . $qs(['tab' => $status])) ?>">
        <span class="ico"><?= $icons[$status] ?? '●' ?></span>
        <?= e(STATUS_LABELS[$status]) ?>
        <span class="n"><?= count($list) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="table-wrap trk-wrap">
    <?php if (!$cards): ?>
      <p class="trk-empty">No <?= e(strtolower(STATUS_LABELS[$tab])) ?> requests.</p>
    <?php else: ?>
      <table class="tbl trk-table">
        <thead>
          <tr>
            <th>Client</th>
            <th>Service</th>
            <th>Schedule</th>
            <th>Submitted</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($cards as $r): $age = age_days($r['created_at']); $late = $tab === 'pending' && $age >= 3; $id = (int)$r['id']; ?>
          <tr class="s-<?= e($tab) ?>">
            <td>
              <div class="trk-name"><a href="<?= e(url('admin/request.php?id=' . $id)) ?>"><?= e($r['full_name']) ?></a></div>
              <div class="trk-sub"><?= e($r['reference_no']) ?> · <?= e(METHOD_LABELS[$r['method']] ?? $r['method']) ?></div>
            </td>
            <td><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?></td>
            <td>
              <?php if ($r['event_date']): ?>
                <?= e(fmt_date($r['event_date'])) ?> <?= e(fmt_time($r['event_time'])) ?>
                <div class="trk-sub"><?= e($r['venue']) ?></div>
              <?php elseif ($r['preferred_date']): ?>
                Prefers <?= e(fmt_date($r['preferred_date'])) ?>
              <?php else: ?>
                <span class="trk-sub">Not set</span>
              <?php endif; ?>
            </td>
            <td><span class="trk-age<?= $late ? ' late' : '' ?>"><?= $age === 0 ? 'Today' : $age . ' day(s) ago' ?></span></td>
            <td>
              <span class="st-badge s-<?= e($tab) ?>"><span class="ico"><?= $icons[$tab] ?? '●' ?></span><?= e(STATUS_LABELS[$tab]) ?></span>
            </td>
            <td>
              <div class="trk-actions">
                <?php /* 1) secondary actions on the left */ ?>
                <?php if (isset($prev[$tab])): ?>
                  <?= $form($id, 'move', $prev[$tab], '← ' . STATUS_LABELS[$prev[$tab]], 'btn-outline-neutral') ?>
                <?php endif; ?>

                <?php if ($tab === 'approved' && !$r['event_date']): ?>
                  <a class="btn btn-sm btn-outline-info" href="<?= e(url('admin/schedule.php?request=' . $id)) ?>">Set schedule</a>
                <?php endif; ?>

                <?php if ($tab === 'pending'): ?>
                  <?= $form($id, 'move', 'declined', 'Decline', 'btn-danger', 'Decline this request?') ?>
                <?php endif; ?>

                <?php if (in_array($tab, ['completed', 'declined'], true)): ?>
                  <?= $form($id, 'archive', '', 'Archive', 'btn-outline-neutral', 'Archive this client? It moves to the Archive page, where you can restore or delete it.') ?>
                <?php endif; ?>

                <?php /* 2) main forward action always on the far right */ ?>
                <?php if (isset($next[$tab])): $to = $next[$tab]; ?>
                  <?= $form($id, 'move', $to, $to === 'completed' ? 'Complete' : STATUS_LABELS[$to] . ' →', 'btn-to-' . $to) ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php admin_end();