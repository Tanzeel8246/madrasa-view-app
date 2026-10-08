<?php require_once 'db.php'; include 'header.php'; 

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();

$role = $_SESSION['role'] ?? 'parent';

if (in_array($role, ['admin', 'nazim', 'teacher'], true)) {
    try {
        // صرف فعال اور غیر ڈیلیٹ شدہ طلباء گنیں
        $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted=0 AND (status='Active' OR status='' OR status IS NULL)")->fetchColumn();
        $total_alumni   = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted=0 AND status='Alumni'")->fetchColumn();
        $total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers WHERE is_deleted=0 AND (status='Active' OR status='' OR status IS NULL)")->fetchColumn();
        $total_notices  = $pdo->query("SELECT COUNT(*) FROM notices WHERE is_deleted=0")->fetchColumn();
        $total_classes  = $pdo->query("SELECT COUNT(*) FROM classes WHERE status='Active' OR status='' OR status IS NULL")->fetchColumn();
        
        $today = date('Y-m-d');
        // آج کی حاضری: حاضر، غیر حاضر، رخصت، تاخیر فیصد
        $today_rows = $pdo->query("
            SELECT status_1, status_2, status_3, status_4, late_1, late_2, late_3, late_4
            FROM attendance
            WHERE date = '$today' AND is_deleted=0
        ")->fetchAll(PDO::FETCH_ASSOC);
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
        
        // Monthly Attendance
        $first_day = date('Y-m-01');
        $total_possible = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date >= '$first_day' AND is_deleted=0")->fetchColumn();
        $total_present  = $pdo->query("
            SELECT COUNT(*) FROM attendance 
            WHERE date >= '$first_day' AND is_deleted=0
              AND (status_1='P' OR status_2='P' OR status_3='P' OR status_4='P')
        ")->fetchColumn();
        $monthly_att_percent = $total_possible > 0 ? round(($total_present / $total_possible) * 100, 1) : 0;

        $management_insight = "رپورٹ تیار کی جا رہی ہے...";

        // رواں ماہ کے اخراجات
        $current_month_expense = $pdo->query("SELECT SUM(amount) FROM finance WHERE LOWER(type)='expense' AND is_deleted=0 AND date >= '$first_day'")->fetchColumn();
        $monthly_expense = (float)($current_month_expense ?? 0);

    } catch (PDOException $e) { 
        // ایرر لاگ کریں تاکہ debugging آسان ہو
        error_log("Dashboard PDO Error: " . $e->getMessage());
        $total_students=0; $total_alumni=0; $total_teachers=0; $present_today_pct=0; $absent_today_pct=0; $leave_today_pct=0; $late_today_pct=0; 
        $total_notices=0; $monthly_att_percent=0; $total_classes=0; 
        $management_insight="ڈیٹا بیس سے رابطہ نہ ہو سکا۔"; $finance_balance=0;
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
                <p class="m-0 small" style="line-height: 1.6;" id="ai-insight-text"><?php echo $management_insight; ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if(in_array($role, ['admin', 'nazim', 'teacher'], true)): ?>
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
        <?php if(has_perm('manage_finance')): ?>
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
                                <th>داخلہ Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt_recent = $pdo->query("SELECT * FROM students WHERE is_deleted=0 ORDER BY id DESC LIMIT 5");
                            while($row = $stmt_recent->fetch(PDO::FETCH_ASSOC)) {
                                echo "<tr><td class='px-4 text-muted'>#{$row['id']}</td><td class='fw-bold text-primary'>{$row['name']}</td><td>{$row['father_name']}</td><td>".translateDate($row['admission_date'])."</td></tr>";
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

