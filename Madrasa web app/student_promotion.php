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
if (!$is_admin && !app_has_perm('manage_students') && !app_has_perm('manage_classes')) {
    header("Location: dashboard.php");
    exit;
}

$tenant_id = (int)get_current_tenant_id();
$msg = '';
$err = '';

// Handle Promotion Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_promotion') {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $from_year = (int)($_POST['from_year'] ?? 1);
    $to_year = (int)($_POST['to_year'] ?? ($from_year + 1));
    $new_session = trim($_POST['new_session'] ?? '');
    $student_actions = $_POST['student_action'] ?? []; // [student_id] => 'Promoted' | 'Detained' | 'Graduated' | 'Dropped'
    $remarks_list = $_POST['student_remarks'] ?? [];

    if ($course_id > 0 && !empty($student_actions) && is_array($student_actions)) {
        $promoted_count = 0;
        $detained_count = 0;
        $graduated_count = 0;

        foreach ($student_actions as $sid => $status) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;

            $remarks = trim($remarks_list[$sid] ?? '');
            $effective_to_year = ($status === 'Promoted') ? $to_year : $from_year;
            $enrollment_status = ($status === 'Graduated') ? 'completed' : (($status === 'Dropped') ? 'dropped' : (($status === 'Detained') ? 'detained' : 'active'));

            // 1. Record in promotion history
            $stmt = $pdo->prepare("INSERT INTO student_year_promotion (tenant_id, student_id, course_id, from_year, to_year, academic_session, status, promoted_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $sid, $course_id, $from_year, $effective_to_year, $new_session, $status, (int)$_SESSION['user_id'], $remarks]);

            // 2. Update active course enrollment
            $up = $pdo->prepare("UPDATE student_course_enrollment SET current_year = ?, academic_session = ?, status = ? WHERE student_id = ? AND course_id = ? AND tenant_id = ? AND status != 'completed'");
            $up->execute([$effective_to_year, $new_session, $enrollment_status, $sid, $course_id, $tenant_id]);

            if ($status === 'Promoted') $promoted_count++;
            elseif ($status === 'Detained') $detained_count++;
            elseif ($status === 'Graduated') $graduated_count++;
        }

        $msg = "✅ سالانہ ترقی مکمل: {$promoted_count} طلبہ اگلے سال میں ترقی پا گئے، {$detained_count} روکے گئے، اور {$graduated_count} فارغ التحصیل قرار پائے۔";
    } else {
        $err = "❌ کوئی کارروائی منتخب نہیں کی گئی۔";
    }
}

// Fetch Courses
$stmt = $pdo->prepare("SELECT id, name, gender, total_years FROM course_structure WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$tenant_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_course_id = (int)($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$selected_year_no = max(1, (int)($_GET['year_no'] ?? 1));

// Current Course
$curr_course = null;
foreach ($courses as $c) {
    if ((int)$c['id'] === $selected_course_id) {
        $curr_course = $c;
        break;
    }
}

// Next Year default
$default_to_year = min($curr_course ? (int)$curr_course['total_years'] : 8, $selected_year_no + 1);

// Fetch Students enrolled in this year
$students = [];
if ($selected_course_id > 0) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.father_name, s.roll_number, s.photo, e.academic_session, e.status as enrollment_status
        FROM student_course_enrollment e
        JOIN students s ON s.id = e.student_id
        WHERE e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND e.status = 'active'
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
        ORDER BY s.roll_number ASC, s.name ASC
    ");
    $stmt->execute([$selected_course_id, $selected_year_no, $tenant_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch recent promotions history
$hist_stmt = $pdo->prepare("
    SELECT p.*, s.name as student_name, s.roll_number, c.name as course_name
    FROM student_year_promotion p
    JOIN students s ON s.id = p.student_id
    LEFT JOIN course_structure c ON c.id = p.course_id
    WHERE p.tenant_id = ?
    ORDER BY p.id DESC LIMIT 15
");
$hist_stmt->execute([$tenant_id]);
$recent_promotions = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <h2 class="fw-bold mb-1"><i class="fas fa-level-up-alt me-2"></i> <?php echo __('سالانہ ترقی و پروموشن مینیجر (Annual Promotion)', 'Annual Promotion Manager'); ?></h2>
                <p class="mb-0 text-white-50"><?php echo __('سال کے اختتام پر طلبہ کو اگلے درجات میں ترقی دینے اور تعلیمی ریکارڈ محفوظ کرنے کا نظام', 'System to promote students to next grades and preserve academic history'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="academic_results_entry.php" class="btn btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                    <i class="fas fa-clipboard-check me-1"></i> <?php echo __('امتحانی رزلٹ انٹری', 'Exam Results Entry'); ?>
                </a>
                <a href="course_manager.php" class="btn btn-outline-light rounded-pill px-3">
                    <i class="fas fa-cogs me-1"></i> <?php echo __('کورس سیٹ اپ', 'Course Setup'); ?>
                </a>
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

    <!-- Filter & Year Selection Card -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold"><?php echo __('کورس منتخب کریں', 'Select Course'); ?></label>
                <select name="course_id" class="form-select rounded-3" onchange="this.form.submit()">
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($selected_course_id == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold"><?php echo __('موجودہ سال / درجہ (جس کو پروموٹ کرنا ہے)', 'Current Year / Grade (To Promote)'); ?></label>
                <select name="year_no" class="form-select rounded-3" onchange="this.form.submit()">
                    <?php 
                    $max_y = $curr_course ? (int)$curr_course['total_years'] : 8;
                    for ($y = 1; $y <= $max_y; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($selected_year_no == $y) ? 'selected' : ''; ?>>
                            <?php echo $darse_nizami_year_names[$y] ?? "سال {$y}"; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100 rounded-3"><i class="fas fa-filter me-1"></i> <?php echo __('طلبہ لوڈ کریں', 'Load Students'); ?></button>
            </div>
        </form>
    </div>

    <!-- Promotion Action Form -->
    <?php if (empty($students)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white mb-4">
            <i class="fas fa-user-check fa-3x text-muted mb-3"></i>
            <h4 class="fw-bold"><?php echo __('اس سال میں کوئی متحرک طالب علم موجود نہیں ہے', 'No active students found in this year'); ?></h4>
            <p class="text-muted"><?php echo __('تمام طلبہ یا تو پہلے ہی ترقی پا چکے ہیں یا اس سال میں کوئی داخلہ نہیں ہے۔', 'All students are already promoted or no enrollments exist in this year.'); ?></p>
        </div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="process_promotion">
            <input type="hidden" name="course_id" value="<?php echo $selected_course_id; ?>">
            <input type="hidden" name="from_year" value="<?php echo $selected_year_no; ?>">

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <h5 class="fw-bold mb-0 text-success">
                                <i class="fas fa-arrow-circle-up me-2"></i> 
                                موجودہ سال: <span class="text-dark"><?php echo $darse_nizami_year_names[$selected_year_no] ?? "سال {$selected_year_no}"; ?></span>
                            </h5>
                            <small class="text-muted">مجموعی طلبہ: <?php echo count($students); ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small mb-1"><?php echo __('اگلا سال / درجہ (Target Year):', 'Target Year / Grade:'); ?></label>
                            <select name="to_year" class="form-select form-select-sm rounded-3">
                                <?php for ($y = 1; $y <= $max_y; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($default_to_year == $y) ? 'selected' : ''; ?>>
                                        <?php echo $darse_nizami_year_names[$y] ?? "سال {$y}"; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small mb-1"><?php echo __('نیا تعلیمی سیشن (Session):', 'New Academic Session:'); ?></label>
                            <input type="text" name="new_session" class="form-control form-control-sm rounded-3" value="<?php echo (date('Y') + 1) . '-' . (date('Y') + 2); ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Quick Action Bar -->
                <div class="bg-light p-2 border-top border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill" onclick="setAllActions('Promoted')">
                            <i class="fas fa-check-double me-1"></i> <?php echo __('سب کو ترقی دیں (Promote All)', 'Promote All'); ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill text-dark" onclick="setAllActions('Detained')">
                            <i class="fas fa-pause-circle me-1"></i> <?php echo __('سب کو اسی سال رکھیں (Detain All)', 'Detain All'); ?>
                        </button>
                        <?php if ($selected_year_no >= $max_y): ?>
                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill" onclick="setAllActions('Graduated')">
                                <i class="fas fa-award me-1"></i> <?php echo __('سب کو فارغ التحصیل قرار دیں', 'Graduate All'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-success fw-bold px-4 rounded-pill shadow-sm" onclick="return confirm('کیا آپ واقعی منتخب کردہ پروموشن نافذ کرنا چاہتے ہیں؟');">
                        <i class="fas fa-save me-1"></i> <?php echo __('سالانہ ترقی لاگو کریں', 'Apply Annual Promotion'); ?>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 70px;"><?php echo __('رول نمبر', 'Roll No'); ?></th>
                                <th class="text-end" style="min-width: 180px;"><?php echo __('طالب علم کا نام', 'Student Name'); ?></th>
                                <th class="text-end" style="min-width: 160px;"><?php echo __('ولدیت', 'Father Name'); ?></th>
                                <th style="width: 170px;"><?php echo __('پروموشن ایکشن', 'Promotion Action'); ?></th>
                                <th style="min-width: 200px;"><?php echo __('ملاحظات / کیفیت (اختیاری)', 'Remarks / Status (Optional)'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): ?>
                                <?php $sid = (int)$st['id']; ?>
                                <tr>
                                    <td class="fw-bold text-success"><?php echo htmlspecialchars($st['roll_number'] ?? '—'); ?></td>
                                    <td class="text-end fw-bold text-dark fs-6"><?php echo htmlspecialchars($st['name']); ?></td>
                                    <td class="text-end text-muted"><?php echo htmlspecialchars($st['father_name'] ?? '—'); ?></td>
                                    <td>
                                        <select name="student_action[<?php echo $sid; ?>]" class="form-select form-select-sm rounded-pill promo-action-select">
                                            <option value="Promoted" selected>ترقی یافتہ (Pass & Promote)</option>
                                            <option value="Detained">روکا گیا (Detain in Same Year)</option>
                                            <option value="Graduated">فارغ التحصیل (Graduated)</option>
                                            <option value="Dropped">خارج / موقوف (Dropped)</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="student_remarks[<?php echo $sid; ?>]" class="form-control form-control-sm rounded-3" placeholder="مثلاً: نمایاں کامیابی، یا مشروط داخلہ">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
                    <small class="text-muted">ترقی لاگو کرنے سے طالب علم کا اگلا سال فعال ہو جائے گا اور پرانا تعلیمی ریکارڈ محفوظ رہے گا۔</small>
                    <button type="submit" class="btn btn-success fw-bold px-4 rounded-pill shadow-sm" onclick="return confirm('کیا آپ واقعی منتخب کردہ پروموشن نافذ کرنا چاہتے ہیں؟');">
                        <i class="fas fa-save me-1"></i> <?php echo __('سالانہ ترقی لاگو کریں', 'Apply Annual Promotion'); ?>
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <!-- Recent Promotions History -->
    <?php if (!empty($recent_promotions)): ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="fw-bold mb-0"><i class="fas fa-history me-2"></i> حالیہ سالانہ ترقیاتی تاریخچہ (Promotion History Log)</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th>تاریخ</th>
                            <th>رول نمبر</th>
                            <th class="text-end">طالب علم</th>
                            <th>کورس</th>
                            <th>سابقہ سال</th>
                            <th>نیا سال</th>
                            <th>سیشن</th>
                            <th>حیثیت</th>
                            <th class="text-end">ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_promotions as $hp): ?>
                            <tr>
                                <td><small class="text-muted"><?php echo substr($hp['created_at'], 0, 10); ?></small></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($hp['roll_number'] ?? '—'); ?></td>
                                <td class="text-end fw-bold"><?php echo htmlspecialchars($hp['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($hp['course_name'] ?? '—'); ?></td>
                                <td><?php echo $darse_nizami_year_names[$hp['from_year']] ?? "سال {$hp['from_year']}"; ?></td>
                                <td class="fw-bold text-success"><?php echo $darse_nizami_year_names[$hp['to_year']] ?? "سال {$hp['to_year']}"; ?></td>
                                <td><?php echo htmlspecialchars($hp['academic_session'] ?? '—'); ?></td>
                                <td>
                                    <?php if ($hp['status'] === 'Promoted'): ?>
                                        <span class="badge bg-success rounded-pill">ترقی یافتہ</span>
                                    <?php elseif ($hp['status'] === 'Detained'): ?>
                                        <span class="badge bg-warning text-dark rounded-pill">روکا گیا</span>
                                    <?php elseif ($hp['status'] === 'Graduated'): ?>
                                        <span class="badge bg-info text-dark rounded-pill">فارغ التحصیل</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($hp['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-muted small"><?php echo htmlspecialchars($hp['remarks'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function setAllActions(val) {
    document.querySelectorAll('.promo-action-select').forEach(function(sel) {
        sel.value = val;
    });
}
</script>

<?php include 'footer.php'; ?>
