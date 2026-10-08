<?php
/**
 * rescue_masjid_data.php (ARCHIVED)
 */
require_once __DIR__ . '/../../db.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['move_finance_id'])) {
        $id = intval($_POST['move_finance_id']);
        $pdo->exec("UPDATE finance SET system_type = 'Masjid', updated_at = NOW() WHERE id = $id");
        $msg = "Finance ID $id moved";
    } elseif (isset($_POST['move_student_id'])) {
        $id = intval($_POST['move_student_id']);
        $pdo->exec("UPDATE students SET system_type = 'Masjid', updated_at = NOW() WHERE id = $id");
        $msg = "Student ID $id moved";
    }
}

$finance = $pdo->query("SELECT * FROM finance WHERE system_type = 'Madrasa' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$students = $pdo->query("SELECT * FROM students WHERE system_type = 'Madrasa' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Rescue Masjid Data</title>
</head>
<body>
    <h1>Rescue Masjid Data</h1>
    <?php if(!empty($msg)) echo "<div>$msg</div>"; ?>
</body>
</html>
