<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_layout.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (too_many_failures($email, 'login_failed')) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $u = find_user_by_email($email);
        if ($u && $u['role'] === 'user' && (int)($u['active'] ?? 1) === 1 && password_verify($pass, $u['password_hash'])) {
            login_session($u);
            log_action('login', 'User logged in');
            flash_modal('LOGIN SUCCESSFUL', 'You have successfully signed into your account. You can close this window and continue using the parish website.', 'my-requests.php');
        } else {
            log_action('login_failed', 'Failed login attempt', $email, null, 'guest');
            $error = 'Incorrect email or password.';
        }
    }
}

auth_start('Log in — St. Joseph Parish');
?>
    <h1 class="auth-title">Welcome!</h1>

    <form method="post" action="login.php" class="auth-form">
      <?= csrf_field() ?>
      <div class="auth-field">
        <label for="email" class="sr-only">Email</label>
        <input id="email" name="email" type="email" placeholder="Email" required autocomplete="email" value="<?= e($email) ?>">
      </div>
      <div class="auth-field">
        <label for="password" class="sr-only">Password</label>
        <input id="password" name="password" type="password" placeholder="Password" required autocomplete="current-password">
        <button type="button" class="auth-eye" data-toggle-pw aria-label="Show password">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
        </button>
      </div>

      <?php if ($error): ?><p class="auth-error" role="alert"><?= e($error) ?></p><?php endif; ?>

      <button type="submit" class="auth-submit" disabled>Log in</button>
    </form>

    <p class="auth-forgot"><a href="forgot-password.php">Forgot your password?</a> Can't get the email? <a href="<?= e(url('info.php?p=contact')) ?>">Contact the parish office</a>.</p>

    <div class="auth-or"><span>OR</span></div>

    <a href="register.php" class="auth-register">Register now</a>

    <div class="auth-rows">
      <a href="request.php" class="auth-row">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M9 12h7M9 16h7"/></svg>
        <span class="auth-row-text"><strong>Request a service</strong><small>Baptism, wedding, funeral, blessing and more</small></span>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </a>
      <a href="index.php#mass-times" class="auth-row">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>
        <span class="auth-row-text"><strong>Mass schedule and information</strong><small>Mass times, confessions and parish services</small></span>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </a>
    </div>

    <p class="auth-note">Parish office hours: Mon–Sat, 8:00 AM – 5:00 PM. For inquiries, email <a href="mailto:parishoffice@stjoseph.org">parishoffice@stjoseph.org</a> or call (02) 8555-0120.</p>
<?php auth_end();