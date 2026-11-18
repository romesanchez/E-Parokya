<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password_reset.php';
require_once __DIR__ . '/../includes/auth_layout.php';

if (current_user()) {
    redirect('index.php');
}

$cfg = require __DIR__ . '/../includes/config.php';
$error = null;
$sent = false;
$email = '';
$devLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (too_many_failures($email, 'reset_requested')) {
        $error = 'Too many requests. Please wait 15 minutes and try again.';
    } else {
        log_action('reset_requested', 'Password reset requested', $email, null, 'guest');

        $u = find_user_by_email($email);
        if ($u && $u['role'] === 'user' && (int)($u['active'] ?? 1) === 1) {
            try {
                $token = create_password_reset((int)$u['id']);
                $link = reset_link($token);
                $ok = send_password_reset_email($u['email'], $u['full_name'], $link);
                if (!$ok) {
                    error_log('Password reset email could not be sent for user #' . (int)$u['id'] . ' (check mail settings).');
                }
                if (!empty($cfg['reset_show_link'])) {
                    $devLink = $link;
                }
            } catch (Throwable $ex) {
                error_log($ex->getMessage());
            }
        }
        $sent = true;
    }
}

auth_start('Forgot password — St. Joseph Parish');
?>
    <h1 class="auth-title">Forgot password?</h1>

<?php if ($sent): ?>
    <p class="auth-ok" role="status">If an account exists for <strong><?= e($email) ?></strong>, we sent a link to reset the password. It works once and expires in 1 hour. Please also check your spam folder.</p>
<?php if ($devLink): ?>
    <p class="auth-note"><strong>Testing mode:</strong> <a href="<?= e($devLink) ?>">open the reset link</a></p>
<?php endif; ?>
    <p class="auth-forgot">No email after a few minutes? <a href="<?= e(url('info.php?p=contact')) ?>">Contact the parish office</a> and they can give you a reset link.</p>
    <a href="login.php" class="auth-register">Back to log in</a>
<?php else: ?>
    <p class="auth-note" style="margin-top:0">Enter the email you used to register. We will send you a link to choose a new password.</p>

    <form method="post" action="forgot-password.php" class="auth-form">
      <?= csrf_field() ?>
      <div class="auth-field">
        <label for="email" class="sr-only">Email</label>
        <input id="email" name="email" type="email" placeholder="Email" required maxlength="255" autocomplete="email" value="<?= e($email) ?>">
      </div>

      <?php if ($error): ?><p class="auth-error" role="alert"><?= e($error) ?></p><?php endif; ?>

      <button type="submit" class="auth-submit" disabled>Send reset link</button>
    </form>

    <p class="auth-forgot"><a href="login.php">Back to log in</a></p>
<?php endif; ?>

    <p class="auth-note">Parish office hours: Mon–Sat, 8:00 AM – 5:00 PM. For inquiries, email <a href="mailto:parishoffice@stjoseph.org">parishoffice@stjoseph.org</a> or call (02) 8555-0120.</p>
<?php auth_end();