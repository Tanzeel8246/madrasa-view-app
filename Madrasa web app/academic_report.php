<?php 
session_start();
require_once 'db.php';

$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role, ['admin', 'superadmin', 'administrator'], true);
$p_needed = ($sys_type === 'Masjid') ? 'manage_masjid_academic_entry' : 'manage_academic_entry';
$p_alt1 = ($sys_type === 'Masjid') ? 'manage_masjid_academic_dashboard' : 'manage_academic_dashboard';
$p_alt2 = ($sys_type === 'Masjid') ? 'manage_masjid_academics' : 'manage_academics';
$p_alt3 = ($sys_type === 'Masjid') ? 'manage_masjid_hifz' : 'manage_hifz';
if (!$is_admin && !app_has_perm($p_needed) && !app_has_perm($p_alt1) && !app_has_perm($p_alt2) && !app_has_perm($p_alt3)) {
    include 'header.php';
    echo "<div class='alert alert-danger m-4 text-center fw-bold'>آپ کو تعلیمی رپورٹ اندراج کی اجازت نہیں ہے۔</div>";
    include 'footer.php';
    exit;
}

if (empty($_GET['class']) && empty($_POST['class_name'])) {
    header('Location: academic_entry_dashboard.php');
    exit;
}

include 'header.php'; 

// --- 1. ڈیٹا محفوظ کرنے کا عمل ---
if (isset($_POST['save_academic'])) {
    try {
        $date = $_POST['date'] ?? date('Y-m-d');
        $cur_tenant_id = (int)get_current_tenant_id();
        $class_name = $_POST['class_name'] ?? '';
        $subject = $_POST['subject'] ?? '';
        
        if ($class_name && $subject) {
            $std_ids = $_POST['std_ids'] ?? [];
            $lessons = $_POST['lessons'] ?? [];
            $grades = $_POST['grades'] ?? [];
            
            $pdo->beginTransaction();
            foreach ($std_ids as $std_id) {
                // Remove existing reports for this student, date, and subject
                $pdo->prepare("DELETE FROM academic_reports WHERE student_id = ? AND date = ? AND subject = ? AND tenant_id = ?")->execute([$std_id, $date, $subject, $cur_tenant_id]);
                
                $lesson = trim($lessons[$std_id] ?? '');
                $grade = trim($grades[$std_id] ?? '');
                
                // Save if there's any data
                if ($lesson !== '' || $grade !== '') {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO academic_reports (student_id, date, class_name, subject, lesson_details, grade, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
                        $stmt->execute([$std_id, $date, $class_name, $subject, $lesson, $grade, $cur_tenant_id]);
                    } catch (Exception $eIns) {
                        $stmt = $pdo->prepare("INSERT INTO academic_reports (student_id, date, class_name, subject, lesson_details, grade, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$std_id, $date, $class_name, $subject, $lesson, $grade, $cur_tenant_id]);
                    }
                }
            }
            $pdo->commit();
            
            echo "<div class='alert alert-success text-center fw-bold shadow mt-2' id='alert_msg'>✅ تعلیمی رپورٹ کامیابی سے محفوظ ہو گئی!</div>";
            echo "<script>setTimeout(()=>{ document.getElementById('alert_msg').style.display='none'; }, 2000);</script>";
        }
    } catch (Exception $e) { 
        if($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>"; 
    }
}


// 2. فلٹرز کی وصولی
$cur_tenant_id = (int)get_current_tenant_id();
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class'] ?? '';
$selected_subject = $_GET['subject'] ?? '';

function academic_normalize_class($value) {
    $value = trim((string)$value);
    $value = str_replace(['ٰ', 'ى', 'ي', 'ك', 'آ', 'آ'], ['', 'ی', 'ی', 'ک', 'ا', 'ا'], $value);
    $value = preg_replace('/\s+/u', '', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

// 3. کلاسز لانا
$classes_data = get_allowed_classes($pdo, 'id ASC', null, null, $sys_type);
$allowed_class_names = array_values(array_filter(array_unique(array_column($classes_data, 'name'))));

// Verify selected_class against allowed classes for non-admin
if (!$is_admin && !empty($selected_class) && !in_array($selected_class, $allowed_class_names, true)) {
    $selected_class = !empty($allowed_class_names) ? $allowed_class_names[0] : '';
}

// منتخب کلاس کے مضامین نکالنا
$available_subjects = [];
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE name = ? AND tenant_id = ?");
    $stmt->execute([$selected_class, $cur_tenant_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($c) {
        $is_hifz = ($c['class_type'] == 'Hifz/Nazira');
        
        $stmt_p = $pdo->prepare("SELECT books FROM class_periods WHERE class_id = ? AND tenant_id = ? AND books IS NOT NULL AND books != ''");
        $stmt_p->execute([$c['id'], $cur_tenant_id]);
        $periods = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($periods)) {
            foreach ($periods as $p) {
                if (trim($p['books']) !== '') {
                    $available_subjects[] = trim($p['books']);
                }
            }
        } else {
            if (!empty($c['subjects_list'])) {
                $parts = array_filter(array_map('trim', explode(',', $c['subjects_list'])));
                foreach($parts as $p) {
                    $p_parts = explode(':', $p);
                    $available_subjects[] = trim($p_parts[0]);
                }
            } elseif ($is_hifz) {
                $available_subjects[] = 'قرآن';
            }
        }
        $available_subjects = array_values(array_unique($available_subjects));
        
        // Auto-select first subject if none is selected
        if (!$selected_subject && !empty($available_subjects)) {
            $selected_subject = $available_subjects[0];
        }
    }
}

// 4. طلباء لانا
$students = [];
$existing_reports = [];
if ($selected_class && $selected_subject) {
    $sys_type = $_SESSION['system_type'] ?? 'Madrasa';
    $sys_cond = ($sys_type === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    $studentColumns = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
    $classExpr = in_array('class_name', $studentColumns, true)
        ? "COALESCE(NULLIF(class, ''), NULLIF(class_name, ''))"
        : "class";
    $sel_date_esc = $pdo->quote($selected_date);
    $stmt = $pdo->query("SELECT id, name, father_name, admission_number, admission_date, graduation_date, status, alumni_category, leaving_reason, $classExpr AS student_class FROM students WHERE tenant_id = $cur_tenant_id AND $sys_cond AND (
        (LOWER(TRIM(status)) IN ('active', 'present', '') OR status='حاضر' OR status IS NULL)
        OR (status='Alumni' AND (graduation_date >= $sel_date_esc OR graduation_date IS NULL OR graduation_date = '' OR graduation_date >= DATE_SUB($sel_date_esc, INTERVAL 6 MONTH)))
        OR (id IN (SELECT student_id FROM academic_reports WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
        OR (id IN (SELECT student_id FROM attendance WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
        OR (id IN (SELECT student_id FROM period_attendance WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
    ) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY admission_number ASC");
    $students = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function($student) use ($selected_class, $selected_date) {
        if (academic_normalize_class($student['student_class'] ?? '') !== academic_normalize_class($selected_class)) return false;
        if (is_student_inactive($student, $selected_date)) return false;
        $adm = !empty($student['admission_date']) ? substr($student['admission_date'], 0, 10) : '';
        if ($adm && $selected_date < $adm) return false;
        $grad = !empty($student['graduation_date']) ? substr($student['graduation_date'], 0, 10) : '';
        if ($grad && $selected_date > $grad) return false;
        return true;
    }));

    if (empty($students)) {
        try {
            $m_stmt = $pdo->query("SELECT id, name, father_name, admission_number, admission_date, graduation_date, status, alumni_category, leaving_reason, $classExpr AS student_class FROM maktab_students WHERE tenant_id = $cur_tenant_id AND (
                (LOWER(TRIM(status)) IN ('active', 'present', '') OR status='حاضر' OR status IS NULL)
                OR (status='Alumni' AND (graduation_date >= $sel_date_esc OR graduation_date IS NULL OR graduation_date = '' OR graduation_date >= DATE_SUB($sel_date_esc, INTERVAL 6 MONTH)))
                OR (id IN (SELECT student_id FROM academic_reports WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
                OR (id IN (SELECT student_id FROM attendance WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
                OR (id IN (SELECT student_id FROM period_attendance WHERE date = $sel_date_esc AND tenant_id = $cur_tenant_id))
            ) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY admission_number ASC");
            $students = array_values(array_filter($m_stmt->fetchAll(PDO::FETCH_ASSOC), function($student) use ($selected_class, $selected_date) {
                if (academic_normalize_class($student['student_class'] ?? '') !== academic_normalize_class($selected_class)) return false;
                if (is_student_inactive($student, $selected_date)) return false;
                $adm = !empty($student['admission_date']) ? substr($student['admission_date'], 0, 10) : '';
                if ($adm && $selected_date < $adm) return false;
                $grad = !empty($student['graduation_date']) ? substr($student['graduation_date'], 0, 10) : '';
                if ($grad && $selected_date > $grad) return false;
                return true;
            }));
        } catch (Exception $e) {}
    }
    
    // پہلے سے موجود رپورٹ لانا
    $clean_cls = str_replace(' ', '', $selected_class);
    $stmt = $pdo->prepare("SELECT student_id, lesson_details, grade FROM academic_reports WHERE (class_name = ? OR REPLACE(class_name, ' ', '') = ?) AND subject = ? AND date = ? AND tenant_id = ?");
    $stmt->execute([$selected_class, $clean_cls, $selected_subject, $selected_date, $cur_tenant_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($reports as $r) {
        $existing_reports[$r['student_id']] = $r;
    }

    // 5. حاضری ریکارڈ لانا اور خودکار اسٹیٹس میپ کرنا
    $att_status_map = [];
    try {
        $stmt_att = $pdo->prepare("SELECT student_id, status, shift FROM attendance WHERE date = ? AND tenant_id = ?");
        $stmt_att->execute([$selected_date, $cur_tenant_id]);
        $atts = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
        foreach ($atts as $a) {
            $sid = (int)$a['student_id'];
            $st = trim((string)$a['status']);
            $st_lower = strtolower($st);
            if ($st === 'A' || strpos($st_lower, 'absent') !== false || strpos($st, 'غیر حاضر') !== false || strpos($st, 'غائب') !== false) {
                $att_status_map[$sid] = 'غیر حاضر';
            } elseif ($st === 'L' || strpos($st_lower, 'leave') !== false || strpos($st, 'رخصت') !== false || strpos($st, 'چھٹی') !== false) {
                if (!isset($att_status_map[$sid]) || $att_status_map[$sid] !== 'غیر حاضر') {
                    $att_status_map[$sid] = 'رخصت';
                }
            } elseif ($st === 'P' || strpos($st_lower, 'present') !== false || strpos($st, 'حاضر') !== false) {
                if (!isset($att_status_map[$sid])) {
                    $att_status_map[$sid] = 'حاضر';
                }
            }
        }
    } catch (Exception $eAtt) {}
}
?>

<style>
    .table-responsive { width: 100% !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table th, .table td { word-break: break-word !important; white-space: normal !important; }
    .grade-absent { color: #dc3545 !important; font-weight: bold; background-color: #fff5f5; border-color: #dc3545 !important; }
    .grade-leave { color: #fd7e14 !important; font-weight: bold; background-color: #fff9f2; border-color: #fd7e14 !important; }
    .grade-present { color: #198754 !important; font-weight: bold; }
</style>

<div class="container py-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h2 class="fw-bold text-primary m-0"><i class="fas fa-book-open me-2"></i> جنرل تعلیمی رپورٹ (اندراج)</h2>
        <a href="academic_entry_dashboard.php" class="btn btn-outline-secondary fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>

    <!-- فلٹرز -->
    <div class="card p-4 shadow-sm border-0 border-top border-5 border-primary mb-4">
        <form method="GET" id="filter_form">
            <div class="row g-3 bg-light p-3 rounded mx-0 border">
                <div class="col-md-4">
                    <label class="fw-bold">تاریخ:</label>
                    <input type="date" name="date" class="form-control fw-bold" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()" required>
                </div>
                <div class="col-md-4">
                    <label class="fw-bold">کلاس:</label>
                    <h4 class="fw-bold text-danger m-0 py-2"><?php echo htmlspecialchars($selected_class); ?></h4>
                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                </div>
                <div class="col-md-4">
                    <label class="fw-bold">مضمون / کتاب کا انتخاب کریں:</label>
                    <select name="subject" id="subject_select" class="form-select fw-bold" onchange="this.form.submit()" required <?php echo empty($available_subjects) ? 'disabled' : ''; ?>>
                        <option value="">-- منتخب کریں --</option>
                        <?php foreach($available_subjects as $subj): ?>
                            <option value="<?php echo htmlspecialchars($subj); ?>" <?php echo $selected_subject === $subj ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subj); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($selected_class && empty($available_subjects)): ?>
                        <small class="text-danger">اس کلاس کے کوئی مضامین متعین نہیں ہیں۔</small>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- انٹری فارم -->
    <?php if ($selected_class && $selected_subject): ?>
        <?php if (!empty($students)): ?>
            <form method="POST" id="academic_form" class="card p-4 shadow-sm border-0">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <input type="hidden" name="class_name" value="<?php echo htmlspecialchars($selected_class); ?>">
                <input type="hidden" name="subject" value="<?php echo htmlspecialchars($selected_subject); ?>">
                
                <div class="alert alert-info border-info fw-bold mb-3 d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-chalkboard-teacher me-2"></i>
                        کلاس: <span class="text-danger fw-bolder mx-1"><?php echo htmlspecialchars($selected_class); ?></span> | 
                        مضمون: <span class="text-primary fw-bolder mx-1"><?php echo htmlspecialchars($selected_subject); ?></span>
                    </span>
                    <span class="badge bg-primary fs-6">کل طلباء: <?php echo count($students); ?></span>
                </div>

                <!-- فوری بیچ اندراج برائے اگلا سبق -->
                <div class="card p-3 mb-3 bg-light border">
                    <div class="row align-items-center g-2">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text bg-white fw-bold"><i class="fas fa-bolt text-warning me-1"></i> تمام حاضر طلبہ کے لیے اگلا سبق:</span>
                                <input type="text" id="batch_next_lesson" class="form-control" placeholder="مثلاً: صفحہ 10 تا 12 یا درس نمبر 3">
                                <button type="button" class="btn btn-outline-success fw-bold" onclick="applyBatchLesson()">
                                    <i class="fas fa-check me-1"></i> تمام حاضرین پر لاگو کریں
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 text-start text-muted small">
                            <i class="fas fa-info-circle me-1"></i> غیر حاضر اور رخصت طلبہ کو خودکار استثناء دیا جائے گا۔
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle bg-white">
                        <thead class="table-light text-center">
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:25%;">نام طالب علم و حاضری</th>
                                <th style="width:35%;">پچھلا سبق / کیفیت</th>
                                <th style="width:35%;">اگلا سبق (مقدار / عنوان)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sr = 1; foreach ($students as $s): 
                                $sid = $s['id'];
                                $rep = $existing_reports[$sid] ?? null;
                                $lesson = $rep['lesson_details'] ?? '';
                                $grade = $rep['grade'] ?? '';

                                // اگر پہلے سے گریڈ موجود نہ ہو تو حاضری کی بنیاد پر خودکار ڈیفالٹ لاگو کریں
                                $att_status = $att_status_map[$sid] ?? 'حاضر';
                                if ($grade === '') {
                                    if ($att_status === 'غیر حاضر') {
                                        $grade = 'غیر حاضر';
                                    } elseif ($att_status === 'رخصت') {
                                        $grade = 'رخصت';
                                    } else {
                                        $grade = 'یاد ہے';
                                    }
                                }

                                $grade_class = ($grade === 'غیر حاضر') ? 'grade-absent' : (($grade === 'رخصت') ? 'grade-leave' : 'grade-present');
                            ?>
                            <tr id="row_<?php echo $sid; ?>">
                                <td class="text-center fw-bold text-muted"><?php echo $sr++; ?></td>
                                <td class="fw-bold text-primary">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span><?php echo htmlspecialchars($s['name']); ?></span>
                                        <?php if ($att_status === 'غیر حاضر'): ?>
                                            <span class="badge bg-danger ms-1">غیر حاضر</span>
                                        <?php elseif ($att_status === 'رخصت'): ?>
                                            <span class="badge bg-warning text-dark ms-1">رخصت</span>
                                        <?php else: ?>
                                            <span class="badge bg-success ms-1">حاضر</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted fw-normal">ولد <?php echo htmlspecialchars($s['father_name']); ?> | رول: <?php echo htmlspecialchars($s['admission_number'] ?? '-'); ?></div>
                                    <input type="hidden" name="std_ids[]" value="<?php echo $sid; ?>">
                                </td>
                                <td>
                                    <select name="grades[<?php echo $sid; ?>]" class="form-select fw-bold grade-select <?php echo $grade_class; ?>" onchange="updateGradeStyle(this)">
                                        <option value="یاد ہے" <?php echo $grade == 'یاد ہے' ? 'selected' : ''; ?>>یاد ہے</option>
                                        <option value="یاد نہیں" <?php echo $grade == 'یاد نہیں' ? 'selected' : ''; ?>>یاد نہیں</option>
                                        <option value="کچا پکا" <?php echo $grade == 'کچا پکا' ? 'selected' : ''; ?>>کچا پکا</option>
                                        <option value="غیر حاضر" <?php echo $grade == 'غیر حاضر' ? 'selected' : ''; ?>>غیر حاضر</option>
                                        <option value="رخصت" <?php echo $grade == 'رخصت' ? 'selected' : ''; ?>>رخصت</option>
                                        <option value="بہترین" <?php echo $grade == 'بہترین' ? 'selected' : ''; ?>>بہترین</option>
                                        <option value="بہتر" <?php echo $grade == 'بہتر' ? 'selected' : ''; ?>>بہتر</option>
                                        <option value="رعایتی پاس" <?php echo $grade == 'رعایتی پاس' ? 'selected' : ''; ?>>رعایتی پاس</option>
                                        <option value="کچا" <?php echo $grade == 'کچا' ? 'selected' : ''; ?>>کچا</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="lessons[<?php echo $sid; ?>]" class="form-control lesson-input" placeholder="اگلا سبق درج کریں..." value="<?php echo htmlspecialchars($lesson); ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit" name="save_academic" class="btn btn-primary w-100 py-3 fw-bold shadow fs-5">
                            <i class="fas fa-save me-2"></i> مکمل کلاس کا تعلیمی ریکارڈ محفوظ کریں
                        </button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning text-center fw-bold mt-4">اس کلاس میں فی الحال کوئی طالب علم موجود نہیں ہے۔</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center mt-5 text-muted">
            <i class="fas fa-hand-pointer fa-3x mb-3"></i>
            <h5 class="fw-bold">براہِ کرم اوپر سے کلاس اور مضمون منتخب کریں</h5>
        </div>
    <?php endif; ?>
</div>

<script>
    function updateGradeStyle(select) {
        select.classList.remove('grade-absent', 'grade-leave', 'grade-present');
        if (select.value === 'غیر حاضر') {
            select.classList.add('grade-absent');
        } else if (select.value === 'رخصت') {
            select.classList.add('grade-leave');
        } else {
            select.classList.add('grade-present');
        }
    }

    function applyBatchLesson() {
        const val = document.getElementById('batch_next_lesson').value.trim();
        if (!val) {
            alert('براہِ کرم پہلے اگلا سبق درج کریں!');
            return;
        }
        document.querySelectorAll('tbody tr').forEach(tr => {
            const gradeSelect = tr.querySelector('.grade-select');
            const lessonInput = tr.querySelector('.lesson-input');
            if (gradeSelect && lessonInput) {
                // غیر حاضر اور رخصت والوں کو چھوڑ کر باقی سب پر لاگو کریں
                if (gradeSelect.value !== 'غیر حاضر' && gradeSelect.value !== 'رخصت') {
                    lessonInput.value = val;
                }
            }
        });
    }
</script>

<?php include 'footer.php'; ?>