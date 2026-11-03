<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$f = [
    'q'       => get_str('q'),
    'status'  => get_str('status'),
    'service' => get_str('service'),
    'method'  => get_str('method'),
    'from'    => get_str('from'),
    'to'      => get_str('to'),
];
$qs = array_filter($f, fn($v) => $v !== '');
$self = url('admin/transactions.php');
$selfQ = $self . ($qs ? '?' . http_build_query($qs) : '');

/* ---- Bulk actions (archive) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!csrf_ok()) {
        admin_flash('err', 'Your session expired. Please try again.');
    } elseif ($act === 'archive_selected') {
        $n = 0;
        foreach ((array)($_POST['ids'] ?? []) as $raw) {
            $id = (int)filter_var($raw, FILTER_VALIDATE_INT);
            $r = $id ? get_request($id) : null;
            if ($r && archive_request($id)) {
                log_action('request_archived', "{$r['reference_no']} {$r['full_name']}");
                $n++;
            }
        }
        admin_flash($n ? 'ok' : 'err', $n ? "{$n} request(s) archived." : 'Nothing archived. Only completed or declined requests can be archived.');
    } elseif ($act === 'archive_completed') {
        $n = archive_all_finished();
        if ($n) {
            log_action('request_archived', "Bulk: {$n} completed request(s) archived");
        }
        admin_flash('ok', "{$n} completed request(s) archived.");
    }
    redirect($selfQ);
}

/* ---- CSV export of the current filter ---- */
if (isset($_GET['export'])) {
    [$all] = admin_requests($f, 1, 5000);
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="transactions-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Ref. No.', 'Received', 'Name', 'Email', 'Phone', 'Service', 'Method', 'Preferred date', 'Status', 'Scheduled date', 'Time', 'Venue']);
    foreach ($all as $r) {
        fputcsv($out, array_map($safe, [
            $r['reference_no'], $r['created_at'], $r['full_name'], $r['email'], $r['phone'],
            SERVICE_LABELS[$r['service_type']] ?? $r['service_type'], $r['method'], $r['preferred_date'],
            STATUS_LABELS[$r['status']] ?? $r['status'], $r['event_date'], $r['event_time'], $r['venue'],
        ]));
    }
    exit;
}

$page = page_param();
[$rows, $total] = admin_requests($f, $page);
$counts = status_counts();

admin_start('Transactions', 'transactions');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Transactions</h1>
      <p class="muted small">All active service requests. Open a row to view, edit or archive it. <?= (int)$counts['completed'] ?> completed request(s) can be archived.</p>
    </div>
    <div class="toolbar">
      <a class="btn btn-primary" href="<?= e(url('admin/assisted.php')) ?>">+ New request</a>
      <a class="btn btn-outline" href="<?= e($self . '?' . http_build_query($qs + ['export' => 1])) ?>">Export CSV</a>
    </div>
  </div>

  <form method="get" action="<?= e($self) ?>" class="card filters">
    <div><label for="q">Name, email, phone or Ref. No.</label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="e.g. EP-2026-000001"></div>
    <div><label for="status">Status</label>
      <select id="status" name="status"><option value="">All</option>
        <?php foreach (STATUS_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="service">Service</label>
      <select id="service" name="service"><option value="">All</option>
        <?php foreach (SERVICE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['service'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="method">Method</label>
      <select id="method" name="method"><option value="">All</option>
        <?php foreach (METHOD_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['method'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="from">Received from</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div><label for="to">Received to</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <div><button class="btn btn-primary" type="submit">Filter</button> <a class="btn btn-outline" href="<?= e($self) ?>">Reset</a></div>
  </form>

  <form method="post" action="<?= e($selfQ) ?>">
    <?= csrf_field() ?>
    <div class="bulkbar">
      <button class="btn btn-outline btn-sm" type="submit" name="action" value="archive_selected" onclick="return confirm('Archive the selected requests? Only completed or declined ones will be archived.')">Archive selected</button>
      <?php if ($counts['completed']): ?>
        <button class="btn btn-outline btn-sm" type="submit" name="action" value="archive_completed" onclick="return confirm('Archive ALL completed requests (<?= (int)$counts['completed'] ?>)?')">Archive all completed</button>
      <?php endif; ?>
      <span class="muted small"><?= (int)$total ?> result(s)</span>
    </div>

    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th><input type="checkbox" data-check-all aria-label="Select all"></th><th>Ref. No.</th><th>Received</th><th>Client</th><th>Service</th><th>Method</th><th>Status</th><th>Schedule</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="muted">No matching requests.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $done = in_array($r['status'], ['completed', 'declined'], true); ?>
          <tr>
            <td><?php if ($done): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" data-row-check aria-label="Select <?= e($r['full_name']) ?>"><?php endif; ?></td>
            <td style="white-space:nowrap"><strong><?= e($r['reference_no']) ?></strong></td>
            <td><?= e(fmt_date($r['created_at'])) ?></td>
            <td><strong><?= e($r['full_name']) ?></strong><span class="sub"><?= e($r['email'] ?: '—') ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></span></td>
            <td><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?></td>
            <td><span class="badge <?= $r['method'] === 'assisted' ? 'badge-approved' : 'badge-completed' ?>"><?= e(METHOD_LABELS[$r['method']] ?? $r['method']) ?></span>
              <?php if ($r['method'] === 'assisted' && $r['staff_name']): ?><span class="sub">by <?= e($r['staff_name']) ?></span><?php endif; ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td><?php if ($r['event_date']): ?><?= e(fmt_date($r['event_date'])) ?><span class="sub"><?= e(fmt_time($r['event_time'])) ?> · <?= e($r['venue']) ?></span>
                <?php elseif ($r['status'] === 'approved'): ?><a class="link" href="<?= e(url('admin/schedule.php?request=' . (int)$r['id'])) ?>">Set</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><a class="link" href="<?= e(url('admin/request.php?id=' . (int)$r['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?php pager($page, $total, PER_PAGE, $self, $qs); ?>
</div>
<?php admin_end();
