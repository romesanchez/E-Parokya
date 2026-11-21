<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php'; // SERVICE_LABELS is used by request and related pages.

/** "Mark Jerome Sanchez" -> "MS" (first + last word), "Anna" -> "A". */
function user_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (!$parts) {
        return '?';
    }

    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : '';

    return $first . $last;
}

/** Same name always gets the same colour, like Google accounts. */
function avatar_color(string $name): string
{
    $colors = ['#b3261e', '#9a3412', '#a16207', '#3f6212', '#0f766e', '#1d4ed8', '#6d28d9', '#a21caf'];
    $index = (crc32(mb_strtolower(trim($name))) & 0x7fffffff) % count($colors);

    return $colors[$index];
}

function page_start(string $title, string $description, string $active = ''): void
{
    $u = current_user();
    $side = $u && $u['role'] === 'user';
    ?>
<!doctype html>
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=4">
    <script src="assets/datepicker.js" defer></script>
</head>
<body<?= $side ? ' class="has-side"' : '' ?>>
<?php if ($side): ?>
<div class="acct" id="acct">
    <button type="button" class="acct-burger" id="acct-toggle" aria-label="Account menu" aria-expanded="false" aria-controls="acct-card">
        <span></span><span></span><span></span>
    </button>

    <aside class="acct-card" id="acct-card" aria-label="Your account">
        <button type="button" class="acct-close" id="acct-close" aria-label="Close account menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                <path d="M5 5l14 14M19 5L5 19"/>
            </svg>
        </button>

        <div class="acct-av" style="background:<?= e(avatar_color($u['name'])) ?>" aria-hidden="true">
            <?= e(user_initials($u['name'])) ?>
        </div>
        <p class="acct-name"><?= e($u['name']) ?></p>
        <p class="acct-email"><?= e($u['email']) ?></p>

        <nav class="acct-nav" aria-label="Account navigation">
            <p class="acct-nav-title">Account</p>
            <a href="<?= e(url('my-requests.php')) ?>" class="acct-link">My Requests</a>

            <p class="acct-nav-title">Parish Information</p>
            <a href="<?= e(url('events.php')) ?>" class="acct-link">Events</a>
            <a href="<?= e(url('info.php?p=contact')) ?>" class="acct-link">Contact Us</a>
            <a href="<?= e(url('info.php?p=privacy')) ?>" class="acct-link">Privacy Policy</a>
            <a href="<?= e(url('info.php?p=agreement')) ?>" class="acct-link">Service Agreement</a>
            <a href="<?= e(url('info.php?p=security')) ?>" class="acct-link">Learn About Security</a>
        </nav>

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
                    <span class="brand-mark" aria-hidden="true">✝</span>
                    <span class="brand-text">
                        <span class="brand-name">St. Joseph Parish</span>
                        <span class="brand-tag">Sancta Familia Ora Pro Nobis</span>
                    </span>
                </a>
            </div>

            <nav class="nav" aria-label="Main navigation">
                <?php if (!$u): ?>
                    <a href="login.php" class="nav-login">Log in</a>
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
    var box = document.getElementById('acct');
    var btn = document.getElementById('acct-toggle');
    var closeBtn = document.getElementById('acct-close');

    if (!box || !btn || !closeBtn) {
        return;
    }

    function setOpen(open) {
        box.classList.toggle('open', open);
        document.documentElement.classList.toggle('acct-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        (open ? closeBtn : btn).focus();
    }

    btn.addEventListener('click', function () {
        setOpen(true);
    });

    closeBtn.addEventListener('click', function () {
        setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && box.classList.contains('open')) {
            setOpen(false);
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}
