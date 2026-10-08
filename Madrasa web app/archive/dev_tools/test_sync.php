<?php
require __DIR__ . '/../../db.php';
try {
    $f = ['date'=>'2026-06-27','type'=>'Income','amount'=>100,'description'=>'Test sync','user_name'=>'Admin'];
    $date = $f['date'];
    $type = $f['type'];
    $amount = $f['amount'];
    $desc = $f['description'];
    $uid = 1;
    $userName = 'Admin';
    $computer_receipt_no = 1;
    
    $pdo->prepare("INSERT INTO finance (date, type, amount, description, head_id, manual_receipt_no, donor_name, donor_address, computer_receipt_no, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$date, $type, $amount, $desc, null, '', '', '', $computer_receipt_no, $uid, $userName]);
    echo "Success! ID: " . $pdo->lastInsertId();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
