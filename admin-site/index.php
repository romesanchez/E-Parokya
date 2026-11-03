<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_card.php';

if (is_admin()) {
    redirect(url('admin/dashboard.php'));
}

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (too_many_failures($email, 'admin_login_failed')) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $u = find_user_by_email($email);
        // Only accounts with the "admin" role can get in. Parishioner accounts are always refused.
        if ($u && $u['role'] === 'admin' && password_verify($pass, $u['password_hash'])) {
            login_session($u);
            log_action('admin_login', 'Admin logged in');
            flash_modal('LOGIN SUCCESSFUL', 'You have successfully signed into the parish office. You can close this window and continue.');
            redirect(url('admin/dashboard.php'));
        }
        log_action('admin_login_failed', 'Failed admin login', $email, null, 'guest');
        $error = 'Incorrect email or password.';
    }
}

admin_card_start('Admin Login — Parish Office', 'Admin Login');
?>
    <form method="post" action="<?= e(url('admin/')) ?>" class="auth-form">
      <?= csrf_field() ?>
      <div class="auth-field">
        <label for="email" class="sr-only">Email</label>
        <input id="email" name="email" type="email" placeholder="Email" required autocomplete="username" value="<?= e($email) ?>">
      </div>
      <div class="auth-field">
        <label for="password" class="sr-only">Password</label>
        <input id="password" name="password" type="password" placeholder="Password" required autocomplete="current-password">
        <?php admin_eye_button(); ?>
      </div>

      <?php if ($error): ?><p class="auth-error" role="alert"><?= e($error) ?></p><?php endif; ?>

      <button type="submit" class="auth-submit" disabled>Log in</button>
    </form>

    <p class="auth-forgot">No admin account yet? <a href="<?= e(url('admin/register.php')) ?>">Create one</a></p>
    <p class="auth-note">For authorized parish personnel only. Every login and action is recorded in the activity log.</p>
<?php admin_card_end();