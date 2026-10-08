<?php 
require_once 'db.php'; 
include 'header.php'; 
$cur_tenant_id = (int)get_current_tenant_id(); 

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
        $status = !empty($_POST['status']) ? $_POST['status'] : 'Active';
        
        $teacher_id = null;

        // اگر Edit ID ہے تو اپڈیٹ کریں
        if (!empty($_POST['edit_id'])) {
            $teacher_id = $_POST['edit_id'];
            $sql = "UPDATE teachers SET name=?, father_name=?, phone=?, cnic=?, qualification=?, address=?, salary=?, house_rent=?, medical_allowance=?, travel_allowance=?, daily_deduction_rate=?, joining_date=?, status=? WHERE id=? AND tenant_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $teacher_id, $cur_tenant_id]);
            $msg = "<div class='alert alert-success fw-bold'>✅ ریکارڈ کامیابی سے اپڈیٹ ہو گیا!</div>";
        } 
        // ورنہ نیا ریکارڈ ڈالیں
        else {
            $sys = $_SESSION['system_type'] ?? 'Madrasa';
            $today_date = date('Y-m-d');
            $sql = "INSERT INTO teachers (name, father_name, phone, cnic, qualification, address, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, joining_date, status, status_change_date, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $fname, $phone, $cnic, $qual, $addr, $salary, $house_rent, $medical_allowance, $travel_allowance, $daily_deduction_rate, $j_date, $status, $today_date, $sys, $cur_tenant_id]);
            $teacher_id = $pdo->lastInsertId();
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا استاد شامل کر لیا گیا!</div>";
        }

        if ($teacher_id) {
            try {
            // Step 3a: Remove from classes
            $all_classes_db = $pdo->query("SELECT id, main_teacher_ids FROM classes WHERE tenant_id = $cur_tenant_id")->fetchAll(PDO::FETCH_ASSOC);
            $update_class_stmt = $pdo->prepare("UPDATE classes SET main_teacher_ids = ? WHERE id = ? AND tenant_id = $cur_tenant_id");
            foreach($all_classes_db as $cls) {
                $tids = json_decode($cls['main_teacher_ids'], true) ?: [];
                if (in_array((int)$teacher_id, $tids, true) || in_array((string)$teacher_id, $tids, true)) {
                    $tids = array_values(array_filter($tids, function($id) use ($teacher_id) { return (int)$id !== (int)$teacher_id; }));
                    $update_class_stmt->execute([json_encode($tids), $cls['id']]);
                }
            }

            // Step 3a: Remove from class_periods
            $all_cps_db = $pdo->query("SELECT id, teacher_ids FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM classes WHERE tenant_id = $cur_tenant_id))")->fetchAll(PDO::FETCH_ASSOC);
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

// Ensure status_reason and status_change_date columns exist
try { $pdo->exec("ALTER TABLE teachers ADD COLUMN status_reason TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE teachers ADD COLUMN status_change_date VARCHAR(20) NULL"); } catch (PDOException $e) {}

// --- ایکشن: اسٹیٹس تبدیلی (Leave / Terminate / Active) ---
if (isset($_POST['change_teacher_status'])) {
    try {
        $t_id = (int)$_POST['teacher_id'];
        $new_status = $_POST['new_status'] ?? 'Active';
        $status_reason = trim($_POST['status_reason'] ?? '');
        $status_change_date = !empty($_POST['status_change_date']) ? $_POST['status_change_date'] : date('Y-m-d');
        $stmt = $pdo->prepare("UPDATE teachers SET status = ?, status_reason = ?, status_change_date = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_status, $status_reason, $status_change_date, $t_id, $cur_tenant_id]);
        $msg = "<div class='alert alert-success'>✅ استاد کا اسٹیٹس کامیابی سے تبدیل ہو گیا!</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>اسٹیٹس تبدیل کرنے میں خرابی: " . $e->getMessage() . "</div>";
    }
}

// --- ایکشن: ڈیلیٹ ---
if (isset($_GET['delete'])) {
    try {
        $pdo->prepare("UPDATE teachers SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$_GET['delete'], $cur_tenant_id]);
        echo "<script>window.location='teachers.php';</script>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>اس ریکارڈ کو ڈیلیٹ نہیں کیا جا سکتا۔</div>";
    }
}

// --- ڈیٹا لائیں ---
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$status_filter = $_GET['status_filter'] ?? 'all';
$filter_sql = "";
if ($status_filter === 'active') {
    $filter_sql = " AND (status = 'Active' OR status = 'حاضر' OR status = 'فعال' OR status IS NULL OR status = '')";
} elseif ($status_filter === 'on_leave') {
    $filter_sql = " AND (status = 'On Leave' OR status = 'رخصت پر')";
} elseif ($status_filter === 'resigned') {
    $filter_sql = " AND (status = 'Resigned' OR status LIKE '%مستعفی%' OR status LIKE '%علیحدہ%')";
} elseif ($status_filter === 'terminated') {
    $filter_sql = " AND (status = 'Terminated' OR status LIKE '%برطرف%' OR status LIKE '%فارغ%')";
} elseif ($status_filter === 'inactive') {
    $filter_sql = " AND (status = 'Inactive' OR status LIKE '%غیر فعال%')";
}

$teachers = $pdo->query("SELECT * FROM teachers WHERE $sys_cond AND tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL) $filter_sql ORDER BY 
    CASE 
        WHEN status IS NULL OR status = '' OR status = 'Active' OR status = 'حاضر' OR status = 'فعال' THEN 0
        WHEN status = 'On Leave' OR status = 'رخصت پر' THEN 1
        ELSE 2
    END ASC, name ASC")->fetchAll();

// --- ایڈٹ موڈ ---
$edit_data = null;
$assigned_classes = [];
$assigned_periods = [];

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$_GET['edit'], $cur_tenant_id]);
    $edit_data = $stmt->fetch();
    
    if ($edit_data) {
        $tid = $edit_data['id'];
        try {
            // Fetch Hifz class assignments
            $ac = $pdo->query("SELECT id, main_teacher_ids FROM classes WHERE tenant_id = $cur_tenant_id")->fetchAll(PDO::FETCH_ASSOC);
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
            $ap = $pdo->query("SELECT class_id, period_no, teacher_ids FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM classes WHERE tenant_id = $cur_tenant_id))")->fetchAll(PDO::FETCH_ASSOC);
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
    $c_sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    $classes_list = $pdo->query("SELECT * FROM classes WHERE $c_sys_cond AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
        <div class="col-md-6">
            <h3 class="text-primary fw-bold"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo t('teachers'); ?> ریکارڈ</h3>
        </div>
        <div class="col-md-6 text-md-end">
            <button type="button" class="btn btn-primary fw-bold px-3 py-2 shadow-sm" data-bs-toggle="collapse" data-bs-target="#teacherFormCollapse" aria-expanded="<?php echo $edit_data ? 'true' : 'false'; ?>">
                <i class="fas fa-user-plus me-1"></i> نیا استاذ ایڈ کریں
            </button>
        </div>
    </div>
    
    <?php echo $msg; ?>

    <div class="collapse <?php echo $edit_data ? 'show' : ''; ?>" id="teacherFormCollapse">
        <div class="card shadow-sm mb-4 border-top border-5 border-primary">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold text-primary"><?php echo $edit_data ? 'ریکارڈ تبدیل کریں' : 'نیا استاد شامل کریں'; ?></h5>
                <?php if(!$edit_data): ?>
                    <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#teacherFormCollapse" aria-label="Close"></button>
                <?php endif; ?>
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
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($edit_data['status'] ?? 'Active'); ?>">
                    
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
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-pills mb-3" id="teacherStatusTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'all' ? 'active bg-primary' : 'text-dark bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=all">تمام اساتذہ</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'active' ? 'active bg-success' : 'text-success bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=active">فعال / حاضر</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'on_leave' ? 'active bg-warning text-dark' : 'text-warning text-dark bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=on_leave">رخصت پر</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'resigned' ? 'active bg-secondary text-white' : 'text-secondary bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=resigned">مستعفی / علیحدہ شدہ</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'terminated' ? 'active bg-danger' : 'text-danger bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=terminated">برطرف / فارغ</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $status_filter === 'inactive' ? 'active bg-dark text-white' : 'text-dark bg-white border'; ?> fw-bold me-2 shadow-sm" href="teachers.php?status_filter=inactive">غیر فعال</a>
        </li>
    </ul>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-list me-2"></i> اساتذہ کی فہرست</h5>
            <span class="badge bg-primary"><?php echo count($teachers); ?> اساتذہ</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center m-0" style="width: 100%;">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th style="white-space: nowrap;">نام استاد</th>
                            <th style="white-space: nowrap;">موبائل / رابطہ</th>
                            <th style="white-space: nowrap;">تعلیم</th>
                            <th style="min-width: 160px; max-width: 280px;">تقرری</th>
                            <th style="white-space: nowrap;">وظیفہ</th>
                            <th style="white-space: nowrap;">اسٹیٹس</th>
                            <th style="white-space: nowrap;"><?php echo t('action_col'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $teacher_assignments = []; // teacher_id => ['hifz' => [class names], 'darse' => [class_name => [periods]]]
                        $class_names = [];
                        try {
                            $all_classes_data = $pdo->query("SELECT id, name, section, class_type, main_teacher_ids FROM classes WHERE $sys_cond AND tenant_id = $cur_tenant_id")->fetchAll(PDO::FETCH_ASSOC);
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
                            $all_cp_data = $pdo->query("SELECT class_id, period_no, teacher_ids FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM classes WHERE $sys_cond AND tenant_id = $cur_tenant_id))")->fetchAll(PDO::FETCH_ASSOC);
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
                            <?php 
                            $separator_shown = false;
                            foreach($teachers as $index => $t): 
                                $t_status = $t['status'] ?? '';
                                $t_is_active = ($t_status === '' || $t_status === 'Active' || $t_status === 'حاضر' || $t_status === 'فعال' || $t_status === 'On Leave' || $t_status === 'رخصت پر');
                                if (!$t_is_active && !$separator_shown && $status_filter === 'all'):
                                    $separator_shown = true;
                            ?>
                            <tr class="table-warning">
                                <td colspan="8" class="text-center fw-bold text-secondary py-2" style="border-top: 3px dashed #adb5bd;">
                                    <i class="fas fa-user-times me-2"></i> فارغ / مستعفی / برطرف اساتذہ (سابقہ عملہ)
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr <?php echo !$t_is_active ? 'class="table-secondary opacity-75"' : ''; ?>>
                                <td><?php echo $index + 1; ?></td>
                                <td class="text-start ps-3 fw-bold" style="white-space: nowrap;">
                                    <?php echo $t['name']; ?>
                                    <br><small class="text-muted"><?php echo $t['father_name']; ?></small>
                                </td>
                                <td style="white-space: nowrap;"><?php echo $t['phone']; ?></td>
                                <td style="white-space: nowrap;"><?php echo $t['qualification']; ?></td>
                                <td style="max-width: 280px;">
                                    <div class="d-flex flex-wrap justify-content-center gap-1">
                                    <?php 
                                        $tid = $t['id'];
                                        $has_assignment = false;
                                        
                                        if (!empty($teacher_assignments[$tid]['hifz'])) {
                                            $has_assignment = true;
                                            foreach ($teacher_assignments[$tid]['hifz'] as $hc) {
                                                echo "<span class='badge bg-success text-white'>$hc (Hifz)</span>";
                                            }
                                        }
                                        
                                        if (!empty($teacher_assignments[$tid]['darse'])) {
                                            $has_assignment = true;
                                            foreach ($teacher_assignments[$tid]['darse'] as $dc => $periods) {
                                                sort($periods);
                                                $p_str = implode(",", $periods);
                                                echo "<span class='badge bg-info text-dark'>$dc [P: $p_str]</span>";
                                            }
                                        }

                                        if (!$has_assignment && !empty($t['assigned_periods'])) {
                                            $decoded_p = json_decode($t['assigned_periods'], true);
                                            if (is_array($decoded_p) && !empty($decoded_p)) {
                                                foreach ($decoded_p as $dc => $periods) {
                                                    $p_arr = (array)$periods;
                                                    sort($p_arr);
                                                    $p_str = implode(",", $p_arr);
                                                    if (!empty($dc)) {
                                                        $has_assignment = true;
                                                        echo "<span class='badge bg-info text-dark'>" . htmlspecialchars($dc) . (!empty($p_str) ? " [P: $p_str]" : "") . "</span>";
                                                    }
                                                }
                                            } else {
                                                $period_summary = teacher_format_period_summary($t['assigned_periods']);
                                                if (!empty($period_summary)) {
                                                    $has_assignment = true;
                                                    echo "<span class='badge bg-info text-dark text-wrap'>P: " . htmlspecialchars($period_summary) . "</span>";
                                                }
                                            }
                                        }

                                        if (!$has_assignment && !empty($t['assigned_classes'])) {
                                            $classes_arr = explode(',', $t['assigned_classes']);
                                            foreach ($classes_arr as $ca) {
                                                $ca = trim($ca);
                                                if ($ca !== '') {
                                                    $has_assignment = true;
                                                    echo "<span class='badge bg-success text-white'>" . htmlspecialchars($ca) . "</span>";
                                                }
                                            }
                                        }
                                        
                                        if (!$has_assignment) {
                                            echo "<span class='text-muted'>-</span>";
                                        }
                                    ?>
                                    </div>
                                </td>
                                <td><?php echo number_format((float)($t['salary'] ?? 0)); ?></td>
                                <td>
                                    <?php 
                                    $st = $t['status'] ?? 'Active';
                                    if($st === 'Active' || $st === 'حاضر' || $st === 'فعال'): ?>
                                        <span class="badge bg-success">فعال / حاضر</span>
                                    <?php elseif($st === 'On Leave' || $st === 'رخصت پر'): ?>
                                        <span class="badge bg-warning text-dark">رخصت پر</span>
                                    <?php elseif($st === 'Resigned' || strpos($st, 'مستعفی') !== false || strpos($st, 'علیحدہ') !== false): ?>
                                        <span class="badge bg-secondary">مستعفی / علیحدہ شدہ</span>
                                    <?php elseif($st === 'Terminated' || strpos($st, 'برطرف') !== false || strpos($st, 'فارغ') !== false): ?>
                                        <span class="badge bg-danger">برطرف / فارغ</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark">غیر فعال (<?php echo htmlspecialchars($st); ?>)</span>
                                    <?php endif; ?>
                                    <?php if(!empty($t['status_change_date'])): ?>
                                        <br><span class="badge bg-light text-primary border border-primary mt-1" style="font-size:11px;" title="تاریخ تبدیلی اسٹیٹس"><i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars($t['status_change_date']); ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($t['status_reason'])): ?>
                                        <br><small class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($t['status_reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-warning text-dark me-1" title="اسٹیٹس تبدیل کریں" onclick="openStatusModal(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>', '<?php echo htmlspecialchars(addslashes($st)); ?>', '<?php echo htmlspecialchars(addslashes($t['status_reason'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($t['status_change_date'] ?? '')); ?>')">
                                        <i class="fas fa-user-clock"></i>
                                    </button>
                                    <a href="teachers.php?edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary" title="ترمیم"><i class="fas fa-edit"></i></a>
                                    <a href="teachers.php?delete=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" title="حذف" onclick="return confirm('کیا آپ واقعی ڈیلیٹ کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
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

<!-- Modal: Change Teacher Status -->
<div class="modal fade" id="teacherStatusModal" tabindex="-1" aria-labelledby="teacherStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content text-end" dir="rtl">
            <form method="POST" action="teachers.php">
                <div class="modal-header bg-primary text-white justify-content-between">
                    <h5 class="modal-title fw-bold" id="teacherStatusModalLabel"><i class="fas fa-user-clock me-2"></i> استاد کا اسٹیٹس تبدیل کریں</h5>
                    <button type="button" class="btn-close btn-close-white ms-0" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-start">
                    <input type="hidden" name="teacher_id" id="modal_teacher_id">
                    <div class="mb-3 text-end">
                        <label class="fw-bold text-muted">نام استاد:</label>
                        <h5 id="modal_teacher_name" class="fw-bold text-primary"></h5>
                    </div>
                    <div class="mb-3 text-end">
                        <label class="fw-bold mb-1">نیا اسٹیٹس منتخب کریں:</label>
                        <select name="new_status" id="modal_new_status" class="form-select fw-bold">
                            <option value="Active">فعال / حاضر (Active)</option>
                            <option value="On Leave">رخصت پر (On Leave)</option>
                            <option value="Resigned">مستعفی / علیحدہ شدہ (Resigned / Left)</option>
                            <option value="Terminated">برطرف / فارغ (Terminated)</option>
                            <option value="Inactive">غیر فعال (Inactive)</option>
                        </select>
                    </div>
                    <div class="mb-3 text-end">
                        <label class="fw-bold mb-1">تاریخ تبدیلی اسٹیٹس (Date):</label>
                        <input type="date" name="status_change_date" id="modal_status_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3 text-end">
                        <label class="fw-bold mb-1">تفصیل / وجہ / رخصت کا دورانیہ:</label>
                        <textarea name="status_reason" id="modal_status_reason" class="form-control" rows="3" placeholder="مثلاً: ایک ماہ کی رخصت، یا استعفیٰ وغیرہ"></textarea>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                    <button type="submit" name="change_teacher_status" class="btn btn-success fw-bold px-4"><i class="fas fa-check me-2"></i> محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openStatusModal(id, name, status, reason, statusDate) {
    document.getElementById('modal_teacher_id').value = id;
    document.getElementById('modal_teacher_name').innerText = name;
    document.getElementById('modal_new_status').value = status || 'Active';
    document.getElementById('modal_status_reason').value = reason || '';
    document.getElementById('modal_status_date').value = (statusDate && statusDate !== 'null') ? statusDate : new Date().toISOString().slice(0, 10);
    var modal = new bootstrap.Modal(document.getElementById('teacherStatusModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>

