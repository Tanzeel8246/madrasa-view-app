<?php 
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once 'db.php'; 
include 'header.php'; 

// سیکیورٹی چیک
if (!isset($_SESSION['user_id'])) { echo "<script>window.location='login.php';</script>"; exit; }

// ─── staff_type اور responsibilities کالم شامل کریں (ایک بار) ───
try { $pdo->exec("ALTER TABLE maktab_teachers ADD COLUMN staff_type VARCHAR(50) DEFAULT 'Teacher'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE maktab_teachers ADD COLUMN responsibilities TEXT DEFAULT ''"); } catch (PDOException $e) {}

// ─── class_periods ٹیبل یقینی بنائیں ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        period_no INT NOT NULL,
        books TEXT,
        teacher_ids JSON NULL
    )");
} catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE class_periods ADD COLUMN period_no INT NULL"); } catch (PDOException $e) {}

$msg = "";

function teacher_u($unicode) {
    return json_decode('"' . $unicode . '"');
}

function teacher_period_labels($class) {
    $text = trim(($class['name'] ?? '') . ' ' . ($class['class_type'] ?? '') . ' ' . ($class['section'] ?? ''));
    $lower = strtolower($text);
    if (strpos($text, teacher_u('\u0646\u0627\u0638\u0631')) !== false || strpos($lower, 'nazira') !== false) {
        return ['nazira_morning' => teacher_u('\u0635\u0628\u062d'), 'nazira_evening' => teacher_u('\u0634\u0627\u0645')];
    }
    if (strpos($text, teacher_u('\u062d\u0641\u0638')) !== false || strpos($lower, 'hifz') !== false) {
        return [
            'hifz_morning'         => teacher_u('\u0635\u0628\u062d'),
            'hifz_after_breakfast' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
            'hifz_noon'            => teacher_u('\u062f\u0648\u067e\u06c1\u0631 (\u0638\u06c1\u0631 \u06a9\u06d2 \u0628\u0639\u062f)'),
            'hifz_evening'         => teacher_u('\u0634\u0627\u0645 (\u0645\u063a\u0631\u0628 \u06a9\u06d2 \u0628\u0639\u062f)')
        ];
    }
    return [
        '1' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 1'),
        '2' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 2'),
        '3' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 3'),
        '4' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 4'),
        '5' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 5'),
        '6' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 6'),
        '7' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 7'),
        '8' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 8'),
    ];
}

function teacher_period_no($period_key) {
    $map = [
        'hifz_morning' => 1, 'hifz_after_breakfast' => 2, 'hifz_noon' => 3, 'hifz_evening' => 4,
        'hifz_sabaq' => 1, 'hifz_sabqi' => 2, 'hifz_manzil' => 3, 'hifz_review' => 4,
        'nazira_morning' => 1, 'nazira_evening' => 2
    ];
    return isset($map[$period_key]) ? $map[$period_key] : (int)$period_key;
}

function teacher_period_key_for_class($class, $period_no) {
    $text = strtolower(trim(($class['name'] ?? '') . ' ' . ($class['class_type'] ?? '')));
    $period_no = (int)$period_no;
    if (strpos($text, 'hifz') !== false || strpos($class['name'] ?? '', teacher_u('\u062d\u0641\u0638')) !== false) {
        return [1 => 'hifz_morning', 2 => 'hifz_after_breakfast', 3 => 'hifz_noon', 4 => 'hifz_evening'][$period_no] ?? (string)$period_no;
    }
    if (strpos($text, 'nazira') !== false || strpos($class['name'] ?? '', teacher_u('\u0646\u0627\u0638\u0631')) !== false) {
        return [1 => 'nazira_morning', 2 => 'nazira_evening'][$period_no] ?? (string)$period_no;
    }
    return (string)$period_no;
}

function teacher_period_label_by_key($key) {
    $labels = [
        'hifz_morning' => teacher_u('\u0635\u0628\u062d'), 'hifz_after_breakfast' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
        'hifz_noon' => teacher_u('\u062f\u0648\u067e\u06c1\u0631'), 'hifz_evening' => teacher_u('\u0634\u0627\u0645'),
        'hifz_sabaq' => teacher_u('\u0635\u0628\u062d'), 'hifz_sabqi' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
        'hifz_manzil' => teacher_u('\u062f\u0648\u067e\u06c1\u0631'), 'hifz_review' => teacher_u('\u0634\u0627\u0645'),
        'nazira_morning' => teacher_u('\u0635\u0628\u062d'), 'nazira_evening' => teacher_u('\u0634\u0627\u0645')
    ];
    if (isset($labels[$key])) return $labels[$key];
    if (ctype_digit((string)$key)) return teacher_u('\u067e\u06cc\u0631\u06cc\u0688 ') . $key;
    return (string)$key;
}

// عملہ کی اقسام
$staff_types = [
    'باورچی'      => '🍳',
    'خادم'        => '🧹',
    'کیشیئر'      => '💰',
    'ناظم'        => '📋',
    'چوکیدار'     => '🔒',
    'ڈرائیور'     => '🚗',
    'دفتری'       => '🗂️',
    'صفائی کارکن' => '🧽',
    'دیگر'        => '👤',
];

// ─── استاد محفوظ کریں ───
if (isset($_POST['save_teacher'])) {
    try {
        $pdo->beginTransaction();

        $name     = $_POST['name'];
        $fname    = $_POST['father_name'];
        $phone    = $_POST['phone'];
        $cnic     = $_POST['cnic'];
        $qual     = $_POST['qualification'];
        $addr     = $_POST['address'];
        $salary   = !empty($_POST['salary']) ? $_POST['salary'] : 0;
        $house_rent         = !empty($_POST['house_rent']) ? $_POST['house_rent'] : 0;
        $medical_allowance  = !empty($_POST['medical_allowance']) ? $_POST['medical_allowance'] : 0;
        $travel_allowance   = !empty($_POST['travel_allowance']) ? $_POST['travel_allowance'] : 0;
        $daily_deduction_rate = !empty($_POST['daily_deduction_rate']) ? $_POST['daily_deduction_rate'] : 0;
        $j_date   = !empty($_POST['joining_date']) ? $_POST['joining_date'] : date('Y-m-d');
        $status   = $_POST['status'];
        $responsibilities = trim($_POST['responsibilities'] ?? '');

        $teacher_id = null;

        if (!empty($_POST['edit_id'])) {
            $teacher_id = $_POST['edit_id'];
            $sql = "UPDATE maktab_teachers SET name=?, father_name=?, phone=?, cnic=?, qualification=?, address=?, salary=?, house_rent=?, medical_allowance=?, travel_allowance=?, daily_deduction_rate=?, joining_date=?, status=?, responsibilities=?, staff_type='Teacher' WHERE id=?";
            $pdo->prepare($sql)->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $responsibilities, $teacher_id]);
            $msg = "<div class='alert alert-success fw-bold'>✅ استاد کا ریکارڈ اپڈیٹ ہو گیا!</div>";
        } else {
            $sql = "INSERT INTO maktab_teachers (name, father_name, phone, cnic, qualification, address, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, joining_date, status, responsibilities, staff_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Teacher')";
            $pdo->prepare($sql)->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $responsibilities]);
            $teacher_id = $pdo->lastInsertId();
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا استاد شامل کر لیا گیا!</div>";
        }

        // کلاس / پیریڈ الاٹمنٹ
        if ($teacher_id) {
            try {
                $all_maktab_classes_db = $pdo->query("SELECT id, main_teacher_ids FROM maktab_classes")->fetchAll(PDO::FETCH_ASSOC);
                $update_class_stmt = $pdo->prepare("UPDATE maktab_classes SET main_teacher_ids = ? WHERE id = ?");
                foreach ($all_maktab_classes_db as $cls) {
                    $tids = json_decode($cls['main_teacher_ids'], true) ?: [];
                    if (in_array((int)$teacher_id, $tids, true) || in_array((string)$teacher_id, $tids, true)) {
                        $tids = array_values(array_filter($tids, function($id) use ($teacher_id) { return (int)$id !== (int)$teacher_id; }));
                        $update_class_stmt->execute([json_encode($tids), $cls['id']]);
                    }
                }

                $all_cps_db = $pdo->query("SELECT id, teacher_ids FROM class_periods")->fetchAll(PDO::FETCH_ASSOC);
                $update_cp_stmt = $pdo->prepare("UPDATE class_periods SET teacher_ids = ? WHERE id = ?");
                foreach ($all_cps_db as $cp) {
                    $tids = json_decode($cp['teacher_ids'], true) ?: [];
                    if (in_array((int)$teacher_id, $tids, true) || in_array((string)$teacher_id, $tids, true)) {
                        $tids = array_values(array_filter($tids, function($id) use ($teacher_id) { return (int)$id !== (int)$teacher_id; }));
                        $update_cp_stmt->execute([json_encode($tids), $cp['id']]);
                    }
                }

                if (!empty($_POST['assign_class']) && is_array($_POST['assign_class'])) {
                    foreach ($_POST['assign_class'] as $c_id => $val) {
                        $cls = $pdo->query("SELECT main_teacher_ids FROM maktab_classes WHERE id = " . (int)$c_id)->fetch(PDO::FETCH_ASSOC);
                        if ($cls) {
                            $tids = json_decode($cls['main_teacher_ids'], true) ?: [];
                            if (!in_array((int)$teacher_id, $tids) && !in_array((string)$teacher_id, $tids)) {
                                $tids[] = (int)$teacher_id;
                                $update_class_stmt->execute([json_encode($tids), $c_id]);
                            }
                        }
                    }
                }

                if (!empty($_POST['assign_period']) && is_array($_POST['assign_period'])) {
                    foreach ($_POST['assign_period'] as $c_id => $periods) {
                        foreach ($periods as $p_num => $val) {
                            $period_no = teacher_period_no($p_num);
                            $cp = $pdo->prepare("SELECT id, teacher_ids FROM class_periods WHERE class_id = ? AND period_no = ?");
                            $cp->execute([$c_id, $period_no]);
                            $cp_row = $cp->fetch(PDO::FETCH_ASSOC);
                            if ($cp_row) {
                                $tids = json_decode($cp_row['teacher_ids'], true) ?: [];
                                if (!in_array((int)$teacher_id, $tids) && !in_array((string)$teacher_id, $tids)) {
                                    $tids[] = (int)$teacher_id;
                                    $update_cp_stmt->execute([json_encode($tids), $cp_row['id']]);
                                }
                            } else {
                                $pdo->prepare("INSERT INTO class_periods (class_id, period_no, teacher_ids) VALUES (?, ?, ?)")
                                    ->execute([$c_id, $period_no, json_encode([(int)$teacher_id])]);
                            }
                        }
                    }
                }
            } catch (PDOException $e) {}
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>❌ ایرر: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ─── دیگر عملہ محفوظ کریں ───
if (isset($_POST['save_staff'])) {
    try {
        $name      = trim($_POST['staff_name']);
        $fname     = trim($_POST['staff_father_name'] ?? '');
        $phone     = trim($_POST['staff_phone'] ?? '');
        $cnic      = trim($_POST['staff_cnic'] ?? '');
        $salary    = !empty($_POST['staff_salary']) ? $_POST['staff_salary'] : 0;
        $j_date    = !empty($_POST['staff_joining_date']) ? $_POST['staff_joining_date'] : date('Y-m-d');
        $status    = $_POST['staff_status'] ?? 'Active';
        $addr      = trim($_POST['staff_address'] ?? '');
        $staff_type = trim($_POST['staff_type'] ?? 'دیگر');
        $resp      = trim($_POST['staff_responsibilities'] ?? '');

        if (!empty($_POST['staff_edit_id'])) {
            $sql = "UPDATE maktab_teachers SET name=?, father_name=?, phone=?, cnic=?, salary=?, joining_date=?, status=?, address=?, staff_type=?, responsibilities=? WHERE id=?";
            $pdo->prepare($sql)->execute([$name, $fname, $phone, $cnic, $salary, $j_date, $status, $addr, $staff_type, $resp, $_POST['staff_edit_id']]);
            $msg = "<div class='alert alert-success fw-bold'>✅ عملہ کا ریکارڈ اپڈیٹ ہو گیا!</div>";
        } else {
            $sql = "INSERT INTO maktab_teachers (name, father_name, phone, cnic, salary, joining_date, status, address, staff_type, responsibilities) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $fname, $phone, $cnic, $salary, $j_date, $status, $addr, $staff_type, $resp]);
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا عملہ شامل کر لیا گیا!</div>";
        }
        // ویب پیج ریلوڈ: tab=staff پر رہیں
        echo "<script>window.location='maktab_teachers.php?tab=staff';</script>";
        exit;
    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger'>❌ ایرر: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ─── ڈیلیٹ ───
if (isset($_GET['delete'])) {
    $pdo->prepare("UPDATE maktab_teachers SET is_deleted = 1 WHERE id = ?")->execute([$_GET['delete']]);
    $redirect = isset($_GET['from_staff']) ? 'maktab_teachers.php?tab=staff' : 'maktab_teachers.php';
    echo "<script>window.location='$redirect';</script>"; exit;
}

// ─── فہرستیں ───
$maktab_teachers = $pdo->query("SELECT * FROM maktab_teachers WHERE (system_type = 'Masjid' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL) AND (staff_type = 'Teacher' OR staff_type IS NULL) ORDER BY status ASC, name ASC")->fetchAll();
$staff_list = $pdo->query("SELECT * FROM maktab_teachers WHERE (system_type = 'Masjid' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL) AND staff_type != 'Teacher' AND staff_type IS NOT NULL ORDER BY staff_type ASC, name ASC")->fetchAll();

// ─── ایڈٹ موڈ (استاد) ───
$edit_data = null;
$assigned_maktab_classes = [];
$assigned_periods = [];
$edit_staff = null;

$active_tab = $_GET['tab'] ?? 'teacher';

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM maktab_teachers WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
    if ($edit_data && ($edit_data['staff_type'] ?? 'Teacher') !== 'Teacher') {
        $edit_staff = $edit_data;
        $edit_data = null;
        $active_tab = 'staff';
    } else {
        $active_tab = 'teacher';
        if ($edit_data) {
            $tid = $edit_data['id'];
            try {
                $ac = $pdo->query("SELECT id, main_teacher_ids FROM maktab_classes")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ac as $c) {
                    $tids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                    if (in_array((int)$tid, $tids, true) || in_array((string)$tid, $tids, true)) {
                        $assigned_maktab_classes[$c['id']] = true;
                    }
                }
            } catch (PDOException $e) {}
            try {
                $ap = $pdo->query("SELECT class_id, period_no, teacher_ids FROM class_periods")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ap as $p) {
                    $tids = json_decode($p['teacher_ids'] ?? '[]', true) ?: [];
                    if (in_array((int)$tid, $tids, true) || in_array((string)$tid, $tids, true)) {
                        $assigned_periods[$p['class_id']][$p['period_no']] = true;
                    }
                }
            } catch (PDOException $e) {}
        }
    }
}

try {
    $maktab_classes_list = $pdo->query("SELECT * FROM maktab_classes ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $maktab_classes_list = [];
}
?>

<div class="container-fluid">
    <div class="row mb-3 align-items-center">
        <div class="col-md-6">
            <h3 class="text-primary fw-bold">
                <i class="fas fa-users me-2"></i> اساتذہ و عملہ ریکارڈ
            </h3>
        </div>
    </div>

    <?php echo $msg; ?>

    <!-- ═══════════════ TABS ═══════════════ -->
    <ul class="nav nav-tabs border-0 mb-0" id="staffTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold <?php echo $active_tab == 'teacher' ? 'active' : ''; ?>"
                id="tab-teacher-btn" data-bs-toggle="tab" data-bs-target="#tab-teacher" type="button">
                📖 اساتذہ
                <span class="badge bg-success ms-1"><?php echo count($maktab_teachers); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold <?php echo $active_tab == 'staff' ? 'active' : ''; ?>"
                id="tab-staff-btn" data-bs-toggle="tab" data-bs-target="#tab-staff" type="button">
                👷 دیگر عملہ
                <span class="badge bg-warning text-dark ms-1"><?php echo count($staff_list); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white shadow-sm">

        <!-- ═══════ TAB 1: اساتذہ ═══════ -->
        <div class="tab-pane fade <?php echo $active_tab == 'teacher' ? 'show active' : ''; ?>" id="tab-teacher">
            
            <!-- استاد فارم -->
            <div class="card border-top border-5 border-success mb-4">
                <div class="card-header bg-white">
                    <h5 class="m-0 fw-bold text-success">
                        <i class="fas fa-chalkboard-teacher me-2"></i>
                        <?php echo $edit_data ? 'استاد کا ریکارڈ تبدیل کریں' : 'نیا استاد شامل کریں'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="edit_id" value="<?php echo $edit_data['id'] ?? ''; ?>">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="fw-bold">نام استاد <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_data['name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">ولدیت</label>
                                <input type="text" name="father_name" class="form-control" value="<?php echo htmlspecialchars($edit_data['father_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">موبائل نمبر</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit_data['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">شناختی کارڈ (CNIC)</label>
                                <input type="text" name="cnic" class="form-control" value="<?php echo htmlspecialchars($edit_data['cnic'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">تعلیم (Qualification)</label>
                                <input type="text" name="qualification" class="form-control" value="<?php echo htmlspecialchars($edit_data['qualification'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold text-success">بنیادی تنخواہ</label>
                                <input type="number" name="salary" class="form-control border-success" value="<?php echo $edit_data['salary'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">ہاؤس رینٹ</label>
                                <input type="number" name="house_rent" class="form-control" value="<?php echo $edit_data['house_rent'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">میڈیکل الاؤنس</label>
                                <input type="number" name="medical_allowance" class="form-control" value="<?php echo $edit_data['medical_allowance'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">سفری الاؤنس</label>
                                <input type="number" name="travel_allowance" class="form-control" value="<?php echo $edit_data['travel_allowance'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold text-danger">فی دن کٹوتی</label>
                                <input type="number" name="daily_deduction_rate" class="form-control border-danger" value="<?php echo $edit_data['daily_deduction_rate'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">تقرری کی تاریخ</label>
                                <input type="date" name="joining_date" class="form-control" value="<?php echo $edit_data['joining_date'] ?? date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">اسٹیٹس</label>
                                <select name="status" class="form-select">
                                    <option value="Active" <?php echo ($edit_data['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active (موجود)</option>
                                    <option value="Inactive" <?php echo ($edit_data['status'] ?? '') == 'Inactive' ? 'selected' : ''; ?>>Inactive (فارغ)</option>
                                </select>
                            </div>

                            <!-- ذمہ داریاں -->
                            <div class="col-12">
                                <label class="fw-bold text-primary">
                                    <i class="fas fa-tasks me-1"></i> ذمہ داریاں / فرائض منصبی
                                </label>
                                <textarea name="responsibilities" class="form-control border-primary" rows="3"
                                    placeholder="مثلاً: درس نظامی کلاس، صبح کی شفٹ نگران، جمعہ بیان، مدرسہ انتظامیہ..."
                                ><?php echo htmlspecialchars($edit_data['responsibilities'] ?? ''); ?></textarea>
                                <small class="text-muted">استاد کے فرائض منصبی تفصیل سے لکھیں — یہ ریکارڈ کا حصہ رہیں گے</small>
                            </div>

                            <!-- کلاس / پیریڈ الاٹمنٹ -->
                            <div class="col-12">
                                <label class="fw-bold mb-2"><i class="fas fa-school me-1"></i> کلاس اور پیریڈز کی تقرری</label>
                                <div class="accordion" id="accordionMaktab Classes">
                                    <?php foreach ($maktab_classes_list as $c):
                                        $cid = $c['id'];
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#collapse_<?= $cid ?>">
                                                <?= htmlspecialchars($c['name']) ?>
                                                <?= !empty($c['section']) ? ' - ' . htmlspecialchars($c['section']) : '' ?>
                                                <span class="badge bg-secondary ms-2"><?= htmlspecialchars($c['class_type'] ?? '') ?></span>
                                            </button>
                                        </h2>
                                        <div id="collapse_<?= $cid ?>" class="accordion-collapse collapse">
                                            <div class="accordion-body py-2">
                                                <div class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input"
                                                        name="assign_class[<?= $cid ?>]"
                                                        id="class_<?= $cid ?>"
                                                        <?= isset($assigned_maktab_classes[$cid]) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-bold" for="class_<?= $cid ?>">اس کلاس میں پڑھائیں گے</label>
                                                </div>
                                                <div class="d-flex flex-wrap gap-3">
                                                    <?php foreach (teacher_period_labels($c) as $p_key => $p_label): ?>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input"
                                                            name="assign_period[<?= $cid ?>][<?= htmlspecialchars($p_key) ?>]"
                                                            id="period_<?= $cid ?>_<?= htmlspecialchars($p_key) ?>"
                                                            <?= isset($assigned_periods[$cid][(string)teacher_period_no($p_key)]) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="period_<?= $cid ?>_<?= htmlspecialchars($p_key) ?>"><?= htmlspecialchars($p_label) ?></label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="fw-bold">مکمل پتہ</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($edit_data['address'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-12 text-end">
                                <?php if ($edit_data): ?>
                                    <a href="maktab_teachers.php" class="btn btn-secondary me-2">کینسل</a>
                                <?php endif; ?>
                                <button type="submit" name="save_teacher" class="btn btn-success fw-bold px-4">
                                    <i class="fas fa-save me-2"></i> محفوظ کریں
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- اساتذہ کی فہرست -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-success"><i class="fas fa-list me-2"></i> اساتذہ کی فہرست</h5>
                    <span class="badge bg-success"><?php echo count($maktab_teachers); ?> اساتذہ</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle text-center m-0">
                            <thead class="table-success">
                                <tr>
                                    <th>#</th>
                                    <th>نام</th>
                                    <th>موبائل</th>
                                    <th>تعلیم</th>
                                    <th>وظیفہ</th>
                                    <th>ذمہ داریاں</th>
                                    <th>سٹیٹس</th>
                                    <th>عمل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($maktab_teachers) > 0): ?>
                                    <?php foreach ($maktab_teachers as $i => $t): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td class="text-start ps-3 fw-bold">
                                            <?php echo htmlspecialchars($t['name']); ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($t['father_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($t['qualification']); ?></td>
                                        <td><?php echo number_format((float)($t['salary'] ?? 0)); ?></td>
                                        <td class="text-start small" style="max-width:200px;">
                                            <?php 
                                            $resp = $t['responsibilities'] ?? '';
                                            if ($resp) {
                                                echo '<span class="text-primary">' . nl2br(htmlspecialchars($resp)) . '</span>';
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $t['status'] == 'Active' ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo $t['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="maktab_teachers.php?edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                            <a href="maktab_teachers.php?delete=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('کیا آپ واقعی حذف کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-muted p-4">کوئی استاد موجود نہیں</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- /TAB 1 -->

        <!-- ═══════ TAB 2: دیگر عملہ ═══════ -->
        <div class="tab-pane fade <?php echo $active_tab == 'staff' ? 'show active' : ''; ?>" id="tab-staff">

            <!-- عملہ فارم -->
            <div class="card border-top border-5 border-warning mb-4">
                <div class="card-header bg-white">
                    <h5 class="m-0 fw-bold text-warning">
                        <i class="fas fa-hard-hat me-2"></i>
                        <?php echo $edit_staff ? 'عملہ کا ریکارڈ تبدیل کریں' : 'نیا عملہ شامل کریں'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="staff_edit_id" value="<?php echo $edit_staff['id'] ?? ''; ?>">
                        <div class="row g-3">
                            <!-- عہدہ / قسم -->
                            <div class="col-md-3">
                                <label class="fw-bold text-warning">عہدہ / قسم <span class="text-danger">*</span></label>
                                <select name="staff_type" class="form-select border-warning">
                                    <?php foreach ($staff_types as $type => $icon): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>"
                                        <?php echo ($edit_staff['staff_type'] ?? '') == $type ? 'selected' : ''; ?>>
                                        <?php echo $icon . '  ' . htmlspecialchars($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">نام <span class="text-danger">*</span></label>
                                <input type="text" name="staff_name" class="form-control" required
                                    value="<?php echo htmlspecialchars($edit_staff['name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">ولدیت</label>
                                <input type="text" name="staff_father_name" class="form-control"
                                    value="<?php echo htmlspecialchars($edit_staff['father_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">موبائل نمبر</label>
                                <input type="text" name="staff_phone" class="form-control"
                                    value="<?php echo htmlspecialchars($edit_staff['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">شناختی کارڈ (CNIC)</label>
                                <input type="text" name="staff_cnic" class="form-control"
                                    value="<?php echo htmlspecialchars($edit_staff['cnic'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold text-success">تنخواہ (ماہانہ)</label>
                                <input type="number" name="staff_salary" class="form-control"
                                    value="<?php echo $edit_staff['salary'] ?? ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">تقرری کی تاریخ</label>
                                <input type="date" name="staff_joining_date" class="form-control"
                                    value="<?php echo $edit_staff['joining_date'] ?? date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="fw-bold">اسٹیٹس</label>
                                <select name="staff_status" class="form-select">
                                    <option value="Active" <?php echo ($edit_staff['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active (موجود)</option>
                                    <option value="Inactive" <?php echo ($edit_staff['status'] ?? '') == 'Inactive' ? 'selected' : ''; ?>>Inactive (فارغ)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="fw-bold">مکمل پتہ</label>
                                <input type="text" name="staff_address" class="form-control"
                                    value="<?php echo htmlspecialchars($edit_staff['address'] ?? ''); ?>">
                            </div>

                            <!-- ذمہ داریاں — اہم ترین فیلڈ -->
                            <div class="col-12">
                                <label class="fw-bold text-danger">
                                    <i class="fas fa-tasks me-1"></i>
                                    ذمہ داریاں / فرائض منصبی ⭐
                                </label>
                                <textarea name="staff_responsibilities" class="form-control border-danger" rows="4"
                                    placeholder="مثلاً:&#10;• صبح و شام کا کھانا پکانا&#10;• برتن صاف کرنا&#10;• طلباء کے لیے ناشتہ تیار کرنا&#10;• ہفتہ وار مینو بنانا"
                                ><?php echo htmlspecialchars($edit_staff['responsibilities'] ?? ''); ?></textarea>
                                <small class="text-danger fw-bold">
                                    <i class="fas fa-info-circle me-1"></i>
                                    تمام ذمہ داریاں تفصیل سے لکھیں — یہ ریکارڈ کا مستقل حصہ ہوں گی
                                </small>
                            </div>

                            <div class="col-12 text-end">
                                <?php if ($edit_staff): ?>
                                    <a href="maktab_teachers.php?tab=staff" class="btn btn-secondary me-2">کینسل</a>
                                <?php endif; ?>
                                <button type="submit" name="save_staff" class="btn btn-warning fw-bold px-4 text-dark">
                                    <i class="fas fa-save me-2"></i> محفوظ کریں
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- عملہ کی فہرست -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-warning"><i class="fas fa-hard-hat me-2"></i> عملہ کی فہرست</h5>
                    <span class="badge bg-warning text-dark"><?php echo count($staff_list); ?> افراد</span>
                </div>
                <div class="card-body p-0">
                    <?php if (count($staff_list) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle text-center m-0">
                            <thead class="table-warning">
                                <tr>
                                    <th>#</th>
                                    <th>عہدہ</th>
                                    <th>نام</th>
                                    <th>موبائل</th>
                                    <th>تنخواہ</th>
                                    <th>ذمہ داریاں</th>
                                    <th>تقرری</th>
                                    <th>سٹیٹس</th>
                                    <th>عمل</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staff_list as $i => $s): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <span class="badge fs-6 bg-warning text-dark">
                                            <?php $icon = $staff_types[$s['staff_type']] ?? '👤'; echo $icon; ?> 
                                            <?php echo htmlspecialchars($s['staff_type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-start ps-3 fw-bold">
                                        <?php echo htmlspecialchars($s['name']); ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($s['father_name']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($s['phone']); ?></td>
                                    <td><?php echo number_format((float)($s['salary'] ?? 0)); ?></td>
                                    <td class="text-start small" style="max-width:220px;">
                                        <?php 
                                        $resp = $s['responsibilities'] ?? '';
                                        if ($resp) {
                                            echo '<span class="text-danger">' . nl2br(htmlspecialchars($resp)) . '</span>';
                                        } else {
                                            echo '<span class="text-muted badge bg-danger-subtle text-danger">ذمہ داریاں درج نہیں!</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($s['joining_date'] ?? ''); ?></small></td>
                                    <td>
                                        <span class="badge <?php echo $s['status'] == 'Active' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $s['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="maktab_teachers.php?edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                        <a href="maktab_teachers.php?delete=<?php echo $s['id']; ?>&from_staff=1" class="btn btn-sm btn-danger" onclick="return confirm('کیا آپ واقعی حذف کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center p-5 text-muted">
                        <div style="font-size: 4rem;">👷</div>
                        <h5 class="mt-3">کوئی عملہ موجود نہیں</h5>
                        <p>اوپر فارم سے باورچی، خادم، کیشیئر وغیرہ کا ریکارڈ شامل کریں</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- /TAB 2 -->

    </div><!-- /tab-content -->
</div>

<script>
// URL میں tab پیرامیٹر ہو تو اس tab کو فعال کریں
(function(){
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab === 'staff') {
        const staffTab = document.getElementById('tab-staff-btn');
        if (staffTab) { 
            staffTab.click(); 
        }
    }
})();
</script>

<?php include 'footer.php'; ?>
