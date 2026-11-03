<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_card.php';

if (is_admin()) {
    redirect(url('admin/dashboard.php'));
}

$cfg = require __DIR__ . '/../includes/config.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$error = null;
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $code = (string)($_POST['admin_code'] ?? '');

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (too_many_failures($ip, 'admin_register_failed')) {
        $error = 'Too many wrong codes. Please wait 15 minutes.';
    } elseif (!hash_equals((string)$cfg['admin_code'], $code)) {
        log_action('admin_register_failed', 'Wrong admin code', $ip, null, 'guest');
        $error = 'Incorrect admin code.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
        $error = 'Password must be 8 to 72 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } elseif (find_user_by_email($email)) {
        $error = 'This email already has an account.';
    } else {
        try {
            $id = create_user($name, $email, $pass, 'admin');
            log_action('admin_register', 'Admin account created', $email, $id, 'admin');
            flash_modal('ACCOUNT CREATED', 'Your admin account is ready. You can now log in.');
            redirect(url('admin/'));
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = 'Could not create the account. Please try again.';
        }
    }
}

admin_card_start('Create Admin Account — Parish Office', 'Create Admin Account');
?>
    <form method="post" action="<?= e(url('admin/register.php')) ?>" class="auth-form">
      <?= csrf_field() ?>
      <div class="auth-field">
        <label for="full_name" class="sr-only">Full name</label>
        <input id="full_name" name="full_name" placeholder="Full name" required maxlength="120" value="<?= e($name) ?>">
      </div>
      <div class="auth-field">
        <label for="email" class="sr-only">Email</label>
        <input id="email" name="email" type="email" placeholder="Email" required maxlength="255" autocomplete="username" value="<?= e($email) ?>">
      </div>
      <div class="auth-field">
        <label for="password" class="sr-only">Password</label>
        <input id="password" name="password" type="password" placeholder="Password (8+ characters)" required minlength="8" autocomplete="new-password">
        <?php admin_eye_button(); ?>
      </div>
      <div class="auth-field">
        <label for="password2" class="sr-only">Confirm password</label>
        <input id="password2" name="password2" type="password" placeholder="Confirm password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="auth-field">
        <label for="admin_code" class="sr-only">Admin code</label>
        <input id="admin_code" name="admin_code" type="password" placeholder="Admin code" required autocomplete="off">
      </div>

      <?php if ($error): ?><p class="auth-error" role="alert"><?= e($error) ?></p><?php endif; ?>

      <button type="submit" class="auth-submit" disabled>Create account</button>
    </form>

    <p class="auth-forgot"><a href="<?= e(url('admin/')) ?>">Back to login</a></p>
    <p class="auth-note">The admin code is set in <code>includes/config.php</code>. Only people who know it can create an admin account.</p>
<?php admin_card_end();