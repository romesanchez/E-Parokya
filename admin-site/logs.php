<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$f = ['action' => get_str('action'), 'role' => get_str('role'), 'q' => get_str('q')];
$logs = search_logs($f);

admin_start('Activity Logs', 'logs');
?>
<div class="container dash wide">
  <h1 class="page-title left">Activity Logs</h1>
  <p class="muted small">Logins, sign-ups, new requests, status changes and scheduling. Showing the latest 300 entries.</p>

  <form method="get" action="<?= e(url('admin/logs.php')) ?>" class="card filters">
    <div><label for="q">Search</label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email or details"></div>
    <div><label for="action">Action</label>
      <select id="action" name="action"><option value="">All</option>
        <?php foreach (LOG_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['action'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="role">Who</label>
      <select id="role" name="role"><option value="">Everyone</option>
        <?php foreach (['admin' => 'Admins', 'user' => 'Parishioners', 'guest' => 'Not logged in'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><button class="btn btn-info" type="submit">Filter</button></div>
  </form>

  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>When</th><th>Who</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?><tr><td colspan="6" class="muted">No log entries.</td></tr><?php endif; ?>
      <?php foreach ($logs as $l): $failed = str_ends_with($l['action'], '_failed'); ?>
        <tr>
          <td style="white-space:nowrap"><?= e(date('M j, Y g:i A', strtotime($l['created_at']))) ?></td>
          <td><?= e($l['actor']) ?></td>
          <td><?= e($l['role']) ?></td>
          <td><span class="badge <?= $failed ? 'badge-declined' : 'badge-approved' ?>"><?= e(LOG_LABELS[$l['action']] ?? $l['action']) ?></span></td>
          <td><?= e($l['details']) ?></td>
          <td class="muted"><?= e($l['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_end();