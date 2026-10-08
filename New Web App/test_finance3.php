<?php
require 'Madrasa web app/db.php';
$stmt = $pdo->query('SELECT * FROM finance');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
