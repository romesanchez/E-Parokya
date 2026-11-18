<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/password_reset.php';
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
    } elseif ($act === 'reset_link') {
        if ((int)($c['active'] ?? 1) !== 1) {
            admin_flash('err', 'This account is disabled. Enable it first, then create the link.');
        } else {
            $token = create_password_reset($id, RESET_TTL_STAFF, (int)current_user()['id']);
            $_SESSION['reset_link'] = ['id' => $id, 'link' => reset_link($token)];
            log_action('client_reset_link', $c['email']);
        }
        redirect($selfQ . ($q !== '' ? '&' : '?') . 'edit=' . $id);
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
      <div class="full toolbar"><button class="btn btn-success" type="submit">Save</button><a class="btn btn-outline" href="<?= e($selfQ) ?>">Cancel</a></div>
    </form>
    <?php
      $rl = (!empty($_SESSION['reset_link']) && (int)$_SESSION['reset_link']['id'] === (int)$edit['id']) ? $_SESSION['reset_link'] : null;
      unset($_SESSION['reset_link']);
      $pubCfg = require __DIR__ . '/../includes/config.php';
    ?>
    <section class="card panel" style="margin-bottom:1rem">
      <h2 style="font:600 1.25rem var(--font-display)">Password reset link</h2>
      <?php if ($rl): ?>
        <p class="muted small">Give this link to <?= e($edit['full_name']) ?> (in person, by call or by message). It works once and expires in 24 hours. It will not be shown again.</p>
        <input type="text" readonly onclick="this.select()" value="<?= e($rl['link']) ?>" aria-label="Password reset link" style="width:100%">
        <?php if (empty($pubCfg['public_url'])): ?><p class="muted small" style="margin-top:.5rem">Tip: set <code>public_url</code> in <code>includes/config.php</code> so this link always points to the public website.</p><?php endif; ?>
      <?php else: ?>
        <p class="muted small">If the parishioner cannot receive the reset email, create a one-time link and give it to them. They choose their own new password, so you never need to know it.</p>
        <form method="post" action="<?= e($selfQ) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="action" value="reset_link"><button class="btn btn-info" type="submit">Create reset link</button></form>
      <?php endif; ?>
    </section>
    <?php $hist = client_logins((int)$edit['id']); if ($hist): ?>
      <section class="card panel" style="margin-bottom:1rem"><h2>Recent activity of <?= e($edit['full_name']) ?></h2>
        <table class="mini"><?php foreach ($hist as $h): ?><tr><td><?= e(LOG_LABELS[$h['action']] ?? $h['action']) ?></td><td class="muted"><?= e($h['ip']) ?></td><td class="muted" style="text-align:right"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td></tr><?php endforeach; ?></table>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <form method="get" action="<?= e($self) ?>" class="card filters">
    <div><label for="q">Name or email</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <div><button class="btn btn-info" type="submit">Search</button> <a class="btn btn-outline" href="<?= e($self) ?>">Reset</a></div>
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
          <td><span class="badge <?= $on ? 'badge-approved' : 'badge-declined' ?>"><?= $on ? 'Active' : 'Disabled' ?></span></td>
          <td>
            <div class="row-actions">
              <a class="icon-btn edit" title="Edit" aria-label="Edit" href="<?= e($selfQ . ($q !== '' ? '&' : '?') . 'edit=' . (int)$c['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>
              <form method="post" action="<?= e($selfQ) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="<?= $on ? 'disable' : 'enable' ?>">
                <button class="icon-btn <?= $on ? 'disable' : 'enable' ?>" title="<?= $on ? 'Disable' : 'Enable' ?>" aria-label="<?= $on ? 'Disable' : 'Enable' ?>" type="submit" <?= $on ? 'onclick="return confirm(\'Disable this account? They will not be able to log in.\')"' : '' ?>><?= $on ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>' : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>' ?></button></form>
              <form method="post" action="<?= e($selfQ) ?>" onsubmit="return confirm('Delete this account? Their requests stay in Transactions without an account. This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="icon-btn delete" title="Delete" aria-label="Delete" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button></form>
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