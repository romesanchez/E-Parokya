<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && is_admin()) {
    log_action('logout', 'Admin logged out');
    logout_session();
}
redirect(url('admin/'));