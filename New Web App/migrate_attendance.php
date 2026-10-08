<?php
require 'db.php';

echo "<h1>Starting Attendance Migration...</h1>";

try {
    $stmt = $pdo->query("SELECT * FROM attendance");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted = 0;
    foreach ($rows as $row) {
        $student_id = $row['student_id'];
        $date = $row['date'];

        for ($i = 1; $i <= 8; $i++) {
            $statusCol = "status_$i";
            $lateCol = "late_$i";

            if (!empty($row[$statusCol])) {
                $status = $row[$statusCol];
                // normalize status
                $status = trim(strtoupper($status));
                if ($status == 'PRESENT') $status = 'P';
                elseif ($status == 'ABSENT') $status = 'A';
                elseif ($status == 'LEAVE') $status = 'L';
                
                if (!in_array($status, ['P', 'A', 'L'])) {
                    if (strpos(strtolower($row[$statusCol]), 'p') !== false) $status = 'P';
                    elseif (strpos(strtolower($row[$statusCol]), 'a') !== false) $status = 'A';
                    elseif (strpos(strtolower($row[$statusCol]), 'l') !== false) $status = 'L';
                }

                $delay = isset($row[$lateCol]) ? (int)$row[$lateCol] : 0;
                $remarks = $delay > 0 ? "Late: $delay mins" : null;

                // Check if already exists
                $check = $pdo->prepare("SELECT id FROM period_attendance WHERE student_id = ? AND date = ? AND period_num = ?");
                $check->execute([$student_id, $date, $i]);
                if ($check->rowCount() == 0) {
                    $ins = $pdo->prepare("INSERT INTO period_attendance (student_id, date, period_num, status, remarks) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$student_id, $date, $i, $status, $remarks]);
                    $inserted++;
                }
            }
        }
    }
    echo "<h2 style='color:green;'>Migration Completed! Migrated $inserted records from old attendance table to new period_attendance table.</h2>";
} catch (Exception $e) {
    echo "<h2 style='color:red;'>Error: " . $e->getMessage() . "</h2>";
}
