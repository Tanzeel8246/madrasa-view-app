<?php
/**
 * Academic Multi-Subject Exam Results Entry (درس نظامی و عصری علوم — امتحانی نتائج اندراج)
 * 110% Tri-Platform Parity with Flutter Mobile App & Multi-Tenant Isolation
 */
require_once 'db.php';
ensure_academic_courses_tables($pdo);
include 'header.php';

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true);
$p_needed = ($sys === 'Masjid') ? 'manage_masjid_exams' : 'manage_exams';

if (!$is_admin && !app_app_has_perm($p_needed) && !app_app_has_perm('manage_classes')) {
    echo "<div class='alert alert-danger m-4 text-center fw-bold'>آپ کو امتحانی نتائج اندراج کی اجازت نہیں ہے۔</div>";
    include 'footer.php';
    exit;
}

$msg = '';
$msg_type = 'success';

// 1. امتحانات کی فہرست
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$exams_stmt = $pdo->prepare("SELECT id, exam_name, exam_date, total_marks, passing_marks FROM exams WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond ORDER BY id DESC");
$exams_stmt->execute([$cur_tenant_id]);
$exams_list = $exams_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. کورسز کی فہرست
$courses_stmt = $pdo->prepare("SELECT id, name, course_type, total_years, gender FROM course_structure WHERE tenant_id = ? AND system_type = ? AND is_deleted = 0 ORDER BY id ASC");
$courses_stmt->execute([$cur_tenant_id, $sys]);
$courses_list = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

// فلٹرز
$selected_exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : ($exams_list[0]['id'] ?? 0);
$selected_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : ($courses_list[0]['id'] ?? 0);
$selected_year_no = isset($_GET['year_no']) ? (int)$_GET['year_no'] : 1;

$selected_course = null;
foreach ($courses_list as $c) {
    if ($c['id'] == $selected_course_id) {
        $selected_course = $c;
        break;
    }
}

// اگر صارف نے کوئیک انرولمنٹ یا امپورٹ کیا ہو
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_enroll') {
    $std_ids = $_POST['student_ids'] ?? [];
    $enroll_yr = (int)($_POST['enroll_year'] ?? $selected_year_no);
    $c_id = (int)($_POST['enroll_course_id'] ?? $selected_course_id);
    
    if (!empty($std_ids) && $c_id > 0) {
        $en_count = 0;
        foreach ($std_ids as $s_id) {
            $s_id = (int)$s_id;
            if ($s_id <= 0) continue;
            $chk = $pdo->prepare("SELECT id FROM student_course_enrollment WHERE student_id = ? AND course_id = ? AND tenant_id = ?");
            $chk->execute([$s_id, $c_id, $cur_tenant_id]);
            $exist_id = $chk->fetchColumn();
            if ($exist_id) {
                $pdo->prepare("UPDATE student_course_enrollment SET current_year = ?, status = 'Active', is_deleted = 0, updated_at = NOW() WHERE id = ?")
                    ->execute([$enroll_yr, $exist_id]);
            } else {
                $pdo->prepare("INSERT INTO student_course_enrollment (student_id, course_id, current_year, status, enrollment_date, tenant_id) VALUES (?, ?, ?, 'Active', CURDATE(), ?)")
                    ->execute([$s_id, $c_id, $enroll_yr, $cur_tenant_id]);
            }
            $en_count++;
        }
        $msg = "منتخب کردہ $en_count طلبہ اس سال / درجہ میں کامیابی سے انرول ہو گئے۔";
    }
}

// 3. نتائج محفوظ کرنے کا ہینڈلر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_academic_results') {
    $post_exam_id = (int)($_POST['exam_id'] ?? $selected_exam_id);
    $post_course_id = (int)($_POST['course_id'] ?? $selected_course_id);
    $post_year_no = (int)($_POST['year_no'] ?? $selected_year_no);
    $marks_data = $_POST['marks'] ?? [];
    $absent_data = $_POST['absent'] ?? [];
    $remarks_data = $_POST['remarks'] ?? [];
    $sub_meta = $_POST['subject_meta'] ?? [];

    if ($post_exam_id > 0 && $post_course_id > 0 && !empty($marks_data)) {
        try {
            $pdo->beginTransaction();
            $saved_count = 0;

            foreach ($marks_data as $std_id => $subjs) {
                $std_id = (int)$std_id;
                $rem = trim($remarks_data[$std_id] ?? '');

                foreach ($subjs as $sub_id => $val) {
                    $sub_id = (int)$sub_id;
                    $is_abs = isset($absent_data[$std_id][$sub_id]) ? 1 : 0;
                    $obtained = $is_abs ? 0.0 : (float)($val === '' ? 0.0 : $val);
                    $sub_name = $sub_meta[$sub_id]['name'] ?? 'مضمون';
                    $sub_max = (float)($sub_meta[$sub_id]['max'] ?? 100);
                    $sub_pass = (float)($sub_meta[$sub_id]['pass'] ?? 40);

                    // اسلامی درجہ گریڈنگ
                    $grade = 'راسب';
                    if ($is_abs) {
                        $grade = 'غیر حاضر';
                    } else {
                        $perc = $sub_max > 0 ? ($obtained / $sub_max) * 100 : 0;
                        if ($perc >= 80) {
                            $grade = 'ممتاز';
                        } elseif ($perc >= 70) {
                            $grade = 'جید جدا';
                        } elseif ($perc >= 50) {
                            $grade = 'جید';
                        } elseif ($perc >= ($sub_max > 0 ? ($sub_pass / $sub_max) * 100 : 40)) {
                            $grade = 'مقبول';
                        } else {
                            $grade = 'راسب';
                        }
                    }

                    // چیک کریں کہ پہلے سے موجود ہے
                    $chk = $pdo->prepare("SELECT id FROM exam_subject_results WHERE exam_id=? AND student_id=? AND course_id=? AND year_no=? AND subject_id=? AND tenant_id=?");
                    $chk->execute([$post_exam_id, $std_id, $post_course_id, $post_year_no, $sub_id, $cur_tenant_id]);
                    $existing_res_id = $chk->fetchColumn();

                    if ($existing_res_id) {
                        $u_stmt = $pdo->prepare("UPDATE exam_subject_results SET marks_obtained=?, max_marks=?, is_absent=?, grade=?, remarks=?, is_synced=0, updated_at=NOW() WHERE id=? AND tenant_id=?");
                        $u_stmt->execute([$obtained, $sub_max, $is_abs, $grade, $rem, $existing_res_id, $cur_tenant_id]);
                    } else {
                        $i_stmt = $pdo->prepare("INSERT INTO exam_subject_results (exam_id, student_id, course_id, year_no, subject_id, subject_name, marks_obtained, max_marks, is_absent, grade, remarks, system_type, tenant_id, is_synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                        $i_stmt->execute([$post_exam_id, $std_id, $post_course_id, $post_year_no, $sub_id, $sub_name, $obtained, $sub_max, $is_abs, $grade, $rem, $sys, $cur_tenant_id]);
                    }
                    $saved_count++;
                }
            }

            $pdo->commit();
            $msg = "تمام امتحانی نتائج ($saved_count اندراجات) کامیابی سے محفوظ ہو گئے!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "نتائج محفوظ کرنے میں غلطی ہوئی: " . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

// 4. منتخب کورس و سال کے مضامین
$subjects = [];
$total_course_max = 0;
if ($selected_course_id > 0) {
    $sub_stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ? AND is_deleted = 0 ORDER BY subject_order ASC, id ASC");
    $sub_stmt->execute([$selected_course_id, $selected_year_no, $cur_tenant_id]);
    $subjects = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subjects as $sb) {
        $total_course_max += (float)$sb['max_marks'];
    }
}

// 5. انرولڈ طلبہ
$enrolled_students = [];
if ($selected_course_id > 0) {
    $std_stmt = $pdo->prepare("
        SELECT e.roll_number as course_roll, e.current_year, st.id as student_id, st.name, st.father_name, st.admission_number, st.class
        FROM student_course_enrollment e
        JOIN students st ON e.student_id = st.id
        WHERE e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND e.is_deleted = 0 AND e.status = 'Active' AND (st.is_deleted = 0 OR st.is_deleted IS NULL)
        ORDER BY COALESCE(e.roll_number, '') ASC, st.name ASC
    ");
    $std_stmt->execute([$selected_course_id, $selected_year_no, $cur_tenant_id]);
    $enrolled_students = $std_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// تمام طلبہ برائے انرولمنٹ موڈل
$all_candidates_stmt = $pdo->prepare("
    SELECT id, name, father_name, admission_number, class 
    FROM students 
    WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND (LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', '') OR status IS NULL)
    ORDER BY class ASC, name ASC
");
$all_candidates_stmt->execute([$cur_tenant_id]);
$all_candidates = $all_candidates_stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. پہلے سے محفوظ شدہ نتائج
$existing_results_map = [];
if ($selected_exam_id > 0 && $selected_course_id > 0) {
    $ex_res_stmt = $pdo->prepare("SELECT * FROM exam_subject_results WHERE exam_id = ? AND course_id = ? AND year_no = ? AND tenant_id = ? AND is_deleted = 0");
    $ex_res_stmt->execute([$selected_exam_id, $selected_course_id, $selected_year_no, $cur_tenant_id]);
    $all_ex_res = $ex_res_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_ex_res as $r) {
        $existing_results_map[$r['student_id']][$r['subject_id']] = $r;
    }
}

function get_darjah_title($yr, $c_type = 'Darse_Nizami') {
    $d_names = [
        1 => 'سال اول (اولیٰ - عامہ اول)',
        2 => 'سال دوم (ثانیہ - عامہ دوم)',
        3 => 'سال سوم (ثالثہ - خاصہ اول)',
        4 => 'سال چہارم (رابعہ - خاصہ دوم)',
        5 => 'سال پنجم (خامسہ - عالیہ اول)',
        6 => 'سال ششم (سادسہ - عالیہ دوم)',
        7 => 'سال ہفتم (سابعہ - عالمیہ اول / موقوف علیہ)',
        8 => 'سال ہشتم (دورہ حدیث شریف - عالمیہ دوم)'
    ];
    $asri_names = [
        1 => 'ہفتم (7th Grade)',
        2 => 'ہشتم (8th Grade)',
        3 => 'نہم (9th Matric)',
        4 => 'دہم (10th Matric)'
    ];
    if ($c_type === 'Asri' || $c_type === 'School') {
        return $asri_names[$yr] ?? "سال $yr";
    }
    return $d_names[$yr] ?? "سال $yr";
}
?>

<div class="container-fluid px-4 py-3">
    <!-- ٹائٹل اور ایکشنز -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1 text-dark">
                <i class="fas fa-graduation-cap text-success me-2"></i>
                امتحانی نتائج اندراج (درس نظامی و عصری علوم)
            </h3>
            <p class="text-muted small mb-0">مضامین کے انفرادی نمبرات درج کریں، اسلامی روایتی گریڈنگ خودکار طور پر لاگو ہوگی۔</p>
        </div>
        <div class="d-flex gap-2">
            <a href="course_manager.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-book me-1"></i> کورسز و نصاب سیٹ اپ
            </a>
            <a href="exams.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-right me-1"></i> امتحانات کی فہرست
            </a>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm fw-bold" role="alert">
            <?php echo ($msg_type === 'success' ? '✅ ' : '❌ ') . htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- فلٹر کارڈ -->
    <div class="card shadow-sm border-0 mb-4 bg-white rounded-3">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <!-- امتحان -->
                <div class="col-md-4">
                    <label class="form-label fw-bold text-secondary">
                        <i class="fas fa-calendar-check me-1 text-primary"></i> امتحان منتخب کریں:
                    </label>
                    <select name="exam_id" class="form-select border-2" onchange="document.getElementById('filterForm').submit();">
                        <?php if (empty($exams_list)): ?>
                            <option value="0">کوئی امتحان موجود نہیں ہے</option>
                        <?php else: ?>
                            <?php foreach ($exams_list as $ex): ?>
                                <option value="<?php echo $ex['id']; ?>" <?php echo ($ex['id'] == $selected_exam_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ex['exam_name']); ?> (<?php echo $ex['exam_date']; ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- کورس -->
                <div class="col-md-4">
                    <label class="form-label fw-bold text-secondary">
                        <i class="fas fa-book-reader me-1 text-success"></i> تعلیمی کورس منتخب کریں:
                    </label>
                    <select name="course_id" class="form-select border-2" onchange="document.getElementById('filterForm').submit();">
                        <?php if (empty($courses_list)): ?>
                            <option value="0">کوئی کورس نہیں ملا (کورسز مینیجر سے بنائیں)</option>
                        <?php else: ?>
                            <?php foreach ($courses_list as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $selected_course_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?> - <?php echo $c['total_years']; ?> سالہ)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- سال / درجہ -->
                <div class="col-md-3">
                    <label class="form-label fw-bold text-secondary">
                        <i class="fas fa-layer-group me-1 text-info"></i> سال / درجہ منتخب کریں:
                    </label>
                    <select name="year_no" class="form-select border-2" onchange="document.getElementById('filterForm').submit();">
                        <?php 
                        $max_y = $selected_course ? (int)$selected_course['total_years'] : 8;
                        $c_type = $selected_course['course_type'] ?? 'Darse_Nizami';
                        for ($y = 1; $y <= $max_y; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y == $selected_year_no) ? 'selected' : ''; ?>>
                                <?php echo get_darjah_title($y, $c_type); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($selected_course)): ?>
        <div class="card p-5 text-center shadow-sm border-0">
            <i class="fas fa-exclamation-circle text-warning fa-3x mb-3"></i>
            <h5 class="fw-bold">کوئی تعلیمی کورس منتخب نہیں ہے</h5>
            <p class="text-muted">برائے مہربانی پہلے کورسز و مضامین مینیجر میں جا کر کورس اور اس کے مضامین درج کریں۔</p>
            <div>
                <a href="course_manager.php" class="btn btn-success fw-bold px-4">
                    <i class="fas fa-plus-circle me-1"></i> نیا کورس بنائیں
                </a>
            </div>
        </div>
    <?php elseif (empty($subjects)): ?>
        <div class="card p-5 text-center shadow-sm border-0">
            <i class="fas fa-book-open text-warning fa-3x mb-3"></i>
            <h5 class="fw-bold text-dark"><?php echo htmlspecialchars($selected_course['name']); ?> — <?php echo get_darjah_title($selected_year_no, $selected_course['course_type']); ?> میں کوئی مضمون شامل نہیں ہے</h5>
            <p class="text-muted">اس سال یا درجے کے لیے امتحانی مضامین و کتب ابھی تک درج نہیں کی گئیں۔</p>
            <div>
                <a href="course_manager.php?course_id=<?php echo $selected_course_id; ?>" class="btn btn-primary fw-bold px-4">
                    <i class="fas fa-plus me-1"></i> مضامین شامل کریں
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- انفارمیشن بار -->
        <div class="d-flex flex-wrap justify-content-between align-items-center bg-light p-3 rounded-3 mb-3 border">
            <div>
                <span class="badge bg-primary px-3 py-2 fs-6 me-2">
                    <i class="fas fa-book me-1"></i> کل مضامین: <?php echo count($subjects); ?>
                </span>
                <span class="badge bg-secondary px-3 py-2 fs-6 me-2">
                    <i class="fas fa-calculator me-1"></i> کل نمبرات: <?php echo $total_course_max; ?>
                </span>
                <span class="badge bg-success px-3 py-2 fs-6">
                    <i class="fas fa-user-graduate me-1"></i> انرولڈ طلباء: <?php echo count($enrolled_students); ?>
                </span>
            </div>
            <div class="d-flex gap-2">
                <a href="academic_result_card.php?exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>" target="_blank" class="btn btn-warning text-dark btn-sm fw-bold">
                    <i class="fas fa-file-invoice me-1"></i> تمام نتیجہ کارڈز پرنٹ کریں
                </a>
                <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#quickEnrollModal">
                    <i class="fas fa-user-plus me-1"></i> طالب علم انرول کریں
                </button>
                <button type="button" class="btn btn-outline-dark btn-sm fw-bold" onclick="window.print();">
                    <i class="fas fa-print me-1"></i> پرنٹ لسٹ
                </button>
            </div>
        </div>

        <?php if (empty($enrolled_students)): ?>
            <div class="card p-5 text-center shadow-sm border-0">
                <i class="fas fa-users-slash text-muted fa-3x mb-3"></i>
                <h5 class="fw-bold">اس کورس و سال میں فی الحال کوئی طالب علم انرول نہیں ہے</h5>
                <p class="text-muted">امتحانی نتائج درج کرنے کے لیے نیچے دیے گئے بٹن پر کلک کر کے طلبہ کو اس درجے میں انرول کریں۔</p>
                <div>
                    <button type="button" class="btn btn-success fw-bold px-4" data-bs-toggle="modal" data-bs-target="#quickEnrollModal">
                        <i class="fas fa-user-plus me-1"></i> اس درجہ میں طلبہ شامل کریں
                    </button>
                </div>
            </div>
        <?php else: ?>
            <!-- نتائج اندراج فارم و ٹیبل -->
            <form method="POST" id="resultsEntryForm">
                <input type="hidden" name="action" value="save_academic_results">
                <input type="hidden" name="exam_id" value="<?php echo $selected_exam_id; ?>">
                <input type="hidden" name="course_id" value="<?php echo $selected_course_id; ?>">
                <input type="hidden" name="year_no" value="<?php echo $selected_year_no; ?>">

                <?php foreach ($subjects as $sb): ?>
                    <input type="hidden" name="subject_meta[<?php echo $sb['id']; ?>][name]" value="<?php echo htmlspecialchars($sb['subject_name']); ?>">
                    <input type="hidden" name="subject_meta[<?php echo $sb['id']; ?>][max]" value="<?php echo $sb['max_marks']; ?>">
                    <input type="hidden" name="subject_meta[<?php echo $sb['id']; ?>][pass]" value="<?php echo $sb['pass_marks']; ?>">
                <?php endforeach; ?>

                <div class="table-responsive bg-white rounded-3 shadow-sm border mb-4">
                    <table class="table table-bordered table-hover align-middle mb-0 text-center" id="academicTable">
                        <thead class="table-dark text-nowrap">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th style="width: 70px;">رول نمبر</th>
                                <th style="min-width: 170px;" class="text-start">طالب علم کا نام</th>
                                <?php foreach ($subjects as $sb): ?>
                                    <th style="min-width: 130px;">
                                        <div class="fw-bold"><?php echo htmlspecialchars($sb['subject_name']); ?></div>
                                        <?php if (!empty($sb['book_name'])): ?>
                                            <div class="small fw-normal text-warning"><?php echo htmlspecialchars($sb['book_name']); ?></div>
                                        <?php endif; ?>
                                        <div class="badge bg-secondary font-monospace" style="font-size: 10px;">
                                            کل: <?php echo (float)$sb['max_marks']; ?> | پاس: <?php echo (float)$sb['pass_marks']; ?>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                                <th style="min-width: 100px;">حاصل کردہ</th>
                                <th style="min-width: 70px;">فیصد %</th>
                                <th style="min-width: 90px;">درجہ / گریڈ</th>
                                <th style="min-width: 140px;">کیفیت / تاثرات</th>
                                <th style="width: 60px;">کارڈ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_idx = 1;
                            foreach ($enrolled_students as $st): 
                                $s_id = (int)$st['student_id'];
                                $st_remarks = '';
                            ?>
                                <tr data-student-id="<?php echo $s_id; ?>" class="student-row">
                                    <td class="text-muted fw-bold"><?php echo $row_idx++; ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars($st['course_roll'] ?: ($st['admission_number'] ?: '-')); ?>
                                        </span>
                                    </td>
                                    <td class="text-start">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($st['name']); ?></div>
                                        <div class="small text-muted">ولد: <?php echo htmlspecialchars($st['father_name'] ?: '-'); ?></div>
                                        <small class="badge bg-info-subtle text-info border" style="font-size: 10px;"><?php echo htmlspecialchars($st['class'] ?: 'کلاس'); ?></small>
                                    </td>

                                    <?php 
                                    foreach ($subjects as $sb): 
                                        $sub_id = (int)$sb['id'];
                                        $saved = $existing_results_map[$s_id][$sub_id] ?? null;
                                        $val = ($saved !== null) ? (float)$saved['marks_obtained'] : '';
                                        $is_abs = ($saved !== null && !empty($saved['is_absent']));
                                        if (!empty($saved['remarks']) && empty($st_remarks)) {
                                            $st_remarks = $saved['remarks'];
                                        }
                                        $max_m = (float)$sb['max_marks'];
                                        $pass_m = (float)$sb['pass_marks'];
                                    ?>
                                        <td class="subject-cell" data-subject-id="<?php echo $sub_id; ?>" data-max="<?php echo $max_m; ?>" data-pass="<?php echo $pass_m; ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="number" 
                                                       step="0.5" 
                                                       min="0" 
                                                       max="<?php echo $max_m; ?>" 
                                                       name="marks[<?php echo $s_id; ?>][<?php echo $sub_id; ?>]" 
                                                       value="<?php echo ($val !== '' ? $val : ''); ?>" 
                                                       class="form-control text-center fw-bold marks-input" 
                                                       placeholder="0"
                                                       <?php echo $is_abs ? 'disabled' : ''; ?>
                                                       oninput="recalculateRow(this);">
                                            </div>
                                            <div class="form-check form-check-inline mt-1 mb-0">
                                                <input class="form-check-input absent-checkbox" 
                                                       type="checkbox" 
                                                       name="absent[<?php echo $s_id; ?>][<?php echo $sub_id; ?>]" 
                                                       value="1" 
                                                       id="abs_<?php echo $s_id; ?>_<?php echo $sub_id; ?>" 
                                                       <?php echo $is_abs ? 'checked' : ''; ?>
                                                       onchange="toggleAbsent(this);">
                                                <label class="form-check-label small text-danger" style="font-size: 10px;" for="abs_<?php echo $s_id; ?>_<?php echo $sub_id; ?>">
                                                    غ/ح
                                                </label>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>

                                    <!-- مجموعہ -->
                                    <td>
                                        <span class="fw-bold total-obtained text-primary fs-6">0</span>
                                        <div class="text-muted small" style="font-size: 11px;">/ <?php echo $total_course_max; ?></div>
                                    </td>

                                    <!-- فیصد -->
                                    <td>
                                        <span class="fw-bold total-percent text-dark">0%</span>
                                    </td>

                                    <!-- اسلامی درجہ -->
                                    <td>
                                        <span class="badge grade-badge bg-secondary px-2 py-1 fs-6">-</span>
                                    </td>

                                    <!-- ریمارکس -->
                                    <td>
                                        <input type="text" 
                                               name="remarks[<?php echo $s_id; ?>]" 
                                               value="<?php echo htmlspecialchars($st_remarks); ?>" 
                                               class="form-control form-control-sm text-center" 
                                               placeholder="مثلاً: ماشاء اللہ عمدہ">
                                    </td>
                                    <td>
                                        <a href="academic_result_card.php?exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>&student_id=<?php echo $s_id; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="نتیجہ کارڈ (DMC) پرنٹ کریں">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- نیچے فلوٹنگ ایکشن بار -->
                <div class="card p-3 shadow border-0 bg-white sticky-bottom mb-4 rounded-3 d-flex flex-row justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold text-dark">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            گریڈنگ فارمولا: ممتاز (&ge;80%) | جید جدا (&ge;70%) | جید (&ge;50%) | مقبول (&ge;پاسنگ) | راسب (&lt;پاسنگ)
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success fw-bold px-4 shadow-sm">
                            <i class="fas fa-save me-1"></i> تمام نتائج محفوظ کریں
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- کوئیک انرولمنٹ موڈل -->
<div class="modal fade" id="quickEnrollModal" tabindex="-1" aria-labelledby="quickEnrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="quick_enroll">
                <input type="hidden" name="enroll_course_id" value="<?php echo $selected_course_id; ?>">
                <input type="hidden" name="enroll_year" value="<?php echo $selected_year_no; ?>">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold" id="quickEnrollModalLabel">
                        <i class="fas fa-user-plus me-2"></i>
                        طلبہ کو اس درجہ (<?php echo get_darjah_title($selected_year_no, $selected_course['course_type'] ?? 'Darse_Nizami'); ?>) میں انرول کریں
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        نیچے دی گئی فہرست سے ان تمام طلباء کا انتخاب کریں جنہیں آپ اس درجے کے امتحانات و نصاب میں شامل کرنا چاہتے ہیں:
                    </p>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="input-group input-group-sm w-50">
                            <input type="text" id="stdSearchInput" class="form-control" placeholder="نام یا رول نمبر سے تلاش کریں..." onkeyup="filterCandidateList()">
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllCandidates(true)">سب منتخب کریں</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllCandidates(false)">انتخاب ختم کریں</button>
                        </div>
                    </div>

                    <div class="table-responsive border rounded" style="max-height: 380px; overflow-y: auto;">
                        <table class="table table-hover table-sm align-middle mb-0" id="candidatesTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width: 40px;" class="text-center">انتخاب</th>
                                    <th>نام طالب علم</th>
                                    <th>ولدیت</th>
                                    <th>داخلہ نمبر</th>
                                    <th>موجودہ کلاس</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_candidates as $cand): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" name="student_ids[]" value="<?php echo $cand['id']; ?>" class="form-check-input candidate-check">
                                        </td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($cand['name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($cand['father_name'] ?: '-'); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($cand['admission_number'] ?: '-'); ?></span></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($cand['class'] ?: '-'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ کریں</button>
                    <button type="submit" class="btn btn-success fw-bold">منتخب طلبہ کو انرول کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// لائیو حسابی عمل اور اسلامی گریڈنگ
function recalculateRow(inputEl) {
    var row = inputEl.closest('tr');
    computeStudentRow(row);
}

function toggleAbsent(checkboxEl) {
    var cell = checkboxEl.closest('.subject-cell');
    var input = cell.querySelector('.marks-input');
    if (checkboxEl.checked) {
        input.value = 0;
        input.disabled = true;
    } else {
        input.disabled = false;
    }
    var row = checkboxEl.closest('tr');
    computeStudentRow(row);
}

function computeStudentRow(row) {
    var cells = row.querySelectorAll('.subject-cell');
    var totalObtained = 0;
    var totalMax = 0;
    var hasAnyFail = false;
    var allAbsent = true;

    cells.forEach(function(cell) {
        var maxM = parseFloat(cell.getAttribute('data-max')) || 100;
        var passM = parseFloat(cell.getAttribute('data-pass')) || 40;
        var input = cell.querySelector('.marks-input');
        var absBox = cell.querySelector('.absent-checkbox');

        var isAbs = absBox && absBox.checked;
        var val = parseFloat(input.value) || 0;

        totalMax += maxM;

        if (!isAbs) {
            allAbsent = false;
            totalObtained += val;
            if (val < passM) {
                hasAnyFail = true;
            }
        } else {
            hasAnyFail = true;
        }
    });

    // حاصل کردہ نمبر دکھائیں
    var totalObtEl = row.querySelector('.total-obtained');
    if (totalObtEl) {
        totalObtEl.textContent = totalObtained.toFixed(1).replace('.0', '');
    }

    // فیصد
    var perc = (totalMax > 0) ? ((totalObtained / totalMax) * 100) : 0;
    var percEl = row.querySelector('.total-percent');
    if (percEl) {
        percEl.textContent = perc.toFixed(1) + '%';
    }

    // گریڈ
    var gradeEl = row.querySelector('.grade-badge');
    if (gradeEl) {
        if (allAbsent) {
            gradeEl.textContent = 'غیر حاضر';
            gradeEl.className = 'badge grade-badge bg-danger px-2 py-1 fs-6';
        } else if (hasAnyFail || perc < 40) {
            gradeEl.textContent = 'راسب';
            gradeEl.className = 'badge grade-badge bg-danger px-2 py-1 fs-6';
        } else if (perc >= 80) {
            gradeEl.textContent = 'ممتاز';
            gradeEl.className = 'badge grade-badge bg-warning text-dark px-2 py-1 fs-6';
        } else if (perc >= 70) {
            gradeEl.textContent = 'جید جدا';
            gradeEl.className = 'badge grade-badge bg-primary px-2 py-1 fs-6';
        } else if (perc >= 50) {
            gradeEl.textContent = 'جید';
            gradeEl.className = 'badge grade-badge bg-info text-dark px-2 py-1 fs-6';
        } else {
            gradeEl.textContent = 'مقبول';
            gradeEl.className = 'badge grade-badge bg-success px-2 py-1 fs-6';
        }
    }
}

// صفحہ لوڈ ہوتے ہی تمام قطاروں کا حساب لگائیں
document.addEventListener('DOMContentLoaded', function() {
    var rows = document.querySelectorAll('.student-row');
    rows.forEach(function(r) {
        computeStudentRow(r);
    });
});

// موڈل سرچ اور سلیکشن
function filterCandidateList() {
    var filter = document.getElementById('stdSearchInput').value.toLowerCase();
    var rows = document.querySelectorAll('#candidatesTable tbody tr');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
    });
}

function selectAllCandidates(status) {
    var checks = document.querySelectorAll('.candidate-check');
    checks.forEach(function(c) {
        if (c.closest('tr').style.display !== 'none') {
            c.checked = status;
        }
    });
}
</script>

<?php include 'footer.php'; ?>
