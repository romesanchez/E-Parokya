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

// First letter of a name, for the round avatar.
$ini = fn(string $n): string => strtoupper(substr(trim($n), 0, 1)) ?: '?';
$svc = fn(string $k): string => SERVICE_LABELS[$k] ?? $k;

// KPI cards: [label, number, caption, link, icon, tone, warn?]
$kpis = [
    ['Open requests',      (int)($counts['pending'] + $counts['approved']), (int)$counts['pending'] . ' pending · ' . (int)$counts['approved'] . ' approved', 'admin/tracker.php',                      '📋', 'brand',  false],
    ['Waiting 3+ days',    count($stale),                                   'Pending, needs a reply',                                                              'admin/tracker.php?tab=pending',         '⏳', 'orange', (bool)$stale],
    ['Upcoming events',    (int)count_upcoming(),                           count($noSched) . ' approved without schedule',                                        'admin/schedule.php',                    '📅', 'blue',   false],
    ['Completed',          (int)$counts['completed'],                       'Ready to archive',                                                                    'admin/tracker.php?tab=completed',       '✓',  'green',  false],
    ['Logins today',       (int)$ls['logins_today'],                        (int)$ls['unique_week'] . ' different people this week',                               'admin/logins.php',                      '🔑', 'gray',   false],
    ['Failed logins today',(int)$ls['failed_today'],                        'Wrong password / 2FA code',                                                           'admin/logins.php?type=failed',          '⚠️', 'red',    (bool)$ls['failed_today']],
];

admin_start('Dashboard', 'dashboard');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Dashboard</h1>
      <p class="muted small"><?= e(date('l, F j, Y')) ?> · <?= count_users() ?> registered parishioners · <?= (int)$weekReq ?> new request(s) this week</p>
    </div>
    <div class="toolbar">
      <a href="<?= e(url('admin/assisted.php')) ?>" class="btn btn-success">+ Assisted Request</a>
      <a href="<?= e(url('admin/tracker.php')) ?>" class="btn btn-outline-info">Open Tracker</a>
    </div>
  </div>

  <!-- Summary numbers -->
  <div class="dash-kpis">
    <?php foreach ($kpis as [$label, $n, $cap, $href, $icon, $tone, $warn]): ?>
      <a class="card dash-kpi tone-<?= e($tone) ?><?= $warn ? ' warn' : '' ?>" href="<?= e(url($href)) ?>">
        <span class="dash-kpi-top">
          <span class="label-caps"><?= e($label) ?></span>
          <span class="chip"><?= $icon ?></span>
        </span>
        <span class="n"><?= (int)$n ?></span>
        <small><?= e($cap) ?></small>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($suspicious): ?>
    <p class="flash flash-err" style="margin-bottom:1.25rem">Warning: <?= count($suspicious) ?> IP address(es) had 3 or more failed logins in the last 24 hours. <a class="link" href="<?= e(url('admin/logins.php?type=failed')) ?>">Review</a></p>
  <?php endif; ?>

  <div class="dash-grid">

    <!-- LEFT: what needs doing -->
    <div class="dash-col">

      <section class="card dash-panel">
        <div class="dash-head">
          <h2>Needs attention</h2>
          <?php if ($stale || $noSched): ?><span class="badge badge-pending"><?= count($stale) + count($noSched) ?></span><?php endif; ?>
        </div>
        <?php if (!$stale && !$noSched): ?>
          <p class="dash-empty">✓ All caught up. Nothing urgent.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($stale as $r): ?>
              <a class="dash-row" href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>">
                <span class="av"><?= e($ini($r['full_name'])) ?></span>
                <span class="dash-main"><strong><?= e($r['full_name']) ?></strong><small><?= e($svc($r['service_type'])) ?> · <?= e($r['reference_no']) ?></small></span>
                <span class="dash-end"><span class="badge badge-pending">Pending <?= age_days($r['created_at']) ?>d</span></span>
              </a>
            <?php endforeach; ?>
            <?php foreach ($noSched as $r): ?>
              <a class="dash-row" href="<?= e(url('admin/schedule.php?request=' . (int)$r['id'])) ?>">
                <span class="av"><?= e($ini($r['full_name'])) ?></span>
                <span class="dash-main"><strong><?= e($r['full_name']) ?></strong><small><?= e($svc($r['service_type'])) ?> · <?= e($r['reference_no']) ?></small></span>
                <span class="dash-end"><span class="badge badge-approved">Set schedule →</span></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="card dash-panel">
        <div class="dash-head">
          <h2>Latest requests</h2>
          <a href="<?= e(url('admin/transactions.php')) ?>">View all →</a>
        </div>
        <?php if (!$recent): ?>
          <p class="dash-empty">No requests yet.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($recent as $r): ?>
              <a class="dash-row" href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>">
                <span class="av"><?= e($ini($r['full_name'])) ?></span>
                <span class="dash-main"><strong><?= e($r['full_name']) ?></strong><small><?= e($svc($r['service_type'])) ?> · <?= e(fmt_date($r['created_at'], false)) ?></small></span>
                <span class="dash-end"><?= status_badge($r['status']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>

    <!-- RIGHT: schedule + overview -->
    <div class="dash-col">

      <section class="card dash-panel">
        <div class="dash-head">
          <h2>Upcoming schedule</h2>
          <a href="<?= e(url('admin/schedule.php')) ?>">View all →</a>
        </div>
        <?php if (!$upcoming): ?>
          <p class="dash-empty">No scheduled events.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($upcoming as $s): $ts = strtotime($s['event_date']); ?>
              <div class="dash-row">
                <span class="date-chip"><b><?= e(date('M', $ts)) ?></b><i><?= e(date('j', $ts)) ?></i></span>
                <span class="dash-main"><strong><?= e($s['full_name']) ?></strong><small><?= e($svc($s['service_type'])) ?> · <?= e($s['venue']) ?></small></span>
                <span class="dash-end"><?= e(fmt_time($s['event_time'])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="card dash-panel">
        <div class="dash-head"><h2>Requests by service</h2></div>
        <?php if (!$services): ?>
          <p class="dash-empty">No requests yet.</p>
        <?php endif; ?>
        <?php foreach ($services as $k => $n): ?>
          <div class="dash-bar">
            <span><?= e($svc($k)) ?></span>
            <div class="bar-track"><div class="bar-fill" style="width:<?= (int)round($n / $maxSvc * 100) ?>%"></div></div>
            <strong><?= (int)$n ?></strong>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="card dash-panel">
        <div class="dash-head">
          <h2>Recent logins</h2>
          <a href="<?= e(url('admin/logins.php')) ?>">View all →</a>
        </div>
        <?php if (!$logins): ?>
          <p class="dash-empty">No logins recorded yet.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($logins as $l): ?>
              <div class="dash-row">
                <span class="av"><?= e($ini($l['actor'])) ?></span>
                <span class="dash-main"><strong><?= e($l['actor']) ?></strong><small><?= e($l['role']) ?> · <?= e($l['ip']) ?></small></span>
                <span class="dash-end"><?= e(date('M j, g:i A', strtotime($l['created_at']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</div>
<?php admin_end();