<?php
require 'db.php';

echo "<h1>Data Reset and Import Script</h1>";

$tables_to_truncate = [
    'students',
    'attendance',
    'daily_hifz',
    'academic_reports',
    'parent_complaints',
    'complaints',
    'finance',
    'exams',
    'exam_results',
    'fee_submissions',
    'leave_applications',
    'admission_applications'
];

try {
    // 1. تمام پرانا ڈیمو ڈیٹا ڈیلیٹ کریں
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
    foreach ($tables_to_truncate as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
        echo "<p style='color:green;'>Cleared table: $table</p>";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
    
    echo "<h3>All Demo Data Cleared!</h3>";

    // 2. CSV فائل سے ڈیٹا پڑھیں اور امپورٹ کریں
    $csv_file = __DIR__ . '/uploads/student_data-1.csv';
    
    if (file_exists($csv_file)) {
        if (($handle = fopen($csv_file, "r")) !== FALSE) {
            // Remove UTF-8 BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // Skip the header row
            $header = fgetcsv($handle, 1000, ",");
            
            $stmt = $pdo->prepare("INSERT INTO students (admission_number, name, father_name, contact_number, class, status) VALUES (?, ?, ?, ?, 'حفظ', 'Active')");
            
            $count = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // CSV Columns: [0] داخلہ نمبر, [1] نام الطالب علم, [2] ولدیت, [3] رابطہ نمبر
                $admission_no = trim($data[0] ?? '');
                $name = trim($data[1] ?? '');
                $father_name = trim($data[2] ?? '');
                $contact = trim($data[3] ?? '');
                
                // نام خالی ہو تو قطار چھوڑ دیں
                if (empty($name)) continue;
                
                // اگر داخلہ نمبر خالی ہے تو عارضی نمبر دے دیں تاکہ Duplicate Error نہ آئے
                if (empty($admission_no)) {
                    $admission_no = 90000 + $count;
                }
                
                $stmt->execute([$admission_no, $name, $father_name, $contact]);
                $count++;
            }
            fclose($handle);
            echo "<h2 style='color:blue;'>Successfully imported $count students into Hifz class!</h2>";
        } else {
            echo "<h2 style='color:red;'>Error opening CSV file.</h2>";
        }
    } else {
        echo "<h2 style='color:red;'>CSV file not found at: $csv_file</h2>";
    }

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Error: " . $e->getMessage() . "</h2>";
}
?>
