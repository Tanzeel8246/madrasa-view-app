<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'middleware/tenant.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_exams') && !app_has_perm('manage_academics')) {
    header("Location: dashboard.php");
    exit;
}

$tenant_id = (int)get_current_tenant_id();
$msg = '';
$err = '';

// Helper function to calculate Islamic Madrasa grade
function calculate_madrasa_grade($obtained, $total, $is_absent = 0) {
    if ($is_absent) return 'غیر حاضر';
    if ($total <= 0) return '—';
    $pct = ($obtained / $total) * 100;
    if ($pct >= 80) return 'ممتاز';
    if ($pct >= 70) return 'جید جدا';
    if ($pct >= 60) return 'جید';
    if ($pct >= 40) return 'مقبول';
    return 'راسب';
}

// 1. Handle New Quick Exam Creation on the fly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_create_exam') {
    $exam_name = trim($_POST['exam_name'] ?? '');
    $exam_date = trim($_POST['exam_date'] ?? date('Y-m-d'));
    if (!empty($exam_name)) {
        $stmt = $pdo->prepare("INSERT INTO exams (exam_name, exam_date, total_marks, passing_marks, system_type, tenant_id) VALUES (?, ?, 100, 40, 'Madrasa', ?)");
        $stmt->execute([$exam_name, $exam_date, $tenant_id]);
        $new_eid = $pdo->lastInsertId();
        $msg = "✅ نیا امتحان '{$exam_name}' کامیابی سے تشکیل دیا گیا!";
        header("Location: academic_results_entry.php?exam_id={$new_eid}&msg=" . urlencode($msg));
        exit;
    }
}

// 2. Handle Bulk Results Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_results') {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $year_no = (int)($_POST['year_no'] ?? 1);
    $marks_data = $_POST['marks'] ?? []; // [student_id][subject_id] => obtained_marks
    $absent_data = $_POST['absent'] ?? []; // [student_id][subject_id] => 1

    if ($exam_id > 0 && $course_id > 0 && !empty($marks_data)) {
        // Fetch subjects info for max marks and names
        $s_stmt = $pdo->prepare("SELECT id, subject_name, max_marks, pass_marks FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ?");
        $s_stmt->execute([$course_id, $year_no, $tenant_id]);
        $subs_map = [];
        while ($sub = $s_stmt->fetch(PDO::FETCH_ASSOC)) {
            $subs_map[(int)$sub['id']] = $sub;
        }

        $saved_count = 0;
        foreach ($marks_data as $student_id => $sub_marks) {
            $student_id = (int)$student_id;
            if ($student_id <= 0) continue;

            foreach ($sub_marks as $subject_id => $val) {
                $subject_id = (int)$subject_id;
                if (!isset($subs_map[$subject_id])) continue;

                $sub_info = $subs_map[$subject_id];
                $is_absent = isset($absent_data[$student_id][$subject_id]) ? 1 : 0;
                $obtained = $is_absent ? 0.0 : (float)$val;
                $max_m = (float)$sub_info['max_marks'];
                $grade = calculate_madrasa_grade($obtained, $max_m, $is_absent);

                // Check existing
                $chk = $pdo->prepare("SELECT id FROM exam_subject_results WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND tenant_id = ? LIMIT 1");
                $chk->execute([$exam_id, $student_id, $subject_id, $tenant_id]);
                $existing_id = $chk->fetchColumn();

                if ($existing_id) {
                    $u_stmt = $pdo->prepare("UPDATE exam_subject_results SET marks_obtained = ?, max_marks = ?, is_absent = ?, grade = ?, course_id = ?, year_no = ? WHERE id = ?");
                    $u_stmt->execute([$obtained, $max_m, $is_absent, $grade, $course_id, $year_no, $existing_id]);
                } else {
                    $i_stmt = $pdo->prepare("INSERT INTO exam_subject_results (tenant_id, exam_id, student_id, course_id, year_no, subject_id, subject_name, marks_obtained, max_marks, is_absent, grade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $i_stmt->execute([$tenant_id, $exam_id, $student_id, $course_id, $year_no, $subject_id, $sub_info['subject_name'], $obtained, $max_m, $is_absent, $grade]);
                }
                $saved_count++;
            }
        }
        $msg = "✅ مضامین وار تمام نتائج کامیابی سے محفوظ ہو گئے!";
    } else {
        $err = "❌ براہ کرم تمام ضروری معلومات اور نمبرات درج کریں۔";
    }
}

// Fetch All Exams for this Tenant
$stmt = $pdo->prepare("SELECT id, exam_name, exam_date FROM exams WHERE tenant_id = ? AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') ORDER BY id DESC");
$stmt->execute([$tenant_id]);
$all_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Courses
$stmt = $pdo->prepare("SELECT id, name, gender, total_years FROM course_structure WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$tenant_id]);
$all_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Selected Filters
$selected_exam_id = (int)($_GET['exam_id'] ?? ($all_exams[0]['id'] ?? 0));
$selected_course_id = (int)($_GET['course_id'] ?? ($all_courses[0]['id'] ?? 0));
$selected_year_no = max(1, (int)($_GET['year_no'] ?? 1));

// Current Course
$curr_course = null;
foreach ($all_courses as $c) {
    if ((int)$c['id'] === $selected_course_id) {
        $curr_course = $c;
        break;
    }
}

// Fetch Subjects for this Course and Year
$subjects = [];
if ($selected_course_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ? ORDER BY subject_order ASC, id ASC");
    $stmt->execute([$selected_course_id, $selected_year_no, $tenant_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Enrolled Students for this Course and Year
$students = [];
if ($selected_course_id > 0) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.father_name, s.roll_number, s.photo, e.academic_session
        FROM student_course_enrollment e
        JOIN students s ON s.id = e.student_id
        WHERE e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND e.status = 'active'
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
        ORDER BY s.roll_number ASC, s.name ASC
    ");
    $stmt->execute([$selected_course_id, $selected_year_no, $tenant_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Existing Marks for this Exam & Year
$existing_results = [];
if ($selected_exam_id > 0 && $selected_course_id > 0) {
    $stmt = $pdo->prepare("
        SELECT student_id, subject_id, marks_obtained, max_marks, is_absent, grade
        FROM exam_subject_results
        WHERE exam_id = ? AND course_id = ? AND year_no = ? AND tenant_id = ?
    ");
    $stmt->execute([$selected_exam_id, $selected_course_id, $selected_year_no, $tenant_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_results[(int)$row['student_id']][(int)$row['subject_id']] = $row;
    }
}

$darse_nizami_year_names = [
    1 => __('سال اول (درجہ اولیٰ)', 'Year 1 (Ula)'),
    2 => __('سال دوم (درجہ ثانیہ)', 'Year 2 (Sania)'),
    3 => __('سال سوم (درجہ ثالثہ)', 'Year 3 (Salisa)'),
    4 => __('سال چہارم (درجہ رابعہ)', 'Year 4 (Rabia)'),
    5 => __('سال پنجم (درجہ خامسہ)', 'Year 5 (Khamisa)'),
    6 => __('سال ششم (درجہ سادسہ)', 'Year 6 (Sadisa)'),
    7 => __('سال ہفتم (درجہ عالیہ)', 'Year 7 (Aliya)'),
    8 => __('سال ہشتم (درجہ عالمیہ / دورۂ حدیث)', 'Year 8 (Almiya / Hadith)')
];

include 'header.php';
?>

<div class="container-fluid py-4" dir="<?php echo ($lang === 'ur') ? 'rtl' : 'ltr'; ?>">
    <!-- Top Header -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 text-white p-4" style="background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fas fa-edit me-2"></i> <?php echo __('درس نظامی و عصری علوم — مضامین وار امتحانی نتائج انٹری', 'Dars-e-Nizami & Contemporary Studies — Subject Results Entry'); ?></h2>
                <p class="mb-0 text-white-50"><?php echo __('تمام کتب اور مضامین کے نمبرات کا ریلیشنل اندراج، خودکار مجموعی نمبر، اور وفاقی گریڈنگ', 'Relational entry of all books and subject marks, automated totals and grading'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning text-dark fw-bold px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#quickExamModal">
                    <i class="fas fa-calendar-plus me-1"></i> <?php echo __('نیا امتحان تشکیل دیں', 'Create New Exam'); ?>
                </button>
                <?php if ($selected_exam_id > 0 && $selected_course_id > 0): ?>
                    <a href="print_academic_result_card.php?exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>" target="_blank" class="btn btn-light text-success fw-bold px-3 rounded-pill shadow-sm">
                        <i class="fas fa-print me-1"></i> <?php echo __('رزلٹ کارڈز پرنٹ', 'Print Result Cards'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm fw-bold">
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm fw-bold">
            <?php echo htmlspecialchars($err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Bar: Exam, Course, Year -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold"><?php echo __('امتحان کا انتخاب (Exam)', 'Select Exam'); ?></label>
                <select name="exam_id" class="form-select rounded-3" onchange="this.form.submit()">
                    <?php if (empty($all_exams)): ?>
                        <option value="0">کوئی امتحان موجود نہیں۔ نیا امتحان بنائیں</option>
                    <?php else: ?>
                        <?php foreach ($all_exams as $ex): ?>
                            <option value="<?php echo $ex['id']; ?>" <?php echo ($selected_exam_id == $ex['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ex['exam_name']); ?> (<?php echo __('تاریخ:', 'Date:'); ?> <?php echo $ex['exam_date']; ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold"><?php echo __('تعلیمی کورس (Course)', 'Course'); ?></label>
                <select name="course_id" class="form-select rounded-3" onchange="this.form.submit()">
                    <?php foreach ($all_courses as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($selected_course_id == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold"><?php echo __('سال / درجہ (Year)', 'Year / Grade'); ?></label>
                <select name="year_no" class="form-select rounded-3" onchange="this.form.submit()">
                    <?php 
                    $max_yrs = $curr_course ? (int)$curr_course['total_years'] : 8;
                    for ($y = 1; $y <= $max_yrs; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($selected_year_no == $y) ? 'selected' : ''; ?>>
                            <?php echo $darse_nizami_year_names[$y] ?? "سال {$y}"; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-success w-100 rounded-3"><i class="fas fa-filter me-1"></i> <?php echo __('فلٹر', 'Filter'); ?></button>
            </div>
        </form>
    </div>

    <!-- Results Entry Grid -->
    <?php if (empty($subjects)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <i class="fas fa-book-open fa-3x text-warning mb-3"></i>
            <h4 class="fw-bold"><?php echo __('اس سال میں کوئی مضمون شامل نہیں ہے', 'No subjects found for this year'); ?></h4>
            <p class="text-muted"><?php echo __('نتائج درج کرنے سے پہلے کورس سیٹ اپ میں جا کر اس درجہ کے کتب و مضامین شامل کریں۔', 'Before entering results, go to course setup and add books/subjects for this grade.'); ?></p>
            <div>
                <a href="course_manager.php?course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>" class="btn btn-success rounded-pill px-4">
                    <i class="fas fa-plus me-1"></i> <?php echo __('مضامین شامل کریں', 'Add Subjects'); ?>
                </a>
            </div>
        </div>
    <?php elseif (empty($students)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <i class="fas fa-user-graduate fa-3x text-primary mb-3"></i>
            <h4 class="fw-bold"><?php echo __('اس سال / درجے میں کوئی طالب علم داخل نہیں ہے', 'No students enrolled in this year/grade'); ?></h4>
            <p class="text-muted"><?php echo __('پہلے طلبہ کو اس کورس اور سال میں انرول (داخل) کریں۔', 'First enroll students in this course and year.'); ?></p>
            <div>
                <a href="student_course_enrollment.php?course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-user-plus me-1"></i> <?php echo __('طلبہ داخل کریں', 'Enroll Students'); ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="save_results">
            <input type="hidden" name="exam_id" value="<?php echo $selected_exam_id; ?>">
            <input type="hidden" name="course_id" value="<?php echo $selected_course_id; ?>">
            <input type="hidden" name="year_no" value="<?php echo $selected_year_no; ?>">

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-table-cells me-2 text-success"></i> 
                            <?php echo __('جدولِ نتائج:', 'Results Sheet:'); ?> <span class="text-primary"><?php echo $darse_nizami_year_names[$selected_year_no] ?? "Year {$selected_year_no}"; ?></span>
                        </h5>
                        <small class="text-muted"><?php echo __('ہر مضمون کے خانے میں حاصل کردہ نمبر درج کریں۔ غیر حاضری کی صورت میں چیک باکس لگائیں۔', 'Enter marks obtained for each subject. Check absent box if absent.'); ?></small>
                    </div>
                    <button type="submit" class="btn btn-success fw-bold px-4 rounded-pill shadow-sm">
                        <i class="fas fa-save me-1"></i> <?php echo __('نتائج محفوظ کریں', 'Save Results'); ?>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0 text-center" id="resultsGridTable">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 60px;"><?php echo __('رول نمبر', 'Roll No'); ?></th>
                                <th class="text-end" style="min-width: 170px;"><?php echo __('طالب علم کا نام', 'Student Name'); ?></th>
                                <?php 
                                $total_possible_marks = 0;
                                foreach ($subjects as $sub): 
                                    $total_possible_marks += (float)$sub['max_marks'];
                                ?>
                                    <th style="min-width: 130px;">
                                        <div class="fw-bold"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
                                        <small class="text-warning d-block" style="font-size: 11px;">
                                            <?php echo !empty($sub['book_name']) ? htmlspecialchars($sub['book_name']) : ''; ?> (<?php echo __('کل:', 'Total:'); ?> <?php echo (float)$sub['max_marks']; ?>)
                                        </small>
                                    </th>
                                <?php endforeach; ?>
                                <th style="min-width: 100px;"><?php echo __('حاصل کردہ / کل', 'Obt / Total'); ?></th>
                                <th style="min-width: 80px;"><?php echo __('فیصد', 'Percentage'); ?></th>
                                <th style="min-width: 100px;"><?php echo __('گریڈ', 'Grade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): ?>
                                <?php 
                                $sid = (int)$st['id'];
                                $st_total_obtained = 0;
                                $st_all_absent = true;
                                $st_any_entered = false;
                                ?>
                                <tr data-student-id="<?php echo $sid; ?>">
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($st['roll_number'] ?? '—'); ?></td>
                                    <td class="text-end fw-bold text-dark">
                                        <?php echo htmlspecialchars($st['name']); ?>
                                        <small class="text-muted d-block font-normal">ولد: <?php echo htmlspecialchars($st['father_name'] ?? '—'); ?></small>
                                    </td>
                                    <?php foreach ($subjects as $sub): ?>
                                        <?php 
                                        $subid = (int)$sub['id'];
                                        $res = $existing_results[$sid][$subid] ?? null;
                                        $obtained = ($res !== null) ? (float)$res['marks_obtained'] : '';
                                        $is_abs = ($res !== null && (int)$res['is_absent'] === 1);
                                        if ($res !== null) {
                                            $st_any_entered = true;
                                            if (!$is_abs) {
                                                $st_all_absent = false;
                                                $st_total_obtained += (float)$obtained;
                                            }
                                        }
                                        ?>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.5" 
                                                       name="marks[<?php echo $sid; ?>][<?php echo $subid; ?>]" 
                                                       class="form-control text-center mark-input" 
                                                       data-sid="<?php echo $sid; ?>"
                                                       data-max="<?php echo (float)$sub['max_marks']; ?>"
                                                       value="<?php echo ($obtained !== '' && !$is_abs) ? $obtained : ''; ?>" 
                                                       max="<?php echo (float)$sub['max_marks']; ?>" min="0"
                                                       <?php echo $is_abs ? 'disabled' : ''; ?>
                                                       placeholder="0"
                                                       oninput="recalcRow(<?php echo $sid; ?>)">
                                                <span class="input-group-text p-1" title="غیر حاضر">
                                                    <input type="checkbox" name="absent[<?php echo $sid; ?>][<?php echo $subid; ?>]" 
                                                           class="form-check-input absent-chk" 
                                                           data-sid="<?php echo $sid; ?>"
                                                           value="1" 
                                                           <?php echo $is_abs ? 'checked' : ''; ?>
                                                           onchange="toggleAbsent(this, <?php echo $sid; ?>)">
                                                </span>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="fw-bold" id="total_<?php echo $sid; ?>">
                                        <span class="tot-obt text-success"><?php echo $st_any_entered ? $st_total_obtained : '—'; ?></span> / <?php echo $total_possible_marks; ?>
                                    </td>
                                    <td class="fw-bold" id="pct_<?php echo $sid; ?>">
                                        <?php 
                                        if ($st_any_entered && $total_possible_marks > 0) {
                                            $pct = round(($st_total_obtained / $total_possible_marks) * 100, 1);
                                            echo "{$pct}%";
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                    <td id="grade_<?php echo $sid; ?>">
                                        <?php 
                                        if ($st_any_entered && $total_possible_marks > 0) {
                                            $g = calculate_madrasa_grade($st_total_obtained, $total_possible_marks, $st_all_absent ? 1 : 0);
                                            $badge_cls = ($g === 'ممتاز') ? 'bg-success' : (($g === 'جید جدا') ? 'bg-primary' : (($g === 'جید') ? 'bg-info text-dark' : (($g === 'مقبول') ? 'bg-warning text-dark' : 'bg-danger')));
                                            echo "<span class='badge {$badge_cls} rounded-pill px-3 py-1'>{$g}</span>";
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-light py-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small">تمام درجات کے نمبرات رزلٹ کارڈز اور سالانہ ترقی کے لیے خودکار شمار ہوں گے۔</span>
                    <button type="submit" class="btn btn-success fw-bold px-4 rounded-pill shadow-sm">
                        <i class="fas fa-save me-1"></i> تمام نتائج محفوظ کریں
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Modal: Quick Create Exam -->
<div class="modal fade" id="quickExamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="quick_create_exam">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-calendar-plus me-2"></i> نیا امتحان تشکیل دیں</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">امتحان کا عنوان (لازمی)</label>
                        <input type="text" name="exam_name" class="form-control rounded-3" placeholder="مثلاً: سالانہ امتحان 1447ھ، ششماہی امتحان، سہ ماہی، دو ماہی یا ماہانہ ٹیسٹ" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">امتحان کی تاریخ</label>
                        <input type="date" name="exam_date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <p class="small text-muted mb-0">امتحان کا دائرہ اختیاری ہے، آپ جب چاہیں نیا امتحان بنا کر اس کے نتائج درج کر سکتے ہیں۔</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">تشکیل دیں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const TOTAL_MAX = <?php echo (float)($total_possible_marks ?? 100); ?>;

function toggleAbsent(chk, sid) {
    const input = chk.closest('.input-group').querySelector('.mark-input');
    if (chk.checked) {
        input.value = '';
        input.disabled = true;
    } else {
        input.disabled = false;
    }
    recalcRow(sid);
}

function recalcRow(sid) {
    const row = document.querySelector('tr[data-student-id="' + sid + '"]');
    if (!row) return;

    const inputs = row.querySelectorAll('.mark-input');
    const absentChks = row.querySelectorAll('.absent-chk');

    let totalObt = 0;
    let anyEntered = false;
    let allAbsent = true;

    for (let i = 0; i < inputs.length; i++) {
        const inp = inputs[i];
        const isAbs = absentChks[i] ? absentChks[i].checked : false;

        if (isAbs) {
            anyEntered = true;
        } else if (inp.value !== '') {
            anyEntered = true;
            allAbsent = false;
            totalObt += parseFloat(inp.value) || 0;
        }
    }

    const totalEl = document.getElementById('total_' + sid);
    const pctEl = document.getElementById('pct_' + sid);
    const gradeEl = document.getElementById('grade_' + sid);

    if (!anyEntered || TOTAL_MAX <= 0) {
        totalEl.innerHTML = '<span class="tot-obt text-success">—</span> / ' + TOTAL_MAX;
        pctEl.innerText = '—';
        gradeEl.innerHTML = '<span class="text-muted">—</span>';
        return;
    }

    totalEl.innerHTML = '<span class="tot-obt text-success">' + totalObt + '</span> / ' + TOTAL_MAX;
    const pct = Math.round((totalObt / TOTAL_MAX) * 1000) / 10;
    pctEl.innerText = pct + '%';

    let grade = 'مقبول';
    let badgeClass = 'bg-warning text-dark';

    if (allAbsent) {
        grade = 'غیر حاضر';
        badgeClass = 'bg-secondary';
    } else if (pct >= 80) {
        grade = 'ممتاز';
        badgeClass = 'bg-success';
    } else if (pct >= 70) {
        grade = 'جید جدا';
        badgeClass = 'bg-primary';
    } else if (pct >= 60) {
        grade = 'جید';
        badgeClass = 'bg-info text-dark';
    } else if (pct >= 40) {
        grade = 'مقبول';
        badgeClass = 'bg-warning text-dark';
    } else {
        grade = 'راسب';
        badgeClass = 'bg-danger';
    }

    gradeEl.innerHTML = '<span class="badge ' + badgeClass + ' rounded-pill px-3 py-1">' + grade + '</span>';
}
</script>

<?php include 'footer.php'; ?>
