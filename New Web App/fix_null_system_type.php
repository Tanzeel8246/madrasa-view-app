<?php
/**
 * fix_null_system_type.php
 * اہم: یہ اسکرپٹ ایک بار ویب سرور پر چلائیں تاکہ سابقہ ڈیٹا ٹھیک ہو جائے
 * IMPORTANT: Run this script ONCE on the web server to fix all existing data.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>ڈیٹا اصلاح جاری ہے...</h2><hr>";
$errors = [];

// Step 1: ensure updated_at column exists in finance and students
$tables_to_fix = ['finance', 'students', 'teachers', 'attendance', 'attendance_teachers', 'daily_hifz', 'daily_nazira'];
foreach ($tables_to_fix as $tbl) {
    try {
        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "<p style='color:green'>✓ $tbl: updated_at column ensured</p>";
    } catch (Exception $e) {
        // Try without IF NOT EXISTS (older MySQL)
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'updated_at'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                echo "<p style='color:green'>✓ $tbl: updated_at column ADDED</p>";
            } else {
                echo "<p style='color:blue'>ℹ $tbl: updated_at already exists</p>";
            }
        } catch (Exception $e2) {
            echo "<p style='color:orange'>⚠ $tbl updated_at: " . htmlspecialchars($e2->getMessage()) . "</p>";
        }
    }
}

// Step 2: Fix NULL updated_at — set to NOW() so delta sync sees them
foreach (['finance', 'students'] as $tbl) {
    try {
        $stmt = $pdo->prepare("UPDATE `$tbl` SET updated_at = NOW() WHERE updated_at IS NULL OR updated_at = '0000-00-00 00:00:00'");
        $stmt->execute();
        $count = $stmt->rowCount();
        echo "<p style='color:green'>✓ $tbl: $count rows had updated_at = NULL → fixed to NOW()</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ $tbl updated_at fix: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Step 3: Fix NULL system_type in finance (all default to 'Madrasa')
try {
    $stmt = $pdo->prepare("UPDATE finance SET system_type = 'Madrasa' WHERE system_type IS NULL OR system_type = ''");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "<p style='color:green'>✓ finance: $count rows had NULL system_type → set to Madrasa</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ finance system_type: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Step 4: Fix NULL system_type in students
try {
    $stmt = $pdo->prepare("UPDATE students SET system_type = 'Madrasa' WHERE system_type IS NULL OR system_type = ''");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "<p style='color:green'>✓ students: $count rows had NULL system_type → set to Madrasa</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ students system_type: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Step 5: For Masjid installations, ask which records should be Masjid
// (We only default-set Madrasa; the user should verify separately for Masjid records)

echo "<hr>";
echo "<h3 style='color:green'>✅ تمام اصلاحات مکمل!</h3>";
echo "<p>اب موبائل ایپ میں <strong>سنک بٹن دبائیں</strong> — تمام ویب ڈیٹا موبائل پر آ جائے گا۔</p>";
echo "<p style='color:red'>نوٹ: یہ فائل چلانے کے بعد سرور سے حذف کر دیں۔</p>";
