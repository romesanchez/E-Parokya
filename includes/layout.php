<?php
require_once __DIR__ . '/helpers.php';

/** "Mark Jerome Sanchez" -> "MS" (first + last word), "Anna" -> "A". */
function user_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

/** Same name always gets the same colour, like Google accounts. */
function avatar_color(string $name): string
{
    $colors = ['#b3261e', '#9a3412', '#a16207', '#3f6212', '#0f766e', '#1d4ed8', '#6d28d9', '#a21caf'];
    return $colors[(crc32(mb_strtolower(trim($name))) & 0x7fffffff) % count($colors)];
}

function page_start(string $title, string $description, string $active = ''): void
{
    $nav = fn(string $key, string $href, string $label, string $cls = 'nav-link') =>
        '<a href="' . $href . '" class="' . $cls . ($active === $key ? ' active' : '') . '">' . $label . '</a>';
    $u = current_user();
    $side = $u && $u['role'] === 'user';
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:type" content="website">
  <link rel="icon" href="favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
<?php if ($side): ?>
  <script>
  // Decide before the page paints so the panel does not flash. Phones always start closed.
  (function () {
    try {
      var s = localStorage.getItem('psc_side');
      var open = window.innerWidth >= 900 ? (s === null ? true : s === '1') : false;
      if (open) { document.documentElement.classList.add('side-open'); }
    } catch (e) {}
  })();
  </script>
<?php endif; ?>
</head>
<body>
<?php if ($side): ?>
<div class="side-backdrop" data-side-close></div>
<aside class="user-side" id="user-side" aria-label="Your account">
  <button type="button" class="side-close" data-side-close aria-label="Hide panel">✕</button>
  <div class="user-card">
    <div class="avatar" style="background:<?= e(avatar_color($u['name'])) ?>" aria-hidden="true"><?= e(user_initials($u['name'])) ?></div>
    <p class="user-name"><?= e($u['name']) ?></p>
    <p class="user-email"><?= e($u['email']) ?></p>
  </div>
  <form method="post" action="<?= e(url('logout.php')) ?>" class="user-logout">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline btn-block">Log out</button>
  </form>
</aside>
<?php endif; ?>
<div class="site">
  <header class="site-header">
    <div class="container header-inner">
      <div class="head-left">
<?php if ($side): ?>
        <button type="button" class="burger" id="side-toggle" aria-label="Show or hide your account panel" aria-controls="user-side" aria-expanded="false"><span></span><span></span><span></span></button>
<?php endif; ?>
        <a href="index.php" class="brand">
          <span class="brand-mark">✝</span>
          <span class="brand-text">
            <span class="brand-name">St. Joseph Parish</span>
            <span class="brand-tag">Sancta Familia Ora Pro Nobis</span>
          </span>
        </a>
      </div>
      <nav class="nav">
        <?= $nav('home', 'index.php', 'Home') ?>
        <?= $nav('request', 'request.php', 'Request a Service') ?>
        <?= $nav('documents', 'documents.php', 'Documents') ?>
        <?php if ($side): ?>
          <?= $nav('mine', 'my-requests.php', 'My Requests') ?>
        <?php elseif (!$u): ?>
          <?= $nav('login', 'login.php', 'Log in', 'nav-link nav-office') ?>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <main>
<?php
}

function page_end(): void
{
    $u = current_user();
    $side = $u && $u['role'] === 'user';
    ?>
  </main>
  <footer class="site-footer">
    <div class="container footer-inner">
      <div>
        <p class="footer-title">St. Joseph Parish</p>
        <p class="muted small">12 Mabini Street, San Roque · (02) 8555-0120</p>
        <p class="muted small">parishoffice@stjoseph.org · Open Mon–Sat, 8:00 AM – 5:00 PM</p>
      </div>
      <div class="muted small footer-right">
        <p>“Pray and work.” — Rule of St. Benedict</p>
        <p class="footer-copy">© <?= date('Y') ?> St. Joseph Parish. All rights reserved.</p>
      </div>
    </div>
  </footer>
</div>
<?php render_modal(); ?>
<?php if ($side): ?>
<script>
(function () {
  var root = document.documentElement, btn = document.getElementById('side-toggle');
  if (!btn) { return; }
  function sync() { btn.setAttribute('aria-expanded', root.classList.contains('side-open') ? 'true' : 'false'); }
  function setOpen(open) {
    root.classList.toggle('side-open', open);
    sync();
    try { localStorage.setItem('psc_side', open ? '1' : '0'); } catch (e) {}
  }
  sync();
  btn.addEventListener('click', function () { setOpen(!root.classList.contains('side-open')); });
  document.querySelectorAll('[data-side-close]').forEach(function (el) {
    el.addEventListener('click', function () { setOpen(false); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && window.innerWidth < 900 && root.classList.contains('side-open')) { setOpen(false); }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}