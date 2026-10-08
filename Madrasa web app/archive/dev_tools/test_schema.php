<?php require __DIR__ . "/../../db.php"; print_r($pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_ASSOC));
