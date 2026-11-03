<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

$me = require_user();
$rows = requests_for_user($me['id']);

page_start('My Requests — St. Joseph Parish', 'Your service requests.', 'mine');
?>
<div class="container dash">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
    <h1 class="page-title left">My Requests</h1>
    <a href="request.php" class="btn btn-primary">New Request</a>
  </div>

  <div class="req-list">
    <?php if (!$rows): ?><div class="empty">You have not submitted any requests yet.</div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="card req">
        <div class="req-main">
          <div class="req-head">
            <h3><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?></h3>
            <span class="badge badge-<?= e($r['status']) ?>"><?= e(STATUS_LABELS[$r['status']] ?? $r['status']) ?></span>
            <span class="badge <?= $r['method'] === 'assisted' ? 'badge-approved' : 'badge-completed' ?>"><?= e(METHOD_LABELS[$r['method']] ?? $r['method']) ?></span>
          </div>
          <p class="small"><span class="muted">Ref. No.: </span><strong><?= e($r['reference_no']) ?></strong></p>
          <p class="muted small">
            For: <?= e($r['full_name']) ?>
            <?php if ($r['preferred_date']): ?> · Preferred: <?= e(fmt_date($r['preferred_date'])) ?><?php endif; ?>
            · Sent <?= e(fmt_date($r['created_at'])) ?>
          </p>
          <?php if ($r['event_date']): ?>
            <p class="small"><span class="muted">Scheduled: </span><strong><?= e(fmt_date($r['event_date'])) ?> at <?= e(fmt_time($r['event_time'])) ?></strong>
              · <?= e($r['venue']) ?><?php if ($r['officiant']): ?> · <?= e($r['officiant']) ?><?php endif; ?></p>
          <?php elseif ($r['status'] === 'pending'): ?>
            <p class="muted small">Waiting for the parish office to review your request.</p>
          <?php elseif ($r['status'] === 'approved'): ?>
            <p class="muted small">Approved. The office will confirm your date and time soon.</p>
          <?php endif; ?>
          <?php if ($r['notes']): ?><p class="note small"><?= e($r['notes']) ?></p><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php page_end();