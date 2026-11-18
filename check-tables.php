<?php
require "includes/db.php";

$pdo = db();

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "MYSQL TABLES:\n";

foreach ($tables as $table) {
    echo "- " . $table . "\n";
}
