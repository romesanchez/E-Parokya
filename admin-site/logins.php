<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$f = ['type' => get_str('type'), 'who' => get_str('who'), 'q' => get_str('q'), 'from' => get_str('from'), 'to' => get_str('to')];
if (!in_array($f['type'], ['', 'success', 'failed', 'logout', 'register'], true)) {
    $f['type'] = '';
}
$qs = array_filter($f, fn($v) => $v !== '');
$self = url('admin/logins.php');
$page = page_param();
[$rows, $total] = login_events($f, $page, 25);
$stats = login_stats();
$sus = suspicious_ips();

$typeLabels = ['' => 'All events', 'success' => 'Successful logins', 'failed' => 'Failed attempts', 'logout' => 'Logouts', 'register' => 'New sign-ups'];

admin_start('Login Activity', 'logins');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Login Activity</h1>
      <p class="muted small">Who signed in, when, and from which IP address — parishioners and staff. Failed attempts are highlighted.</p>
    </div>
  </div>

  <div class="grid-kpi">
    <div class="card kpi"><p class="label-caps">Logins today</p><p class="stat-n"><?= (int)$stats['logins_today'] ?></p></div>
    <div class="card kpi<?= $stats['failed_today'] ? ' warn' : '' ?>"><p class="label-caps">Failed today</p><p class="stat-n"><?= (int)$stats['failed_today'] ?></p></div>
    <div class="card kpi"><p class="label-caps">Active this week</p><p class="stat-n"><?= (int)$stats['unique_week'] ?></p><small>different accounts</small></div>
    <div class="card kpi"><p class="label-caps">Sign-ups this week</p><p class="stat-n"><?= (int)$stats['signups_week'] ?></p></div>
  </div>

  <?php if ($sus): ?>
    <section class="card panel danger-zone" style="margin-bottom:1rem">
      <h2>Suspicious IP addresses (3+ failures in 24 hours)</h2>
      <table class="mini"><?php foreach ($sus as $s): ?>
        <tr><td><strong><?= e($s['ip']) ?></strong></td><td><?= (int)$s['n'] ?> failed attempts</td><td class="muted" style="text-align:right">last <?= e(date('M j, g:i A', strtotime($s['last_at']))) ?></td></tr>
      <?php endforeach; ?></table>
      <p class="muted small" style="margin-top:.4rem">Accounts are already locked for 15 minutes after 5 failures. To block an address completely, add it to <code>admin_allowed_ips</code> in config.php.</p>
    </section>
  <?php endif; ?>

  <form method="get" action="<?= e($self) ?>" class="card filters">
    <div><label for="q">Name, email or IP</label><input id="q" name="q" value="<?= e($f['q']) ?>"></div>
    <div><label for="type">Event</label>
      <select id="type" name="type"><?php foreach ($typeLabels as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="who">Who</label>
      <select id="who" name="who"><?php foreach ([''=>'Everyone','user'=>'Parishioners','admin'=>'Staff'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['who'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <div><button class="btn btn-info" type="submit">Filter</button> <a class="btn btn-outline" href="<?= e($self) ?>">Reset</a></div>
  </form>

  <p class="muted small" style="margin:.75rem 0 .5rem"><?= (int)$total ?> event(s)</p>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>When</th><th>Who</th><th>Type</th><th>Event</th><th>IP address</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted">No events match.</td></tr><?php endif; ?>
      <?php foreach ($rows as $l): $failed = str_ends_with($l['action'], '_failed'); $adm = $l['role'] === 'admin' || str_starts_with($l['action'], 'admin_'); ?>
        <tr>
          <td style="white-space:nowrap"><?= e(date('M j, Y g:i A', strtotime($l['created_at']))) ?></td>
          <td><strong><?= e($l['actor']) ?></strong><?php if ($l['account_email'] && $l['account_email'] !== $l['actor']): ?><span class="sub"><?= e($l['account_email']) ?></span><?php endif; ?></td>
          <td><?= $adm ? 'Staff' : 'Parishioner' ?></td>
          <td><span class="badge <?= $failed ? 'badge-declined' : 'badge-approved' ?>"><?= e(LOG_LABELS[$l['action']] ?? $l['action']) ?></span></td>
          <td class="muted"><?= e($l['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php pager($page, $total, 25, $self, $qs); ?>
</div>
<?php admin_end();