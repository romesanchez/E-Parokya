<?php
require_once __DIR__ . '/helpers.php';

function admin_eye_button(): void
{
    ?>
        <button type="button" class="auth-eye" data-toggle-pw aria-label="Show password">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
        </button>
<?php
}

function admin_card_start(string $title, string $heading): void
{
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?></title>
  <link rel="icon" href="<?= e(url('favicon.ico')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/auth.css')) ?>">
</head>
<body class="admin-login">
  <div class="admin-bg" style="background-image:url('<?= e(url('assets/hero-church.jpg')) ?>')" aria-hidden="true"></div>

  <main class="admin-card">
    <a href="<?= e(url('admin/')) ?>" class="brand auth-brand">
      <span class="brand-mark">✝</span>
      <span class="brand-text">
        <span class="brand-name">Parish Office</span>
        <span class="brand-tag">Staff only</span>
      </span>
    </a>

    <h1 class="auth-title"><?= e($heading) ?></h1>
<?php
}

function admin_card_end(): void
{
    ?>
  </main>

<script>
(function () {
  document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentElement.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('on', show);
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });
  document.querySelectorAll('.auth-form').forEach(function (form) {
    var btn = form.querySelector('.auth-submit');
    var req = Array.prototype.slice.call(form.querySelectorAll('input[required]'));
    function check() {
      btn.disabled = !req.every(function (i) { return i.value.trim() !== ''; });
    }
    form.addEventListener('input', check);
    form.addEventListener('change', check);
    check();
    setTimeout(check, 300);
  });
})();
</script>
<?php render_modal(); ?>
</body>
</html>
<?php
}