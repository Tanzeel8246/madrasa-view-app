<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  ملٹی ٹیننٹ ڈیٹا بیس بیک اپ و ایکسل ایکسپورٹ ٹول
 *  Tenant-Specific Backup & Excel Data Export Tool
 * ════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$cur_tenant_id = (int)get_current_tenant_id();
$user_role = strtolower((string)($_SESSION['role'] ?? ''));

if ($user_role !== 'admin' && $user_role !== 'superadmin' && $user_role !== 'super_admin') {
    die("<div class='container my-5 alert alert-danger'>" . __('صرف ایڈمنسٹریٹر کو ڈیٹا بیک اپ اور ایکسپورٹ کی اجازت ہے۔', 'Only administrators are allowed to backup and export data.') . "</div>");
}

$action = $_GET['action'] ?? '';

// 1. Export Specific Table as CSV / Excel
if ($action === 'export_csv') {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? 'students');
    $allowed_tables = ['students', 'teachers', 'attendance', 'finance', 'hifz_record', 'classes', 'maktab_students'];
    if (!in_array($table, $allowed_tables, true)) {
        die("Invalid table specified.");
    }

    $tCond = ($cur_tenant_id === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
    $hasIsDel = false;
    try {
        $hasIsDel = (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE 'is_deleted'")->fetch();
    } catch (Throwable $e) {}
    $delCond = $hasIsDel ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
    $stmt = $pdo->query("SELECT * FROM `$table` WHERE $tCond $delCond");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tenant_' . $cur_tenant_id . '_' . $table . '_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for Urdu support in Excel
    fputs($output, "\xEF\xBB\xBF");

    if (!empty($rows)) {
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

// 2. Export Full SQL Snapshot for this Tenant
if ($action === 'export_sql') {
    $tables = ['students', 'teachers', 'classes', 'attendance', 'finance', 'heads', 'hifz_record', 'nazira_record', 'qaida_records', 'maktab_students', 'maktab_teachers', 'maktab_classes'];
    $sqlDump = "-- Tenant $cur_tenant_id Snapshot Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";

    $tCond = ($cur_tenant_id === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
    foreach ($tables as $tbl) {
        try {
            $stmt = $pdo->query("SELECT * FROM `$tbl` WHERE $tCond");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $sqlDump .= "-- Table: $tbl\n";
                foreach ($rows as $r) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($r));
                    $vals = array_map(function($v) use ($pdo) {
                        return $v === null ? "NULL" : $pdo->quote($v);
                    }, array_values($r));
                    $sqlDump .= "INSERT INTO `$tbl` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sqlDump .= "\n";
            }
        } catch (Throwable $e) {}
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename=tenant_' . $cur_tenant_id . '_backup_' . date('Y-m-d_His') . '.sql');
    echo $sqlDump;
    exit;
}
?>

<div class="container my-4">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-database me-2 text-warning"></i> <?php echo __('ڈیٹا بیک اپ و ایکسل ایکسپورٹ (Tenant Data Management)', 'Data Backup & Excel Export (Tenant Data Management)'); ?></h5>
            <span class="badge bg-warning text-dark px-3 py-2"><?php echo __('ٹیننٹ آئی ڈی:', 'Tenant ID:'); ?> <?php echo $cur_tenant_id; ?></span>
        </div>
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light p-4 rounded-4 shadow-sm text-center">
                        <div class="mb-3"><i class="fas fa-file-archive fa-3x text-primary"></i></div>
                        <h5 class="fw-bold"><?php echo __('مکمل ادارہ جاتی ایس کیو ایل بیک اپ', 'Full Institutional SQL Backup'); ?></h5>
                        <p class="text-muted small"><?php echo __('اپنے ادارے کے تمام طلبہ، کلاسز، اساتذہ، حاضری اور فنانس ریکارڈز کا مکمل ایس کیو ایل اسنیپ شاٹ ڈاؤن لوڈ کریں۔', 'Download a complete SQL snapshot of all students, classes, teachers, attendance, and finance records.'); ?></p>
                        <div class="mt-auto">
                            <a href="?action=export_sql" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                                <i class="fas fa-download me-1"></i> <?php echo __('بیک اپ ڈاؤن لوڈ کریں (.sql)', 'Download Backup (.sql)'); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light p-4 rounded-4 shadow-sm">
                        <div class="text-center mb-3"><i class="fas fa-file-excel fa-3x text-success"></i></div>
                        <h5 class="fw-bold text-center"><?php echo __('ایکسل (CSV) فائلز ایکسپورٹ', 'Excel (CSV) Files Export'); ?></h5>
                        <p class="text-muted small text-center"><?php echo __('مخصوص ماڈیول کا ڈیٹا باآسانی ایکسل یا گوگل شیٹس میں کھولنے کے لیے ایکسپورٹ کریں۔', 'Export specific module data to easily open in Excel or Google Sheets.'); ?></p>
                        <div class="list-group list-group-flush rounded-3">
                            <a href="?action=export_csv&table=students" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-user-graduate text-success me-2"></i> <?php echo __('طلبہ ریکارڈ (Students)', 'Student Records (Students)'); ?></span>
                                <i class="fas fa-file-download text-muted"></i>
                            </a>
                            <a href="?action=export_csv&table=teachers" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-chalkboard-teacher text-info me-2"></i> <?php echo __('اساتذہ ریکارڈ (Teachers)', 'Teacher Records (Teachers)'); ?></span>
                                <i class="fas fa-file-download text-muted"></i>
                            </a>
                            <a href="?action=export_csv&table=attendance" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-calendar-check text-warning me-2"></i> <?php echo __('حاضری لاگز (Attendance)', 'Attendance Logs (Attendance)'); ?></span>
                                <i class="fas fa-file-download text-muted"></i>
                            </a>
                            <a href="?action=export_csv&table=finance" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-coins text-danger me-2"></i> <?php echo __('مالیاتی آمدن و خرچ (Finance)', 'Finance Income & Expenses (Finance)'); ?></span>
                                <i class="fas fa-file-download text-muted"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
