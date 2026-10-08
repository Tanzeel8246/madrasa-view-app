<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن مشترکہ ہیڈر و نیویگیشن (Super Admin Shared Header & Nav)
 * ════════════════════════════════════════════════════════════════════
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

if (empty($_SESSION['is_super_admin']) && function_exists('madrasa_restore_session_from_cookie')) {
    madrasa_restore_session_from_cookie($pdo);
}

if (!isset($_SESSION['is_super_admin'])) {
    header("Location: login.php");
    exit;
}

// Auto-heal super admin & SaaS tables if not created yet
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `super_admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `email` VARCHAR(150) DEFAULT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT NOT NULL,
            `subscription_id` INT DEFAULT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) DEFAULT 'PKR',
            `method` VARCHAR(50) DEFAULT 'manual',
            `reference_no` VARCHAR(100) DEFAULT NULL,
            `gateway_response` TEXT DEFAULT NULL,
            `status` ENUM('pending','completed','failed','refunded') DEFAULT 'completed',
            `paid_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (`tenant_id`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tenant_activity_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT NOT NULL,
            `user_id` INT DEFAULT NULL,
            `action` VARCHAR(100) NOT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (`tenant_id`),
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `saas_broadcasts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `type` ENUM('info','warning','danger','success') DEFAULT 'info',
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATE DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {}

$cur_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'سوپر ایڈمن ڈیش بورڈ';
$sa_name = $_SESSION['super_admin_name'] ?? 'Super Admin';
$sa_user = $_SESSION['super_admin_user'] ?? 'superadmin';
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - SaaS سوپر ایڈمن</title>
    
    <!-- Theme Flash Prevention -->
    <script>
        (function() {
            const t = localStorage.getItem('sa_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">

    <style>
        :root, [data-theme="dark"] {
            --sa-bg: #090e1a;
            --sa-card-bg: #111a2e;
            --sa-card-border: #1f2d4a;
            --sa-sidebar-bg: #0d1527;
            --sa-sidebar-border: #1b2640;
            --sa-text-main: #f8fafc;
            --sa-text-sub: #cbd5e1;
            --sa-text-muted: #94a3b8;
            --sa-primary: #3b82f6;
            --sa-primary-hover: #2563eb;
            --sa-table-bg: #111a2e;
            --sa-table-header-bg: #0d1527;
            --sa-table-row-hover: #16223b;
            --sa-border: #1f2d4a;
            --sa-input-bg: #090e1a;
            --sa-input-border: #243557;
            --sa-dropdown-bg: #111a2e;
            --sa-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
        }

        [data-theme="light"] {
            --sa-bg: #f4f6fb;
            --sa-card-bg: #ffffff;
            --sa-card-border: #e2e8f0;
            --sa-sidebar-bg: #ffffff;
            --sa-sidebar-border: #e2e8f0;
            --sa-text-main: #0f172a;
            --sa-text-sub: #334155;
            --sa-text-muted: #64748b;
            --sa-primary: #2563eb;
            --sa-primary-hover: #1d4ed8;
            --sa-table-bg: #ffffff;
            --sa-table-header-bg: #f8fafc;
            --sa-table-row-hover: #f1f5f9;
            --sa-border: #e2e8f0;
            --sa-input-bg: #f8fafc;
            --sa-input-border: #cbd5e1;
            --sa-dropdown-bg: #ffffff;
            --sa-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
        }

        body {
            font-family: 'Noto Nastaliq Urdu', Tahoma, Arial, sans-serif;
            background: var(--sa-bg);
            color: var(--sa-text-main);
            min-height: 100vh;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .sidebar {
            background: var(--sa-sidebar-bg);
            border-left: 1px solid var(--sa-sidebar-border);
            min-height: 100vh;
            padding: 20px 12px;
            box-shadow: var(--sa-shadow);
            transition: all 0.25s ease;
        }

        .sidebar-brand {
            padding: 10px;
            border-bottom: 1px solid var(--sa-border);
            margin-bottom: 20px;
            text-align: center;
        }

        .sidebar-link {
            color: var(--sa-text-sub);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 6px;
            transition: all 0.2s ease;
        }

        .sidebar-link:hover {
            background: rgba(59, 130, 246, 0.12);
            color: var(--sa-primary);
        }

        .sidebar-link.active {
            background: var(--sa-primary);
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
        }

        .card-custom, .card-stat {
            background: var(--sa-card-bg);
            border: 1px solid var(--sa-card-border);
            border-radius: 18px;
            padding: 24px;
            box-shadow: var(--sa-shadow);
            transition: all 0.25s ease;
        }

        .card-stat:hover {
            transform: translateY(-3px);
            border-color: var(--sa-primary);
        }

        .stat-label {
            color: var(--sa-text-sub) !important;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .stat-value {
            color: var(--sa-text-main) !important;
            font-size: 2.2rem;
            font-weight: 800;
            margin-top: 4px;
        }

        /* Cohesive Table Styling */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--sa-border);
        }

        .table-custom th {
            background-color: var(--sa-table-header-bg) !important;
            color: var(--sa-text-sub) !important;
            font-weight: 700;
            border-bottom: 2px solid var(--sa-border) !important;
            padding: 14px 16px;
        }

        .table-custom td {
            background-color: var(--sa-table-bg) !important;
            color: var(--sa-text-main) !important;
            border-bottom: 1px solid var(--sa-border) !important;
            padding: 14px 16px;
            vertical-align: middle;
        }

        .table-custom tr:hover td {
            background-color: var(--sa-table-row-hover) !important;
        }

        .form-control, .form-select {
            background-color: var(--sa-input-bg) !important;
            border-color: var(--sa-input-border) !important;
            color: var(--sa-text-main) !important;
            border-radius: 10px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--sa-primary) !important;
            box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25);
        }

        .modal-content {
            background-color: var(--sa-card-bg);
            color: var(--sa-text-main);
            border: 1px solid var(--sa-card-border);
            border-radius: 18px;
            box-shadow: var(--sa-shadow);
        }

        .dropdown-menu {
            background-color: var(--sa-dropdown-bg);
            border: 1px solid var(--sa-border);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
            max-height: 280px;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1065 !important;
            border-radius: 12px;
            padding: 6px 0;
            min-width: 230px;
        }

        /* Custom Scrollbar for Dropdown Menu */
        .dropdown-menu::-webkit-scrollbar {
            width: 6px;
        }
        .dropdown-menu::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }
        .dropdown-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.25);
            border-radius: 4px;
        }
        .dropdown-menu::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        .dropdown-item {
            color: var(--sa-text-sub);
            padding: 9px 16px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            text-align: right;
        }

        .dropdown-item:hover {
            background-color: var(--sa-table-row-hover);
            color: var(--sa-primary);
        }

        /* Fix for Form Submit Buttons inside Dropdown */
        button.dropdown-item {
            background: transparent !important;
            border: none !important;
            width: 100%;
            text-align: right;
            display: flex;
            align-items: center;
            padding: 9px 16px;
            font-size: 0.9rem;
            color: inherit;
            cursor: pointer;
        }

        button.dropdown-item:hover,
        button.dropdown-item:focus {
            background-color: var(--sa-table-row-hover) !important;
        }

        button.dropdown-item.text-danger:hover {
            background-color: rgba(239, 68, 68, 0.15) !important;
            color: #ef4444 !important;
        }

        button.dropdown-item.text-success:hover {
            background-color: rgba(16, 185, 129, 0.15) !important;
            color: #10b981 !important;
        }

        .table-responsive {
            min-height: 280px;
        }

        .theme-toggle-btn {
            background: rgba(59, 130, 246, 0.1);
            color: var(--sa-primary);
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 50rem;
            padding: 6px 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .theme-toggle-btn:hover {
            background: var(--sa-primary);
            color: #fff;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-12 col-md-3 col-lg-2 sidebar">
            <div class="sidebar-brand">
                <i class="fas fa-shield-alt text-primary fs-2 mb-2"></i>
                <h5 class="fw-bold mb-0" style="color: var(--sa-text-main);">سوپر ایڈمن پینل</h5>
                <small style="color: var(--sa-text-muted);">SaaS کنٹرول سینٹر</small>
            </div>

            <nav>
                <a href="dashboard.php" class="sidebar-link <?php echo $cur_page==='dashboard.php'?'active':''; ?>">
                    <i class="fas fa-chart-line me-2"></i> اوور ویو و میٹرکس
                </a>
                <a href="tenants.php" class="sidebar-link <?php echo $cur_page==='tenants.php'?'active':''; ?>">
                    <i class="fas fa-building me-2"></i> رجسٹرڈ ادارے (Tenants)
                </a>
                <a href="plans.php" class="sidebar-link <?php echo $cur_page==='plans.php'?'active':''; ?>">
                    <i class="fas fa-tags me-2"></i> سبسکرپشن پلانز
                </a>
                <a href="payments.php" class="sidebar-link <?php echo $cur_page==='payments.php'?'active':''; ?>">
                    <i class="fas fa-receipt me-2"></i> وصولیاں و بلنگ
                </a>
                <a href="broadcasts.php" class="sidebar-link <?php echo $cur_page==='broadcasts.php'?'active':''; ?>">
                    <i class="fas fa-bullhorn me-2"></i> سسٹم اعلانات
                </a>
                <a href="activity_logs.php" class="sidebar-link <?php echo $cur_page==='activity_logs.php'?'active':''; ?>">
                    <i class="fas fa-history me-2"></i> سرگرمی لاگ (Audit)
                </a>
                <a href="profile.php" class="sidebar-link <?php echo $cur_page==='profile.php'?'active':''; ?>">
                    <i class="fas fa-user-shield me-2"></i> پروفائل و سیکیورٹی
                </a>
                <a href="../migrate_to_saas.php" class="sidebar-link">
                    <i class="fas fa-database me-2"></i> مائیگریشن اسٹیٹس
                </a>
                <a href="../login.php" class="sidebar-link">
                    <i class="fas fa-external-link-alt me-2"></i> عام پینل پر جائیں
                </a>
                <a href="logout.php" class="sidebar-link text-danger mt-4">
                    <i class="fas fa-sign-out-alt me-2"></i> لاگ آؤٹ
                </a>
            </nav>
        </div>

        <!-- Main Content Wrapper -->
        <div class="col-12 col-md-9 col-lg-10 p-3 p-md-4">
            <!-- Top Navbar Bar -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom" style="border-color: var(--sa-border) !important;">
                <div>
                    <h3 class="fw-bold mb-1" style="color: var(--sa-text-main);"><?php echo htmlspecialchars($page_title); ?></h3>
                    <p class="small mb-0" style="color: var(--sa-text-muted);">
                        پلیٹ فارم: <code>madrasa.mamfss.com</code> | خوش آمدید، <strong style="color: var(--sa-text-main);"><?php echo htmlspecialchars($sa_name); ?></strong>
                    </p>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
                    <!-- Dark/Light Theme Switcher Button -->
                    <button class="theme-toggle-btn" id="saThemeToggle" onclick="toggleSaTheme()" title="تھیم تبدیل کریں">
                        <i class="fas fa-sun" id="saThemeIcon"></i>
                        <span id="saThemeText">لائٹ موڈ</span>
                    </button>

                    <a href="profile.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
                        <i class="fas fa-user-circle me-1"></i> پروفائل
                    </a>
                </div>
            </div>
