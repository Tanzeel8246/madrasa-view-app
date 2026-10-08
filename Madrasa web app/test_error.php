<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Testing PHP Syntax on manage_users.php:<br>";
$output = [];
$return_var = 0;
exec('php -l manage_users.php 2>&1', $output, $return_var);
echo implode("<br>", $output);
echo "<br>Return code: " . $return_var;
