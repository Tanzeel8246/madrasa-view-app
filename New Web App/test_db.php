<?php
require 'db.php';
try {
    $stmt = $pdo->query("SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance WHERE status LIKE '%غ%' OR status_1 LIKE '%غ%' OR status LIKE '%رخصت%' OR status_1 LIKE '%رخصت%' OR status LIKE '%Absent%' OR status_1 LIKE '%Absent%' ORDER BY id DESC LIMIT 10");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents('test_db_output.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Done";
} catch (Exception $e) {
    file_put_contents('test_db_output.json', json_encode(["error" => $e->getMessage()]));
}
