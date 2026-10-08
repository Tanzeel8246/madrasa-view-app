<?php
require_once 'db.php';
include 'header.php';
$cur_tenant_id = (int)get_current_tenant_id();

// Ensure Phase 1 tables
if (function_exists('ensure_phase1_tables')) {
    ensure_phase1_tables($pdo);
}

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$can_manage = ($role === 'admin' || $role === 'nazim' || app_has_perm('manage_teachers') || app_has_perm('manage_teacher_attendance'));
$is_teacher = ($role === 'teacher');

if (!$can_manage && !$is_teacher) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

// Find teacher_id if logged in as teacher
$logged_teacher_id = 0;
if ($is_teacher) {
    try {
        $st = $pdo->prepare("SELECT id FROM teachers WHERE (user_id = ? OR id = ?) AND tenant_id = ? LIMIT 1");
        $st->execute([$user_id, $user_id, $cur_tenant_id]);
        $logged_teacher_id = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}

$success_msg = '';
$error_msg = '';

// Handle Status Change (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_status']) && $can_manage) {
    $leave_id = (int)($_POST['leave_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    if ($leave_id > 0 && in_array($new_status, ['Approved', 'Rejected', 'Pending'])) {
        $approved_by = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? ($_SESSION['role'] ?? 'Admin'));
        if (function_exists('approve_teacher_leave')) {
            $res = approve_teacher_leave($pdo, $leave_id, $new_status, $approved_by);
            if ($res) {
                $success_msg = ($new_status === 'Approved') ? "رخصت کامیابی سے منظور کر لی گئی اور حاضری رجسٹر میں خودکار اندراج ہو گیا۔" : "رخصت کا اسٹیٹس تبدیل ہو گیا۔";
            } else {
                $error_msg = "اسٹیٹس تبدیل کرنے میں خرابی پیش آئی۔";
            }
        }
    }
}

// Handle Delete Leave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_leave']) && $can_manage) {
    $leave_id = (int)($_POST['leave_id'] ?? 0);
    if ($leave_id > 0) {
        try {
            $pdo->prepare("UPDATE teacher_leaves SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$leave_id, $cur_tenant_id]);
            $success_msg = "رخصت کا ریکارڈ حذف کر دیا گیا۔";
            if (function_exists('log_activity')) {
                log_activity($pdo, 'leave', "رخصت کا ریکارڈ (ID: {$leave_id}) حذف کیا گیا");
            }
        } catch (Throwable $e) {
            $error_msg = "حذف کرنے میں خرابی پیش آئی: " . $e->getMessage();
        }
    }
}

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$leave_sys_cond = ($sys === 'Masjid') ? "l.system_type = 'Masjid'" : "(l.system_type = 'Madrasa' OR l.system_type IS NULL OR l.system_type = '')";

// Handle New Leave Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    $teacher_id = $is_teacher ? $logged_teacher_id : (int)($_POST['teacher_id'] ?? 0);
    $leave_type = trim($_POST['leave_type'] ?? 'اتفاقی');
    $start_date = trim($_POST['start_date'] ?? date('Y-m-d'));
    $end_date = trim($_POST['end_date'] ?? $start_date);
    $reason = trim($_POST['reason'] ?? '');
    // Auto-approve if admin/nazim is creating it with 'Approved' selected
    $init_status = ($can_manage && isset($_POST['auto_approve']) && $_POST['auto_approve'] == '1') ? 'Approved' : 'Pending';

    if ($teacher_id <= 0) {
        $error_msg = "براہ کرم استاذ کا انتخاب کریں۔";
    } elseif (empty($start_date) || empty($end_date)) {
        $error_msg = "تاریخ کا اندراج لازمی ہے۔";
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $error_msg = "اختتامی تاریخ ابتدائی تاریخ سے پہلے نہیں ہو سکتی۔";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO teacher_leaves (teacher_id, leave_type, start_date, end_date, reason, status, approved_by, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $approved_by_val = ($init_status === 'Approved') ? ($_SESSION['user_name'] ?? 'Admin') : null;
            $stmt->execute([$teacher_id, $leave_type, $start_date, $end_date, $reason, $init_status, $approved_by_val, $sys, $cur_tenant_id]);
            $new_id = $pdo->lastInsertId();

            if ($init_status === 'Approved' && function_exists('approve_teacher_leave')) {
                approve_teacher_leave($pdo, $new_id, 'Approved', $approved_by_val);
            } else {
                if (function_exists('log_activity')) {
                    $tName = 'استاذ';
                    try {
                        if ($sys === 'Masjid') {
                            $tn = $pdo->prepare("SELECT name FROM maktab_teachers WHERE id = ? AND tenant_id = ?");
                            $tn->execute([$teacher_id, $cur_tenant_id]);
                            $tName = $tn->fetchColumn();
                        }
                        if (empty($tName)) {
                            $tn = $pdo->prepare("SELECT name FROM teachers WHERE id = ? AND tenant_id = ?");
                            $tn->execute([$teacher_id, $cur_tenant_id]);
                            $tName = $tn->fetchColumn() ?: 'استاذ';
                        }
                    } catch (Throwable $e) {}
                    log_activity($pdo, 'leave', "استاذ محترم {$tName} کی رخصت کی درخواست درج کی گئی ({$sys} - از {$start_date} تا {$end_date})");
                }
            }

            $success_msg = "رخصت کی درخواست کامیابی کے ساتھ جمع ہو گئی ہے۔";
        } catch (Throwable $e) {
            $error_msg = "درخواست درج کرنے میں خرابی: " . $e->getMessage();
        }
    }
}

// Fetch Teachers for Dropdown based on system_type
$all_teachers = [];
try {
    if ($sys === 'Masjid') {
        $all_teachers = $pdo->query("
            SELECT id, name, phone FROM (
                SELECT id, name, phone FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' AND tenant_id = {$cur_tenant_id}
                UNION
                SELECT id, name, phone FROM maktab_teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = {$cur_tenant_id}
            ) combined ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $all_teachers = $pdo->query("SELECT id, name, phone FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND tenant_id = {$cur_tenant_id} ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Filters
$filter_teacher = (int)($_GET['filter_teacher'] ?? ($is_teacher ? $logged_teacher_id : 0));
$filter_status = trim($_GET['filter_status'] ?? '');
$filter_from = trim($_GET['filter_from'] ?? '');
$filter_to = trim($_GET['filter_to'] ?? '');

$where = ["(l.is_deleted = 0 OR l.is_deleted IS NULL)", $leave_sys_cond, "l.tenant_id = {$cur_tenant_id}"];
$params = [];

if ($is_teacher) {
    $where[] = "l.teacher_id = ?";
    $params[] = $logged_teacher_id;
} elseif ($filter_teacher > 0) {
    $where[] = "l.teacher_id = ?";
    $params[] = $filter_teacher;
}

if (!empty($filter_status)) {
    $where[] = "l.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_from)) {
    $where[] = "l.end_date >= ?";
    $params[] = $filter_from;
}

if (!empty($filter_to)) {
    $where[] = "l.start_date <= ?";
    $params[] = $filter_to;
}

$where_sql = implode(' AND ', $where);

// Summary Counts
$total_count = 0;
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
try {
    $base_c = "SELECT COUNT(*) FROM teacher_leaves l WHERE (l.is_deleted = 0 OR l.is_deleted IS NULL) AND {$leave_sys_cond} AND l.tenant_id = {$cur_tenant_id}" . ($is_teacher ? " AND l.teacher_id = {$logged_teacher_id}" : "");
    $total_count = (int)$pdo->query($base_c)->fetchColumn();
    $pending_count = (int)$pdo->query($base_c . " AND l.status = 'Pending'")->fetchColumn();
    $approved_count = (int)$pdo->query($base_c . " AND l.status = 'Approved'")->fetchColumn();
    $rejected_count = (int)$pdo->query($base_c . " AND l.status = 'Rejected'")->fetchColumn();
} catch (Throwable $e) {}

// Fetch List
$leaves_list = [];
try {
    $sql = "SELECT l.*, COALESCE(t.name, mt.name, 'استاذ') as teacher_name, COALESCE(t.phone, mt.phone, '') as teacher_phone 
            FROM teacher_leaves l 
            LEFT JOIN teachers t ON l.teacher_id = t.id 
            LEFT JOIN maktab_teachers mt ON l.teacher_id = mt.id
            WHERE {$where_sql} 
            ORDER BY l.id DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaves_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<div class="container-fluid py-3">
    <!-- عنوان اور ہیڈر -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1 text-primary">
                <i class="fas fa-calendar-minus me-2"></i>اساتذہ کی رخصت (Teacher Leave Management)
            </h3>
            <p class="text-muted mb-0">اساتذہ کی چھٹیوں کا باقاعدہ اندراج، منظوری اور خودکار حاضری انضمام</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#newLeaveModal">
                <i class="fas fa-plus-circle me-1"></i> نئی رخصت کا اندراج
            </button>
        </div>
    </div>

    <!-- پیغامات -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- شماریاتی کارڈز (Stats Cards) -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-primary border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted d-block small fw-bold">کل درخواستیں</span>
                        <h4 class="fw-bold mb-0 text-primary mt-1"><?php echo $total_count; ?></h4>
                    </div>
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary">
                        <i class="fas fa-list-ul fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-warning border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted d-block small fw-bold">زیرِ غور (Pending)</span>
                        <h4 class="fw-bold mb-0 text-warning mt-1"><?php echo $pending_count; ?></h4>
                    </div>
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3 text-warning">
                        <i class="fas fa-clock fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted d-block small fw-bold">منظور شدہ (Approved)</span>
                        <h4 class="fw-bold mb-0 text-success mt-1"><?php echo $approved_count; ?></h4>
                    </div>
                    <div class="rounded-circle bg-success bg-opacity-10 p-3 text-success">
                        <i class="fas fa-check-double fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-danger border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted d-block small fw-bold">مسترد شدہ (Rejected)</span>
                        <h4 class="fw-bold mb-0 text-danger mt-1"><?php echo $rejected_count; ?></h4>
                    </div>
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3 text-danger">
                        <i class="fas fa-times-circle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- فلٹرز کارڈ -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <?php if (!$is_teacher): ?>
                <div class="col-md-3 col-12">
                    <label class="form-label small fw-bold text-muted mb-1">استاذ کا انتخاب:</label>
                    <select name="filter_teacher" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">تمام اساتذہ</option>
                        <?php foreach ($all_teachers as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($filter_teacher == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-2 col-6">
                    <label class="form-label small fw-bold text-muted mb-1">اسٹیٹس:</label>
                    <select name="filter_status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">تمام اسٹیٹس</option>
                        <option value="Pending" <?php echo ($filter_status === 'Pending') ? 'selected' : ''; ?>>زیرِ غور (Pending)</option>
                        <option value="Approved" <?php echo ($filter_status === 'Approved') ? 'selected' : ''; ?>>منظور شدہ (Approved)</option>
                        <option value="Rejected" <?php echo ($filter_status === 'Rejected') ? 'selected' : ''; ?>>مسترد شدہ (Rejected)</option>
                    </select>
                </div>

                <div class="col-md-2 col-6">
                    <label class="form-label small fw-bold text-muted mb-1">تاریخ (از):</label>
                    <input type="date" name="filter_from" id="leaveFrom" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_from); ?>" onchange="if(this.value && document.getElementById('leaveTo').value) this.form.submit();">
                </div>

                <div class="col-md-2 col-6">
                    <label class="form-label small fw-bold text-muted mb-1">تاریخ (تا):</label>
                    <input type="date" name="filter_to" id="leaveTo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_to); ?>" onchange="if(this.value && document.getElementById('leaveFrom').value) this.form.submit();">
                </div>

                <div class="col-md-3 col-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill fw-bold">
                        <i class="fas fa-filter me-1"></i> تلاش کریں
                    </button>
                    <a href="teacher_leaves.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- فہرست رخصت -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fas fa-table text-primary me-2"></i>درخواستوں کی فہرست
            </h5>
            <span class="badge bg-light text-muted border px-2 py-1">کل نتائج: <?php echo count($leaves_list); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-light text-muted">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>استاذ محترم</th>
                        <th>نوعیتِ رخصت</th>
                        <th>مدت (تاریخ از / تا)</th>
                        <th>کل ایام</th>
                        <th>وجہ</th>
                        <th>اسٹیٹس</th>
                        <th>منظور کنندہ</th>
                        <th>کارروائی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves_list)): ?>
                        <tr>
                            <td colspan="9" class="py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 text-secondary opacity-50"></i>
                                <p class="mb-0 fw-bold">کوئی درخواست نہیں ملی۔</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $idx = 1; foreach ($leaves_list as $row): 
                            $start = strtotime($row['start_date']);
                            $end = strtotime($row['end_date']);
                            $days = ($start && $end && $end >= $start) ? (floor(($end - $start) / (60*60*24)) + 1) : 1;
                        ?>
                        <tr>
                            <td class="text-muted small"><?php echo $idx++; ?></td>
                            <td class="text-start">
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($row['teacher_name'] ?? 'نامعلوم'); ?></span>
                                <?php if (!empty($row['teacher_phone'])): ?>
                                    <small class="text-muted"><i class="fas fa-phone-alt me-1 fa-xs"></i><?php echo htmlspecialchars($row['teacher_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info bg-opacity-10 text-info px-2 py-1 rounded-pill">
                                    <?php echo htmlspecialchars($row['leave_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-dark d-block"><?php echo date('d M Y', $start); ?></span>
                                <small class="text-muted">تا <?php echo date('d M Y', $end); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary rounded-pill"><?php echo $days; ?> دن</span>
                            </td>
                            <td class="text-start" style="max-width: 250px;">
                                <small class="text-secondary"><?php echo nl2br(htmlspecialchars($row['reason'] ?: '—')); ?></small>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'Approved'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success px-2 py-1 rounded-pill">
                                        <i class="fas fa-check me-1"></i>منظور شدہ
                                    </span>
                                <?php elseif ($row['status'] === 'Rejected'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1 rounded-pill">
                                        <i class="fas fa-times me-1"></i>مسترد شدہ
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1 rounded-pill">
                                        <i class="fas fa-hourglass-half me-1"></i>زیرِ غور
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted fw-bold"><?php echo htmlspecialchars($row['approved_by'] ?: '—'); ?></small>
                            </td>
                            <td>
                                <?php if ($can_manage): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-light btn-sm rounded-circle shadow-none" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                            <?php if ($row['status'] !== 'Approved'): ?>
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('کیا آپ واقعی یہ رخصت منظور کرنا چاہتے ہیں؟ حاضری میں خودکار طور پر چھٹی لگ جائے گی۔');">
                                                        <input type="hidden" name="action_status" value="1">
                                                        <input type="hidden" name="leave_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="status" value="Approved">
                                                        <button type="submit" class="dropdown-item text-success fw-bold">
                                                            <i class="fas fa-check-circle me-2"></i>منظور کریں (Approve)
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ($row['status'] !== 'Rejected'): ?>
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('کیا آپ واقعی یہ درخواست مسترد کرنا چاہتے ہیں؟');">
                                                        <input type="hidden" name="action_status" value="1">
                                                        <input type="hidden" name="leave_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="status" value="Rejected">
                                                        <button type="submit" class="dropdown-item text-danger fw-bold">
                                                            <i class="fas fa-times-circle me-2"></i>مسترد کریں (Reject)
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟');">
                                                    <input type="hidden" name="delete_leave" value="1">
                                                    <input type="hidden" name="leave_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-secondary">
                                                        <i class="fas fa-trash-alt me-2"></i>حذف کریں
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- نئی رخصت کا Modal -->
<div class="modal fade" id="newLeaveModal" tabindex="-1" aria-labelledby="newLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 bg-primary text-white">
                <h5 class="modal-title fw-bold" id="newLeaveModalLabel">
                    <i class="fas fa-calendar-plus me-2"></i>نئی رخصت کا اندراج
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="teacher_leaves.php">
                <div class="modal-body p-4">
                    <input type="hidden" name="submit_leave" value="1">

                    <?php if ($is_teacher): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">استاذ کا نام:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'آپ'); ?>" disabled>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">استاذ کا انتخاب کریں: <span class="text-danger">*</span></label>
                            <select name="teacher_id" class="form-select" required>
                                <option value="">— استاذ منتخب کریں —</option>
                                <?php foreach ($all_teachers as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">نوعیتِ رخصت (Leave Type): <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select" required>
                            <option value="اتفاقی (Casual)">اتفاقی رخصت (Casual Leave)</option>
                            <option value="طبی / بیماری (Medical)">طبی رخصت (Sick / Medical)</option>
                            <option value="ضروری کام (Personal)">ضروری کام (Personal)</option>
                            <option value="حج / عمرہ (Pilgrimage)">حج / عمرہ (Hajj / Umrah)</option>
                            <option value="دیگر (Other)">دیگر (Other)</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">تاریخ (سے): <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="modal_start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">تاریخ (تک): <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="modal_end_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">چھٹی کی وجہ / تفصیل:</label>
                        <textarea name="reason" rows="3" class="form-control" placeholder="تفصیل یا عذر بیان کریں..."></textarea>
                    </div>

                    <?php if ($can_manage): ?>
                        <div class="form-check form-switch p-0 d-flex align-items-center gap-2 mb-2">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="auto_approve" value="1" id="autoApproveCheck" checked>
                            <label class="form-check-label fw-bold small text-dark" for="autoApproveCheck">
                                فوری منظور کریں اور حاضری میں خودکار 'L' درج کریں
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 p-3 bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">منسوخ کریں</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <i class="fas fa-save me-1"></i> محفوظ کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var startDateInput = document.getElementById('modal_start_date');
    var endDateInput = document.getElementById('modal_end_date');
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (endDateInput.value < startDateInput.value) {
                endDateInput.value = startDateInput.value;
            }
            endDateInput.min = startDateInput.value;
        });
    }
});
</script>

<?php include 'footer.php'; ?>
