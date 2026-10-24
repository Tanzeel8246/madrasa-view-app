<?php 
ob_start();
require_once __DIR__ . '/session_init.php';
if (session_status() === PHP_SESSION_NONE) { madrasa_safe_session_start(); }
require_once 'db.php'; 

$sys = $_SESSION['system_type'] ?? 'Madrasa';
if ($sys === 'Masjid') {
    if (!headers_sent()) {
        header("Location: masjid_dashboard.php");
    }
    echo "<script>window.location.replace('masjid_dashboard.php');</script>";
    exit;
}

include 'header.php'; 

$tenant_id_dash = (int)get_current_tenant_id();

// Auto-heal vital columns if missing in production (Only runs once to prevent MySQL metadata locks and HTTP 500)
$dashMigrateFlag = __DIR__ . '/.dashboard_schema_v2_done';
if (!file_exists($dashMigrateFlag)) {
    try { $pdo->exec("ALTER TABLE students ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE students ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE students ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE teachers ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE teachers ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE teachers ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE classes ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE classes ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE classes ADD COLUMN is_deleted TINYINT DEFAULT 0"); } catch (Throwable $e) {}
    @touch($dashMigrateFlag);
}

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));

if (in_array($role, ['admin', 'nazim', 'teacher', 'mudarris', 'superadmin', 'super_admin', 'administrator'], true)) {
    try {
        $st_set = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
        $st_set->execute([$tenant_id_dash]);
        $settings = $st_set->fetch();
        if (!$settings) {
            $settings = ['madrasa_name' => ($_SESSION['tenant_name'] ?? 'مدرسہ'), 'current_hijri_year' => '1447'];
        }

        $privacy_std_cond = get_student_class_privacy_sql($pdo, 'class');
        $privacy_s_std_cond = get_student_class_privacy_sql($pdo, 's.class');
        $allowed_classes_list = get_allowed_classes($pdo, 'name ASC', null, null, 'Madrasa');
        $allowed_cls_count = count($allowed_classes_list);

        $t_dash_cond = " AND tenant_id = $tenant_id_dash";

        // صرف مدرسہ کے فعال اور غیر ڈیلیٹ شدہ طلباء گنیں (بلحاظ پرائیویسی فلٹر)
        $total_students = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') $t_dash_cond AND ($privacy_std_cond)")->fetchColumn();
        $total_alumni   = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE (is_deleted=0 OR is_deleted IS NULL) AND status='Alumni' AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') $t_dash_cond AND ($privacy_std_cond)")->fetchColumn();
        $total_teachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL) AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') $t_dash_cond")->fetchColumn();
        $total_notices  = (int)$pdo->query("SELECT COUNT(*) FROM notices WHERE (is_deleted=0 OR is_deleted IS NULL) $t_dash_cond")->fetchColumn();
        $total_classes  = $allowed_cls_count;
        
        $today = date('Y-m-d');
        // آج کی حاضری: خالص مدرسہ طلبہ بلحاظ پرائیویسی
        $stmt_today = $pdo->prepare("
            SELECT a.status_1, a.status_2, a.status_3, a.status_4, a.late_1, a.late_2, a.late_3, a.late_4
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL)
              AND (s.system_type='Madrasa' OR s.system_type IS NULL OR s.system_type='')
              AND ($privacy_s_std_cond)
        ");
        $stmt_today->execute([$today, $tenant_id_dash, $tenant_id_dash]);
        $today_rows = $stmt_today->fetchAll(PDO::FETCH_ASSOC);
        $today_present = 0; $today_absent = 0; $today_leave = 0; $today_late = 0; $today_total = 0;
        foreach ($today_rows as $att_row) {
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
        $pct = function($value) use ($today_total) {
            return $today_total > 0 ? round(($value / $today_total) * 100) : 0;
        };
        $present_today_pct = $pct($today_present);
        $absent_today_pct = $pct($today_absent);
        $leave_today_pct = $pct($today_leave);
        $late_today_pct = $pct($today_late);
        
        // Monthly Attendance (Madrasa)
        $first_day = date('Y-m-01');
        $stmt_pos = $pdo->prepare("
            SELECT COUNT(*) FROM attendance a 
            JOIN students s ON a.student_id = s.id 
            WHERE a.date >= ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL)
              AND (s.system_type='Madrasa' OR s.system_type IS NULL OR s.system_type='')
              AND ($privacy_s_std_cond)
        ");
        $stmt_pos->execute([$first_day, $tenant_id_dash, $tenant_id_dash]);
        $total_possible = (int)$stmt_pos->fetchColumn();

        $stmt_pres = $pdo->prepare("
            SELECT COUNT(*) FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date >= ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL)
              AND (a.status_1='P' OR a.status_2='P' OR a.status_3='P' OR a.status_4='P')
              AND (s.system_type='Madrasa' OR s.system_type IS NULL OR s.system_type='')
              AND ($privacy_s_std_cond)
        ");
        $stmt_pres->execute([$first_day, $tenant_id_dash, $tenant_id_dash]);
        $total_present = (int)$stmt_pres->fetchColumn();
        $monthly_att_percent = $total_possible > 0 ? round(($total_present / $total_possible) * 100, 1) : 0;

        // رواں ماہ کے خالص آپریشنل اخراجات (مدرسہ - قرضہ جات کی ادائیگی کو الگ رکھتے ہوئے)
        try {
            $stmt_exp = $pdo->prepare("SELECT SUM(amount) FROM finance WHERE LOWER(type)='expense' AND tenant_id = ? AND (is_loan = 0 OR is_loan IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND date >= ? AND (system_type='Madrasa' OR system_type IS NULL OR system_type='')");
            $stmt_exp->execute([$tenant_id_dash, $first_day]);
            $current_month_expense = $stmt_exp->fetchColumn();
            $monthly_expense = (float)($current_month_expense ?? 0);
        } catch (Throwable $eExp) {
            $stmt_exp = $pdo->prepare("SELECT SUM(amount) FROM finance WHERE LOWER(type)='expense' AND tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND date >= ? AND (system_type='Madrasa' OR system_type IS NULL OR system_type='')");
            $stmt_exp->execute([$tenant_id_dash, $first_day]);
            $current_month_expense = $stmt_exp->fetchColumn();
            $monthly_expense = (float)($current_month_expense ?? 0);
        }

        // Phase 1: Today's Attendance Situation (صرف مجاز مدرسہ کلاسز کی حاضری صورتحال)
        $classes_all = $allowed_classes_list;
        $marked_class_names = [];
        $remaining_classes = [];
        $done_classes = [];
        try {
            $stmt_m1 = $pdo->prepare("SELECT DISTINCT TRIM(s.class) FROM period_attendance pa JOIN students s ON pa.student_id = s.id WHERE pa.date = ? AND pa.tenant_id = ? AND s.tenant_id = ? AND s.class IS NOT NULL AND s.class != '' AND (s.system_type='Madrasa' OR s.system_type IS NULL OR system_type='')");
            $stmt_m1->execute([$today, $tenant_id_dash, $tenant_id_dash]);
            $m1 = $stmt_m1->fetchAll(PDO::FETCH_COLUMN);

            $stmt_m2 = $pdo->prepare("SELECT DISTINCT TRIM(s.class) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.is_deleted=0 OR a.is_deleted IS NULL) AND s.class IS NOT NULL AND s.class != '' AND (s.system_type='Madrasa' OR s.system_type IS NULL OR system_type='')");
            $stmt_m2->execute([$today, $tenant_id_dash, $tenant_id_dash]);
            $m2 = $stmt_m2->fetchAll(PDO::FETCH_COLUMN);

            $marked_class_names = array_unique(array_filter(array_merge($m1, $m2)));
            foreach ($classes_all as $c) {
                if (in_array(trim($c['name']), $marked_class_names)) {
                    $done_classes[] = $c;
                } else {
                    $remaining_classes[] = $c;
                }
            }
        } catch (Throwable $e) {}

        // Phase 1: Today's Teacher Attendance (صرف مدرسہ اساتذہ)
        $t_today_present = 0; $t_today_leave = 0; $t_today_absent = 0;
        try {
            $stmt_tp = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status = 'P' AND (t.system_type='Madrasa' OR t.system_type IS NULL OR t.system_type='')");
            $stmt_tp->execute([$today, $tenant_id_dash, $tenant_id_dash]);
            $t_today_present = (int)$stmt_tp->fetchColumn();

            $stmt_tl = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status IN ('L', 'LA') AND (t.system_type='Madrasa' OR t.system_type IS NULL OR t.system_type='')");
            $stmt_tl->execute([$today, $tenant_id_dash, $tenant_id_dash]);
            $t_today_leave = (int)$stmt_tl->fetchColumn();

            $stmt_ta = $pdo->prepare("SELECT COUNT(*) FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status = 'A' AND (t.system_type='Madrasa' OR t.system_type IS NULL OR t.system_type='')");
            $stmt_ta->execute([$today, $tenant_id_dash, $tenant_id_dash]);
            $t_today_absent = (int)$stmt_ta->fetchColumn();
        } catch (Throwable $e) {}

        // Phase 1: Top 5 Students (صرف مدرسہ طلبہ)
        $top_students = [];
        try {
            $stmt_top = $pdo->prepare("
                SELECT s.id, s.name, s.father_name, s.class, 
                       ROUND(AVG(er.marks_obtained), 1) as avg_marks,
                       COUNT(er.id) as exams_count
                FROM exam_results er
                JOIN students s ON er.student_id = s.id
                WHERE s.tenant_id = ? AND (s.is_deleted=0 OR s.is_deleted IS NULL) AND (s.system_type='Madrasa' OR s.system_type IS NULL OR s.system_type='')
                GROUP BY s.id, s.name, s.father_name, s.class
                ORDER BY avg_marks DESC
                LIMIT 5
            ");
            $stmt_top->execute([$tenant_id_dash]);
            $top_students = $stmt_top->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        if (empty($top_students)) {
            try {
                $stmt_top2 = $pdo->prepare("
                    SELECT id, name, father_name, class, NULL as avg_marks, 0 as exams_count
                    FROM students
                    WHERE tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND (status='Active' OR status='' OR status IS NULL)
                      AND (system_type='Madrasa' OR system_type IS NULL OR system_type='')
                    ORDER BY id DESC LIMIT 5
                ");
                $stmt_top2->execute([$tenant_id_dash]);
                $top_students = $stmt_top2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }

        // Phase 1: Recent Activity Logs
        $recent_activities = [];
        try {
            if (function_exists('ensure_phase1_tables')) ensure_phase1_tables($pdo);
            $rec_act_stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE tenant_id = ? AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') ORDER BY id DESC LIMIT 8");
            $rec_act_stmt->execute([$tenant_id_dash]);
            $recent_activities = $rec_act_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

    } catch (Throwable $e) { 
        error_log("Dashboard Data Error: " . $e->getMessage());
    }
}
?>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6 d-flex align-items-center">
            <img src="logo.png" alt="Logo" style="width: 50px; height: 50px; border-radius: 50%; margin-left: 15px; border: 2px solid #284D3D; background: white; object-fit: contain;">
            <h2 class="fw-bold text-primary m-0"><?php echo t('dashboard'); ?></h2>
        </div>
        <?php if($role == 'admin'): ?>
        <div class="col-md-6">
            <div class="neobox p-3 bg-gradient-green text-white mb-0">
                <div class="d-flex align-items-center mb-1">
                    <i class="fas fa-university me-2 text-warning"></i>
                    <small class="fw-bold text-uppercase" style="letter-spacing: 1px;">مشورہ</small>
                </div>
                <p class="m-0 small" style="line-height: 1.6;" id="ai-insight-text"><?php echo htmlspecialchars($management_insight ?? 'انتظامی تجاویز حاصل کی جا رہی ہیں...'); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if(in_array($role, ['admin', 'nazim', 'teacher', 'mudarris', 'superadmin'], true)): ?>
    <!-- 8 Stat Cards Grid (Admin) -->
    <div class="row g-4 mb-5">
        <?php if(has_perm('manage_students')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="students.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-green h-100">
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <div class="stat-label">زیرِ تعلیم طلبہ</div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_teachers')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="teachers.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-blue h-100">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo $total_teachers; ?></div>
                    <div class="stat-label"><?php echo t('active_teachers'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_classes')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="manage_classes.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-gold h-100">
                    <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
                    <div class="stat-value"><?php echo $total_classes; ?></div>
                    <div class="stat-label"><?php echo t('active_classes'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_attendance')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="attendance.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-info h-100">
                    <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                    <div class="stat-value" style="font-size: 1.05rem; line-height: 1.7;">
                        حاضر <?php echo $present_today_pct; ?>% | غیر حاضر <?php echo $absent_today_pct; ?>%<br>
                        رخصت <?php echo $leave_today_pct; ?>% | تاخیر <?php echo $late_today_pct; ?>%
                    </div>
                    <div class="stat-label"><?php echo t('today_attendance_perc'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_attendance')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="attendance.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-primary h-100" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                    <div class="stat-icon"><i class="fas fa-chart-line text-white"></i></div>
                    <div class="stat-value text-white"><?php echo $monthly_att_percent; ?>%</div>
                    <div class="stat-label text-white-50"><?php echo t('monthly_attendance_perc'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_finance') || has_perm('manage_finance_entry') || has_perm('manage_finance_report') || has_perm('manage_financial_reports')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="simple_finance.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-success h-100" style="background: linear-gradient(135deg, #d31027 0%, #ea384d 100%);">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave text-white"></i></div>
                    <div class="stat-value text-white"><?php echo number_format($monthly_expense); ?></div>
                    <div class="stat-label text-white-50"><?php echo t('this_month_expenses'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_students')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="students.php?status=alumni" class="text-decoration-none">
                <div class="stat-card bg-gradient-dark h-100">
                    <div class="stat-icon"><i class="fas fa-award"></i></div>
                    <div class="stat-value"><?php echo $total_alumni; ?></div>
                    <div class="stat-label"><?php echo t('alumni_graduates'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if(has_perm('manage_notices')): ?>
        <div class="col-md-3 col-sm-6">
            <a href="manage_notices.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-red h-100">
                    <div class="stat-icon"><i class="fas fa-bell"></i></div>
                    <div class="stat-value"><?php echo $total_notices; ?></div>
                    <div class="stat-label"><?php echo t('notices'); ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Phase 1: Today's Attendance Situation Widget (آج کی حاضری صورتحال و ناظم الرٹ) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-2 text-primary">
                        <i class="fas fa-tasks fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">آج کی مجموعی حاضری صورتحال (Today's Situation)</h5>
                        <small class="text-muted"><?php echo translateDate($today); ?> کے لیے حاضری اندراج کی لائیو صورتحال</small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="attendance.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                        <i class="fas fa-clipboard-check me-1"></i> حاضری رجسٹر
                    </a>
                    <a href="teachers_attendance.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                        <i class="fas fa-user-clock me-1"></i> اساتذہ حاضری
                    </a>
                </div>
            </div>

            <div class="row g-3 align-items-stretch">
                <!-- طلبہ کلاسز حاضری پیش رفت -->
                <div class="col-lg-7 col-12">
                    <div class="p-3 bg-light rounded-3 h-100 border">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><i class="fas fa-chalkboard text-info me-1"></i> قرآنی و درسی کلاسز کی حاضری:</span>
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
                                <span class="text-danger small fw-bold"><i class="fas fa-exclamation-circle me-1"></i> غیر درج شدہ کلاسز (ایکشن مطلوب):</span>
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
                                <i class="fas fa-check-circle me-1"></i> ماشاءاللہ! آج تمام کلاسز کی حاضری مکمل ہو چکی ہے۔
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- اساتذہ حاضری کا جائزہ -->
                <div class="col-lg-5 col-12">
                    <div class="p-3 bg-light rounded-3 h-100 border d-flex flex-column justify-content-between">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><i class="fas fa-chalkboard-teacher text-primary me-1"></i> اساتذہ کرام کی حاضری:</span>
                            <a href="teacher_leaves.php" class="small text-decoration-none fw-bold text-primary">چھٹیوں کا ریکارڈ &larr;</a>
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

    <div class="row">
        <?php if(has_perm('manage_students')): ?>
        <div class="col-lg-8 mb-4">
            <div class="neobox p-0 overflow-hidden">
                <div class="bg-light fw-bold py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-clock me-2 text-primary"></i> <?php echo t('recent_admissions'); ?></span>
                    <a href="students.php" class="btn btn-sm btn-outline-primary rounded-pill">تمام دیکھیں</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4"><?php echo t('student_id'); ?></th>
                                <th><?php echo t('student'); ?></th>
                                <th><?php echo t('father_name'); ?></th>
                                <th><?php echo t('admission_date'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $recent_rows = [];
                            try {
                                $stmt_recent = $pdo->prepare("SELECT * FROM students WHERE tenant_id = ? AND (is_deleted=0 OR is_deleted IS NULL) AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') ORDER BY id DESC LIMIT 5");
                                $stmt_recent->execute([$tenant_id_dash]);
                                $recent_rows = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Throwable $eRec1) {
                                try {
                                    $stmt_recent = $pdo->query("SELECT * FROM students WHERE (is_deleted=0 OR is_deleted IS NULL) AND (system_type='Madrasa' OR system_type IS NULL OR system_type='') ORDER BY id DESC LIMIT 5");
                                    $recent_rows = $stmt_recent ? $stmt_recent->fetchAll(PDO::FETCH_ASSOC) : [];
                                } catch (Throwable $eRec2) {
                                    try {
                                        $stmt_recent = $pdo->query("SELECT * FROM students ORDER BY id DESC LIMIT 5");
                                        $recent_rows = $stmt_recent ? $stmt_recent->fetchAll(PDO::FETCH_ASSOC) : [];
                                    } catch (Throwable $eRec3) {
                                        $recent_rows = [];
                                    }
                                }
                            }
                            if (!empty($recent_rows)) {
                                foreach ($recent_rows as $row) {
                                    $adm_date_str = function_exists('translateDate') ? translateDate($row['admission_date'] ?? '') : ($row['admission_date'] ?? '-');
                                    echo "<tr><td class='px-4 text-muted'>#" . htmlspecialchars($row['id'] ?? '') . "</td><td class='fw-bold text-primary'>" . htmlspecialchars($row['name'] ?? '') . "</td><td>" . htmlspecialchars($row['father_name'] ?? '') . "</td><td>" . $adm_date_str . "</td></tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4' class='text-center text-muted py-3'>کوئی حالیہ داخلہ موجود نہیں۔</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-lg-4 mb-4">
            <div class="neobox p-0 overflow-hidden">
                <div class="bg-light fw-bold py-3 px-4 border-bottom"><i class="fas fa-bolt me-2 text-warning"></i> <?php echo t('quick_links'); ?></div>
                <div class="list-group list-group-flush">
                    <?php if(has_perm('manage_hifz')): ?>
                    <a href="hifz.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-quran me-2 text-success"></i> حفظ (روزانہ اندراج)</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_academics')): ?>
                    <a href="academic_entry_dashboard.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-book-open me-2 text-primary"></i> تعلیمی رپورٹ (اندراج)</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_attendance')): ?>
                    <a href="attendance.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-calendar-alt me-2 text-primary"></i> <?php echo t('attendance'); ?></span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_finance')): ?>
                    <a href="simple_finance.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-wallet me-2 text-warning"></i> <?php echo t('finance'); ?></span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_students')): ?>
                    <a href="students.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-user-graduate me-2 text-info"></i> طلباء کی فہرست</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_teachers') || has_perm('manage_attendance')): ?>
                    <a href="teacher_leaves.php" class="list-group-item list-group-item-action py-3 border-0 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-calendar-minus me-2 text-primary"></i> اساتذہ رخصت مینیجمنٹ</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_notices')): ?>
                    <a href="manage_notices.php" class="list-group-item list-group-item-action py-3 border-0 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-bullhorn me-2 text-danger"></i> <?php echo t('notices'); ?> بورڈ</span>
                        <i class="fas fa-chevron-left small opacity-50"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Phase 1: Recent Activity Timeline & Top 5 Students Row -->
    <div class="row mb-4">
        <!-- Activity Timeline -->
        <div class="col-lg-6 mb-4">
            <div class="neobox p-0 overflow-hidden h-100">
                <div class="bg-light fw-bold py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-stream me-2 text-primary"></i> حالیہ سرگرمیاں (Recent Activities)</span>
                    <span class="badge bg-primary-subtle text-primary rounded-pill small">لائیو لاگ</span>
                </div>
                <div class="p-3">
                    <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-history fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0 small fw-bold">فی الوقت کوئی نئی سرگرمی درج نہیں ہے۔</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline-list">
                            <?php foreach ($recent_activities as $act): 
                                $act_type = strtolower($act['action_type'] ?? '');
                                $icon = 'fas fa-dot-circle text-secondary';
                                if (strpos($act_type, 'leave') !== false) $icon = 'fas fa-calendar-minus text-warning';
                                elseif (strpos($act_type, 'att') !== false) $icon = 'fas fa-clipboard-check text-primary';
                                elseif (strpos($act_type, 'fee') !== false || strpos($act_type, 'finance') !== false) $icon = 'fas fa-money-bill-wave text-success';
                                elseif (strpos($act_type, 'admiss') !== false) $icon = 'fas fa-graduation-cap text-info';
                            ?>
                                <div class="d-flex align-items-start gap-3 py-2 border-bottom border-light">
                                    <div class="rounded-circle bg-light p-2 text-center" style="width: 36px; height: 36px;">
                                        <i class="<?php echo $icon; ?> fa-sm"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold small text-dark"><?php echo htmlspecialchars($act['user_name'] ?? 'سسٹم'); ?></span>
                                            <small class="text-muted" style="font-size: 11px;"><?php echo date('d M, h:i A', strtotime($act['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-0 small text-secondary"><?php echo htmlspecialchars($act['description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 5 Students Card -->
        <div class="col-lg-6 mb-4">
            <div class="neobox p-0 overflow-hidden h-100">
                <div class="bg-light fw-bold py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-award me-2 text-warning"></i> نمایاں طلبہ (Top 5 Students)</span>
                    <a href="students.php" class="btn btn-sm btn-outline-primary rounded-pill">تمام طلبہ</a>
                </div>
                <div class="p-3">
                    <?php if (empty($top_students)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-user-graduate fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0 small fw-bold">طلبہ کا ڈیٹا دستیاب نہیں ہے۔</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php $pos = 1; foreach ($top_students as $std): 
                                $badgeColor = ($pos === 1) ? 'bg-warning text-dark' : (($pos === 2) ? 'bg-secondary text-white' : (($pos === 3) ? 'bg-danger text-white' : 'bg-primary bg-opacity-10 text-primary'));
                            ?>
                                <div class="list-group-item px-0 py-2 border-0 border-bottom d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="badge rounded-circle <?php echo $badgeColor; ?> d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 12px;">
                                            <?php echo $pos++; ?>
                                        </span>
                                        <div>
                                            <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($std['name']); ?></span>
                                            <small class="text-muted">ولدیت: <?php echo htmlspecialchars($std['father_name'] ?: '—'); ?> | کلاس: <?php echo htmlspecialchars($std['class'] ?: '—'); ?></small>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <?php if (!empty($std['avg_marks'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1 rounded-pill fw-bold">
                                                <?php echo $std['avg_marks']; ?> نمبر
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info border border-info px-2 py-1 rounded-pill small">
                                                فعال طالب علم
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: // For Parent Role ?>
    
    <div class="row g-4 mb-5">
        <div class="col-md-3 col-sm-6">
            <a href="parent_report.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-green h-100">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-value"><i class="fas fa-arrow-left"></i></div>
                    <div class="stat-label"><?php echo t('my_report'); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-sm-6">
            <a href="parent_fee.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-blue h-100">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-value"><i class="fas fa-arrow-left"></i></div>
                    <div class="stat-label"><?php echo t('pay_fee'); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-sm-6">
            <a href="parent_leave.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-gold h-100">
                    <div class="stat-icon"><i class="fas fa-envelope-open-text"></i></div>
                    <div class="stat-value"><i class="fas fa-arrow-left"></i></div>
                    <div class="stat-label"><?php echo t('apply_leave'); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-sm-6">
            <a href="parent_complaint.php" class="text-decoration-none">
                <div class="stat-card bg-gradient-red h-100">
                    <div class="stat-icon"><i class="fas fa-frown"></i></div>
                    <div class="stat-value"><i class="fas fa-arrow-left"></i></div>
                    <div class="stat-label"><?php echo t('parent_complaint'); ?></div>
                </div>
            </a>
        </div>
    </div>
    
    <?php endif; ?>
</div>
<?php if($role == 'admin'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    fetch('ajax_insight.php')
        .then(response => response.text())
        .then(data => {
            if(data.trim() !== "") {
                document.getElementById('ai-insight-text').innerText = data;
            } else {
                document.getElementById('ai-insight-text').innerText = "اے آئی سے رابطہ نہ ہو سکا۔";
            }
        })
        .catch(err => {
            document.getElementById('ai-insight-text').innerText = "اے آئی سے رابطہ نہ ہو سکا۔";
        });
});
</script>
<?php endif; ?>
<?php include 'footer.php'; ?>

