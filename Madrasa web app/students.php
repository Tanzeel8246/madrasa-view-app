<?php 
require_once 'db.php'; 
include 'header.php'; 
$cur_tenant_id = (int)get_current_tenant_id();

// --- ایکشنز (Actions) ---

// 1. فارغ التحصیل کرنا (Alumni)
if (isset($_POST['graduate_student'])) {
    validate_csrf_or_abort();
    $id = $_POST['student_id'];
    $g_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
    $category = $_POST['alumni_category'] ?? 'completed';
    $reason = $_POST['reason'] ?? '';
    $duration = !empty($_POST['duration']) ? $_POST['duration'] : null;
    $wifaq_exam = isset($_POST['wifaq_exam']) ? 1 : 0;
    $wifaq_marks = ($wifaq_exam && !empty($_POST['wifaq_marks'])) ? trim($_POST['wifaq_marks']) : null;
    $mehrab = !empty($_POST['mehrab_recitation']) ? trim($_POST['mehrab_recitation']) : null;

    $stmt = $pdo->prepare("UPDATE students SET status = 'Alumni', alumni_category = ?, graduation_date = ?, leaving_reason = ?, duration = ?, wifaq_exam = ?, wifaq_marks = ?, mehrab_recitation = ? WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$category, $g_date, $reason, $duration, $wifaq_exam, $wifaq_marks, $mehrab, $id, $cur_tenant_id]);
    echo "<script>window.location='students.php';</script>";
    exit;
}

// 2. بحال کرنا (Restore - اگر غلطی سے فارغ ہو گیا ہو)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_student'])) {
    validate_csrf_or_abort();
    $r_id = (int)$_POST['student_id'];
    $stmt = $pdo->prepare("UPDATE students SET status = 'Active' WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$r_id, $cur_tenant_id]);
    echo "<script>window.location='students.php';</script>";
    exit;
}

// 2.5 پروموٹ کرنا (Promote)
if (isset($_POST['promote_student'])) {
    validate_csrf_or_abort();
    $id = $_POST['student_id'];
    $new_class = $_POST['new_class'];
    $quran_edition = !empty($_POST['quran_edition']) ? trim($_POST['quran_edition']) : null;
    $hifz_sequence = !empty($_POST['hifz_sequence']) ? trim($_POST['hifz_sequence']) : 'reverse';
    $hifz_section = !empty($_POST['hifz_section']) ? trim($_POST['hifz_section']) : 'regular';
    $is_quran_class = (mb_stripos($new_class, 'حفظ') !== false || mb_stripos($new_class, 'ناظرہ') !== false || mb_stripos($new_class, 'گردان') !== false);
    if ($is_quran_class && $quran_edition) {
        $stmt = $pdo->prepare("UPDATE students SET class = ?, quran_edition = ?, hifz_sequence = ?, hifz_section = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_class, $quran_edition, $hifz_sequence, $hifz_section, $id, $cur_tenant_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE students SET class = ?, hifz_section = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_class, $hifz_section, $id, $cur_tenant_id]);
    }
    echo "<script>window.location='students.php';</script>";
    exit;
}

// 3. ڈیلیٹ کرنا (Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    validate_csrf_or_abort();
    $d_id = (int)$_POST['student_id'];
    $stmt = $pdo->prepare("UPDATE students SET is_deleted = 1 WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$d_id, $cur_tenant_id]);
    echo "<script>window.location='students.php';</script>";
    exit;
}

// 4. محفوظ کرنا (Save)
if (isset($_POST['save_student'])) {
    validate_csrf_or_abort();
    try {
        $name = $_POST['name'];
        $fname = $_POST['father_name'];
        $adm_no = $_POST['admission_number'];
        $cls = $_POST['class'];
        $contact = $_POST['contact_number'];
        $addr = $_POST['address'];
        $residence_status = $_POST['residence_status'] ?? '';
        $hifz_sequence = !empty($_POST['hifz_sequence']) ? $_POST['hifz_sequence'] : 'reverse';
        $hifz_section = !empty($_POST['hifz_section']) ? $_POST['hifz_section'] : 'regular';

        // Auto self-heal columns if not present
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN hifz_sequence VARCHAR(50) DEFAULT 'reverse'");
        } catch (Throwable $eSeq) {}
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN hifz_section VARCHAR(50) DEFAULT 'regular'");
        } catch (Throwable $eSec) {}

        // Photo Handling
        $photo_path = $_POST['old_photo'] ?? '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/students/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_name = "std_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name)) {
                $photo_path = $upload_dir . $photo_name;
            }
        }

        if(empty($contact)) {
            throw new Exception("رابطہ نمبر لکھنا لازمی ہے!");
        }

        $allowed_classes_check = get_allowed_classes($pdo);
        $allowed_class_names_check = array_column($allowed_classes_check, 'name');
        if (($_SESSION['role'] ?? '') !== 'admin' && !in_array($cls, $allowed_class_names_check)) {
            throw new Exception("آپ کو اس کلاس میں اندراج یا ترمیم کی اجازت نہیں ہے!");
        }
        
        // اپڈیٹ
        if(!empty($_POST['edit_id'])) {
            $sql = "UPDATE students SET name=?, father_name=?, admission_number=?, class=?, contact_number=?, address=?, admission_date=?, graduation_date=?, duration=?, photo=?, residence_status=?, quran_edition=?, hifz_sequence=?, hifz_section=? WHERE id=? AND tenant_id=?";
            $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;
            $grad_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
            $duration = !empty($_POST['duration']) ? $_POST['duration'] : null;
            $quran_edition = !empty($_POST['quran_edition']) ? $_POST['quran_edition'] : '16 سطری';
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $cls, $contact, $addr, $adm_date, $grad_date, $duration, $photo_path, $residence_status, $quran_edition, $hifz_sequence, $hifz_section, $_POST['edit_id'], $cur_tenant_id]);
            $msg = "<div class='alert alert-success fw-bold'>✅ ریکارڈ اپڈیٹ ہو گیا!</div>";
        } 
        // نیا داخلہ
        else {
            // Check plan quota limits
            $quotaCheck = function_exists('check_quota_limit') ? check_quota_limit($pdo, 'students', $cur_tenant_id) : ['allowed' => true];
            if (!$quotaCheck['allowed']) {
                throw new Exception($quotaCheck['message']);
            }

            // Drop legacy single-column unique index on students(admission_number) if still present
            try {
                $indexes = $pdo->query("SHOW INDEX FROM `students`")->fetchAll(PDO::FETCH_ASSOC);
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
                            try { $pdo->exec("ALTER TABLE `students` DROP INDEX `$kName`"); } catch (Throwable $eDrop) {}
                        }
                    }
                }
                try { $pdo->exec("ALTER TABLE `students` ADD UNIQUE KEY `idx_tenant_adm_no` (`tenant_id`, `admission_number`)"); } catch (Throwable $eAddIdx) {}
            } catch (Throwable $eIdx) {}

            // Check for duplicate admission number within current tenant
            $check = $pdo->prepare("SELECT id FROM students WHERE admission_number = ? AND tenant_id = ?");
            $check->execute([$adm_no, $cur_tenant_id]);
            if($check->fetch()) {
                throw new Exception("یہ داخلہ نمبر پہلے ہی موجود ہے!");
            }

            // Extract serial and hijri year from adm_no (Format: 1447-01)
            $parts = explode('-', $adm_no);
            $h_year = (int)$parts[0];
            $s_no = isset($parts[1]) ? (int)$parts[1] : 0;

            $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d');
            $quran_edition = !empty($_POST['quran_edition']) ? $_POST['quran_edition'] : '16 سطری';
            $sys = $_SESSION['system_type'] ?? 'Madrasa';
            $sql = "INSERT INTO students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, photo, residence_status, quran_edition, hifz_sequence, hifz_section, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $s_no, $h_year, $cls, $contact, $addr, $adm_date, $photo_path, $residence_status, $quran_edition, $hifz_sequence, $hifz_section, $sys, $cur_tenant_id]);
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا طالب علم ایڈ ہو گیا!</div>";
        }
        echo "<script>window.location='students.php';</script>";
        
    } catch(Exception $e) { 
        $msg = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>"; 
    }
}

// --- ڈیٹا لائیں (دو الگ الگ لسٹیں) ---
// رجسٹریشن نمبر لاجک
$current_hijri_year = '1447';
try {
    $current_hijri_year = $pdo->query("SELECT current_hijri_year FROM settings WHERE tenant_id = $cur_tenant_id LIMIT 1")->fetchColumn() ?: '1447';
} catch (Throwable $e) {
    try {
        $current_hijri_year = $pdo->query("SELECT current_hijri_year FROM settings LIMIT 1")->fetchColumn() ?: '1447';
    } catch (Throwable $e2) {
        $current_hijri_year = '1447';
    }
}

$last_serial = 0;
try {
    // 1. طلبہ ٹیبل سے سب سے بڑا سیریل نمبر لائیں
    $last_serial = (int)($pdo->query("SELECT MAX(serial_no) FROM students WHERE tenant_id = $cur_tenant_id")->fetchColumn() ?: 0);
    // 2. داخلہ درخواستوں کے ٹیبل سے موازنہ کریں (اگر کالم موجود ہو)
    try {
        $app_serial = (int)($pdo->query("SELECT MAX(serial_no) FROM admission_applications WHERE tenant_id = $cur_tenant_id")->fetchColumn() ?: 0);
        $last_serial = max($last_serial, $app_serial);
    } catch (Throwable $eApp) {
        try {
            $app_serial = (int)($pdo->query("SELECT MAX(serial_no) FROM admission_applications")->fetchColumn() ?: 0);
            $last_serial = max($last_serial, $app_serial);
        } catch (Throwable $eApp2) {}
    }
} catch (Throwable $e) {
    $last_serial = 0;
}
$next_serial = $last_serial + 1;
$suggested_adm_no = $current_hijri_year . "-" . sprintf("%02d", $next_serial);

// 1. موجودہ طلباء (Active)
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$user_role_lower = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role_lower, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim']);

// Dynamic column checking to prevent any SQL errors on unmigrated schemas
$std_cols = [];
try {
    $std_cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$has_tenant_col = in_array('tenant_id', $std_cols);
$has_sys_col    = in_array('system_type', $std_cols);
$has_del_col    = in_array('is_deleted', $std_cols);
$has_status_col = in_array('status', $std_cols);
$has_adm_col    = in_array('admission_number', $std_cols);
$has_class_col  = in_array('class', $std_cols);

$tenant_cond = ($has_tenant_col)
    ? (($cur_tenant_id == 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id")
    : "1=1";

$sys_cond = ($has_sys_col)
    ? (($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')")
    : "1=1";

$del_cond = ($has_del_col) ? "(is_deleted = 0 OR is_deleted IS NULL)" : "1=1";

$active_status_cond = ($has_status_col) 
    ? "(LOWER(TRIM(status)) = 'active' OR status = 'حاضر' OR status IS NULL OR status = '')" 
    : "1=1";

$order_by = ($has_adm_col) ? "ORDER BY admission_number DESC" : "ORDER BY id DESC";

$allowed_classes = get_allowed_classes($pdo);
$allowed_class_names = array_column($allowed_classes, 'name');

$class_condition = "1=1";
if (!$is_admin) {
    if (empty($allowed_class_names)) {
        $class_condition = "1=0"; // No access or no classes for this teacher
    } else {
        $inClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names));
        $class_condition = "class IN ($inClasses)";
    }
}

$active_students = [];
try {
    if (!empty($_GET['class_filter']) && $has_class_col) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE $active_status_cond AND $tenant_cond AND $del_cond AND $sys_cond AND class = ? AND ($class_condition) $order_by");
        $stmt->execute([$_GET['class_filter']]);
        $active_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $active_students = $pdo->query("SELECT * FROM students WHERE $active_status_cond AND $tenant_cond AND $del_cond AND $sys_cond AND ($class_condition) $order_by")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $eAct) {
    try {
        // Fallback without class_condition or sys_cond
        $active_students = $pdo->query("SELECT * FROM students WHERE $active_status_cond AND $del_cond $order_by")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eAct2) {
        try {
            // Absolute bare fallback: load any existing student records
            $active_students = $pdo->query("SELECT * FROM students ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eAct3) {
            $active_students = [];
        }
    }
}

// Group active students by class
$grouped_students = [];
foreach ($active_students as $s) {
    $c = empty($s['class']) ? 'دیگر' : $s['class'];
    if (!isset($grouped_students[$c])) $grouped_students[$c] = [];
    $grouped_students[$c][] = $s;
}

// Predefined order
$predefined_order = ['حفظ', 'گردان', 'تجوید', 'حسن صوت', 'اولیٰ', 'اولیٰ دہم', 'اولیٰ نہم', 'مڈل'];
$ordered_classes = [];

foreach ($predefined_order as $pc) {
    if (isset($grouped_students[$pc])) {
        $ordered_classes[] = $pc;
    }
}
foreach (array_keys($grouped_students) as $c) {
    if (!in_array($c, $predefined_order)) {
        $ordered_classes[] = $c;
    }
}

// 2. فارغ التحصیل طلباء (Alumni)
try {
    // Auto-heal dropped & expelled students' categories in DB
    $pdo->exec("UPDATE students SET alumni_category = 'dropped' 
        WHERE (alumni_category IS NULL OR alumni_category = '' OR alumni_category = 'completed') 
          AND (
            LOWER(TRIM(status)) IN ('left', 'dropped') 
            OR leaving_reason LIKE '%چھوڑ%' 
            OR leaving_reason LIKE '%ترک%' 
            OR leaving_reason LIKE '%شوقیہ%'
            OR leaving_reason LIKE '%left%'
            OR leaving_reason LIKE '%drop%'
          )");
    $pdo->exec("UPDATE students SET alumni_category = 'expelled' 
        WHERE (alumni_category IS NULL OR alumni_category = '' OR alumni_category = 'completed') 
          AND (
            LOWER(TRIM(status)) IN ('expelled', 'struck off', 'خارج') 
            OR leaving_reason LIKE '%خارج%' 
            OR leaving_reason LIKE '%اخراج%' 
            OR leaving_reason LIKE '%expel%'
            OR leaving_reason LIKE '%struck%'
          )");
} catch (Throwable $eAlmSync) {}

$alumni_students = [];
$alumni_status_cond = ($has_status_col) 
    ? "(LOWER(TRIM(status)) IN ('alumni', 'left', 'struck off', 'خارج', 'فارغ', 'فارغ التحصیل') OR (graduation_date IS NOT NULL AND graduation_date != ''))"
    : "(graduation_date IS NOT NULL AND graduation_date != '')";

try {
    $alumni_students = $pdo->query("SELECT * FROM students WHERE $alumni_status_cond AND $tenant_cond AND $del_cond AND ($class_condition) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $eAlm) {
    try {
        $alumni_students = $pdo->query("SELECT * FROM students WHERE $alumni_status_cond ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eAlm2) {
        $alumni_students = [];
    }
}

// --- ایڈٹ موڈ ---
$edit_data = null;
$show_form = false; 
if(isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id=? " . ($has_tenant_col ? " AND $tenant_cond" : ""));
        $stmt->execute([$_GET['edit']]);
        $edit_data = $stmt->fetch();
        $show_form = true;
    } catch (Throwable $e) {
        $edit_data = null;
    }
}
?>

<div class="container-fluid">
    
    <div class="sticky-top pt-2" style="z-index: 100;">
        <div class="card mb-3 bg-dark text-white shadow-lg border-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="card-body text-center py-2">
                <div class="d-flex justify-content-center align-items-center gap-3">
                    <button type="button" id="mic_btn" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow" onclick="toggleVoice()">
                        <i class="fas fa-microphone me-2"></i> <span><?php echo ($lang === 'en') ? 'Speak (Start)' : 'بولیں (Start)'; ?></span>
                    </button>
                    <div id="status_text" class="text-white small text-start border-start ps-3" style="min-width: 250px;">
                        <span class="opacity-75"><?php echo ($lang === 'en') ? 'Mic is off.' : 'مائیک بند ہے۔'; ?></span>
                    </div>
                </div>
                <div class="mt-2 small"><b><?php echo ($lang === 'en') ? 'Format:' : 'طریقہ:'; ?></b> <?php echo ($lang === 'en') ? '"Name Ahmed, Father Ali, Roll 50, Class Hifz, Contact 03001234567, Address Lahore... OK"' : '"نام احمد، ولدیت علی، رول نمبر 50، کلاس حفظ، رابطہ 03001234567، پتہ لاہور... اوکے"'; ?></div>
            </div>
        </div>
    </div>

    <?php $studentQuota = function_exists('check_quota_limit') ? check_quota_limit($pdo, 'students', $cur_tenant_id) : ['allowed' => true]; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="text-primary fw-bold m-0"><i class="fas fa-users"></i> <?php echo ($lang === 'en') ? 'Students Record' : 'طلباء کا ریکارڈ'; ?></h3>
        <button class="btn btn-success fw-bold shadow" onclick="toggleForm()">
            <i class="fas fa-user-plus me-2"></i> <?php echo ($lang === 'en') ? 'Add New Student' : 'نیا طالب علم شامل کریں'; ?>
        </button>
    </div>

    <?php if(isset($msg)) echo $msg; ?>
    <?php if (!$studentQuota['allowed'] && !$edit_data): ?>
        <div class="alert alert-danger fw-bold shadow-sm p-3 mb-3 border-2 border-danger d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-lock me-2 fs-5"></i> <strong><?php echo ($lang === 'en') ? 'Limit Reached:' : 'حد مکمل (Quota Reached):'; ?></strong> <?php echo ($lang === 'en') ? 'You have reached the student limit for your subscription plan. Please upgrade your plan to register new students.' : htmlspecialchars($studentQuota['message']); ?>
            </div>
            <a href="subscription_status.php" class="btn btn-sm btn-danger px-3 py-1 fw-bold text-white shadow-sm">
                <i class="fas fa-arrow-up me-1"></i> <?php echo ($lang === 'en') ? 'Upgrade Plan' : 'سبسکرپشن اپ گریڈ کریں'; ?>
            </a>
        </div>
    <?php endif; ?>

    <div id="student_form_container" class="card shadow-sm mb-4 border-top border-5 border-primary" style="display: <?php echo $show_form ? 'block' : 'none'; ?>;">
        <div class="card-header bg-light">
            <h5 class="fw-bold m-0 text-primary"><?php echo $edit_data ? __('ریکارڈ اپڈیٹ کریں', 'Update Record') : __('نیا اندراج', 'New Enrollment'); ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']??''; ?>">
                <input type="hidden" name="old_photo" value="<?php echo $edit_data['photo']??''; ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold"><?php echo __('نام طالب علم', 'Student Name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="s_name" class="form-control" required value="<?php echo $edit_data['name']??''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold"><?php echo __('ولدیت', 'Father Name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="father_name" id="s_fname" class="form-control" required value="<?php echo $edit_data['father_name']??''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold"><?php echo __('داخلہ نمبر (Registration No)', 'Admission / Reg No'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="admission_number" id="s_adm" class="form-control" required value="<?php echo $edit_data['admission_number'] ?? $suggested_adm_no; ?>" placeholder="<?php echo ($lang === 'en') ? 'e.g. ' . $suggested_adm_no : 'مثلاً ' . $suggested_adm_no; ?>">
                        <small class="text-muted"><?php echo __('آخری نمبر:', 'Last Serial:'); ?> <b><?php echo $current_hijri_year . "-" . sprintf("%02d", $last_serial); ?></b></small>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold"><?php echo __('کلاس / درجہ', 'Class / Grade'); ?></label>
                        <select name="class" id="s_class" class="form-control" onchange="toggleQuranEdition(this.value)">
                            <option value=""><?php echo __('کلاس منتخب کریں', 'Select Class'); ?></option>
                            <?php 
                            foreach($allowed_classes as $cl) {
                                if ($cl['status'] !== 'Active') continue;
                                $sel = (isset($edit_data['class']) && $edit_data['class'] == $cl['name']) ? 'selected' : '';
                                echo "<option value='{$cl['name']}' $sel>{$cl['name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('رہائشی حیثیت (Residential Status)', 'Residential Status'); ?></label>
                        <select name="residence_status" class="form-control">
                            <option value=""><?php echo __('منتخب کریں', 'Select'); ?></option>
                            <option value="residential_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'residential_aided') ? 'selected' : ''; ?>><?php echo __('اقامتی، امدادی', 'Boarding, Aided'); ?></option>
                            <option value="non_residential_non_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'non_residential_non_aided') ? 'selected' : ''; ?>><?php echo __('غیر اقامتی، غیر امدادی', 'Day Scholar, Self-Financed'); ?></option>
                            <option value="residential_non_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'residential_non_aided') ? 'selected' : ''; ?>><?php echo __('اقامتی، غیر امدادی', 'Boarding, Self-Financed'); ?></option>
                            <option value="non_residential_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'non_residential_aided') ? 'selected' : ''; ?>><?php echo __('غیر اقامتی، امدادی', 'Day Scholar, Aided'); ?></option>
                        </select>
                    </div>
                    
                    <div class="col-md-8" id="quran_edition_group" style="display: none;">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="fw-bold text-success"><i class="fas fa-quran me-1"></i> <?php echo __('قرآن کریم نسخہ / لائنز (Quran Edition)', 'Quran Edition / Lines'); ?></label>
                                <select name="quran_edition" id="s_quran_edition" class="form-control border-success">
                                    <option value="13 سطری" <?php echo (isset($edit_data['quran_edition']) && $edit_data['quran_edition'] == '13 سطری') ? 'selected' : ''; ?>>13 سطری قرآن کریم (13 Lines)</option>
                                    <option value="15 سطری" <?php echo (isset($edit_data['quran_edition']) && $edit_data['quran_edition'] == '15 سطری') ? 'selected' : ''; ?>>15 سطری قرآن کریم (15 Lines)</option>
                                    <option value="16 سطری" <?php echo (!isset($edit_data['quran_edition']) || $edit_data['quran_edition'] == '16 سطری') ? 'selected' : ''; ?>>16 سطری قرآن کریم (16 Lines - عام فہم)</option>
                                    <option value="18 سطری" <?php echo (isset($edit_data['quran_edition']) && $edit_data['quran_edition'] == '18 سطری') ? 'selected' : ''; ?>>18 سطری قرآن کریم (18 Lines)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold text-success"><i class="fas fa-sort-amount-down me-1"></i> طریقۂ حفظ / ترتیب (Hifz Order)</label>
                                <select name="hifz_sequence" id="s_hifz_sequence" class="form-control border-success">
                                    <option value="reverse" <?php echo (!isset($edit_data['hifz_sequence']) || $edit_data['hifz_sequence'] == 'reverse') ? 'selected' : ''; ?>>طریقہ 1: پارہ 30 تا 1 (عم سے الٹی ترتیب)</option>
                                    <option value="forward" <?php echo (isset($edit_data['hifz_sequence']) && $edit_data['hifz_sequence'] == 'forward') ? 'selected' : ''; ?>>طریقہ 2: پارہ 1 تا 30 (شروع سے سیدھی ترتیب)</option>
                                    <option value="thirty_then_forward" <?php echo (isset($edit_data['hifz_sequence']) && $edit_data['hifz_sequence'] == 'thirty_then_forward') ? 'selected' : ''; ?>>طریقہ 3: پارہ عم (30) پھر 1 تا 29</option>
                                </select>
                            </div>
                            <div class="col-md-12 mt-2">
                                <label class="fw-bold text-primary"><i class="fas fa-layer-group me-1"></i> حفظ سیکشن / نوعیت (Hifz Section):</label>
                                <select name="hifz_section" id="s_hifz_section" class="form-select border-primary fw-bold">
                                    <option value="regular" <?php echo (!isset($edit_data['hifz_section']) || $edit_data['hifz_section'] == 'regular') ? 'selected' : ''; ?>>سیکشن A: ریگولر حفظ (مکمل وقت)</option>
                                    <option value="manzil_gardan" <?php echo (isset($edit_data['hifz_section']) && $edit_data['hifz_section'] == 'manzil_gardan') ? 'selected' : ''; ?>>سیکشن B: منزل / گردان (پارٹ ٹائم / سکول طلبہ)</option>
                                </select>
                                <small class="text-muted">منزل/گردان کے طلبہ کا تعلیمی ریکارڈ بنیادی طور پر منزل/گردان کی دہرائی پر مشتمل ہوتا ہے اور نیا سبق نہ سنانے پر ناغہ شمار نہیں ہوتا۔</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('رابطہ نمبر', 'Contact Number'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="contact_number" id="s_contact" class="form-control" required placeholder="0300xxxxxxx" value="<?php echo $edit_data['contact_number']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('تاریخ داخلہ', 'Admission Date'); ?></label>
                        <input type="date" name="admission_date" class="form-control" value="<?php echo $edit_data['admission_date']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('تاریخ فراغت (Alumni Only)', 'Graduation Date (Alumni Only)'); ?></label>
                        <input type="date" name="graduation_date" class="form-control" value="<?php echo $edit_data['graduation_date']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('تعلیمی دورانیہ', 'Duration'); ?></label>
                        <input type="text" name="duration" class="form-control" placeholder="<?php echo __('مثلاً 3 سال', 'e.g. 3 Years'); ?>" value="<?php echo $edit_data['duration']??''; ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="fw-bold"><?php echo __('مکمل پتہ', 'Full Address'); ?></label>
                        <input type="text" name="address" id="s_addr" class="form-control" value="<?php echo $edit_data['address']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold"><?php echo __('تصویر (Photo)', 'Photo'); ?></label>
                        <input type="file" name="photo" class="form-control">
                        <?php if(!empty($edit_data['photo'])): ?>
                            <img src="<?php echo $edit_data['photo']; ?>" class="mt-2 rounded" width="50">
                        <?php endif; ?>
                    </div>

                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-secondary me-2" onclick="toggleForm()"><?php echo __('کینسل', 'Cancel'); ?></button>
                        <button type="submit" name="save_student" id="save_btn" class="btn btn-primary fw-bold px-4"><?php echo __('محفوظ کریں', 'Save'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php 
    $residence_tabs = [
        'all' => ($lang === 'en') ? 'All' : 'تمام',
        'residential_aided' => ($lang === 'en') ? 'Boarding, Aided' : 'اقامتی، امدادی',
        'non_residential_non_aided' => ($lang === 'en') ? 'Day Scholar, Self-Financed' : 'غیر اقامتی، غیر امدادی',
        'residential_non_aided' => ($lang === 'en') ? 'Boarding, Self-Financed' : 'اقامتی، غیر امدادی',
        'non_residential_aided' => ($lang === 'en') ? 'Day Scholar, Aided' : 'غیر اقامتی، امدادی',
        'alumni' => ($lang === 'en') ? 'Alumni / Passed Out' : 'فارغ التحصیل'
    ];
    ?>
    <ul class="nav nav-tabs mb-4 flex-nowrap overflow-x-auto text-nowrap border-bottom" id="residenceTabs" role="tablist" style="overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        <?php $tab_first = true; foreach($residence_tabs as $tab_key => $tab_label): ?>
            <li class="nav-item" role="presentation" style="flex: 0 0 auto;">
                <button class="nav-link fw-bold <?php echo $tab_first ? 'active' : ''; ?>" id="<?php echo $tab_key; ?>-tab" data-bs-toggle="tab" data-bs-target="#<?php echo $tab_key; ?>" type="button" role="tab" aria-controls="<?php echo $tab_key; ?>" aria-selected="<?php echo $tab_first ? 'true' : 'false'; ?>" style="white-space: nowrap;"><?php echo $tab_label; ?></button>
            </li>
        <?php $tab_first = false; endforeach; ?>
    </ul>
    
    <div class="tab-content" id="residenceTabsContent">
        <?php $tab_first = true; foreach($residence_tabs as $tab_key => $tab_label): ?>
            <div class="tab-pane fade <?php echo $tab_first ? 'show active' : ''; ?>" id="<?php echo $tab_key; ?>" role="tabpanel" aria-labelledby="<?php echo $tab_key; ?>-tab">
                <?php if ($tab_key === 'alumni'): ?>
                    <?php 
                    $alumni_counts = ['all' => count($alumni_students), 'completed' => 0, 'dropped' => 0, 'expelled' => 0];
                    foreach ($alumni_students as $as) {
                        $cat = resolve_alumni_category($as);
                        if (!isset($alumni_counts[$cat])) $alumni_counts[$cat] = 0;
                        $alumni_counts[$cat]++;
                    }
                    ?>
                    <?php if(count($alumni_students) > 0): ?>
    <div class="card shadow-sm border-0 border-top border-5 border-secondary">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <h5 class="m-0 fw-bold"><i class="fas fa-graduation-cap me-2"></i> فارغ التحصیل طلباء (Alumni / Passed Out)</h5>
            <span class="badge bg-light text-dark fw-bold">کل طلباء: <?php echo count($alumni_students); ?></span>
        </div>
        <div class="card-body p-3">
            <!-- 3 Alumni Sub-category Filter Buttons -->
            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center bg-light p-2 rounded border">
                <span class="fw-bold text-secondary me-2"><i class="fas fa-filter me-1"></i> کیٹیگری فلٹر:</span>
                <button type="button" class="btn btn-sm btn-primary active alumni-filter-btn" onclick="filterAlumniCategory('all', this)">تمام (<?php echo $alumni_counts['all']; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-success alumni-filter-btn" onclick="filterAlumniCategory('completed', this)">🎓 تکمیلِ تعلیم (<?php echo $alumni_counts['completed']; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-warning text-dark alumni-filter-btn" onclick="filterAlumniCategory('dropped', this)">⏸️ ترکِ تعلیم (<?php echo $alumni_counts['dropped']; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-danger alumni-filter-btn" onclick="filterAlumniCategory('expelled', this)">❌ اخراج (<?php echo $alumni_counts['expelled']; ?>)</button>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center m-0 bg-white">
                    <thead class="table-secondary">
                        <tr>
                            <th><?php echo ($lang === 'en') ? 'S.No' : 'نمبرشمار'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Roll No / Reg' : 'رول نمبر'; ?></th>
                            <th class="text-start ps-3"><?php echo ($lang === 'en') ? 'Student Name' : 'نام طالب علم'; ?></th>
                            <th><?php echo ($lang === 'en') ? "Father's Name" : 'ولدیت'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Contact No' : 'رابطہ نمبر'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Category' : 'کیٹیگری'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Admission Date' : 'تاریخ داخلہ'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Graduation Date' : 'تاریخ فراغت'; ?></th>
                            <th><?php echo ($lang === 'en') ? 'Duration' : 'دورانیہ'; ?></th>
                            <th><?php echo t('action_col'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $serial_num_alumni = 1; foreach($alumni_students as $s): 
                            $cat = resolve_alumni_category($s);
                            if ($cat === 'completed') {
                                $catBadge = '<span class="badge bg-success"><i class="fas fa-graduation-cap me-1"></i> تکمیلِ تعلیم</span>';
                            } elseif ($cat === 'dropped') {
                                $catBadge = '<span class="badge bg-warning text-dark"><i class="fas fa-pause-circle me-1"></i> ترکِ تعلیم</span>';
                            } elseif ($cat === 'expelled') {
                                $catBadge = '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> اخراج</span>';
                            } else {
                                $catBadge = '<span class="badge bg-secondary">سابقہ</span>';
                            }
                        ?>
                        <tr class="text-muted alumni-row" data-category="<?php echo htmlspecialchars($cat); ?>">
                            <td class="fw-bold"><?php echo $serial_num_alumni++; ?></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($s['admission_number']); ?></span></td>
                            <td class="text-start ps-3 fw-bold text-dark"><?php echo htmlspecialchars($s['name']); ?></td>
                            <td><?php echo htmlspecialchars($s['father_name'] ?? '-'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($s['contact_number'] ?? '-'); ?></td>
                            <td><?php echo $catBadge; ?></td>
                            <td><?php echo !empty($s['admission_date']) ? date('d-m-Y', strtotime($s['admission_date'])) : '-'; ?></td>
                            <td><?php echo !empty($s['graduation_date']) ? date('d-m-Y', strtotime($s['graduation_date'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($s['duration'] ?? '-'); ?></td>
                            <td>
                                <div class="btn-group">
                                    <?php if ($cat === 'completed'): ?>
                                        <a href="print_sanad.php?student_id=<?php echo $s['id']; ?>" target="_blank" class="btn btn-sm btn-warning text-dark fw-bold" title="سند جاری و پرنٹ کریں">
                                            <i class="fas fa-certificate me-1"></i> سند
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" action="students.php" style="display:inline-block;" onsubmit="return confirm('کیا اسے دوبارہ موجودہ لسٹ میں شامل کرنا ہے؟');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="restore_student" value="1">
                                        <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="دوبارہ داخل (Restore)"><i class="fas fa-undo"></i></button>
                                    </form>
                                    <form method="POST" action="students.php" style="display:inline-block;" onsubmit="return confirm('کیا واقعی یہ ریکارڈ ہمیشہ کے لیے ڈیلیٹ کرنا چاہتے ہیں؟');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_student" value="1">
                                        <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="ہمیشہ کے لیے ڈیلیٹ"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">کوئی فارغ التحصیل طالب علم موجود نہیں ہے۔</div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if(empty($ordered_classes)): ?>
                    <div class="alert alert-info text-center fw-bold">کوئی موجودہ طالب علم نہیں</div>
                <?php else: ?>
                    <?php foreach($ordered_classes as $cName): ?>
                        <?php 
                            // Filter students for this tab
                            $tab_students = array_filter($grouped_students[$cName], function($s) use ($tab_key) {
                                $s_status = $s['residence_status'] ?? '';
                                if ($tab_key === 'all') return true;
                                return $s_status === $tab_key;
                            });
                            if(empty($tab_students)) continue; // Skip if no students for this class in this tab
                        ?>
                        <div class="card shadow-sm border-0 mb-4 border-top border-3 border-primary">
                            <div class="card-header bg-light">
                                <h5 class="m-0 fw-bold text-primary"><i class="fas fa-users me-2"></i> <?php echo ($lang === 'en') ? ('Class: ' . $cName) : ('کلاس: ' . $cName); ?></h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle text-center m-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th><?php echo ($lang === 'en') ? 'S.No' : 'نمبرشمار'; ?></th>
                                                <th><?php echo ($lang === 'en') ? 'Roll No / Reg' : 'رول نمبر'; ?></th>
                                                <th><?php echo ($lang === 'en') ? 'Photo' : 'تصویر'; ?></th>
                                                <th><?php echo ($lang === 'en') ? 'Student Name' : 'نام طالب علم'; ?></th>
                                                <th><?php echo ($lang === 'en') ? "Father's Name" : 'ولدیت'; ?></th>
                                                <th><?php echo ($lang === 'en') ? 'Contact' : 'رابطہ نمبر'; ?></th>
                                                <th style="width: 200px;"><?php echo ($lang === 'en') ? 'Actions' : 'ایکشن'; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $serial_num = 1; foreach($tab_students as $s): ?>
                                            <tr>
                                                <td class="fw-bold text-muted"><?php echo $serial_num++; ?></td>
                                                <td class="fw-bold"><?php echo $s['admission_number']; ?></td>
                                                <td>
                                                    <?php if(!empty($s['photo'])): ?>
                                                        <img src="<?php echo $s['photo']; ?>" class="rounded-circle" width="35" height="35" style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="bg-secondary text-white rounded-circle d-inline-block text-center" style="width:35px; height:35px; line-height:35px;"><i class="fas fa-user"></i></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-start ps-3 fw-bold">
                                                    <?php echo e($s['name']); ?>
                                                    <?php if (($s['hifz_section'] ?? 'regular') === 'manzil_gardan'): ?>
                                                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.75rem;"><i class="fas fa-book-reader me-1"></i>منزل/گردان</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo e($s['father_name']); ?></td>
                                                <td><?php echo e($s['contact_number']); ?></td>
                                                <td>
                                                    <div class="btn-group shadow-sm">
                                                        <a href="print_form.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-info text-white" title="فارم پرنٹ کریں" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                        <a href="print_id_card.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-success text-white" title="آئی ڈی کارڈ" target="_blank">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <a href="students.php?edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('btn_edit'); ?>"><i class="fas fa-edit"></i></a>
                                                        
                                                        <button type="button" class="btn btn-sm btn-dark text-warning" title="فارغ التحصیل کریں" onclick="openGraduateModal('<?php echo $s['id']; ?>', '<?php echo addslashes($s['name']); ?>', '<?php echo $s['admission_date']; ?>')">
                                                            <i class="fas fa-user-graduate"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-sm btn-info text-white" title="پروموٹ کریں" onclick="openPromoteModal('<?php echo $s['id']; ?>', '<?php echo addslashes($s['name']); ?>', '<?php echo addslashes($s['quran_edition'] ?? '16 سطری'); ?>', '<?php echo addslashes($s['hifz_sequence'] ?? 'reverse'); ?>', '<?php echo addslashes($s['hifz_section'] ?? 'regular'); ?>')">
                                                            <i class="fas fa-level-up-alt"></i>
                                                        </button>
                                                        
                                                        <form method="POST" action="students.php" style="display:inline-block;" onsubmit="return confirm('کیا واقعی یہ ریکارڈ ہمیشہ کے لیے ڈیلیٹ کرنا چاہتے ہیں؟');">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="delete_student" value="1">
                                                            <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" title="ڈیلیٹ"><i class="fas fa-trash"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; // end alumni else ?>
            </div>
        <?php $tab_first = false; endforeach; ?>
    </div>

    <!-- Graduation Modal -->
<div class="modal fade" id="graduateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content shadow-lg border-0" style="border-radius: 15px;">
            <?php echo csrf_field(); ?>
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-graduate me-2 text-warning"></i> فارغ التحصیل / خارج ریکارڈ (Alumni & Migration)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="student_id" id="grad_std_id">
                <div class="mb-3">
                    <label class="fw-bold mb-1">نام طالب علم:</label>
                    <input type="text" id="grad_name" class="form-control bg-light fw-bold" readonly>
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1 text-primary">نوعیت / کیٹیگری:*</label>
                    <select name="alumni_category" id="grad_category" class="form-select border-primary fw-bold" required onchange="onCategoryChange()">
                        <option value="completed">🎓 تعلیم مکمل کرنے والے (تکمیلِ تعلیم)</option>
                        <option value="dropped">⏸️ درمیان میں چھوڑ کر جانے والے (ترکِ تعلیم)</option>
                        <option value="expelled">❌ مدرسہ سے خارج کیے جانے والے (اخراج)</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="fw-bold mb-1">تاریخ داخلہ:*</label>
                        <input type="date" name="admission_date" id="grad_adm_date" class="form-control border-primary" required onchange="calcDuration()">
                    </div>
                    <div class="col-6">
                        <label class="fw-bold mb-1 text-primary">تاریخ فراغت / اخراج:*</label>
                        <input type="date" name="graduation_date" id="grad_date" class="form-control border-primary" required onchange="calcDuration()">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1">کل تعلیمی دورانیہ (خودکار):</label>
                    <input type="text" name="duration" id="grad_duration" class="form-control fw-bold text-success bg-light" readonly placeholder="خودکار کیلکولیٹ ہوگا">
                </div>

                <!-- تکمیلِ تعلیم کے اضافی فیلڈز -->
                <div id="completed_fields" class="mb-3">
                    <div class="card p-3 bg-light border-primary shadow-sm" style="border-radius: 10px;">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="wifaq_exam" id="grad_wifaq_exam" onchange="toggleWifaqMarks()">
                            <label class="form-check-label fw-bold text-dark" for="grad_wifaq_exam">کیا طالب علم نے وفاق کا امتحان دیا ہے؟</label>
                        </div>
                        <div id="wifaq_marks_box" style="display: none;" class="mb-2">
                            <label class="fw-bold mb-1 small text-primary">وفاق امتحان میں حاصل کردہ نمبرات / پوزیشن:</label>
                            <input type="text" name="wifaq_marks" id="grad_wifaq_marks" class="form-control form-control-sm" placeholder="مثال: 450 یا ممتاز">
                        </div>
                        <div class="mt-2">
                            <label class="fw-bold mb-1 small text-dark">محراب (تراویح میں قرآن سنایا؟):</label>
                            <select name="mehrab_recitation" id="grad_mehrab" class="form-select form-select-sm border-secondary">
                                <option value="">منتخب کریں...</option>
                                <option value="ہاں">ہاں</option>
                                <option value="نہیں">نہیں</option>
                                <option value="سنا ہے">سنا ہے</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="fw-bold mb-1">فراغت / اخراج کی وجہ:*</label>
                    <input type="text" name="reason" id="grad_reason" class="form-control border-primary" required value="تعلیم مکمل ہونے کی وجہ سے">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                <button type="submit" name="graduate_student" class="btn btn-dark text-warning fw-bold px-4">محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Promote Modal -->
<div class="modal fade" id="promoteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
            <?php echo csrf_field(); ?>
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-level-up-alt me-2"></i> طالب علم کو پروموٹ کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="student_id" id="promote_std_id">
                <div class="mb-3">
                    <label class="fw-bold mb-1">نام طالب علم:</label>
                    <input type="text" id="promote_name" class="form-control bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1 text-primary">نئی کلاس منتخب کریں:*</label>
                    <select name="new_class" id="promote_new_class" class="form-select border-primary" required onchange="togglePromoteQuranEdition(this.value)">
                        <option value="">منتخب کریں...</option>
                        <?php 
                        foreach($allowed_classes as $cl) {
                            if ($cl['status'] !== 'Active') continue;
                            echo "<option value='{$cl['name']}'>{$cl['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="mb-3" id="promote_quran_container" style="display: none;">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="fw-bold mb-1 text-success"><i class="fas fa-quran me-1"></i> تعلیمی نسخہ قرآن کریم:*</label>
                            <select name="quran_edition" id="promote_quran_edition" class="form-select border-success">
                                <option value="13 سطری">13 سطری قرآن کریم (13 Lines)</option>
                                <option value="15 سطری">15 سطری قرآن کریم (15 Lines)</option>
                                <option value="16 سطری" selected>16 سطری قرآن کریم (16 Lines - عام فہم)</option>
                                <option value="18 سطری">18 سطری قرآن کریم (18 Lines)</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="fw-bold mb-1 text-success"><i class="fas fa-sort-amount-down me-1"></i> طریقۂ حفظ:*</label>
                            <select name="hifz_sequence" id="promote_hifz_sequence" class="form-select border-success">
                                <option value="reverse" selected>طریقہ 1: پارہ 30 تا 1 (عم سے الٹی ترتیب)</option>
                                <option value="forward">طریقہ 2: پارہ 1 تا 30 (شروع سے سیدھی ترتیب)</option>
                                <option value="thirty_then_forward">طریقہ 3: پارہ عم (30) پھر 1 تا 29</option>
                            </select>
                        </div>
                        <div class="col-sm-12 mt-2">
                            <label class="fw-bold mb-1 text-primary"><i class="fas fa-layer-group me-1"></i> حفظ سیکشن / نوعیت:*</label>
                            <select name="hifz_section" id="promote_hifz_section" class="form-select border-primary">
                                <option value="regular" selected>سیکشن A: ریگولر حفظ (مکمل وقت)</option>
                                <option value="manzil_gardan">سیکشن B: منزل / گردان (پارٹ ٹائم / سکول)</option>
                            </select>
                        </div>
                    </div>
                    <small class="text-muted">حفظ، ناظرہ یا گردان میں پروموشن پر قرآنی نسخہ اور طریقۂ حفظ ریکارڈ میں خودکار شامل ہو جائے گا۔</small>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                <button type="submit" name="promote_student" class="btn btn-info text-white fw-bold px-4">پروموٹ کریں</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleForm() {
    var form = document.getElementById('student_form_container');
    if (form.style.display === 'none') {
        form.style.display = 'block';
        document.getElementById('s_name').focus();
    } else {
        form.style.display = 'none';
    }
}

// Voice Logic
var recognition; var isListening = false;
function toggleVoice() { if(isListening) stopRecognition(); else startRecognition(); }

function startRecognition() {
    if (!('webkitSpeechRecognition' in window)) { alert("Use Google Chrome"); return; }
    
    document.getElementById('student_form_container').style.display = 'block'; // فارم کھولیں

    recognition = new webkitSpeechRecognition();
    recognition.lang = 'ur-PK'; recognition.continuous = true; recognition.interimResults = true;
    
    var btn = document.getElementById('mic_btn');
    btn.innerHTML = '<i class="fas fa-stop"></i> رک جائیں'; 
    btn.className = 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow pulse-btn';
    
    isListening = true;
    recognition.onresult = function(event) {
        var txt = ""; for (var i = event.resultIndex; i < event.results.length; ++i) txt += event.results[i][0].transcript;
        document.getElementById('status_text').innerHTML = "<b>سنا گیا:</b> " + txt;
        processStudentData(txt);
    };
    recognition.onerror = function() { stopRecognition(); };
    recognition.start();
}

function stopRecognition() { isListening = false; if(recognition) recognition.stop(); 
    var btn = document.getElementById('mic_btn'); btn.innerHTML = 'بولیں (Start)'; btn.className = 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow';
    document.getElementById('status_text').innerHTML = "مائیک بند ہے۔";
}

function processStudentData(text) {
    if (text.match(/(?:سیو|محفوظ|اوکے|ڈن)/i)) { stopRecognition(); document.getElementById('save_btn').click(); return; }

    var name = text.match(/(?:نام|name)\s*([ا-ی\w\s]+?)(?:ولدیت|باپ|رول|$)/);
    if(name) { document.getElementById('s_name').value = name[1].trim(); highlight('s_name'); }

    var fname = text.match(/(?:ولدیت|سن آف|باپ|والد)\s*([ا-ی\w\s]+?)(?:رول|کلاس|پتہ|$)/);
    if(fname) { document.getElementById('s_fname').value = fname[1].trim(); highlight('s_fname'); }

    var roll = text.match(/(?:رول نمبر|آئی ڈی|نمبر)\s*(\d+|[ا-ی]+)/);
    if(roll && urduToNum(roll[1])) { document.getElementById('s_adm').value = urduToNum(roll[1]); highlight('s_adm'); }

    var cls = text.match(/(?:کلاس|درجہ)\s*([ا-ی\w\s]+?)(?:پتہ|رابطہ|$)/);
    if(cls) { document.getElementById('s_class').value = cls[1].trim(); highlight('s_class'); }

    // Contact Number (Voice)
    var contact = text.match(/(?:رابطہ|موبائل|فون|نمبر)\s*([\d\s]+)/);
    if(contact) {
        var cleanNum = contact[1].replace(/\s/g, '');
        document.getElementById('s_contact').value = cleanNum;
        highlight('s_contact');
    }

    var addr = text.match(/(?:پتہ|ایڈریس|رہائش)\s*([ا-ی\w\s]+)/);
    if(addr) { document.getElementById('s_addr').value = addr[1].trim(); highlight('s_addr'); }
}

function highlight(id) { var el = document.getElementById(id); el.style.border = "2px solid green"; setTimeout(function(){ el.style.border = ""; }, 1000); }
function urduToNum(str) { if(str.match(/^\d+$/)) return parseInt(str); var map = {'ایک':1,'دو':2,'پاںچ':5,'دس':10}; for(var k in map) if(str.includes(k)) return map[k]; return str; }

function isQuranClass(cls) {
    if (!cls) return false;
    return cls.includes('حفظ') || cls.includes('ناظرہ') || cls.includes('گردان');
}

function toggleQuranEdition(cls) {
    const el = document.getElementById('quran_edition_group');
    if (!el) return;
    el.style.display = isQuranClass(cls) ? 'block' : 'none';
}

function togglePromoteQuranEdition(cls) {
    const el = document.getElementById('promote_quran_container');
    if (!el) return;
    el.style.display = isQuranClass(cls) ? 'block' : 'none';
}

function openGraduateModal(id, name, admDate) {
    document.getElementById('grad_std_id').value = id;
    document.getElementById('grad_name').value = name;
    document.getElementById('grad_adm_date').value = admDate;
    document.getElementById('grad_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('grad_category').value = 'completed';
    document.getElementById('grad_wifaq_exam').checked = false;
    document.getElementById('grad_wifaq_marks').value = '';
    document.getElementById('grad_mehrab').value = '';
    document.getElementById('wifaq_marks_box').style.display = 'none';
    document.getElementById('completed_fields').style.display = 'block';
    document.getElementById('grad_reason').value = 'تعلیم مکمل ہونے کی وجہ سے';
    calcDuration();
    new bootstrap.Modal(document.getElementById('graduateModal')).show();
}

function onCategoryChange() {
    const cat = document.getElementById('grad_category').value;
    const completedFields = document.getElementById('completed_fields');
    const reasonInput = document.getElementById('grad_reason');
    
    if (cat === 'completed') {
        completedFields.style.display = 'block';
        reasonInput.value = 'تعلیم مکمل ہونے کی وجہ سے';
    } else if (cat === 'dropped') {
        completedFields.style.display = 'none';
        reasonInput.value = 'بغیر تعلیم مکمل کیے (درمیان میں چھوڑا)';
    } else if (cat === 'expelled') {
        completedFields.style.display = 'none';
        reasonInput.value = 'مدرسہ سے خارج کیا گیا (تادیبی کارروائی)';
    }
}

function toggleWifaqMarks() {
    const isChecked = document.getElementById('grad_wifaq_exam').checked;
    document.getElementById('wifaq_marks_box').style.display = isChecked ? 'block' : 'none';
}

function openPromoteModal(id, name, currentQuran, currentSequence, currentSection) {
    document.getElementById('promote_std_id').value = id;
    document.getElementById('promote_name').value = name;
    document.getElementById('promote_new_class').value = '';
    document.getElementById('promote_quran_container').style.display = 'none';
    if (currentQuran && document.getElementById('promote_quran_edition')) {
        document.getElementById('promote_quran_edition').value = currentQuran;
    }
    if (currentSequence && document.getElementById('promote_hifz_sequence')) {
        document.getElementById('promote_hifz_sequence').value = currentSequence;
    }
    if (currentSection && document.getElementById('promote_hifz_section')) {
        document.getElementById('promote_hifz_section').value = currentSection;
    }
    new bootstrap.Modal(document.getElementById('promoteModal')).show();
}

document.addEventListener("DOMContentLoaded", function() {
    const sClass = document.getElementById('s_class');
    if (sClass) toggleQuranEdition(sClass.value);
});

function calcDuration() {
    let admDateVal = document.getElementById('grad_adm_date').value;
    let gradDateVal = document.getElementById('grad_date').value;
    
    if(!admDateVal || !gradDateVal) return;

    let d1 = new Date(admDateVal);
    let d2 = new Date(gradDateVal);
    
    if(d2 < d1) {
        document.getElementById('grad_duration').value = "غلط تاریخ!";
        return;
    }

    let diff = d2 - d1;
    let daysTotal = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    let years = Math.floor(daysTotal / 365);
    let remainingDays = daysTotal % 365;
    let months = Math.floor(remainingDays / 30);
    let days = remainingDays % 30;

    let res = "";
    if(years > 0) res += years + " سال ";
    if(months > 0) res += months + " ماہ ";
    if(days > 0) res += days + " دن";
    
    document.getElementById('grad_duration').value = res.trim() || "0 دن";
}

function filterAlumniCategory(category, btn) {
    document.querySelectorAll('.alumni-filter-btn').forEach(b => {
        b.classList.remove('btn-primary', 'active');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.add('btn-primary', 'active');
    btn.classList.remove('btn-outline-secondary');

    const rows = document.querySelectorAll('.alumni-row');
    rows.forEach(row => {
        const cat = row.getAttribute('data-category') || 'completed';
        if (category === 'all' || cat === category) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<style>.pulse-btn { animation: pulse 1s infinite; }</style>

<?php include 'footer.php'; ?>