<?php
require_once 'db.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. فلٹرز - header include سے پہلے
$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

try {
    $settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
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

try {
    // Fetch all active Hifz students for lazy student detection
    $stmt_lazy = $pdo->prepare("
        SELECT s.id, s.name, s.father_name, s.admission_number, $student_roll_select FROM students s
        WHERE (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present') 
          AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
          AND s.class = ?
        ORDER BY s.name ASC
    ");
    $stmt_lazy->execute([$selected_class]);
    $all_class_students = $stmt_lazy->fetchAll();

    if ($selected_student_id > 0) {
        $all_hifz_students = array_values(array_filter($all_class_students, function($st) use ($selected_student_id) {
            return (int)$st['id'] === $selected_student_id;
        }));
    } else {
        $all_hifz_students = $all_class_students;
    }
} catch (PDOException $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger'><strong>ڈیٹا بیس ایرر (Students):</strong> " . $e->getMessage() . "</div></div>";
    $all_class_students = [];
    $all_hifz_students = [];
}

// Helpers
function getGradePoints($grade, $max) {
    if (!$grade) return 0;
    $g = trim($grade);
    if (in_array($g, ['Excellent', 'ممتاز', 'بہترین', 'اعلیٰ'])) return $max;
    if (in_array($g, ['Good', 'بہتر', 'اچھا'])) return $max * 0.8;
    if (in_array($g, ['Average', 'معمولی', 'مناسب'])) return $max * 0.6;
    if (in_array($g, ['Weak', 'کمزور', 'توجہ طلب'])) return $max * 0.4;
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
    .table th, .table td { word-break: break-word !important; white-space: normal !important; padding: 3px 4px !important; font-size: clamp(9px, 1vw, 12px); vertical-align: middle; }
    .bg-light-purple { background-color: #f3e5f5; }
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
    
    @media print {
        @page { size: A4 landscape; margin: 4mm 5mm; }
        body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; background: #fff !important; margin: 0 !important; padding: 0 !important; }
        .d-print-none, header, footer, nav, .navbar, .sidebar { display: none !important; }
        #printableArea { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; width: 100% !important; max-width: 100% !important; }
        .table { width: 100% !important; max-width: 100% !important; table-layout: fixed !important; font-size: 8.5pt !important; }
        .table th, .table td { 
            white-space: normal !important; 
            word-break: break-word !important; 
            word-wrap: break-word !important; 
            padding: 2px 3px !important; 
            line-height: 1.15 !important;
        }
        .small { font-size: 7.5pt !important; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="fw-bold text-success mb-0"><i class="fas fa-filter me-2"></i> حفظ و ناظرہ رپورٹ فلٹرز & پرنٹ</h4>
        <a href="academic_view_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1"><i class="fas fa-user-graduate me-1 text-success"></i> طالب علم (سرچ کریں):</label>
            <select name="student_id" class="form-select form-select-sm fw-bold">
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
            <select name="period" id="hifzPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyHifzPeriodDates(this.value)">
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
            <input type="date" name="from" id="hifzFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ تک:</label>
            <input type="date" name="to" id="hifzToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-success btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> فلٹر کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-print me-1"></i> پرنٹ</button>
        </div>
    </form>
</div>

<script>
function applyHifzPeriodDates(val) {
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
        document.getElementById('hifzFromDate').value = formatDate(fromDate);
        document.getElementById('hifzToDate').value = formatDate(toDate);
    }
}
</script>

<div id="printableArea" class="bg-white p-2 p-md-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-3 pb-2">
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-quran me-2"></i> حفظ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <div class="table-responsive" style="width: 100%; max-width: 100%; border-radius: 8px;">
        <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="font-size: 12px; width: 100% !important; max-width: 100% !important; table-layout: fixed !important;">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width: 2.5%;">#</th>
                    <th rowspan="2" style="width: 4.5%;">رول نمبر</th>
                    <th rowspan="2" style="width: 11%;"><?php echo t('student'); ?></th>
                    <th colspan="3" class="bg-success text-white"><?php echo t('sabaq'); ?> (Sabaq)</th>
                    <th colspan="4" class="bg-primary text-white"><?php echo t('sabaq'); ?>ی (Sabqi)</th>
                    <th colspan="4" class="bg-warning text-dark"><?php echo t('manzil'); ?> (Manzil)</th>
                    <th rowspan="2" style="width: 22.5%;" class="bg-danger text-white">شکایات / کیفیت</th>
                </tr>
                <tr>
                    <th style="width: 3.5%;">پارہ</th><th style="width: 3.5%;">لائنیں</th><th style="width: 4.5%;">کیفیت</th>
                    <th style="width: 7%;">تفصیل</th><th style="width: 4.5%;">مقدار</th><th style="width: 4.5%;">کیفیت</th><th style="width: 6.5%;">سننے والا</th>
                    <th style="width: 10%;">تفصیل</th><th style="width: 4.5%;">مقدار</th><th style="width: 4.5%;">کیفیت</th><th style="width: 6.5%;">سننے والا</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch Hifz records
                $sql = "SELECT d.* FROM daily_hifz d 
                        JOIN students s ON d.student_id = s.id 
                        WHERE d.date BETWEEN ? AND ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$start_date, $end_date, $selected_class]);
                    $hifz_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    echo "<tr><td colspan='15'>DB Error: " . $e->getMessage() . "</td></tr>";
                    $hifz_records = [];
                }

                $hifz_map = [];
                foreach ($hifz_records as $rec) {
                    $hifz_map[$rec['date']][$rec['student_id']] = $rec;
                }


                // Fetch Attendance for all students in class to link Absent / Leave students
                $hifz_student_ids = array_map(function($st) { return (int)$st['id']; }, $all_hifz_students);
                $att_records = [];
                if (!empty($hifz_student_ids)) {
                    $in_clause = implode(',', $hifz_student_ids);
                    $att_sql = "SELECT student_id, date, status, status_1, status_2, status_3, status_4 FROM attendance WHERE student_id IN ($in_clause)";
                    try {
                        $att_stmt = $pdo->query($att_sql);
                        $att_records = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $att_records = [];
                    }
                }

                $att_map = [];
                foreach ($att_records as $a) {
                    $getS = function($k) use ($a) {
                        $s = strtoupper(trim($a[$k] ?? ''));
                        if ($s === '' || $s === '-') $s = strtoupper(trim($a['status'] ?? ''));
                        $tokens = preg_split('/\s+/', $s);
                        if (strpos($s, 'ABSENT') !== false || strpos($s, 'غیر حاضر') !== false || strpos($s, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) return 'A';
                        if (strpos($s, 'LEAVE') !== false || strpos($s, 'رخصت') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) return 'L';
                        if (strpos($s, 'P') !== false || strpos($s, 'حاضر') !== false) return 'P';
                        return $s !== '' ? 'P' : '';
                    };
                    $v1 = $getS('status_1');
                    $v2 = $getS('status_2');
                    $v3 = $getS('status_3');
                    
                    $dRaw = trim($a['date'] ?? '');
                    $dRawDateOnly = explode(' ', $dRaw)[0];
                    $sid = (int)$a['student_id'];
                    $att_map[$dRaw][$sid] = ['s1' => $v1, 's2' => $v2, 's3' => $v3];
                    $att_map[$dRawDateOnly][$sid] = ['s1' => $v1, 's2' => $v2, 's3' => $v3];

                    $dNorm = '';
                    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dRawDateOnly, $m)) {
                        $dNorm = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                    } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dRawDateOnly, $m)) {
                        $dNorm = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    }
                    if ($dNorm) {
                        $att_map[$dNorm][$sid] = ['s1' => $v1, 's2' => $v2, 's3' => $v3];
                    }
                }

                // Fetch Attendance from new period_attendance table (used by web panel)
                $pa_records = [];
                if (!empty($hifz_student_ids)) {
                    $in_clause = implode(',', $hifz_student_ids);
                    $pa_sql = "SELECT student_id, date, period_num, status FROM period_attendance WHERE student_id IN ($in_clause)";
                    try {
                        $pa_stmt = $pdo->query($pa_sql);
                        $pa_records = $pa_stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $pa_records = [];
                    }
                }
                
                foreach ($pa_records as $pa) {
                    $dRaw = trim($pa['date'] ?? '');
                    $dRawDateOnly = explode(' ', $dRaw)[0];
                    $sid = (int)$pa['student_id'];
                    $pnum = (int)$pa['period_num'];
                    $stat = strtoupper(trim($pa['status'] ?? ''));
                    $normStat = '';
                    if (strpos($stat, 'A') !== false || strpos($stat, 'غ') !== false) $normStat = 'A';
                    elseif (strpos($stat, 'L') !== false || strpos($stat, 'ر') !== false) $normStat = 'L';
                    elseif ($stat !== '') $normStat = 'P';
                    
                    if ($normStat !== '') {
                        if (!isset($att_map[$dRawDateOnly][$sid])) {
                            $att_map[$dRawDateOnly][$sid] = ['s1'=>'', 's2'=>'', 's3'=>''];
                        }
                        if ($pnum == 1) $att_map[$dRawDateOnly][$sid]['s1'] = $normStat;
                        elseif ($pnum == 2) $att_map[$dRawDateOnly][$sid]['s2'] = $normStat;
                        elseif ($pnum == 3) $att_map[$dRawDateOnly][$sid]['s3'] = $normStat;
                        
                        $dNorm = '';
                        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dRawDateOnly, $m)) {
                            $dNorm = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                        } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dRawDateOnly, $m)) {
                            $dNorm = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                        }
                        if ($dNorm && $dNorm !== $dRawDateOnly) {
                            if (!isset($att_map[$dNorm][$sid])) {
                                $att_map[$dNorm][$sid] = ['s1'=>'', 's2'=>'', 's3'=>''];
                            }
                            if ($pnum == 1) $att_map[$dNorm][$sid]['s1'] = $normStat;
                            elseif ($pnum == 2) $att_map[$dNorm][$sid]['s2'] = $normStat;
                            elseif ($pnum == 3) $att_map[$dNorm][$sid]['s3'] = $normStat;
                        }
                    }
                }

                // Fetch Complaints for the date range
                $c_sql = "SELECT c.student_id, c.date, c.complaint as description, 'admin' as type FROM complaints c WHERE c.date BETWEEN ? AND ?";
                $pc_sql = "SELECT p.student_id, p.date, CONCAT('[', p.category, '] ', p.description) as description, 'parent' as type FROM parent_complaints p WHERE p.date BETWEEN ? AND ?";
                
                $all_c_sql = "$c_sql UNION ALL $pc_sql";
                try {
                    $c_stmt = $pdo->prepare($all_c_sql);
                    $c_stmt->execute([$start_date, $end_date, $start_date, $end_date]);
                    $complaints_data = $c_stmt->fetchAll();
                } catch (PDOException $e) {
                    $c_stmt = $pdo->prepare($c_sql);
                    $c_stmt->execute([$start_date, $end_date]);
                    $complaints_data = $c_stmt->fetchAll();
                }
                
                $complaints_map = [];
                foreach($complaints_data as $cd) {
                    $key = $cd['student_id'] . '_' . $cd['date'];
                    if(!isset($complaints_map[$key])) $complaints_map[$key] = [];
                    $complaints_map[$key][] = ($cd['type'] == 'parent' ? '⚠️(والدین)' : '') . $cd['description'];
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
                
                // Weekly Frequency Trackers
                $freq_sabaq = [];
                $freq_sabqi = [];
                $freq_manzil = [];

                if (count($all_hifz_students) > 0) {
                    foreach ($report_dates as $date) {
                        $formatted_date = translateDateUrduFull($date);
                        echo "<tr class='table-secondary'><td colspan='15' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $summary_missed_sabaq = 0;
                        $summary_missed_sabqi = 0;
                        $summary_missed_manzil = 0;
                        $summary_absent_cnt = 0;
                        $summary_leave_cnt = 0;
                        // Per-period absent/leave counts
                        $summary_sabaq_absent = 0;
                        $summary_sabaq_leave = 0;
                        $summary_sabqi_absent = 0;
                        $summary_sabqi_leave = 0;
                        $summary_manzil_absent = 0;
                        $summary_manzil_leave = 0;
                        $daily_complaints = 0;
                        $day_of_week = date('N', strtotime($date));
                        $is_thursday = ($day_of_week == 4);
                        $is_friday = ($day_of_week == 5);
                        
                        $sr_no = 1;
                        foreach ($all_hifz_students as $student) {
                            $student_id = $student['id'];
                            $rec = $hifz_map[$date][$student_id] ?? null;
                            
                            $attStatus = $att_map[$date][$student_id] ?? ['s1'=>'', 's2'=>'', 's3'=>''];
                            
                            $as1 = $attStatus['s1'] ?? '';
                            $as2 = $attStatus['s2'] ?? '';
                            $as3 = $attStatus['s3'] ?? '';

                            if ($rec) {
                                if ($rec['sabaq_status'] === 'غیر حاضر' || $rec['sabaq_status'] === 'Absent') $as1 = 'A';
                                elseif ($rec['sabaq_status'] === 'رخصت' || $rec['sabaq_status'] === 'Leave') $as1 = 'L';
                                if ($rec['sabqi_status'] === 'غیر حاضر' || $rec['sabqi_status'] === 'Absent') $as2 = 'A';
                                elseif ($rec['sabqi_status'] === 'رخصت' || $rec['sabqi_status'] === 'Leave') $as2 = 'L';
                                if ($rec['manzil_status'] === 'غیر حاضر' || $rec['manzil_status'] === 'Absent') $as3 = 'A';
                                elseif ($rec['manzil_status'] === 'رخصت' || $rec['manzil_status'] === 'Leave') $as3 = 'L';
                            }

                            // Per-period absent/leave counts
                            if ($as1 === 'A') $summary_sabaq_absent++;
                            elseif ($as1 === 'L') $summary_sabaq_leave++;
                            if ($as2 === 'A') $summary_sabqi_absent++;
                            elseif ($as2 === 'L') $summary_sabqi_leave++;
                            if ($as3 === 'A') $summary_manzil_absent++;
                            elseif ($as3 === 'L') $summary_manzil_leave++;
                            // Global absent/leave: count each student once
                            if ($as1 === 'A' || $as2 === 'A' || $as3 === 'A') $summary_absent_cnt++;
                            if ($as1 === 'L' || $as2 === 'L' || $as3 === 'L') $summary_leave_cnt++;

                            $process_period = function($stat, $prefix) use (&$rec, &$summary_missed_sabaq, &$summary_missed_sabqi, &$summary_missed_manzil, &$freq_sabaq, &$freq_sabqi, &$freq_manzil, $student_id, $is_thursday, $is_friday) {
                                $p = [];
                                if ($is_friday || ($is_thursday && $prefix === 'manzil')) {
                                    $b = "<span class='text-primary fw-bold'>تعطیل</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-';
                                    return $p;
                                }
                                $p = [];
                                if ($stat === 'A') {
                                    $b = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-';
                                } elseif ($stat === 'L') {
                                    $b = "<span class='text-success fw-bold'>رخصت</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-';
                                } else {
                                    $m_p = $prefix == 'manzil' ? 'manzil_details' : ($prefix.'_parah');
                                    if ($prefix == 'manzil' && empty(trim($rec[$m_p] ?? ''))) {
                                        $m_p = 'manzil_parah';
                                    }
                                    $m_a = $prefix == 'sabaq' ? 'sabaq_lines' : ($prefix.'_amount');
                                    
                                    $missed = !$rec || empty(trim($rec[$prefix.'_status'] ?? '')) || ($rec[$prefix.'_status'] ?? '') == 'ناغہ' || ($rec[$prefix.'_status'] ?? '') == '-';
                                    $missed = $missed || empty(trim($rec[$m_p] ?? '')) || ($rec[$m_p] ?? '') == '-';
                                    
                                    if ($missed) {
                                        if ($prefix == 'sabaq') { $summary_missed_sabaq++; $freq_sabaq[$student_id] = ($freq_sabaq[$student_id] ?? 0) + 1; }
                                        if ($prefix == 'sabqi') { $summary_missed_sabqi++; $freq_sabqi[$student_id] = ($freq_sabqi[$student_id] ?? 0) + 1; }
                                        if ($prefix == 'manzil') { $summary_missed_manzil++; $freq_manzil[$student_id] = ($freq_manzil[$student_id] ?? 0) + 1; }
                                        
                                        $p['status'] = '-'; $p['parah'] = '-'; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-';
                                    } else {
                                        $p['parah'] = cleanUrduStr($rec[$m_p] ?? '');
                                        $p['amount'] = htmlspecialchars($rec[$m_a] ?? '');
                                        $p['status'] = translateStatusClean($rec[$prefix.'_status'] ?? '');
                                        $p['time'] = !empty($rec[$prefix.'_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($rec[$prefix.'_time']))."</small>" : "";
                                        $p['listener'] = htmlspecialchars($rec[$prefix.'_listener'] ?? '');
                                    }
                                }
                                return $p;
                            };

                            $p_sabaq = $process_period($as1, 'sabaq');
                            $p_sabqi = $process_period($as2, 'sabqi');
                            $p_manzil = $process_period($as3, 'manzil');

                            $sabaq_parah = $p_sabaq['parah']; $sabaq_lines = $p_sabaq['amount']; $sabaq_status = $p_sabaq['status']; $sabaq_time = $p_sabaq['time'];
                            $sabqi_parah = $p_sabqi['parah']; $sabqi_amount = $p_sabqi['amount']; $sabqi_status = $p_sabqi['status']; $sabqi_time = $p_sabqi['time']; $sabqi_listener = $p_sabqi['listener'];
                            $manzil_parah = $p_manzil['parah']; $manzil_amount = $p_manzil['amount']; $manzil_status = $p_manzil['status']; $manzil_time = $p_manzil['time']; $manzil_listener = $p_manzil['listener'];

                            if ($rec) {
                                $students_with_records[] = $student_id;
                                $totalSabaq += getGradePoints($rec['sabaq_status'], 30);
                                $totalSabqi += getGradePoints($rec['sabqi_status'], 30);
                                $totalManzil += getGradePoints($rec['manzil_status'], 40);
                                $h_count++;
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
                                <td>{$sabaq_parah}</td><td>{$sabaq_lines}</td><td>{$sabaq_status}{$sabaq_time}</td>
                                <td style='font-size:11px;'>{$sabqi_parah}</td><td>{$sabqi_amount}</td><td>{$sabqi_status}{$sabqi_time}</td><td class='small text-muted'>{$sabqi_listener}</td>
                                <td class='text-start ps-1' style='font-size:11px;'>{$manzil_parah}</td><td>{$manzil_amount}</td><td>{$manzil_status}{$manzil_time}</td><td class='small text-muted'>{$manzil_listener}</td>
                                <td class='small text-start text-danger' style='font-size:11px;'>{$complaints_html}</td>
                            </tr>";
                            
                            $sr_no++;
                        }
                        
                        // Daily Summary Row
                        $total_st = count($all_hifz_students);
                        $sabaq_0 = $total_st - $summary_missed_sabaq;
                        $sabqi_0 = $total_st - $summary_missed_sabqi;
                        $manzil_0 = $total_st - $summary_missed_manzil;
                        
                        $url_base = "hifz_filtered_report.php?class=".urlencode($selected_class)."&start_date=$start_date&end_date=$end_date&filter_date=$date";
                        
                        // Daily Summary Row - redesigned to show sabaq/sabqi/manzil separately with per-period absent/leave
                        $sabaq_sender = $total_st - $summary_missed_sabaq - $summary_sabaq_absent - $summary_sabaq_leave;
                        if ($sabaq_sender < 0) $sabaq_sender = 0;
                        $sabqi_sender = $total_st - $summary_missed_sabqi - $summary_sabqi_absent - $summary_sabqi_leave;
                        if ($sabqi_sender < 0) $sabqi_sender = 0;
                        $manzil_sender = $total_st - $summary_missed_manzil - $summary_manzil_absent - $summary_manzil_leave;
                        if ($manzil_sender < 0) $manzil_sender = 0;

                        echo "<tr class='border-top border-dark border-2' style='background:#f0f4f8;'>
                            <td colspan='15' class='p-2'>
                              <div class='d-flex justify-content-between align-items-center mb-1' dir='rtl'>
                                <div class='d-flex gap-3'>
                                  <a href='{$url_base}&lesson_type=absent' target='_blank' class='text-danger fw-bold text-decoration-none' style='font-size:11px;'>غیر حاضر: <u>{$summary_absent_cnt}</u></a>
                                  <a href='{$url_base}&lesson_type=leave' target='_blank' class='text-warning fw-bold text-decoration-none' style='font-size:11px;'>رخصت: <u>{$summary_leave_cnt}</u></a>
                                  <span class='fw-bold text-danger' style='font-size:11px;'>شکایات: $daily_complaints</span>
                                </div>
                                <span class='fw-bold text-dark' style='font-size:12px;'>روزانہ سمری ({$formatted_date})</span>
                              </div>
                              <div class='row g-1' dir='rtl'>
                                <div class='col-4'>
                                  <div class='border border-success rounded p-2 text-center' style='font-size:11px; background:rgba(25,135,84,0.05)'>
                                    <div class='fw-bold text-success mb-1'>سبق</div>
                                    <a href='{$url_base}&lesson_type=sabaq&miss_count=0' target='_blank' class='text-success fw-bold text-decoration-none d-block'>سنانے والے: <u>$sabaq_sender</u></a>
                                    <a href='{$url_base}&lesson_type=sabaq&miss_count=any' target='_blank' class='text-danger fw-bold text-decoration-none d-block'>ناغہ: <u>$summary_missed_sabaq</u></a>
                                    <a href='{$url_base}&lesson_type=sabaq_absent' target='_blank' class='text-danger fw-bold text-decoration-none d-block' style='opacity:0.8'>غیر حاضر: <u>$summary_sabaq_absent</u></a>
                                    <a href='{$url_base}&lesson_type=sabaq_leave' target='_blank' class='text-warning fw-bold text-decoration-none d-block'>رخصت: <u>$summary_sabaq_leave</u></a>
                                  </div>
                                </div>
                                <div class='col-4'>
                                  <div class='border border-primary rounded p-2 text-center' style='font-size:11px; background:rgba(13,110,253,0.05)'>
                                    <div class='fw-bold text-primary mb-1'>سبقی</div>
                                    <a href='{$url_base}&lesson_type=sabqi&miss_count=0' target='_blank' class='text-success fw-bold text-decoration-none d-block'>سنانے والے: <u>$sabqi_sender</u></a>
                                    <a href='{$url_base}&lesson_type=sabqi&miss_count=any' target='_blank' class='text-danger fw-bold text-decoration-none d-block'>ناغہ: <u>$summary_missed_sabqi</u></a>
                                    <a href='{$url_base}&lesson_type=sabqi_absent' target='_blank' class='text-danger fw-bold text-decoration-none d-block' style='opacity:0.8'>غیر حاضر: <u>$summary_sabqi_absent</u></a>
                                    <a href='{$url_base}&lesson_type=sabqi_leave' target='_blank' class='text-warning fw-bold text-decoration-none d-block'>رخصت: <u>$summary_sabqi_leave</u></a>
                                  </div>
                                </div>
                                <div class='col-4'>
                                  <div class='border border-warning rounded p-2 text-center' style='font-size:11px; background:rgba(255,193,7,0.05)'>
                                    <div class='fw-bold text-warning mb-1'>منزل</div>
                                    <a href='{$url_base}&lesson_type=manzil&miss_count=0' target='_blank' class='text-success fw-bold text-decoration-none d-block'>سنانے والے: <u>$manzil_sender</u></a>
                                    <a href='{$url_base}&lesson_type=manzil&miss_count=any' target='_blank' class='text-danger fw-bold text-decoration-none d-block'>ناغہ: <u>$summary_missed_manzil</u></a>
                                    <a href='{$url_base}&lesson_type=manzil_absent' target='_blank' class='text-danger fw-bold text-decoration-none d-block' style='opacity:0.8'>غیر حاضر: <u>$summary_manzil_absent</u></a>
                                    <a href='{$url_base}&lesson_type=manzil_leave' target='_blank' class='text-warning fw-bold text-decoration-none d-block'>رخصت: <u>$summary_manzil_leave</u></a>
                                  </div>
                                </div>
                              </div>
                            </td>
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

    <?php if (count($report_dates) > 1): 
        $sabaq_1 = 0; $sabaq_2 = 0; $sabaq_3 = 0; $sabaq_more = 0;
        $sabqi_1 = 0; $sabqi_2 = 0; $sabqi_3 = 0; $sabqi_more = 0;
        $manzil_1 = 0; $manzil_2 = 0; $manzil_3 = 0; $manzil_more = 0;
        
        foreach($all_hifz_students as $s) {
            $sid = $s['id'];
            $sq = $freq_sabaq[$sid] ?? 0;
            if($sq == 1) $sabaq_1++; elseif($sq == 2) $sabaq_2++; elseif($sq == 3) $sabaq_3++; elseif($sq > 3) $sabaq_more++;
            
            $sqi = $freq_sabqi[$sid] ?? 0;
            if($sqi == 1) $sabqi_1++; elseif($sqi == 2) $sabqi_2++; elseif($sqi == 3) $sabqi_3++; elseif($sqi > 3) $sabqi_more++;
            
            $mz = $freq_manzil[$sid] ?? 0;
            if($mz == 1) $manzil_1++; elseif($mz == 2) $manzil_2++; elseif($mz == 3) $manzil_3++; elseif($mz > 3) $manzil_more++;
        }
    ?>
    <div class="card shadow-sm mb-4 border-top border-5 border-info page-break">
        <div class="card-body">
            <h5 class="fw-bold mb-3 text-info"><i class="fas fa-chart-bar me-2"></i> مجموعی ناغہ جات سمری (Period Missed Lessons Summary)</h5>
            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 14px;">
                <thead class="bg-light">
                    <tr>
                        <th>کیٹیگری</th>
                        <th class="text-success">سبق کا ناغہ</th>
                        <th class="text-primary">سبقی کا ناغہ</th>
                        <th class="text-warning text-dark">منزل کا ناغہ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold">صرف 1 ناغہ</td>
                        <td class="fw-bold text-danger"><?php echo $sabaq_1; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $sabqi_1; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $manzil_1; ?> طلبہ</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">2 ناغے</td>
                        <td class="fw-bold text-danger"><?php echo $sabaq_2; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $sabqi_2; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $manzil_2; ?> طلبہ</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">3 ناغے</td>
                        <td class="fw-bold text-danger"><?php echo $sabaq_3; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $sabqi_3; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $manzil_3; ?> طلبہ</td>
                    </tr>
                    <tr class="table-danger">
                        <td class="fw-bold text-danger">3 سے زیادہ ناغے</td>
                        <td class="fw-bold text-danger"><?php echo $sabaq_more; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $sabqi_more; ?> طلبہ</td>
                        <td class="fw-bold text-danger"><?php echo $manzil_more; ?> طلبہ</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $students_with_records = array_unique($students_with_records);
    $lazy_students = [];
    foreach($all_hifz_students as $hs) {
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
