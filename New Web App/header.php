<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 

// Login check and redirect
$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['user_id']) && $current_page != 'index.php' && $current_page != 'login.php') {
    header("Location: login.php");
    exit;
}

// Notification system
$pending_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    try {
        $p_fee = $pdo->query("SELECT COUNT(*) FROM fee_submissions WHERE status = 'Pending'")->fetchColumn();
        $p_leave = $pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'Pending'")->fetchColumn();
        $p_complaints = $pdo->query("SELECT COUNT(*) FROM parent_complaints WHERE status = 'Pending'")->fetchColumn();
        // Admission Applications pending count
        $p_admissions = 0;
        try { $p_admissions = $pdo->query("SELECT COUNT(*) FROM admission_applications WHERE LOWER(status)='pending'")->fetchColumn(); } catch(Exception $e){}
        $pending_count = $p_fee + $p_leave + $p_complaints;
    } catch (Exception $e) { $pending_count = 0; }
}

// Language and session settings
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; }
$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'ur';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Guest';

function has_perm($perm) {
    if (!isset($_SESSION['role'])) return false;
    if ($_SESSION['role'] === 'admin') return true;
    $perms = $_SESSION['permissions'] ?? [];
    return in_array($perm, $perms);
}

?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('app_name'); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="shortcut icon" href="logo.png" type="image/png">
    
    <?php if($lang == 'ur'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <script>
        // Immediate theme load logic to prevent FOUC
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
        })();

        function setTheme(themeName) {
            document.documentElement.className = '';
            if (themeName !== 'default') {
                document.documentElement.classList.add('theme-' + themeName);
                localStorage.setItem('theme', themeName);
            } else {
                localStorage.setItem('theme', 'default');
            }
        }
    </script>
    
    <?php include __DIR__ . '/theme_styles.php'; ?>
</head>
<body>

<?php 
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
?>
<div id="wrapper">
    <div id="sidebar-wrapper">
        <div class="text-center py-3 border-bottom border-secondary bg-dark">
            <img src="logo.png" alt="Logo" style="width: 65px; height: 65px; object-fit: contain; margin-bottom: 8px; border-radius: 50%; border: 2px solid white; background-color: white;">
            <h5 class="mb-2 text-white fw-bold"><?php echo e(t('app_name')); ?></h5>
            
            <!-- Masjid / Madrasa Switcher Tabs -->
            <div class="d-flex justify-content-center px-3 mt-2">
                <div class="btn-group w-100 rounded-pill p-1" style="background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255,255,255,0.2);">
                    <a href="switch_system.php?sys=Madrasa" class="btn btn-sm rounded-pill fw-bold <?php echo ($system_type === 'Madrasa') ? 'btn-success text-white shadow-sm' : 'text-white-50 border-0'; ?>" style="flex: 1; text-decoration: none;">
                        <i class="fas fa-university me-1"></i> <?php echo ($lang=='ur') ? 'مدرسہ' : 'Madrasa'; ?>
                    </a>
                    <a href="switch_system.php?sys=Masjid" class="btn btn-sm rounded-pill fw-bold <?php echo ($system_type === 'Masjid') ? 'btn-success text-white shadow-sm' : 'text-white-50 border-0'; ?>" style="flex: 1; text-decoration: none;">
                        <i class="fas fa-mosque me-1"></i> <?php echo ($lang=='ur') ? 'مسجد' : 'Masjid'; ?>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="list-group list-group-flush mt-2 pb-5">
            <a href="./index.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='index.php'?'active-link':''; ?>"><i class="fas fa-globe me-2"></i> <?php echo t('home_page'); ?></a>
            
            <?php if($system_type === 'Masjid'): ?>
                <!-- MASJID NAVIGATION -->
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="masjid_dashboard.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='masjid_dashboard.php'?'active-link':''; ?>"><i class="fas fa-mosque me-2 text-success"></i> <?php echo ($lang=='ur') ? 'مسجد ڈیش بورڈ' : 'Masjid Dashboard'; ?></a>
                <?php endif; ?>

                <?php if(in_array($role, ['admin', 'nazim', 'teacher'])): ?>
                    <!-- مکتب (نظامِ تعلیم) -->
                    <a href="#maktabSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-book-quran me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'مکتب (نظامِ تعلیم)' : 'Maktab System'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse show" id="maktabSubmenu" style="background: rgba(0,0,0,0.2);">
                        <?php if(has_perm('manage_classes')): ?>
                        <a href="manage_classes.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='manage_classes.php'?'active-link':''; ?>"><i class="fas fa-chalkboard me-2"></i> <?php echo ($lang=='ur') ? 'قرآنی کلاسز (حفظ، ناظرہ، قاعدہ، حسن صوت)' : 'Quran Classes'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_students')): ?>
                        <a href="students.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='students.php'?'active-link':''; ?>"><i class="fas fa-user-graduate me-2"></i> <?php echo ($lang=='ur') ? 'طلبہ ریکارڈ' : 'Students Record'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_teachers')): ?>
                        <a href="teachers.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='teachers.php'?'active-link':''; ?>"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo ($lang=='ur') ? 'اساتذہ ریکارڈ' : 'Teachers Record'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
                        <a href="academic_entry_dashboard.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='academic_entry_dashboard.php'?'active-link':''; ?>"><i class="fas fa-edit me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry'; ?></a>
                        <a href="academic_view_dashboard.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='academic_view_dashboard.php'?'active-link':''; ?>"><i class="fas fa-chart-line me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (دیکھیں)' : 'Academic View'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_attendance')): ?>
                        <a href="attendance.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='attendance.php'?'active-link':''; ?>"><i class="fas fa-calendar-check me-2"></i> <?php echo ($lang=='ur') ? 'حاضری رجسٹر' : 'Attendance Register'; ?></a>
                        <a href="attendance_report.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='attendance_report.php'?'active-link':''; ?>"><i class="fas fa-file-signature me-2"></i> <?php echo ($lang=='ur') ? 'حاضری رپورٹ' : 'Attendance Report'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_exams')): ?>
                        <a href="exams.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='exams.php'?'active-link':''; ?>"><i class="fas fa-clipboard-list me-2"></i> <?php echo ($lang=='ur') ? 'امتحانات' : 'Exams'; ?></a>
                        <?php endif; ?>
                    </div>

                    <!-- وظائف و قرضہ جات -->
                    <a href="staff_loans.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='staff_loans.php'?'active-link':''; ?>"><i class="fas fa-hand-holding-usd me-2 text-info"></i> <?php echo ($lang=='ur') ? 'وظائف و قرض/ایڈوانس' : 'Stipends & Loans'; ?></a>

                    <!-- فنانس سیکشن -->
                    <a href="#masjidFinanceSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-coins me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'فنانس سیکشن (مسجد)' : 'Masjid Finance'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse show" id="masjidFinanceSubmenu" style="background: rgba(0,0,0,0.2);">
                        <a href="simple_finance.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='simple_finance.php'?'active-link':''; ?>"><i class="fas fa-cash-register me-2"></i> <?php echo ($lang=='ur') ? 'آمدن و خرچ' : 'Income & Expense'; ?></a>
                        <a href="in_kind_donations.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='in_kind_donations.php'?'active-link':''; ?>"><i class="fas fa-gift me-2"></i> <?php echo ($lang=='ur') ? 'عطیات / اشیاء' : 'In-Kind Donations'; ?></a>
                        <a href="masjid_assets.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='masjid_assets.php'?'active-link':''; ?>"><i class="fas fa-boxes me-2"></i> <?php echo ($lang=='ur') ? 'اثاثہ جات' : 'Assets'; ?></a>
                        <a href="finance_report.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='finance_report.php'?'active-link':''; ?>"><i class="fas fa-chart-bar me-2"></i> <?php echo ($lang=='ur') ? 'فنانس رپورٹ' : 'Finance Report'; ?></a>
                    </div>

                    <!-- اعتکاف ریکارڈ -->
                    <a href="masjid_itikaf.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='masjid_itikaf.php'?'active-link':''; ?>"><i class="fas fa-user-clock me-2 text-success"></i> <?php echo ($lang=='ur') ? 'اعتکاف ریکارڈ' : 'Itikaf Record'; ?></a>

                    <?php if($role == 'admin'): ?>
                    <a href="manage_users.php" class="list-group-item"><i class="fas fa-users-cog me-2"></i> <?php echo t('manage_users'); ?></a>
                    <a href="settings.php" class="list-group-item"><i class="fas fa-cog me-2"></i> <?php echo t('settings'); ?></a>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <!-- MADRASA NAVIGATION -->
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active-link':''; ?>"><i class="fas fa-tachometer-alt me-2"></i> <?php echo t('dashboard'); ?></a>
                <?php endif; ?>

                <?php if(in_array($role, ['admin', 'nazim', 'teacher'])): ?>
                    <?php if(has_perm('manage_requests')): ?>
                    <a href="admin_requests.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='admin_requests.php'?'active-link':''; ?>">
                        <span><i class="fas fa-bell me-2"></i> <?php echo t('requests'); ?></span>
                        <?php if($pending_count > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?php echo $pending_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_notices')): ?>
                    <a href="manage_notices.php" class="list-group-item"><i class="fas fa-bullhorn me-2"></i> <?php echo t('notice_board'); ?></a>
                    <?php endif; ?>
                    
                    <?php if(has_perm('manage_classes')): ?>
                    <a href="manage_classes.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='manage_classes.php'?'active-link':''; ?>"><i class="fas fa-chalkboard me-2"></i> <?php echo t('manage_classes'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_admissions')): ?>
                    <a href="admin_admissions.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='admin_admissions.php'?'active-link':''; ?>">
                        <span><i class="fas fa-graduation-cap me-2"></i> <?php echo t('manage_admissions'); ?></span>
                        <?php if(isset($p_admissions) && $p_admissions > 0): ?>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $p_admissions; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    
                    <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
                    <a href="academic_entry_dashboard.php" class="list-group-item"><i class="fas fa-book-open me-2"></i> <?php echo t('manage_academic_dashboard'); ?></a>
                    <a href="academic_view_dashboard.php" class="list-group-item"><i class="fas fa-chart-line me-2"></i> &#1578;&#1593;&#1604;&#1740;&#1605;&#1740; &#1705;&#1575;&#1585;&#1705;&#1585;&#1583;&#1711;&#1740; (&#1583;&#1740;&#1705;&#1726;&#1740;&#1722;)</a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_students')): ?>
                    <a href="students.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='students.php'?'active-link':''; ?>"><i class="fas fa-user-graduate me-2"></i> <?php echo t('students'); ?></a>
                    <a href="alumni.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='alumni.php'?'active-link':''; ?>"><i class="fas fa-award me-2"></i> <?php echo t('alumni'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_teachers')): ?>
                    <a href="teachers.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teachers.php'?'active-link':''; ?>"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo t('teachers'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_teacher_attendance') || has_perm('manage_attendance')): ?>
                    <a href="teachers_attendance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teachers_attendance.php'?'active-link':''; ?>"><i class="fas fa-user-clock me-2"></i> &#1581;&#1575;&#1590;&#1585;&#1740; &#1575;&#1587;&#1575;&#1578;&#1584;&#1729;</a>
                    <a href="teacher_attendance_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teacher_attendance_report.php'?'active-link':''; ?>"><i class="fas fa-file-signature me-2"></i> &#1581;&#1575;&#1590;&#1585;&#1740; &#1575;&#1587;&#1575;&#1578;&#1584;&#1729; &#1585;&#1662;&#1608;&#1585;&#1657;</a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_attendance')): ?>
                    <a href="attendance.php" class="list-group-item"><i class="fas fa-calendar-check me-2"></i> <?php echo t('attendance_register'); ?></a>
                    <a href="attendance_report.php" class="list-group-item"><i class="fas fa-file-signature me-2"></i> <?php echo t('attendance_report'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_exams')): ?>
                    <a href="exams.php" class="list-group-item"><i class="fas fa-clipboard-list me-2"></i> <?php echo t('exams'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_finance') || has_perm('manage_erp')): ?>
                    <a href="#erpSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-file-invoice-dollar me-2 text-warning"></i> <?php echo t('erp_accounts'); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse" id="erpSubmenu" style="background: rgba(0,0,0,0.2);">
                        <?php if(has_perm('manage_finance')): ?>
                        <a href="simple_finance.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='simple_finance.php'?'active-link':''; ?>"><i class="fas fa-coins me-2"></i> &#1570;&#1605;&#1583;&#1606; &#1608; &#1582;&#1585;&#1670;</a>
                        <a href="finance_report.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='finance_report.php'?'active-link':''; ?>"><i class="fas fa-chart-bar me-2"></i> <?php echo t('finance_report'); ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_erp')): ?>
                        <a href="finance.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='finance.php'?'active-link':''; ?>"><i class="fas fa-wallet me-2"></i> <?php echo t('finance_dashboard_menu'); ?></a>
                        <a href="manage_departments.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='manage_departments.php'?'active-link':''; ?>"><i class="fas fa-building me-2"></i> &#1588;&#1593;&#1576;&#1729; &#1580;&#1575;&#1578; / &#1576;&#1585;&#1575;&#1606;&#1670;&#1586;</a>
                        <a href="acc_chart.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_chart.php'?'active-link':''; ?>"><i class="fas fa-sitemap me-2"></i> <?php echo t('chart_of_accounts_menu'); ?></a>
                        <a href="acc_vouchers.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_vouchers.php'?'active-link':''; ?>"><i class="fas fa-receipt me-2"></i> <?php echo t('vouchers_menu'); ?></a>
                        <a href="acc_ledger.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_ledger.php'?'active-link':''; ?>"><i class="fas fa-book me-2"></i> <?php echo t('general_ledger_menu'); ?></a>
                        <a href="acc_trial_balance.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_trial_balance.php'?'active-link':''; ?>"><i class="fas fa-balance-scale me-2"></i> میزان (Trial Balance)</a>
                        <a href="acc_income_statement.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_income_statement.php'?'active-link':''; ?>"><i class="fas fa-chart-pie me-2"></i> آمدن و اخراجات (Income Stmt)</a>
                        <a href="acc_balance_sheet.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_balance_sheet.php'?'active-link':''; ?>"><i class="fas fa-balance-scale-left me-2"></i> بیلنس شیٹ (Balance Sheet)</a>
                        <a href="acc_invoices.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_invoices.php'?'active-link':''; ?>"><i class="fas fa-file-invoice me-2"></i> <?php echo t('fee_invoices_menu'); ?></a>
                        <a href="acc_payroll.php" class="list-group-item ps-5 border-0 text-white <?php echo basename($_SERVER['PHP_SELF'])=='acc_payroll.php'?'active-link':''; ?>"><i class="fas fa-users-cog me-2"></i> <?php echo t('payroll_menu'); ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if(has_perm('add_complaint') || in_array($role, ['admin', 'nazim'])): ?>
                    <a href="add_complaint.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='add_complaint.php'?'active-link':''; ?>"><i class="fas fa-plus-circle me-2 text-danger"></i> &#1588;&#1705;&#1575;&#1740;&#1578; &#1705;&#1575; &#1575;&#1606;&#1583;&#1585;&#1575;&#1580;</a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_complaints') || in_array($role, ['admin', 'nazim'])): ?>
                    <a href="manage_complaints.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='manage_complaints.php'?'active-link':''; ?>"><i class="fas fa-exclamation-triangle me-2 text-danger"></i> &#1588;&#1705;&#1575;&#1740;&#1575;&#1578; &#1705;&#1575; &#1585;&#1740;&#1705;&#1575;&#1585;&#1672;</a>
                    <?php endif; ?>
                    <?php if($role == 'admin'): ?>
                    <a href="manage_users.php" class="list-group-item"><i class="fas fa-users-cog me-2"></i> <?php echo t('manage_users'); ?></a>
                    <a href="settings.php" class="list-group-item"><i class="fas fa-cog me-2"></i> <?php echo t('settings'); ?></a>
                    <?php endif; ?>
                
                <?php elseif($role == 'parent'): ?>
                    <a href="parent_report.php" class="list-group-item"><i class="fas fa-user-graduate me-2"></i> <?php echo t('my_report'); ?></a>
                    <a href="parent_fee.php" class="list-group-item"><i class="fas fa-money-bill-wave me-2"></i> <?php echo t('pay_fee'); ?></a>
                    <a href="parent_leave.php" class="list-group-item"><i class="fas fa-envelope-open-text me-2"></i> <?php echo t('apply_leave'); ?></a>
                    <a href="parent_complaint.php" class="list-group-item text-danger"><i class="fas fa-frown me-2"></i> <?php echo t('parent_complaint'); ?></a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="profile.php" class="list-group-item text-warning"><i class="fas fa-key me-2"></i> <?php echo t('profile'); ?></a>
                <a href="logout.php" class="list-group-item text-danger"><i class="fas fa-sign-out-alt me-2"></i> <?php echo t('logout'); ?></a>
            <?php else: ?>
                <a href="login.php" class="list-group-item text-success fw-bold"><i class="fas fa-sign-in-alt me-2"></i> <?php echo t('login'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="sidebar-overlay" id="overlay"></div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-white shadow-sm px-3 mb-4 sticky-top">
            <button class="btn text-primary border-0 fs-3" id="menu-toggle"><i class="fas fa-bars"></i></button>
            <div class="ms-3">
                <span class="text-primary fw-bold d-none d-md-block"><img src="logo.png" alt="Logo" style="width: 30px; height: 30px; border-radius: 50%; margin-left: 5px; object-fit: contain; vertical-align: middle;"><?php echo (isset($_SESSION['user_id'])) ? e(t('app_name'))." (".e($user_name).")" : e(t('app_name')); ?></span>
                <span class="text-primary fw-bold d-md-none small"><img src="logo.png" alt="Logo" style="width: 20px; height: 20px; border-radius: 50%; margin-left: 5px; object-fit: contain; vertical-align: middle;"><?php echo e(t('app_name')); ?></span>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Theme Switcher Dropdown (Minimalist & Professional) -->
                <div class="dropdown">
                    <button class="btn btn-outline-primary btn-sm rounded-circle p-2 d-flex align-items-center justify-content-center" type="button" id="themeDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="width: 36px; height: 36px;" title="<?php echo ($lang=='ur') ? 'تھیم تبدیل کریں' : 'Change Theme'; ?>">
                        <i class="fas fa-palette"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 p-2" aria-labelledby="themeDropdown" style="border-radius: 16px; min-width: auto;">
                        <li class="d-flex align-items-center gap-2 px-1">
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #284D3D;" href="javascript:void(0)" onclick="setTheme('default')" title="<?php echo ($lang=='ur') ? 'کلاسک سبز' : 'Classic Green'; ?>">
                                <span class="theme-dot" style="background:#D4AC0D; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #0c0f16;" href="javascript:void(0)" onclick="setTheme('dark-emerald')" title="<?php echo ($lang=='ur') ? 'ڈارک ایمرلڈ' : 'Dark Emerald'; ?>">
                                <span class="theme-dot" style="background:#00e676; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #0b2545;" href="javascript:void(0)" onclick="setTheme('classic-navy')" title="<?php echo ($lang=='ur') ? 'نیوی نیلگا' : 'Classic Navy'; ?>">
                                <span class="theme-dot" style="background:#3b82f6; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #34495e;" href="javascript:void(0)" onclick="setTheme('warm-terracotta')" title="<?php echo ($lang=='ur') ? 'ٹیراکوٹا' : 'Warm Terracotta'; ?>">
                                <span class="theme-dot" style="background:#c87a53; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #e0f2f1;" href="javascript:void(0)" onclick="setTheme('mint-glass')" title="<?php echo ($lang=='ur') ? 'منٹ گلاس' : 'Mint Glass'; ?>">
                                <span class="theme-dot" style="background:#00bfa5; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                            <a class="p-1 d-flex align-items-center justify-content-center rounded-circle border" style="width: 32px; height: 32px; background: #3c4e4b;" href="javascript:void(0)" onclick="setTheme('sage-sand')" title="<?php echo ($lang=='ur') ? 'سیج اینڈ سینڈ' : 'Sage & Sand'; ?>">
                                <span class="theme-dot" style="background:#7b9e87; width: 12px; height: 12px; border-radius: 50%;"></span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <a href="?lang=<?php echo ($lang=='ur')?'en':'ur'; ?>" class="lang-btn btn btn-outline-success btn-sm rounded-pill px-3 fw-bold">
                    <?php echo ($lang=='ur') ? 'English' : '&#1575;&#1585;&#1583;&#1608;'; ?>
                </a>
            </div>
        </nav>
        <div class="container-fluid px-2 px-md-4">



