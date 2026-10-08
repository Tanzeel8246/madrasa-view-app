<?php
$pdo = new PDO('mysql:host=localhost;dbname=madrasa_ems', 'root', '');
$stmt = $pdo->query('SHOW TRIGGERS');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
