<?php
require_once 'db.php';
require_once 'send_sms.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $type = $_POST['type'] ?? 'sms';

    if ($student_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'طالب علم کا ID درست نہیں ہے۔']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'طالب علم نہیں ملا۔']);
        exit;
    }

    $month = date('Y-m');
    $start_date = $month . "-01";
    $end_date = date("Y-m-t", strtotime($start_date));
    
    $att_counts = $pdo->query("SELECT 
        COUNT(CASE WHEN status_1='P' THEN 1 END) as presents,
        COUNT(CASE WHEN status_1='A' THEN 1 END) as absents
        FROM attendance WHERE student_id = {$student['id']} AND date BETWEEN '$start_date' AND '$end_date'")->fetch();
    
    $hifz = $pdo->query("SELECT * FROM daily_hifz WHERE student_id = {$student['id']} AND date BETWEEN '$start_date' AND '$end_date' ORDER BY date DESC LIMIT 1")->fetch();
    $last_sabaq = $hifz ? "پارہ " . $hifz['sabaq_parah'] : "کوئی ریکارڈ نہیں";

    $message = "مدرسہ اشرف المدارس: {$student['name']} کی رپورٹ ($month)۔ حاضری: {$att_counts['presents']}۔ سبق: $last_sabaq۔";

    if ($type === 'sms') {
        if (!empty($student['parent_phone'])) {
            $res = send_sms($student['parent_phone'], $message);
            if ($res['success']) {
                echo json_encode(['success' => true, 'message' => 'SMS کامیابی سے بھیج دیا گیا ہے۔']);
            } else {
                echo json_encode(['success' => false, 'message' => 'SMS بھیجنے میں خرابی: ' . $res['message']]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'والدین کا موبائل نمبر درج نہیں ہے۔']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'فی الحال صرف SMS کی سہولت موجود ہے۔']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Request']);
}
?>
