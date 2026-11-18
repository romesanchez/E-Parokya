<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/requirements.php';
$me = require_admin();

$id = (int)(filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
$r = $id ? get_request($id) : null;
if (!$r) {
    admin_flash('err', 'Request not found.');
    redirect(url('admin/transactions.php'));
}
$self = url('admin/request.php?id=' . $id);

// Open an uploaded document (staff only).
if (isset($_GET['file'])) {
    $fileRow = req_row_by_id((int)$_GET['file']);
    if (!$fileRow || (int)$fileRow['request_id'] !== $id) {
        not_found();
    }
    req_send_file($fileRow);
}
$archived = !empty($r['archived_at']);
$error = null;
$v = [
    'service_type' => $r['service_type'], 'full_name' => $r['full_name'], 'email' => (string)$r['email'],
    'phone' => (string)$r['phone'], 'preferred_date' => (string)$r['preferred_date'], 'notes' => (string)$r['notes'], 'status' => $r['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (req_post_too_large()) {
        $error = 'That file is too large. The maximum size is 5 MB.';
    } elseif (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($act === 'save') {
        foreach ($v as $k => $_) {
            $v[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        }
        $v['email'] = strtolower($v['email']);
        $error = validate_request_form($v);
        if (!$error && $v['status'] !== $r['status']) {
            $error = requirements_gate($id, $v['service_type'], $v['status']);
        }
        if (!$error) {
            update_request_details($id, $v);
            log_action('request_updated', "{$r['reference_no']} {$v['full_name']}" . ($v['status'] !== $r['status'] ? ": {$r['status']} → {$v['status']}" : ''));
            admin_flash('ok', 'Request updated.');
            redirect($self);
        }
    } elseif (strpos($act, 'req_') === 0) {
        $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
        $def = requirement_def($r['service_type'], $key);
        $note = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
        $reviews = ['req_verify' => 'verify', 'req_reject' => 'reject', 'req_inperson' => 'inperson', 'req_reset' => 'reset'];
        if (!$def) {
            admin_flash('err', 'Unknown requirement.');
        } elseif ($act === 'req_upload') {
            if ($err = req_store_upload($id, $key, $_FILES['file'] ?? null)) {
                admin_flash('err', $err);
            } else {
                log_action('requirement_uploaded', "{$r['reference_no']} {$def['label']} (by staff)");
                admin_flash('ok', 'File uploaded for ' . $def['label'] . '.');
            }
        } elseif (isset($reviews[$act])) {
            if ($err = req_review($id, $key, $reviews[$act], $note, (string)($me['name'] ?? 'staff'))) {
                admin_flash('err', $err);
            } else {
                log_action('requirement_' . $reviews[$act], "{$r['reference_no']} {$def['label']}" . ($note !== '' ? ': ' . $note : ''));
                admin_flash('ok', $def['label'] . ' updated.');
            }
        }
        redirect($self);
    } elseif ($act === 'archive') {
        if (archive_request($id)) {
            log_action('request_archived', "{$r['reference_no']} {$r['full_name']}");
            admin_flash('ok', 'Archived. You can restore or delete it permanently from the Archive page.');
            redirect(url('admin/transactions.php'));
        }
        $error = 'Only completed or declined requests can be archived. Change the status first.';
    } elseif ($act === 'restore') {
        restore_request($id);
        log_action('request_restored', "{$r['reference_no']} {$r['full_name']}");
        admin_flash('ok', 'Request restored.');
        redirect($self);
    } elseif ($act === 'delete') {
        if (purge_request($id)) {
            req_purge_orphans();
            log_action('request_deleted', "{$r['reference_no']} {$r['full_name']}");
            admin_flash('ok', 'Request deleted permanently.');
            redirect(url('admin/archive.php'));
        }
        $error = 'Archive the request first, then delete it from the Archive page.';
    }
}

$history = request_history($r['reference_no']);
$reqs = req_list($id, $r['service_type']);
$prog = req_progress($reqs);
$idStatus = req_identity_status($reqs);
$reqForm = function (string $act, string $key, string $label, string $cls, bool $withNote = false) use ($self, $id): string {
    return '<form method="post" action="' . e($self) . '" class="req-inline">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . e($act) . '"><input type="hidden" name="key" value="' . e($key) . '">'
        . ($withNote ? '<input name="note" maxlength="300" placeholder="Reason (required)" required aria-label="Reason">' : '')
        . '<button class="act ' . e($cls) . '" type="submit">' . e($label) . '</button></form>';
};
admin_start($r['reference_no'], $archived ? 'archive' : 'transactions');
?>
<style>
  .req-item { padding: .8rem 0; border-top: 1px solid var(--border); }
  .req-item:first-of-type { border-top: 0; }
  .req-top { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
  .req-tag { font-size: .7rem; color: var(--muted-foreground); text-transform: uppercase; letter-spacing: .05em; margin-left: .35rem; }
  .req-acts { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; align-items: center; }
  .req-inline { display: inline-flex; gap: .35rem; align-items: center; margin: 0; }
  .req-inline input[type=text], .req-inline input:not([type]) { padding: .25rem .5rem; font-size: .8rem; border: 1px solid var(--border); border-radius: .4rem; width: 11rem; }
  .req-inline input[type=file] { font-size: .78rem; max-width: 12rem; }
  .req-note { font-size: .8rem; margin-top: .35rem; padding: .35rem .6rem; border-radius: .4rem; background: var(--secondary); }
  .req-note.bad { background: var(--c-red-bg); color: var(--c-red); }
</style>
<div class="container wide">
  <p class="small"><a class="link" href="<?= e(url($archived ? 'admin/archive.php' : 'admin/transactions.php')) ?>">← Back to <?= $archived ? 'Archive' : 'Transactions' ?></a></p>
  <div class="page-head" style="margin-top:.5rem">
    <div>
      <h1 class="page-title left"><?= e($r['full_name']) ?></h1>
      <p class="muted small"><?= e($r['reference_no']) ?> · <?= e(SERVICE_LABELS[$r['service_type']] ?? $r['service_type']) ?> · received <?= e(fmt_date($r['created_at'])) ?> · <?= e(METHOD_LABELS[$r['method']] ?? $r['method']) ?>
        <?php if ($archived): ?> · <strong>Archived <?= e(fmt_date($r['archived_at'])) ?></strong><?php endif; ?></p>
    </div>
    <div><?= status_badge($r['status']) ?></div>
  </div>
  <?php if ($error): ?><p class="flash flash-err" role="alert"><?= e($error) ?></p><?php endif; ?>

  <div class="detail-grid">
    <form method="post" action="<?= e($self) ?>" class="card panel form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>">
      <div><label for="service_type">Service</label>
        <select id="service_type" name="service_type"><?php foreach (SERVICE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['service_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="status">Status</label>
        <select id="status" name="status"><?php foreach (STATUS_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="full"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" value="<?= e($v['full_name']) ?>"></div>
      <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" value="<?= e($v['email']) ?>"></div>
      <div><label for="phone">Contact number</label><input id="phone" name="phone" maxlength="40" value="<?= e($v['phone']) ?>"></div>
      <div><label for="preferred_date">Preferred date</label><input id="preferred_date" name="preferred_date" type="date" value="<?= e($v['preferred_date']) ?>"></div>
      <div class="full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="1000"><?= e($v['notes']) ?></textarea></div>
      <div class="full toolbar"><button class="btn btn-success" type="submit">Save changes</button></div>
    </form>

    <div style="display:grid;gap:1rem;align-content:start">
      <section class="card panel">
        <div class="req-top"><h2>Requirements</h2>
          <span class="badge <?= $prog['ready'] ? 'badge-completed' : 'badge-pending' ?>"><?= (int)$prog['verified'] ?>/<?= (int)$prog['required'] ?> required verified</span></div>
        <?php if ($idStatus !== null): ?>
          <p class="small" style="margin:.4rem 0 .2rem"><span class="muted">Identity:</span>
            <span class="badge <?= e(REQ_STATUS_BADGE[$idStatus]) ?>"><?= $idStatus === 'verified' ? '✓ ID verified' : e('ID ' . strtolower(REQ_STATUS_LABELS[$idStatus])) ?></span></p>
        <?php endif; ?>
        <?php if (!$reqs): ?><p class="muted small">This service has no document requirements.</p><?php endif; ?>
        <?php if ($reqs && !$prog['ready']): ?><p class="muted small">This request cannot be Approved until every required item is verified.</p><?php endif; ?>
        <?php foreach ($reqs as $q): ?>
          <div class="req-item">
            <div class="req-top">
              <span><strong><?= e($q['label']) ?></strong><span class="req-tag"><?= $q['required'] ? 'Required' : 'Optional' ?></span></span>
              <span class="badge <?= e(REQ_STATUS_BADGE[$q['status']]) ?>"><?= e(REQ_STATUS_LABELS[$q['status']]) ?></span>
            </div>
            <p class="muted small"><?= e($q['hint']) ?></p>
            <?php if ($q['has_file']): ?>
              <p class="small"><a class="link" target="_blank" rel="noopener" href="<?= e($self . '&file=' . $q['id']) ?>">📎 <?= e($q['file_name']) ?></a>
                <span class="muted"> · uploaded <?= e(date('M j, g:i A', strtotime($q['uploaded_at']))) ?></span></p>
            <?php endif; ?>
            <?php if ($q['note']): ?><p class="req-note<?= $q['status'] === 'rejected' ? ' bad' : '' ?>"><?= e($q['note']) ?><?= $q['reviewed_by'] ? ' — ' . e($q['reviewed_by']) : '' ?></p><?php endif; ?>
            <div class="req-acts">
              <?php if ($q['status'] === 'submitted' || ($q['status'] === 'rejected' && $q['has_file'])): ?><?= $reqForm('req_verify', $q['key'], 'Verify', 'act-success') ?><?php endif; ?>
              <?php if ($q['has_file'] && $q['status'] !== 'rejected'): ?><?= $reqForm('req_reject', $q['key'], 'Reject', 'act-delete', true) ?><?php endif; ?>
              <?php if ($q['status'] !== 'verified'): ?><?= $reqForm('req_inperson', $q['key'], 'Verified in person', 'act-info') ?><?php endif; ?>
              <?php if ($q['status'] === 'verified' || $q['status'] === 'rejected'): ?><?= $reqForm('req_reset', $q['key'], 'Reset', 'act-edit') ?><?php endif; ?>
            </div>
            <?php if ($q['status'] !== 'verified'): ?>
              <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" class="req-inline" style="margin-top:.5rem">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="req_upload"><input type="hidden" name="key" value="<?= e($q['key']) ?>">
                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required aria-label="Upload file for <?= e($q['label']) ?>">
                <button class="act act-warn" type="submit"><?= $q['has_file'] ? 'Replace file' : 'Upload for client' ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="card panel">
        <h2>Details</h2>
        <dl class="kv">
          <dt>Account</dt><dd><?= e($r['account_email'] ?? 'No account (walk-in)') ?></dd>
          <dt>Processed by</dt><dd><?= e($r['staff_name'] ?? ($r['method'] === 'online' ? 'Self-service (online)' : '—')) ?></dd>
          <dt>Last update</dt><dd><?= e(date('M j, Y g:i A', strtotime($r['updated_at']))) ?></dd>
          <dt>Schedule</dt><dd><?php if ($r['event_date']): ?><?= e(fmt_date($r['event_date'])) ?> · <?= e(fmt_time($r['event_time'])) ?><br><?= e($r['venue']) ?><?= $r['officiant'] ? ' · ' . e($r['officiant']) : '' ?><?php else: ?>Not scheduled<?php endif; ?>
            <?php if (!$archived): ?><br><a class="act <?= $r['event_date'] ? 'act-edit' : 'act-success' ?>" href="<?= e(url('admin/schedule.php?request=' . $id)) ?>"><?= $r['event_date'] ? 'Edit schedule' : 'Set schedule' ?></a><?php endif; ?></dd>
        </dl>
      </section>

      <section class="card panel danger-zone">
        <h2>Finished with this client?</h2>
        <?php if ($archived): ?>
          <p class="muted small" style="margin-bottom:.6rem">This request is archived. Restore it, or delete it for good (this also removes its schedule and cannot be undone).</p>
          <div class="toolbar">
            <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn btn-info" type="submit">Restore</button></form>
            <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Delete <?= e(addslashes($r['full_name'])) ?> permanently? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn-danger" type="submit">Delete permanently</button></form>
          </div>
        <?php elseif (in_array($r['status'], ['completed', 'declined'], true)): ?>
          <p class="muted small" style="margin-bottom:.6rem">The request is <?= e(strtolower(STATUS_LABELS[$r['status']])) ?>. Archive it to remove it from the lists and the tracker.</p>
          <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Archive this client?')"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><button class="btn btn-warning" type="submit">Archive client</button></form>
        <?php else: ?>
          <p class="muted small">Still <?= e(strtolower(STATUS_LABELS[$r['status']])) ?>. Mark it <strong>Completed</strong> (or <strong>Declined</strong>) first, then you can archive it.</p>
        <?php endif; ?>
      </section>

      <section class="card panel">
        <h2>History</h2>
        <?php if (!$history): ?><p class="muted small">No recorded actions yet.</p><?php endif; ?>
        <ul class="timeline">
          <?php foreach ($history as $h): ?>
            <li><strong><?= e(LOG_LABELS[$h['action']] ?? REQ_LOG_LABELS[$h['action']] ?? $h['action']) ?></strong> <span class="muted">· <?= e($h['actor']) ?> · <?= e(date('M j, g:i A', strtotime($h['created_at']))) ?></span><br><span class="muted"><?= e($h['details']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>
  </div>
</div>
<?php admin_end();