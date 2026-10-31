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
if (!$is_admin && !app_has_perm('manage_classes') && !app_has_perm('manage_exams')) {
    header("Location: dashboard.php");
    exit;
}

$tenant_id = (int)get_current_tenant_id();
$msg = '';
$err = '';

// Handle Course Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_course') {
        $name = trim($_POST['name'] ?? '');
        $course_type = trim($_POST['course_type'] ?? 'Darse_Nizami');
        $total_years = max(1, min(16, (int)($_POST['total_years'] ?? 8)));
        $gender = trim($_POST['gender'] ?? 'بنین');
        $desc = trim($_POST['description'] ?? '');

        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO course_structure (tenant_id, name, course_type, total_years, gender, description, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$tenant_id, $name, $course_type, $total_years, $gender, $desc]);
            $new_course_id = $pdo->lastInsertId();

            // Auto-populate default year titles (اولیٰ، ثانیہ وغیرہ) if Darse Nizami
            $default_titles = [
                1 => 'سال اول (درجہ اولیٰ)',
                2 => 'سال دوم (درجہ ثانیہ)',
                3 => 'سال سوم (درجہ ثالثہ)',
                4 => 'سال چہارم (درجہ رابعہ)',
                5 => 'سال پنجم (درجہ خامسہ)',
                6 => 'سال ششم (درجہ سادسہ)',
                7 => 'سال ہفتم (درجہ عالیہ / موقوف علیہ)',
                8 => 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)'
            ];

            $msg = "✅ نیا کورس کامیابی سے شامل کر دیا گیا!";
            header("Location: course_manager.php?course_id=" . $new_course_id . "&msg=" . urlencode($msg));
            exit;
        } else {
            $err = "❌ کورس کا نام درج کرنا لازمی ہے۔";
        }
    }

    if ($action === 'edit_course') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $course_type = trim($_POST['course_type'] ?? 'Darse_Nizami');
        $total_years = max(1, min(16, (int)($_POST['total_years'] ?? 8)));
        $gender = trim($_POST['gender'] ?? 'بنین');
        $desc = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($cid > 0 && !empty($name)) {
            $stmt = $pdo->prepare("UPDATE course_structure SET name = ?, course_type = ?, total_years = ?, gender = ?, description = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$name, $course_type, $total_years, $gender, $desc, $is_active, $cid, $tenant_id]);
            $msg = "✅ کورس کی تفصیلات اپڈیٹ ہو گئیں۔";
        }
    }

    if ($action === 'delete_course') {
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid > 0) {
            $stmt = $pdo->prepare("DELETE FROM course_subjects WHERE course_id = ? AND tenant_id = ?");
            $stmt->execute([$cid, $tenant_id]);
            $stmt = $pdo->prepare("DELETE FROM course_structure WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$cid, $tenant_id]);
            $msg = "✅ کورس اور اس کے تمام مضامین حذف کر دیے گئے۔";
            header("Location: course_manager.php?msg=" . urlencode($msg));
            exit;
        }
    }

    if ($action === 'add_subject') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $year_no = max(1, (int)($_POST['year_no'] ?? 1));
        $year_title = trim($_POST['year_title'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $book_name = trim($_POST['book_name'] ?? '');
        $max_marks = (float)($_POST['max_marks'] ?? 100);
        $pass_marks = (float)($_POST['pass_marks'] ?? 40);
        $subject_order = (int)($_POST['subject_order'] ?? 0);
        $is_optional = isset($_POST['is_optional']) ? 1 : 0;

        if ($cid > 0 && !empty($subject_name)) {
            $stmt = $pdo->prepare("INSERT INTO course_subjects (tenant_id, course_id, year_no, year_title, subject_name, book_name, max_marks, pass_marks, subject_order, is_optional) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $cid, $year_no, $year_title, $subject_name, $book_name, $max_marks, $pass_marks, $subject_order, $is_optional]);
            $msg = "✅ مضمون کامیابی سے شامل کر دیا گیا۔";
            header("Location: course_manager.php?course_id={$cid}&year_no={$year_no}&msg=" . urlencode($msg));
            exit;
        } else {
            $err = "❌ مضمون کا نام درج کرنا لازمی ہے۔";
        }
    }

    if ($action === 'edit_subject') {
        $sid = (int)($_POST['subject_id'] ?? 0);
        $cid = (int)($_POST['course_id'] ?? 0);
        $year_no = max(1, (int)($_POST['year_no'] ?? 1));
        $year_title = trim($_POST['year_title'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $book_name = trim($_POST['book_name'] ?? '');
        $max_marks = (float)($_POST['max_marks'] ?? 100);
        $pass_marks = (float)($_POST['pass_marks'] ?? 40);
        $subject_order = (int)($_POST['subject_order'] ?? 0);
        $is_optional = isset($_POST['is_optional']) ? 1 : 0;

        if ($sid > 0 && !empty($subject_name)) {
            $stmt = $pdo->prepare("UPDATE course_subjects SET year_title = ?, subject_name = ?, book_name = ?, max_marks = ?, pass_marks = ?, subject_order = ?, is_optional = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$year_title, $subject_name, $book_name, $max_marks, $pass_marks, $subject_order, $is_optional, $sid, $tenant_id]);
            $msg = "✅ مضمون کی تفصیلات اپڈیٹ ہو گئیں۔";
            header("Location: course_manager.php?course_id={$cid}&year_no={$year_no}&msg=" . urlencode($msg));
            exit;
        }
    }

    if ($action === 'delete_subject') {
        $sid = (int)($_POST['subject_id'] ?? 0);
        $cid = (int)($_POST['course_id'] ?? 0);
        $year_no = (int)($_POST['year_no'] ?? 1);
        if ($sid > 0) {
            $stmt = $pdo->prepare("DELETE FROM course_subjects WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$sid, $tenant_id]);
            $msg = "✅ مضمون حذف کر دیا گیا۔";
            header("Location: course_manager.php?course_id={$cid}&year_no={$year_no}&msg=" . urlencode($msg));
            exit;
        }
    }
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; }
if (!empty($_GET['err'])) { $err = $_GET['err']; }

// Fetch All Courses for Current Tenant
$stmt = $pdo->prepare("SELECT * FROM course_structure WHERE tenant_id = ? ORDER BY id ASC");
$stmt->execute([$tenant_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active Selected Course
$selected_course_id = (int)($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$current_course = null;
foreach ($courses as $c) {
    if ((int)$c['id'] === $selected_course_id) {
        $current_course = $c;
        break;
    }
}

// Active Selected Year
$selected_year_no = max(1, (int)($_GET['year_no'] ?? 1));

// Fetch Subjects for Current Course & Year
$subjects = [];
if ($current_course) {
    $stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND tenant_id = ? AND year_no = ? ORDER BY subject_order ASC, id ASC");
    $stmt->execute([$current_course['id'], $tenant_id, $selected_year_no]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Default traditional names for Darse Nizami years
$darse_nizami_year_names = [
    1 => __('سال اول (درجہ اولیٰ)', 'Year 1 (Ula)'),
    2 => __('سال دوم (درجہ ثانیہ)', 'Year 2 (Sania)'),
    3 => __('سال سوم (درجہ ثالثہ)', 'Year 3 (Salisa)'),
    4 => __('سال چہارم (درجہ رابعہ)', 'Year 4 (Rabia)'),
    5 => __('سال پنجم (درجہ خامسہ)', 'Year 5 (Khamisa)'),
    6 => __('سال ششم (درجہ سادسہ)', 'Year 6 (Sadisa)'),
    7 => __('سال ہفتم (درجہ عالیہ)', 'Year 7 (Aliya)'),
    8 => __('سال ہشتم (درجہ عالمیہ / دورۂ حدیث)', 'Year 8 (Almiya / Hadith)'),
    9 => __('سال نہم (تخصص فی الفقہ / الافتاء)', 'Year 9 (Takhassus Fiqh)'),
    10 => __('سال دہم (تخصص فی الحدیث)', 'Year 10 (Takhassus Hadith)')
];

include 'header.php';
?>

<div class="container-fluid py-4" dir="<?php echo ($lang === 'ur') ? 'rtl' : 'ltr'; ?>">
    <!-- Header Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-primary text-white p-4" style="background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fas fa-book-reader me-2"></i> <?php echo __('درس نظامی و عصری علوم — کورس و نصاب مینیجر', 'Dars-e-Nizami & Contemporary Studies — Course Manager'); ?></h2>
                <p class="mb-0 text-white-50"><?php echo __('ادارے کے تمام تعلیمی کورسز، سالانہ درجات، کتب اور مضامین کا باضابطہ انتظام', 'Formal management of all educational courses, grades, books and subjects'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning text-dark fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#newCourseModal">
                    <i class="fas fa-plus-circle me-1"></i> <?php echo __('نیا کورس شامل کریں', 'Add New Course'); ?>
                </button>
                <a href="academic_results_entry.php" class="btn btn-outline-light rounded-pill px-3">
                    <i class="fas fa-clipboard-check me-1"></i> <?php echo __('نتائج انٹری', 'Results Entry'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm fw-bold" role="alert">
            <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm fw-bold" role="alert">
            <?php echo htmlspecialchars($err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Courses Sidebar List -->
        <div class="col-lg-3 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-layer-group me-2"></i> <?php echo __('تعلیمی شعبہ جات / کورسز', 'Educational Departments / Courses'); ?></span>
                    <span class="badge bg-primary rounded-pill"><?php echo count($courses); ?></span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (empty($courses)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-graduation-cap fa-3x mb-3 text-secondary"></i>
                            <p class="mb-0"><?php echo __('کوئی کورس موجود نہیں۔ اوپر دیے گئے بٹن سے نیا کورس شامل کریں۔', 'No course found. Add a new course using the button above.'); ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($courses as $c): ?>
                            <?php $isActiveCourse = ($c['id'] == $selected_course_id); ?>
                            <a href="course_manager.php?course_id=<?php echo $c['id']; ?>" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center <?php echo $isActiveCourse ? 'active' : ''; ?>" style="<?php echo $isActiveCourse ? 'background-color: #2d6a4f; border-color: #2d6a4f;' : ''; ?>">
                                <div>
                                    <div class="fw-bold fs-6"><?php echo htmlspecialchars($c['name']); ?></div>
                                    <small class="<?php echo $isActiveCourse ? 'text-white-50' : 'text-muted'; ?>">
                                        <?php echo ($c['gender'] ?? 'بنین'); ?> • <?php echo (int)$c['total_years']; ?> سالہ کورس
                                    </small>
                                </div>
                                <i class="fas fa-chevron-left <?php echo $isActiveCourse ? 'text-white' : 'text-muted'; ?>"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($current_course): ?>
                <!-- Current Course Details & Action Box -->
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-light">
                    <h6 class="fw-bold text-dark border-bottom pb-2"><i class="fas fa-info-circle me-1"></i> <?php echo __('منتخب کورس کی تفصیلات', 'Selected Course Details'); ?></h6>
                    <p class="mb-1"><strong><?php echo __('کورس:', 'Course:'); ?></strong> <?php echo htmlspecialchars($current_course['name']); ?></p>
                    <p class="mb-1"><strong><?php echo __('نوعیت:', 'Type:'); ?></strong> <?php echo htmlspecialchars($current_course['course_type']); ?></p>
                    <p class="mb-1"><strong><?php echo __('کل درجات/سال:', 'Total Years:'); ?></strong> <?php echo (int)$current_course['total_years']; ?> <?php echo __('سال', 'Years'); ?></p>
                    <p class="mb-2"><strong><?php echo __('صنف:', 'Gender:'); ?></strong> <?php echo htmlspecialchars($current_course['gender']); ?></p>
                    <?php if (!empty($current_course['description'])): ?>
                        <p class="small text-muted mb-3"><?php echo nl2br(htmlspecialchars($current_course['description'])); ?></p>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary flex-fill rounded-pill" data-bs-toggle="modal" data-bs-target="#editCourseModal">
                            <i class="fas fa-edit me-1"></i> <?php echo __('ترمیم', 'Edit'); ?>
                        </button>
                        <form method="POST" onsubmit="return confirm('کیا آپ واقعی یہ کورس اور اس کے تمام مضامین حذف کرنا چاہتے ہیں؟');" class="flex-fill">
                            <input type="hidden" name="action" value="delete_course">
                            <input type="hidden" name="course_id" value="<?php echo $current_course['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100 rounded-pill">
                                <i class="fas fa-trash me-1"></i> <?php echo __('حذف', 'Delete'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Main Content Area: Years & Subjects Setup -->
        <div class="col-lg-9 col-md-8">
            <?php if (!$current_course): ?>
                <div class="card border-0 shadow-sm rounded-4 p-5 text-center">
                    <i class="fas fa-book fa-4x text-muted mb-3"></i>
                    <h4 class="fw-bold"><?php echo __('کوئی کورس منتخب نہیں ہے', 'No Course Selected'); ?></h4>
                    <p class="text-muted"><?php echo __('براہ کرم سائیڈ بار سے کوئی کورس منتخب کریں یا نیا کورس بنا کر سیٹ اپ شروع کریں۔', 'Please select a course from the sidebar or create a new course to start setup.'); ?></p>
                </div>
            <?php else: ?>
                <!-- Years Navigation Tabs -->
                <div class="card border-0 shadow-sm rounded-4 p-3 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <h5 class="fw-bold text-success mb-0">
                            <i class="fas fa-calendar-alt me-2"></i> <?php echo __('درجات و سالانہ تقسیم', 'Grades & Annual Distribution'); ?> (<?php echo __('سال 1 تا', 'Year 1 to'); ?> <?php echo (int)$current_course['total_years']; ?>)
                        </h5>
                        <button type="button" class="btn btn-success btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#newSubjectModal">
                            <i class="fas fa-plus me-1"></i> <?php echo __('اس سال میں نیا مضمون شامل کریں', 'Add New Subject in This Year'); ?>
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                        <?php for ($y = 1; $y <= (int)$current_course['total_years']; $y++): ?>
                            <?php 
                                $isYearActive = ($y == $selected_year_no);
                                $tabTitle = $darse_nizami_year_names[$y] ?? "سال {$y}";
                            ?>
                            <a href="course_manager.php?course_id=<?php echo $current_course['id']; ?>&year_no=<?php echo $y; ?>" 
                               class="btn <?php echo $isYearActive ? 'btn-success fw-bold text-white shadow-sm' : 'btn-outline-secondary'; ?> rounded-pill px-3 py-2 text-decoration-none">
                                <?php echo $tabTitle; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Subjects Table for Active Year -->
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">
                                <i class="fas fa-books me-2 text-warning"></i> 
                                <?php echo __('مضامین و کتب برائے:', 'Subjects & Books for:'); ?> <span class="text-success"><?php echo $darse_nizami_year_names[$selected_year_no] ?? "Year {$selected_year_no}"; ?></span>
                            </h5>
                            <small class="text-muted"><?php echo __('اس سال کے تمام مضامین امتحانات، رزلٹ انٹری اور نتیجہ نامہ کارڈ میں خودکار طور پر ظاہر ہوں گے۔', 'All subjects for this year will appear automatically in exams, result entry and report cards.'); ?></small>
                        </div>
                        <span class="badge bg-secondary rounded-pill px-3 py-2 fs-6"><?php echo __('کل مضامین:', 'Total Subjects:'); ?> <?php echo count($subjects); ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 60px;"><?php echo __('ترتیب', 'Order'); ?></th>
                                    <th class="text-end"><?php echo __('مضمون کا نام', 'Subject Name'); ?></th>
                                    <th class="text-end"><?php echo __('کتاب کا نام', 'Book Name'); ?></th>
                                    <th><?php echo __('کل نمبر (Max)', 'Total Marks (Max)'); ?></th>
                                    <th><?php echo __('پاسنگ نمبر', 'Passing Marks'); ?></th>
                                    <th><?php echo __('نوعیت', 'Type'); ?></th>
                                    <th><?php echo __('ایکشنز', 'Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($subjects)): ?>
                                    <tr>
                                        <td colspan="7" class="py-5 text-muted">
                                            <i class="fas fa-file-alt fa-3x mb-2 text-black-50"></i>
                                            <p class="mb-0">اس سال میں تاحال کوئی مضمون شامل نہیں کیا گیا۔</p>
                                            <button type="button" class="btn btn-sm btn-success rounded-pill mt-2" data-bs-toggle="modal" data-bs-target="#newSubjectModal">
                                                <i class="fas fa-plus me-1"></i> پہلا مضمون شامل کریں
                                            </button>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($subjects as $s): ?>
                                        <tr>
                                            <td class="fw-bold text-muted"><?php echo (int)$s['subject_order']; ?></td>
                                            <td class="text-end fw-bold text-dark fs-6"><?php echo htmlspecialchars($s['subject_name']); ?></td>
                                            <td class="text-end text-muted"><?php echo !empty($s['book_name']) ? htmlspecialchars($s['book_name']) : '—'; ?></td>
                                            <td><span class="badge bg-light text-dark border px-2 py-1"><?php echo (float)$s['max_marks']; ?></span></td>
                                            <td><span class="badge bg-light text-danger border px-2 py-1"><?php echo (float)$s['pass_marks']; ?></span></td>
                                            <td>
                                                <?php if (!empty($s['is_optional'])): ?>
                                                    <span class="badge bg-warning text-dark rounded-pill">اختیاری</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success rounded-pill">لازمی</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" onclick="openEditSubjectModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('کیا آپ یہ مضمون حذف کرنا چاہتے ہیں؟');" style="display:inline;">
                                                        <input type="hidden" name="action" value="delete_subject">
                                                        <input type="hidden" name="subject_id" value="<?php echo $s['id']; ?>">
                                                        <input type="hidden" name="course_id" value="<?php echo $current_course['id']; ?>">
                                                        <input type="hidden" name="year_no" value="<?php echo $selected_year_no; ?>">
                                                        <button type="submit" class="btn btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: New Course -->
<div class="modal fade" id="newCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="add_course">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-graduation-cap me-2"></i> نیا تعلیمی کورس شامل کریں</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کورس کا نام (لازمی)</label>
                        <input type="text" name="name" class="form-control rounded-3" placeholder="مثلاً: درس نظامی بنین، درس نظامی بنات، عصری علوم" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">کورس کی قسم</label>
                            <select name="course_type" class="form-select rounded-3">
                                <option value="Darse_Nizami">درس نظامی</option>
                                <option value="Asri">عصری علوم</option>
                                <option value="Hifz">حفظ و تجوید</option>
                                <option value="Other">دیگر تخصص / شارٹ کورس</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">کل درجات / سال</label>
                            <input type="number" name="total_years" class="form-control rounded-3" value="8" min="1" max="16" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">صنف (Gender)</label>
                        <select name="gender" class="form-select rounded-3">
                            <option value="بنین">بنین (طلبہ)</option>
                            <option value="بنات">بنات (طالبات)</option>
                            <option value="مشترک">مشترک</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مختصر تفصیل / تعارف (اختیاری)</label>
                        <textarea name="description" class="form-control rounded-3" rows="2" placeholder="کورس کی اہداف و مقاصد"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Course -->
<?php if ($current_course): ?>
<div class="modal fade" id="editCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="edit_course">
                <input type="hidden" name="course_id" value="<?php echo $current_course['id']; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> کورس کی تفصیلات میں ترمیم</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کورس کا نام</label>
                        <input type="text" name="name" class="form-control rounded-3" value="<?php echo htmlspecialchars($current_course['name']); ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">کورس کی قسم</label>
                            <select name="course_type" class="form-select rounded-3">
                                <option value="Darse_Nizami" <?php echo $current_course['course_type']=='Darse_Nizami'?'selected':''; ?>>درس نظامی</option>
                                <option value="Asri" <?php echo $current_course['course_type']=='Asri'?'selected':''; ?>>عصری علوم</option>
                                <option value="Hifz" <?php echo $current_course['course_type']=='Hifz'?'selected':''; ?>>حفظ و تجوید</option>
                                <option value="Other" <?php echo $current_course['course_type']=='Other'?'selected':''; ?>>دیگر تخصص</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">کل درجات / سال</label>
                            <input type="number" name="total_years" class="form-control rounded-3" value="<?php echo (int)$current_course['total_years']; ?>" min="1" max="16" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">صنف</label>
                        <select name="gender" class="form-select rounded-3">
                            <option value="بنین" <?php echo $current_course['gender']=='بنین'?'selected':''; ?>>بنین (طلبہ)</option>
                            <option value="بنات" <?php echo $current_course['gender']=='بنات'?'selected':''; ?>>بنات (طالبات)</option>
                            <option value="مشترک" <?php echo $current_course['gender']=='مشترک'?'selected':''; ?>>مشترک</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تفصیل</label>
                        <textarea name="description" class="form-control rounded-3" rows="2"><?php echo htmlspecialchars($current_course['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="courseActiveCheck" <?php echo !empty($current_course['is_active'])?'checked':''; ?>>
                        <label class="form-check-label fw-bold" for="courseActiveCheck">کورس فعال ہے</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">اپڈیٹ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: New Subject -->
<?php if ($current_course): ?>
<div class="modal fade" id="newSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="add_subject">
                <input type="hidden" name="course_id" value="<?php echo $current_course['id']; ?>">
                <input type="hidden" name="year_no" value="<?php echo $selected_year_no; ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-book me-2"></i> نیا مضمون شامل کریں — <?php echo $darse_nizami_year_names[$selected_year_no] ?? "سال {$selected_year_no}"; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">درجہ / سال کا عنوان (اختیاری)</label>
                        <input type="text" name="year_title" class="form-control rounded-3" value="<?php echo htmlspecialchars($darse_nizami_year_names[$selected_year_no] ?? "سال {$selected_year_no}"); ?>" placeholder="مثلاً: درجہ اولیٰ">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مضمون کا نام (لازمی)</label>
                        <input type="text" name="subject_name" class="form-control rounded-3" placeholder="مثلاً: صرف، نحو، فقہ، ترجمہ قرآن، ریاضی، انگریزی" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مقررہ کتاب کا نام (اختیاری)</label>
                        <input type="text" name="book_name" class="form-control rounded-3" placeholder="مثلاً: علم الصیغہ، ہدایۃ النحو، نور الایضاح">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">کل نمبر (Max Marks)</label>
                            <input type="number" step="0.5" name="max_marks" class="form-control rounded-3" value="100" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">پاسنگ نمبر (Pass Marks)</label>
                            <input type="number" step="0.5" name="pass_marks" class="form-control rounded-3" value="40" required>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">ترتیب نمبر</label>
                            <input type="number" name="subject_order" class="form-control rounded-3" value="<?php echo count($subjects) + 1; ?>">
                        </div>
                        <div class="col-6 d-flex align-items-center mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_optional" id="optCheck">
                                <label class="form-check-label fw-bold" for="optCheck">اختیاری مضمون ہے</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">شامل کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Edit Subject -->
<?php if ($current_course): ?>
<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="edit_subject">
                <input type="hidden" name="subject_id" id="edit_subject_id">
                <input type="hidden" name="course_id" value="<?php echo $current_course['id']; ?>">
                <input type="hidden" name="year_no" value="<?php echo $selected_year_no; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> مضمون کی تفصیلات میں ترمیم</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">سال / درجہ کا عنوان</label>
                        <input type="text" name="year_title" id="edit_year_title" class="form-control rounded-3">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مضمون کا نام</label>
                        <input type="text" name="subject_name" id="edit_subject_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مقررہ کتاب کا نام</label>
                        <input type="text" name="book_name" id="edit_book_name" class="form-control rounded-3">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">کل نمبر (Max Marks)</label>
                            <input type="number" step="0.5" name="max_marks" id="edit_max_marks" class="form-control rounded-3" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">پاسنگ نمبر (Pass Marks)</label>
                            <input type="number" step="0.5" name="pass_marks" id="edit_pass_marks" class="form-control rounded-3" required>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">ترتیب نمبر</label>
                            <input type="number" name="subject_order" id="edit_subject_order" class="form-control rounded-3">
                        </div>
                        <div class="col-6 d-flex align-items-center mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_optional" id="edit_is_optional">
                                <label class="form-check-label fw-bold" for="edit_is_optional">اختیاری مضمون ہے</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">اپڈیٹ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openEditSubjectModal(sub) {
    document.getElementById('edit_subject_id').value = sub.id;
    document.getElementById('edit_year_title').value = sub.year_title || '';
    document.getElementById('edit_subject_name').value = sub.subject_name || '';
    document.getElementById('edit_book_name').value = sub.book_name || '';
    document.getElementById('edit_max_marks').value = sub.max_marks || 100;
    document.getElementById('edit_pass_marks').value = sub.pass_marks || 40;
    document.getElementById('edit_subject_order').value = sub.subject_order || 0;
    document.getElementById('edit_is_optional').checked = (parseInt(sub.is_optional) === 1);
    
    var myModal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    myModal.show();
}
</script>

<?php include 'footer.php'; ?>
