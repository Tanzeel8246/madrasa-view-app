<?php 
require_once 'db.php'; 
include 'header.php'; 

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$can_class_attendance = app_has_perm('manage_attendance');
$can_teacher_attendance = $can_class_attendance || app_has_perm('manage_teacher_attendance');

if (!in_array($role, ['admin', 'nazim', 'teacher'], true) && !$can_class_attendance) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
$selected_class = $_GET['class'] ?? $_POST['class'] ?? '';
$selected_period = $_GET['period_num'] ?? $_POST['period_num'] ?? '';
$cur_tenant_id = (int)get_current_tenant_id();

function normalize_teacher_attendance_status($status) {
    $clean = trim((string)$status);
    if ($clean === 'حاضر' || strtoupper($clean) === 'P') return 'P';
    if ($clean === 'غیر حاضر' || strtoupper($clean) === 'A') return 'A';
    if ($clean === 'رخصت' || $clean === 'رخصت اتفاقی' || strtoupper($clean) === 'L') return 'L';
    if ($clean === 'بکار مدرسہ' || strtoupper($clean) === 'OD') return 'P';
    return $clean === '' ? 'P' : $clean;
}

function sync_teacher_daily_summary(PDO $pdo, $teacherId, $date, $tenantId = 1) {
    $stmt = $pdo->prepare("SELECT status, late_minutes FROM teacher_period_attendance WHERE teacher_id = ? AND date = ? AND tenant_id = ?");
    $stmt->execute([$teacherId, $date, $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    $status = 'A';
    $late = 0;
    foreach ($rows as $r) {
        $cur = normalize_teacher_attendance_status($r['status'] ?? 'P');
        if ($cur === 'P') $status = 'P';
        elseif ($status !== 'P' && $cur === 'L') $status = 'L';
        $late = max($late, (int)($r['late_minutes'] ?? 0));
    }
    $check = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? AND tenant_id = ? LIMIT 1");
    $check->execute([$teacherId, $date, $tenantId]);
    $id = $check->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE attendance_teachers SET status = ?, late_minutes = ?, remarks = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$status, $late, (string)$late, $id, $tenantId]);
    } else {
        $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$teacherId, $date, $status, $late, (string)$late, $tenantId]);
    }
}

function att_id_list($value) {
    if (is_array($value)) {
        $items = $value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') $out[] = $item;
    }
    return array_values(array_unique($out));
}

function att_normalize_period_key($key) {
    $key = trim((string)$key);
    $legacy = [
        'hifz_sabaq' => 'hifz_morning',
        'hifz_sabqi' => 'hifz_after_breakfast',
        'hifz_manzil' => 'hifz_noon',
        'hifz_review' => 'hifz_evening',
    ];
    return $legacy[$key] ?? $key;
}

function att_period_no_from_key($key) {
    $key = att_normalize_period_key($key);
    $map = [
        'hifz_morning' => 1,
        'hifz_after_breakfast' => 2,
        'hifz_noon' => 3,
        'hifz_evening' => 4,
        'nazira_morning' => 1,
        'nazira_evening' => 2,
    ];
    return isset($map[$key]) ? $map[$key] : (int)$key;
}

function att_class_text_has($classRow, $needles) {
    $lower = function($value) {
        $value = (string)$value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $text = $lower(trim(
        (string)($classRow['name'] ?? '') . ' ' .
        (string)($classRow['class_type'] ?? '') . ' ' .
        (string)($classRow['section'] ?? '')
    ));
    foreach ($needles as $needle) {
        $needleText = $lower($needle);
        $found = function_exists('mb_strpos') ? mb_strpos($text, $needleText, 0, 'UTF-8') : strpos($text, $needleText);
        if ($needle !== '' && $found !== false) {
            return true;
        }
    }
    return false;
}

function att_is_hifz_class($classRow) {
    return att_class_text_has($classRow, ['حفظ', 'گردان', 'حسن', 'Ø­ÙØ¸', 'hifz', 'gardan', 'husn']);
}

function att_is_nazira_class($classRow) {
    return att_class_text_has($classRow, ['ناظرہ', 'Ù†Ø§Ø¸Ø±Û', 'nazira']);
}

function att_assigned_period_numbers($assignedPeriods, $className) {
    $raw = trim((string)$assignedPeriods);
    if ($raw === '') return [];

    $values = [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (array_key_exists($className, $decoded)) {
            $values = is_array($decoded[$className])
                ? $decoded[$className]
                : explode(',', (string)$decoded[$className]);
        }
    } else {
        $values = explode(',', $raw);
    }

    $periods = [];
    foreach ($values as $value) {
        $periodNo = att_period_no_from_key($value);
        if ($periodNo > 0) $periods[] = $periodNo;
    }
    return array_values(array_unique($periods));
}

function att_teacher_ids_for_class_period(PDO $pdo, $class_id, $selected_period) {
    $selected_period = (int)$selected_period;
    if (!$class_id || !$selected_period) return [];

    $stmt = $pdo->prepare("SELECT id, name, class_type, section, main_teacher_ids FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $classRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$classRow) return [];

    $className = $classRow['name'] ?? '';
    $mainTeacherIds = att_id_list($classRow['main_teacher_ids'] ?? '');

    $stmt = $pdo->prepare("SELECT period_no, teacher_ids, teacher_id FROM class_periods WHERE class_id = ?");
    $stmt->execute([$class_id]);
    $periodRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $explicitPeriodMap = [];
    foreach ($periodRows as $row) {
        $periodNo = (int)($row['period_no'] ?? 0);
        $teacherIds = [];
        if (!empty($row['teacher_ids'])) $teacherIds = array_merge($teacherIds, att_id_list($row['teacher_ids']));
        if (!empty($row['teacher_id'])) $teacherIds[] = (string)$row['teacher_id'];
        foreach (array_unique(array_filter($teacherIds)) as $tid) {
            $explicitPeriodMap[(string)$tid][] = $periodNo;
        }
    }

    $profileClassMap = [];
    $profilePeriodMap = [];
    $teachersStmt = $pdo->query("SELECT id, assigned_classes, assigned_periods FROM teachers WHERE (status = 'Active' OR status = 'حاضر' OR status = 'فعال' OR status = '' OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)");
    foreach ($teachersStmt->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
        $tid = (string)$teacher['id'];
        $assignedClasses = att_id_list($teacher['assigned_classes'] ?? '');
        $periods = att_assigned_period_numbers($teacher['assigned_periods'] ?? '', $className);
        if (!empty($periods)) $profilePeriodMap[$tid] = $periods;
        if ($className !== '' && in_array($className, $assignedClasses, true)) {
            $profileClassMap[$tid] = true;
        }
    }

    $candidates = array_values(array_unique(array_merge(
        $mainTeacherIds,
        array_keys($explicitPeriodMap),
        array_keys($profileClassMap)
    )));

    $result = [];
    foreach ($candidates as $tid) {
        $tid = (string)$tid;
        if (isset($profilePeriodMap[$tid])) {
            if (in_array($selected_period, $profilePeriodMap[$tid], true)) $result[] = $tid;
            continue;
        }
        if (isset($explicitPeriodMap[$tid])) {
            if (in_array($selected_period, array_map('intval', $explicitPeriodMap[$tid]), true)) $result[] = $tid;
            continue;
        }
        if (isset($profileClassMap[$tid]) || in_array($tid, $mainTeacherIds, true)) {
            $result[] = $tid;
        }
    }

    return array_values(array_unique(array_filter($result)));
}

// Get allowed classes based on role
if ($role === 'teacher' && !$can_class_attendance) {
    $all_classes = get_allowed_classes($pdo, 'name ASC');
    
    $classes = [];
    foreach ($all_classes as $c) {
        $c_t_ids = [];
        if (!empty($c['main_teacher_ids'])) $c_t_ids = array_merge($c_t_ids, att_id_list($c['main_teacher_ids']));
        if (isset($c['main_teacher_id']) && !empty($c['main_teacher_id'])) $c_t_ids[] = $c['main_teacher_id'];
        if (isset($c['teacher_id']) && !empty($c['teacher_id'])) $c_t_ids[] = $c['teacher_id'];
        $c_t_ids = array_values(array_unique(array_filter($c_t_ids)));
        
        $is_teacher_in_class = in_array($user_id, $c_t_ids) || in_array((string)$user_id, $c_t_ids);
        
        if (!$is_teacher_in_class) {
            $stmt_cp = $pdo->prepare("SELECT teacher_id, teacher_ids FROM class_periods WHERE class_id = ?");
            $stmt_cp->execute([$c['id']]);
            $cps = $stmt_cp->fetchAll();
            foreach ($cps as $cp) {
                $cp_t_ids = [];
                if (!empty($cp['teacher_ids'])) $cp_t_ids = array_merge($cp_t_ids, att_id_list($cp['teacher_ids']));
                if (!empty($cp['teacher_id'])) $cp_t_ids[] = $cp['teacher_id'];
                
                if (in_array($user_id, $cp_t_ids) || in_array((string)$user_id, $cp_t_ids)) {
                    $is_teacher_in_class = true;
                    break;
                }
            }
        }
        
        if ($is_teacher_in_class) {
            $classes[] = $c;
        }
    }
} else {
    $classes = get_allowed_classes($pdo, 'name ASC');
}

// Ensure selected_class is valid for teacher
if ($role === 'teacher' && !$can_class_attendance && $selected_class) {
    $valid_class = false;
    foreach ($classes as $c) {
        if ($c['name'] === $selected_class) { $valid_class = true; break; }
    }
    if (!$valid_class && !empty($classes)) {
        $selected_class = $classes[0]['name'];
    }
}

// Find class_id and class_name
$class_id = null;
$class_name = '';
foreach ($classes as $c) {
    if ($c['name'] === $selected_class) {
        $class_id = $c['id'];
        $class_name = $c['name'];
        break;
    }
}

// Get allowed periods for the selected class
$allowed_periods = [];
if ($class_id) {
    if (strpos($class_name, 'حفظ') !== false || strpos($class_name, 'گردان') !== false || strpos($class_name, 'حسن صوت') !== false) {
        $base_periods = [1 => 'صبح', 2 => 'ناشتہ کے بعد', 3 => 'دوپہر', 4 => 'شام'];
    } elseif (strpos($class_name, 'ناظرہ') !== false) {
        $base_periods = [1 => 'صبح', 2 => 'شام'];
    } else {
        $base_periods = [1 => 'سبق 1', 2 => 'سبق 2', 3 => 'سبق 3', 4 => 'سبق 4', 5 => 'سبق 5', 6 => 'سبق 6', 7 => 'مطالعہ', 8 => 'تکرار'];
    }

    $selectedClassRow = null;
    foreach ($classes as $c) {
        if ((int)$c['id'] === (int)$class_id) {
            $selectedClassRow = $c;
            break;
        }
    }
    if (att_is_hifz_class($selectedClassRow ?? ['name' => $class_name])) {
        $base_periods = [1 => 'صبح', 2 => 'ناشتہ کے بعد', 3 => 'دوپہر', 4 => 'شام'];
    } elseif (att_is_nazira_class($selectedClassRow ?? ['name' => $class_name])) {
        $base_periods = [1 => 'صبح', 2 => 'شام'];
    }

    if ($role === 'teacher' && !$can_class_attendance) {
        $stmt = $pdo->prepare("SELECT period_no, teacher_id, teacher_ids FROM class_periods WHERE class_id = ? ORDER BY period_no ASC");
        $stmt->execute([$class_id]);
        $all_periods = $stmt->fetchAll();
        $assigned = [];
        foreach ($all_periods as $p) {
            $t_ids = [];
            if (!empty($p['teacher_ids'])) {
                $t_ids = att_id_list($p['teacher_ids']);
            } elseif (!empty($p['teacher_id'])) {
                $t_ids = [$p['teacher_id']];
            }
            if (in_array($user_id, $t_ids) || in_array((string)$user_id, $t_ids)) {
                $assigned[] = $p['period_no'];
            }
        }
        foreach ($assigned as $p) {
            if (isset($base_periods[$p])) {
                $allowed_periods[$p] = $base_periods[$p];
            }
        }
    } else {
        $allowed_periods = $base_periods;
    }
}

// Validate selected period
if ($selected_period && !array_key_exists($selected_period, $allowed_periods)) {
    $selected_period = !empty($allowed_periods) ? array_key_first($allowed_periods) : '';
}

// --- محفوظ کرنے کا عمل ---
if (isset($_POST['save_attendance'])) {
    $date = $_POST['date'];
    $selected_period = $_POST['period_num'];
    $selected_class = $_POST['class'];
    
    $s_data = $_POST['status'] ?? []; 
    $r_data = $_POST['remarks'] ?? []; 
    
    if (!empty($selected_class) && !empty($selected_period)) {
        
        // Save teacher attendance
        if ($can_teacher_attendance && isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids']) && $class_id) {
            $allowed_teacher_ids = att_teacher_ids_for_class_period($pdo, $class_id, $selected_period);
            foreach ($_POST['teacher_ids'] as $t_id) {
                if (!in_array((string)$t_id, $allowed_teacher_ids, true)) continue;
                $t_status = normalize_teacher_attendance_status($_POST['teacher_status'][$t_id] ?? 'P');
                $t_late_mins = $_POST['teacher_late_mins'][$t_id] ?? 0;
                $t_remarks = $_POST['teacher_remarks'][$t_id] ?? '';
                
                $exist_t = $pdo->prepare("SELECT id FROM teacher_period_attendance WHERE teacher_id=? AND class_id=? AND date=? AND period_no=? AND tenant_id=?");
                $exist_t->execute([$t_id, $class_id, $date, $selected_period, $cur_tenant_id]);
                $row_t = $exist_t->fetch();
                
                if ($row_t) {
                    $sql = "UPDATE teacher_period_attendance SET status=?, late_minutes=?, remarks=? WHERE id=? AND tenant_id=?";
                    $pdo->prepare($sql)->execute([$t_status, $t_late_mins, $t_remarks, $row_t['id'], $cur_tenant_id]);
                } else {
                    $sql = "INSERT INTO teacher_period_attendance (teacher_id, class_id, date, period_no, status, late_minutes, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$t_id, $class_id, $date, $selected_period, $t_status, $t_late_mins, $t_remarks, $cur_tenant_id]);
                }
                sync_teacher_daily_summary($pdo, $t_id, $date, $cur_tenant_id);
            }
        }

        if (!empty($s_data)) {
            $students_ids = array_keys($s_data); 

            foreach ($students_ids as $std_id) {
                $st = $s_data[$std_id] ?? ''; 
                $rem = $r_data[$std_id] ?? ''; 
                
                if ($st === '') continue; // Skip if not marked

                $exist = $pdo->prepare("SELECT id FROM period_attendance WHERE student_id=? AND date=? AND period_num=? AND tenant_id=?");
                $exist->execute([$std_id, $date, $selected_period, $cur_tenant_id]);
                $row = $exist->fetch();
                
                if ($row) {
                    $sql = "UPDATE period_attendance SET status=?, remarks=? WHERE id=? AND tenant_id=?";
                    $pdo->prepare($sql)->execute([$st, $rem, $row['id'], $cur_tenant_id]);
                } else {
                    $sql = "INSERT INTO period_attendance (student_id, date, period_num, status, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([$std_id, $date, $selected_period, $st, $rem, $cur_tenant_id]);
                }
            }
        }
        echo "<div class='alert alert-success text-center fw-bold shadow'>✅ حاضری ریکارڈ (پیریڈ {$selected_period}) اپڈیٹ ہو گیا!</div>";
    }
}

// Fetch assigned teachers
$assigned_teachers = [];

if ($can_teacher_attendance && $class_id && $selected_period) {
    $t_ids = att_teacher_ids_for_class_period($pdo, $class_id, $selected_period);
    $t_ids = array_values(array_unique(array_filter($t_ids)));

    if (!empty($t_ids)) {
        $placeholders = implode(',', array_fill(0, count($t_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM teachers WHERE id IN ($placeholders) AND tenant_id = ? AND (status = 'Active' OR status = 'حاضر' OR status = 'فعال' OR status = '' OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute(array_merge($t_ids, [$cur_tenant_id]));
        $teachers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($teachers_data as $t) {
            $t_chk = $pdo->prepare("SELECT status, late_minutes, remarks FROM teacher_period_attendance WHERE teacher_id=? AND class_id=? AND date=? AND period_no=? AND tenant_id=?");
            $t_chk->execute([$t['id'], $class_id, $date, $selected_period, $cur_tenant_id]);
            $t_res = $t_chk->fetch();

            $assigned_teachers[] = [
                'id' => $t['id'],
                'name' => $t['name'],
                'status' => $t_res['status'] ?? 'P',
                'late_minutes' => (int)($t_res['late_minutes'] ?? 0),
                'remarks' => $t_res['remarks'] ?? ''
            ];
        }
    }
}

// Fetch students
$students = [];
if ($selected_class && $selected_period) {
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    $sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    
    $studentCols = [];
    try { $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
    $hasSys = in_array('system_type', $studentCols, true);
    $stdSysCond = $hasSys ? $sys_cond : "1=1";
    
    $classExpr = in_array('class_name', $studentCols, true) 
        ? "(TRIM(class) = ? OR TRIM(class_name) = ?)" 
        : "TRIM(class) = ?";
    $params = in_array('class_name', $studentCols, true) 
        ? [$cur_tenant_id, $date, trim($selected_class), trim($selected_class)] 
        : [$cur_tenant_id, $date, trim($selected_class)];
        
    $query = "SELECT * FROM students WHERE tenant_id = ? AND $stdSysCond AND (LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', '') OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND (admission_date IS NULL OR admission_date = '' OR admission_date <= ?) AND $classExpr ORDER BY admission_number ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fallback if zero found: check without system_type constraint if class is unique
    if (empty($students)) {
        $queryFb = "SELECT * FROM students WHERE tenant_id = ? AND (LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', '') OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND (admission_date IS NULL OR admission_date = '' OR admission_date <= ?) AND $classExpr ORDER BY admission_number ASC";
        $stmtFb = $pdo->prepare($queryFb);
        $stmtFb->execute($params);
        $students = $stmtFb->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="container-fluid py-3">
    
    <div class="sticky-top pt-2" style="z-index: 100;">
        <div class="card mb-3 bg-dark text-white shadow-lg border-0" style="background: linear-gradient(135deg, #284D3D 0%, #1a3329 100%);">
            <div class="card-body text-center py-2">
                <div class="d-flex justify-content-center align-items-center gap-3">
                    <button type="button" id="mic_btn" class="btn btn-light text-success fw-bold rounded-pill px-4 shadow" onclick="toggleVoice()">
                        <i class="fas fa-microphone me-2"></i> <span>بولیں (Start)</span>
                    </button>
                    <div id="status_text" class="text-white small text-start border-start ps-3" style="min-width: 250px;">
                        <span class="opacity-75">مائیک بند ہے۔</span>
                    </div>
                </div>
                <div class="mt-2 small text-warning" style="font-size: 11px;">
                    <b>طریقہ:</b> "رول نمبر 10 <?php echo t('status_present'); ?>" (منتخب پیریڈ میں لگے گا)
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="attendanceForm">
        <div class="card p-3 shadow-sm border-top border-5 border-success mb-3">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <label class="fw-bold">تاریخ منتخب کریں:</label>
                    <input type="date" name="date" id="dateInput" class="form-control fw-bold" value="<?php echo htmlspecialchars($date); ?>" onchange="updateURL()">
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">کلاس منتخب کریں:</label>
                    <select name="class" id="classSelect" class="form-control fw-bold" onchange="updateURL()">
                        <option value="">کلاس منتخب کریں</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['name']); ?>" <?php echo ($selected_class === $c['name']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-bold">پیریڈ منتخب کریں:</label>
                    <select name="period_num" id="periodSelect" class="form-control fw-bold" onchange="updateURL()">
                        <option value="">پیریڈ منتخب کریں</option>
                        <?php foreach($allowed_periods as $p => $label): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($selected_period == $p) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 text-end d-flex align-items-end justify-content-end gap-2 mt-3 mt-md-0">
                    <button type="button" id="wa_btn" class="btn btn-outline-success fw-bold px-3 shadow" <?php echo (!$selected_class || !$selected_period || empty($students)) ? 'disabled' : ''; ?> onclick="openWhatsAppModal()">
                        <i class="fab fa-whatsapp me-1"></i> WhatsApp متن
                    </button>
                    <button type="submit" name="save_attendance" id="save_btn" class="btn btn-success fw-bold px-3 shadow" <?php echo (!$selected_class || !$selected_period) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save me-1"></i> محفوظ کریں
                    </button>
                </div>
            </div>
        </div>

        <?php if ($selected_class && $selected_period): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center m-0">
                        <thead style="background: #284D3D; color: white;">
                            <tr>
                                <th style="width: 80px;">رول نمبر</th>
                                <th class="text-start ps-3">نام</th>
                                <th style="width: 450px;">حاضری اور تاخیر (پیریڈ <?php echo htmlspecialchars($selected_period); ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Teacher Rows -->
                            <?php if (!empty($assigned_teachers)): ?>
                                <?php foreach ($assigned_teachers as $t): ?>
                                <tr class="table-warning border-warning">
                                    <td class="fw-bold h5 m-0 align-middle">
                                        <input type="hidden" name="teacher_ids[]" value="<?php echo htmlspecialchars($t['id']); ?>">
                                        استاذ
                                    </td>
                                    <td class="text-start ps-3 m-0 align-middle">
                                        <span class="fw-bold h6 d-block mb-1 text-primary">
                                            <i class="fas fa-chalkboard-teacher me-2"></i><?php echo htmlspecialchars($t['name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Hidden field for late minutes -->
                                        <input type="hidden" name="teacher_late_mins[<?= $t['id'] ?>]" 
                                               id="t_period_late_val_<?= $t['id'] ?>" 
                                               value="<?= (int)($t['late_minutes'] ?? 0) ?>">
                                        <div class="row g-2 px-2 align-items-center">
                                            <div class="col-5">
                                                <select name="teacher_status[<?= $t['id'] ?>]" 
                                                        class="form-select form-select-sm fw-bold text-center" 
                                                        style="height: 45px;"
                                                        onchange="toggleTeacherPeriodLate(this, <?= $t['id'] ?>)">
                                                    <option value="P" <?= $t['status'] == 'P' ? 'selected' : '' ?>>حاضر</option>
                                                    <option value="L" <?= $t['status'] == 'L' ? 'selected' : '' ?>>رخصت</option>
                                                    <option value="LA" <?= $t['status'] == 'LA' ? 'selected' : '' ?>>رخصت اتفاقی</option>
                                                    <option value="OD" <?= $t['status'] == 'OD' ? 'selected' : '' ?>>بکار مدرسہ</option>
                                                </select>
                                            </div>
                                            <div class="col-7" id="t_period_late_block_<?= $t['id'] ?>" 
                                                 style="display: <?= ($t['status'] == 'P') ? 'flex' : 'none' ?>; gap: 6px; align-items: center;">
                                                <select class="form-select form-select-sm" 
                                                        id="t_period_late_sel_<?= $t['id'] ?>"
                                                        onchange="toggleTeacherPeriodMins(this, <?= $t['id'] ?>)"
                                                        style="height:38px; font-size:13px; flex:1;">
                                                    <option value="0" <?= (int)($t['late_minutes'] ?? 0) == 0 ? 'selected' : '' ?>>بلا تاخیر</option>
                                                    <option value="late" <?= (int)($t['late_minutes'] ?? 0) > 0 ? 'selected' : '' ?>>تاخیر ▼</option>
                                                </select>
                                                <input type="number" 
                                                       id="t_period_late_mins_<?= $t['id'] ?>"
                                                       value="<?= (int)($t['late_minutes'] ?? 0) > 0 ? (int)$t['late_minutes'] : '' ?>" 
                                                       placeholder="منٹ"
                                                       min="1" max="999"
                                                       class="form-control form-control-sm text-center" 
                                                       style="height:38px; font-size:13px; width:70px; display:<?= (int)($t['late_minutes'] ?? 0) > 0 ? 'block' : 'none' ?>;"
                                                       oninput="syncTeacherPeriodLateVal(<?= $t['id'] ?>)">
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <tr class="table-secondary">
                                <td colspan="3" class="fw-bold text-danger py-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>کوئی استاذ متعین نہیں
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                            <!-- Students Loop -->
                            <?php foreach($students as $s): 
                                $chk = $pdo->prepare("SELECT status, remarks FROM period_attendance WHERE student_id=? AND date=? AND period_num=? AND tenant_id=?");
                                $chk->execute([$s['id'], $date, $selected_period, $cur_tenant_id]);
                                $res = $chk->fetch();
                                
                                $val       = $res['status']  ?? 'P'; // Default to Present
                                $late_mins = (int)($res['remarks'] ?? 0); // remarks reused for late minutes
                                
                                $bg_class = 'bg-success text-white';
                                if($val == 'A') $bg_class = 'bg-danger text-white';
                                if($val == 'L') $bg_class = 'bg-warning text-dark';
                                if($val == '') $bg_class = 'bg-light text-dark';
                                if($val == 'P' && $late_mins > 0) $bg_class = 'bg-warning text-dark';
                            ?>
                            <tr id="row_<?php echo htmlspecialchars($selected_period); ?>_<?php echo htmlspecialchars($s['admission_number']); ?>" class="std-row" data-adm="<?php echo htmlspecialchars($s['admission_number']); ?>">
                                <td class="fw-bold bg-light h5 m-0"><?php echo htmlspecialchars($s['admission_number']); ?></td>
                                <td class="text-start ps-3 m-0">
                                    <span class="fw-bold h6 d-block mb-1"><?php echo htmlspecialchars($s['name']); ?></span>
                                    <small class="text-muted" style="font-size: 12px;">ولد: <?php echo htmlspecialchars($s['father_name']); ?></small>
                                </td>
                                <td>
                                    <!-- Hidden field that stores the final late minutes value -->
                                    <input type="hidden" name="remarks[<?= $s['id'] ?>]" 
                                           id="late_val_<?= $s['id'] ?>_<?= htmlspecialchars($selected_period) ?>"
                                           value="<?= $late_mins ?>">
                                    <div class="row g-2 px-2 align-items-center">
                                        <div class="col-5">
                                            <select name="status[<?php echo $s['id']; ?>]" 
                                                    id="sel_<?php echo htmlspecialchars($selected_period); ?>_<?php echo htmlspecialchars($s['admission_number']); ?>" 
                                                    class="form-select fw-bold text-center status-select <?php echo $bg_class; ?>" 
                                                    style="height: 45px; font-size: 16px;" 
                                                    onchange="updateColor(this); toggleStudentLate(this, <?= $s['id'] ?>, '<?= htmlspecialchars($selected_period) ?>')">
                                                <option value="" <?php echo $val==''?'selected':''; ?>>--- (Not Marked) ---</option>
                                                <option value="P" <?php echo $val=='P'?'selected':''; ?>><?php echo t('status_present'); ?> (Present)</option>
                                                <option value="A" <?php echo $val=='A'?'selected':''; ?>>غیر <?php echo t('status_present'); ?> (Absent)</option>
                                                <option value="L" <?php echo $val=='L'?'selected':''; ?>><?php echo t('status_leave'); ?> (Leave)</option>
                                            </select>
                                        </div>
                                        <div class="col-7" id="late_block_<?= $s['id'] ?>_<?= htmlspecialchars($selected_period) ?>" 
                                             style="display: <?= ($val == 'P') ? 'flex' : 'none' ?>; gap: 6px; align-items: center;">
                                            <select class="form-select form-select-sm" 
                                                    id="late_sel_<?= $s['id'] ?>_<?= htmlspecialchars($selected_period) ?>"
                                                    onchange="toggleStudentMins(this, <?= $s['id'] ?>, '<?= htmlspecialchars($selected_period) ?>')"
                                                    style="height:38px; font-size:13px; flex:1;">
                                                <option value="0" <?= $late_mins == 0 ? 'selected' : '' ?>>بلا تاخیر</option>
                                                <option value="late" <?= $late_mins > 0 ? 'selected' : '' ?>>تاخیر ▼</option>
                                            </select>
                                            <input type="number" 
                                                   id="late_mins_<?= $s['id'] ?>_<?= htmlspecialchars($selected_period) ?>"
                                                   value="<?= $late_mins > 0 ? $late_mins : '' ?>" 
                                                   placeholder="منٹ"
                                                   min="1" max="999"
                                                   class="form-control form-control-sm text-center" 
                                                   style="height:38px; font-size:13px; width:70px; display:<?= $late_mins > 0 ? 'block' : 'none' ?>;"
                                                   oninput="syncLateVal(<?= $s['id'] ?>, '<?= htmlspecialchars($selected_period) ?>')">
                                        </div>
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
            <div class="alert alert-info text-center fw-bold mt-4 shadow-sm">
                براہ کرم کلاس اور پیریڈ منتخب کریں تاکہ حاضری لی جا سکے۔
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
function toggleTeacherPeriodLate(selectEl, tid) {
    var block = document.getElementById('t_period_late_block_' + tid);
    var valH = document.getElementById('t_period_late_val_' + tid);
    if (selectEl.value === 'P') {
        if (block) block.style.display = 'flex';
    } else {
        if (block) block.style.display = 'none';
        if (valH) valH.value = '0';
        var sel = document.getElementById('t_period_late_sel_' + tid);
        if (sel) sel.value = '0';
        var mins = document.getElementById('t_period_late_mins_' + tid);
        if (mins) { mins.style.display = 'none'; mins.value = ''; }
    }
}

function toggleTeacherPeriodMins(selectEl, tid) {
    var mins = document.getElementById('t_period_late_mins_' + tid);
    var valH = document.getElementById('t_period_late_val_' + tid);
    if (selectEl.value === 'late') {
        if (mins) { mins.style.display = 'block'; mins.focus(); }
    } else {
        if (mins) { mins.style.display = 'none'; mins.value = ''; }
        if (valH) valH.value = '0';
    }
}

function syncTeacherPeriodLateVal(tid) {
    var mins = document.getElementById('t_period_late_mins_' + tid);
    var valH = document.getElementById('t_period_late_val_' + tid);
    if (mins && valH) {
        valH.value = mins.value || '0';
    }
}

function updateURL() {
    var date = document.getElementById('dateInput').value;
    var cls = document.getElementById('classSelect').value;
    var period = document.getElementById('periodSelect').value;
    window.location.href = 'attendance.php?class=' + encodeURIComponent(cls) + '&date=' + encodeURIComponent(date) + '&period_num=' + encodeURIComponent(period);
}

function updateColor(select) {
    select.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-light', 'text-white', 'text-dark');
    if(select.value === 'P') { select.classList.add('bg-success', 'text-white'); }
    else if(select.value === 'A') { select.classList.add('bg-danger', 'text-white'); }
    else if(select.value === 'L') { select.classList.add('bg-warning', 'text-dark'); }
    else { select.classList.add('bg-light', 'text-dark'); }
}

// تاخیر بلاک — صرف حاضر کی صورت میں ظاہر ہو
function toggleStudentLate(selectEl, sid, period) {
    var lateBlock = document.getElementById('late_block_' + sid + '_' + period);
    var lateValHidden = document.getElementById('late_val_' + sid + '_' + period);
    if (selectEl.value === 'P') {
        if (lateBlock) lateBlock.style.display = 'flex';
    } else {
        if (lateBlock) lateBlock.style.display = 'none';
        // تاخیر صفر کر دیں جب غیر حاضر / رخصت ہو
        if (lateValHidden) lateValHidden.value = '0';
        var lateSel = document.getElementById('late_sel_' + sid + '_' + period);
        if (lateSel) { lateSel.value = '0'; }
        var minsInput = document.getElementById('late_mins_' + sid + '_' + period);
        if (minsInput) { minsInput.style.display = 'none'; minsInput.value = ''; }
    }
}

// تاخیر منٹ انپٹ — صرف تاخیر سلیکٹ کرنے پر ظاہر ہو
function toggleStudentMins(selectEl, sid, period) {
    var minsInput = document.getElementById('late_mins_' + sid + '_' + period);
    var lateValHidden = document.getElementById('late_val_' + sid + '_' + period);
    var statusSel = document.querySelector('select[name="status[' + sid + ']"]');

    if (selectEl.value === 'late') {
        if (minsInput) { minsInput.style.display = 'block'; minsInput.focus(); }
        // رنگ تبدیل کریں — تاخیر = نارنجی
        if (statusSel) {
            statusSel.classList.remove('bg-success', 'text-white');
            statusSel.classList.add('bg-warning', 'text-dark');
        }
    } else {
        if (minsInput) { minsInput.style.display = 'none'; minsInput.value = ''; }
        if (lateValHidden) lateValHidden.value = '0';
        // رنگ واپس سبز
        if (statusSel && statusSel.value === 'P') {
            statusSel.classList.remove('bg-warning', 'text-dark');
            statusSel.classList.add('bg-success', 'text-white');
        }
    }
}

// منٹ تبدیل ہونے پر hidden field کو اپڈیٹ کریں
function syncLateVal(sid, period) {
    var minsInput = document.getElementById('late_mins_' + sid + '_' + period);
    var lateValHidden = document.getElementById('late_val_' + sid + '_' + period);
    if (minsInput && lateValHidden) {
        lateValHidden.value = minsInput.value || '0';
    }
}

var recognition; var isListening = false;
function toggleVoice() { if(isListening) stopRecognition(); else startRecognition(); }

function startRecognition() {
    if (!('webkitSpeechRecognition' in window)) { alert("Use Google Chrome"); return; }
    recognition = new webkitSpeechRecognition();
    recognition.lang = 'ur-PK'; recognition.continuous = true; recognition.interimResults = true;
    
    var btn = document.getElementById('mic_btn');
    btn.innerHTML = '<i class="fas fa-stop"></i> رک جائیں'; btn.className = 'btn btn-danger text-white fw-bold rounded-pill px-4 shadow pulse-btn';
    document.getElementById('status_text').innerHTML = "سن رہا ہوں..."; isListening = true;

    recognition.onresult = function(event) {
        var txt = ""; for (var i = event.resultIndex; i < event.results.length; ++i) txt += event.results[i][0].transcript;
        document.getElementById('status_text').innerHTML = "<b>سنا گیا:</b> " + txt;
        processAttendance(txt);
    };
    recognition.onerror = function() { stopRecognition(); };
    recognition.start();
}

function stopRecognition() { isListening = false; if(recognition) recognition.stop(); 
    var btn = document.getElementById('mic_btn'); btn.innerHTML = 'بولیں (Start)'; btn.className = 'btn btn-light text-success fw-bold rounded-pill px-4 shadow';
    document.getElementById('status_text').innerHTML = "مائیک بند ہے۔";
}

function processAttendance(text) {
    if (text.match(/(?:سیو|محفوظ|اوکے|ڈن)/i)) { stopRecognition(); document.getElementById('save_btn').click(); return; }

    var rollMatch = text.match(/(\d+)/); 
    if(rollMatch) {
        var roll = urduToNum(rollMatch[1]);
        var targetSlot = document.getElementById('periodSelect').value; 
        if(!targetSlot) return; // Period must be selected

        var selectId = "sel_" + targetSlot + "_" + roll;
        var selectEl = document.getElementById(selectId);
        var rowId = "row_" + targetSlot + "_" + roll;

        if(selectEl) {
            var row = document.getElementById(rowId);
            if(row) {
                row.scrollIntoView({behavior: "smooth", block: "center"});
                row.style.border = "4px solid blue"; setTimeout(()=>{ row.style.border = ""; }, 1500);
            }
            if(text.match(/(?:غیر <?php echo t('status_present'); ?>|ایبسنٹ|غائب|نہیں)/)) { selectEl.value = 'A'; }
            else if(text.match(/(?:<?php echo t('status_leave'); ?>|چھٹی|لیو)/)) { selectEl.value = 'L'; }
            else { selectEl.value = 'P'; } 
            updateColor(selectEl);
        }
    }
}

function urduToNum(str) {
    var map = {'ایک':1,'دو':2,'تین':3,'چار':4,'پانچ':5,'چھ':6,'سات':7,'آٹھ':8,'نو':9,'دس':10,'بیس':20,'تیس':30,'سو':100};
    if(str.toString().match(/^\d+$/)) return parseInt(str);
    for(var k in map) if(str.includes(k)) return map[k];
    return str;
}

function openWhatsAppModal() {
    var dateVal = document.getElementById('dateInput').value;
    var classSelect = document.getElementById('classSelect');
    var className = classSelect.options[classSelect.selectedIndex] ? classSelect.options[classSelect.selectedIndex].text : '';
    var periodSelect = document.getElementById('periodSelect');
    var periodName = periodSelect.options[periodSelect.selectedIndex] ? periodSelect.options[periodSelect.selectedIndex].text : '';

    var total = 0, present = 0, absent = 0, leave = 0;
    var absentList = [];
    var leaveList = [];

    var selectElements = document.querySelectorAll('select[name^="period_status"]');
    selectElements.forEach(function(sel) {
        total++;
        var val = sel.value;
        var row = sel.closest('tr');
        var nameEl = row ? row.querySelector('td:nth-child(2) span') : null;
        var studentName = nameEl ? nameEl.innerText.trim() : 'طالب علم';

        if (val === 'P') {
            present++;
        } else if (val === 'A') {
            absent++;
            absentList.push(studentName);
        } else if (val === 'L') {
            leave++;
            leaveList.push(studentName);
        } else {
            present++;
        }
    });

    var msg = "📋 *مدرسہ اشرف المدارس - روزانہ حاضری رپورٹ*\n";
    msg += "━━━━━━━━━━━━━━━━━━━━\n";
    msg += "📅 *تاریخ:* " + dateVal + "\n";
    if (className) msg += "🏫 *جماعت:* " + className + "\n";
    if (periodName) msg += "⏰ *پیریڈ:* " + periodName + "\n";
    msg += "━━━━━━━━━━━━━━━━━━━━\n";
    msg += "👥 *کل طلبہ:* " + total + "\n";
    msg += "✅ *حاضر:* " + present + "\n";
    msg += "❌ *غیر حاضر:* " + absent + "\n";
    msg += "📝 *رخصت:* " + leave + "\n";

    if (absentList.length > 0) {
        msg += "\n❌ *غیر حاضر طلبہ:*\n";
        absentList.forEach(function(name, idx) {
            msg += (idx + 1) + ". " + name + "\n";
        });
    }

    if (leaveList.length > 0) {
        msg += "\n📝 *رخصت پر طلبہ:*\n";
        leaveList.forEach(function(name, idx) {
            msg += (idx + 1) + ". " + name + "\n";
        });
    }

    msg += "\n_اشرف المدارس مینجمنٹ سسٹم_";

    document.getElementById('wa_message_text').value = msg;
    var waUrl = "https://wa.me/?text=" + encodeURIComponent(msg);
    document.getElementById('wa_direct_link').setAttribute('href', waUrl);

    var waModal = new bootstrap.Modal(document.getElementById('whatsAppModal'));
    waModal.show();
}

function copyWhatsAppText() {
    var copyText = document.getElementById("wa_message_text");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var alertBox = document.getElementById('copySuccessAlert');
        alertBox.classList.remove('d-none');
        setTimeout(function() {
            alertBox.classList.add('d-none');
        }, 2500);
    });
}
</script>

<!-- WhatsApp Modal -->
<div class="modal fade" id="whatsAppModal" tabindex="-1" aria-labelledby="whatsAppModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="whatsAppModalLabel"><i class="fab fa-whatsapp me-2"></i> WhatsApp حاضری خلاصہ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="copySuccessAlert" class="alert alert-success d-none py-2 text-center small fw-bold">
                    <i class="fas fa-check-circle me-1"></i> متن کامیابی سے کلپ بورڈ پر کاپی ہو گیا!
                </div>
                <label class="form-label fw-bold small text-muted">پیغام کا متن:</label>
                <textarea id="wa_message_text" class="form-control font-monospace" rows="10" readonly style="direction: rtl; font-size: 13px;"></textarea>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">بند کریں</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" onclick="copyWhatsAppText()">
                        <i class="fas fa-copy me-1"></i> کلپ بورڈ پر کاپی کریں
                    </button>
                    <a id="wa_direct_link" href="#" target="_blank" class="btn btn-success btn-sm fw-bold shadow-sm">
                        <i class="fab fa-whatsapp me-1"></i> WhatsApp پر کھولیں
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>.pulse-btn { animation: pulse 1s infinite; }</style>
<?php include 'footer.php'; ?>
