<?php
require 'db.php';
try {
    $stmt = $pdo->query("DESCRIBE students");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($columns);
} catch (Exception $e) {
    echo $e->getMessage();
}
