<?php 
// ایرر رپورٹنگ (تاکہ اگر کوئی مسئلہ ہو تو نظر آئے)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php'; 
include 'header.php'; 

// سیکیورٹی چیک
if (!isset($_SESSION['user_id'])) { echo "<script>window.location='login.php';</script>"; exit; }

$msg = "";

// Ensure class_periods table for teacher assignment compatibility.
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
try { $pdo->exec("UPDATE class_periods SET period_no = period_number WHERE period_no IS NULL AND period_number IS NOT NULL"); } catch (PDOException $e) {}




function teacher_u($unicode) {
    return json_decode('"' . $unicode . '"');
}

function teacher_class_kind($class) {
    $text = trim(($class['name'] ?? '') . ' ' . ($class['class_type'] ?? '') . ' ' . ($class['section'] ?? ''));
    $lower = strtolower($text);
    if (strpos($text, teacher_u('\u0646\u0627\u0638\u0631')) !== false || strpos($lower, 'nazira') !== false) return 'nazira';
    if (strpos($text, teacher_u('\u062d\u0641\u0638')) !== false || strpos($lower, 'hifz') !== false) return 'hifz';
    return 'regular';
}

function teacher_period_labels($class) {
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    if ($sys === 'Masjid') {
        return [
            '1' => teacher_u('\u0635\u0628\u062d \u06a9\u0627 \u0633\u06cc\u0634\u0646 (Morning Shift)'),
            '2' => teacher_u('\u062f\u0648\u067e\u06c1\u0631 \u06a9\u0627 \u0633\u06cc\u0634\u0646 (Noon Shift)'),
            '3' => teacher_u('\u0639\u0635\u0631 / \u0634\u0627\u0645 \u06a9\u0627 \u0633\u06cc\u0634\u0646 (Evening Shift)'),
            '4' => teacher_u('\u0645\u063a\u0631\u0628 / \u0639\u0634\u0627\u0621 \u06a9\u0627 \u0633\u06cc\u0634\u0646 (Night Shift)')
        ];
    }
    $kind = teacher_class_kind($class);
    if ($kind === 'hifz') {
        return [
            'hifz_morning' => teacher_u('\u0635\u0628\u062d'),
            'hifz_after_breakfast' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
            'hifz_noon' => teacher_u('\u062f\u0648\u067e\u06c1\u0631 (\u0638\u06c1\u0631 \u06a9\u06d2 \u0628\u0639\u062f)'),
            'hifz_evening' => teacher_u('\u0634\u0627\u0645 (\u0645\u063a\u0631\u0628 \u06a9\u06d2 \u0628\u0639\u062f)')
        ];
    }
    if ($kind === 'nazira') {
        return [
            'nazira_morning' => teacher_u('\u0635\u0628\u062d'),
            'nazira_evening' => teacher_u('\u0634\u0627\u0645')
        ];
    }
    return [
        '1' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 1 (\u0635\u0628\u062d)'),
        '2' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 2'),
        '3' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 3'),
        '4' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 4'),
        '5' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 5'),
        '6' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 6'),
        '7' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 7 (\u0645\u0637\u0627\u0644\u0639\u06c1)'),
        '8' => teacher_u('\u067e\u06cc\u0631\u06cc\u0688 8 (\u062a\u06a9\u0631\u0627\u0631)')
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
    $kind = teacher_class_kind($class);
    $period_no = (int)$period_no;
    if ($kind === 'hifz') {
        return [1 => 'hifz_morning', 2 => 'hifz_after_breakfast', 3 => 'hifz_noon', 4 => 'hifz_evening'][$period_no] ?? (string)$period_no;
    }
    if ($kind === 'nazira') {
        return [1 => 'nazira_morning', 2 => 'nazira_evening'][$period_no] ?? (string)$period_no;
    }
    return (string)$period_no;
}

function teacher_period_label_by_key($key) {
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    if ($sys === 'Masjid') {
        $masjid_labels = [
            '1' => teacher_u('\u0635\u0628\u062d \u06a9\u0627 \u0633\u06cc\u0634\u0646'),
            '2' => teacher_u('\u062f\u0648\u067e\u06c1\u0631 \u06a9\u0627 \u0633\u06cc\u0634\u0646'),
            '3' => teacher_u('\u0639\u0635\u0631 / \u0634\u0627\u0645 \u06a9\u0627 \u0633\u06cc\u0634\u0646'),
            '4' => teacher_u('\u0645\u063a\u0631\u0628 / \u0639\u0634\u0627\u0621 \u06a9\u0627 \u0633\u06cc\u0634\u0646')
        ];
        if (isset($masjid_labels[(string)$key])) return $masjid_labels[(string)$key];
    }
    $labels = [
        'hifz_morning' => teacher_u('\u0635\u0628\u062d'),
        'hifz_after_breakfast' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
        'hifz_noon' => teacher_u('\u062f\u0648\u067e\u06c1\u0631 (\u0638\u06c1\u0631 \u06a9\u06d2 \u0628\u0639\u062f)'),
        'hifz_evening' => teacher_u('\u0634\u0627\u0645 (\u0645\u063a\u0631\u0628 \u06a9\u06d2 \u0628\u0639\u062f)'),
        'hifz_sabaq' => teacher_u('\u0635\u0628\u062d'),
        'hifz_sabqi' => teacher_u('\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'),
        'hifz_manzil' => teacher_u('\u062f\u0648\u067e\u06c1\u0631 (\u0638\u06c1\u0631 \u06a9\u06d2 \u0628\u0639\u062f)'),
        'hifz_review' => teacher_u('\u0634\u0627\u0645 (\u0645\u063a\u0631\u0628 \u06a9\u06d2 \u0628\u0639\u062f)'),
        'nazira_morning' => teacher_u('\u0635\u0628\u062d'),
        'nazira_evening' => teacher_u('\u0634\u0627\u0645')
    ];
    if (isset($labels[$key])) return $labels[$key];
    if (ctype_digit((string)$key)) return teacher_u('\u067e\u06cc\u0631\u06cc\u0688 ') . $key;
    return (string)$key;
}

function teacher_format_period_summary($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $parts = [];
        foreach ($decoded as $class_name => $periods) {
            $labels = [];
            foreach ((array)$periods as $period_key) {
                $labels[] = teacher_period_label_by_key((string)$period_key);
            }
            if (!empty($labels)) {
                $parts[] = $class_name . ': ' . implode(', ', $labels);
            }
        }
        return implode(' | ', $parts);
    }
    $labels = [];
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $period_key) {
        $labels[] = teacher_period_label_by_key($period_key);
    }
    return implode(', ', $labels);
}

// --- ایکشن: ڈیٹا محفوظ کریں ---
if (isset($_POST['save_teacher'])) {
    try {
        $pdo->beginTransaction();

        // فارم سے ڈیٹا حاصل کریں
        $name = $_POST['name'];
        $fname = $_POST['father_name'];
        $phone = $_POST['phone'];
        $cnic = $_POST['cnic'];
        $qual = $_POST['qualification'];
        $addr = $_POST['address'];
        $salary = !empty($_POST['salary']) ? $_POST['salary'] : 0;
        $house_rent = !empty($_POST['house_rent']) ? $_POST['house_rent'] : 0;
        $medical_allowance = !empty($_POST['medical_allowance']) ? $_POST['medical_allowance'] : 0;
        $travel_allowance = !empty($_POST['travel_allowance']) ? $_POST['travel_allowance'] : 0;
        $daily_deduction_rate = !empty($_POST['daily_deduction_rate']) ? $_POST['daily_deduction_rate'] : 0;
        $j_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : date('Y-m-d');
        $status = $_POST['status'];
        
        $teacher_id = null;

        // اگر Edit ID ہے تو اپڈیٹ کریں
        if (!empty($_POST['edit_id'])) {
            $teacher_id = $_POST['edit_id'];
            $sql = "UPDATE teachers SET name=?, father_name=?, phone=?, cnic=?, qualification=?, address=?, salary=?, house_rent=?, medical_allowance=?, travel_allowance=?, daily_deduction_rate=?, joining_date=?, status=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $teacher_id]);
            $msg = "<div class='alert alert-success fw-bold'>✅ ریکارڈ کامیابی سے اپڈیٹ ہو گیا!</div>";
        } 
        // ورنہ نیا ریکارڈ ڈالیں
        else {
            $sys = $_SESSION['system_type'] ?? 'Madrasa';
            $sql = "INSERT INTO teachers (name, father_name, phone, cnic, qualification, address, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, joining_date, status, system_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $sys]);
            $teacher_id = $pdo->lastInsertId();
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا استاد شامل کر لیا گیا!</div>";
        }

        if ($teacher_id) {
            try {
            // Step 3a: Remove from classes
            $all_classes_db = $pdo->query("SELECT id, main_teacher_ids FROM classes")->fetchAll(PDO::FETCH_ASSOC);
            $update_class_stmt = $pdo->prepare("UPDATE classes SET main_teacher_ids = ? WHERE id = ?");
            foreach($all_classes_db as $cls) {
                $tids = json_decode($cls['main_teacher_ids'], true) ?: [];
                if (in_array((int)$teacher_id, $tids, true) || in_array((string)$teacher_id, $tids, true)) {
                    $tids = array_values(array_filter($tids, function($id) use ($teacher_id) { return (int)$id !== (int)$teacher_id; }));
                    $update_class_stmt->execute([json_encode($tids), $cls['id']]);
                }
            }

            // Step 3a: Remove from class_periods
            $all_cps_db = $pdo->query("SELECT id, teacher_ids FROM class_periods")->fetchAll(PDO::FETCH_ASSOC);
            $update_cp_stmt = $pdo->prepare("UPDATE class_periods SET teacher_ids = ? WHERE id = ?");
            foreach($all_cps_db as $cp) {
                $tids = json_decode($cp['teacher_ids'], true) ?: [];
                if (in_array((int)$teacher_id, $tids, true) || in_array((string)$teacher_id, $tids, true)) {
                    $tids = array_values(array_filter($tids, function($id) use ($teacher_id) { return (int)$id !== (int)$teacher_id; }));
                    $update_cp_stmt->execute([json_encode($tids), $cp['id']]);
                }
            }

            // Step 3b: Loop through $_POST['assign_class']
            if (!empty($_POST['assign_class']) && is_array($_POST['assign_class'])) {
                foreach ($_POST['assign_class'] as $c_id => $val) {
                    $cls = $pdo->query("SELECT main_teacher_ids FROM classes WHERE id = " . (int)$c_id)->fetch(PDO::FETCH_ASSOC);
                    if ($cls) {
                        $tids = json_decode($cls['main_teacher_ids'], true) ?: [];
                        if (!in_array((int)$teacher_id, $tids) && !in_array((string)$teacher_id, $tids)) {
                            $tids[] = (int)$teacher_id;
                            $update_class_stmt->execute([json_encode($tids), $c_id]);
                        }
                    }
                }
            }

            // Step 3c: Loop through $_POST['assign_period'][$class_id]
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
                            $tids = [(int)$teacher_id];
                            $insert_cp = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, teacher_ids) VALUES (?, ?, ?)");
                            $insert_cp->execute([$c_id, $period_no, json_encode($tids)]);
                        }
                    }
                }
            }

            $assigned_classes_summary = [];
            $class_ids = [];
            if (!empty($_POST['assign_class']) && is_array($_POST['assign_class'])) {
                $class_ids = array_merge($class_ids, array_keys($_POST['assign_class']));
            }
            if (!empty($_POST['assign_period']) && is_array($_POST['assign_period'])) {
                $class_ids = array_merge($class_ids, array_keys($_POST['assign_period']));
            }
            $class_ids = array_values(array_unique(array_filter($class_ids)));
            if (!empty($class_ids)) {
                $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                $class_stmt = $pdo->prepare("SELECT name FROM classes WHERE id IN ($placeholders) ORDER BY name ASC");
                $class_stmt->execute($class_ids);
                $assigned_classes_summary = array_column($class_stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
            }

            $assigned_periods_summary = [];
            if (!empty($_POST['assign_period']) && is_array($_POST['assign_period'])) {
                $class_names_by_id = [];
                if (!empty($class_ids)) {
                    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                    $class_stmt = $pdo->prepare("SELECT id, name FROM classes WHERE id IN ($placeholders)");
                    $class_stmt->execute($class_ids);
                    foreach ($class_stmt->fetchAll(PDO::FETCH_ASSOC) as $class_row) {
                        $class_names_by_id[(string)$class_row['id']] = $class_row['name'];
                    }
                }
                foreach ($_POST['assign_period'] as $c_id => $periods) {
                    $class_name = $class_names_by_id[(string)$c_id] ?? (string)$c_id;
                    $assigned_periods_summary[$class_name] = array_values(array_keys((array)$periods));
                }
            }

            $summary_stmt = $pdo->prepare("UPDATE teachers SET assigned_classes = ?, assigned_periods = ? WHERE id = ?");
            $summary_stmt->execute([
                implode(',', $assigned_classes_summary),
                json_encode($assigned_periods_summary, JSON_UNESCAPED_UNICODE),
                $teacher_id
            ]);
            } catch (PDOException $e) {
                // Profile data has already been saved; class/period assignment remains optional.
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = "<div class='alert alert-danger'>❌ ڈیٹا بیس ایرر: " . $e->getMessage() . "</div>";
    }
}

// --- ایکشن: ڈیلیٹ ---
if (isset($_GET['delete'])) {
    try {
        $pdo->prepare("UPDATE teachers SET is_deleted = 1 WHERE id = ?")->execute([$_GET['delete']]);
        echo "<script>window.location='teachers.php';</script>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>اس ریکارڈ کو ڈیلیٹ نہیں کیا جا سکتا۔</div>";
    }
}

// --- ڈیٹا لائیں ---
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$teachers = $pdo->query("SELECT * FROM teachers WHERE system_type = '$sys' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY status ASC, name ASC")->fetchAll();

// --- ایڈٹ موڈ ---
$edit_data = null;
$assigned_classes = [];
$assigned_periods = [];

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
    
    if ($edit_data) {
        $tid = $edit_data['id'];
        try {
            // Fetch Hifz class assignments
            $ac = $pdo->query("SELECT id, main_teacher_ids FROM classes")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ac as $c) {
                $tids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                if (in_array((int)$tid, $tids, true) || in_array((string)$tid, $tids, true)) {
                    $assigned_classes[$c['id']] = true;
                }
            }
        } catch (PDOException $e) {
            $assigned_classes = [];
        }
        try {
            // Fetch Darse Nizami periods assignments
            $ap = $pdo->query("SELECT class_id, period_no, teacher_ids FROM class_periods")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ap as $p) {
                $tids = json_decode($p['teacher_ids'] ?? '[]', true) ?: [];
                if (in_array((int)$tid, $tids, true) || in_array((string)$tid, $tids, true)) {
                    $assigned_periods[$p['class_id']][$p['period_no']] = true;
                }
            }
        } catch (PDOException $e) {
            $assigned_periods = [];
        }
    }
}

try {
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    $classes_list = $pdo->query("SELECT * FROM classes WHERE (system_type = '$sys' OR system_type IS NULL OR system_type = '') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $classes_list = [];
}

// Normalize teacher period assignments after classes are loaded.
if ($edit_data && !empty($classes_list)) {
    $classes_by_id = [];
    $class_id_by_name = [];
    foreach ($classes_list as $class_row) {
        $classes_by_id[(string)$class_row['id']] = $class_row;
        $class_id_by_name[$class_row['name']] = (string)$class_row['id'];
    }

    foreach ($assigned_periods as $class_id => $period_map) {
        if (!isset($classes_by_id[(string)$class_id])) continue;
        foreach (array_keys((array)$period_map) as $period_no) {
            $period_key = teacher_period_key_for_class($classes_by_id[(string)$class_id], $period_no);
            $assigned_periods[$class_id][$period_key] = true;
        }
    }

    $stored_periods = json_decode($edit_data['assigned_periods'] ?? '', true);
    if (is_array($stored_periods)) {
        foreach ($stored_periods as $class_name => $periods) {
            $class_id = $class_id_by_name[$class_name] ?? null;
            if ($class_id === null) continue;
            $assigned_classes[$class_id] = true;
            foreach ((array)$periods as $period_key) {
                $assigned_periods[$class_id][(string)$period_key] = true;
            }
        }
    }
}
?>

<div class="container-fluid">
    <div class="row mb-3 align-items-center">
        <div class="col-md-6"><h3 class="text-primary fw-bold"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo t('teachers'); ?> ریکارڈ</h3></div>
    </div>
    
    <?php echo $msg; ?>

    <div class="card shadow-sm mb-4 border-top border-5 border-primary">
        <div class="card-header bg-white">
            <h5 class="m-0 fw-bold text-primary"><?php echo $edit_data ? 'ریکارڈ تبدیل کریں' : 'نیا استاد شامل کریں'; ?></h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="edit_id" value="<?php echo $edit_data['id'] ?? ''; ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold">نام استاد <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?php echo $edit_data['name'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold"><?php echo t('father_name'); ?></label>
                        <input type="text" name="father_name" class="form-control" value="<?php echo $edit_data['father_name'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">موبائل نمبر</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo $edit_data['phone'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">شناختی کارڈ (CNIC)</label>
                        <input type="text" name="cnic" class="form-control" value="<?php echo $edit_data['cnic'] ?? ''; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="fw-bold">تعلیم (Qualification)</label>
                        <input type="text" name="qualification" class="form-control" value="<?php echo $edit_data['qualification'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-success">بنیادی تنخواہ (Basic Salary)</label>
                        <input type="number" name="salary" class="form-control border-success" value="<?php echo $edit_data['salary'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-primary">ہاؤس رینٹ (House Rent)</label>
                        <input type="number" name="house_rent" class="form-control" value="<?php echo $edit_data['house_rent'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-primary">میڈیکل الاؤنس</label>
                        <input type="number" name="medical_allowance" class="form-control" value="<?php echo $edit_data['medical_allowance'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-primary">سفری الاؤنس (Travel)</label>
                        <input type="number" name="travel_allowance" class="form-control" value="<?php echo $edit_data['travel_allowance'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-danger" title="یہ رقم فی غیر حاضری کاٹی جائے گی">فی دن کٹوتی (Fine/Day)</label>
                        <input type="number" name="daily_deduction_rate" class="form-control border-danger" value="<?php echo $edit_data['daily_deduction_rate'] ?? ''; ?>" placeholder="e.g. 500">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">تقرری کی تاریخ</label>
                        <input type="date" name="joining_date" class="form-control" value="<?php echo $edit_data['joining_date'] ?? date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">اسٹیٹس</label>
                        <select name="status" class="form-select">
                            <option value="Active" <?php echo ($edit_data['status']??'')=='Active'?'selected':''; ?>>Active (موجود)</option>
                            <option value="Inactive" <?php echo ($edit_data['status']??'')=='Inactive'?'selected':''; ?>>Inactive (فارغ)</option>
                        </select>
                    </div>
                    
                    <div class="col-12 mt-3">
                        <label class="fw-bold mb-2"><?php echo ($sys === 'Masjid') ? 'مکتب کلاس اور سیشنز کی تقرری (Maktab Quranic Shifts Assignment)' : 'کلاس اور پیریڈز کی تقرری'; ?></label>
                        <div class="accordion" id="accordionClasses">
                            <?php foreach($classes_list as $c): 
                                $is_hifz = (($c['class_type'] ?? '') === 'Hifz/Nazira');
                                $cid = $c['id'];
                            ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="heading_<?= $cid ?>">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?= $cid ?>" aria-expanded="false" aria-controls="collapse_<?= $cid ?>">
                                        <?= htmlspecialchars($c['name']) ?> <?= !empty($c['section']) ? ' - ' . htmlspecialchars($c['section']) : '' ?> 
                                        <span class="badge bg-secondary ms-2"><?= htmlspecialchars($c['class_type'] ?? 'Darse Nizami / Other') ?></span>
                                    </button>
                                </h2>
                                <div id="collapse_<?= $cid ?>" class="accordion-collapse collapse" aria-labelledby="heading_<?= $cid ?>" data-bs-parent="#accordionClasses">
                                    <div class="accordion-body">
                                        <?php $periods_labels = teacher_period_labels($c); ?>
                                            <div class="form-check mb-2">
                                                <input type="checkbox" class="form-check-input" name="assign_class[<?= $cid ?>]" id="class_<?= $cid ?>" <?= isset($assigned_classes[$cid]) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-bold" for="class_<?= $cid ?>">&#1575;&#1587; &#1705;&#1604;&#1575;&#1587; &#1605;&#1740;&#1722; &#1662;&#1681;&#1726;&#1575;&#1574;&#1740;&#1722; &#1711;&#1746;</label>
                                            </div>
                                            <div class="d-flex flex-wrap gap-3">
                                                <?php foreach($periods_labels as $p_key => $p_label): ?>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" name="assign_period[<?= $cid ?>][<?= htmlspecialchars($p_key) ?>]" id="period_<?= $cid ?>_<?= htmlspecialchars($p_key) ?>" <?= isset($assigned_periods[$cid][(string)$p_key]) ? 'checked' : '' ?>>
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
                        <textarea name="address" class="form-control" rows="2"><?php echo $edit_data['address'] ?? ''; ?></textarea>
                    </div>
                    
                    <div class="col-12 text-end">
                        <?php if($edit_data): ?>
                            <a href="teachers.php" class="btn btn-secondary me-2">کینسل</a>
                        <?php endif; ?>
                        <button type="submit" name="save_teacher" class="btn btn-success fw-bold px-4">
                            <i class="fas fa-save me-2"></i> محفوظ کریں
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-list me-2"></i> موجودہ اساتذہ کی فہرست</h5>
            <span class="badge bg-primary"><?php echo count($teachers); ?> اساتذہ</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center m-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>نام استاد</th>
                            <th>موبائل / رابطہ</th>
                            <th>تعلیم</th>
                            <th>تقرری</th>
                            <th>وظیفہ</th>
                            <th>اسٹیٹس</th>
                            <th><?php echo t('action_col'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $teacher_assignments = []; // teacher_id => ['hifz' => [class names], 'darse' => [class_name => [periods]]]
                        $class_names = [];
                        try {
                            $all_classes_data = $pdo->query("SELECT id, name, section, class_type, main_teacher_ids FROM classes")->fetchAll(PDO::FETCH_ASSOC);
                            foreach($all_classes_data as $c) {
                                $cname = $c['name'] . (!empty($c['section']) ? ' - '.$c['section'] : '');
                                $class_names[$c['id']] = $cname;
                                $tids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                                foreach($tids as $tid) {
                                    if (($c['class_type'] ?? '') === 'Hifz/Nazira') {
                                        $teacher_assignments[$tid]['hifz'][] = $cname;
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            $class_names = [];
                        }
                        try {
                            $all_cp_data = $pdo->query("SELECT class_id, period_no, teacher_ids FROM class_periods")->fetchAll(PDO::FETCH_ASSOC);
                            foreach($all_cp_data as $cp) {
                                $tids = json_decode($cp['teacher_ids'] ?? '[]', true) ?: [];
                                foreach($tids as $tid) {
                                    if (isset($class_names[$cp['class_id']])) {
                                        $teacher_assignments[$tid]['darse'][$class_names[$cp['class_id']]][] = $cp['period_no'];
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            // Assignment data is optional for the list; teacher records must still render.
                        }
                        
                        if(count($teachers) > 0): ?>
                            <?php foreach($teachers as $index => $t): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td class="text-start ps-3 fw-bold">
                                    <?php echo $t['name']; ?>
                                    <br><small class="text-muted"><?php echo $t['father_name']; ?></small>
                                </td>
                                <td><?php echo $t['phone']; ?></td>
                                <td><?php echo $t['qualification']; ?></td>
                                <td>
                                    <?php 
                                        $tid = $t['id'];
                                        $has_assignment = false;
                                        
                                        if (!empty($teacher_assignments[$tid]['hifz'])) {
                                            $has_assignment = true;
                                            foreach ($teacher_assignments[$tid]['hifz'] as $hc) {
                                                echo "<span class='badge bg-success text-white m-1'>$hc (Hifz)</span>";
                                            }
                                        }
                                        
                                        if (!empty($teacher_assignments[$tid]['darse'])) {
                                            $has_assignment = true;
                                            foreach ($teacher_assignments[$tid]['darse'] as $dc => $periods) {
                                                sort($periods);
                                                $p_str = implode(",", $periods);
                                                echo "<span class='badge bg-info text-dark m-1'>$dc [P: $p_str]</span>";
                                            }
                                        }

                                        if (!$has_assignment && !empty($t['assigned_classes'])) {
                                            $has_assignment = true;
                                            echo "<span class='badge bg-success text-white m-1'>" . htmlspecialchars($t['assigned_classes']) . "</span>";
                                        }
                                        if (!empty($t['assigned_periods'])) {
                                            $has_assignment = true;
                                            $period_summary = teacher_format_period_summary($t['assigned_periods']);
                                            echo "<span class='badge bg-info text-dark m-1'>P: " . htmlspecialchars($period_summary) . "</span>";
                                        }
                                        
                                        if (!$has_assignment) {
                                            echo "-";
                                        }
                                    ?>
                                </td>
                                <td><?php echo number_format((float)($t['salary'] ?? 0)); ?></td>
                                <td>
                                    <?php if($t['status'] == 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="teachers.php?edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                    <a href="teachers.php?delete=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('کیا آپ واقعی ڈیلیٹ کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="p-4 text-danger fw-bold">کوئی استاد موجود نہیں</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

