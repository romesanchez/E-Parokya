<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');
    $row  = find_user_by_id($me['id']);

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (too_many_failures($me['email'], 'admin_password_failed')) {
        $error = 'Too many wrong attempts. Please wait 15 minutes.';
    } elseif (!$row || !password_verify($cur, $row['password_hash'])) {
        log_action('admin_password_failed', 'Wrong current password', $me['email']);
        $error = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8 || strlen($new) > 72) {
        $error = 'New password must be 8 to 72 characters.';
    } elseif ($new === $cur) {
        $error = 'New password must be different from the current one.';
    } elseif ($new !== $new2) {
        $error = 'The new passwords do not match.';
    } else {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        log_action('admin_password_changed', 'Admin changed own password');
        admin_flash('ok', 'Your password has been changed.');
        redirect(url('admin/password.php'));
    }
}

admin_start('Change Password', 'password');
?>
<div class="container narrow-page">
  <h1 class="page-title left">Change Password</h1>
  <p class="muted small mt-3">Signed in as <?= e($me['email']) ?></p>

  <form method="post" action="<?= e(url('admin/password.php')) ?>" class="card form" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field">
      <label for="current">Current password</label>
      <input id="current" name="current" type="password" required autocomplete="current-password">
    </div>
    <div class="field">
      <label for="new">New password (8+ characters)</label>
      <input id="new" name="new" type="password" required minlength="8" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="new2">Confirm new password</label>
      <input id="new2" name="new2" type="password" required minlength="8" autocomplete="new-password">
    </div>

    <?php if ($error): ?><p class="alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <button type="submit" class="btn btn-primary">Update password</button>
  </form>
</div>
<?php admin_end();