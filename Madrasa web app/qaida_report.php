<?php
require_once 'db.php';

// 1. فلٹرز - header include سے پہلے
$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();

try {
    $st_stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
    $st_stmt->execute([$cur_tenant_id]);
    $settings = $st_stmt->fetch();
    $week_start_day = $settings['week_start_day'] ?? 6;
} catch (Exception $e) {
    $week_start_day = 6;
    // echo "Settings table error: " . $e->getMessage() . "<br>";
}

$period = $_GET['period'] ?? 'current_week';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';

if (empty($start_date) || empty($end_date)) {
    $range = get_period_date_range($period, $start_date, $end_date, $week_start_day);
    $start_date = $range['from'];
    $end_date = $range['to'];
}

include 'header.php';

$student_roll_select = "s.admission_number AS roll_number";
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}



$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;
$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "(s.system_type = 'Masjid' OR s.admission_number LIKE 'M-%' OR COALESCE(s.roll_number, '') LIKE 'M-%')" : "((s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '') AND (s.admission_number NOT LIKE 'M-%' AND COALESCE(s.roll_number, '') NOT LIKE 'M-%'))";
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM students LIKE 'class_name'");
    $hasClassName = ($chkCol && $chkCol->rowCount() > 0);

    $class_id = 0;
    try {
        $c_stmt = $pdo->prepare("SELECT id FROM classes WHERE name = ? LIMIT 1");
        $c_stmt->execute([$selected_class]);
        $class_row = $c_stmt->fetch();
        if ($class_row) {
            $class_id = (int)$class_row['id'];
        }
    } catch (Exception $eC) {}

    $matchConditions = [
        "s.class = ?",
        "TRIM(s.class) = ?",
        "s.class LIKE ?"
    ];
    $matchParams = [
        $selected_class,
        trim($selected_class),
        '%' . trim($selected_class) . '%'
    ];

    if ($hasClassName) {
        $matchConditions[] = "s.class_name = ?";
        $matchConditions[] = "TRIM(s.class_name) = ?";
        $matchConditions[] = "s.class_name LIKE ?";
        $matchParams[] = $selected_class;
        $matchParams[] = trim($selected_class);
        $matchParams[] = '%' . trim($selected_class) . '%';
    }

    if ($class_id > 0) {
        $matchConditions[] = "s.class = '$class_id'";
        if ($hasClassName) {
            $matchConditions[] = "s.class_name = '$class_id'";
        }
    }

    $classMatchSql = "(" . implode(" OR ", $matchConditions) . ")";

    // Query students
    $stmt_lazy = $pdo->prepare("
        SELECT s.*, $student_roll_select 
        FROM students s 
        WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
          AND (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
          AND $sys_cond
          AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
          AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
          AND $classMatchSql 
        ORDER BY s.name ASC
    ");
    $stmt_lazy->execute(array_merge([$cur_tenant_id], $matchParams));
    $all_class_students = $stmt_lazy->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if tenant_id was restrictive
    if (empty($all_class_students)) {
        $stmt_lazy_fallback = $pdo->prepare("
            SELECT s.*, $student_roll_select 
            FROM students s 
            WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
              AND $sys_cond 
              AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
              AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
              AND $classMatchSql 
            ORDER BY s.name ASC
        ");
        $stmt_lazy_fallback->execute($matchParams);
        $all_class_students = $stmt_lazy_fallback->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback to maktab_students if still empty
    if (empty($all_class_students) && $sys_type === 'Masjid') {
        try {
            $m_stmt = $pdo->prepare("
                SELECT s.*, $student_roll_select 
                FROM maktab_students s 
                WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
                  AND (s.class = ? OR TRIM(s.class) = ? OR s.class LIKE ?) 
                ORDER BY s.name ASC
            ");
            $m_stmt->execute([$selected_class, trim($selected_class), '%' . trim($selected_class) . '%']);
            $all_class_students = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eM) {}
    }

    if ($selected_student_id > 0) {
        $all_qaida_students = array_values(array_filter($all_class_students, function($st) use ($selected_student_id) {
            return (int)$st['id'] === $selected_student_id;
        }));
    } else {
        $filtered_qaida = array_values(array_filter($all_class_students, function($st) use ($start_date) {
            return !is_student_inactive($st, $start_date);
        }));
        $all_qaida_students = !empty($filtered_qaida) ? $filtered_qaida : $all_class_students;
    }
} catch (PDOException $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger'><strong>ڈیٹا بیس ایرر (Students):</strong> " . $e->getMessage() . "</div></div>";
    $all_class_students = [];
    $all_qaida_students = [];
}

// Helpers
function getGradePoints($grade, $max) {
    if (!$grade) return 0;
    $g = trim($grade);
    if ($g == 'Excellent' || $g == 'ممتاز' || $g == 'بہترین' || $g == 'اعلیٰ' || $g == 'بہت اچھے' || $g == 'بہت اچھا') return $max;
    if ($g == 'Good' || $g == 'بہتر' || $g == 'اچھا' || $g == 'ٹھیک') return $max * 0.8;
    if ($g == 'Average' || $g == 'معمولی' || $g == 'مناسب' || $g == 'درمیانہ') return $max * 0.6;
    if ($g == 'Weak' || $g == 'کمزور' || $g == 'توجہ طلب' || $g == 'بہت کمزور') return $max * 0.4;
    return 0;
}

function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'تمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}

function translateStatusClean($status) {
    $map = ['Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور', 'بہترین'=>'بہترین', 'بہتر'=>'بہتر', 'کمزور'=>'کمزور'];
    $color = ['Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger', 'بہترین'=>'success', 'بہتر'=>'primary', 'کمزور'=>'danger'];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}

function translateQtyClean($qty) {
    $map = ['Quarter'=> 'پاؤ', 'Half'=> 'آدھا', 'Three Quarter'=> 'تین پاؤ', 'Full'=> 'مکمل', '1.5 Parah'=> 'ڈیڑھ', '2 Parah'=> 'دو', '2.5 Parah'=> 'ڈھائی', '3 Parah'=> 'تین'];
    return $map[$qty] ?? $qty;
}

function shortName($name) {
    return (strlen($name) > 15) ? mb_substr($name, 0, 15).'..' : $name;
}

function cleanUrduStr($str) {
    if(empty($str)) return '-';
    $str = str_replace(['(3/4)', '(1/2)', '(1/4)', '(1)', '((', '))', 'پارہ '], ['', '', '', '', '(', ')', ''], $str);
    $str = preg_replace('/\(?\b(\d{1,2}:\d{2}(?:\s*[aApP][mM])?)\b\)?/i', '<div class="small text-muted" style="font-size: 0.75rem;">$1</div>', $str);
    return trim($str);
}
?>

<style>
    .report-wrapper { width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; }
    .table-responsive { width: 100% !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table th, .table td { word-break: break-word !important; white-space: normal !important; padding: 4px 6px !important; font-size: clamp(10px, 1.1vw, 13px); vertical-align: middle; }
    .bg-light-purple { background-color: #f3e5f5; }
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
    
    @media print {
        @page { size: A4 landscape; margin: 5mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .d-print-none { display: none !important; }
        #printableArea { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .table { width: 100% !important; table-layout: fixed !important; max-width: 100% !important; }
        .table th, .table td { 
            white-space: normal !important; 
            word-wrap: break-word !important; 
            font-size: 9px !important; 
            padding: 2px !important; 
            line-height: 1.1 !important;
        }
        .small { font-size: 8px !important; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="fw-bold text-success mb-0"><i class="fas fa-filter me-2"></i> نورانی قاعدہ رپورٹ فلٹرز & پرنٹ</h4>
        <a href="academic_view_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1"><i class="fas fa-user-graduate me-1 text-success"></i> طالب علم (سرچ کریں):</label>
            <select name="student_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                <?php foreach($all_class_students as $st): ?>
                    <option value="<?php echo $st['id']; ?>" <?php echo $selected_student_id == $st['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($st['admission_number'] . ' - ' . $st['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">دورانیہ (Period):</label>
            <select name="period" id="qaidaPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyQaidaPeriodDates(this.value)">
                <option value="current_week" <?php echo $period==='current_week'?'selected':''; ?>>رواں ہفتہ (ڈیفالٹ)</option>
                <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (آخری 7 دن)</option>
                <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (آخری 30 دن)</option>
                <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (آخری 90 دن)</option>
                <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (آخری 180 دن)</option>
                <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (پورا سال)</option>
                <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ سے:</label>
            <input type="date" name="from" id="qaidaFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('qaidaToDate').value) this.form.submit();" required>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ تک:</label>
            <input type="date" name="to" id="qaidaToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('qaidaFromDate').value) this.form.submit();" required>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-success btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> فلٹر کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-print me-1"></i> پرنٹ</button>
        </div>
    </form>
</div>

<script>
function applyQaidaPeriodDates(val) {
    const today = new Date();
    const formatDate = (d) => {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    let fromDate = new Date();
    let toDate = new Date();
    const weekStartDay = <?php echo (int)$week_start_day; ?>;

    if (val === 'current_week') {
        const currentDayNum = today.getDay();
        const daysSinceStart = (currentDayNum - weekStartDay + 7) % 7;
        fromDate.setDate(today.getDate() - daysSinceStart);
        toDate = today;
    } else if (val === 'weekly') {
        fromDate.setDate(today.getDate() - 6);
        toDate = today;
    } else if (val === 'monthly') {
        fromDate.setDate(today.getDate() - 29);
        toDate = today;
    } else if (val === 'quarterly') {
        fromDate.setDate(today.getDate() - 89);
        toDate = today;
    } else if (val === 'semi_annually') {
        fromDate.setDate(today.getDate() - 179);
        toDate = today;
    } else if (val === 'annually') {
        fromDate.setDate(today.getDate() - 364);
        toDate = today;
    }
    if (val !== 'custom') {
        document.getElementById('qaidaFromDate').value = formatDate(fromDate);
        document.getElementById('qaidaToDate').value = formatDate(toDate);
        document.getElementById('qaidaPeriodSelect').form.submit();
    }
}
</script>

<div id="printableArea" class="bg-white p-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-quran me-2"></i> قاعدہ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <div class="table-responsive" style="width: 100%; max-width: 100%; border-radius: 8px;">
        <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="font-size: 12px; width: 100% !important; max-width: 100% !important; table-layout: fixed !important;">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width: 2.5%;">#</th>
                    <th rowspan="2" style="width: 4.5%;">رول نمبر</th>
                    <th rowspan="2" style="width: 11%;"><?php echo t('student'); ?></th>
                    <th rowspan="2" style="width: 5%;">سنانے کا وقت</th>
                    <th colspan="3" class="bg-success text-white">سبق 1</th>
                    <th colspan="3" class="bg-primary text-white">سبق 2</th>
                    <th colspan="3" class="bg-warning text-dark">سبق 3</th>
                    <th colspan="3" class="bg-info text-white">سبق 4</th>
                    <th rowspan="2" style="width: 4.5%;" class="bg-secondary text-white">حاضری</th>
                    <th rowspan="2" style="width: 17.5%;" class="bg-danger text-white">شکایات / رویہ</th>
                </tr>
                <tr>
                    <th style="width: 4%;">تختی/صفحہ</th><th style="width: 4%;">مقدار</th><th style="width: 4.5%;">کیفیت</th>
                    <th style="width: 4%;">تختی/صفحہ</th><th style="width: 4%;">مقدار</th><th style="width: 4.5%;">کیفیت</th>
                    <th style="width: 4%;">تختی/صفحہ</th><th style="width: 4%;">مقدار</th><th style="width: 4.5%;">کیفیت</th>
                    <th style="width: 4%;">تختی/صفحہ</th><th style="width: 4%;">مقدار</th><th style="width: 4.5%;">کیفیت</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Date normalization helper
                $norm_date = function($dRaw) {
                    $dRaw = explode(' ', trim((string)$dRaw))[0];
                    if (empty($dRaw)) return '';
                    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                    elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    $ts = strtotime($dRaw);
                    return $ts ? date('Y-m-d', $ts) : $dRaw;
                };

                // Fetch Qaida records
                $sql = "SELECT d.* FROM qaida_records d 
                        JOIN students s ON d.student_id = s.id 
                        WHERE d.tenant_id = ? AND s.tenant_id = ? AND (d.is_deleted = 0 OR d.is_deleted IS NULL) AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$cur_tenant_id, $cur_tenant_id, $selected_class]);
                    $hifz_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    echo "<tr><td colspan='15'>DB Error: " . $e->getMessage() . "</td></tr>";
                    $hifz_records = [];
                }

                $qaida_map = [];
                foreach ($hifz_records as $rec) {
                    $dNorm = $norm_date($rec['date'] ?? '');
                    if ($dNorm >= $start_date && $dNorm <= $end_date) {
                        $qaida_map[$dNorm][$rec['student_id']] = $rec;
                    }
                }

                // Fetch Complaints for the date range
                $c_sql = "SELECT c.student_id, c.date, c.complaint as description, 'admin' as type FROM complaints c WHERE c.tenant_id = $cur_tenant_id";
                $pc_sql = "SELECT p.student_id, p.date, CONCAT('[', p.category, '] ', p.description) as description, 'parent' as type FROM parent_complaints p WHERE p.tenant_id = $cur_tenant_id";
                
                $all_c_sql = "$c_sql UNION ALL $pc_sql";
                try {
                    $c_stmt = $pdo->query($all_c_sql);
                    $complaints_data = $c_stmt->fetchAll();
                } catch (PDOException $e) {
                    $c_stmt = $pdo->query($c_sql);
                    $complaints_data = $c_stmt->fetchAll();
                }
                
                $complaints_map = [];
                foreach($complaints_data as $cd) {
                    $dNorm = $norm_date($cd['date'] ?? '');
                    if ($dNorm >= $start_date && $dNorm <= $end_date) {
                        $key = $cd['student_id'] . '_' . $dNorm;
                        if(!isset($complaints_map[$key])) $complaints_map[$key] = [];
                        $complaints_map[$key][] = ($cd['type'] == 'parent' ? '⚠️(والدین)' : '') . $cd['description'];
                    }
                }

                // Fetch Attendance for all class students
                $all_q_sids = array_map(function($st) { return (int)$st['id']; }, $all_qaida_students);
                $att_map = [];

                if (!empty($all_q_sids)) {
                    $in_clause = implode(',', $all_q_sids);
                    try {
                        $att_sql = "SELECT * FROM attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_clause)";
                        $att_stmt = $pdo->query($att_sql);
                        $att_records = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($att_records as $a) {
                            $dNorm = $norm_date($a['date'] ?? '');
                            if (!$dNorm || $dNorm < $start_date || $dNorm > $end_date) continue;
                            $sid = (int)$a['student_id'];
                            $st = mb_strtoupper(trim(($a['status'] ?? '') . ' ' . ($a['status_1'] ?? '') . ' ' . ($a['status_2'] ?? '') . ' ' . ($a['status_3'] ?? '') . ' ' . ($a['status_4'] ?? '')), 'UTF-8');
                            $tokens = preg_split('/\s+/', $st);
                            if (strpos($st, 'ABSENT') !== false || strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) {
                                $att_map[$dNorm][$sid] = 'A';
                            } elseif (strpos($st, 'LEAVE') !== false || strpos($st, 'رخصت') !== false || strpos($st, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) {
                                if (($att_map[$dNorm][$sid] ?? '') !== 'A') $att_map[$dNorm][$sid] = 'L';
                            }
                        }
                    } catch (Exception $e) {}

                    try {
                        $pa_sql = "SELECT student_id, date, status FROM period_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_clause) UNION ALL SELECT student_id, date, status FROM maktab_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_clause)";
                        $pa_stmt = $pdo->query($pa_sql);
                        $pa_records = $pa_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($pa_records as $p) {
                            $dNorm = $norm_date($p['date'] ?? '');
                            if (!$dNorm || $dNorm < $start_date || $dNorm > $end_date) continue;
                            $sid = (int)$p['student_id'];
                            $s = mb_strtoupper(trim((string)($p['status'] ?? '')), 'UTF-8');
                            $tokens = preg_split('/\s+/', $s);
                            if (strpos($s, 'ABSENT') !== false || strpos($s, 'غیر حاضر') !== false || strpos($s, 'غیرحاضر') !== false || strpos($s, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) {
                                $att_map[$dNorm][$sid] = 'A';
                            } elseif (strpos($s, 'LEAVE') !== false || strpos($s, 'رخصت') !== false || strpos($s, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) {
                                if (($att_map[$dNorm][$sid] ?? '') !== 'A') $att_map[$dNorm][$sid] = 'L';
                            }
                        }
                    } catch (Exception $e) {}
                }

                // Generate dates in range (descending)
                $report_dates = [];
                $start_time = strtotime($start_date);
                $end_time = strtotime($end_date);
                for ($current_time = $end_time; $current_time >= $start_time; $current_time -= 86400) {
                    $report_dates[] = date('Y-m-d', $current_time);
                }

                $totalSabaq = 0; $totalSabqi = 0; $totalManzil = 0; $h_count = 0;
                $students_with_records = [];

                if (count($all_qaida_students) > 0) {
                    foreach ($report_dates as $date) {
                        $formatted_date = translateDateUrduFull($date);
                        echo "<tr class='table-secondary'><td colspan='15' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $day_of_week = date('N', strtotime($date));
                        $is_friday = ($day_of_week == 5);

                        $summary_missed_sabaq = 0;
                        $summary_missed_sabqi = 0;
                        $summary_missed_manzil = 0;
                        $daily_complaints = 0;
                        
                        $sr_no = 1;
                        foreach ($all_qaida_students as $student) {
                            $student_id = $student['id'];
                            $rec = $qaida_map[$date][$student_id] ?? null;
                            $attStatus = $att_map[$date][$student_id] ?? '';

                            $sAdm = !empty($student['admission_date']) ? $norm_date($student['admission_date']) : '';
                            $hasRecOnDay = !empty($rec) || !empty($attStatus);
                            if (!$hasRecOnDay && is_student_inactive($student, $date)) continue;





                            if ($is_friday) {
                                $ht = '-';
                                $badge = "<span class='text-primary fw-bold'>جمعہ</span>";
                                $s1 = $badge; $q1 = '-'; $g1 = $badge;
                                $s2 = '-'; $q2 = '-'; $g2 = '-';
                                $s3 = '-'; $q3 = '-'; $g3 = '-';
                                $s4 = '-'; $q4 = '-'; $g4 = '-';
                                $att = $badge;
                                $beh = '-';
                            } else {
                                if ($rec) {
                                    $chk_q_stat = function($st) {
                                        $st = mb_strtoupper(trim((string)$st), 'UTF-8');
                                        if (strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || strpos($st, 'ABSENT') !== false || $st === 'A') return 'A';
                                        if (strpos($st, 'رخصت') !== false || strpos($st, 'LEAVE') !== false || $st === 'L') return 'L';
                                        return '';
                                    };
                                    $qStat = $chk_q_stat($rec['attendance'] ?? '') ?: $chk_q_stat($rec['grade_1'] ?? '');
                                    if ($qStat === 'A') $attStatus = 'A';
                                    elseif ($qStat === 'L' && $attStatus !== 'A') $attStatus = 'L';
                                }
                                
                                if ($attStatus === 'A' || $attStatus === 'L') {
                                    $ht = '-';
                                    $attClass = ($attStatus === 'A') ? 'bg-danger text-white' : 'bg-warning text-dark';
                                    $attLabel = ($attStatus === 'A') ? 'غیر حاضر' : 'رخصت پر';
                                    $badge = "<span class='badge {$attClass}'>{$attLabel}</span>";
                                    $s1 = $badge; $q1 = '-'; $g1 = $badge;
                                    $s2 = '-'; $q2 = '-'; $g2 = '-';
                                    $s3 = '-'; $q3 = '-'; $g3 = '-';
                                    $s4 = '-'; $q4 = '-'; $g4 = '-';
                                    $att = $badge;
                                    $beh = '-';
                                    if ($rec) {
                                        $beh = htmlspecialchars($rec['behavior'] ?? '-');
                                    }
                                } elseif ($rec) {
                                    $students_with_records[] = $student_id;
                                    $ht = empty($rec['hearing_time']) ? '-' : date("h:i A", strtotime($rec['hearing_time']));
                                    
                                    $s1 = htmlspecialchars($rec['sabaq_1'] ?? '-');
                                    $q1 = htmlspecialchars($rec['qty_1'] ?? '-');
                                    $g1 = htmlspecialchars($rec['grade_1'] ?? '-');
                                    
                                    $s2 = htmlspecialchars($rec['sabaq_2'] ?? '-');
                                    $q2 = htmlspecialchars($rec['qty_2'] ?? '-');
                                    $g2 = htmlspecialchars($rec['grade_2'] ?? '-');
                                    
                                    $s3 = htmlspecialchars($rec['sabaq_3'] ?? '-');
                                    $q3 = htmlspecialchars($rec['qty_3'] ?? '-');
                                    $g3 = htmlspecialchars($rec['grade_3'] ?? '-');
                                    
                                    $s4 = htmlspecialchars($rec['sabaq_4'] ?? '-');
                                    $q4 = htmlspecialchars($rec['qty_4'] ?? '-');
                                    $g4 = htmlspecialchars($rec['grade_4'] ?? '-');
                                    
                                    $att = translateStatusClean($rec['attendance'] ?? 'حاضر');
                                    $beh = htmlspecialchars($rec['behavior'] ?? '-');
                                } else {
                                    $ht = '-';
                                    $s1 = "<span class='badge bg-danger'>ناغہ</span>"; $q1 = '-'; $g1 = "<span class='badge bg-danger'>ناغہ</span>";
                                    $s2 = '-'; $q2 = '-'; $g2 = '-';
                                    $s3 = '-'; $q3 = '-'; $g3 = '-';
                                    $s4 = '-'; $q4 = '-'; $g4 = '-';
                                    $att = "<span class='badge bg-success'>حاضر</span>"; $beh = '-';
                                }
                            }
                            
                            $comp_key = $student_id . '_' . $date;
                            if (isset($complaints_map[$comp_key])) {
                                $complaints_html = implode('<br><hr class="my-1 border-danger">', $complaints_map[$comp_key]);
                                $daily_complaints += count($complaints_map[$comp_key]);
                            } else {
                                $complaints_html = '-';
                            }
                            
                            echo "<tr>
                                <td>$sr_no</td>
                                <td>".htmlspecialchars(($student['roll_number'] ?: $student['admission_number']) ?? '')."</td>
                                <td class='fw-bold'>".htmlspecialchars($student['name'] ?? '')." " . (!empty($student['father_name']) ? "<br><small class='text-muted'>".htmlspecialchars($student['father_name'] ?? '')."</small>" : "") . "</td>
                                <td dir='ltr'>{$ht}</td>
                                <td>{$s1}</td><td>{$q1}</td><td>{$g1}</td>
                                <td>{$s2}</td><td>{$q2}</td><td>{$g2}</td>
                                <td>{$s3}</td><td>{$q3}</td><td>{$g3}</td>
                                <td>{$s4}</td><td>{$q4}</td><td>{$g4}</td>
                                <td class='fw-bold'>{$att}</td>
                                <td class='small text-start text-danger' style='font-size:11px;'><b>رویہ: {$beh}</b><br>{$complaints_html}</td>
                            </tr>";
                            
                            $sr_no++;
                        }
                        
                        // Daily Summary Row
                        $total_st = count($all_qaida_students);
                        $sabaq_0 = $total_st - $summary_missed_sabaq;
                        $sabqi_0 = $total_st - $summary_missed_sabqi;
                        $manzil_0 = $total_st - $summary_missed_manzil;
                        
                        $url_base = "hifz_filtered_report.php?class=".urlencode($selected_class)."&start_date=$start_date&end_date=$end_date&filter_date=$date";
                        
                        echo "<tr class='table-light border-top border-dark border-2'>
                                <td colspan='3' class='fw-bold text-end'>آج کی سمری ($formatted_date):</td>
                                <td colspan='3' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: $summary_missed_sabaq</span><br>
                                    <span class='text-success'>سنانے والے: $sabaq_0</span>
                                </td>
                                <td colspan='4' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: $summary_missed_sabqi</span><br>
                                    <span class='text-success'>سنانے والے: $sabqi_0</span>
                                </td>
                                <td colspan='4' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: $summary_missed_manzil</span><br>
                                    <span class='text-success'>سنانے والے: $manzil_0</span>
                                </td>
                                <td class='text-danger fw-bold'>شکایات: $daily_complaints</td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='14' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                }
                
                $students_with_records = array_unique($students_with_records);
                $academicScore = ($h_count > 0) ? round(($totalSabaq + $totalSabqi + $totalManzil) / $h_count) : 0;
                ?>
            </tbody>
        </table>
    </div>

    

    <?php
    $students_with_records = array_unique($students_with_records);
    $lazy_students = [];
    foreach($all_qaida_students as $hs) {
        if(!in_array($hs['id'], $students_with_records)) {
            $lazy_students[] = $hs;
        }
    }
    usort($lazy_students, function($a, $b) {
        return $a['admission_number'] <=> $b['admission_number'];
    });
    ?>
    
    <?php if(count($lazy_students) > 0): ?>
    <div class="mt-5">
        <h4 class="text-danger border-bottom border-danger pb-2 fw-bold"><i class="fas fa-exclamation-circle me-2"></i> سست طلبہ (جن کی کوئی رپورٹ درج نہیں ہوئی)</h4>
        <table class="table table-bordered border-danger text-center table-sm mt-3" style="font-size: 13px;">
            <thead class="bg-danger text-white">
                <tr>
                    <th style="width: 10%;">رول نمبر</th>
                    <th style="width: 30%;">نام <?php echo t('student'); ?></th>
                    <th style="width: 30%;">ولدیت</th>
                    <th style="width: 30%;">کیفیت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lazy_students as $ls): ?>
                <tr>
                    <td class="fw-bold bg-light h6 m-0"><?php echo $ls['admission_number']; ?></td>
                    <td class="text-start ps-3 fw-bold"><?php echo $ls['name']; ?></td>
                    <td class="text-start ps-3 text-muted"><?php echo $ls['father_name']; ?></td>
                    <td class="text-danger fw-bold">کوئی <?php echo t('sabaq'); ?> نہیں سنایا</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if($h_count > 0): ?>
    <div class="row mt-4">
        <div class="col-md-4 text-center">
            <div class="card p-3 shadow-sm border-0 bg-light">
                <h6 class="fw-bold mb-3">حفظ کی مجموعی کارکردگی</h6>
                <div class="gauge-container">
                    <svg class="gauge-svg" viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="40"></circle>
                        <circle class="gauge-fill" cx="50" cy="50" r="40" style="stroke-dasharray: <?php echo ($academicScore * 2.51); ?>, 251.2;"></circle>
                    </svg>
                    <div class="gauge-text"><?php echo $academicScore; ?>%</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
