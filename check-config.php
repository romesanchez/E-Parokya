<?php
echo "ENVIRONMENT VARIABLE: ";
var_dump(getenv('DB_DRIVER'));

$config = require __DIR__ . '/includes/config.php';

echo "CONFIG DRIVER: ";
var_dump($config['driver']);
