<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
try {
    require_once 'db.php';
} catch (Throwable $e) {
    die("<div style='font-family:sans-serif;padding:40px;text-align:center;direction:rtl;'><h2>ڈیٹابیس رابطہ کی خرابی</h2><p style='color:#c00;font-size:16px;'>" . htmlspecialchars($e->getMessage()) . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")</p></div>");
} 

// Ensure session restoration from persistent auth cookie
if (empty($_SESSION['user_id']) && function_exists('madrasa_restore_session_from_cookie')) {
    madrasa_restore_session_from_cookie($pdo);
}

// Login check, session idle timeout, and redirect
$current_page = basename($_SERVER['PHP_SELF']);
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > (86400 * 30))) {
        session_unset();
        session_destroy();
        if (function_exists('madrasa_clear_auth_cookies')) {
            madrasa_clear_auth_cookies();
        }
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Auto-heal session ID if user ID in database was reassigned (multi-tenant safe)
    if (!empty($_SESSION['username'])) {
        try {
            $sessTid = (int)($_SESSION['tenant_id'] ?? 1);
            $checkUser = $pdo->prepare("SELECT id, role, name, permissions FROM admins WHERE username = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
            $checkUser->execute([$_SESSION['username'], $sessTid]);
            $cuRow = $checkUser->fetch(PDO::FETCH_ASSOC);
            if ($cuRow && (int)$cuRow['id'] !== (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_id'] = (int)$cuRow['id'];
                $_SESSION['role'] = $cuRow['role'];
                $_SESSION['name'] = $cuRow['name'];
                if (!empty($cuRow['permissions'])) {
                    $_SESSION['permissions'] = json_decode($cuRow['permissions'], true) ?: [];
                }
            }
        } catch (Throwable $e) {}
    }
} elseif ($current_page != 'index.php' && $current_page != 'login.php' && $current_page != 'register.php') {
    header("Location: login.php");
    exit;
}

// Tenant Resolution for Session
$current_tenant_id = get_current_tenant_id();
if (isset($_SESSION['user_id']) && (!isset($_SESSION['tenant_id']) || empty($_SESSION['tenant_name']))) {
    $_SESSION['tenant_id'] = $current_tenant_id;
    $_SESSION['tenant_code'] = get_current_tenant_code();
    try {
        $tStmt = $pdo->prepare("SELECT name, status FROM tenants WHERE id = ?");
        $tStmt->execute([$current_tenant_id]);
        $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $_SESSION['tenant_name'] = $tRow['name'];
            $_SESSION['tenant_status'] = $tRow['status'];
        }
    } catch (Throwable $e) {}
}

if (!function_exists('is_mod_allowed')) {
    function is_mod_allowed($moduleKey) {
        global $pdo, $current_tenant_id;
        if (function_exists('is_module_allowed_for_tenant')) {
            return is_module_allowed_for_tenant($pdo, $moduleKey, $current_tenant_id);
        }
        return true;
    }
}

// Masjid wing permission guard: if not allowed, force Madrasa mode and block masjid pages
if (!is_mod_allowed('masjid_wing')) {
    if (($_SESSION['system_type'] ?? '') === 'Masjid') {
        $_SESSION['system_type'] = 'Madrasa';
    }
    $masjid_exclusive_pages = [
        'masjid_dashboard.php', 'maktab_dashboard.php', 'maktab_classes.php',
        'maktab_students.php', 'maktab_teachers.php', 'maktab_attendance.php',
        'maktab_attendance_report.php', 'maktab_hifz.php', 'maktab_nazira.php',
        'maktab_qaida.php', 'maktab_exams.php', 'maktab_exam_results_entry.php',
        'maktab_academics.php', 'masjid_itikaf.php', 'masjid_staff.php'
    ];
    if (in_array($current_page, $masjid_exclusive_pages)) {
        header("Location: dashboard.php");
        exit;
    }
}

// Load Tenant Custom Branding Settings
$app_settings = get_tenant_settings($pdo, $current_tenant_id);
if (!empty($app_settings['madrasa_name']) && empty($_SESSION['tenant_name'])) {
    $_SESSION['tenant_name'] = $app_settings['madrasa_name'];
}
$sys_active = $_SESSION['system_type'] ?? 'Madrasa';
$header_madrasa_name = !empty($app_settings['madrasa_name']) ? $app_settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'جامعہ دارالعلوم');
$header_masjid_name = !empty($app_settings['masjid_name']) ? $app_settings['masjid_name'] : (!empty($_SESSION['tenant_name']) ? 'مسجد ' . $_SESSION['tenant_name'] : ($current_tenant_id === 1 ? 'مسجد اشرف المدارس' : 'مرکزی جامع مسجد'));
$header_current_name = ($sys_active === 'Masjid') ? $header_masjid_name : $header_madrasa_name;

$fallback_logo = ($current_tenant_id === 1) ? 'logo.png' : 'logo_mindcrafts.svg';
$header_logo_file = ($sys_active === 'Masjid' && !empty($app_settings['masjid_logo_path'])) ? $app_settings['masjid_logo_path'] : (!empty($app_settings['logo_path']) && $app_settings['logo_path'] !== 'logo.png' ? $app_settings['logo_path'] : $fallback_logo);
if (!file_exists(__DIR__ . '/' . $header_logo_file)) {
    $header_logo_file = 'logo_mindcrafts.svg';
}

// Check subscription status
$tenant_sub_status = check_tenant_subscription($pdo, $current_tenant_id);
$is_trial = false;
$days_left = 0;
$current_plan_name = 'بنیادی پیکج (Basic)';
$current_plan_code = 'basic';
$sub_ends_at = '';
try {
    $subInfo = $pdo->prepare("SELECT s.*, p.name as plan_name, p.code as plan_code FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id = p.id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1");
    $subInfo->execute([$current_tenant_id]);
    $subRow = $subInfo->fetch(PDO::FETCH_ASSOC);
    if ($subRow) {
        $is_trial = ($subRow['status'] === 'trial');
        if (!empty($subRow['plan_name'])) $current_plan_name = $subRow['plan_name'];
        if (!empty($subRow['plan_code'])) $current_plan_code = $subRow['plan_code'];
        $sub_ends_at = $subRow['ends_at'] ?: '';
        if ($subRow['ends_at']) {
            $days_left = max(0, (int)ceil((strtotime($subRow['ends_at']) - time()) / 86400));
        }
    }
} catch (Throwable $e) {}

// Notification & Smart Alert system (Modular Service)
require_once __DIR__ . '/services/NotificationBadgeService.php';
$pending_count = 0;
$smart_alerts_count = 0;
$daily_naaghah = 0;
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'nazim', 'teacher'])) {
    try {
        $sys_val = $_SESSION['system_type'] ?? 'Madrasa';
        $is_mas = ($sys_val === 'Masjid');
        $badgeData = NotificationBadgeService::getCounts($pdo, $current_tenant_id, $sys_val);
        $pending_count = $badgeData['total_pending'];
        $daily_naaghah = $badgeData['daily_naaghah'];
        $t_cond = " AND tenant_id = $current_tenant_id";
        $today_str = date('Y-m-d');

        $daily_abs = 0;
        try {
            if ($is_mas) {
                $daily_abs = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM period_attendance WHERE date = '$today_str' AND status IN ('A', 'Absent') AND (is_deleted = 0 OR is_deleted IS NULL) $t_cond")->fetchColumn();
            } else {
                $daily_abs = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM (
                    SELECT student_id FROM attendance WHERE date = '$today_str' AND (status_1 IN ('A', 'Absent') OR status_2 IN ('A', 'Absent') OR status_3 IN ('A', 'Absent') OR status_4 IN ('A', 'Absent')) AND (is_deleted = 0 OR is_deleted IS NULL) $t_cond
                    UNION
                    SELECT student_id FROM period_attendance WHERE date = '$today_str' AND status IN ('A', 'Absent') AND (is_deleted = 0 OR is_deleted IS NULL) $t_cond
                ) as abs_tbl")->fetchColumn();
            }
        } catch (Throwable $e) {}

        $user_id_for_count = (int)($_SESSION['user_id'] ?? 0);
        
        $read_count_today = 0;
        try {
            $read_count_today = (int)$pdo->query("SELECT COUNT(*) FROM smart_notification_reads WHERE user_id = $user_id_for_count AND alert_id LIKE '%_$today_str' AND is_deleted = 0")->fetchColumn();
        } catch (Throwable $e) {}

        $smart_alerts_count = max(0, $pending_count + $daily_naaghah + $daily_abs - $read_count_today);
    } catch (Throwable $e) { 
        $pending_count = 0; 
        $smart_alerts_count = 0; 
    }
}

// Language and session settings
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; }
$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'ur';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Guest';


function has_perm($perm) {
    if (!isset($_SESSION['role'])) return false;
    
    // Map individual permission to its parent module
    $permToModuleMap = [
        'manage_classes' => 'classes',
        'manage_students' => 'students',
        'manage_teachers' => 'teachers',
        'manage_teacher_attendance' => 'attendance',
        'manage_attendance' => 'attendance',
        'manage_attendance_report' => 'attendance',
        'manage_hifz' => 'hifz',
        'manage_hifz_daily' => 'hifz',
        'manage_hifz_report' => 'hifz',
        'manage_academic_dashboard' => 'hifz',
        'manage_academic_entry' => 'hifz',
        'manage_academic_view' => 'hifz',
        'manage_academics' => 'hifz',
        'manage_exams' => 'exams',
        'manage_admissions' => 'admissions',
        'manage_notices' => 'notices',
        'manage_requests' => 'requests',
        'add_complaint' => 'requests',
        'manage_complaints' => 'requests',
        'manage_finance' => 'simple_finance',
        'manage_finance_entry' => 'simple_finance',
        'manage_finance_report' => 'simple_finance',
        'manage_financial_reports' => 'simple_finance',
        'manage_fee_defaulters' => 'simple_finance',
        'manage_assets' => 'assets_donations',
        'manage_in_kind_donations' => 'assets_donations',
        'manage_stipends' => 'payroll',
        'manage_loans' => 'loans',
        'manage_erp' => 'erp_accounts',
        'manage_library' => 'library',
        'manage_inventory' => 'inventory',
        'manage_hostel' => 'hostel',
        'manage_recruitment' => 'recruitment',
        'manage_website' => 'cms',
        'manage_saas' => 'saas',
    ];

    if (isset($permToModuleMap[$perm]) && !is_mod_allowed($permToModuleMap[$perm])) {
        return false;
    }

    $perms = $_SESSION['permissions'] ?? [];
    return has_permission($_SESSION['role'], $perms, $perm);
}

if (!function_exists('get_today_hijri_date')) {
    function get_today_hijri_date() {
        try {
            $d = (int)date('d');
            $m = (int)date('m');
            $y = (int)date('Y');
            if ($m <= 2) { $y -= 1; $m += 12; }
            $a = floor($y / 100);
            $b = 2 - $a + floor($a / 4);
            $jd = floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $b - 1524;
            $l = $jd - 1948440 + 10632;
            $n = floor(($l - 1) / 10631);
            $l = $l - 10631 * $n + 354;
            $j = (floor((10985 - $l) / 5316)) * (floor((50 * $l) / 17719)) + (floor($l / 5670)) * (floor((43 * $l) / 15238));
            $l = $l - (floor((30 - $j) / 15)) * (floor((17719 * $j) / 50)) - (floor($j / 16)) * (floor((15238 * $j) / 43)) + 29;
            $hm = (int)floor((24 * $l) / 709);
            $hd = (int)($l - floor((709 * $hm) / 24));
            $hy = (int)(30 * $n + $j - 30);

            $months = ['', 'محرم', 'صفر', 'ربیع الاول', 'ربیع الثانی', 'جمادی الاول', 'جمادی الثانی', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذوالقعدہ', 'ذوالحجہ'];
            $mName = $months[$hm] ?? (string)$hm;
            return "$hd $mName $hy ھ";
        } catch (Throwable $e) {
            return date('d-m-Y');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($header_current_name); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($header_logo_file); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($header_logo_file); ?>" type="image/png">
    
    <?php if($lang == 'ur'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <script>
        const validThemes = ['default', 'parchment-sage', 'pastel-sky', 'espresso-cream', 'classic-emerald', 'aurora-glass', 'soft-neumorphic', 'obsidian-glow', 'warm-sand'];
        const isUrdu = '<?php echo $lang; ?>' === 'ur';
        const themeInfoMap = {
            'default': { name: isUrdu ? 'عاجی زمرد و شاہی وقار (ڈیفالٹ)' : 'Ivory Parchment & Sage (Default)', short: isUrdu ? 'عاجی زمرد' : 'Parchment Sage' },
            'parchment-sage': { name: isUrdu ? 'عاجی زمرد و شاہی وقار (ڈیفالٹ)' : 'Ivory Parchment & Sage (Default)', short: isUrdu ? 'عاجی زمرد' : 'Parchment Sage' },
            'pastel-sky': { name: isUrdu ? 'نرم نیلم و فیروزی لطافت' : 'Pastel Sky Breeze', short: isUrdu ? 'پیسٹل اسکائی' : 'Pastel Sky' },
            'espresso-cream': { name: isUrdu ? 'شاہی قہوہ و صندل' : 'Dark Espresso & Warm Cream', short: isUrdu ? 'اسپریسو کریم' : 'Espresso Cream' },
            'classic-emerald': { name: isUrdu ? 'کلاسک ادارہ جاتی زمرد' : 'Classic Royal Emerald', short: isUrdu ? 'کلاسک زمرد' : 'Classic Emerald' },
            'aurora-glass': { name: isUrdu ? 'اورورا فراسٹیڈ گلاس' : 'Aurora Frosted Glass', short: isUrdu ? 'اورورا گلاس' : 'Aurora Glass' },
            'soft-neumorphic': { name: isUrdu ? 'سافٹ اسلیٹ نیومورفزم' : 'Soft Slate Neumorphic', short: isUrdu ? 'سافٹ اسلیٹ' : 'Soft Slate' },
            'obsidian-glow': { name: isUrdu ? 'لگژری اوبسیڈین سائبر' : 'Luxury Obsidian Cyber Glow', short: isUrdu ? 'اوبسیڈین گلو' : 'Obsidian Glow' },
            'warm-sand': { name: isUrdu ? 'شاہی صندل نیومورفزم' : 'Warm Sand Neumorphic', short: isUrdu ? 'شاہی صندل' : 'Warm Sand' }
        };

        function updateThemeUI(themeName) {
            const current = themeName || localStorage.getItem('theme') || 'default';
            const info = themeInfoMap[current] || themeInfoMap['default'];
            
            document.querySelectorAll('.current-theme-name').forEach(function(el) {
                el.textContent = info.name;
            });
            document.querySelectorAll('.current-theme-label').forEach(function(el) {
                el.textContent = info.short;
            });
            
            document.querySelectorAll('.theme-select-btn').forEach(function(btn) {
                const bTheme = btn.getAttribute('data-theme');
                const isMatch = (bTheme === current) || (bTheme === 'default' && (current === 'default' || current === 'parchment-sage'));
                const check = btn.querySelector('.theme-check');
                if (isMatch) {
                    btn.classList.add('active-theme-ring');
                    if (check) check.style.display = 'block';
                } else {
                    btn.classList.remove('active-theme-ring');
                    if (check) check.style.display = 'none';
                }
            });
        }

        // Immediate theme load logic to prevent FOUC
        (function() {
            let savedTheme = localStorage.getItem('theme') || 'default';
            if (!validThemes.includes(savedTheme)) {
                savedTheme = 'default';
                localStorage.setItem('theme', 'default');
            }
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    updateThemeUI(savedTheme);
                });
            } else {
                updateThemeUI(savedTheme);
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
            updateThemeUI(themeName);
        }
    </script>
    
    <?php include __DIR__ . '/theme_styles.php'; ?>
    <style>
        @keyframes beaconPulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 9px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        @keyframes blinkRedDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.15; transform: scale(0.8); }
        }
        .pulsing-beacon-btn {
            animation: beaconPulse 1.8s infinite;
            background: #fff1f2 !important;
            border: 1.5px solid #ef4444 !important;
            color: #b91c1c !important;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            text-decoration: none;
        }
        .pulsing-beacon-btn:hover {
            background: #ffe4e6 !important;
            color: #991b1b !important;
            transform: scale(1.03);
        }
        .blinking-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            background-color: #ef4444;
            border-radius: 50%;
            animation: blinkRedDot 1s infinite;
            box-shadow: 0 0 8px #ef4444;
        }
    </style>
</head>
<body>

<?php 
$system_type = $_SESSION['system_type'] ?? 'Madrasa';
?>
<div id="wrapper">
    <div id="sidebar-wrapper">
        <div class="sidebar-top-header text-center py-3">
            <img src="<?php echo htmlspecialchars($header_logo_file); ?>" alt="Logo" class="sidebar-logo mb-2">
            <h5 class="sidebar-app-title mb-2 fw-bold"><?php echo htmlspecialchars($header_current_name); ?></h5>
            
            <!-- Masjid / Madrasa Switcher Tabs -->
            <?php if (is_mod_allowed('masjid_wing')): ?>
            <div class="d-flex justify-content-center px-3 mt-2">
                <div class="btn-group w-100 sidebar-switcher-group rounded-pill p-1">
                    <a href="switch_system.php?sys=Madrasa" class="btn btn-sm rounded-pill fw-bold system-switch-btn <?php echo ($system_type === 'Madrasa') ? 'active' : ''; ?>" style="flex: 1; text-decoration: none;">
                        <i class="fas fa-university me-1"></i> <?php echo ($lang=='ur') ? 'مدرسہ' : 'Madrasa'; ?>
                    </a>
                    <a href="switch_system.php?sys=Masjid" class="btn btn-sm rounded-pill fw-bold system-switch-btn <?php echo ($system_type === 'Masjid') ? 'active' : ''; ?>" style="flex: 1; text-decoration: none;">
                        <i class="fas fa-mosque me-1"></i> <?php echo ($lang=='ur') ? 'مسجد' : 'Masjid'; ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="list-group list-group-flush mt-2 pb-5">
            <a href="./index.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='index.php'?'active-link':''; ?>"><i class="fas fa-globe me-2"></i> <?php echo t('home_page'); ?></a>
            
            <?php if($system_type === 'Masjid'): ?>
                <!-- MASJID NAVIGATION -->
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="masjid_dashboard.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='masjid_dashboard.php'?'active-link':''; ?>"><i class="fas fa-mosque me-2 text-success"></i> <?php echo ($lang=='ur') ? 'مسجد ڈیش بورڈ' : 'Masjid Dashboard'; ?></a>
                    <a href="smart_notifications.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='smart_notifications.php'?'active-link':''; ?>">
                        <span><i class="fas fa-bell text-warning me-2"></i> <?php echo ($lang=='ur') ? 'اسمارٹ الرٹس و نوٹیفیکیشن' : 'Smart Alerts'; ?></span>
                        <?php if(isset($smart_alerts_count) && $smart_alerts_count > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?php echo $smart_alerts_count; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if(in_array($role, ['admin', 'nazim', 'teacher'])): 
                    $cur_p = basename($_SERVER['PHP_SELF']);
                    $maktab_pages = [
                        'manage_classes.php', 'maktab_classes.php', 'students.php', 'teachers.php', 'teacher_leaves.php',
                        'academic_entry_dashboard.php', 'academic_view_dashboard.php', 
                        'attendance.php', 'attendance_report.php', 'exams.php', 'exam_results_entry.php',
                        'student_profile.php', 'hifz.php', 'maktab_hifz.php', 'nazira.php',
                        'maktab_nazira.php', 'qaida_entry.php', 'maktab_qaida.php', 'takrar.php',
                        'maktab_takrar.php', 'husn_saut.php', 'academic_report_view.php', 'maktab_academic_report.php'
                    ];
                    $maktab_open = in_array($cur_p, $maktab_pages);

                    $masjid_finance_pages = [
                        'simple_finance.php', 'in_kind_donations.php', 'assets.php', 'finance_report.php'
                    ];
                    $masjid_finance_open = in_array($cur_p, $masjid_finance_pages);
                ?>
                    <!-- مکتب (نظامِ تعلیم) -->
                    <a href="#maktabSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center <?php echo $maktab_open ? '' : 'collapsed'; ?>" aria-expanded="<?php echo $maktab_open ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-book-quran me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'مکتب (نظامِ تعلیم)' : 'Maktab System'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse sidebar-submenu <?php echo $maktab_open ? 'show' : ''; ?>" id="maktabSubmenu">
                        <?php if(has_perm('manage_classes')): ?>
                        <a href="maktab_classes.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='maktab_classes.php'?'active-link':''; ?>"><i class="fas fa-chalkboard me-2"></i> <?php echo ($lang=='ur') ? 'مکتب کلاسز' : 'Maktab Classes'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_students')): ?>
                        <a href="students.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='students.php'?'active-link':''; ?>"><i class="fas fa-user-graduate me-2"></i> <?php echo ($lang=='ur') ? 'طلبہ ریکارڈ' : 'Students Record'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_teachers')): ?>
                        <a href="teachers.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teachers.php'?'active-link':''; ?>"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo ($lang=='ur') ? 'اساتذہ ریکارڈ' : 'Teachers Record'; ?></a>
                        <a href="teacher_leaves.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='teacher_leaves.php'?'active-link':''; ?>">
                            <span><i class="fas fa-calendar-minus me-2"></i> <?php echo ($lang=='ur') ? 'اساتذہ رخصت' : 'Teacher Leaves'; ?></span>
                            <?php if(isset($p_teacher_leaves) && $p_teacher_leaves > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill"><?php echo $p_teacher_leaves; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
                        <a href="academic_entry_dashboard.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='academic_entry_dashboard.php'?'active-link':''; ?>"><i class="fas fa-edit me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry'; ?></a>
                        <a href="academic_view_dashboard.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='academic_view_dashboard.php'?'active-link':''; ?>"><i class="fas fa-chart-line me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (دیکھیں)' : 'Academic View'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_attendance')): ?>
                        <a href="attendance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='attendance.php'?'active-link':''; ?>"><i class="fas fa-calendar-check me-2"></i> <?php echo ($lang=='ur') ? 'حاضری رجسٹر' : 'Attendance Register'; ?></a>
                        <a href="attendance_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='attendance_report.php'?'active-link':''; ?>"><i class="fas fa-file-signature me-2"></i> <?php echo ($lang=='ur') ? 'حاضری رپورٹ' : 'Attendance Report'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_exams')): ?>
                        <a href="exams.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='exams.php'?'active-link':''; ?>"><i class="fas fa-clipboard-list me-2"></i> <?php echo ($lang=='ur') ? 'امتحانات' : 'Exams'; ?></a>
                        <?php endif; ?>
                    </div>

                    <!-- وظائف عملہ (مسجد) -->
                    <?php if(has_perm('manage_masjid_stipends') || has_perm('manage_stipends')): ?>
                    <a href="acc_payroll.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_payroll.php'?'active-link':''; ?>"><i class="fas fa-money-check-alt me-2 text-success"></i> <?php echo ($lang=='ur') ? 'وظائف عملہ' : 'Staff Stipends'; ?></a>
                    <?php endif; ?>

                    <!-- ایڈوانس و قرضہ جات -->
                    <?php if(has_perm('manage_masjid_loans') || has_perm('manage_loans')): ?>
                    <a href="loans_management.php" class="list-group-item <?php echo (basename($_SERVER['PHP_SELF'])=='loans_management.php' || basename($_SERVER['PHP_SELF'])=='staff_loans.php')?'active-link':''; ?>"><i class="fas fa-hand-holding-usd me-2 text-info"></i> <?php echo ($lang=='ur') ? 'ایڈوانس و قرض مینیجمنٹ' : 'Loans & Advance'; ?></a>
                    <?php endif; ?>

                    <!-- فنانس سیکشن -->
                    <?php if(has_perm('manage_masjid_finance') || has_perm('manage_masjid_finance_entry') || has_perm('manage_masjid_finance_report') || has_perm('manage_masjid_financial_reports') || has_perm('manage_masjid_assets') || has_perm('manage_masjid_in_kind_donations') || has_perm('manage_finance')): ?>
                    <a href="#masjidFinanceSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center <?php echo $masjid_finance_open ? '' : 'collapsed'; ?>" aria-expanded="<?php echo $masjid_finance_open ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-coins me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'فنانس سیکشن (مسجد)' : 'Masjid Finance'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse sidebar-submenu <?php echo $masjid_finance_open ? 'show' : ''; ?>" id="masjidFinanceSubmenu">
                        <?php if(has_perm('manage_masjid_finance') || has_perm('manage_masjid_finance_entry') || has_perm('manage_finance')): ?>
                        <a href="simple_finance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='simple_finance.php'?'active-link':''; ?>"><i class="fas fa-cash-register me-2"></i> <?php echo ($lang=='ur') ? 'آمدن و خرچ' : 'Income & Expense'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_masjid_in_kind_donations') || has_perm('manage_in_kind_donations')): ?>
                        <a href="in_kind_donations.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='in_kind_donations.php'?'active-link':''; ?>"><i class="fas fa-gift me-2"></i> <?php echo ($lang=='ur') ? 'عطیات / اشیاء' : 'In-Kind Donations'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_masjid_assets') || has_perm('manage_assets')): ?>
                        <a href="assets.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='assets.php'?'active-link':''; ?>"><i class="fas fa-boxes me-2"></i> <?php echo ($lang=='ur') ? 'اثاثہ جات' : 'Assets'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_masjid_financial_reports') || has_perm('manage_masjid_finance_report') || has_perm('manage_financial_reports') || has_perm('manage_finance_report') || has_perm('manage_finance')): ?>
                        <a href="finance_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='finance_report.php'?'active-link':''; ?>"><i class="fas fa-chart-bar me-2"></i> <?php echo ($lang=='ur') ? 'فنانس رپورٹ' : 'Finance Report'; ?></a>
                        <?php endif; ?>
                        <?php if($role === 'admin' || in_array($role, ['admin', 'superadmin', 'administrator'])): ?>
                        <a href="recycle_bin.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='recycle_bin.php'?'active-link':''; ?>"><i class="fas fa-trash-alt me-2 text-danger"></i> <?php echo ($lang=='ur') ? 'ری سائیکل بن (ٹریش)' : 'Recycle Bin'; ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

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
                    <a href="smart_notifications.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='smart_notifications.php'?'active-link':''; ?>">
                        <span><i class="fas fa-bell text-warning me-2"></i> <?php echo ($lang=='ur') ? 'اسمارٹ الرٹس و نوٹیفیکیشن' : 'Smart Alerts'; ?></span>
                        <?php if(isset($smart_alerts_count) && $smart_alerts_count > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?php echo $smart_alerts_count; ?></span>
                        <?php endif; ?>
                    </a>
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
                    <a href="admissions_pipeline.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='admissions_pipeline.php'?'active-link':''; ?>"><i class="fas fa-stream me-2 text-info"></i> <?php echo ($lang=='ur') ? 'مراحلِ داخلہ' : 'Admissions Stages'; ?></a>
                    <?php endif; ?>
                    
                    <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
                    <a href="academic_entry_dashboard.php" class="list-group-item"><i class="fas fa-book-open me-2"></i> <?php echo t('manage_academic_dashboard'); ?></a>
                    <a href="academic_view_dashboard.php" class="list-group-item"><i class="fas fa-chart-line me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی کارکردگی' : 'Academic Performance'; ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_students')): ?>
                    <a href="students.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='students.php'?'active-link':''; ?>"><i class="fas fa-user-graduate me-2"></i> <?php echo t('students'); ?></a>
                    <a href="alumni.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='alumni.php'?'active-link':''; ?>"><i class="fas fa-award me-2"></i> <?php echo t('alumni'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_teachers')): ?>
                    <a href="teachers.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teachers.php'?'active-link':''; ?>"><i class="fas fa-chalkboard-teacher me-2"></i> <?php echo t('teachers'); ?></a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_teacher_attendance') || has_perm('manage_attendance')): ?>
                    <a href="teachers_attendance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teachers_attendance.php'?'active-link':''; ?>"><i class="fas fa-user-clock me-2"></i> <?php echo ($lang=='ur') ? 'حاضری اساتذہ' : 'Teacher Attendance'; ?></a>
                    <a href="teacher_attendance_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='teacher_attendance_report.php'?'active-link':''; ?>"><i class="fas fa-file-signature me-2"></i> <?php echo ($lang=='ur') ? 'اساتذہ حاضری رپورٹ' : 'Teacher Attendance Report'; ?></a>
                    <a href="teacher_leaves.php" class="list-group-item d-flex justify-content-between align-items-center <?php echo basename($_SERVER['PHP_SELF'])=='teacher_leaves.php'?'active-link':''; ?>">
                        <span><i class="fas fa-calendar-minus me-2"></i> <?php echo ($lang=='ur') ? 'اساتذہ رخصت' : 'Teacher Leaves'; ?></span>
                        <?php if(isset($p_teacher_leaves) && $p_teacher_leaves > 0): ?>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $p_teacher_leaves; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if(has_perm('manage_attendance')): ?>
                    <a href="attendance.php" class="list-group-item"><i class="fas fa-calendar-check me-2"></i> <?php echo t('attendance_register'); ?></a>
                    <a href="qr_attendance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='qr_attendance.php'?'active-link':''; ?>"><i class="fas fa-qrcode me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'کیو آر / بارکوڈ حاضری' : 'QR Attendance'; ?></a>
                    <?php if(has_perm('manage_attendance_report') || has_perm('manage_attendance')): ?>
                    <a href="attendance_report.php" class="list-group-item"><i class="fas fa-file-signature me-2"></i> <?php echo t('attendance_report'); ?></a>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if(has_perm('manage_exams')): ?>
                    <a href="exams.php" class="list-group-item"><i class="fas fa-clipboard-list me-2"></i> <?php echo t('exams'); ?></a>
                    <?php endif; ?>

                    <!-- درس نظامی و عصری علوم (جامع تعلیمی نظام) -->
                    <?php 
                    $academic_pages = ['course_manager.php', 'student_course_enrollment.php', 'academic_results_entry.php', 'student_promotion.php', 'academic_reports.php'];
                    $academic_open = in_array(basename($_SERVER['PHP_SELF']), $academic_pages);
                    ?>
                    <?php if(has_perm('manage_academics') || has_perm('manage_academic_entry') || has_perm('manage_exams') || in_array($role, ['admin', 'superadmin', 'nazim'])): ?>
                    <a href="#darseNizamiSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center <?php echo $academic_open ? '' : 'collapsed'; ?>" aria-expanded="<?php echo $academic_open ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-graduation-cap me-2 text-success"></i> <?php echo ($lang=='ur') ? 'درس نظامی و عصری علوم' : 'Darse Nizami & Academics'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse sidebar-submenu <?php echo $academic_open ? 'show' : ''; ?>" id="darseNizamiSubmenu">
                        <a href="course_manager.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='course_manager.php'?'active-link':''; ?>"><i class="fas fa-book-reader me-2"></i> <?php echo ($lang=='ur') ? 'کورس و مضامین سیٹ اپ' : 'Course & Subjects Setup'; ?></a>
                        <a href="student_course_enrollment.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='student_course_enrollment.php'?'active-link':''; ?>"><i class="fas fa-user-check me-2"></i> <?php echo ($lang=='ur') ? 'طلبہ کورس داخلہ' : 'Course Enrollment'; ?></a>
                        <a href="academic_results_entry.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='academic_results_entry.php'?'active-link':''; ?>"><i class="fas fa-edit me-2"></i> <?php echo ($lang=='ur') ? 'مضامین وار رزلٹ انٹری' : 'Subject Marks Entry'; ?></a>
                        <a href="student_promotion.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='student_promotion.php'?'active-link':''; ?>"><i class="fas fa-level-up-alt me-2"></i> <?php echo ($lang=='ur') ? 'سالانہ ترقی (پروموشن)' : 'Annual Promotion'; ?></a>
                        <a href="academic_reports.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='academic_reports.php'?'active-link':''; ?>"><i class="fas fa-chart-pie me-2"></i> <?php echo ($lang=='ur') ? 'تعلیمی رپورٹس و رینکنگ' : 'Academic Reports & Rankings'; ?></a>
                    </div>
                    <?php endif; ?>

                    <!-- انتظامات (سب مینیو: کتب خانہ، شاپ و انوینٹری، دارالاقامہ، بھرتی و تقرری) -->
                    <?php 
                    $management_pages = ['library.php', 'inventory.php', 'hostel.php', 'recruitment.php'];
                    $management_open = in_array(basename($_SERVER['PHP_SELF']), $management_pages);
                    $can_manage_any = (is_mod_allowed('library') && has_perm('manage_library')) ||
                                      (is_mod_allowed('inventory') && has_perm('manage_inventory')) ||
                                      (is_mod_allowed('hostel') && has_perm('manage_hostel')) ||
                                      (is_mod_allowed('recruitment') && has_perm('manage_recruitment'));
                    ?>
                    <?php if($can_manage_any): ?>
                    <a href="#managementSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center <?php echo $management_open ? '' : 'collapsed'; ?>" aria-expanded="<?php echo $management_open ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-briefcase me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'انتظامات' : 'Management'; ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse sidebar-submenu <?php echo $management_open ? 'show' : ''; ?>" id="managementSubmenu">
                        <?php if(is_mod_allowed('library') && has_perm('manage_library')): ?>
                        <a href="library.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='library.php'?'active-link':''; ?>"><i class="fas fa-book-reader me-2"></i> <?php echo ($lang=='ur') ? 'کتب خانہ' : 'Library Management'; ?></a>
                        <?php endif; ?>
                        <?php if(is_mod_allowed('inventory') && has_perm('manage_inventory')): ?>
                        <a href="inventory.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='inventory.php'?'active-link':''; ?>"><i class="fas fa-store me-2"></i> <?php echo ($lang=='ur') ? 'شاپ و انوینٹری' : 'School Shop & Inventory'; ?></a>
                        <?php endif; ?>
                        <?php if(is_mod_allowed('hostel') && has_perm('manage_hostel')): ?>
                        <a href="hostel.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='hostel.php'?'active-link':''; ?>"><i class="fas fa-hotel me-2"></i> <?php echo ($lang=='ur') ? 'دارالاقامہ' : 'Hostel Management'; ?></a>
                        <?php endif; ?>
                        <?php if(is_mod_allowed('recruitment') && has_perm('manage_recruitment')): ?>
                        <a href="recruitment.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='recruitment.php'?'active-link':''; ?>"><i class="fas fa-user-tie me-2"></i> <?php echo ($lang=='ur') ? 'بھرتی و تقرری' : 'Jobs & Recruitment'; ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if(is_mod_allowed('cms') && (has_perm('manage_website') || has_perm('manage_notices'))): ?>
                    <a href="website_cms.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='website_cms.php'?'active-link':''; ?>"><i class="fas fa-globe me-2 text-success"></i> <?php echo ($lang=='ur') ? 'ویب سائٹ و سوشل CMS' : 'Website CMS'; ?></a>
                    <?php endif; ?>

                    <?php 
                    $erp_pages = [
                        'simple_finance.php', 'in_kind_donations.php', 'assets.php', 'finance_report.php',
                        'annual_report.php', 'fee_defaulters.php', 'acc_payroll.php', 'loans_management.php',
                        'staff_loans.php', 'finance.php', 'manage_departments.php', 'acc_chart.php',
                        'acc_vouchers.php', 'acc_ledger.php', 'acc_trial_balance.php', 'acc_income_statement.php',
                        'acc_balance_sheet.php', 'acc_invoices.php', 'recycle_bin.php'
                    ];
                    $erp_open = in_array(basename($_SERVER['PHP_SELF']), $erp_pages);
                    ?>
                    <?php if(has_perm('manage_finance') || has_perm('manage_finance_entry') || has_perm('manage_finance_report') || has_perm('manage_financial_reports') || has_perm('manage_fee_defaulters') || has_perm('manage_loans') || has_perm('manage_stipends') || has_perm('manage_assets') || has_perm('manage_in_kind_donations') || has_perm('manage_erp')): ?>
                    <a href="#erpSubmenu" data-bs-toggle="collapse" class="list-group-item d-flex justify-content-between align-items-center <?php echo $erp_open ? '' : 'collapsed'; ?>" aria-expanded="<?php echo $erp_open ? 'true' : 'false'; ?>">
                        <span><i class="fas fa-file-invoice-dollar me-2 text-warning"></i> <?php echo t('erp_accounts'); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </a>
                    <div class="collapse sidebar-submenu <?php echo $erp_open ? 'show' : ''; ?>" id="erpSubmenu">
                        <?php if(has_perm('manage_finance') || has_perm('manage_finance_entry')): ?>
                        <a href="simple_finance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='simple_finance.php'?'active-link':''; ?>"><i class="fas fa-coins me-2"></i> <?php echo ($lang=='ur') ? 'آمدن و خرچ' : 'Income & Expense'; ?></a>
                        <?php endif; ?>
                        <?php if($role === 'admin' || in_array($role, ['admin', 'superadmin', 'administrator'])): ?>
                        <a href="recycle_bin.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='recycle_bin.php'?'active-link':''; ?>"><i class="fas fa-trash-alt me-2 text-danger"></i> <?php echo ($lang=='ur') ? 'ری سائیکل بن (ٹریش)' : 'Recycle Bin'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_in_kind_donations') || has_perm('manage_masjid_in_kind_donations')): ?>
                        <a href="in_kind_donations.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='in_kind_donations.php'?'active-link':''; ?>"><i class="fas fa-gift me-2"></i> <?php echo ($lang=='ur') ? 'عطیات / اشیاء' : 'In-Kind Donations'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_assets') || has_perm('manage_masjid_assets')): ?>
                        <a href="assets.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='assets.php'?'active-link':''; ?>"><i class="fas fa-boxes me-2"></i> <?php echo ($lang=='ur') ? 'اثاثہ جات' : 'Assets'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_financial_reports') || has_perm('manage_finance_report') || has_perm('manage_finance')): ?>
                        <a href="finance_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='finance_report.php'?'active-link':''; ?>"><i class="fas fa-chart-bar me-2"></i> <?php echo t('finance_report'); ?></a>
                        <a href="annual_report.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='annual_report.php'?'active-link':''; ?>"><i class="fas fa-file-invoice me-2 text-info"></i> <?php echo ($lang=='ur') ? 'سالانہ جامع رپورٹ' : 'Annual Report'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_fee_defaulters') || has_perm('manage_financial_reports') || has_perm('manage_finance_report') || has_perm('manage_finance')): ?>
                        <a href="fee_defaulters.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='fee_defaulters.php'?'active-link':''; ?>"><i class="fas fa-exclamation-triangle me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'فیس یاددہانی و بقایاجات' : 'Fee Reminders'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_stipends') || has_perm('manage_erp')): ?>
                        <a href="acc_payroll.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_payroll.php'?'active-link':''; ?>"><i class="fas fa-money-check-alt me-2 text-success"></i> <?php echo ($lang=='ur') ? 'وظائف عملہ' : 'Staff Stipends'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_loans') || has_perm('manage_erp')): ?>
                        <a href="loans_management.php" class="list-group-item <?php echo (basename($_SERVER['PHP_SELF'])=='loans_management.php' || basename($_SERVER['PHP_SELF'])=='staff_loans.php')?'active-link':''; ?>"><i class="fas fa-hand-holding-usd me-2 text-info"></i> <?php echo ($lang=='ur') ? 'ایڈوانس و قرض مینیجمنٹ' : 'Loans & Advances'; ?></a>
                        <?php endif; ?>
                        <?php if(has_perm('manage_erp')): ?>
                        <a href="finance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='finance.php'?'active-link':''; ?>"><i class="fas fa-wallet me-2"></i> <?php echo t('finance_dashboard_menu'); ?></a>
                        <a href="manage_departments.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='manage_departments.php'?'active-link':''; ?>"><i class="fas fa-building me-2"></i> <?php echo ($lang=='ur') ? 'شعبہ جات / برانچز' : 'Departments / Branches'; ?></a>
                        <a href="acc_chart.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_chart.php'?'active-link':''; ?>"><i class="fas fa-sitemap me-2"></i> <?php echo t('chart_of_accounts_menu'); ?></a>
                        <a href="acc_vouchers.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_vouchers.php'?'active-link':''; ?>"><i class="fas fa-receipt me-2"></i> <?php echo t('vouchers_menu'); ?></a>
                        <a href="acc_ledger.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_ledger.php'?'active-link':''; ?>"><i class="fas fa-book me-2"></i> <?php echo t('general_ledger_menu'); ?></a>
                        <a href="acc_trial_balance.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_trial_balance.php'?'active-link':''; ?>"><i class="fas fa-balance-scale me-2"></i> <?php echo ($lang=='ur') ? 'میزان پڑتال' : 'Trial Balance'; ?></a>
                        <a href="acc_income_statement.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_income_statement.php'?'active-link':''; ?>"><i class="fas fa-chart-pie me-2"></i> <?php echo ($lang=='ur') ? 'آمدن و اخراجات' : 'Income Statement'; ?></a>
                        <a href="acc_balance_sheet.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_balance_sheet.php'?'active-link':''; ?>"><i class="fas fa-balance-scale-left me-2"></i> <?php echo ($lang=='ur') ? 'بیلنس شیٹ' : 'Balance Sheet'; ?></a>
                        <a href="acc_invoices.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='acc_invoices.php'?'active-link':''; ?>"><i class="fas fa-file-invoice me-2"></i> <?php echo t('fee_invoices_menu'); ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if(has_perm('add_complaint') || in_array($role, ['admin', 'nazim'])): ?>
                    <a href="add_complaint.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='add_complaint.php'?'active-link':''; ?>"><i class="fas fa-plus-circle me-2 text-danger"></i> <?php echo ($lang=='ur') ? 'شکایت کا اندراج' : 'Add Complaint'; ?></a>
                    <?php endif; ?>
                    <?php if(has_perm('manage_complaints') || in_array($role, ['admin', 'nazim'])): ?>
                    <a href="manage_complaints.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='manage_complaints.php'?'active-link':''; ?>"><i class="fas fa-exclamation-triangle me-2 text-danger"></i> <?php echo ($lang=='ur') ? 'شکایات کا ریکارڈ' : 'Complaints Record'; ?></a>
                    <?php endif; ?>
                    <?php if($role == 'admin'): ?>
                    <a href="manage_users.php" class="list-group-item"><i class="fas fa-users-cog me-2"></i> <?php echo t('manage_users'); ?></a>
                    <a href="settings.php" class="list-group-item"><i class="fas fa-cog me-2"></i> <?php echo t('settings'); ?></a>
                    <a href="subscription_status.php" class="list-group-item <?php echo basename($_SERVER['PHP_SELF'])=='subscription_status.php'?'active-link':''; ?>"><i class="fas fa-gem me-2 text-warning"></i> <?php echo ($lang=='ur') ? 'سبسکرپشن و فیس کیفیت' : 'Subscription & Status'; ?></a>
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
        <?php if (!empty($_SESSION['impersonated_by_super_admin'])): ?>
            <div class="alert alert-warning border-0 rounded-0 py-2 px-3 m-0 d-flex justify-content-between align-items-center shadow-sm" style="background: #f59e0b; color: #78350f; font-weight: 600; z-index: 9999;">
                <div>
                    <i class="fas fa-user-secret me-2 fs-5"></i> آپ اس وقت بطور سوپر ایڈمن اس ادارے (<strong><?php echo htmlspecialchars($_SESSION['tenant_name'] ?? ''); ?> [<?php echo htmlspecialchars($_SESSION['tenant_code'] ?? ''); ?>]</strong>) میں داخل ہیں۔
                </div>
                <a href="super_admin/impersonate.php?action=stop" class="btn btn-sm btn-dark rounded-pill px-3 fw-bold">
                    <i class="fas fa-sign-out-alt me-1"></i> سوپر ایڈمن پینل پر واپس جائیں
                </a>
            </div>
        <?php endif; ?>
        <nav class="navbar navbar-light bg-white shadow-sm px-3 mb-4 sticky-top">
            <button class="btn text-primary border-0 fs-3" id="menu-toggle"><i class="fas fa-bars"></i></button>
            <div class="ms-3 d-flex align-items-center">
                <span class="text-primary fw-bold d-none d-md-block"><img src="<?php echo htmlspecialchars($header_logo_file); ?>" alt="Logo" style="width: 30px; height: 30px; border-radius: 50%; margin-left: 5px; object-fit: contain; vertical-align: middle;"><?php echo (isset($_SESSION['user_id'])) ? htmlspecialchars($header_current_name)." (".e($user_name).")" : htmlspecialchars($header_current_name); ?></span>
                <span class="text-primary fw-bold d-md-none small text-truncate" style="max-width: 140px; display: inline-block; vertical-align: middle;"><img src="<?php echo htmlspecialchars($header_logo_file); ?>" alt="Logo" style="width: 20px; height: 20px; border-radius: 50%; margin-left: 5px; object-fit: contain; vertical-align: middle;"><?php echo htmlspecialchars($header_current_name); ?></span>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small ms-2 d-none d-lg-inline-block"><i class="fas fa-moon me-1"></i> <?php echo get_today_hijri_date(); ?></span>
                <?php if (isset($_SESSION['tenant_code'])): ?>
                    <span class="badge px-2 py-1 small ms-2 font-monospace shadow-sm" style="background-color: var(--btn-primary-bg) !important; color: #ffffff !important; border: 1px solid rgba(255,255,255,0.2) !important; font-weight: 700; font-size: 0.82rem;" title="ادارہ کوڈ">
                        <i class="fas fa-id-badge me-1"></i><?php echo htmlspecialchars($_SESSION['tenant_code']); ?>
                    </span>
                    <?php if (!empty($header_current_name) || !empty($_SESSION['tenant_name'])): ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1 small ms-1 d-none d-xl-inline-block">
                            <i class="fas fa-building me-1"></i><?php echo htmlspecialchars(!empty($header_current_name) ? $header_current_name : $_SESSION['tenant_name']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($is_trial): ?>
                        <button type="button" class="btn btn-sm rounded-pill pulsing-beacon-btn d-flex align-items-center gap-2 px-3 py-1 ms-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#trialWarningModal" title="آزمائشی مدت برائے <?php echo htmlspecialchars($current_plan_name); ?>">
                            <span class="blinking-dot"></span>
                            <span class="small fw-bold">ٹرائل: <?php echo $days_left; ?> دن باقی</span>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Smart Notifications Hub Icon -->
                <a href="smart_notifications.php" class="btn btn-outline-primary btn-sm rounded-circle p-2 d-flex align-items-center justify-content-center position-relative" style="width: 36px; height: 36px;" title="<?php echo ($lang=='ur') ? 'اسمارٹ الرٹس و نوٹیفیکیشن' : 'Smart Alerts'; ?>">
                    <i class="fas fa-bell"></i>
                    <?php if(isset($smart_alerts_count) && $smart_alerts_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                            <?php echo $smart_alerts_count; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <!-- Theme Switcher Dropdown (Right-to-Left Bar & Active Theme Mention) -->
                <div class="dropdown">
                    <button class="btn btn-outline-primary btn-sm rounded-pill px-3 d-flex align-items-center gap-2 shadow-sm" type="button" id="themeDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="height: 36px;" title="<?php echo ($lang=='ur') ? 'تھیم تبدیل کریں' : 'Change Theme'; ?>">
                        <i class="fas fa-palette"></i>
                        <span class="current-theme-label fw-bold small d-none d-sm-inline" id="headerThemeBadge">عاجی زمرد</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end theme-dropdown-menu shadow-lg border-0 p-3" aria-labelledby="themeDropdown" style="border-radius: 20px; min-width: 320px; z-index: 1070;">
                        <!-- Header with selected theme mentioned -->
                        <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom" dir="rtl">
                            <span class="small fw-bold text-muted"><i class="fas fa-palette me-1 text-primary"></i> <?php echo ($lang=='ur') ? 'منتخب تھیم:' : 'Active Theme:'; ?></span>
                            <span class="badge rounded-pill px-3 py-1 fw-bold current-theme-name" id="dropdownThemeTitle" style="background: var(--primary, #1B4332); color: #fff; font-size: 0.8rem;">عاجی زمرد و شاہی وقار (ڈیفالٹ)</span>
                        </div>
                        <div class="small text-muted text-end mb-2" dir="rtl" style="font-size: 0.75rem;">
                            <?php echo ($lang=='ur') ? 'دائیں سے بائیں کسی بھی تھیم کا انتخاب کریں:' : 'Select theme (Right to Left):'; ?>
                        </div>
                        <!-- RTL Horizontal Selection Bar (Right-to-Left) -->
                        <div class="d-flex align-items-center justify-content-between gap-1 gap-sm-2 py-1 theme-selection-bar" dir="rtl">
                            <!-- 1. Default: Parchment Sage -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="default" style="width: 32px; height: 32px; background: #F7F2EA; border-color: #1B4332 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('default')" title="<?php echo ($lang=='ur') ? 'عاجی زمرد و شاہی وقار (ڈیفالٹ)' : 'Ivory Parchment & Sage (Default)'; ?>">
                                <span class="theme-dot" style="background:#1B4332; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-white" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 2. Pastel Sky -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="pastel-sky" style="width: 32px; height: 32px; background: #EDF4F9; border-color: #0284C7 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('pastel-sky')" title="<?php echo ($lang=='ur') ? 'نرم نیلم و فیروزی لطافت' : 'Pastel Sky Breeze'; ?>">
                                <span class="theme-dot" style="background:#0284C7; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-white" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 3. Espresso Cream -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="espresso-cream" style="width: 32px; height: 32px; background: #181613; border-color: #C89D5C !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('espresso-cream')" title="<?php echo ($lang=='ur') ? 'شاہی قہوہ و صندل' : 'Dark Espresso & Warm Cream'; ?>">
                                <span class="theme-dot" style="background:#EFE9DF; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-dark" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 4. Classic Emerald -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="classic-emerald" style="width: 32px; height: 32px; background: #133E2F; border-color: #B89230 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('classic-emerald')" title="<?php echo ($lang=='ur') ? 'کلاسک ادارہ جاتی زمرد' : 'Classic Royal Emerald'; ?>">
                                <span class="theme-dot" style="background:#B89230; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-white" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 5. Aurora Glass -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="aurora-glass" style="width: 32px; height: 32px; background: linear-gradient(135deg, #10B981, #06B6D4); border-color: #10B981 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('aurora-glass')" title="<?php echo ($lang=='ur') ? 'اورورا فراسٹیڈ گلاس' : 'Aurora Frosted Glass'; ?>">
                                <span class="theme-dot" style="background:#ffffff; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-dark" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 6. Soft Neumorphic -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="soft-neumorphic" style="width: 32px; height: 32px; background: #E9EEF4; border-color: #CBD5E1 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('soft-neumorphic')" title="<?php echo ($lang=='ur') ? 'سافٹ اسلیٹ نیومورفزم' : 'Soft Slate Neumorphic'; ?>">
                                <span class="theme-dot" style="background:#10B981; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-white" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 7. Obsidian Glow -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="obsidian-glow" style="width: 32px; height: 32px; background: #0D1117; border-color: #00F2FE !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('obsidian-glow')" title="<?php echo ($lang=='ur') ? 'لگژری اوبسیڈین سائبر' : 'Luxury Obsidian Cyber Glow'; ?>">
                                <span class="theme-dot" style="background:#00F2FE; width: 13px; height: 13px; border-radius: 50%; box-shadow: 0 0 6px #00F2FE;"></span>
                                <i class="fas fa-check theme-check position-absolute text-dark" style="font-size: 9px; display: none;"></i>
                            </a>
                            <!-- 8. Warm Sand -->
                            <a class="theme-select-btn p-1 d-flex align-items-center justify-content-center rounded-circle border shadow-sm position-relative" data-theme="warm-sand" style="width: 32px; height: 32px; background: #F5F0E6; border-color: #D2C6B4 !important; text-decoration: none;" href="javascript:void(0)" onclick="setTheme('warm-sand')" title="<?php echo ($lang=='ur') ? 'شاہی صندل نیومورفزم' : 'Warm Sand Neumorphic'; ?>">
                                <span class="theme-dot" style="background:#0284C7; width: 13px; height: 13px; border-radius: 50%;"></span>
                                <i class="fas fa-check theme-check position-absolute text-white" style="font-size: 9px; display: none;"></i>
                            </a>
                        </div>
                    </div>
                </div>
                
                <a href="?lang=<?php echo ($lang=='ur')?'en':'ur'; ?>" class="lang-btn btn btn-outline-success btn-sm rounded-pill px-3 fw-bold">
                    <?php echo ($lang=='ur') ? 'English' : '&#1575;&#1585;&#1583;&#1608;'; ?>
                </a>
            </div>
        </nav>
        <div class="container-fluid px-2 px-md-4">
            <?php if (isset($tenant_sub_status) && !$tenant_sub_status['ok']): ?>
                <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle fs-3 me-3 text-danger"></i>
                    <div>
                        <h6 class="fw-bold mb-1">سبسکرپشن میعاد ختم / معطل!</h6>
                        <small><?php echo htmlspecialchars($tenant_sub_status['message']); ?> — برائے مہربانی ایڈمنسٹریٹر سے رابطہ کریں۔</small>
                    </div>
                </div>
            <?php elseif (isset($is_trial) && $is_trial && $days_left <= 3): ?>
                <div class="alert alert-warning shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-hourglass-half fs-3 me-3 text-warning"></i>
                    <div>
                        <h6 class="fw-bold mb-1">مفت آزمائش (Trial) ختم ہونے کے قریب ہے!</h6>
                        <small>آپ کے ادارے کا ٹرائل پیریڈ ختم ہونے میں صرف <strong><?php echo $days_left; ?> دن</strong> باقی ہیں۔ خدمات بلاتعطل جاری رکھنے کے لیے سبسکرپشن ایکٹو کروائیں۔</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php 
            try {
                $bStmt = $pdo->query("SELECT * FROM saas_broadcasts WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) ORDER BY id DESC LIMIT 1");
                $broadcast = $bStmt->fetch(PDO::FETCH_ASSOC);
                if ($broadcast):
                    $bClass = ($broadcast['type'] === 'danger') ? 'alert-danger' : (($broadcast['type'] === 'warning') ? 'alert-warning' : (($broadcast['type'] === 'success') ? 'alert-success' : 'alert-info'));
            ?>
                <div class="alert <?php echo $bClass; ?> shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-bullhorn fs-3 me-3"></i>
                    <div>
                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($broadcast['title']); ?></h6>
                        <small><?php echo htmlspecialchars($broadcast['message']); ?></small>
                    </div>
                </div>
            <?php endif; } catch (Throwable $e) {} ?>

            <!-- 🌟 Trial Warning Modal -->
            <div class="modal fade" id="trialWarningModal" tabindex="-1" aria-labelledby="trialWarningModalLabel" aria-hidden="true" dir="rtl">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content rounded-4 border-0 shadow-lg">
                        <div class="modal-header border-0 pb-0 justify-content-between">
                            <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                                <span class="blinking-dot"></span> آزمائشی مدت کی یاد دہانی (Trial Notice)
                            </h5>
                            <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body py-3 text-center">
                            <div class="mb-3">
                                <div class="d-inline-flex p-3 rounded-circle bg-danger bg-opacity-10 text-danger fs-1">
                                    <i class="fas fa-hourglass-half fa-spin" style="--fa-animation-duration: 4s;"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold mb-2">آپ کے <span class="text-success"><?php echo htmlspecialchars($current_plan_name ?? 'پلان'); ?></span> کے صرف <span class="text-danger fs-3 fw-bold"><?php echo (int)($days_left ?? 0); ?></span> دن کا ٹرائل باقی ہے۔</h5>
                            <p class="text-muted small px-3 mb-3">
                                سبسکرپشن چارجز ادا کر کے اپنا پلان ایکٹیویٹ / ری نیو کروائیں تاکہ تمام فیچرز، موبائل ایپ رسائی اور کلاؤڈ بیک اپ کی بلا تعطل فراہمی جاری رہے۔
                            </p>
                            <div class="p-3 bg-light rounded-3 text-start mb-3 border">
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">منتخب شدہ پلان:</span>
                                    <strong class="text-dark"><?php echo htmlspecialchars($current_plan_name ?? 'بنیادی پیکج'); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">ٹرائل کی اختتامی تاریخ:</span>
                                    <strong class="text-danger font-monospace"><?php echo htmlspecialchars($sub_ends_at ?: date('Y-m-d', strtotime('+14 days'))); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between small">
                                    <span class="text-muted">حیثیت:</span>
                                    <span class="badge bg-warning text-dark px-2 py-1">آزمائشی ٹرائل فعال ہے</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0 d-flex gap-2">
                            <a href="subscription_status.php" class="btn btn-success flex-grow-1 rounded-pill fw-bold py-2">
                                <i class="fas fa-gem me-1"></i> سبسکرپشن کیفیت و فیس تفصیلات دیکھیں
                            </a>
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">سمجھ گیا</button>
                        </div>
                    </div>
                </div>
            </div>



