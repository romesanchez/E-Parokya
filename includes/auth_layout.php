<?php
require_once __DIR__ . '/helpers.php';

function auth_start(
    string $title,
    string $brandName = 'St. Joseph Parish',
    string $brandTag = 'Sancta Familia Ora Pro Nobis',
    ?string $brandHref = null
): void {
    $brandHref ??= url('index.php');
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="icon" href="<?= e(url('favicon.ico')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/auth.css')) ?>">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-left">
    <a href="<?= e($brandHref) ?>" class="brand auth-brand">
      <span class="brand-mark">✝</span>
      <span class="brand-text">
        <span class="brand-name"><?= e($brandName) ?></span>
        <span class="brand-tag"><?= e($brandTag) ?></span>
      </span>
    </a>
<?php
}

function auth_end(): void
{
    ?>
  </section>
</div>
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
    setTimeout(check, 300); // browser autofill
  });
})();
</script>
<?php render_modal(); ?>
</body>
</html>
<?php
}