<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php'; // defines SERVICE_LABELS (used by the nav)

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
    $u = current_user();
    $side = $u && $u['role'] === 'user';

    // One-line description for each service (used by the search box).
    $navServices = [
        'baptism'         => 'Welcome your child into the family of God',
        'wedding'         => 'Begin your life together before God',
        'funeral'         => 'Vigil, Requiem Mass and interment',
        'blessing'        => 'A priest visits and blesses your home',
        'first_communion' => "A child's first meeting with Jesus",
        'confirmation'    => 'Be sealed with the Spirit',
        'mass_intention'  => 'Offer a Mass for a loved one',
    ];
    $searchItems = array_map(
        fn($k, $l) => ['name' => $l, 'desc' => $navServices[$k] ?? '', 'href' => 'request.php?service=' . $k],
        array_keys(SERVICE_LABELS),
        SERVICE_LABELS
    );
    $searchItems[] = ['name' => 'Mass Schedule', 'desc' => 'Sunday and weekday Mass times', 'href' => 'index.php#mass-times'];
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
</head>
<body<?= $side ? ' class="has-side"' : '' ?>>
<?php if ($side): ?>
<div class="acct" id="acct">
  <button type="button" class="acct-burger" id="acct-toggle" aria-label="Account menu" aria-expanded="false" aria-controls="acct-card"><span></span><span></span><span></span></button>
  <aside class="acct-card" id="acct-card" aria-label="Your account">
    <div class="acct-av" style="background:<?= e(avatar_color($u['name'])) ?>" aria-hidden="true"><?= e(user_initials($u['name'])) ?></div>
    <p class="acct-name"><?= e($u['name']) ?></p>
    <p class="acct-email"><?= e($u['email']) ?></p>
    <form method="post" action="<?= e(url('logout.php')) ?>" class="acct-logout">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline btn-block">Log out</button>
    </form>
  </aside>
</div>
<?php endif; ?>
<div class="site">
  <header class="site-header">
    <div class="container header-inner">
      <div class="head-left">
        <a href="index.php" class="brand">
          <span class="brand-mark">✝</span>
          <span class="brand-text">
            <span class="brand-name">St. Joseph Parish</span>
            <span class="brand-tag">Sancta Familia Ora Pro Nobis</span>
          </span>
        </a>
      </div>
      <nav class="nav" aria-label="Main">
        <!-- Services: opens on hover -->
        <div class="nav-item has-menu">
          <button type="button" class="nav-trigger<?= $active === 'request' ? ' active' : '' ?>" aria-haspopup="true" aria-expanded="false">
            Services
            <svg class="chev" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2 4l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div class="nav-menu">
            <div class="container menu-inner">
              <div class="menu-intro">
                <a href="request.php" class="menu-title">
                  Services overview
                  <svg width="28" height="18" viewBox="0 0 28 18" aria-hidden="true"><path d="M1 9h24M18 2l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <p>We're here for every milestone of your faith.</p>
              </div>
              <div class="menu-links">
                <?php foreach (SERVICE_LABELS as $key => $label): ?>
                  <a href="request.php?service=<?= e($key) ?>">
                    <?= e($label) ?>
                    <svg width="8" height="12" viewBox="0 0 8 12" aria-hidden="true"><path d="M1.5 1.5L6 6l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Search -->
        <div class="nav-item nav-search">
          <button type="button" class="search-btn" id="nav-search-btn" aria-label="Search" aria-expanded="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/></svg>
          </button>
          <div class="search-pop" id="nav-search-pop" hidden>
            <input type="search" id="nav-search-input" placeholder="Search services…" autocomplete="off" aria-label="Search services">
            <div class="search-results" id="nav-search-results"></div>
          </div>
        </div>

        <?php if ($side): ?>
          <a href="my-requests.php" class="nav-login">My Requests</a>
        <?php elseif (!$u): ?>
          <a href="login.php" class="nav-login">Log in</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <script>
  (function () {
    var items = <?= json_encode($searchItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var menuItem = document.querySelector('.has-menu');
    var trigger = menuItem.querySelector('.nav-trigger');
    var btn = document.getElementById('nav-search-btn');
    var pop = document.getElementById('nav-search-pop');
    var input = document.getElementById('nav-search-input');
    var results = document.getElementById('nav-search-results');

    function closeSearch() { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    function closeMenu() { menuItem.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); }

    function render(q) {
      q = q.trim().toLowerCase();
      results.innerHTML = '';
      var list = items.filter(function (i) { return !q || (i.name + ' ' + i.desc).toLowerCase().indexOf(q) !== -1; });
      if (!list.length) {
        var p = document.createElement('p');
        p.className = 'search-empty';
        p.textContent = 'No results found.';
        results.appendChild(p);
        return;
      }
      list.forEach(function (i) {
        var a = document.createElement('a');
        a.className = 'search-hit';
        a.href = i.href;
        var s = document.createElement('strong'); s.textContent = i.name;
        var m = document.createElement('small'); m.textContent = i.desc;
        a.appendChild(s); a.appendChild(m);
        results.appendChild(a);
      });
    }

    // Phones / tablets have no hover, so tapping toggles the menu.
    trigger.addEventListener('click', function (e) {
      if (!window.matchMedia('(hover: none)').matches) return;
      e.stopPropagation();
      closeSearch();
      var open = menuItem.classList.toggle('open');
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      closeMenu();
      if (pop.hidden) {
        pop.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        render(input.value);
        input.focus();
      } else {
        closeSearch();
      }
    });
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { var a = results.querySelector('a'); if (a) window.location = a.href; }
    });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { closeSearch(); closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSearch(); closeMenu(); } });
  })();
  </script>
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
  var box = document.getElementById('acct'), btn = document.getElementById('acct-toggle');
  if (!box || !btn) { return; }
  btn.addEventListener('click', function () {
    var open = box.classList.toggle('open');
    document.documentElement.classList.toggle('acct-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}