<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'middleware/tenant.php';

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = (int)get_current_tenant_id();

// Helper for grade
function get_grade_badge($pct) {
    if ($pct >= 80) return '<span class="badge bg-success rounded-pill px-3 py-1">ممتاز</span>';
    if ($pct >= 70) return '<span class="badge bg-primary rounded-pill px-3 py-1">جید جدا</span>';
    if ($pct >= 60) return '<span class="badge bg-info text-dark rounded-pill px-3 py-1">جید</span>';
    if ($pct >= 40) return '<span class="badge bg-warning text-dark rounded-pill px-3 py-1">مقبول</span>';
    return '<span class="badge bg-danger rounded-pill px-3 py-1">راسب</span>';
}

// Fetch Exams
$exam_stmt = $pdo->prepare("SELECT id, exam_name, exam_date FROM exams WHERE tenant_id = ? ORDER BY id DESC");
$exam_stmt->execute([$tenant_id]);
$all_exams = $exam_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Courses
$c_stmt = $pdo->prepare("SELECT id, name, gender, total_years FROM course_structure WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
$c_stmt->execute([$tenant_id]);
$all_courses = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$tab = $_GET['tab'] ?? 'class';
$selected_exam_id = (int)($_GET['exam_id'] ?? ($all_exams[0]['id'] ?? 0));
$selected_course_id = (int)($_GET['course_id'] ?? ($all_courses[0]['id'] ?? 0));
$selected_year_no = max(1, (int)($_GET['year_no'] ?? 1));
$selected_student_id = (int)($_GET['student_id'] ?? 0);
$selected_subject_id = (int)($_GET['subject_id'] ?? 0);

$darse_nizami_year_names = [
    1 => 'سال اول (درجہ اولیٰ)',
    2 => 'سال دوم (درجہ ثانیہ)',
    3 => 'سال سوم (درجہ ثالثہ)',
    4 => 'سال چہارم (درجہ رابعہ)',
    5 => 'سال پنجم (درجہ خامسہ)',
    6 => 'سال ششم (درجہ سادسہ)',
    7 => 'سال ہفتم (درجہ عالیہ)',
    8 => 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)'
];

// --- 1. Class / Year Performance Data ---
$class_students = [];
$class_total_max = 0;
$class_stats = ['total' => 0, 'passed' => 0, 'failed' => 0, 'mumtaz' => 0, 'jayyid_jiddan' => 0, 'jayyid' => 0, 'maqbool' => 0, 'avg_pct' => 0];

if ($selected_exam_id > 0 && $selected_course_id > 0) {
    // Subjects max sum
    $s_max_stmt = $pdo->prepare("SELECT SUM(max_marks) FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ?");
    $s_max_stmt->execute([$selected_course_id, $selected_year_no, $tenant_id]);
    $class_total_max = (float)$s_max_stmt->fetchColumn();

    // Query students with aggregate marks
    $q_class = "
        SELECT s.id, s.name, s.father_name, s.roll_number,
               COALESCE(SUM(r.marks_obtained), 0) as total_obtained,
               COUNT(r.id) as subjects_count,
               MIN(CASE WHEN r.is_absent = 1 OR r.marks_obtained < sub.pass_marks THEN 0 ELSE 1 END) as is_all_passed
        FROM student_course_enrollment e
        JOIN students s ON s.id = e.student_id
        LEFT JOIN exam_subject_results r ON r.student_id = s.id AND r.exam_id = ? AND r.course_id = ? AND r.year_no = ? AND r.tenant_id = ?
        LEFT JOIN course_subjects sub ON sub.id = r.subject_id
        WHERE e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND e.status = 'active'
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
        GROUP BY s.id, s.name, s.father_name, s.roll_number
        ORDER BY total_obtained DESC, s.roll_number ASC
    ";
    $st_stmt = $pdo->prepare($q_class);
    $st_stmt->execute([$selected_exam_id, $selected_course_id, $selected_year_no, $tenant_id, $selected_course_id, $selected_year_no, $tenant_id]);
    $class_students = $st_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sum_pct = 0;
    foreach ($class_students as $cs) {
        if ($cs['subjects_count'] > 0 && $class_total_max > 0) {
            $class_stats['total']++;
            $pct = ($cs['total_obtained'] / $class_total_max) * 100;
            $sum_pct += $pct;

            if ($cs['is_all_passed'] == 1 && $pct >= 40) {
                $class_stats['passed']++;
                if ($pct >= 80) $class_stats['mumtaz']++;
                elseif ($pct >= 70) $class_stats['jayyid_jiddan']++;
                elseif ($pct >= 60) $class_stats['jayyid']++;
                else $class_stats['maqbool']++;
            } else {
                $class_stats['failed']++;
            }
        }
    }
    if ($class_stats['total'] > 0) {
        $class_stats['avg_pct'] = round($sum_pct / $class_stats['total'], 1);
    }
}

// --- 2. Subjects list for subject analytics ---
$subjects_list = [];
if ($selected_course_id > 0) {
    $sub_q = $pdo->prepare("SELECT id, subject_name, book_name, max_marks, pass_marks FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ? ORDER BY subject_order ASC");
    $sub_q->execute([$selected_course_id, $selected_year_no, $tenant_id]);
    $subjects_list = $sub_q->fetchAll(PDO::FETCH_ASSOC);
    if ($selected_subject_id == 0 && !empty($subjects_list)) {
        $selected_subject_id = (int)$subjects_list[0]['id'];
    }
}

// --- 3. Subject-specific data & weak students ---
$subject_students = [];
$weak_students = [];
if ($selected_exam_id > 0 && $selected_subject_id > 0) {
    $sub_res_stmt = $pdo->prepare("
        SELECT r.*, s.name as student_name, s.roll_number, s.father_name
        FROM exam_subject_results r
        JOIN students s ON s.id = r.student_id
        WHERE r.exam_id = ? AND r.subject_id = ? AND r.tenant_id = ?
        ORDER BY r.marks_obtained DESC, s.roll_number ASC
    ");
    $sub_res_stmt->execute([$selected_exam_id, $selected_subject_id, $tenant_id]);
    $subject_students = $sub_res_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($subject_students as $st_res) {
        $obt = (float)$st_res['marks_obtained'];
        $max_m = (float)$st_res['max_marks'];
        $pct = ($max_m > 0) ? ($obt / $max_m) * 100 : 0;
        if ($st_res['is_absent'] == 1 || $pct < 40) {
            $weak_students[] = $st_res;
        }
    }
}

// --- 4. Student Academic Profile (Compare across all exams) ---
$student_exam_history = [];
if ($selected_student_id > 0) {
    $std_hist_stmt = $pdo->prepare("
        SELECT ex.exam_name, ex.exam_date, r.subject_name, r.marks_obtained, r.max_marks, r.grade, r.is_absent
        FROM exam_subject_results r
        JOIN exams ex ON ex.id = r.exam_id
        WHERE r.student_id = ? AND r.tenant_id = ?
        ORDER BY ex.exam_date DESC, r.subject_id ASC
    ");
    $std_hist_stmt->execute([$selected_student_id, $tenant_id]);
    $student_exam_history = $std_hist_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 5. Annual Summary Overview ---
$annual_summary = [];
$ann_stmt = $pdo->prepare("
    SELECT c.name as course_name, e.current_year,
           COUNT(e.id) as enrolled_count,
           SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) as active_count,
           SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) as graduated_count,
           SUM(CASE WHEN e.status = 'detained' THEN 1 ELSE 0 END) as detained_count,
           SUM(CASE WHEN e.status = 'dropped' THEN 1 ELSE 0 END) as dropped_count
    FROM student_course_enrollment e
    JOIN course_structure c ON c.id = e.course_id
    WHERE e.tenant_id = ?
    GROUP BY c.name, e.current_year
    ORDER BY c.name ASC, e.current_year ASC
");
$ann_stmt->execute([$tenant_id]);
$annual_summary = $ann_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <!-- Top Header Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 text-white p-4" style="background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fas fa-chart-line me-2"></i> درس نظامی و عصری علوم — جامع تعلیمی و امتحانی رپورٹس</h2>
                <p class="mb-0 text-white-50">کلاس کی کارکردگی، پوزیشن ہولڈرز، کمزور طلبہ کی نشاندہی، اور سالانہ تعلیمی خلاصہ</p>
            </div>
            <div class="d-flex gap-2">
                <a href="academic_results_entry.php" class="btn btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                    <i class="fas fa-edit me-1"></i> نتائج انٹری
                </a>
                <a href="course_manager.php" class="btn btn-outline-light rounded-pill px-3">
                    <i class="fas fa-cogs me-1"></i> کورس سیٹ اپ
                </a>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm">
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($tab === 'class') ? 'active bg-success' : 'text-dark'; ?>" href="academic_reports.php?tab=class&exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>">
                <i class="fas fa-users-class me-1"></i> کلاس وار کارکردگی و پوزیشنز
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($tab === 'subject') ? 'active bg-success' : 'text-dark'; ?>" href="academic_reports.php?tab=subject&exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>">
                <i class="fas fa-book me-1"></i> مضمون وار تجزیہ و کمزور طلبہ
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($tab === 'student') ? 'active bg-success' : 'text-dark'; ?>" href="academic_reports.php?tab=student&student_id=<?php echo $selected_student_id; ?>">
                <i class="fas fa-user-graduate me-1"></i> انفرادی طالب علم رپورٹ
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($tab === 'annual') ? 'active bg-success' : 'text-dark'; ?>" href="academic_reports.php?tab=annual">
                <i class="fas fa-file-invoice me-1"></i> سالانہ کورس خلاصہ (Annual Summary)
            </a>
        </li>
    </ul>

    <!-- TAB 1: CLASS PERFORMANCE & TOP PERFORMERS -->
    <?php if ($tab === 'class'): ?>
        <!-- Filter Card -->
        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="class">
                <div class="col-md-4">
                    <label class="form-label fw-bold">امتحان</label>
                    <select name="exam_id" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php foreach ($all_exams as $ex): ?>
                            <option value="<?php echo $ex['id']; ?>" <?php echo ($selected_exam_id == $ex['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ex['exam_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">کورس</label>
                    <select name="course_id" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php foreach ($all_courses as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($selected_course_id == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">سال / درجہ</label>
                    <select name="year_no" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php for ($y = 1; $y <= 8; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($selected_year_no == $y) ? 'selected' : ''; ?>>
                                <?php echo $darse_nizami_year_names[$y] ?? "سال {$y}"; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-success w-100 rounded-3"><i class="fas fa-filter"></i></button>
                </div>
            </form>
        </div>

        <!-- Class KPI Summary Cards -->
        <div class="row g-3 mb-4 text-center">
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">شامل طلبہ</div>
                    <div class="fw-bold fs-4 text-dark"><?php echo $class_stats['total']; ?></div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">کامیاب طلبہ</div>
                    <div class="fw-bold fs-4 text-success"><?php echo $class_stats['passed']; ?></div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">ناکام طلبہ</div>
                    <div class="fw-bold fs-4 text-danger"><?php echo $class_stats['failed']; ?></div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">شرح کامیابی</div>
                    <div class="fw-bold fs-4 text-primary">
                        <?php echo ($class_stats['total'] > 0) ? round(($class_stats['passed'] / $class_stats['total']) * 100, 1) : 0; ?>%
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">اوسط نمبرات</div>
                    <div class="fw-bold fs-4 text-info"><?php echo $class_stats['avg_pct']; ?>%</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="text-muted small">ممتاز گریڈ</div>
                    <div class="fw-bold fs-4 text-success"><?php echo $class_stats['mumtaz']; ?></div>
                </div>
            </div>
        </div>

        <!-- Positions & Class Table -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-trophy me-2 text-warning"></i> 
                    پوزیشن ہولڈرز اور کلاس رینکنگ لسٹ
                </h5>
                <a href="print_academic_result_card.php?exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>" target="_blank" class="btn btn-outline-success btn-sm rounded-pill px-3">
                    <i class="fas fa-print me-1"></i> تمام رزلٹ کارڈز پرنٹ
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">پوزیشن</th>
                            <th style="width: 80px;">رول نمبر</th>
                            <th class="text-end">طالب علم کا نام</th>
                            <th class="text-end">ولدیت</th>
                            <th>حاصل کردہ نمبرات</th>
                            <th>فیصد</th>
                            <th>گریڈ</th>
                            <th>حیثیت</th>
                            <th>کارڈ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($class_students)): ?>
                            <tr>
                                <td colspan="9" class="py-5 text-muted">اس سال میں کوئی نتائج نہیں ملے۔</td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $pos = 1;
                            foreach ($class_students as $st): 
                                $obt = (float)$st['total_obtained'];
                                $pct = ($class_total_max > 0) ? round(($obt / $class_total_max) * 100, 1) : 0;
                                $is_passed = ($st['is_all_passed'] == 1 && $pct >= 40);
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($pos === 1): ?>
                                            <span class="badge bg-warning text-dark rounded-pill px-2 py-1"><i class="fas fa-medal me-1"></i> اول</span>
                                        <?php elseif ($pos === 2): ?>
                                            <span class="badge bg-secondary text-white rounded-pill px-2 py-1"><i class="fas fa-medal me-1"></i> دوم</span>
                                        <?php elseif ($pos === 3): ?>
                                            <span class="badge bg-danger text-white rounded-pill px-2 py-1"><i class="fas fa-medal me-1"></i> سوم</span>
                                        <?php else: ?>
                                            <span class="text-muted fw-bold"><?php echo $pos; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($st['roll_number'] ?? '—'); ?></td>
                                    <td class="text-end fw-bold text-dark fs-6"><?php echo htmlspecialchars($st['name']); ?></td>
                                    <td class="text-end text-muted"><?php echo htmlspecialchars($st['father_name'] ?? '—'); ?></td>
                                    <td class="fw-bold fs-6 text-dark"><?php echo $obt; ?> <small class="text-muted font-normal">/ <?php echo $class_total_max; ?></small></td>
                                    <td class="fw-bold text-primary"><?php echo $pct; ?>%</td>
                                    <td><?php echo get_grade_badge($pct); ?></td>
                                    <td>
                                        <?php if ($is_passed): ?>
                                            <span class="badge bg-success rounded-pill">کامیاب</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill">راسب</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="print_academic_result_card.php?exam_id=<?php echo $selected_exam_id; ?>&course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $selected_year_no; ?>&student_id=<?php echo $st['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">
                                            <i class="fas fa-id-card"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php $pos++; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 2: SUBJECT ANALYTICS & WEAK STUDENTS -->
    <?php if ($tab === 'subject'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="subject">
                <div class="col-md-3">
                    <label class="form-label fw-bold">امتحان</label>
                    <select name="exam_id" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php foreach ($all_exams as $ex): ?>
                            <option value="<?php echo $ex['id']; ?>" <?php echo ($selected_exam_id == $ex['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ex['exam_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">کورس</label>
                    <select name="course_id" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php foreach ($all_courses as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($selected_course_id == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">سال / درجہ</label>
                    <select name="year_no" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php for ($y = 1; $y <= 8; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($selected_year_no == $y) ? 'selected' : ''; ?>>
                                <?php echo $darse_nizami_year_names[$y] ?? "سال {$y}"; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">مضمون منتخب کریں</label>
                    <select name="subject_id" class="form-select rounded-3" onchange="this.form.submit()">
                        <?php foreach ($subjects_list as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>" <?php echo ($selected_subject_id == $sub['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sub['subject_name']); ?> (<?php echo htmlspecialchars($sub['book_name'] ?? ''); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <!-- Weak Students Alert Box -->
        <?php if (!empty($weak_students)): ?>
            <div class="alert alert-danger shadow-sm rounded-4 p-4 mb-4">
                <h5 class="fw-bold mb-2"><i class="fas fa-exclamation-circle me-2"></i> کمزور اور غیر حاضر طلبہ کی نشاندہی (Weak / Attention Needed)</h5>
                <p class="mb-3 small">اس مضمون میں درج ذیل <?php echo count($weak_students); ?> طلبہ کے نمبرات پاسنگ حد سے کم ہیں جن پر خصوصی توجہ کی ضرورت ہے:</p>
                <div class="row g-2">
                    <?php foreach ($weak_students as $ws): ?>
                        <div class="col-md-4">
                            <div class="p-2 border rounded-3 bg-white d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($ws['student_name']); ?></strong>
                                    <small class="text-muted d-block">رول: <?php echo $ws['roll_number']; ?></small>
                                </div>
                                <div>
                                    <?php if ($ws['is_absent'] == 1): ?>
                                        <span class="badge bg-secondary">غیر حاضر</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?php echo $ws['marks_obtained']; ?> / <?php echo $ws['max_marks']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Subject Marks Details Table -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-list me-2 text-success"></i> اس مضمون میں تمام طلبہ کی کارکردگی</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">رول نمبر</th>
                            <th class="text-end">طالب علم کا نام</th>
                            <th class="text-end">ولدیت</th>
                            <th>حاصل کردہ نمبرات</th>
                            <th>کل نمبر</th>
                            <th>فیصد</th>
                            <th>گریڈ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subject_students)): ?>
                            <tr><td colspan="7" class="py-4 text-muted">کوئی ریکارڈ نہیں ملا۔</td></tr>
                        <?php else: ?>
                            <?php foreach ($subject_students as $st): ?>
                                <?php 
                                $obt = (float)$st['marks_obtained'];
                                $max_m = (float)$st['max_marks'];
                                $pct = ($max_m > 0) ? round(($obt / $max_m) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($st['roll_number'] ?? '—'); ?></td>
                                    <td class="text-end fw-bold text-dark"><?php echo htmlspecialchars($st['student_name']); ?></td>
                                    <td class="text-end text-muted"><?php echo htmlspecialchars($st['father_name'] ?? '—'); ?></td>
                                    <td class="fw-bold fs-6"><?php echo $st['is_absent'] ? '<span class="text-danger">غیر حاضر</span>' : $obt; ?></td>
                                    <td><?php echo $max_m; ?></td>
                                    <td class="fw-bold"><?php echo $pct; ?>%</td>
                                    <td><?php echo get_grade_badge($pct); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: STUDENT ACADEMIC PROFILE -->
    <?php if ($tab === 'student'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h5 class="fw-bold text-dark mb-3"><i class="fas fa-search me-2 text-success"></i> طالب علم کا تعلیمی پروفائل تلاش کریں</h5>
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="student">
                <div class="col-md-9">
                    <select name="student_id" class="form-select rounded-3" required>
                        <option value="">طالب علم منتخب کریں...</option>
                        <?php 
                        $all_st_list = $pdo->prepare("SELECT id, name, father_name, roll_number FROM students WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY roll_number ASC, name ASC");
                        $all_st_list->execute([$tenant_id]);
                        while ($st_opt = $all_st_list->fetch(PDO::FETCH_ASSOC)) {
                            $sel = ($selected_student_id == $st_opt['id']) ? 'selected' : '';
                            echo "<option value='{$st_opt['id']}' {$sel}>" . htmlspecialchars($st_opt['name']) . " (رول: " . $st_opt['roll_number'] . " - والد: " . $st_opt['father_name'] . ")</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100 rounded-3 fw-bold"><i class="fas fa-user-check me-1"></i> پروفائل دیکھیں</button>
                </div>
            </form>
        </div>

        <?php if ($selected_student_id > 0): ?>
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-history me-2"></i> طالب علم کی تمام امتحانات کی تفصیلی کارکردگی</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="table-light">
                            <tr>
                                <th>امتحان کا نام</th>
                                <th>تاریخ</th>
                                <th class="text-end">مضمون</th>
                                <th>حاصل کردہ نمبرات</th>
                                <th>کل نمبر</th>
                                <th>گریڈ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($student_exam_history)): ?>
                                <tr><td colspan="6" class="py-4 text-muted">اس طالب علم کے امتحانی نتائج موجود نہیں۔</td></tr>
                            <?php else: ?>
                                <?php foreach ($student_exam_history as $sh): ?>
                                    <tr>
                                        <td class="fw-bold text-success"><?php echo htmlspecialchars($sh['exam_name']); ?></td>
                                        <td><small class="text-muted"><?php echo $sh['exam_date']; ?></small></td>
                                        <td class="text-end fw-bold"><?php echo htmlspecialchars($sh['subject_name']); ?></td>
                                        <td class="fw-bold"><?php echo $sh['is_absent'] ? '<span class="text-danger">غیر حاضر</span>' : $sh['marks_obtained']; ?></td>
                                        <td><?php echo $sh['max_marks']; ?></td>
                                        <td>
                                            <?php 
                                            $pct = ($sh['max_marks'] > 0) ? ($sh['marks_obtained'] / $sh['max_marks']) * 100 : 0;
                                            echo get_grade_badge($pct);
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- TAB 4: ANNUAL SUMMARY OVERVIEW -->
    <?php if ($tab === 'annual'): ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="fw-bold mb-0"><i class="fas fa-layer-group me-2"></i> سالانہ تعلیمی خلاصہ و کورس وائز تجزیہ</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th class="text-end">کورس کا نام</th>
                            <th>سال / درجہ</th>
                            <th>کل داخلہ یافتہ طلبہ</th>
                            <th>فعال طلبہ</th>
                            <th>فارغ التحصیل</th>
                            <th>روکے گئے (Detained)</th>
                            <th>خارج شدہ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($annual_summary)): ?>
                            <tr><td colspan="7" class="py-4 text-muted">کوئی کورس انرولمنٹ ڈیٹا دستیاب نہیں ہے۔</td></tr>
                        <?php else: ?>
                            <?php foreach ($annual_summary as $as): ?>
                                <tr>
                                    <td class="text-end fw-bold text-dark fs-6"><?php echo htmlspecialchars($as['course_name']); ?></td>
                                    <td class="fw-bold text-primary"><?php echo $darse_nizami_year_names[$as['current_year']] ?? "سال {$as['current_year']}"; ?></td>
                                    <td class="fw-bold fs-6"><?php echo (int)$as['enrolled_count']; ?></td>
                                    <td><span class="badge bg-success rounded-pill px-3 py-1"><?php echo (int)$as['active_count']; ?></span></td>
                                    <td><span class="badge bg-info text-dark rounded-pill px-3 py-1"><?php echo (int)$as['graduated_count']; ?></span></td>
                                    <td><span class="badge bg-warning text-dark rounded-pill px-3 py-1"><?php echo (int)$as['detained_count']; ?></span></td>
                                    <td><span class="badge bg-secondary rounded-pill px-3 py-1"><?php echo (int)$as['dropped_count']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
