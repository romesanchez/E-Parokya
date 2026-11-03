<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$counts = status_counts();
$ls = login_stats();
$stale = stale_pending(3);
$noSched = approved_without_schedule();
$upcoming = upcoming_schedule(6);
$services = service_breakdown();
$maxSvc = $services ? max($services) : 1;
$weekReq = requests_since(date('Y-m-d H:i:s', time() - 7 * 86400));
[$recent] = admin_requests([], 1, 6);
[$logins] = login_events(['type' => 'success'], 1, 6);
$suspicious = suspicious_ips();

admin_start('Dashboard', 'dashboard');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Dashboard</h1>
      <p class="muted small"><?= e(date('l, F j, Y')) ?> · <?= count_users() ?> registered parishioners · <?= (int)$weekReq ?> new request(s) this week</p>
    </div>
    <div class="toolbar">
      <a href="<?= e(url('admin/assisted.php')) ?>" class="btn btn-primary">+ Assisted Request</a>
      <a href="<?= e(url('admin/tracker.php')) ?>" class="btn btn-outline">Open Tracker</a>
    </div>
  </div>

  <div class="grid-kpi">
    <a class="card kpi" href="<?= e(url('admin/tracker.php')) ?>"><p class="label-caps">Open requests</p><p class="stat-n"><?= (int)($counts['pending'] + $counts['approved']) ?></p><small><?= (int)$counts['pending'] ?> pending · <?= (int)$counts['approved'] ?> approved</small></a>
    <a class="card kpi<?= $stale ? ' warn' : '' ?>" href="<?= e(url('admin/transactions.php?status=pending')) ?>"><p class="label-caps">Waiting 3+ days</p><p class="stat-n"><?= count($stale) ?></p><small>Pending, needs a reply</small></a>
    <a class="card kpi" href="<?= e(url('admin/schedule.php')) ?>"><p class="label-caps">Upcoming events</p><p class="stat-n"><?= count_upcoming() ?></p><small><?= count($noSched) ?> approved without schedule</small></a>
    <a class="card kpi" href="<?= e(url('admin/transactions.php?status=completed')) ?>"><p class="label-caps">Completed</p><p class="stat-n"><?= (int)$counts['completed'] ?></p><small>Ready to archive</small></a>
    <a class="card kpi" href="<?= e(url('admin/logins.php')) ?>"><p class="label-caps">Logins today</p><p class="stat-n"><?= (int)$ls['logins_today'] ?></p><small><?= (int)$ls['unique_week'] ?> different people this week</small></a>
    <a class="card kpi<?= $ls['failed_today'] ? ' warn' : '' ?>" href="<?= e(url('admin/logins.php?type=failed')) ?>"><p class="label-caps">Failed logins today</p><p class="stat-n"><?= (int)$ls['failed_today'] ?></p><small>Wrong password / 2FA code</small></a>
  </div>

  <?php if ($suspicious): ?>
    <p class="flash flash-err" style="margin-bottom:1rem">Warning: <?= count($suspicious) ?> IP address(es) had 3 or more failed logins in the last 24 hours. <a class="link" href="<?= e(url('admin/logins.php?type=failed')) ?>">Review</a></p>
  <?php endif; ?>

  <div class="panels">
    <section class="card panel">
      <h2>Needs attention</h2>
      <?php if (!$stale && !$noSched): ?><p class="muted small">Nothing urgent. 🎉</p><?php endif; ?>
      <table class="mini">
        <?php foreach ($stale as $r): ?>
          <tr><td><a class="link" href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a><br><span class="muted"><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?> · <?= e($r['reference_no']) ?></span></td>
            <td style="text-align:right"><span class="badge badge-pending">Pending <?= age_days($r['created_at']) ?>d</span></td></tr>
        <?php endforeach; ?>
        <?php foreach ($noSched as $r): ?>
          <tr><td><a class="link" href="<?= e(url('admin/schedule.php?request=' . (int)$r['id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a><br><span class="muted"><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?> · <?= e($r['reference_no']) ?></span></td>
            <td style="text-align:right"><span class="badge badge-approved">Set schedule →</span></td></tr>
        <?php endforeach; ?>
      </table>
    </section>

    <section class="card panel">
      <h2>Upcoming schedule</h2>
      <?php if (!$upcoming): ?><p class="muted small">No scheduled events.</p><?php endif; ?>
      <table class="mini">
        <?php foreach ($upcoming as $s): ?>
          <tr><td style="white-space:nowrap"><strong><?= e(fmt_date($s['event_date'], false)) ?></strong><br><span class="muted"><?= e(fmt_time($s['event_time'])) ?></span></td>
            <td><?= e($s['full_name']) ?><br><span class="muted"><?= e(SERVICE_LABELS[$s['service_type']] ?? $s['service_type']) ?> · <?= e($s['venue']) ?></span></td></tr>
        <?php endforeach; ?>
      </table>
    </section>

    <section class="card panel">
      <h2>Requests by service</h2>
      <?php if (!$services): ?><p class="muted small">No requests yet.</p><?php endif; ?>
      <?php foreach ($services as $k => $n): ?>
        <div class="bar-row"><span><?= e(SERVICE_LABELS[$k] ?? $k) ?></span><div class="bar-track"><div class="bar-fill" style="width:<?= (int)round($n / $maxSvc * 100) ?>%"></div></div><strong><?= (int)$n ?></strong></div>
      <?php endforeach; ?>
    </section>

    <section class="card panel">
      <h2>Latest requests</h2>
      <table class="mini">
        <?php foreach ($recent as $r): ?>
          <tr><td><a class="link" href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a><br><span class="muted"><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?> · <?= e(fmt_date($r['created_at'], false)) ?></span></td>
            <td style="text-align:right"><?= status_badge($r['status']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </section>

    <section class="card panel">
      <h2>Recent logins</h2>
      <?php if (!$logins): ?><p class="muted small">No logins recorded yet.</p><?php endif; ?>
      <table class="mini">
        <?php foreach ($logins as $l): ?>
          <tr><td><strong><?= e($l['actor']) ?></strong><br><span class="muted"><?= e($l['role']) ?> · <?= e($l['ip']) ?></span></td>
            <td style="text-align:right" class="muted"><?= e(date('M j, g:i A', strtotime($l['created_at']))) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="small" style="margin-top:.5rem"><a class="link" href="<?= e(url('admin/logins.php')) ?>">All login activity →</a></p>
    </section>
  </div>
</div>
<?php admin_end();
