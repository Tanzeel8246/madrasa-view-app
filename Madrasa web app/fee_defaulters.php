<?php 
require_once 'db.php'; 
include 'header.php'; 

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "s.system_type = 'Masjid'" : "(s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')";

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
$p_needed = ($sys === 'Masjid') ? 'manage_masjid_fee_defaulters' : 'manage_fee_defaulters';
$p_alt1 = ($sys === 'Masjid') ? 'manage_masjid_finance' : 'manage_finance';
$p_alt2 = ($sys === 'Masjid') ? 'manage_masjid_finance_report' : 'manage_finance_report';
if (!$is_admin && !app_has_perm($p_needed) && !app_has_perm($p_alt1) && !app_has_perm($p_alt2)) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$selected_month = $_GET['month'] ?? date('Y-m');
$selected_class = $_GET['class'] ?? '';
$search = trim($_GET['search'] ?? '');

// Fetch Allowed Classes strictly for this user and system
$allowed_classes = get_allowed_classes($pdo, 'name ASC', null, null, $sys);
$classes = array_values(array_filter(array_unique(array_column($allowed_classes, 'name'))));

if (!$is_admin && !empty($selected_class) && !in_array($selected_class, $classes, true)) {
    $selected_class = '';
}

// Query Defaulters
$defaulters = [];
try {
    if (function_exists('ensure_column')) {
        ensure_column($pdo, 'students', 'monthly_fee', 'DECIMAL(10,2) DEFAULT 0');
        ensure_column($pdo, 'fee_submissions', 'submission_date', 'DATE DEFAULT NULL');
        ensure_column($pdo, 'fee_submissions', 'date', 'DATE DEFAULT NULL');
    }

    $privacy_sql = get_student_class_privacy_sql($pdo, 's.class');

    $query = "
        SELECT s.id, s.name, s.father_name, s.class, s.contact_number, COALESCE(s.monthly_fee, 0) as monthly_fee
        FROM students s
        WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL)
        AND $sys_cond
        AND ($privacy_sql)
        AND s.id NOT IN (
            SELECT student_id FROM fee_submissions 
            WHERE (COALESCE(submission_date, date) LIKE ?) AND (LOWER(status) IN ('approved', 'paid', 'ادا شدہ') OR status IS NULL)
        )
    ";
    $params = ["$selected_month%"];

    if (!empty($selected_class)) {
        $query .= " AND s.class = ?";
        $params[] = $selected_class;
    }

    if (!empty($search)) {
        $query .= " AND (s.name LIKE ? OR s.father_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $query .= " ORDER BY s.class ASC, s.name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $defaulters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $defaulters = [];
}

$month_title = function_exists('format_urdu_month_year') ? format_urdu_month_year($selected_month) : date('F Y', strtotime($selected_month . '-01'));

// Bulk WhatsApp message
$bulk_msg = "📋 *فہرست واجب الادا فیس جات - مدرسہ اشرف المدارس فروکہ*\n";
$bulk_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$bulk_msg .= "📅 *ماہ :* " . $month_title . "\n";
$bulk_msg .= "👥 *کل طلبہ :* " . count($defaulters) . "\n";
$bulk_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$cnt = 1;
foreach ($defaulters as $d) {
    $amt = number_format((float)($d['monthly_fee'] ?: 1000));
    $bulk_msg .= "$cnt. {$d['name']} ولد {$d['father_name']} ({$d['class']}) - $amt روپے\n";
    $cnt++;
}
$bulk_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$bulk_msg .= "_منجانب: ادارہ اشرف المدارس فروکہ_";
?>

<div class="container-fluid py-3" dir="rtl">

    <div class="card p-3 mb-4 bg-light shadow-sm border-0 rounded-3 d-print-none">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="fw-bold small mb-1">ماہ (Month):</label>
                <input type="month" name="month" class="form-control fw-bold" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="this.form.submit()" required>
            </div>
            <div class="col-md-3">
                <label class="fw-bold small mb-1">درجہ (Class):</label>
                <select name="class" class="form-select" onchange="this.form.submit()">
                    <option value="">تمام درجات (All Classes)</option>
                    <?php foreach($classes as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($selected_class == $c) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="fw-bold small mb-1">تلاش (Search):</label>
                <input type="text" name="search" class="form-control" placeholder="نام یا ولدیت لکھیں..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary fw-bold w-100"><i class="fas fa-search me-1"></i> تلاش کریں</button>
                <button type="button" onclick="window.print()" class="btn btn-dark fw-bold"><i class="fas fa-print"></i></button>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-3">
            <div class="fw-bold fs-5">
                <i class="fas fa-exclamation-circle me-2"></i> واجب الادا فیس کی فہرست (بابت ماہ: <?php echo $month_title; ?>)
            </div>
            <div class="d-flex gap-2 d-print-none">
                <button type="button" class="btn btn-success fw-bold btn-sm" data-bs-toggle="modal" data-bs-target="#bulkWAModal">
                    <i class="fab fa-whatsapp me-1"></i> تمام لسٹ WhatsApp متن
                </button>
                <span class="badge bg-white text-danger fs-6 align-self-center">کل تعداد: <?php echo count($defaulters); ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle text-center mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 50px;">شمار</th>
                        <th>نام طالب علم</th>
                        <th>ولدیت</th>
                        <th>درجہ / کلاس</th>
                        <th>فون نمبر</th>
                        <th>ماہانہ فیس</th>
                        <th class="d-print-none">ایکشن (WhatsApp یاددہانی)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($defaulters)): ?>
                    <tr>
                        <td colspan="7" class="py-4 text-success fw-bold fs-5">
                            <i class="fas fa-check-circle me-2"></i> تمام طلبہ کی فیس ادا شدہ ہے! ماشاء اللہ
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php $idx = 1; foreach($defaulters as $row): 
                        $amt = number_format((float)($row['monthly_fee'] ?: 1000));
                        $rawPhone = preg_replace('/[^0-9]/', '', $row['contact_number'] ?? '');
                        if (str_starts_with($rawPhone, '0')) { $rawPhone = '92' . substr($rawPhone, 1); }
                        
                        $indiv_msg = "محترم والدین: جناب {$row['father_name']}!\n";
                        $indiv_msg .= "السلام علیکم ورحمۃ اللہ وبرکاتہ!\n";
                        $indiv_msg .= "اطلاع دی جاتی ہے کہ طالب علم *{$row['name']}* کی بابت ماہ *{$month_title}* کے تعلیمی اخراجات / ماہانہ فیس مبلغ *{$amt} روپے* واجب الادا ہیں۔\n";
                        $indiv_msg .= "براہ کرم بر وقت جمع کروا کر رسید حاصل فرمائیں۔ شکریہ!\n";
                        $indiv_msg .= "_ادارہ اشرف المدارس فروکہ_";
                        $wa_link = !empty($rawPhone) ? "https://wa.me/{$rawPhone}?text=" . urlencode($indiv_msg) : "#";
                    ?>
                    <tr>
                        <td><?php echo $idx++; ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['father_name']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['class']); ?></span></td>
                        <td dir="ltr"><?php echo htmlspecialchars($row['contact_number'] ?: '---'); ?></td>
                        <td class="fw-bold text-danger">Rs. <?php echo $amt; ?></td>
                        <td class="d-print-none">
                            <?php if(!empty($rawPhone)): ?>
                            <a href="<?php echo $wa_link; ?>" target="_blank" class="btn btn-sm btn-success fw-bold">
                                <i class="fab fa-whatsapp me-1"></i> یاددہانی بھیجیں
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">نمبر موجود نہیں</span>
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

<!-- Bulk WhatsApp Modal -->
<div class="modal fade" id="bulkWAModal" tabindex="-1" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fab fa-whatsapp me-2"></i> تمام واجب الادا فیس لسٹ کا WhatsApp متن</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">آپ اس متن کو کاپی کر کے اپنے WhatsApp گروپ یا ایڈمنز کو بھیج سکتے ہیں:</p>
                <textarea id="bulkWAText" class="form-control fw-bold" rows="12" readonly style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($bulk_msg); ?></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary fw-bold" onclick="copyBulkText()"><i class="fas fa-copy me-1"></i> متن کاپی کریں</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
            </div>
        </div>
    </div>
</div>

<script>
function copyBulkText() {
    const txt = document.getElementById('bulkWAText');
    txt.select();
    navigator.clipboard.writeText(txt.value);
    alert('واٹس ایپ لسٹ کامیابی سے کاپی ہو گئی!');
}
</script>

<?php include 'footer.php'; ?>
