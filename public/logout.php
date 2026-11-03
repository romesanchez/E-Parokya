<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && current_user()) {
    log_action('logout', 'Logged out');
    logout_session();
}
redirect('index.php');