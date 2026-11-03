<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
        $error = 'Password must be 8 to 72 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } elseif (find_user_by_email($email)) {
        $error = 'An account with this email already exists.';
    } else {
        try {
            $id = create_user($name, $email, $pass, 'user');
            login_session(['id' => $id, 'full_name' => $name, 'email' => $email, 'role' => 'user']);
            log_action('register', 'New parishioner account');
            flash_modal('ACCOUNT CREATED', 'Your account is ready and you are signed in. You can close this window and send your first request.');
            redirect('my-requests.php');
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = 'Could not create your account. Please try again.';
        }
    }
}

page_start('Sign up — St. Joseph Parish', 'Create an account to request parish services.', 'register');
?>
<div class="container narrow-page">
  <div class="center">
    <h1 class="page-title">Create an account</h1>
    <div class="rule"></div>
    <p class="muted small mt-3">One account lets you send requests and follow their progress.</p>
  </div>
  <form method="post" action="register.php" class="card form mt-6">
    <?= csrf_field() ?>
    <div class="field">
      <label for="full_name">Full name</label>
      <input id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($name) ?>">
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" value="<?= e($email) ?>">
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password2">Confirm password</label>
        <input id="password2" name="password2" type="password" required minlength="8" autocomplete="new-password">
      </div>
    </div>
    <?php if ($error): ?><p class="alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-block">Sign up</button>
    <p class="center muted small">Already registered? <a href="login.php" class="link">Log in</a></p>
  </form>
</div>
<?php page_end();