<?php
require 'db.php';
echo "Host: " . getenv('MADRASA_DB_HOST') . "\n";
echo "DB: " . getenv('MADRASA_DB_NAME') . "\n";
echo "User: " . getenv('MADRASA_DB_USER') . "\n";
echo "Pass: " . getenv('MADRASA_DB_PASS') . "\n";

echo "Actual Host: $host\n";
echo "Actual DB: $dbname\n";
echo "Actual User: $user\n";
?>
