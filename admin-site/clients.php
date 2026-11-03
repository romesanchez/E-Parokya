<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$self = url('admin/clients.php');
$q = get_str('q');
$editId = (int)(filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0);
$selfQ = $self . ($q !== '' ? '?q=' . urlencode($q) : '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (int)(filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $c = $id ? get_client($id) : null;
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$c) {
        $error = 'Client not found.';
    } elseif ($act === 'disable' || $act === 'enable') {
        set_client_active($id, $act === 'enable');
        log_action($act === 'enable' ? 'client_enabled' : 'client_disabled', $c['email']);
        admin_flash('ok', $c['full_name'] . ($act === 'enable' ? ' can log in again.' : ' can no longer log in.'));
        redirect($selfQ);
    } elseif ($act === 'delete') {
        delete_client($id);
        log_action('client_deleted', $c['email']);
        admin_flash('ok', 'Account deleted. Their requests are still in Transactions as "no account".');
        redirect($selfQ);
    } elseif ($act === 'save') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $newpass = (string)($_POST['new_password'] ?? '');
        $other = find_user_by_email($email);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $error = 'Please enter a valid name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $error = 'Please enter a valid email address.';
        } elseif ($other && (int)$other['id'] !== $id) {
            $error = 'That email is already used by another account.';
        } elseif ($newpass !== '' && (strlen($newpass) < 8 || strlen($newpass) > 72)) {
            $error = 'New password must be 8 to 72 characters.';
        } else {
            update_client($id, $name, $email);
            if ($newpass !== '') {
                set_client_password($id, $newpass);
            }
            log_action('client_updated', $email . ($newpass !== '' ? ' (password reset)' : ''));
            admin_flash('ok', 'Client updated.');
            redirect($selfQ);
        }
        $editId = $id;
    }
}

$page = page_param();
[$rows, $total] = admin_clients($q, $page);
$edit = $editId ? get_client($editId) : null;

admin_start('Clients', 'clients');
?>
<div class="container wide">
  <div class="page-head">
    <div>
      <h1 class="page-title left">Clients</h1>
      <p class="muted small">Parishioner accounts registered on the public website: when they joined, how often they log in, and how many requests they have.</p>
    </div>
  </div>
  <?php if ($error): ?><p class="flash flash-err" role="alert"><?= e($error) ?></p><?php endif; ?>

  <?php if ($edit): ?>
    <form method="post" action="<?= e($selfQ) ?>" class="card panel form-grid" style="margin:1rem 0">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="full"><h2 style="font:600 1.25rem var(--font-display)">Edit client</h2></div>
      <div><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" value="<?= e($_POST['full_name'] ?? $edit['full_name']) ?>"></div>
      <div><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="255" value="<?= e($_POST['email'] ?? $edit['email']) ?>"></div>
      <div class="full"><label for="new_password">Reset password <span class="muted normal">(leave blank to keep the current one)</span></label><input id="new_password" name="new_password" type="text" autocomplete="off" maxlength="72" placeholder="At least 8 characters"></div>
      <div class="full toolbar"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-outline" href="<?= e($selfQ) ?>">Cancel</a></div>
    </form>
    <?php $hist = client_logins((int)$edit['id']); if ($hist): ?>
      <section class="card panel" style="margin-bottom:1rem"><h2>Recent activity of <?= e($edit['full_name']) ?></h2>
        <table class="mini"><?php foreach ($hist as $h): ?><tr><td><?= e(LOG_LABELS[$h['action']] ?? $h['action']) ?></td><td class="muted"><?= e($h['ip']) ?></td><td class="muted" style="text-align:right"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td></tr><?php endforeach; ?></table>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <form method="get" action="<?= e($self) ?>" class="card filters">
    <div><label for="q">Name or email</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <div><button class="btn btn-primary" type="submit">Search</button> <a class="btn btn-outline" href="<?= e($self) ?>">Reset</a></div>
  </form>

  <p class="muted small" style="margin:.75rem 0 .5rem"><?= (int)$total ?> client(s)</p>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Client</th><th>Registered</th><th>Last login</th><th>Logins</th><th>Requests</th><th>Account</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">No clients found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): $on = (int)$c['active'] === 1; ?>
        <tr>
          <td><strong><?= e($c['full_name']) ?></strong><span class="sub"><?= e($c['email']) ?></span></td>
          <td><?= e(fmt_date($c['created_at'])) ?></td>
          <td><?= $c['last_login'] ? e(date('M j, Y g:i A', strtotime($c['last_login']))) : '<span class="muted">Never</span>' ?></td>
          <td><?= (int)$c['login_count'] ?></td>
          <td><a class="link" href="<?= e(url('admin/transactions.php?q=' . urlencode($c['email']))) ?>"><?= (int)$c['req_count'] ?></a><?= $c['open_count'] ? '<span class="sub">' . (int)$c['open_count'] . ' open</span>' : '' ?></td>
          <td><span class="badge <?= $on ? 'badge-completed' : 'badge-declined' ?>"><?= $on ? 'Active' : 'Disabled' ?></span></td>
          <td>
            <div class="row-actions">
              <a class="link" href="<?= e($selfQ . ($q !== '' ? '&' : '?') . 'edit=' . (int)$c['id']) ?>">Edit</a>
              <form method="post" action="<?= e($selfQ) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="<?= $on ? 'disable' : 'enable' ?>">
                <button class="linkbtn link" type="submit" <?= $on ? 'onclick="return confirm(\'Disable this account? They will not be able to log in.\')"' : '' ?>><?= $on ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="<?= e($selfQ) ?>" onsubmit="return confirm('Delete this account? Their requests stay in Transactions without an account. This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="linkbtn link link-danger" type="submit">Delete</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php pager($page, $total, PER_PAGE, $self, $q !== '' ? ['q' => $q] : []); ?>
</div>
<?php admin_end();
