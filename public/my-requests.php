<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/requirements.php';

$me = require_user();

// Open one of my own uploaded documents.
if (isset($_GET['file'])) {
    $fileRow = req_row_by_id((int)$_GET['file']);
    $owner = $fileRow ? get_request((int)$fileRow['request_id']) : null;
    if (!$fileRow || !$owner || (int)$owner['user_id'] !== (int)$me['id']) {
        not_found();
    }
    req_send_file($fileRow);
}

// Upload a document for one requirement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = ['t' => 'err', 'm' => 'Something went wrong. Please try again.'];
    $rid = (int)(filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    $own = $rid ? get_request($rid) : null;
    if (req_post_too_large()) {
        $msg['m'] = 'That file is too large. The maximum size is 5 MB.';
    } elseif (!csrf_ok()) {
        $msg['m'] = 'Your session expired. Please try again.';
    } elseif (!$own || (int)$own['user_id'] !== (int)$me['id'] || !empty($own['archived_at'])
        || !in_array($own['status'], ['pending', 'approved'], true)) {
        $msg['m'] = 'Documents can no longer be added to this request.';
    } elseif (!($def = requirement_def($own['service_type'], $key))) {
        $msg['m'] = 'Unknown requirement.';
    } else {
        $cur = null;
        foreach (req_list($rid, $own['service_type']) as $q) {
            if ($q['key'] === $key) {
                $cur = $q;
            }
        }
        if ($cur && $cur['status'] === 'verified') {
            $msg['m'] = 'That document was already verified by the parish office.';
        } elseif ($err = req_store_upload($rid, $key, $_FILES['file'] ?? null)) {
            $msg['m'] = $err;
        } else {
            log_action('requirement_uploaded', "{$own['reference_no']} {$def['label']}");
            $msg = ['t' => 'ok', 'm' => $def['label'] . ' was uploaded. The parish office will review it.'];
        }
    }
    $_SESSION['req_flash'] = $msg;
    redirect('my-requests.php#req-' . $rid);
}
$flash = $_SESSION['req_flash'] ?? null;
unset($_SESSION['req_flash']);

$rows = requests_for_user($me['id']);
$comingUp = events_public_upcoming(3);

page_start('My Requests — St. Joseph Parish', 'Your service requests.', 'mine');
?>
<div class="container dash">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
    <h1 class="page-title left">My Requests</h1>
    <a href="request.php" class="btn btn-primary">New Request</a>
  </div>

  <?php if ($flash): ?><p class="<?= $flash['t'] === 'err' ? 'alert-error' : 'note small' ?>" role="alert" style="margin-bottom:1rem"><?= e($flash['m']) ?></p><?php endif; ?>

  <div class="req-list">
    <?php if (!$rows): ?><div class="empty">You have not submitted any requests yet.</div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="card req" id="req-<?= (int)$r['id'] ?>">
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

          <?php
            $items = req_list((int)$r['id'], $r['service_type']);
            $prog = req_progress($items);
            $canUpload = empty($r['archived_at']) && in_array($r['status'], ['pending', 'approved'], true);
          ?>
          <?php if ($items): ?>
            <?php $pct = $prog['required'] > 0 ? (int)round($prog['verified'] / $prog['required'] * 100) : 100; ?>
            <details class="rq"<?= ($canUpload && !$prog['ready']) ? ' open' : '' ?>>
              <summary>
                <span>Requirements</span>
                <span class="rq-count"><?= $prog['required'] > 0 ? (int)$prog['verified'] . ' of ' . (int)$prog['required'] . ' verified' : 'Optional' ?><i class="rq-chev" aria-hidden="true"></i></span>
              </summary>
              <div class="rq-bar" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></div>
              <p class="rq-help">Upload a clear photo or scan (PDF, JPG or PNG, up to 5 MB). The parish office reviews each document before approving your request.</p>

              <?php foreach ($items as $q): ?>
                <div class="rq-item">
                  <div class="rq-top">
                    <div class="rq-info">
                      <div class="rq-title"><?= e($q['label']) ?> <span class="rq-tag"><?= $q['required'] ? 'Required' : 'Optional' ?></span></div>
                      <div class="rq-hint"><?= e($q['hint']) ?></div>
                    </div>
                    <span class="badge <?= e(REQ_STATUS_BADGE[$q['status']]) ?>"><?= e($q['status'] === 'submitted' ? 'Under review' : ($q['status'] === 'verified' ? '✓ Verified' : REQ_STATUS_LABELS[$q['status']])) ?></span>
                  </div>

                  <?php if ($q['has_file']): ?>
                    <a class="rq-file" target="_blank" rel="noopener" href="my-requests.php?file=<?= (int)$q['id'] ?>"><span aria-hidden="true">📎</span><span class="rq-fname"><?= e($q['file_name']) ?></span></a>
                  <?php endif; ?>
                  <?php if ($q['status'] === 'rejected' && $q['note']): ?><p class="rq-alert">Please upload a corrected copy: <?= e($q['note']) ?></p><?php endif; ?>
                  <?php if ($q['status'] === 'verified' && $q['note'] && !$q['has_file']): ?><p class="rq-hint" style="margin-top:.4rem"><?= e($q['note']) ?></p><?php endif; ?>

                  <?php if ($canUpload && $q['status'] !== 'verified'): ?>
                    <form method="post" action="my-requests.php" enctype="multipart/form-data" class="rq-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="key" value="<?= e($q['key']) ?>">
                      <label class="rq-pick">
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-label="File for <?= e($q['label']) ?>">
                        <b><?= $q['has_file'] ? 'Choose new file' : 'Choose file' ?></b>
                        <span class="rq-name">No file chosen</span>
                      </label>
                      <button class="btn btn-primary rq-go" type="submit" disabled><?= $q['has_file'] ? 'Replace' : 'Upload' ?></button>
                      <p class="rq-err" role="alert"></p>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($comingUp): ?>
    <h2 class="h2">Coming up in the parish</h2>
    <div class="ev-list"><?php foreach ($comingUp as $ev) { render_event_card($ev); } ?></div>
    <p class="ev-more"><a href="events.php" class="btn btn-outline">All events and weddings</a></p>
  <?php endif; ?>
</div>
<style>
  .rq { margin-top: 1rem; border-top: 1px solid var(--border); padding-top: .85rem; }
  .rq > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: .75rem; font-weight: 600; font-size: .92rem; padding: .15rem 0; }
  .rq > summary::-webkit-details-marker { display: none; }
  .rq-count { display: inline-flex; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 600; color: var(--primary); }
  .rq-chev { width: .55rem; height: .55rem; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(45deg) translateY(-2px); transition: transform .15s; }
  .rq[open] .rq-chev { transform: rotate(-135deg) translateY(-2px); }
  .rq-bar { height: .4rem; border-radius: 999px; background: var(--secondary); overflow: hidden; margin: .65rem 0 .2rem; }
  .rq-bar i { display: block; height: 100%; background: var(--primary); border-radius: 999px; }
  .rq-help { margin: .45rem 0 .2rem; font-size: .8rem; color: var(--muted-foreground); }
  .rq-item { margin-top: .65rem; padding: .85rem .95rem; border: 1px solid var(--border); border-radius: .75rem; background: color-mix(in oklab, var(--secondary) 50%, var(--card)); }
  .rq-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .6rem; }
  .rq-info { min-width: 0; flex: 1; }
  .rq-title { font-weight: 600; font-size: .9rem; line-height: 1.35; }
  .rq-tag { margin-left: .25rem; font-size: .68rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted-foreground); white-space: nowrap; }
  .rq-hint { margin-top: .15rem; font-size: .8rem; color: var(--muted-foreground); }
  .rq-top .badge { flex-shrink: 0; white-space: nowrap; }
  .rq-file { display: inline-flex; align-items: center; gap: .4rem; max-width: 100%; margin-top: .55rem; font-size: .82rem; font-weight: 500; color: var(--primary); }
  .rq-file:hover { text-decoration: underline; }
  .rq-fname { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
  .rq-alert { margin-top: .55rem; padding: .5rem .7rem; border-radius: .5rem; font-size: .8rem; color: var(--destructive); background: color-mix(in oklab, var(--destructive) 9%, transparent); }
  .rq-form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: stretch; margin-top: .7rem; }
  .rq-pick { position: relative; display: flex; align-items: center; gap: .65rem; flex: 1 1 14rem; min-width: 0; padding: .6rem .8rem; border: 1.5px dashed color-mix(in oklab, var(--primary) 45%, var(--border)); border-radius: .6rem; background: var(--card); cursor: pointer; transition: border-color .15s, background .15s; }
  .rq-pick:hover { border-color: var(--primary); background: color-mix(in oklab, var(--primary) 4%, var(--card)); }
  .rq-pick:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
  .rq-pick input { position: absolute; width: 1px; height: 1px; padding: 0; margin: 0; border: 0; opacity: 0; overflow: hidden; }
  .rq-pick b { flex-shrink: 0; font-size: .82rem; font-weight: 600; color: var(--primary); }
  .rq-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .82rem; color: var(--muted-foreground); }
  .rq-go { flex: 0 0 auto; padding: .6rem 1.4rem; }
  .rq-go:disabled { opacity: .45; cursor: not-allowed; }
  .rq-err { flex-basis: 100%; margin: 0; font-size: .8rem; color: var(--destructive); }
  .rq-err:empty { display: none; }
  @media (max-width: 560px) {
    .rq-item { padding: .8rem .8rem; }
    .rq-top { flex-wrap: wrap; }
    .rq-form { flex-direction: column; }
    .rq-pick { flex: none; padding: .85rem .9rem; }
    .rq-go { width: 100%; min-height: 2.9rem; }
  }
</style>
<script>
(function () {
  var MAX = 5 * 1024 * 1024;
  document.querySelectorAll('.rq-form').forEach(function (f) {
    var inp = f.querySelector('input[type=file]'), name = f.querySelector('.rq-name'),
        go = f.querySelector('.rq-go'), err = f.querySelector('.rq-err');
    inp.addEventListener('change', function () {
      var file = inp.files && inp.files[0];
      err.textContent = '';
      if (!file) { name.textContent = 'No file chosen'; go.disabled = true; return; }
      if (file.size > MAX) {
        inp.value = ''; name.textContent = 'No file chosen'; go.disabled = true;
        err.textContent = 'That file is over 5 MB. Please choose a smaller one.';
        return;
      }
      name.textContent = file.name;
      go.disabled = false;
    });
    f.addEventListener('submit', function () { go.disabled = true; go.textContent = 'Uploading…'; });
  });
})();
</script>
<?php page_end();