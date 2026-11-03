<?php
// The separate admin website is marked by an empty file named is-admin-site.txt in its folder.
if (!defined('PSC_ADMIN_SITE') && is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/is-admin-site.txt')) {
    define('PSC_ADMIN_SITE', true);
}

// Different session names so the two sites never share a login (cookies ignore ports).
session_name(defined('PSC_ADMIN_SITE') ? 'psc_admin' : 'psc_site');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function fmt_date(?string $d, bool $year = true): string
{
    if (!$d) {
        return '';
    }
    return date($year ? 'M j, Y' : 'M j', strtotime($d));
}

function fmt_time(?string $t): string
{
    return $t ? date('g:i A', strtotime($t)) : '';
}

function get_str(string $key): string
{
    return is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
}

/* ---------- URLs (work from /, /admin/ and XAMPP subfolders) ---------- */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = preg_replace('#/admin$#', '', $dir);
    return rtrim($dir, '/');
}

function url(string $path = ''): string
{
    if (defined('PSC_ADMIN_SITE')) {
        $path = preg_replace('#^admin(/|$)#', '', ltrim($path, '/'));
    }
    return base_path() . '/' . ltrim($path, '/');
}

/* ---------- Auth ---------- */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    return [
        'id'    => (int)$_SESSION['uid'],
        'name'  => (string)($_SESSION['name'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'role'  => (string)($_SESSION['role'] ?? 'user'),
    ];
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function login_session(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid']   = (int)$u['id'];
    $_SESSION['name']  = $u['full_name'];
    $_SESSION['email'] = $u['email'];
    $_SESSION['role']  = $u['role'];
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        redirect(url('login.php'));
    }
    if ($u['role'] === 'admin') {
        redirect(url('admin/dashboard.php'));
    }
    return $u;
}

function require_admin(): array
{
    require_once __DIR__ . '/security.php';
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        if (defined('PSC_ADMIN_SITE')) {
            redirect(url('admin/'));
        }
        not_found();
    }
    // Idle timeout: 30 minutes.
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 1800) {
        logout_session();
        redirect(url('admin/'));
    }
    $_SESSION['last_seen'] = time();

    // Re-check the database so a deleted admin loses access immediately.
    $row = find_user_by_id($u['id']);
    if (!$row || $row['role'] !== 'admin') {
        logout_session();
        redirect(url('admin/'));
    }
    return $u;
}

function flash_modal(string $title, string $text, ?string $goto = null): void
{
    $_SESSION['modal'] = ['title' => $title, 'text' => $text, 'goto' => $goto];
}

function render_modal(): void
{
    if (empty($_SESSION['modal'])) {
        return;
    }
    $m = $_SESSION['modal'];
    unset($_SESSION['modal']);
    $goto = $m['goto'] ?? null;
    ?>
<div class="modal-backdrop" id="flash-modal" role="dialog" aria-modal="true" aria-labelledby="flash-modal-title">
  <div class="modal-card">
    <svg class="modal-check" viewBox="0 0 48 48" width="56" height="56" aria-hidden="true">
      <circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="2.5"/>
      <path d="M14 25l7 7 13-15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <h2 id="flash-modal-title"><?= e($m['title']) ?></h2>
    <p><?= e($m['text']) ?></p>
    <button type="button" class="btn btn-primary" id="flash-modal-close">CLOSE WINDOW</button>
  </div>
</div>
<script>
(function () {
  var m = document.getElementById('flash-modal'), b = document.getElementById('flash-modal-close');
  var go = <?= json_encode($goto, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  function close() { if (go) { location.href = go; } else { m.remove(); } }
  b.addEventListener('click', close);
  m.addEventListener('click', function (e) { if (e.target === m) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  b.focus();
})();
</script>
<?php
}

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $path = e(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>404 Not Found</title></head>'
        . '<body style="font-family:system-ui,sans-serif;margin:0">'
        . '<h1 style="background:#99c;margin:0;padding:.6em 12px;font-weight:400;font-size:1.6rem">Not Found</h1>'
        . '<p style="padding:0 12px">The requested resource <code>' . $path . '</code> was not found on this server.</p>'
        . '</body></html>';
    exit;
}

function absolute_url(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
}

// Admin site only: refuse addresses that are not on the allow-list.
if (defined('PSC_ADMIN_SITE')) {
    require_once __DIR__ . '/security.php';
    if (!admin_ip_allowed()) {
        not_found();
    }
}