<?php
require "includes/db.php";

try {
    $pdo = db();

    echo "CONNECTION SUCCESSFUL\n";
    echo "DRIVER: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
    echo "DATABASE: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";

    echo "\nDATABASE TABLES:\n";

    foreach ($pdo->query("SHOW TABLES") as $table) {
        echo "- " . $table[0] . "\n";
    }

    echo "\nTOTAL USERS: ";
    echo $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "\n";

} catch (Throwable $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
}
