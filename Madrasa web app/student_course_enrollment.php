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

// Handle Enrollment Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'enroll_single') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $current_year = max(1, (int)($_POST['current_year'] ?? 1));
        $session = trim($_POST['academic_session'] ?? date('Y') . '-' . (date('Y') + 1));
        $date = trim($_POST['enrollment_date'] ?? date('Y-m-d'));
        $status = trim($_POST['status'] ?? 'active');

        if ($student_id > 0 && $course_id > 0) {
            // Deactivate previous active enrollment for this student
            $pdo->prepare("UPDATE student_course_enrollment SET status = 'inactive' WHERE student_id = ? AND tenant_id = ? AND status = 'active'")
                ->execute([$student_id, $tenant_id]);

            $stmt = $pdo->prepare("INSERT INTO student_course_enrollment (tenant_id, student_id, course_id, current_year, academic_session, enrollment_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $student_id, $course_id, $current_year, $session, $date, $status]);
            $msg = "✅ طالب علم کو کورس میں کامیابی سے داخل کر دیا گیا۔";
        } else {
            $err = "❌ طالب علم اور کورس کا انتخاب لازمی ہے۔";
        }
    }

    if ($action === 'enroll_bulk') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $current_year = max(1, (int)($_POST['current_year'] ?? 1));
        $session = trim($_POST['academic_session'] ?? date('Y') . '-' . (date('Y') + 1));
        $date = trim($_POST['enrollment_date'] ?? date('Y-m-d'));
        $student_ids = $_POST['selected_students'] ?? [];

        if ($course_id > 0 && !empty($student_ids) && is_array($student_ids)) {
            $cnt = 0;
            foreach ($student_ids as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                $pdo->prepare("UPDATE student_course_enrollment SET status = 'inactive' WHERE student_id = ? AND tenant_id = ? AND status = 'active'")
                    ->execute([$sid, $tenant_id]);

                $stmt = $pdo->prepare("INSERT INTO student_course_enrollment (tenant_id, student_id, course_id, current_year, academic_session, enrollment_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$tenant_id, $sid, $course_id, $current_year, $session, $date]);
                $cnt++;
            }
            $msg = "✅ منتخب کردہ {$cnt} طلبہ کو بیک وقت کورس میں داخل کر دیا گیا۔";
        } else {
            $err = "❌ براہ کرم کم از کم ایک طالب علم اور مطلوبہ کورس منتخب کریں۔";
        }
    }

    if ($action === 'update_enrollment') {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $current_year = max(1, (int)($_POST['current_year'] ?? 1));
        $session = trim($_POST['academic_session'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if ($eid > 0 && $course_id > 0) {
            $stmt = $pdo->prepare("UPDATE student_course_enrollment SET course_id = ?, current_year = ?, academic_session = ?, status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$course_id, $current_year, $session, $status, $eid, $tenant_id]);
            $msg = "✅ انرولمنٹ کی تفصیلات اپڈیٹ ہو گئیں۔";
        }
    }

    if ($action === 'delete_enrollment') {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        if ($eid > 0) {
            $pdo->prepare("DELETE FROM student_course_enrollment WHERE id = ? AND tenant_id = ?")
                ->execute([$eid, $tenant_id]);
            $msg = "✅ انرولمنٹ ریکارڈ حذف کر دیا گیا۔";
        }
    }
}

// Fetch Courses
$stmt = $pdo->prepare("SELECT * FROM course_structure WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$tenant_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$filter_course = (int)($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$filter_year = (int)($_GET['year_no'] ?? 0);
$filter_status = trim($_GET['status'] ?? 'active');
$search = trim($_GET['search'] ?? '');

// Build Query for Enrolled Students
$where = "e.tenant_id = :tid AND (s.is_deleted = 0 OR s.is_deleted IS NULL)";
$params = [':tid' => $tenant_id];

if ($filter_course > 0) {
    $where .= " AND e.course_id = :cid";
    $params[':cid'] = $filter_course;
}
if ($filter_year > 0) {
    $where .= " AND e.current_year = :yr";
    $params[':yr'] = $filter_year;
}
if (!empty($filter_status)) {
    $where .= " AND e.status = :st";
    $params[':st'] = $filter_status;
}
if (!empty($search)) {
    $where .= " AND (s.name LIKE :q OR s.roll_number LIKE :q OR s.father_name LIKE :q)";
    $params[':q'] = "%$search%";
}

$query = "
    SELECT e.*, s.name as student_name, s.father_name, s.roll_number, s.class_name,
           c.name as course_name, c.total_years
    FROM student_course_enrollment e
    JOIN students s ON s.id = e.student_id
    LEFT JOIN course_structure c ON c.id = e.course_id
    WHERE $where
    ORDER BY e.current_year ASC, s.roll_number ASC, s.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Un-enrolled or Active Students for the dialog
$all_students_stmt = $pdo->prepare("SELECT id, name, father_name, roll_number, class_name FROM students WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (status IS NULL OR status = '' OR status = 'Active') ORDER BY roll_number ASC, name ASC");
$all_students_stmt->execute([$tenant_id]);
$available_students = $all_students_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <!-- Header Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 text-white p-4" style="background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fas fa-user-graduate me-2"></i> <?php echo __('طلبہ کورس انرولمنٹ و سالانہ درجات رجسٹریشن', 'Student Course Enrollment & Grade Registration'); ?></h2>
                <p class="mb-0 text-white-50"><?php echo __('طلبہ کو درس نظامی، عصری علوم کے درجات اور سالانہ تعلیمی سیشن میں رجسٹر کریں', 'Register students into courses, grades and academic sessions'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning text-dark fw-bold px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#enrollSingleModal">
                    <i class="fas fa-user-plus me-1"></i> <?php echo __('انفرادی داخلہ', 'Single Enrollment'); ?>
                </button>
                <button type="button" class="btn btn-light text-success fw-bold px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#enrollBulkModal">
                    <i class="fas fa-users me-1"></i> <?php echo __('اجتماعی (Bulk) داخلہ', 'Bulk Enrollment'); ?>
                </button>
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

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold"><?php echo __('کورس منتخب کریں', 'Select Course'); ?></label>
                <select name="course_id" class="form-select rounded-3" onchange="this.form.submit()">
                    <option value="0"><?php echo __('تمام کورسز', 'All Courses'); ?></option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($filter_course == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold"><?php echo __('سال / درجہ', 'Year / Grade'); ?></label>
                <select name="year_no" class="form-select rounded-3" onchange="this.form.submit()">
                    <option value="0"><?php echo __('تمام سال / درجات', 'All Years / Grades'); ?></option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($filter_year == $i) ? 'selected' : ''; ?>>
                            <?php echo $darse_nizami_year_names[$i] ?? "سال {$i}"; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold"><?php echo __('کیفیت (Status)', 'Status'); ?></label>
                <select name="status" class="form-select rounded-3" onchange="this.form.submit()">
                    <option value=""><?php echo __('تمام کیفیات', 'All Statuses'); ?></option>
                    <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>><?php echo __('فعال (Active)', 'Active'); ?></option>
                    <option value="completed" <?php echo ($filter_status === 'completed') ? 'selected' : ''; ?>><?php echo __('مکمل شدہ (Completed)', 'Completed'); ?></option>
                    <option value="detained" <?php echo ($filter_status === 'detained') ? 'selected' : ''; ?>><?php echo __('روکا گیا (Detained)', 'Detained'); ?></option>
                    <option value="dropped" <?php echo ($filter_status === 'dropped') ? 'selected' : ''; ?>><?php echo __('خارج (Dropped)', 'Dropped'); ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold"><?php echo __('تلاش (نام، رول نمبر)', 'Search (Name, Roll No)'); ?></label>
                <input type="text" name="search" class="form-control rounded-3" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo __('نام یا رول نمبر...', 'Name or Roll No...'); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-success w-100 rounded-3"><i class="fas fa-search"></i></button>
            </div>
        </form>
    </div>

    <!-- Enrollments Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-list-check me-2 text-success"></i> <?php echo __('داخلہ یافتہ طلبہ کی فہرست', 'Enrolled Students List'); ?></h5>
            <span class="badge bg-primary rounded-pill px-3 py-2 fs-6"><?php echo __('کل تعداد:', 'Total Count:'); ?> <?php echo count($enrollments); ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-light">
                    <tr>
                        <th style="width: 70px;"><?php echo __('رول نمبر', 'Roll No'); ?></th>
                        <th class="text-end"><?php echo __('طالب علم کا نام', 'Student Name'); ?></th>
                        <th class="text-end"><?php echo __('ولدیت', 'Father Name'); ?></th>
                        <th><?php echo __('کورس', 'Course'); ?></th>
                        <th><?php echo __('موجودہ سال / درجہ', 'Current Year / Grade'); ?></th>
                        <th><?php echo __('تعلیمی سیشن', 'Academic Session'); ?></th>
                        <th><?php echo __('داخلہ تاریخ', 'Enrollment Date'); ?></th>
                        <th><?php echo __('حیثیت', 'Status'); ?></th>
                        <th><?php echo __('ایکشنز', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($enrollments)): ?>
                        <tr>
                            <td colspan="9" class="py-5 text-muted">
                                <i class="fas fa-user-slash fa-3x mb-2 text-black-50"></i>
                                <p class="mb-0"><?php echo __('اس فلٹر کے مطابق کوئی طالب علم داخل نہیں ہے۔', 'No students enrolled matching this filter.'); ?></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($enrollments as $e): ?>
                            <tr>
                                <td class="fw-bold text-success"><?php echo htmlspecialchars($e['roll_number'] ?? '—'); ?></td>
                                <td class="text-end fw-bold text-dark fs-6"><?php echo htmlspecialchars($e['student_name']); ?></td>
                                <td class="text-end text-muted"><?php echo htmlspecialchars($e['father_name'] ?? '—'); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($e['course_name'] ?? '—'); ?></span></td>
                                <td class="fw-bold text-primary">
                                    <?php echo $darse_nizami_year_names[$e['current_year']] ?? "سال {$e['current_year']}"; ?>
                                </td>
                                <td><?php echo htmlspecialchars($e['academic_session'] ?? '—'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($e['enrollment_date'] ?? '—'); ?></small></td>
                                <td>
                                    <?php if ($e['status'] === 'active'): ?>
                                        <span class="badge bg-success rounded-pill"><?php echo __('فعال', 'Active'); ?></span>
                                    <?php elseif ($e['status'] === 'completed'): ?>
                                        <span class="badge bg-info text-dark rounded-pill"><?php echo __('فارغ التحصیل', 'Graduated'); ?></span>
                                    <?php elseif ($e['status'] === 'detained'): ?>
                                        <span class="badge bg-warning text-dark rounded-pill"><?php echo __('روکا گیا', 'Detained'); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($e['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="openEditEnrollmentModal(<?php echo htmlspecialchars(json_encode($e)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" onsubmit="return confirm('کیا آپ یہ انرولمنٹ ریکارڈ حذف کرنا چاہتے ہیں؟');" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_enrollment">
                                            <input type="hidden" name="enrollment_id" value="<?php echo $e['id']; ?>">
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
</div>

<!-- Modal: Single Student Enrollment -->
<div class="modal fade" id="enrollSingleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="enroll_single">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i> طالب علم کا کورس میں داخلہ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">طالب علم منتخب کریں (لازمی)</label>
                        <select name="student_id" class="form-select rounded-3" required>
                            <option value="">طالب علم منتخب کریں...</option>
                            <?php foreach ($available_students as $st): ?>
                                <option value="<?php echo $st['id']; ?>">
                                    <?php echo htmlspecialchars($st['name']); ?> (رول: <?php echo $st['roll_number']; ?> - والد: <?php echo $st['father_name']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo __('کورس منتخب کریں', 'Select Course'); ?></label>
                        <select name="course_id" class="form-select rounded-3" required>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['gender']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo __('سال / درجہ', 'Year / Grade'); ?></label>
                            <select name="current_year" class="form-select rounded-3" required>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $darse_nizami_year_names[$i] ?? "سال {$i}"; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">تعلیمی سیشن</label>
                            <input type="text" name="academic_session" class="form-control rounded-3" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" placeholder="مثلاً: 2025-2026">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">داخلہ تاریخ</label>
                            <input type="date" name="enrollment_date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">حیثیت (Status)</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active">فعال (Active)</option>
                                <option value="detained">روکا گیا (Detained)</option>
                                <option value="completed">مکمل (Completed)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">داخل کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Bulk Student Enrollment -->
<div class="modal fade" id="enrollBulkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="enroll_bulk">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-users me-2"></i> پوری کلاس / متعدد طلبہ کا اکٹھا داخلہ (Bulk)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">مطلوبہ کورس</label>
                            <select name="course_id" class="form-select rounded-3" required>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">کس سال / درجے میں؟</label>
                            <select name="current_year" class="form-select rounded-3" required>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $darse_nizami_year_names[$i] ?? "سال {$i}"; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تعلیمی سیشن</label>
                            <input type="text" name="academic_session" class="form-control rounded-3" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-bold mb-0">داخلے کے لیے طلبہ منتخب کریں:</label>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(true)">سب کو منتخب کریں</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(false)">انتخاب ختم کریں</button>
                        </div>
                    </div>

                    <div class="border rounded-3 p-2" style="max-height: 280px; overflow-y: auto;">
                        <div class="row g-2">
                            <?php foreach ($available_students as $st): ?>
                                <div class="col-md-6">
                                    <div class="form-check p-2 border rounded-2 bg-white">
                                        <input class="form-check-input bulk-chk" type="checkbox" name="selected_students[]" value="<?php echo $st['id']; ?>" id="st_<?php echo $st['id']; ?>">
                                        <label class="form-check-label w-100" for="st_<?php echo $st['id']; ?>">
                                            <span class="fw-bold"><?php echo htmlspecialchars($st['name']); ?></span>
                                            <small class="text-muted d-block">رول: <?php echo $st['roll_number']; ?> • کلاس: <?php echo $st['class_name'] ?? '—'; ?></small>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">منتخب طلبہ داخل کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Enrollment -->
<div class="modal fade" id="editEnrollmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="update_enrollment">
                <input type="hidden" name="enrollment_id" id="edit_eid">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> انرولمنٹ ریکارڈ میں ترمیم</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">طالب علم</label>
                        <input type="text" id="edit_sname" class="form-control rounded-3" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کورس</label>
                        <select name="course_id" id="edit_cid" class="form-select rounded-3" required>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold"><?php echo __('سال / درجہ', 'Year / Grade'); ?></label>
                            <select name="current_year" id="edit_cyear" class="form-select rounded-3" required>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $darse_nizami_year_names[$i] ?? "سال {$i}"; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">تعلیمی سیشن</label>
                            <input type="text" name="academic_session" id="edit_session" class="form-control rounded-3">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">حیثیت (Status)</label>
                        <select name="status" id="edit_status" class="form-select rounded-3">
                            <option value="active">فعال (Active)</option>
                            <option value="detained">روکا گیا (Detained)</option>
                            <option value="completed">مکمل (Completed)</option>
                            <option value="dropped">خارج (Dropped)</option>
                        </select>
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

<script>
function toggleSelectAll(select) {
    document.querySelectorAll('.bulk-chk').forEach(function(chk) {
        chk.checked = select;
    });
}

function openEditEnrollmentModal(e) {
    document.getElementById('edit_eid').value = e.id;
    document.getElementById('edit_sname').value = e.student_name + ' (رول: ' + (e.roll_number || '') + ')';
    document.getElementById('edit_cid').value = e.course_id;
    document.getElementById('edit_cyear').value = e.current_year;
    document.getElementById('edit_session').value = e.academic_session || '';
    document.getElementById('edit_status').value = e.status || 'active';

    var myModal = new bootstrap.Modal(document.getElementById('editEnrollmentModal'));
    myModal.show();
}
</script>

<?php include 'footer.php'; ?>
