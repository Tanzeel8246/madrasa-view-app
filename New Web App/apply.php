<?php
// apply.php - جامع داخلہ فارم ہینڈلر
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // رجسٹریشن نمبر لاجک (خودکار تسلسل)
        $current_hijri_year = $pdo->query("SELECT current_hijri_year FROM settings LIMIT 1")->fetchColumn() ?: '1447';
        $last_serial = $pdo->query("SELECT MAX(serial_no) FROM (SELECT serial_no FROM students UNION SELECT serial_no FROM admission_applications) as combined")->fetchColumn() ?: 0;
        $next_serial = $last_serial + 1;
        $adm_no = $current_hijri_year . "-" . sprintf("%02d", $next_serial);

        // ڈیٹا وصول کریں
        $student_name = trim($_POST['student_name'] ?? '');
        $father_name  = trim($_POST['father_name'] ?? '');
        $dob          = trim($_POST['dob'] ?? '');
        $address      = trim($_POST['address'] ?? '');
        $phone        = trim($_POST['phone'] ?? ''); // Saved as mobile_no
        $email        = trim($_POST['email'] ?? '');
        $perm_addr    = trim($_POST['permanent_address'] ?? '');
        $qual         = trim($_POST['qualification'] ?? '');
        $last_study   = trim($_POST['last_studied'] ?? '');
        $aim          = trim($_POST['aim'] ?? '');
        $prev_madaris = trim($_POST['prev_madaris'] ?? '');
        $leave_reason = trim($_POST['leave_reason'] ?? '');
        $g_name       = trim($_POST['guardian_name'] ?? '');
        $g_cnic       = trim($_POST['guardian_cnic'] ?? '');
        $g_rel        = trim($_POST['guardian_relation'] ?? '');
        $g_addr       = trim($_POST['guardian_address'] ?? '');
        $dept         = $_POST['department'] ?? 'نامعلوم';

        if (empty($student_name) || empty($father_name)) {
            throw new Exception("نام اور ولدیت لازمی ہے۔");
        }

        // تصویر ہینڈلنگ
        $photo_path = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $dir = 'uploads/students/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_name = "app_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $photo_name)) {
                $photo_path = $dir . $photo_name;
            }
        }

        // ڈیٹا بیس میں محفوظ کریں
        $sql = "INSERT INTO admission_applications 
                (student_name, father_name, dob, address, mobile_no, photo, email, status, application_date, 
                 permanent_address, qualification, last_studied, aim, prev_madaris, leave_reason, 
                 guardian_name, guardian_cnic, guardian_relation, guardian_address, department, hijri_year, serial_no) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $student_name, $father_name, $dob, $address, $phone, $photo_path, $email,
            $perm_addr, $qual, $last_study, $aim, $prev_madaris, $leave_reason,
            $g_name, $g_cnic, $g_rel, $g_addr, $dept, $current_hijri_year, $next_serial
        ]);

        echo json_encode([
            "success" => true, 
            "message" => "آپ کا فارم کامیابی سے جمع ہو گیا ہے۔ آپ کا داخلہ نمبر $adm_no ہے۔ جلد اطلاع دی جائے گی۔",
            "admission_number" => $adm_no
        ]);

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
?>
