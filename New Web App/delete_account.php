<?php 
require_once 'db.php'; 
$msg = "";

if (isset($_POST['request_delete'])) {
    $adm_no = $_POST['adm_no'];
    $phone = $_POST['phone'];
    
    // یہاں ہم صرف ایک شکایت درج کر دیتے ہیں تاکہ ایڈمن کو پتہ چل جائے کہ کس نے اکاؤنٹ ڈیلیٹ کرنا ہے
    $stmt = $pdo->prepare("INSERT INTO parent_complaints (student_id, date, category, description, status) 
                          SELECT id, CURDATE(), 'Account Deletion', ?, 'Pending' 
                          FROM students WHERE admission_number = ? AND contact_number = ?");
    $stmt->execute(["User requested account deletion for Roll No: $adm_no. Contact: $phone", $adm_no, $phone]);
    
    if ($stmt->rowCount() > 0) {
        $msg = "<div class='alert alert-success'>✅ آپ کی درخواست موصول ہو گئی ہے۔ انتظامیہ 24 گھنٹوں میں آپ سے رابطہ کرے گی۔</div>";
    } else {
        $msg = "<div class='alert alert-danger'>❌ فراہم کردہ معلومات ریکارڈ سے مطابقت نہیں رکھتی ہیں۔</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اکاؤنٹ ختم کریں - مدرسہ اشرف المدارس</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        body { font-family: 'serif'; background: #f8f9fa; padding: 40px 20px; }
        .legal-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); max-width: 500px; margin: auto; }
        h2 { color: #dc3545; }
    </style>
</head>
<body>
    <div class="legal-card text-center">
        <h2 class="mb-4">اکاؤنٹ ختم کرنے کی درخواست (Delete Account)</h2>
        <p>اگر آپ اپنا یا اپنے بچے کا ڈیٹا مدرسہ کے آن لائن سسٹم سے ختم کرنا چاہتے ہیں تو ذیل میں تفصیلات فراہم کریں۔</p>
        
        <?php echo $msg; ?>

        <form method="POST" class="text-start">
            <div class="mb-3">
                <label class="fw-bold">داخلہ نمبر (Roll No)</label>
                <input type="text" name="adm_no" class="form-control" required placeholder="مثلاً 1447-01">
            </div>
            <div class="mb-3">
                <label class="fw-bold">رجسٹرڈ موبائل نمبر</label>
                <input type="text" name="phone" class="form-control" required placeholder="0300xxxxxxx">
            </div>
            <p class="small text-muted">نوٹ: اکاؤنٹ ڈیلیٹ کرنے سے آپ کی تمام تعلیمی رپورٹ اور ہسٹری ختم ہو جائے گی۔</p>
            <button type="submit" name="request_delete" class="btn btn-danger w-100 fw-bold">درخواست بھیجیں</button>
        </form>

        <div class="text-center mt-4">
            <a href="index.php" class="small text-decoration-none">ہوم پیج پر واپس جائیں</a>
        </div>
    </div>
</body>
</html>
