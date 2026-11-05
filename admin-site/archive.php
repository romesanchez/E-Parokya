<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$f = ['q' => get_str('q'), 'service' => get_str('service'), 'from' => get_str('from'), 'to' => get_str('to'), 'archived' => 1];
$qs = array_filter(['q' => $f['q'], 'service' => $f['service'], 'from' => $f['from'], 'to' => $f['to']], fn($v) => $v !== '');
$self = url('admin/archive.php');
$selfQ = $self . ($qs ? '?' . http_build_query($qs) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $ids = array_filter(array_map(fn($x) => (int)filter_var($x, FILTER_VALIDATE_INT), (array)($_POST['ids'] ?? [])));
    if (!csrf_ok()) {
        admin_flash('err', 'Your session expired. Please try again.');
    } elseif (!$ids) {
        admin_flash('err', 'Select at least one request first.');
    } elseif (in_array($act, ['restore', 'delete'], true)) {
        $n = 0;
        foreach ($ids as $id) {
            $r = get_request($id);
            if (!$r) {
                continue;
            }
            if ($act === 'restore' && restore_request($id)) {
                log_action('request_restored', "{$r['reference_no']} {$r['full_name']}");
                $n++;
            } elseif ($act === 'delete' && purge_request($id)) {
                log_action('request_deleted', "{$r['reference_no']} {$r['full_name']}");
                $n++;
            }
        }
        admin_flash('ok', $n . ' request(s) ' . ($act === 'restore' ? 'restored.' : 'deleted permanently.'));
    }
    redirect($selfQ);
}

$page = page_param();
[$rows, $total] = admin_requests($f, $page);

admin_start('Archive', 'archive');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Archive</h1>
      <p class="muted small">Finished clients that were removed from the tracker. Restore them if archived by mistake, or delete them permanently once the parish no longer needs the record.</p>
    </div>
  </div>

  <form method="get" action="<?= e($self) ?>" class="card filters">
    <div><label for="q">Name, email or Ref. No.</label><input id="q" name="q" value="<?= e($f['q']) ?>"></div>
    <div><label for="service">Service</label>
      <select id="service" name="service"><option value="">All</option>
        <?php foreach (SERVICE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['service'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="from">Received from</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div><label for="to">Received to</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <div><button class="btn btn-info" type="submit">Filter</button> <a class="btn btn-outline" href="<?= e($self) ?>">Reset</a></div>
  </form>

  <form method="post" action="<?= e($selfQ) ?>">
    <?= csrf_field() ?>
    <div class="bulkbar">
      <button class="btn btn-info btn-sm" type="submit" name="action" value="restore">Restore selected</button>
      <button class="btn-danger btn-sm" type="submit" name="action" value="delete" onclick="return confirm('Permanently delete the selected requests? This cannot be undone.')">Delete selected permanently</button>
      <span class="muted small"><?= (int)$total ?> archived</span>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th><input type="checkbox" data-check-all aria-label="Select all"></th><th>Ref. No.</th><th>Client</th><th>Service</th><th>Final status</th><th>Received</th><th>Archived</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">The archive is empty.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" data-row-check aria-label="Select <?= e($r['full_name']) ?>"></td>
            <td style="white-space:nowrap"><strong><?= e($r['reference_no']) ?></strong></td>
            <td><strong><?= e($r['full_name']) ?></strong><span class="sub"><?= e($r['email'] ?: '—') ?></span></td>
            <td><?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td><?= e(fmt_date($r['created_at'])) ?></td>
            <td><?= e(fmt_date($r['archived_at'])) ?></td>
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