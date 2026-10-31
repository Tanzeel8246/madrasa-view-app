<?php
// admissions_pipeline.php - مراحلِ داخلہ بورڈ
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'nazim', 'superadmin'], true) || has_perm('manage_students');
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase2_tables($pdo);

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');
$sys_cond = $is_masjid ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$cur_tenant_id = (int)get_current_tenant_id();

$success_msg = '';
$error_msg = '';

// Photo upload helper
function handle_student_photo_upload($is_masjid) {
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $is_masjid ? 'uploads/maktab_students/' : 'uploads/students/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $photo_name = "std_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name)) {
                return $upload_dir . $photo_name;
            }
        }
    }
    return '';
}

// --- ایکشنز (Actions) ---

// 1. نئی داخلہ درخواست کا اندراج (مکمل فارم)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_inquiry'])) {
    try {
        $name = trim($_POST['student_name'] ?? '');
        $fname = trim($_POST['father_name'] ?? '');
        $adm_no = trim($_POST['admission_number'] ?? '');
        $cls = trim($_POST['class_applied'] ?? '');
        $quran_edition = trim($_POST['quran_edition'] ?? '16 سطری');
        $res_status = trim($_POST['residence_status'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d');
        $grad_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
        $duration = trim($_POST['duration'] ?? '');
        $addr = trim($_POST['address'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $photo_path = handle_student_photo_upload($is_masjid);

        if (empty($name) || empty($fname)) {
            throw new Exception("طالب علم کا نام اور ولدیت لازمی ہیں!");
        }
        if (empty($phone)) {
            throw new Exception("رابطہ نمبر لکھنا لازمی ہے!");
        }

        $stmt = $pdo->prepare("INSERT INTO admission_inquiries 
            (student_name, father_name, admission_number, phone, class_applied, quran_edition, residence_status, admission_date, graduation_date, duration, address, photo, admin_notes, status, system_type, tenant_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, NOW())");
        $stmt->execute([$name, $fname, $adm_no, $phone, $cls, $quran_edition, $res_status, $adm_date, $grad_date, $duration, $addr, $photo_path, $admin_notes, $active_sys, $cur_tenant_id]);

        log_activity($pdo, 'admissions', "نئی داخلہ درخواست درج کی گئی: $name ($active_sys)");
        $success_msg = "نئی داخلہ درخواست کامیابی سے درج کر لی گئی!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. اسٹیٹس کی تبدیلی
if (isset($_POST['update_status'])) {
    try {
        $inq_id = (int)$_POST['inquiry_id'];
        $new_status = $_POST['new_status'];
        $allowed = ['Pending', 'Interviewed', 'Accepted', 'Rejected'];
        if (in_array($new_status, $allowed, true)) {
            $pdo->prepare("UPDATE admission_inquiries SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$new_status, $inq_id, $cur_tenant_id]);
            log_activity($pdo, 'admissions', "داخلہ درخواست #$inq_id کا مرحلہ تبدیل کیا گیا: $new_status");
            $success_msg = "مرحلہ کامیابی سے تبدیل کر دیا گیا!";
        }
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. انٹرویو ریمارکس اور تاریخ محفوظ کرنا
if (isset($_POST['save_interview'])) {
    try {
        $inq_id = (int)$_POST['inquiry_id'];
        $int_date = !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
        $int_notes = trim($_POST['interview_notes'] ?? '');
        $new_status = $_POST['status'] ?? 'Interviewed';

        $pdo->prepare("UPDATE admission_inquiries SET interview_date = ?, interview_notes = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
            ->execute([$int_date, $int_notes, $new_status, $inq_id, $cur_tenant_id]);

        log_activity($pdo, 'admissions', "داخلہ درخواست #$inq_id کے انٹرویو ریمارکس محفوظ ہوئے");
        $success_msg = "انٹرویو کی تفصیلات محفوظ کر لی گئیں!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 4. باقاعدہ طالب علم میں منتقلی (Enrollment / Convert to Student)
if (isset($_POST['convert_to_student'])) {
    try {
        $inq_id = (int)$_POST['inquiry_id'];
        $inqStmt = $pdo->prepare("SELECT * FROM admission_inquiries WHERE id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
        $inqStmt->execute([$inq_id, $cur_tenant_id]);
        $inq = $inqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inq) throw new Exception("درخواست کا ریکارڈ نہیں ملا!");

        $target_class = trim($_POST['enroll_class'] ?? ($inq['class_applied'] ?: ''));
        if (empty($target_class)) throw new Exception("کلاس کا انتخاب لازمی ہے!");

        $h_stmt = $pdo->prepare("SELECT current_hijri_year FROM settings WHERE tenant_id = ? LIMIT 1");
        $h_stmt->execute([$cur_tenant_id]);
        $current_hijri = $h_stmt->fetchColumn() ?: '1447';
        $target_table = $is_masjid ? 'maktab_students' : 'students';

        $s_stmt = $pdo->prepare("SELECT MAX(serial_no) FROM (SELECT serial_no FROM $target_table WHERE tenant_id = ? UNION SELECT serial_no FROM admission_applications WHERE tenant_id = ?) as cb");
        $s_stmt->execute([$cur_tenant_id, $cur_tenant_id]);
        $last_serial = $s_stmt->fetchColumn() ?: 0;
        $next_serial = (int)$last_serial + 1;
        $adm_no = trim($_POST['admission_number'] ?? '');
        if (empty($adm_no)) {
            $adm_no = !empty($inq['admission_number']) ? $inq['admission_number'] : ($current_hijri . "-" . sprintf("%02d", $next_serial));
        }

        $res_status = $_POST['residence_status'] ?? ($inq['residence_status'] ?: '');
        $quran_edition = $_POST['quran_edition'] ?? ($inq['quran_edition'] ?: '16 سطری');
        $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : (!empty($inq['admission_date']) ? $inq['admission_date'] : date('Y-m-d'));
        $duration = $_POST['duration'] ?? ($inq['duration'] ?: '');
        $photo_path = handle_student_photo_upload($is_masjid);
        if (empty($photo_path) && !empty($inq['photo'])) {
            $photo_path = $inq['photo'];
        }

        // Drop legacy single-column unique index on target table(admission_number) if still present
        try {
            $indexes = $pdo->query("SHOW INDEX FROM `$target_table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $idx) {
                if ($idx['Non_unique'] == 0 && $idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === 'admission_number') {
                    $kName = $idx['Key_name'];
                    $hasTenant = false;
                    foreach ($indexes as $idx2) {
                        if ($idx2['Key_name'] === $kName && $idx2['Column_name'] === 'tenant_id') {
                            $hasTenant = true;
                        }
                    }
                    if (!$hasTenant) {
                        try { $pdo->exec("ALTER TABLE `$target_table` DROP INDEX `$kName`"); } catch (Throwable $eDrop) {}
                    }
                }
            }
            try { $pdo->exec("ALTER TABLE `$target_table` ADD UNIQUE KEY `idx_tenant_adm_no` (`tenant_id`, `admission_number`)"); } catch (Throwable $eAddIdx) {}
        } catch (Throwable $eIdx) {}

        // Check duplicate within current tenant
        $dup = $pdo->prepare("SELECT id FROM $target_table WHERE admission_number = ? AND tenant_id = ?");
        $dup->execute([$adm_no, $cur_tenant_id]);
        if ($dup->fetch()) throw new Exception("داخلہ نمبر '$adm_no' پہلے سے موجود ہے!");

        $parts = explode('-', $adm_no);
        $h_year = (int)$parts[0];
        $s_no = isset($parts[1]) ? (int)$parts[1] : $next_serial;

        if ($is_masjid) {
            $sql = "INSERT INTO maktab_students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, duration, photo, residence_status, quran_edition, tenant_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $inq['student_name'],
                $inq['father_name'],
                $adm_no,
                $s_no,
                $h_year,
                $target_class,
                $inq['phone'],
                $inq['address'],
                $adm_date,
                $duration,
                $photo_path,
                $res_status,
                $quran_edition,
                $cur_tenant_id
            ]);
        } else {
            $sql = "INSERT INTO students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, duration, photo, residence_status, quran_edition, system_type, tenant_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $inq['student_name'],
                $inq['father_name'],
                $adm_no,
                $s_no,
                $h_year,
                $target_class,
                $inq['phone'],
                $inq['address'],
                $adm_date,
                $duration,
                $photo_path,
                $res_status,
                $quran_edition,
                $active_sys,
                $cur_tenant_id
            ]);
        }
        $new_std_id = $pdo->lastInsertId();

        // Update inquiry
        $pdo->prepare("UPDATE admission_inquiries SET status = 'Accepted', converted_student_id = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
            ->execute([$new_std_id, $inq_id, $cur_tenant_id]);

        log_activity($pdo, 'admissions', "مراحلِ داخلہ سے باقاعدہ طالب علم داخل کیا گیا: {$inq['student_name']} (داخلہ نمبر: $adm_no, کلاس: $target_class, ٹیبل: $target_table)");
        $success_msg = "طالب علم '{$inq['student_name']}' کامیابی کے ساتھ باقاعدہ داخل کر لیا گیا! (داخلہ نمبر: $adm_no)";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 4.5 ڈائریکٹ طالب علم شامل کرنا (Direct Student Admission - مکمل فارم)
if (isset($_POST['direct_enroll_student'])) {
    try {
        $name = trim($_POST['student_name'] ?? '');
        $fname = trim($_POST['father_name'] ?? '');
        $adm_no = trim($_POST['admission_number'] ?? '');
        $cls = trim($_POST['enroll_class'] ?? '');
        $quran_edition = trim($_POST['quran_edition'] ?? '16 سطری');
        $res_status = trim($_POST['residence_status'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d');
        $grad_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
        $duration = trim($_POST['duration'] ?? '');
        $addr = trim($_POST['address'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $photo_path = handle_student_photo_upload($is_masjid);

        if (empty($name) || empty($fname)) {
            throw new Exception("طالب علم کا نام اور ولدیت لازمی ہیں!");
        }
        if (empty($phone)) {
            throw new Exception("رابطہ نمبر لکھنا لازمی ہے!");
        }
        if (empty($cls)) {
            throw new Exception("کلاس منتخب کرنا لازمی ہے!");
        }

        $h_stmt = $pdo->prepare("SELECT current_hijri_year FROM settings WHERE tenant_id = ? LIMIT 1");
        $h_stmt->execute([$cur_tenant_id]);
        $current_hijri = $h_stmt->fetchColumn() ?: '1447';
        $target_table = $is_masjid ? 'maktab_students' : 'students';

        $s_stmt = $pdo->prepare("SELECT MAX(serial_no) FROM (SELECT serial_no FROM $target_table WHERE tenant_id = ? UNION SELECT serial_no FROM admission_applications WHERE tenant_id = ?) as cb");
        $s_stmt->execute([$cur_tenant_id, $cur_tenant_id]);
        $last_serial = $s_stmt->fetchColumn() ?: 0;
        $next_serial = (int)$last_serial + 1;
        if (empty($adm_no)) {
            $adm_no = $current_hijri . "-" . sprintf("%02d", $next_serial);
        }

        // Duplicate check
        $dup = $pdo->prepare("SELECT id FROM $target_table WHERE admission_number = ? AND tenant_id = ?");
        $dup->execute([$adm_no, $cur_tenant_id]);
        if ($dup->fetch()) throw new Exception("داخلہ نمبر '$adm_no' پہلے سے موجود ہے!");

        $parts = explode('-', $adm_no);
        $h_year = (int)$parts[0];
        $s_no = isset($parts[1]) ? (int)$parts[1] : $next_serial;

        if ($is_masjid) {
            $sql = "INSERT INTO maktab_students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, graduation_date, duration, photo, residence_status, quran_edition, tenant_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $s_no, $h_year, $cls, $phone, $addr, $adm_date, $grad_date, $duration, $photo_path, $res_status, $quran_edition, $cur_tenant_id]);
        } else {
            $sql = "INSERT INTO students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, graduation_date, duration, photo, residence_status, quran_edition, system_type, tenant_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $s_no, $h_year, $cls, $phone, $addr, $adm_date, $grad_date, $duration, $photo_path, $res_status, $quran_edition, $active_sys, $cur_tenant_id]);
        }
        $new_std_id = $pdo->lastInsertId();

        // Also create inquiry entry marked as Accepted & Converted so it displays in pipeline
        $pdo->prepare("INSERT INTO admission_inquiries (student_name, father_name, admission_number, phone, class_applied, quran_edition, residence_status, admission_date, graduation_date, duration, address, photo, admin_notes, status, system_type, converted_student_id, tenant_id, created_at, updated_at) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Accepted', ?, ?, ?, NOW(), NOW())")
            ->execute([$name, $fname, $adm_no, $phone, $cls, $quran_edition, $res_status, $adm_date, $grad_date, $duration, $addr, $photo_path, $admin_notes, $active_sys, $new_std_id, $cur_tenant_id]);

        log_activity($pdo, 'admissions', "ڈائریکٹ طالب علم داخل کیا گیا: $name (داخلہ نمبر: $adm_no, کلاس: $cls, ٹیبل: $target_table)");
        $success_msg = "طالب علم '$name' کامیابی سے داخل کر لیا گیا! (داخلہ نمبر: $adm_no)";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 5. ڈیلیٹ کرنا
if (isset($_GET['del_id']) && $role === 'admin') {
    try {
        $del_id = (int)$_GET['del_id'];
        $pdo->prepare("UPDATE admission_inquiries SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$del_id, $cur_tenant_id]);
        log_activity($pdo, 'admissions', "داخلہ انکوائری #$del_id ڈیلیٹ کی گئی");
        $success_msg = "ریکارڈ ڈیلیٹ کر دیا گیا!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// رجسٹریشن نمبر لاجک
$h_stmt = $pdo->prepare("SELECT current_hijri_year FROM settings WHERE tenant_id = ? LIMIT 1");
$h_stmt->execute([$cur_tenant_id]);
$current_hijri_year = $h_stmt->fetchColumn() ?: '1447';

$target_table = $is_masjid ? 'maktab_students' : 'students';
$s_stmt = $pdo->prepare("SELECT MAX(serial_no) FROM (SELECT serial_no FROM $target_table WHERE tenant_id = ? UNION SELECT serial_no FROM admission_applications WHERE tenant_id = ?) as combined_serials");
$s_stmt->execute([$cur_tenant_id, $cur_tenant_id]);
$last_serial = $s_stmt->fetchColumn() ?: 0;
$next_serial = (int)$last_serial + 1;
$suggested_adm_no = $current_hijri_year . "-" . sprintf("%02d", $next_serial);

// کلاسز لسٹ برائے ڈراپ ڈاؤن
$class_table = $is_masjid ? 'maktab_classes' : 'classes';
try {
    if ($is_masjid) {
        $c_stmt = $pdo->prepare("SELECT name FROM maktab_classes WHERE tenant_id = ? AND (status = 'Active' OR status = '' OR status IS NULL) ORDER BY id ASC");
        $c_stmt->execute([$cur_tenant_id]);
    } else {
        $c_stmt = $pdo->prepare("SELECT name FROM classes WHERE tenant_id = ? AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC");
        $c_stmt->execute([$cur_tenant_id]);
    }
    $classes = $c_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $classes = [];
}
if (empty($classes)) {
    // If tenant has no classes, auto-seed default classes so they persist in database for this tenant
    if (!$is_masjid) {
        $default_classes = [
            ['name' => 'حفظ', 'class_type' => 'Hifz/Gardan/Husn'],
            ['name' => 'ناظرہ', 'class_type' => 'Nazira'],
            ['name' => 'قاعدہ', 'class_type' => 'Qaida'],
            ['name' => 'درجہ اولیٰ', 'class_type' => 'Darse Nizami / Other']
        ];
        $stmt_seed_m = $pdo->prepare("INSERT INTO classes (name, class_type, system_type, tenant_id) VALUES (?, ?, 'Madrasa', ?)");
        foreach ($default_classes as $dc) {
            try { $stmt_seed_m->execute([$dc['name'], $dc['class_type'], $cur_tenant_id]); } catch (Throwable $eSeed) {}
        }
        $classes = array_column($default_classes, 'name');
    } else {
        $classes = ['قاعدہ', 'ناظرہ', 'حفظ', 'ترجمہ'];
    }
}

// تمام انکوائریز حاصل کریں
$search = trim($_GET['search'] ?? '');
$class_filter = trim($_GET['class_filter'] ?? '');

$where = "(is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND $sys_cond";
$params = [$cur_tenant_id];

if ($search !== '') {
    $where .= " AND (student_name LIKE ? OR father_name LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($class_filter !== '') {
    $where .= " AND class_applied = ?";
    $params[] = $class_filter;
}

$stmt = $pdo->prepare("SELECT * FROM admission_inquiries WHERE $where ORDER BY id DESC");
$stmt->execute($params);
$all_inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// گروپ بندی برائے 4 کالمز
$stages = [
    'Pending'     => ['title' => __('موصولہ درخواستیں', 'Received Applications'), 'color' => '#0288d1', 'icon' => 'fa-inbox', 'items' => []],
    'Interviewed' => ['title' => __('ٹیسٹ و انٹرویو', 'Test & Interview'),   'color' => '#f57c00', 'icon' => 'fa-comments', 'items' => []],
    'Accepted'    => ['title' => __('منظور شدہ داخلے', 'Approved Admissions'),  'color' => '#2e7d32', 'icon' => 'fa-check-circle', 'items' => []],
    'Rejected'    => ['title' => __('مسترد شدہ', 'Rejected'),        'color' => '#c62828', 'icon' => 'fa-times-circle', 'items' => []]
];

foreach ($all_inquiries as $inq) {
    $st = $inq['status'] ?? 'Pending';
    if (!isset($stages[$st])) $st = 'Pending';
    $stages[$st]['items'][] = $inq;
}

$total_count = count($all_inquiries);
?>

<div class="container-fluid py-4" style="background: #f8fafc; min-height: 100vh;">
    <!-- پیج ہیڈر -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h2 class="fw-bold text-dark m-0 d-flex align-items-center">
                <i class="fas fa-stream text-primary me-2"></i>
                <?php echo $is_masjid ? __('مراحلِ داخلہ (مکتب)', 'Admission Pipeline (Maktab)') : __('مراحلِ داخلہ', 'Admission Pipeline'); ?>
                <span class="badge bg-<?php echo $is_masjid ? 'info' : 'success'; ?> ms-3 fs-6">
                    <?php echo $is_masjid ? __('مسجد / مکتب موڈ', 'Masjid / Maktab Mode') : __('مدرسہ موڈ', 'Madrasa Mode'); ?>
                </span>
            </h2>
            <p class="text-muted m-0 small mt-1"><?php echo __('نئے داخلوں کے مراحل، انٹرویو، منظوری اور طالب علم اندراج کا بورڈ', 'New admissions stages, interviews, approvals and student enrollment board'); ?></p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <button class="btn btn-outline-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#directEnrollModal">
                <i class="fas fa-user-plus me-1"></i> <?php echo __('ڈائریکٹ طالب علم شامل کریں', 'Direct Admission'); ?>
            </button>
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newInquiryModal">
                <i class="fas fa-file-signature me-1"></i> <?php echo __('نئی درخواست درج کریں', 'New Application'); ?>
            </button>
        </div>
    </div>

    <!-- پیغامات -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- فلٹرز و سرچ بار -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="<?php echo __('نام، ولدیت یا موبائل نمبر تلاش کریں...', 'Search name, father name or mobile...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="class_filter" class="form-select">
                        <option value=""><?php echo __('-- تمام کلاسز --', '-- All Classes --'); ?></option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php if ($class_filter === $c) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 fw-bold">
                        <i class="fas fa-filter me-1"></i> <?php echo __('فلٹر کریں', 'Filter'); ?>
                    </button>
                    <?php if ($search !== '' || $class_filter !== ''): ?>
                        <a href="admissions_pipeline.php" class="btn btn-outline-secondary"><?php echo __('ری سیٹ', 'Reset'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- کانبان بورڈ کالمز (Kanban Board Columns) -->
    <div class="row g-3">
        <?php foreach ($stages as $stage_key => $stage): 
            $count = count($stage['items']);
        ?>
            <div class="col-lg-3 col-md-6">
                <div class="kanban-column bg-white rounded-3 shadow-sm border p-3 h-100 d-flex flex-column" style="border-top: 4px solid <?php echo $stage['color']; ?> !important; min-height: 550px;">
                    <!-- کالم کا ہیڈر -->
                    <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                        <div class="d-flex align-items-center">
                            <i class="fas <?php echo $stage['icon']; ?> me-2" style="color: <?php echo $stage['color']; ?>;"></i>
                            <h5 class="fw-bold m-0 text-dark"><?php echo $stage['title']; ?></h5>
                        </div>
                        <span class="badge rounded-pill text-white fw-bold px-3 py-2" style="background: <?php echo $stage['color']; ?>;">
                            <?php echo $count; ?>
                        </span>
                    </div>

                    <!-- کارڈز لسٹ -->
                    <div class="kanban-items flex-grow-1 overflow-auto" style="max-height: 70vh;">
                        <?php if (empty($stage['items'])): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-folder-open fa-2x mb-2 opacity-50"></i>
                                <p class="small m-0"><?php echo __('اس مرحلے میں کوئی درخواست نہیں', 'No applications in this stage'); ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($stage['items'] as $item): 
                                $is_converted = !empty($item['converted_student_id']);
                            ?>
                                <div class="card mb-3 border border-light shadow-sm rounded-3 kanban-card position-relative" style="background: #ffffff;">
                                    <div class="card-body p-3">
                                        <!-- ٹاپ بیج -->
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-graduation-cap me-1 text-primary"></i> <?php echo htmlspecialchars($item['class_applied'] ?: 'غیر متعین'); ?>
                                            </span>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($item['created_at'])); ?></small>
                                        </div>

                                        <!-- طالب علم کی تفصیل -->
                                        <h6 class="fw-bold text-dark mb-1 d-flex align-items-center">
                                            <i class="fas fa-user-circle text-secondary me-2"></i>
                                            <?php echo htmlspecialchars($item['student_name']); ?>
                                        </h6>
                                        <p class="small text-muted mb-1">
                                            <strong><?php echo __('ولدیت:', 'Father:'); ?></strong> <?php echo htmlspecialchars($item['father_name']); ?>
                                        </p>

                                        <div class="d-flex flex-wrap gap-1 mb-2">
                                            <?php if (!empty($item['admission_number'])): ?>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="fas fa-id-card text-muted me-1"></i> <?php echo htmlspecialchars($item['admission_number']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['quran_edition'])): ?>
                                                <span class="badge bg-light text-primary border">
                                                    <i class="fas fa-book-quran me-1"></i> <?php echo htmlspecialchars($item['quran_edition']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($item['phone'])): ?>
                                            <p class="small text-muted mb-2 d-flex align-items-center">
                                                <i class="fas fa-phone-alt text-success me-1"></i>
                                                <a href="tel:<?php echo htmlspecialchars($item['phone']); ?>" class="text-decoration-none text-dark me-2">
                                                    <?php echo htmlspecialchars($item['phone']); ?>
                                                </a>
                                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $item['phone']); ?>" target="_blank" class="badge bg-success text-white text-decoration-none">
                                                    <i class="fab fa-whatsapp"></i> <?php echo __('واٹس ایپ', 'WhatsApp'); ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (!empty($item['interview_date'])): ?>
                                            <div class="p-2 rounded bg-warning bg-opacity-10 border border-warning border-opacity-25 small mb-2">
                                                <div class="fw-bold text-warning-emphasis">
                                                    <i class="fas fa-calendar-check me-1"></i> <?php echo __('انٹرویو:', 'Interview:'); ?> <?php echo htmlspecialchars($item['interview_date']); ?>
                                                </div>
                                                <?php if (!empty($item['interview_notes'])): ?>
                                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars($item['interview_notes']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- اینرولڈ بیج اگر کنورٹ ہو چکا ہو -->
                                        <?php if ($is_converted): ?>
                                            <div class="alert alert-success p-1 mb-2 text-center small fw-bold">
                                                <i class="fas fa-check-double me-1"></i> <?php echo __('باقاعدہ طالب علم بن چکا ہے', 'Enrolled as Regular Student'); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- ایکشن بٹنز -->
                                        <div class="d-flex flex-wrap gap-1 mt-2 pt-2 border-top">
                                            <!-- مرحلہ منتقلی ڈراپ ڈاؤن -->
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <?php echo __('منتقل کریں', 'Move'); ?>
                                                </button>
                                                <ul class="dropdown-menu shadow">
                                                    <?php foreach ($stages as $k => $s): if ($k === $stage_key) continue; ?>
                                                        <li>
                                                            <form method="POST">
                                                                <input type="hidden" name="inquiry_id" value="<?php echo $item['id']; ?>">
                                                                <input type="hidden" name="new_status" value="<?php echo $k; ?>">
                                                                <button type="submit" name="update_status" class="dropdown-item py-1">
                                                                    <i class="fas <?php echo $s['icon']; ?> me-1" style="color: <?php echo $s['color']; ?>;"></i> <?php echo $s['title']; ?>
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>

                                            <!-- انٹرویو تفصیل بٹن -->
                                            <button class="btn btn-sm btn-outline-warning" 
                                                    onclick="openInterviewModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                <i class="fas fa-pencil-alt"></i> <?php echo __('انٹرویو', 'Interview'); ?>
                                            </button>

                                            <!-- طالب علم بنانے کا سنہری بٹن (صرف Accepted پر اگر کنورٹ نہ ہوا ہو) -->
                                            <?php if ($stage_key === 'Accepted' && !$is_converted): ?>
                                                <button class="btn btn-sm btn-success fw-bold flex-grow-1" 
                                                        onclick="openEnrollModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-user-plus me-1"></i> <?php echo __('طالب علم بنائیں', 'Enroll Student'); ?>
                                                </button>
                                            <?php endif; ?>

                                            <!-- ڈیلیٹ بٹن -->
                                            <?php if ($role === 'admin'): ?>
                                                <a href="admissions_pipeline.php?del_id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger ms-auto" 
                                                   onclick="return confirm('کیا آپ واقعی یہ انکوائری حذف کرنا چاہتے ہیں؟');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- موڈل 1: نئی داخلہ درخواست فارم (مکمل فارم) -->
<div class="modal fade" id="newInquiryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-signature me-2"></i> نئی داخلہ درخواست کا اندراج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">نام طالب علم <span class="text-danger">*</span></label>
                            <input type="text" name="student_name" class="form-control" required placeholder="مثلاً: محمد احمد">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ولدیت <span class="text-danger">*</span></label>
                            <input type="text" name="father_name" class="form-control" required placeholder="مثلاً: عبداللہ خان">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">داخلہ نمبر (Registration No)</label>
                            <input type="text" name="admission_number" class="form-control" placeholder="مثلاً <?php echo $suggested_adm_no; ?>" value="<?php echo $suggested_adm_no; ?>">
                            <small class="text-muted">تجویز کردہ: <b><?php echo $suggested_adm_no; ?></b></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">کلاس / درجہ</label>
                            <select name="class_applied" class="form-control" onchange="toggleInqQuran(this.value, 'inq_quran_group')">
                                <option value="">کلاس منتخب کریں</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="inq_quran_group" style="display: none;">
                            <label class="form-label fw-bold text-success"><i class="fas fa-quran me-1"></i> قرآن کریم نسخہ / لائنز</label>
                            <select name="quran_edition" class="form-control border-success">
                                <option value="13 سطری">13 سطری قرآن کریم (13 Lines)</option>
                                <option value="15 سطری">15 سطری قرآن کریم (15 Lines)</option>
                                <option value="16 سطری" selected>16 سطری قرآن کریم (16 Lines - عام فہم)</option>
                                <option value="18 سطری">18 سطری قرآن کریم (18 Lines)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رہائشی حیثیت</label>
                            <select name="residence_status" class="form-control">
                                <option value="">منتخب کریں</option>
                                <option value="residential_aided">اقامتی، امدادی</option>
                                <option value="non_residential_non_aided">غیر اقامتی، غیر امدادی</option>
                                <option value="residential_non_aided">اقامتی، غیر امدادی</option>
                                <option value="non_residential_aided">غیر اقامتی، امدادی</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رابطہ نمبر <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" required placeholder="0300xxxxxxx">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاریخ داخلہ</label>
                            <input type="date" name="admission_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تعلیمی دورانیہ</label>
                            <input type="text" name="duration" class="form-control" placeholder="مثلاً 3 سال">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">مکمل پتہ</label>
                            <input type="text" name="address" class="form-control" placeholder="محلہ / گلی / شہر">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تصویر (Photo)</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">ابتدائی ریمارکس / نوٹس</label>
                            <textarea name="admin_notes" class="form-control" rows="2" placeholder="سابقہ تعلیم یا اضافی معلومات..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="add_inquiry" class="btn btn-primary fw-bold px-4">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 2: انٹرویو ریمارکس و تاریخ -->
<div class="modal fade" id="interviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-check me-2"></i> انٹرویو کی تفصیلات و پیش رفت</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="inquiry_id" id="modal_int_inquiry_id">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-3 text-primary" id="modal_int_student_name"></h6>
                    <div class="mb-3">
                        <label class="form-label fw-bold">انٹرویو / ٹیسٹ کی تاریخ</label>
                        <input type="date" name="interview_date" id="modal_int_date" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">انٹرویو ریمارکس / تاثرات</label>
                        <textarea name="interview_notes" id="modal_int_notes" class="form-control" rows="3" placeholder="طالب علم کی قابلیت، سابقہ حفظ یا قاعدہ کا معیار..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مرحلہ منتخب کریں</label>
                        <select name="status" id="modal_int_status" class="form-select">
                            <option value="Interviewed">ٹیسٹ و انٹرویو (Interviewed)</option>
                            <option value="Accepted">منظور شدہ (Accepted)</option>
                            <option value="Rejected">مسترد شدہ (Rejected)</option>
                            <option value="Pending">زیرِ غور (Pending)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="save_interview" class="btn btn-warning fw-bold px-4">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 3: طالب علم میں منتقلی (Enroll as Student Confirmation) -->
<div class="modal fade" id="enrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-check me-2"></i> باقاعدہ داخلہ فارم (منتقلی)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="inquiry_id" id="modal_enroll_inquiry_id">
                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 small mb-3">
                        یہ درخواست منظور ہو چکی ہے۔ تصدیق کے بعد اس کا ریکارڈ باقاعدہ طلبہ رجسٹر میں شامل ہو جائے گا۔
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">طالب علم کا نام</label>
                            <input type="text" id="modal_enroll_name" class="form-control" readonly style="background: #f8fafc;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">والد کا نام</label>
                            <input type="text" id="modal_enroll_fname" class="form-control" readonly style="background: #f8fafc;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">داخلہ نمبر (Registration No)</label>
                            <input type="text" name="admission_number" id="modal_enroll_adm_no" class="form-control" placeholder="مثلاً <?php echo $suggested_adm_no; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">مقررہ کلاس <span class="text-danger">*</span></label>
                            <select name="enroll_class" id="modal_enroll_class" class="form-control" required onchange="toggleInqQuran(this.value, 'modal_enroll_quran_group')">
                                <option value="">کلاس منتخب کریں</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="modal_enroll_quran_group" style="display: none;">
                            <label class="form-label fw-bold text-success"><i class="fas fa-quran me-1"></i> قرآن کریم نسخہ / لائنز</label>
                            <select name="quran_edition" id="modal_enroll_quran_edition" class="form-control border-success">
                                <option value="13 سطری">13 سطری قرآن کریم (13 Lines)</option>
                                <option value="15 سطری">15 سطری قرآن کریم (15 Lines)</option>
                                <option value="16 سطری" selected>16 سطری قرآن کریم (16 Lines - عام فہم)</option>
                                <option value="18 سطری">18 سطری قرآن کریم (18 Lines)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رہائشی حیثیت</label>
                            <select name="residence_status" id="modal_enroll_residence_status" class="form-control">
                                <option value="">منتخب کریں</option>
                                <option value="residential_aided">اقامتی، امدادی</option>
                                <option value="non_residential_non_aided">غیر اقامتی، غیر امدادی</option>
                                <option value="residential_non_aided">اقامتی، غیر امدادی</option>
                                <option value="non_residential_aided">غیر اقامتی، امدادی</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاریخ داخلہ</label>
                            <input type="date" name="admission_date" id="modal_enroll_adm_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تعلیمی دورانیہ</label>
                            <input type="text" name="duration" id="modal_enroll_duration" class="form-control" placeholder="مثلاً 3 سال">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تصویر (Photo)</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="convert_to_student" class="btn btn-success fw-bold px-4">
                        <i class="fas fa-check-circle me-1"></i> داخلہ مکمل کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 4: ڈائریکٹ طالب علم شامل کریں (Direct Student Enrollment - مکمل فارم) -->
<div class="modal fade" id="directEnrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i> ڈائریکٹ طالب علم داخلہ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="alert alert-success py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i> یہ فارم طالب علم کو براہِ راست باقاعدہ طلبہ رجسٹر میں درج کرے گا اور داخلہ مراحل میں بھی اس کا ریکارڈ بن جائے گا۔
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">نام طالب علم <span class="text-danger">*</span></label>
                            <input type="text" name="student_name" class="form-control" required placeholder="مثلاً: محمد عمر">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ولدیت <span class="text-danger">*</span></label>
                            <input type="text" name="father_name" class="form-control" required placeholder="مثلاً: عبداللہ">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">داخلہ نمبر (Registration No) <span class="text-danger">*</span></label>
                            <input type="text" name="admission_number" class="form-control" required value="<?php echo $suggested_adm_no; ?>" placeholder="مثلاً <?php echo $suggested_adm_no; ?>">
                            <small class="text-muted">تجویز کردہ: <b><?php echo $suggested_adm_no; ?></b></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">کلاس / درجہ <span class="text-danger">*</span></label>
                            <select name="enroll_class" class="form-control" required onchange="toggleInqQuran(this.value, 'direct_quran_group')">
                                <option value="">کلاس منتخب کریں</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="direct_quran_group" style="display: none;">
                            <label class="form-label fw-bold text-success"><i class="fas fa-quran me-1"></i> قرآن کریم نسخہ / لائنز</label>
                            <select name="quran_edition" class="form-control border-success">
                                <option value="13 سطری">13 سطری قرآن کریم (13 Lines)</option>
                                <option value="15 سطری">15 سطری قرآن کریم (15 Lines)</option>
                                <option value="16 سطری" selected>16 سطری قرآن کریم (16 Lines - عام فہم)</option>
                                <option value="18 سطری">18 سطری قرآن کریم (18 Lines)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رہائشی حیثیت</label>
                            <select name="residence_status" class="form-control">
                                <option value="">منتخب کریں</option>
                                <option value="residential_aided">اقامتی، امدادی</option>
                                <option value="non_residential_non_aided">غیر اقامتی، غیر امدادی</option>
                                <option value="residential_non_aided">اقامتی، غیر امدادی</option>
                                <option value="non_residential_aided">غیر اقامتی، امدادی</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رابطہ نمبر <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" required placeholder="0300xxxxxxx">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاریخ داخلہ</label>
                            <input type="date" name="admission_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تعلیمی دورانیہ</label>
                            <input type="text" name="duration" class="form-control" placeholder="مثلاً 3 سال">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">مکمل پتہ</label>
                            <input type="text" name="address" class="form-control" placeholder="محلہ / گلی / شہر">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تصویر (Photo)</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">ابتدائی ریمارکس / نوٹس</label>
                            <textarea name="admin_notes" class="form-control" rows="2" placeholder="سابقہ تعلیم یا اضافی معلومات..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="direct_enroll_student" class="btn btn-success fw-bold px-4">
                        <i class="fas fa-check-circle me-1"></i> طالب علم شامل کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function isQuranClass(cls) {
    if (!cls) return false;
    return cls.includes('حفظ') || cls.includes('ناظرہ') || cls.includes('گردان');
}

function toggleInqQuran(cls, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.style.display = isQuranClass(cls) ? 'block' : 'none';
}

function openInterviewModal(item) {
    document.getElementById('modal_int_inquiry_id').value = item.id;
    document.getElementById('modal_int_student_name').textContent = item.student_name + " بن " + item.father_name;
    document.getElementById('modal_int_date').value = item.interview_date || '';
    document.getElementById('modal_int_notes').value = item.interview_notes || '';
    document.getElementById('modal_int_status').value = item.status || 'Interviewed';
    new bootstrap.Modal(document.getElementById('interviewModal')).show();
}

function openEnrollModal(item) {
    document.getElementById('modal_enroll_inquiry_id').value = item.id;
    document.getElementById('modal_enroll_name').value = item.student_name || '';
    document.getElementById('modal_enroll_fname').value = item.father_name || '';
    const cls = item.class_applied || '';
    if (cls) {
        document.getElementById('modal_enroll_class').value = cls;
    }
    toggleInqQuran(cls, 'modal_enroll_quran_group');
    if (item.admission_number) {
        document.getElementById('modal_enroll_adm_no').value = item.admission_number;
    } else {
        document.getElementById('modal_enroll_adm_no').value = '<?php echo $suggested_adm_no; ?>';
    }
    if (item.quran_edition) {
        document.getElementById('modal_enroll_quran_edition').value = item.quran_edition;
    }
    if (item.residence_status) {
        document.getElementById('modal_enroll_residence_status').value = item.residence_status;
    }
    if (item.admission_date) {
        document.getElementById('modal_enroll_adm_date').value = item.admission_date;
    }
    if (item.duration) {
        document.getElementById('modal_enroll_duration').value = item.duration;
    }
    new bootstrap.Modal(document.getElementById('enrollModal')).show();
}
</script>

<style>
.kanban-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.08) !important;
}
.kanban-items::-webkit-scrollbar {
    width: 6px;
}
.kanban-items::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 4px;
}
</style>

<?php include 'footer.php'; ?>
