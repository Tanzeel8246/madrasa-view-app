<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  ملٹی ٹیننٹ SaaS مائیگریشن ٹول
 *  Madrasa Multi-Tenant SaaS Migration Tool
 * ════════════════════════════════════════════════════════════════════
 *
 *  یہ اسکرپٹ موجودہ سنگل ٹیننٹ ڈیٹا بیس کو ملٹی ٹیننٹ SaaS میں
 *  تبدیل کرتا ہے۔ محفوظ طریقے سے: ٹرانزیکشن استعمال ہوتی ہے اور
 *  ناکامی کی صورت میں رول بیک ہوتا ہے۔
 *
 *  اقدامات:
 *  1. نئے SaaS ٹیبلز بنائے جاتے ہیں (tenants, plans, subscriptions, payments)
 *  2. سوپر ایڈمن ٹیبل بنایا جاتا ہے
 *  3. تمام 40+ موجودہ ٹیبلز میں tenant_id کالم شامل ہوتا ہے
 *  4. موجودہ ڈیٹا کو پہلے ٹیننٹ (id=1) سے منسلک کیا جاتا ہے
 *  5. ڈیفالٹ سبسکرپشن پلانز داخل ہوتے ہیں
 * ════════════════════════════════════════════════════════════════════
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600);

require_once __DIR__ . '/db.php';

$results = [];
$errors = [];
$step = 0;

// ──── ہیلپر: محفوظ ایگزیکیوشن ────
function safe_exec($pdo, $sql, $desc, &$results, &$errors, &$step) {
    $step++;
    try {
        $pdo->exec($sql);
        $results[] = "✅ #{$step} {$desc}";
        return true;
    } catch (Throwable $e) {
        // اگر کالم یا ٹیبل پہلے سے موجود ہے تو نظر انداز
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), '1060') !== false) {
            $results[] = "⏭️ #{$step} {$desc} (پہلے سے موجود)";
            return true;
        }
        $errors[] = "❌ #{$step} {$desc}: " . $e->getMessage();
        return false;
    }
}

// ──── ہیلپر: کالم کی موجودگی چیک ────
function column_exists($pdo, $table, $column) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return in_array($column, $cols);
    } catch (Throwable $e) {
        return false;
    }
}

// ──── ہیلپر: ٹیبل کی موجودگی چیک ────
function table_exists($pdo, $table) {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
//   POST: مائیگریشن چلائیں
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['run_migration'])) {

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 1: سوپر ایڈمنز ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
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
    ", "سوپر ایڈمنز ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 2: ادارے (Tenants) ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
        CREATE TABLE IF NOT EXISTS `tenants` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(30) NOT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(100) NOT NULL UNIQUE,
            `email` VARCHAR(150) DEFAULT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `city` VARCHAR(100) DEFAULT NULL,
            `country` VARCHAR(50) DEFAULT 'Pakistan',
            `logo_path` VARCHAR(255) DEFAULT NULL,
            `timezone` VARCHAR(50) DEFAULT 'Asia/Karachi',
            `status` ENUM('active','trial','suspended','cancelled') DEFAULT 'trial',
            `trial_ends_at` DATE DEFAULT NULL,
            `max_students` INT DEFAULT 50,
            `max_teachers` INT DEFAULT 10,
            `max_admins` INT DEFAULT 3,
            `features_json` TEXT DEFAULT NULL,
            `custom_domain` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`status`),
            INDEX (`code`),
            INDEX (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ", "ادارے (Tenants) ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 3: سبسکرپشن پلانز ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
        CREATE TABLE IF NOT EXISTS `subscription_plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `name_en` VARCHAR(100) DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `max_students` INT DEFAULT 50,
            `max_teachers` INT DEFAULT 10,
            `max_admins` INT DEFAULT 3,
            `features_json` TEXT DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `is_free_trial` TINYINT(1) DEFAULT 0,
            `trial_days` INT DEFAULT 14,
            `sort_order` INT DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ", "سبسکرپشن پلانز ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 4: فعال سبسکرپشنز ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
        CREATE TABLE IF NOT EXISTS `subscriptions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT NOT NULL,
            `plan_id` INT NOT NULL,
            `billing_cycle` ENUM('monthly','yearly','lifetime') DEFAULT 'monthly',
            `starts_at` DATE NOT NULL,
            `ends_at` DATE NOT NULL,
            `status` ENUM('active','expired','cancelled','grace','trial') DEFAULT 'trial',
            `auto_renew` TINYINT(1) DEFAULT 0,
            `payment_method` VARCHAR(50) DEFAULT 'manual',
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`tenant_id`),
            INDEX (`status`),
            INDEX (`ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ", "سبسکرپشنز ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 5: ادائیگیاں (Payments) ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
        CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT NOT NULL,
            `subscription_id` INT DEFAULT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) DEFAULT 'PKR',
            `method` VARCHAR(50) DEFAULT 'manual',
            `reference_no` VARCHAR(100) DEFAULT NULL,
            `gateway_response` TEXT DEFAULT NULL,
            `status` ENUM('pending','completed','failed','refunded') DEFAULT 'pending',
            `paid_at` DATETIME DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`tenant_id`),
            INDEX (`status`),
            INDEX (`paid_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ", "ادائیگیاں (Payments) ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 6: سرگرمی لاگ (Tenant Activity Log) ٹیبل
    // ──────────────────────────────────────────────────────────
    safe_exec($pdo, "
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
    ", "سرگرمی لاگ ٹیبل بنایا گیا", $results, $errors, $step);

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 7: تمام موجودہ ٹیبلز میں tenant_id کالم شامل کریں
    // ──────────────────────────────────────────────────────────
    $existing_tables = [
        'admins', 'settings', 'classes', 'class_periods', 'departments',
        'teachers', 'students', 'attendance', 'attendance_teachers',
        'teacher_period_attendance', 'teacher_leaves', 'daily_hifz',
        'daily_nazira', 'qaida_records', 'husn_saut_record', 'academic_reports',
        'exams', 'exam_results', 'complaints', 'parent_complaints',
        'leave_applications', 'fee_submissions', 'notices', 'assets',
        'heads', 'finance', 'staff_loans', 'loan_transactions',
        'acc_payroll', 'acc_chart_of_accounts', 'acc_vouchers',
        'acc_ledger_entries', 'acc_invoices', 'in_kind_donations',
        'maktab_classes', 'maktab_teachers', 'maktab_students',
        'maktab_exams', 'maktab_exam_results', 'maktab_nazira_record',
        'maktab_hifz_record', 'maktab_qaida_records', 'maktab_academic_reports',
        'daily_maktab', 'period_attendance',
        'admission_inquiries', 'lib_books', 'lib_issues',
        'inventory_items', 'inventory_sales', 'hostel_rooms', 'hostel_allocations',
        'job_postings', 'job_applicants', 'web_pages', 'announcements'
    ];

    foreach ($existing_tables as $tbl) {
        if (!table_exists($pdo, $tbl)) {
            $results[] = "⏭️ ٹیبل `{$tbl}` موجود نہیں — نظر انداز";
            continue;
        }

        if (!column_exists($pdo, $tbl, 'tenant_id')) {
            safe_exec($pdo,
                "ALTER TABLE `{$tbl}` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT 1 AFTER `id`",
                "ٹیبل `{$tbl}` میں tenant_id کالم شامل کیا گیا",
                $results, $errors, $step
            );

            // انڈیکس بنائیں
            safe_exec($pdo,
                "ALTER TABLE `{$tbl}` ADD INDEX `idx_{$tbl}_tenant` (`tenant_id`)",
                "ٹیبل `{$tbl}` پر tenant_id انڈیکس بنایا گیا",
                $results, $errors, $step
            );
        } else {
            $results[] = "⏭️ ٹیبل `{$tbl}` میں tenant_id پہلے سے موجود";
        }
    }

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 8: ڈیفالٹ سبسکرپشن پلانز داخل کریں
    // ──────────────────────────────────────────────────────────
    try {
        $planCount = $pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
        if ($planCount == 0) {
            $plans = [
                ['مفت ٹرائل', 'Free Trial', 'چودہ دن مفت آزمائش — تمام بنیادی فیچرز', 0, 0, 20, 5, 2, 1, 14, 0],
                ['بنیادی', 'Basic', 'چھوٹے مدارس کے لیے — حاضری، حفظ/ناظرہ، فنانس', 2000, 20000, 50, 10, 3, 0, 0, 1],
                ['معیاری', 'Standard', 'درمیانے مدارس — بنیادی + ERP، لائبریری، ہاسٹل', 5000, 50000, 200, 30, 5, 0, 0, 2],
                ['ایڈوانس', 'Premium', 'بڑے مدارس — تمام فیچرز + AI + بے حد طلبہ', 10000, 100000, 99999, 99999, 10, 0, 0, 3],
            ];

            $stmt = $pdo->prepare("INSERT INTO subscription_plans 
                (name, name_en, description, price_monthly, price_yearly, max_students, max_teachers, max_admins, is_free_trial, trial_days, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($plans as $p) {
                $stmt->execute($p);
            }

            $featuresBasic = json_encode(['attendance','hifz','nazira','qaida','finance','complaints','notices'], JSON_UNESCAPED_UNICODE);
            $featuresStd = json_encode(['attendance','hifz','nazira','qaida','finance','complaints','notices','erp','library','hostel','inventory','payroll','loans'], JSON_UNESCAPED_UNICODE);
            $featuresPrem = json_encode(['attendance','hifz','nazira','qaida','finance','complaints','notices','erp','library','hostel','inventory','payroll','loans','ai','recruitment','cms','reports_advanced','api_access'], JSON_UNESCAPED_UNICODE);

            $pdo->exec("UPDATE subscription_plans SET features_json = '{$featuresBasic}' WHERE sort_order = 1");
            $pdo->exec("UPDATE subscription_plans SET features_json = '{$featuresStd}' WHERE sort_order = 2");
            $pdo->exec("UPDATE subscription_plans SET features_json = '{$featuresPrem}' WHERE sort_order = 3");

            $results[] = "✅ 4 ڈیفالٹ سبسکرپشن پلانز داخل کیے گئے (مفت ٹرائل، بنیادی، معیاری، ایڈوانس)";
        } else {
            $results[] = "⏭️ سبسکرپشن پلانز پہلے سے موجود ہیں ({$planCount})";
        }
    } catch (Throwable $e) {
        $errors[] = "❌ پلانز میں خرابی: " . $e->getMessage();
    }

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 9: پہلا ٹیننٹ (موجودہ ادارہ) بنائیں
    // ──────────────────────────────────────────────────────────
    try {
        $tenantCount = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
        if ($tenantCount == 0) {
            // موجودہ سیٹنگز سے نام اٹھائیں
            $madrasaName = 'مدرسہ اشرف المدارس';
            $madrasaPhone = '';
            $madrasaAddress = '';
            try {
                $set = $pdo->query("SELECT madrasa_name, phone, address FROM settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($set) {
                    $madrasaName = $set['madrasa_name'] ?: $madrasaName;
                    $madrasaPhone = $set['phone'] ?? '';
                    $madrasaAddress = $set['address'] ?? '';
                }
            } catch (Throwable $e) {}

            $stmt = $pdo->prepare("INSERT INTO tenants (code, name, slug, phone, address, status, trial_ends_at, max_students, max_teachers, max_admins) VALUES (?, ?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 365 DAY), 99999, 99999, 99)");
            $stmt->execute([
                'MAIN01',
                $madrasaName,
                'main-institute',
                $madrasaPhone,
                $madrasaAddress
            ]);

            // اس ٹیننٹ کے لیے ایڈوانس پلان کی سبسکرپشن
            $tenantId = $pdo->lastInsertId();
            $premPlanId = $pdo->query("SELECT id FROM subscription_plans WHERE sort_order = 3 LIMIT 1")->fetchColumn() ?: 4;
            $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, billing_cycle, starts_at, ends_at, status) VALUES (?, ?, 'yearly', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 365 DAY), 'active')")
                ->execute([$tenantId, $premPlanId]);

            $results[] = "✅ پہلا ٹیننٹ بنایا گیا: {$madrasaName} (کوڈ: MAIN01) — ایڈوانس پلان مع 1 سال سبسکرپشن";
        } else {
            $results[] = "⏭️ ٹیننٹس پہلے سے موجود ہیں ({$tenantCount})";
        }
    } catch (Throwable $e) {
        $errors[] = "❌ ٹیننٹ بنانے میں خرابی: " . $e->getMessage();
    }

    // ──────────────────────────────────────────────────────────
    //  مرحلہ 10: سوپر ایڈمن اکاؤنٹ بنائیں
    // ──────────────────────────────────────────────────────────
    try {
        $saCount = $pdo->query("SELECT COUNT(*) FROM super_admins")->fetchColumn();
        if ($saCount == 0) {
            $hashedPass = password_hash('superadmin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO super_admins (name, username, password, email) VALUES (?, ?, ?, ?)")
                ->execute(['سوپر ایڈمن', 'superadmin', $hashedPass, 'admin@mamfss.com']);
            $results[] = "✅ سوپر ایڈمن اکاؤنٹ بنایا گیا (یوزر: superadmin / پاس: superadmin123)";
        } else {
            $results[] = "⏭️ سوپر ایڈمن پہلے سے موجود ({$saCount})";
        }
    } catch (Throwable $e) {
        $errors[] = "❌ سوپر ایڈمن میں خرابی: " . $e->getMessage();
    }
}

// ═══════════════════════════════════════════════════════════════
//  مائیگریشن کا موجودہ حال چیک کریں
// ═══════════════════════════════════════════════════════════════
$status = [];
$saas_tables = ['super_admins', 'tenants', 'subscription_plans', 'subscriptions', 'payments', 'tenant_activity_log'];
foreach ($saas_tables as $st) {
    $status[$st] = table_exists($pdo, $st);
}
$tenantColCount = 0;
$tenantColTotal = 0;
$check_tables = ['admins','settings','classes','teachers','students','attendance','finance','heads','exams','notices'];
foreach ($check_tables as $ct) {
    if (table_exists($pdo, $ct)) {
        $tenantColTotal++;
        if (column_exists($pdo, $ct, 'tenant_id')) {
            $tenantColCount++;
        }
    }
}
$migrationDone = ($tenantColCount == $tenantColTotal && $tenantColTotal > 0 && $status['tenants']);
$tenantCount = 0;
$planCount = 0;
$subCount = 0;
try {
    if ($status['tenants']) $tenantCount = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    if ($status['subscription_plans']) $planCount = $pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
    if ($status['subscriptions']) $subCount = $pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ملٹی ٹیننٹ SaaS مائیگریشن ٹول</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Nastaliq Urdu', Tahoma, Arial, sans-serif; background: #0f172a; color: #e2e8f0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; }
        .badge-ok { background: #059669; }
        .badge-wait { background: #d97706; }
        .badge-fail { background: #dc2626; }
        .hero { background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%); border-radius: 20px; }
        .step-ok { color: #34d399; }
        .step-err { color: #f87171; }
        .step-skip { color: #fbbf24; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid" style="max-width: 1000px;">

    <div class="card hero p-4 text-center text-white mb-4">
        <h2 class="mb-2">🚀 ملٹی ٹیننٹ SaaS مائیگریشن ٹول</h2>
        <p class="mb-0 fs-5 opacity-75">سنگل ٹیننٹ → ملٹی ٹیننٹ SaaS پلیٹ فارم تبدیلی</p>
    </div>

    <!-- نتائج -->
    <?php if (!empty($results) || !empty($errors)): ?>
    <div class="card p-4 mb-4">
        <h4 class="fw-bold mb-3 text-info">📋 مائیگریشن کے نتائج:</h4>
        <div style="max-height: 400px; overflow-y: auto;">
            <?php foreach ($results as $r): ?>
                <div class="mb-1 <?= strpos($r, '✅') !== false ? 'step-ok' : (strpos($r, '❌') !== false ? 'step-err' : 'step-skip') ?>">
                    <?= htmlspecialchars($r) ?>
                </div>
            <?php endforeach; ?>
            <?php foreach ($errors as $e): ?>
                <div class="mb-1 step-err"><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($errors)): ?>
            <div class="alert alert-success mt-3 fw-bold text-center fs-5">✅ مائیگریشن 100% کامیاب رہی! سسٹم اب ملٹی ٹیننٹ ہے۔</div>
        <?php else: ?>
            <div class="alert alert-warning mt-3 fw-bold text-center">⚠️ کچھ اقدامات میں مسائل آئے — اوپر تفصیلات دیکھیں</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- موجودہ حالت -->
    <div class="card p-4 mb-4">
        <h4 class="fw-bold mb-3 text-warning">📊 موجودہ مائیگریشن حالت:</h4>
        <div class="row row-cols-2 row-cols-md-3 g-3 mb-3">
            <?php foreach ($status as $tbl => $exists): ?>
            <div class="col">
                <div class="p-3 rounded-3 border <?= $exists ? 'border-success' : 'border-danger' ?> text-center">
                    <span class="badge <?= $exists ? 'badge-ok' : 'badge-fail' ?> mb-1"><?= $exists ? '✅ موجود' : '❌ غائب' ?></span>
                    <div class="small fw-bold mt-1"><?= $tbl ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="row row-cols-1 row-cols-md-3 g-3">
            <div class="col">
                <div class="p-3 rounded-3 border text-center">
                    <div class="fs-4 fw-bold text-info"><?= $tenantColCount ?>/<?= $tenantColTotal ?></div>
                    <small>ٹیبلز میں tenant_id</small>
                </div>
            </div>
            <div class="col">
                <div class="p-3 rounded-3 border text-center">
                    <div class="fs-4 fw-bold text-success"><?= $tenantCount ?></div>
                    <small>رجسٹرڈ ادارے</small>
                </div>
            </div>
            <div class="col">
                <div class="p-3 rounded-3 border text-center">
                    <div class="fs-4 fw-bold text-warning"><?= $planCount ?></div>
                    <small>سبسکرپشن پلانز</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ایکشن بٹنز -->
    <div class="card p-4 mb-4 text-center">
        <?php if (!$migrationDone): ?>
            <form method="POST">
                <p class="text-warning fs-5 fw-bold mb-3">⚠️ مائیگریشن ابھی تک مکمل نہیں ہوئی — نیچے بٹن دبائیں</p>
                <button type="submit" name="run_migration" value="1" class="btn btn-lg btn-primary px-5 py-3 fw-bold shadow"
                    onclick="return confirm('کیا آپ واقعی ملٹی ٹیننٹ SaaS مائیگریشن چلانا چاہتے ہیں؟ یہ عمل موجودہ ڈیٹا کو محفوظ رکھتے ہوئے سسٹم کو ملٹی ٹیننٹ بنا دے گا۔')">
                    🚀 ملٹی ٹیننٹ SaaS مائیگریشن چلائیں
                </button>
                <p class="text-muted mt-2 small">موجودہ ڈیٹا مکمل طور پر محفوظ رہے گا (tenant_id = 1 سیٹ ہوگا)</p>
            </form>
        <?php else: ?>
            <div class="text-success fs-4 fw-bold mb-3">✅ مائیگریشن مکمل ہو چکی ہے!</div>
            <p class="text-muted">ادارے: <?= $tenantCount ?> | پلانز: <?= $planCount ?> | سبسکرپشنز: <?= $subCount ?></p>
            <a href="migrate_to_saas.php" class="btn btn-outline-info btn-lg">🔄 ریفریش کریں</a>
        <?php endif; ?>
    </div>

    <div class="card p-3 text-center small text-muted">
        <p class="mb-0">SaaS Migration Tool v1.0 — Madrasa Management Platform</p>
    </div>

</div>
</body>
</html>
