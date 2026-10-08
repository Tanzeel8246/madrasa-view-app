<?php
/**
 * rescue_masjid_data.php
 * یہ اسکرپٹ ان پرانے ریکارڈز کو वापस مسجد میں منتقل کرنے کے لیے ہے جو 
 * پچھلے اسکرپٹ کی وجہ سے غلطی سے مدرسہ میں شامل ہو گئے تھے۔
 */
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['move_finance_id'])) {
        $id = intval($_POST['move_finance_id']);
        $pdo->exec("UPDATE finance SET system_type = 'Masjid', updated_at = NOW() WHERE id = $id");
        $msg = "فنانس ریکارڈ ID $id کو مسجد میں منتقل کر دیا گیا ہے۔";
    } elseif (isset($_POST['move_student_id'])) {
        $id = intval($_POST['move_student_id']);
        $pdo->exec("UPDATE students SET system_type = 'Masjid', updated_at = NOW() WHERE id = $id");
        $msg = "طالب علم ID $id کو مسجد میں منتقل کر دیا گیا ہے۔";
    }
}

// Fetch 100 recent Madrasa finance records
$finance = $pdo->query("SELECT * FROM finance WHERE system_type = 'Madrasa' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// Fetch 100 recent Madrasa students
$students = $pdo->query("SELECT * FROM students WHERE system_type = 'Madrasa' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ڈیٹا ریکوری ٹول (مسجد)</title>
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', Arial, sans-serif; padding: 20px; background: #f9f9f9; }
        h1, h2 { color: #d32f2f; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 40px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: right; }
        th { background: #f2f2f2; }
        button { background: #1976d2; color: #fff; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; }
        button:hover { background: #115293; }
        .notice { background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #ffeeba; }
    </style>
</head>
<body>

    <h1>ڈیٹا ریکوری ٹول (مسجد میں منتقلی)</h1>
    <div class="notice">
        <b>ہدایت:</b> یہ ٹول ان ریکارڈز کی فہرست دکھا رہا ہے جو اس وقت "مدرسہ" کے کھاتے میں ہیں۔ 
        اگر کوئی ریکارڈ دراصل مسجد کا ہے اور غلطی سے مدرسہ میں آ گیا ہے، تو اس کے سامنے موجود <b>"مسجد میں بھیجیں"</b> کا بٹن دبائیں۔<br>
        (یہاں صرف تازہ ترین 100 ریکارڈز دکھائے جا رہے ہیں)
    </div>

    <?php if(!empty($msg)) echo "<div class='success'>$msg</div>"; ?>

    <h2>فنانس ریکارڈز (Finance)</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>تاریخ</th>
            <th>قسم</th>
            <th>رقم</th>
            <th>تفصیل</th>
            <th>ایکشن</th>
        </tr>
        <?php foreach ($finance as $f): ?>
        <tr>
            <td><?= $f['id'] ?></td>
            <td><?= $f['date'] ?></td>
            <td><?= $f['type'] ?></td>
            <td><?= number_format((float)$f['amount']) ?></td>
            <td><?= htmlspecialchars($f['description']) ?></td>
            <td>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="move_finance_id" value="<?= $f['id'] ?>">
                    <button type="submit">مسجد میں بھیجیں</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>طلبہ کا ریکارڈ (Students)</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>نام</th>
            <th>والد کا نام</th>
            <th>کلاس</th>
            <th>ایکشن</th>
        </tr>
        <?php foreach ($students as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['father_name']) ?></td>
            <td><?= htmlspecialchars($s['class']) ?></td>
            <td>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="move_student_id" value="<?= $s['id'] ?>">
                    <button type="submit">مسجد میں بھیجیں</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

</body>
</html>
