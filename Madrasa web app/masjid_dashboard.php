<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

$cur_tenant_id = (int)get_current_tenant_id();
if (function_exists('is_module_allowed_for_tenant') && !is_module_allowed_for_tenant($pdo, 'masjid_wing', $cur_tenant_id)) {
    $_SESSION['system_type'] = 'Madrasa';
    header("Location: dashboard.php");
    exit;
}

$sys = $_SESSION['system_type'] ?? 'Madrasa';
if ($sys !== 'Masjid') {
    header("Location: dashboard.php");
    exit;
}

if (empty($_SESSION['user_id']) && function_exists('madrasa_restore_session_from_cookie')) {
    madrasa_restore_session_from_cookie($pdo);
}
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$title = "مسجد ڈیش بورڈ (Masjid Management)";
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$today = date('Y-m-d');
$first_day = date('Y-m-01');

$total_students = 0;
$total_teachers = 0;
$total_classes = 0;
$monthly_expense = 0;
$present_today_pct = 0;
$absent_today_pct = 0;
$leave_today_pct = 0;
$late_today_pct = 0;
$total_assets = 0;
$total_itikaf = 0;

$classes_all = [];
$marked_class_names = [];
$remaining_classes = [];
$done_classes = [];
$t_today_present = 0;
$t_today_leave = 0;
$t_today_absent = 0;
$top_students = [];
$recent_activities = [];

if (in_array($role, ['admin', 'nazim', 'teacher', 'mudarris', 'superadmin', 'super_admin', 'administrator'], true)) {
    try {
        $masjid_tenant_id = get_current_tenant_id();
        $t_mas_cond = " AND tenant_id = $masjid_tenant_id";

        $masjid_privacy_std = get_student_class_privacy_sql($pdo, 'class', null, null, 'Masjid');
        $masjid_privacy_s_std = get_student_class_privacy_sql($pdo, 's.class', null, null, 'Masjid');
        $masjid_allowed_cls = get_allowed_classes($pdo, 'name ASC', null, null, 'Masjid');
        $masjid_allowed_cls_count = count($masjid_allowed_cls);

        // 1. مکتب طلباء (بلحاظ پرائیویسی)
        try {
            $c1 = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) AND system_type='Masjid' $t_mas_cond AND ($masjid_privacy_std)")->fetchColumn();
            $c2 = 0;
            try { $c2 = (int)$pdo->query("SELECT COUNT(*) FROM maktab_students WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) $t_mas_cond AND ($masjid_privacy_std)")->fetchColumn(); } catch(Throwable $e) {}
            $total_students = max($c1, $c2, ($c1 + $c2));
        } catch (Throwable $e) {}

        // 2. مکتب اساتذہ
        try {
            $t1 = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) AND system_type='Masjid' $t_mas_cond")->fetchColumn();
            $t2 = 0;
            try { $t2 = (int)$pdo->query("SELECT COUNT(*) FROM maktab_teachers WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) $t_mas_cond")->fetchColumn(); } catch(Throwable $e) {}
            $total_teachers = max($t1, $t2, ($t1 + $t2));
        } catch (Throwable $e) {}

        // 3. مکتب کلاسز (مجاز کلاسز)
        $total_classes = $masjid_allowed_cls_count;

        // 4. رواں ماہ کے مسجد اخراجات (قرض کی واپسی کو خالص اخراجات سے الگ رکھتے ہوئے)
        try {
            $stmt_exp = $pdo->prepare("SELECT SUM(amount) FROM masjid_finance WHERE LOWER(type)='expense' AND tenant_id = ? AND (is_loan = 0 OR is_loan IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND date >= ?");
            $stmt_exp->execute([$masjid_tenant_id, $first_day]);
            $exp1 = $stmt_exp->fetchColumn();
            $monthly_expense = (float)($exp1 ?? 0);
        } catch (Throwable $e) {
            try {
                $stmt_exp = $pdo->prepare("SELECT SUM(amount) FROM masjid_finance WHERE LOWER(type)='expense' AND tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND date >= ?");
                $stmt_exp->execute([$masjid_tenant_id, $first_day]);
                $exp1 = $stmt_exp->fetchColumn();
                $monthly_expense = (float)($exp1 ?? 0);
            } catch (Throwable $e2) {}
        }

        // 5. اثاثہ جات اور اعتکاف
        try {
            $total_assets = (int)$pdo->query("SELECT COUNT(*) FROM masjid_assets WHERE (is_deleted=0 OR is_deleted IS NULL) $t_mas_cond")->fetchColumn();
        } catch (Throwable $e) {}
        try {
            $total_itikaf = (int)$pdo->query("SELECT COUNT(*) FROM masjid_itikaf WHERE (is_deleted=0 OR is_deleted IS NULL) $t_mas_cond")->fetchColumn();
        } catch (Throwable $e) {}

        // 6. آج کی حاضری فیصد (مکتب)
        try {
            $stmt_att = $pdo->prepare("
                SELECT a.status_1, a.status_2, a.status_3, a.status_4, a.late_1, a.late_2, a.late_3, a.late_4
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.date = ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL)
                  AND s.system_type='Masjid'
                  AND ($masjid_privacy_s_std)
            ");
            $stmt_att->execute([$today, $masjid_tenant_id, $masjid_tenant_id]);
            $att_rows = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

            $today_present = 0; $today_absent = 0; $today_leave = 0; $today_late = 0; $today_total = 0;
            foreach ($att_rows as $att_row) {
                for ($i = 1; $i <= 4; $i++) {
                    $status = strtoupper(trim((string)($att_row["status_$i"] ?? '')));
                    if ($status === '') continue;
                    $today_total++;
                    $late_minutes = (int)($att_row["late_$i"] ?? 0);
                    if ($late_minutes > 0 || $status === 'LATE') {
                        $today_late++;
                    } elseif ($status === 'A' || $status === 'ABSENT') {
                        $today_absent++;
                    } elseif ($status === 'L' || $status === 'LEAVE' || $status === 'R') {
                        $today_leave++;
                    } elseif ($status === 'P' || $status === 'PRESENT') {
                        $today_present++;
                    }
                }
            }

            // Also check maktab_attendance if attendance was empty
            if ($today_total === 0) {
                try {
                    $stmt_matt = $pdo->prepare("SELECT status, delay_minutes FROM maktab_attendance WHERE date = ? AND tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL)");
                    $stmt_matt->execute([$today, $masjid_tenant_id]);
                    $m_att_rows = $stmt_matt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($m_att_rows as $mar) {
                        $st = strtoupper(trim((string)($mar['status'] ?? '')));
                        if ($st === '') continue;
                        $today_total++;
                        $delay = (int)($mar['delay_minutes'] ?? 0);
                        if ($delay > 0 || $st === 'LATE') $today_late++;
                        elseif ($st === 'A' || $st === 'ABSENT') $today_absent++;
                        elseif ($st === 'L' || $st === 'LEAVE' || $st === 'R') $today_leave++;
                        elseif ($st === 'P' || $st === 'PRESENT') $today_present++;
                    }
                } catch (Throwable $eM) {}
            }

            $pct = function($value) use ($today_total) {
                return $today_total > 0 ? round(($value / $today_total) * 100) : 0;
            };
            $present_today_pct = $pct($today_present);
            $absent_today_pct = $pct($today_absent);
            $leave_today_pct = $pct($today_leave);
            $late_today_pct = $pct($today_late);
        } catch (Throwable $e) {}

        // 7. Phase 1: Today's Maktab Attendance Situation (کلاسز کی صورتحال)
        try {
            $classes_all = $pdo->prepare("SELECT id, name FROM classes WHERE (status='Active' OR status='' OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND system_type='Masjid' AND tenant_id = ? ORDER BY name ASC");
            $classes_all->execute([$masjid_tenant_id]);
            $classes_all = $classes_all->fetchAll(PDO::FETCH_ASSOC);
            if (empty($classes_all)) {
                try {
                    $stmt_mc = $pdo->prepare("SELECT id, name FROM maktab_classes WHERE (is_deleted=0 OR is_deleted IS NULL) AND tenant_id = ? ORDER BY name ASC");
                    $stmt_mc->execute([$masjid_tenant_id]);
                    $classes_all = $stmt_mc->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $eMc) {}
            }

            $m1 = [];
            try {
                $stmt_m1 = $pdo->prepare("SELECT DISTINCT TRIM(s.class) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL) AND s.class IS NOT NULL AND s.class != '' AND s.system_type='Masjid'");
                $stmt_m1->execute([$today, $masjid_tenant_id, $masjid_tenant_id]);
                $m1 = $stmt_m1->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $eM1) {}
            $m2 = [];
            try {
                $stmt_m2 = $pdo->prepare("SELECT DISTINCT TRIM(class_name) FROM maktab_attendance WHERE date = ? AND tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND class_name IS NOT NULL AND class_name != ''");
                $stmt_m2->execute([$today, $masjid_tenant_id]);
                $m2 = $stmt_m2->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $eM2) {}

            $marked_class_names = array_unique(array_filter(array_merge($m1, $m2)));
            foreach ($classes_all as $c) {
                if (in_array(trim($c['name']), $marked_class_names)) {
                    $done_classes[] = $c;
                } else {
                    $remaining_classes[] = $c;
                }
            }
        } catch (Throwable $e) {}

        // 8. Phase 1: Today's Maktab Teacher Attendance (اساتذہ حاضری)
        try {
            $stmt_tp = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status = 'P' AND t.system_type='Masjid'");
            $stmt_tp->execute([$today, $masjid_tenant_id, $masjid_tenant_id]);
            $t_today_present = (int)$stmt_tp->fetchColumn();

            $stmt_tl = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status IN ('L', 'LA') AND t.system_type='Masjid'");
            $stmt_tl->execute([$today, $masjid_tenant_id, $masjid_tenant_id]);
            $t_today_leave = (int)$stmt_tl->fetchColumn();

            $stmt_ta = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status = 'A' AND t.system_type='Masjid'");
            $stmt_ta->execute([$today, $masjid_tenant_id, $masjid_tenant_id]);
            $t_today_absent = (int)$stmt_ta->fetchColumn();

            if ($t_today_present === 0 && $t_today_leave === 0 && $t_today_absent === 0) {
                try {
                    $stmt_mtp = $pdo->prepare("SELECT COUNT(*) FROM maktab_teacher_attendance WHERE date = ? AND tenant_id = ? AND status = 'P'");
                    $stmt_mtp->execute([$today, $masjid_tenant_id]);
                    $t_today_present = (int)$stmt_mtp->fetchColumn();

                    $stmt_mtl = $pdo->prepare("SELECT COUNT(*) FROM maktab_teacher_attendance WHERE date = ? AND tenant_id = ? AND status IN ('L', 'LA')");
                    $stmt_mtl->execute([$today, $masjid_tenant_id]);
                    $t_today_leave = (int)$stmt_mtl->fetchColumn();

                    $stmt_mta = $pdo->prepare("SELECT COUNT(*) FROM maktab_teacher_attendance WHERE date = ? AND tenant_id = ? AND status = 'A'");
                    $stmt_mta->execute([$today, $masjid_tenant_id]);
                    $t_today_absent = (int)$stmt_mta->fetchColumn();
                } catch (Throwable $eMt) {}
            }
        } catch (Throwable $e) {}

        // 9. Phase 1: Top 5 Maktab Students
        try {
            $stmt_top = $pdo->prepare("
                SELECT s.id, s.name, s.father_name, s.class, 
                       ROUND(AVG(er.marks_obtained), 1) as avg_marks,
                       COUNT(er.id) as exams_count
                FROM exam_results er
                JOIN students s ON er.student_id = s.id
                WHERE s.tenant_id = ? AND (s.is_deleted=0 OR s.is_deleted IS NULL) AND s.system_type='Masjid'
                GROUP BY s.id, s.name, s.father_name, s.class
                ORDER BY avg_marks DESC
                LIMIT 5
            ");
            $stmt_top->execute([$masjid_tenant_id]);
            $top_students = $stmt_top->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        if (empty($top_students)) {
            try {
                $stmt_top2 = $pdo->prepare("
                    SELECT id, name, father_name, class, NULL as avg_marks, 0 as exams_count
                    FROM students
                    WHERE tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL)
                      AND system_type='Masjid'
                    ORDER BY id DESC LIMIT 5
                ");
                $stmt_top2->execute([$masjid_tenant_id]);
                $top_students = $stmt_top2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }
        if (empty($top_students)) {
            try {
                $top_students = $pdo->query("
                    SELECT id, name, father_name, class_name as class, NULL as avg_marks, 0 as exams_count
                    FROM maktab_students
                    WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL)
                    ORDER BY id DESC LIMIT 5
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }

        // 10. Phase 1: Recent Activity Logs
        try {
            if (function_exists('ensure_phase1_tables')) ensure_phase1_tables($pdo);
            $recent_activities = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

    } catch (Throwable $e) {
        error_log("Masjid Dashboard Error: " . $e->getMessage());
    }
}
?>

<div class="container-fluid py-3" dir="rtl">
    <!-- عنوان اور ہیڈر -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-7 d-flex align-items-center">
            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 24px;">
                <i class="fas fa-mosque"></i>
            </div>
            <div>
                <h3 class="fw-bold text-success mb-0">مسجد ڈیش بورڈ (نظامِ مکتب و مسجد انتظام)</h3>
                <small class="text-muted">مسجد اور قرآنی مکتب کی تمام تر روزمرہ سرگرمیوں کا براہِ راست جائزہ</small>
            </div>
        </div>
        <div class="col-md-5 text-start mt-2 mt-md-0">
            <div class="btn-group shadow-sm rounded-pill p-1 bg-white border">
                <a href="attendance.php" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                    <i class="fas fa-calendar-check me-1"></i> مکتب حاضری
                </a>
                <a href="teacher_leaves.php" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold">
                    <i class="fas fa-calendar-minus me-1"></i> اساتذہ رخصت
                </a>
                <a href="simple_finance.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold">
                    <i class="fas fa-wallet me-1"></i> فنانس
                </a>
            </div>
        </div>
    </div>

    <?php if(in_array($role, ['admin', 'nazim', 'teacher', 'mudarris', 'superadmin'], true)): ?>
    <!-- 6 اہم شماریاتی کارڈز (Stat Cards) -->
    <div class="row g-3 mb-4">
        <!-- مکتب طلبہ -->
        <?php if(has_perm('manage_masjid_students') || has_perm('manage_students')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="students.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%); border-radius: 14px; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="display-6 fw-bold mb-0"><?php echo $total_students; ?></div>
                            <div class="small text-white-50 mt-1">مکتب طلباء</div>
                        </div>
                        <i class="fas fa-user-graduate fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- مکتب اساتذہ -->
        <?php if(has_perm('manage_masjid_teachers') || has_perm('manage_teachers')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="teachers.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #0d47a1 0%, #1976d2 100%); border-radius: 14px; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="display-6 fw-bold mb-0"><?php echo $total_teachers; ?></div>
                            <div class="small text-white-50 mt-1">مکتب اساتذہ</div>
                        </div>
                        <i class="fas fa-chalkboard-teacher fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- قرآنی کلاسز -->
        <?php if(has_perm('manage_masjid_classes') || has_perm('manage_classes')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="manage_classes.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #f57f17 0%, #fbc02d 100%); border-radius: 14px; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="display-6 fw-bold mb-0"><?php echo $total_classes; ?></div>
                            <div class="small text-white-50 mt-1">قرآنی کلاسز</div>
                        </div>
                        <i class="fas fa-chalkboard fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- آج کی حاضری -->
        <?php if(has_perm('manage_masjid_attendance') || has_perm('manage_attendance')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="attendance.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #00695c 0%, #00897b 100%); border-radius: 14px; color: white;">
                    <div class="small text-white-50 mb-1">آج کی حاضری</div>
                    <div class="fw-bold" style="font-size: 0.95rem; line-height: 1.5;">
                        حاضر: <?php echo $present_today_pct; ?>% | غیر: <?php echo $absent_today_pct; ?>%<br>
                        رخصت: <?php echo $leave_today_pct; ?>% | تاخیر: <?php echo $late_today_pct; ?>%
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- ماہانہ اخراجات -->
        <?php if(has_perm('manage_masjid_finance') || has_perm('manage_masjid_finance_entry') || has_perm('manage_masjid_finance_report') || has_perm('manage_masjid_financial_reports') || has_perm('manage_finance')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="simple_finance.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #b71c1c 0%, #d32f2f 100%); border-radius: 14px; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="h3 fw-bold mb-0"><?php echo number_format($monthly_expense); ?></div>
                            <div class="small text-white-50 mt-1">رواں ماہ اخراجات</div>
                        </div>
                        <i class="fas fa-wallet fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- مسجد اثاثہ جات -->
        <?php if(has_perm('manage_masjid_assets') || has_perm('manage_assets') || has_perm('manage_finance')): ?>
        <div class="col-xl-2 col-lg-4 col-sm-6">
            <a href="assets.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100 p-3" style="background: linear-gradient(135deg, #37474f 0%, #546e7a 100%); border-radius: 14px; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="display-6 fw-bold mb-0"><?php echo $total_assets; ?></div>
                            <div class="small text-white-50 mt-1">اثاثہ جات</div>
                        </div>
                        <i class="fas fa-boxes fa-2x opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Phase 1: Today's Attendance Situation Widget (آج کی حاضری صورتحال - مسجد و مکتب) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-success bg-opacity-10 p-2 text-success">
                        <i class="fas fa-tasks fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">آج کی مکتب حاضری صورتحال (Today's Situation)</h5>
                        <small class="text-muted"><?php echo translateDate($today); ?> کے لیے قرآنی کلاسز و اساتذہ کی لائیو صورتحال</small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="attendance.php" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                        <i class="fas fa-clipboard-check me-1"></i> حاضری رجسٹر
                    </a>
                    <a href="teachers_attendance.php" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold">
                        <i class="fas fa-user-clock me-1"></i> اساتذہ حاضری
                    </a>
                </div>
            </div>

            <div class="row g-3 align-items-stretch">
                <!-- مکتب کلاسز حاضری پیش رفت -->
                <div class="col-lg-7 col-12">
                    <div class="p-3 bg-light rounded-3 h-100 border">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><i class="fas fa-chalkboard text-success me-1"></i> مکتب کلاسز کی حاضری:</span>
                            <span class="badge <?php echo empty($remaining_classes) ? 'bg-success' : 'bg-warning text-dark'; ?> rounded-pill">
                                <?php echo count($done_classes); ?> / <?php echo count($classes_all); ?> کلاسز مکمل
                            </span>
                        </div>
                        <?php 
                        $cls_total = count($classes_all);
                        $cls_done = count($done_classes);
                        $cls_pct = $cls_total > 0 ? round(($cls_done / $cls_total) * 100) : 0;
                        ?>
                        <div class="progress mb-2" style="height: 10px; border-radius: 5px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $cls_pct; ?>%" aria-valuenow="<?php echo $cls_pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>

                        <?php if (!empty($remaining_classes)): ?>
                            <div class="mt-2">
                                <span class="text-danger small fw-bold"><i class="fas fa-exclamation-circle me-1"></i> غیر درج شدہ کلاسز:</span>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <?php foreach ($remaining_classes as $rc): ?>
                                        <a href="attendance.php?class=<?php echo urlencode($rc['name']); ?>" class="badge bg-danger-subtle text-danger border border-danger text-decoration-none py-1 px-2 rounded-pill small">
                                            <i class="fas fa-pen-nib me-1 fa-xs"></i><?php echo htmlspecialchars($rc['name']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-success small fw-bold mt-2">
                                <i class="fas fa-check-circle me-1"></i> الحمدللہ! آج تمام مکتب کلاسز کی حاضری مکمل ہو چکی ہے۔
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- مکتب اساتذہ حاضری کا جائزہ -->
                <div class="col-lg-5 col-12">
                    <div class="p-3 bg-light rounded-3 h-100 border d-flex flex-column justify-content-between">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><i class="fas fa-chalkboard-teacher text-success me-1"></i> مکتب اساتذہ کی حاضری:</span>
                            <a href="teacher_leaves.php" class="small text-decoration-none fw-bold text-success">چھٹیوں کا ریکارڈ &larr;</a>
                        </div>
                        <div class="row g-2 text-center my-auto">
                            <div class="col-4">
                                <div class="bg-white p-2 rounded-3 border">
                                    <span class="text-success fw-bold d-block h5 mb-0"><?php echo $t_today_present; ?></span>
                                    <small class="text-muted" style="font-size: 11px;">حاضر</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white p-2 rounded-3 border">
                                    <span class="text-warning fw-bold d-block h5 mb-0"><?php echo $t_today_leave; ?></span>
                                    <small class="text-muted" style="font-size: 11px;">رخصت</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white p-2 rounded-3 border">
                                    <span class="text-danger fw-bold d-block h5 mb-0"><?php echo $t_today_absent; ?></span>
                                    <small class="text-muted" style="font-size: 11px;">غیر حاضر</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Phase 1: Top Students & Quick Links Row -->
    <div class="row mb-4">
        <!-- نمایاں طلبہ (مکتب) -->
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="bg-light fw-bold py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-star text-warning me-2"></i> نمایاں طلباء (Top Maktab Students)</span>
                    <a href="students.php" class="btn btn-sm btn-outline-success rounded-pill">تمام طلبہ</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">طالب علم</th>
                                <th>والد کا نام</th>
                                <th>کلاس</th>
                                <th class="text-center">کارکردگی</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($top_students)): ?>
                                <?php foreach($top_students as $idx => $ts): ?>
                                    <tr>
                                        <td class="px-4 fw-bold text-success">
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-circle me-1"><?php echo $idx + 1; ?></span>
                                            <?php echo htmlspecialchars($ts['name'] ?? ''); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($ts['father_name'] ?? ''); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ts['class'] ?? ''); ?></span></td>
                                        <td class="text-center">
                                            <?php if(isset($ts['avg_marks']) && $ts['avg_marks'] !== null): ?>
                                                <span class="badge bg-success rounded-pill px-2 py-1"><?php echo $ts['avg_marks']; ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted small">فعال</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">طلبہ کا ریکارڈ دستیاب نہیں</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- فوری لنکس (Quick Links) -->
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="bg-light fw-bold py-3 px-4 border-bottom"><i class="fas fa-bolt me-2 text-warning"></i> مسجد و مکتب اہم لنکس</div>
                <div class="list-group list-group-flush">
                    <a href="manage_classes.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-chalkboard me-2 text-success"></i> کلاسز</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <a href="attendance.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-clipboard-check me-2 text-primary"></i> روزانہ طلبہ حاضری رجسٹر</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <a href="teachers.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-chalkboard-teacher me-2 text-info"></i> اساتذہ و عملہ ریکارڈ</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <a href="teacher_leaves.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-calendar-minus me-2 text-warning"></i> اساتذہ رخصت مینیجمنٹ</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <a href="simple_finance.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-wallet me-2 text-danger"></i> مسجد آمدن و اخراجات</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <a href="masjid_itikaf.php" class="list-group-item list-group-item-action py-3 border-0 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-praying-hands me-2 text-teal" style="color: #009688;"></i> اعتکاف رجسٹریشن و ریکارڈ</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Phase 1: Recent Activity Timeline -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="bg-light fw-bold py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-stream me-2 text-success"></i> حالیہ سرگرمیاں (Recent Activity Logs)</span>
                    <span class="badge bg-success-subtle text-success rounded-pill small">لائیو لاگ</span>
                </div>
                <div class="card-body p-4">
                    <?php if(!empty($recent_activities)): ?>
                        <div class="timeline" style="border-right: 2px solid #e9ecef; padding-right: 20px; margin-right: 10px;">
                            <?php foreach($recent_activities as $act): ?>
                                <div class="mb-3 position-relative">
                                    <div class="position-absolute" style="right: -27px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: #2e7d32; border: 2px solid white;"></div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($act['description']); ?></span>
                                        <small class="text-muted" style="font-size: 11px;"><?php echo date('d M, h:i A', strtotime($act['created_at'])); ?></small>
                                    </div>
                                    <small class="text-muted d-block" style="font-size: 11px;"><i class="fas fa-user me-1 fa-xs"></i><?php echo htmlspecialchars($act['user_name'] ?? 'سسٹم'); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">فی الوقت کوئی سرگرمی ریکارڈ نہیں ہوئی</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
