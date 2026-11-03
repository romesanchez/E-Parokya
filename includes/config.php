<?php
// Database settings. Override with environment variables or edit the defaults.
// DB_DRIVER = "mysql" (default) or "sqlite" (zero-setup, good for a quick try).
return [
    'driver'  => getenv('DB_DRIVER') ?: 'sqlite',
    'host'    => getenv('DB_HOST') ?: '127.0.0.1',
    'port'    => getenv('DB_PORT') ?: '3306',
    'name'    => getenv('DB_NAME') ?: 'parish_service_compass',
    'user'    => getenv('DB_USER') ?: 'root',
    'pass'    => getenv('DB_PASS') ?: '',
    'sqlite'  => getenv('DB_SQLITE') ?: __DIR__ . '/../data/parish.sqlite',
    'admin_code' => getenv('ADMIN_CODE') ?: 'CHANGE-ME-2026',   // needed to create an admin account
    // Admin site only: addresses allowed to reach it. Empty = anyone. Example: ['127.0.0.1', '::1', '192.168.1.0/24']
    'admin_allowed_ips' => getenv('ADMIN_ALLOWED_IPS') ? array_map('trim', explode(',', getenv('ADMIN_ALLOWED_IPS'))) : [],
];
