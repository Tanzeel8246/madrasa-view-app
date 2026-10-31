<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  محفوظ ٹیننٹ ٹیسٹ ڈیٹا صفائی ٹول (Safe Tenant Test Data Cleaner)
 * ════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$user_role = strtolower((string)($_SESSION['role'] ?? ''));
if ($user_role !== 'superadmin' && $user_role !== 'super_admin' && $user_role !== 'admin') {
    die("<div class='container my-5 alert alert-danger'>" . __('صرف مجاز ایڈمنسٹریٹر کو یہ کارروائی انجام دینے کی اجازت ہے۔', 'Only authorized administrators are allowed to perform this action.') . "</div>");
}

$is_super = ($user_role === 'superadmin' || $user_role === 'super_admin' || !empty($_SESSION['is_super_admin']));
$msg = '';
$error = '';
$cur_tenant_id = (int)get_current_tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_clean'])) {
    // غیر سپر ایڈمن کسی بھی صورت اپنے موجودہ ٹیننٹ کے علاوہ کسی دوسرے ٹیننٹ کا ڈیٹا صاف نہیں کر سکتا
    $target_tenant = $is_super ? (int)($_POST['target_tenant_id'] ?? $cur_tenant_id) : $cur_tenant_id;
    $clean_finance = isset($_POST['clean_finance']);
    $clean_attendance = isset($_POST['clean_attendance']);
    $clean_test_students = isset($_POST['clean_test_students']);

    if ($target_tenant <= 0) {
        $error = "برائے مہربانی درست ٹیننٹ منتخب کریں۔";
    } else {
        try {
            $pdo->beginTransaction();
            $tCond = ($target_tenant === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $target_tenant";

            // خودکار سیفٹی بیک اپ ہر صفائی سے قبل لازمی
            $tables = ['students', 'teachers', 'classes', 'attendance', 'finance', 'heads', 'hifz_record'];
            $backupSql = "-- Auto Safety Backup before cleanup for Tenant $target_tenant\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
            $tCondB = ($target_tenant === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $target_tenant";
            foreach ($tables as $tbl) {
                try {
                    $stmtB = $pdo->query("SELECT * FROM `$tbl` WHERE $tCondB");
                    $rowsB = $stmtB->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rowsB)) {
                        foreach ($rowsB as $r) {
                            $keys = array_map(function($k) { return "`$k`"; }, array_keys($r));
                            $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($r));
                            $backupSql .= "INSERT INTO `$tbl` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                        }
                    }
                } catch (Throwable $eB) {}
            }
            $bDir = __DIR__ . '/uploads/backups/';
            if (!is_dir($bDir)) @mkdir($bDir, 0755, true);
            file_put_contents($bDir . 'auto_backup_tenant_' . $target_tenant . '_' . date('Y-m-d_His') . '.sql', $backupSql);

            if ($clean_finance) {
                $pdo->exec("UPDATE finance SET is_deleted = 1, deleted_at = NOW() WHERE $tCond AND (description LIKE '%ٹیسٹ%' OR description LIKE '%test%' OR donor_name LIKE '%ٹیسٹ%' OR donor_name LIKE '%test%')");
                $pdo->exec("UPDATE masjid_finance SET is_deleted = 1, deleted_at = NOW() WHERE $tCond AND (description LIKE '%ٹیسٹ%' OR description LIKE '%test%')");
            }

            if ($clean_attendance) {
                // Remove orphaned dummy attendance
                $pdo->exec("DELETE FROM attendance WHERE $tCond AND student_id NOT IN (SELECT id FROM students WHERE $tCond)");
            }

            if ($clean_test_students) {
                $pdo->exec("UPDATE students SET is_deleted = 1 WHERE $tCond AND (name LIKE '%ٹیسٹ%' OR name LIKE '%test%' OR roll_number LIKE '%test%')");
                $pdo->exec("UPDATE maktab_students SET is_deleted = 1 WHERE $tCond AND (name LIKE '%ٹیسٹ%' OR name LIKE '%test%' OR roll_number LIKE '%test%')");
            }

            $pdo->commit();
            $msg = "ٹیننٹ #$target_tenant کے لیے ٹیسٹ ڈیٹا کامیابی سے صاف اور آرکائیو کر دیا گیا۔";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "خرابی: " . $e->getMessage();
        }
    }
}
?>

<div class="container my-4">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-danger text-white py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-broom me-2"></i> <?php echo __('محفوظ ٹیننٹ ٹیسٹ ڈیٹا صفائی (Safe Test Data Cleaner)', 'Safe Tenant Test Data Cleaner'); ?></h5>
        </div>
        <div class="card-body p-4">
            <?php if ($msg): ?>
                <div class="alert alert-success fw-bold"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger fw-bold"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('<?php echo __('کیا آپ واقعی منتخب ٹیسٹ ڈیٹا کو صاف کرنا چاہتے ہیں؟', 'Are you sure you want to clean up the selected test data?'); ?>');">
                <?php if ($is_super): 
                    $all_tenants = [];
                    try {
                        $all_tenants = $pdo->query("SELECT id, name, code FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $eT) {}
                ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('ٹیننٹ منتخب کریں (سپر ایڈمن موڈ):', 'Select Tenant (Super Admin Mode):'); ?></label>
                    <select name="target_tenant_id" class="form-select" required>
                        <?php foreach ($all_tenants as $tn): ?>
                            <option value="<?php echo $tn['id']; ?>" <?php echo ((int)$tn['id'] === $cur_tenant_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tn['name']); ?> (<?php echo __('کوڈ:', 'Code:'); ?> <?php echo htmlspecialchars($tn['code'] ?? ''); ?> - <?php echo __('آئی ڈی:', 'ID:'); ?> <?php echo $tn['id']; ?>) <?php echo ((int)$tn['id'] === $cur_tenant_id) ? '— [' . __('موجودہ لاگ ان ٹیننٹ', 'Currently Logged-in Tenant') . ']' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i> <?php echo __('احتیاط: ڈیفالٹ میں آپ کا موجودہ ٹیننٹ منتخب ہے۔', 'Caution: By default, your currently logged-in tenant is selected.'); ?></small>
                </div>
                <?php else: ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('ٹیننٹ:', 'Tenant:'); ?></label>
                    <input type="hidden" name="target_tenant_id" value="<?php echo $cur_tenant_id; ?>">
                    <div class="form-control bg-light fw-bold text-primary">
                        <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($_SESSION['tenant_name'] ?? __('موجودہ ادارہ', 'Current Institute')); ?> (<?php echo __('ٹیننٹ آئی ڈی:', 'Tenant ID:'); ?> <?php echo $cur_tenant_id; ?>)
                    </div>
                    <small class="text-muted"><i class="fas fa-lock me-1"></i> <?php echo __('سیکیورٹی پروٹیکشن: آپ صرف اپنے ادارے کا ٹیسٹ ڈیٹا صاف کرنے کے مجاز ہیں۔', 'Security Protection: You are only authorized to clean data for your own institute.'); ?></small>
                </div>
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label fw-bold"><?php echo __('صفائی کے اختیارات:', 'Cleanup Options:'); ?></label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="clean_test_students" id="c1" checked>
                        <label class="form-check-label" for="c1"><?php echo __('فرضی و ٹیسٹ طلبہ ریکارڈز کی صفائی (نام میں test یا ٹیسٹ ہو)', 'Clean test student records (containing "test" in name/roll no)'); ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="clean_finance" id="c2" checked>
                        <label class="form-check-label" for="c2"><?php echo __('فرضی فنانس اندراجات کی صفائی', 'Clean dummy finance entries'); ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="clean_attendance" id="c3" checked>
                        <label class="form-check-label" for="c3"><?php echo __('غیر متعلقہ یا یتیم (Orphaned) حاضری اندراجات کا خاتمہ', 'Remove orphaned attendance records'); ?></label>
                    </div>
                </div>

                <button type="submit" name="confirm_clean" value="1" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fas fa-trash-alt me-1"></i> <?php echo __('تصدیق کریں اور صفائی کریں', 'Confirm and Clean Data'); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
