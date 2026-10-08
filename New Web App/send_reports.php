<?php 
require_once 'db.php'; 
include 'header.php'; 
include 'notifications.php'; // اوپر والی فائل شامل کریں

$msg = "";

// جب بٹن دبایا جائے
if (isset($_POST['send_bulk'])) {
    $month = $_POST['month']; // 2023-10
    $start_date = $month . "-01";
    $end_date = date("Y-m-t", strtotime($start_date));
    
    // 1. تمام طلباء کا ڈیٹا لائیں
    $students = $pdo->query("SELECT * FROM students")->fetchAll();
    
    $principal_report = "<h3>ماہانہ رپورٹ برائے مہتمم صاحب ($month)</h3><table border='1' cellpadding='5'><tr><th>طالب علم</th><th>حاضری</th><th>حفظ کارکردگی</th></tr>";

    foreach ($students as $std) {
        // A. اس طالب علم کی حاضری کا حساب
        $att_counts = $pdo->query("SELECT 
            COUNT(CASE WHEN status_1='P' THEN 1 END) as presents,
            COUNT(CASE WHEN status_1='A' THEN 1 END) as absents
            FROM attendance WHERE student_id = {$std['id']} AND date BETWEEN '$start_date' AND '$end_date'")->fetch();
        
        // B. حفظ کی کارکردگی (آخری سبق)
        $hifz = $pdo->query("SELECT * FROM daily_hifz WHERE student_id = {$std['id']} AND date BETWEEN '$start_date' AND '$end_date' ORDER BY date DESC LIMIT 1")->fetch();
        $last_sabaq = $hifz ? "پارہ " . $hifz['sabaq_parah'] : "کوئی ریکارڈ نہیں";

        // --- C. والدین کے لیے میسج تیار کریں ---
        
        // 1. ای میل باڈی (HTML)
        $email_body = "
        <div style='direction: rtl; font-family: Tahoma;'>
            <h2>محترم والدین، السلام علیکم!</h2>
            <p>آپ کے بیٹے <strong>{$std['name']}</strong> کی ماہانہ کارکردگی ($month) درج ذیل ہے:</p>
            <ul>
                <li>کل حاضری: {$att_counts['presents']} دن</li>
                <li>غیر حاضری: {$att_counts['absents']} دن</li>
                <li>موجودہ سبق: $last_sabaq</li>
            </ul>
            <p>منجانب: مدرسہ اشرف المدارس</p>
        </div>";

        // 2. ایس ایم ایس باڈی (Short Text)
        $sms_body = "مدرسہ اشرف المدارس: {$std['name']} رپورٹ ($month)۔ حاضری: {$att_counts['presents']}۔ سبق: $last_sabaq۔";

        // --- D. سینڈنگ (Sending) ---
        
        // ای میل بھیجیں
        if (!empty($std['parent_email'])) {
            sendEmail($std['parent_email'], "Monthly Report: " . $std['name'], $email_body);
        }
        
        // ایس ایم ایس بھیجیں
        if (!empty($std['parent_phone'])) {
            sendSMS($std['parent_phone'], $sms_body);
        }

        // واٹس ایپ بھیجیں
        if (!empty($std['parent_whatsapp'])) {
            sendWhatsApp($std['parent_whatsapp'], $sms_body); // یا لمبا میسج
        }

        // --- E. پرنسپل کی رپورٹ میں اضافہ ---
        $principal_report .= "<tr>
            <td>{$std['name']}</td>
            <td>P: {$att_counts['presents']} / A: {$att_counts['absents']}</td>
            <td>$last_sabaq</td>
        </tr>";
    }

    $principal_report .= "</table>";

    // 2. پرنسپل کو ای میل بھیجیں (ایڈمن کی ای میل)
    $principal_email = "principal@madrasa.com"; // یہاں پرنسپل کی ای میل لکھیں
    sendEmail($principal_email, "Full Monthly Report - $month", $principal_report);

    $msg = "<div class='alert alert-success'>✅ تمام رپورٹس پروسیس ہو گئی ہیں! (والدین اور پرنسپل کو بھیج دی گئیں)</div>";
}
?>

<div class="card p-4 shadow-sm" style="max-width: 600px; margin: 0 auto;">
    <h3 class="text-center text-primary fw-bold mb-4">📢 خودکار رپورٹنگ سسٹم</h3>
    
    <?php echo $msg; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="fw-bold">کس مہینے کی رپورٹ بھیجنی ہے؟</label>
            <input type="month" name="month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
        </div>

        <div class="alert alert-info small">
            <i class="fas fa-info-circle"></i> جیسے ہی آپ بٹن دبائیں گے:
            <ul>
                <li>تمام والدین کو ان کے بچے کی رپورٹ ای میل/SMS ہو جائے گی۔</li>
                <li>پرنسپل صاحب کو تمام طلباء کی مکمل لسٹ ای میل ہو جائے گی۔</li>
            </ul>
        </div>

        <button type="submit" name="send_bulk" class="btn btn-success w-100 py-3 fw-bold">
            <i class="fas fa-paper-plane me-2"></i> ابھی سینڈ کریں (Send Now)
        </button>
    </form>
</div>

<?php include 'footer.php'; ?>