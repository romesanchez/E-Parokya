<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_data.php';

/** Queue a message shown on the next admin page. $type: ok | err */
function admin_flash(string $type, string $msg): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $msg];
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(STATUS_LABELS[$status] ?? $status) . '</span>';
}

/** Page links for a list. $params = the current filters (without "page"). */
function pager(int $page, int $total, int $per, string $base, array $params = []): void
{
    $pages = max(1, (int)ceil($total / $per));
    if ($pages <= 1) {
        return;
    }
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    $link = fn(int $p) => e($base . '?' . http_build_query($params + ['page' => $p]));
    echo '<nav class="pager" aria-label="Pages">';
    echo $page > 1 ? '<a href="' . $link($page - 1) . '">← Prev</a>' : '<span class="off">← Prev</span>';
    echo '<span class="pager-info">Page ' . $page . ' of ' . $pages . ' · ' . $total . ' total</span>';
    echo $page < $pages ? '<a href="' . $link($page + 1) . '">Next →</a>' : '<span class="off">Next →</span>';
    echo '</nav>';
}

function admin_start(string $title, string $active = ''): void
{
    $me = current_user();
    $item = fn(string $key, string $href, string $label, ?int $badge = null) =>
        '<a href="' . e(url('admin/' . $href)) . '" class="side-link' . ($active === $key ? ' active' : '') . '"><span>' . $label . '</span>'
        . ($badge ? '<em class="side-badge">' . $badge . '</em>' : '') . '</a>';
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    $arch = is_admin() ? archived_count() : 0;
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> — Parish Office</title>
  <link rel="icon" href="<?= e(url('favicon.ico')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/admin.css')) ?>">
</head>
<body class="admin-app">
<?php if (is_admin()): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a href="<?= e(url('admin/dashboard.php')) ?>" class="brand side-brand">
      <span class="brand-mark">✝</span>
      <span class="brand-text"><span class="brand-name">Parish Office</span><span class="brand-tag">Staff only</span></span>
    </a>
    <nav class="side-nav">
      <p class="side-label">Overview</p>
      <?= $item('dashboard', 'dashboard.php', 'Dashboard') ?>
      <p class="side-label">Requests</p>
      <?= $item('tracker', 'tracker.php', 'Tracker') ?>
      <?= $item('transactions', 'transactions.php', 'Transactions') ?>
      <?= $item('assisted', 'assisted.php', '+ Assisted Request') ?>
      <?= $item('schedule', 'schedule.php', 'Scheduling') ?>
      <?= $item('archive', 'archive.php', 'Archive', $arch) ?>
      <p class="side-label">People</p>
      <?= $item('clients', 'clients.php', 'Clients') ?>
      <?= $item('logins', 'logins.php', 'Login Activity') ?>
      <p class="side-label">Records</p>
      <?= $item('documents', 'documents.php', 'Documents') ?>
      <p class="side-label">System</p>
      <?= $item('logs', 'logs.php', 'Activity Logs') ?>
    </nav>
    <div class="side-foot">
      <p class="small"><strong><?= e($me['name'] ?? '') ?></strong><br><span class="muted"><?= e($me['email'] ?? '') ?></span></p>
      <form method="post" action="<?= e(url('admin/logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline btn-block">Log out</button>
      </form>
    </div>
  </aside>
  <div class="content">
    <div class="topbar">
      <button type="button" class="menu-btn" id="menu-btn" aria-label="Menu" aria-controls="sidebar">☰</button>
      <span class="topbar-title"><?= e($title) ?></span>
    </div>
    <main>
<?php if ($flash): ?>
      <div class="container wide"><p class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></p></div>
<?php endif; ?>
<?php else: ?>
<div class="shell"><div class="content"><main>
<?php endif; ?>
<?php
}

function admin_end(): void
{
    ?>
    </main>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('menu-btn'), s = document.getElementById('sidebar');
  if (b && s) { b.addEventListener('click', function () { s.classList.toggle('open'); }); }
  // Select-all checkbox for bulk tables
  var all = document.querySelector('[data-check-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('[data-row-check]').forEach(function (c) { c.checked = all.checked; });
    });
  }
})();
</script>
</body>
</html>
<?php
}