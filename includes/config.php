<?php
// Database settings.
// Set DB_DRIVER=sqlite to use data/parish.sqlite (no MySQL needed).
return [
    'driver'  => getenv('DB_DRIVER') ?: 'mysql',
    'host'    => getenv('DB_HOST') ?: '127.0.0.1',
    'port'    => getenv('DB_PORT') ?: '3306',
    'name'    => getenv('DB_NAME') ?: 'parish_service_compass',
    'user'    => getenv('DB_USER') ?: 'root',
    'pass'    => getenv('DB_PASS') ?: '',
    'sqlite'  => getenv('DB_SQLITE') ?: __DIR__ . '/../data/parish.sqlite',
    'admin_code' => getenv('ADMIN_CODE') ?: 'CHANGE-ME-2026',
    // Password reset. public_url = address of the PUBLIC site (no trailing slash), used in reset links,
    // e.g. 'https://parish.example.org' or 'http://localhost/parish/public'.
    'public_url' => getenv('PUBLIC_URL') ?: '',
    'mail_from'  => getenv('MAIL_FROM') ?: 'no-reply@stjoseph.org',
    // TESTING ONLY: set RESET_SHOW_LINK=1 to show the reset link on screen (e.g. XAMPP without a mail server).
    'reset_show_link' => getenv('RESET_SHOW_LINK') === '1',
    'admin_allowed_ips' => getenv('ADMIN_ALLOWED_IPS')
        ? array_map('trim', explode(',', getenv('ADMIN_ALLOWED_IPS')))
        : [],
];