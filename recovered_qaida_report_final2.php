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

$period = $_GET['period'] ?? 'weekly';
if (isset($_GET['from']) && trim($_GET['from']) !== '') {
    $start_date = trim($_GET['from']);
    $end_date = isset($_GET['to']) && trim($_GET['to']) !== '' ? trim($_GET['to']) : $start_date;
} else {
    $now = new DateTime();
    if ($period === 'current_week') {
        $current_day_num = (int)$now->format('w');
        $ws_day = $week_start_day ?? 6;
        $days_since_start = ($current_day_num - $ws_day + 7) % 7;
        $start = clone $now;
        $start->modify("-$days_since_start days");
        $start_date = $start->format('Y-m-d');
        $end_date = $now->format('Y-m-d');
    } else {
        $start = clone $now;
        $start->modify("-6 days");
        $start_date = $start->format('Y-m-d');
        $end_date = $now->format('Y-m-d');
    }
}
$start_date = normalizeDateToYMD($start_date);
$end_date = normalizeDateToYMD($end_date);

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
    // Fetch all active Qaida students
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
        $all_qaida_students = array_values(array_filter($all_class_students, function($st) use ($selected_student_id) {
            return (int)$st['id'] === $selected_student_id;
        }));
    } else {
        $all_qaida_students = $all_class_students;
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

function normalizeDateToYMD($rawDate) {
    if (empty($rawDate)) return '';
    $rawDate = trim($rawDate);
    // Strip time if T or space exists
    $rawDate = preg_replace('/[T ].*$/', '', $rawDate);
    
    // YYYY-MM-DD or YYYY/MM/DD
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $rawDate, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $rawDate, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($rawDate);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return $rawDate;
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
            <select name="period" id="qaidaPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyQaidaPeriodDates(this.value)">
                <option value="current_week" <?php echo $period==='current_week'?'selected':''; ?>>رواں ہفتہ</option>
                <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (آخری 7 دن - ڈیفالٹ)</option>
                <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (آخری 30 دن)</option>
                <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (آخری 90 دن)</option>
                <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (آخری 180 دن)</option>
                <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (پورا سال)</option>
                <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ سے:</label>
            <input type="date" name="from" id="qaidaFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ تک:</label>
            <input type="date" name="to" id="qaidaToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" required>
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
    }
}
</script>

<div id="printableArea" class="bg-white p-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-quran me-2"></i> قاعدہ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <div class="table-responsive" style="width: 100%; max-width: 100%; border-radius: 8px;">
        <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="font-size: 12px; min-width: 800px;">
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
                $all_student_ids = !empty($all_qaida_students) ? array_column($all_qaida_students, 'id') : [];
                $hifz_records = [];
                try {
                    $stmt = $pdo->prepare("SELECT d.* FROM qaida_records d 
                                           JOIN students s ON d.student_id = s.id 
                                           WHERE d.is_deleted = 0 AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?");
                    $stmt->execute([$selected_class]);
                    $hifz_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    echo "<tr><td colspan='15'>DB Error: " . $e->getMessage() . "</td></tr>";
                }

                $qaida_map = [];
                foreach ($hifz_records as $rec) {
                    $normD = normalizeDateToYMD($rec['date'] ?? '');
                    if ($normD && $normD >= $start_date && $normD <= $end_date) {
                        $qaida_map[$normD][$rec['student_id']] = $rec;
                    }
                }
                
                // Fetch Attendance for students
                $att_map = [];
                if (!empty($all_student_ids)) {
                    $in_clause = implode(',', array_map('intval', $all_student_ids));
                    $att_sql = "SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance WHERE student_id IN ($in_clause)";
                    try {
                        $att_stmt = $pdo->query($att_sql);
                        foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                            $normDate = normalizeDateToYMD($a['date'] ?? '');
                            if (!$normDate) continue;

                            $resolve = function($s) use ($a) {
                                $s = strtoupper(trim($s ?? ''));
                                if ($s === '' || $s === '-') $s = strtoupper(trim($a['status'] ?? ''));
                                if ($s === 'A' || $s === 'غ' || mb_strpos($s, 'ABSENT') !== false || mb_strpos($s, 'غیر حاضر') !== false) return 'A';
                                if ($s === 'L' || $s === 'ر' || mb_strpos($s, 'LEAVE') !== false || mb_strpos($s, 'رخصت') !== false) return 'L';
                                if ($s === 'P' || $s === 'ح' || mb_strpos($s, 'PRESENT') !== false || mb_strpos($s, 'حاضر') !== false) return 'P';
                                return null;
                            };
                            
                            $sid = (int)$a['student_id'];
                            $genStat = $resolve($a['status'] ?? '');
                            $s1 = !empty($a['status_1']) ? $resolve($a['status_1']) : null;
                            $s2 = !empty($a['status_2']) ? $resolve($a['status_2']) : null;
                            $s3 = !empty($a['status_3']) ? $resolve($a['status_3']) : null;
                            
                            // Inherit absence forward across shifts if unrecorded
                            if (!$s1 && !$s2 && !$s3) {
                                $s1 = $genStat; $s2 = $genStat; $s3 = $genStat;
                            } else {
                                if ($s1 === 'A' || $s1 === 'L') {
                                    if (!$s2) $s2 = $s1;
                                    if (!$s3) $s3 = $s1;
                                }
                                if ($s2 === 'A' || $s2 === 'L') {
                                    if (!$s3) $s3 = $s2;
                                }
                            }
                            if (!$s1) $s1 = 'P';
                            if (!$s2) $s2 = 'P';
                            if (!$s3) $s3 = 'P';
                            if (!$genStat) $genStat = 'P';

                            $att_map[$normDate][$sid] = [
                                'gen' => $genStat,
                                's1' => $s1,
                                's2' => $s2,
                                's3' => $s3
                            ];
                        }
                    } catch (PDOException $e) {}

                    $pa_sql = "SELECT student_id, date, period_num, status FROM period_attendance WHERE student_id IN ($in_clause) UNION ALL SELECT student_id, date, period_num, status FROM maktab_attendance WHERE student_id IN ($in_clause)";
                    try {
                        $pa_stmt = $pdo->query($pa_sql);
                        foreach ($pa_stmt->fetchAll(PDO::FETCH_ASSOC) as $pa) {
                            $normDate = normalizeDateToYMD($pa['date'] ?? '');
                            if (!$normDate) continue;
                            
                            $sid = (int)$pa['student_id'];
                            $pnum_raw = trim($pa['period_num'] ?? '');
                            $pnum_str = strtolower($pnum_raw);
                            $stat = strtoupper(trim($pa['status'] ?? ''));
                            
                            $normStat = '';
                            if ($stat === 'A' || $stat === 'غ' || mb_strpos($stat, 'ABSENT') !== false || mb_strpos($stat, 'غیر حاضر') !== false) $normStat = 'A';
                            elseif ($stat === 'L' || $stat === 'ر' || mb_strpos($stat, 'LEAVE') !== false || mb_strpos($stat, 'رخصت') !== false) $normStat = 'L';
                            elseif ($stat !== '') $normStat = 'P';
                            
                            if ($normStat !== '') {
                                if (!isset($att_map[$normDate][$sid])) {
                                    $att_map[$normDate][$sid] = ['gen'=>null, 's1'=>null, 's2'=>null, 's3'=>null];
                                }
                                if ($pnum_raw === '1' || $pnum_str === 'sabaq' || $pnum_str === 'morning' || mb_strpos($pnum_str, 'سبق') !== false || mb_strpos($pnum_str, 'صبح') !== false || $pnum_raw === '۱') {
                                    $att_map[$normDate][$sid]['s1'] = $normStat;
                                } elseif ($pnum_raw === '2' || $pnum_str === 'sabqi' || $pnum_str === 'noon' || mb_strpos($pnum_str, 'سبقی') !== false || mb_strpos($pnum_str, 'دوپہر') !== false || $pnum_raw === '۲') {
                                    $att_map[$normDate][$sid]['s2'] = $normStat;
                                } elseif ($pnum_raw === '3' || $pnum_str === 'manzil' || $pnum_str === 'evening' || mb_strpos($pnum_str, 'منزل') !== false || mb_strpos($pnum_str, 'عصر') !== false || mb_strpos($pnum_str, 'شام') !== false || $pnum_raw === '۳') {
                                    $att_map[$normDate][$sid]['s3'] = $normStat;
                                }
                            }
                        }
                    } catch (PDOException $e) {}
                    
                    // Apply cascading inheritance and default to 'P'
                    foreach ($att_map as $d => $students) {
                        foreach ($students as $sid => $st) {
                            $s1 = $st['s1']; $s2 = $st['s2']; $s3 = $st['s3']; $gen = $st['gen'];
                            if (!$s1 && !$s2 && !$s3) {
                                $s1 = $gen; $s2 = $gen; $s3 = $gen;
                            } else {
                                if ($s1 === 'A' || $s1 === 'L') {
                                    if (!$s2) $s2 = $s1;
                                    if (!$s3) $s3 = $s1;
                                }
                                if ($s2 === 'A' || $s2 === 'L') {
                                    if (!$s3) $s3 = $s2;
                                }
                            }
                            $att_map[$d][$sid]['s1'] = $s1 ?: 'P';
                            $att_map[$d][$sid]['s2'] = $s2 ?: 'P';
                            $att_map[$d][$sid]['s3'] = $s3 ?: 'P';
                            $att_map[$d][$sid]['gen'] = $gen ?: 'P';
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

                // Generate dates in range (descending) using DatePeriod
                $report_dates = [];
                try {
                    $start_dt = new DateTime($start_date);
                    $end_dt = new DateTime($end_date);
                    $end_dt->modify('+1 day');
                    $period_range = new DatePeriod($start_dt, new DateInterval('P1D'), $end_dt);
                    foreach ($period_range as $dt) {
                        $report_dates[] = $dt->format('Y-m-d');
                    }
                    $report_dates = array_reverse($report_dates);
                } catch (Exception $e) {
                    $report_dates = [$start_date];
                }

                $totalSabaq = 0; $totalSabqi = 0; $totalManzil = 0; $h_count = 0;
                $students_with_records = [];
                
                // Weekly Frequency Trackers
                

                if (count($all_qaida_students) > 0) {
                    foreach ($report_dates as $date) {
                        $formatted_date = translateDateUrduFull($date);
                        echo "<tr class='table-secondary'><td colspan='15' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $summary_missed_sabaq = 0;
                        $summary_missed_sabqi = 0;
                        $summary_missed_manzil = 0;
                        $summary_absent_cnt = 0;
                        $summary_leave_cnt = 0;
                        
                        $summary_s1_absent = 0; $summary_s1_leave = 0;
                        $summary_s2_absent = 0; $summary_s2_leave = 0;
                        $summary_s3_absent = 0; $summary_s3_leave = 0;
                        
                        $daily_complaints = 0;
                        
                        $sr_no = 1;
                        foreach ($all_qaida_students as $student) {
                            $student_id = $student['id'];
                            $rec = $qaida_map[$date][$student_id] ?? null;
                            
                            $attStatus = $att_map[$date][$student_id] ?? ['s1'=>'P', 's2'=>'P', 's3'=>'P'];
                            $as1 = $attStatus['s1'] ?? 'P';
                            $as2 = $attStatus['s2'] ?? 'P';
                            $as3 = $attStatus['s3'] ?? 'P';
                            
                            // If record exists and marks specific absence, override
                            if ($rec) {
                                if (in_array($rec['attendance'] ?? '', ['غیر حاضر', 'Absent'])) { $as1='A'; $as2='A'; $as3='A'; }
                                elseif (in_array($rec['attendance'] ?? '', ['رخصت', 'Leave'])) { $as1='L'; $as2='L'; $as3='L'; }
                            }
                            
                            if ($as1 === 'A') $summary_s1_absent++; elseif ($as1 === 'L') $summary_s1_leave++;
                            if ($as2 === 'A') $summary_s2_absent++; elseif ($as2 === 'L') $summary_s2_leave++;
                            if ($as3 === 'A') $summary_s3_absent++; elseif ($as3 === 'L') $summary_s3_leave++;
                            
                            if ($as1 === 'A' || $as2 === 'A' || $as3 === 'A') $summary_absent_cnt++;
                            elseif ($as1 === 'L' || $as2 === 'L' || $as3 === 'L') $summary_leave_cnt++;
                            
                            if ($rec) {
                                $students_with_records[] = $student_id;
                                $ht = empty($rec['hearing_time']) ? '-' : date("h:i A", strtotime($rec['hearing_time']));
                                
                                $renderGrade = function($val, $stat, &$missed_counter) {
                                    if ($val && $val !== '-') return $val;
                                    if ($stat === 'A') return 'غیر حاضر';
                                    if ($stat === 'L') return 'رخصت';
                                    $missed_counter++;
                                    return '-';
                                };

                                $s1 = htmlspecialchars($rec['sabaq_1'] ?? '-');
                                $q1 = htmlspecialchars($rec['qty_1'] ?? '-');
                                $g1 = $renderGrade($rec['grade_1'] ?? '-', $as1, $summary_missed_sabaq);
                                if ($g1 === '-' && $s1 !== '-' && $s1 !== '') $summary_missed_sabaq--; // Had lesson but no grade? (Rare)
                                else if ($g1 !== '-' && $g1 !== 'غیر حاضر' && $g1 !== 'رخصت' && ($s1 === '-' || $s1 === '')) $summary_missed_sabaq++; // No lesson entered
                                
                                $s2 = htmlspecialchars($rec['sabaq_2'] ?? '-');
                                $q2 = htmlspecialchars($rec['qty_2'] ?? '-');
                                $g2 = $renderGrade($rec['grade_2'] ?? '-', $as2, $summary_missed_sabqi);
                                if ($g2 !== '-' && $g2 !== 'غیر حاضر' && $g2 !== 'رخصت' && ($s2 === '-' || $s2 === '')) $summary_missed_sabqi++;
                                
                                $s3 = htmlspecialchars($rec['sabaq_3'] ?? '-');
                                $q3 = htmlspecialchars($rec['qty_3'] ?? '-');
                                $g3 = $renderGrade($rec['grade_3'] ?? '-', $as3, $summary_missed_manzil);
                                if ($g3 !== '-' && $g3 !== 'غیر حاضر' && $g3 !== 'رخصت' && ($s3 === '-' || $s3 === '')) $summary_missed_manzil++;
                                
                                $s4 = htmlspecialchars($rec['sabaq_4'] ?? '-');
                                $q4 = htmlspecialchars($rec['qty_4'] ?? '-');
                                $g4 = $renderGrade($rec['grade_4'] ?? '-', $as3, $dummy); // 4th lesson maps to period 3
                                
                                $att = translateStatusClean($rec['attendance'] ?? '-');
                                $beh = htmlspecialchars($rec['behavior'] ?? '-');
                            } else {
                                $ht = '-';
                                $renderMissing = function($stat, &$missed_counter) {
                                    if ($stat === 'A') return 'غیر حاضر';
                                    if ($stat === 'L') return 'رخصت';
                                    $missed_counter++;
                                    return '-';
                                };
                                $s1 = '-'; $q1 = '-'; $g1 = $renderMissing($as1, $summary_missed_sabaq);
                                $s2 = '-'; $q2 = '-'; $g2 = $renderMissing($as2, $summary_missed_sabqi);
                                $s3 = '-'; $q3 = '-'; $g3 = $renderMissing($as3, $summary_missed_manzil);
                                $s4 = '-'; $q4 = '-'; $g4 = $renderMissing($as3, $dummy);
                                
                                if ($as1 === 'A' || $as2 === 'A' || $as3 === 'A') $att = 'غیر حاضر';
                                elseif ($as1 === 'L' || $as2 === 'L' || $as3 === 'L') $att = 'رخصت';
                                else $att = 'حاضر';
                                $beh = '-';
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
                        $sabaq_0 = max(0, $total_st - $summary_missed_sabaq - $summary_s1_absent - $summary_s1_leave);
                        $sabqi_0 = max(0, $total_st - $summary_missed_sabqi - $summary_s2_absent - $summary_s2_leave);
                        $manzil_0 = max(0, $total_st - $summary_missed_manzil - $summary_s3_absent - $summary_s3_leave);
                        
                        $url_base = "hifz_filtered_report.php?class=".urlencode($selected_class)."&start_date=$start_date&end_date=$end_date&filter_date=$date";
                        
                        echo "<tr class='table-light border-top border-dark border-2'>
                                <td colspan='4' class='fw-bold text-end' style='font-size:12px;'>
                                    آج کی سمری ($formatted_date):<br>
                                    <span class='text-danger'>کل غیر حاضر: $summary_absent_cnt</span> | 
                                    <span class='text-warning text-dark'>رخصت: $summary_leave_cnt</span>
                                </td>
                                <td colspan='3' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: <a href='$url_base&lesson_type=sabaq&miss_count=any' target='_blank'>$summary_missed_sabaq</a></span><br>
                                    <span class='text-danger opacity-75'>غیر حاضر/رخصت: " . ($summary_s1_absent + $summary_s1_leave) . "</span><br>
                                    <span class='text-success'>سنانے والے: <a href='$url_base&lesson_type=sabaq&miss_count=0' target='_blank'>$sabaq_0</a></span>
                                </td>
                                <td colspan='3' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: <a href='$url_base&lesson_type=sabqi&miss_count=any' target='_blank'>$summary_missed_sabqi</a></span><br>
                                    <span class='text-danger opacity-75'>غیر حاضر/رخصت: " . ($summary_s2_absent + $summary_s2_leave) . "</span><br>
                                    <span class='text-success'>سنانے والے: <a href='$url_base&lesson_type=sabqi&miss_count=0' target='_blank'>$sabqi_0</a></span>
                                </td>
                                <td colspan='6' class='fw-bold' style='font-size:11px;'>
                                    <span class='text-danger'>ناغہ: <a href='$url_base&lesson_type=manzil&miss_count=any' target='_blank'>$summary_missed_manzil</a></span><br>
                                    <span class='text-danger opacity-75'>غیر حاضر/رخصت: " . ($summary_s3_absent + $summary_s3_leave) . "</span><br>
                                    <span class='text-success'>سنانے والے: <a href='$url_base&lesson_type=manzil&miss_count=0' target='_blank'>$manzil_0</a></span>
                                </td>
                                <td colspan='2' class='text-danger fw-bold'>شکایات: $daily_complaints</td>
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
<!-- Drill-down Student List Modal -->
<div class="modal fade" id="drillDownStudentModal" tabindex="-1" aria-labelledby="drillDownModalLabel" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-success text-white py-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-users-cog fs-5"></i>
                    <h5 class="modal-title fw-bold fs-6 mb-0" id="drillDownModalLabel">تفصیلات طلبہ</h5>
                    <span class="badge bg-light text-dark rounded-pill px-2" id="drillDownCount">0</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="drillDownSearchInput" class="form-control" placeholder="نام یا رول نمبر سے تلاش کریں..." onkeyup="filterDrillDownTable()">
                    </div>
                    <button class="btn btn-sm btn-outline-secondary text-nowrap" onclick="printDrillDownTable()"><i class="fas fa-print me-1"></i> پرنٹ</button>
                </div>
                <div class="table-responsive bg-white rounded shadow-sm" style="max-height: 400px;">
                    <table class="table table-hover table-bordered table-sm text-center mb-0 align-middle" id="drillDownTable" style="font-size: 13px;">
                        <thead class="table-secondary sticky-top">
                            <tr>
                                <th style="width: 8%;">#</th>
                                <th style="width: 15%;">رول نمبر</th>
                                <th style="width: 32%;">طالب علم کا نام</th>
                                <th style="width: 25%;">ولدیت</th>
                                <th style="width: 20%;">کیفیت / تفصیل</th>
                            </tr>
                        </thead>
                        <tbody id="drillDownTableBody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 bg-white d-flex justify-content-between">
                <small class="text-muted" id="drillDownCategoryNote"></small>
                <button type="button" class="btn btn-secondary btn-sm fw-bold px-3" data-bs-dismiss="modal">بند کریں</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentDrillStudents = [];
function openStudentDrillModal(title, categoryName, students) {
    currentDrillStudents = Array.isArray(students) ? students : [];
    document.getElementById('drillDownModalLabel').innerText = title;
    document.getElementById('drillDownCount').innerText = currentDrillStudents.length + ' طلبہ';
    document.getElementById('drillDownCategoryNote').innerText = 'کیٹیگری: ' + categoryName;
    document.getElementById('drillDownSearchInput').value = '';
    
    renderDrillDownRows(currentDrillStudents);
    
    const modalEl = document.getElementById('drillDownStudentModal');
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
}

function renderDrillDownRows(list) {
    const tbody = document.getElementById('drillDownTableBody');
    if (!list || list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-3 text-muted fw-bold">اس کیٹیگری میں کوئی طالب علم موجود نہیں ہے۔</td></tr>`;
        return;
    }
    let html = '';
    list.forEach((st, idx) => {
        const roll = st.roll || '-';
        const name = st.name || '-';
        const fname = st.father_name || '-';
        const status = st.status || '-';
        
        let statusBadge = status;
        if (status.includes('غیر حاضر')) {
            statusBadge = `<span class="badge bg-danger">${status}</span>`;
        } else if (status.includes('رخصت')) {
            statusBadge = `<span class="badge bg-warning text-dark">${status}</span>`;
        } else if (status.includes('ناغہ')) {
            statusBadge = `<span class="badge bg-secondary">${status}</span>`;
        } else {
            statusBadge = `<span class="text-success fw-bold">${status}</span>`;
        }
        
        html += `<tr>
            <td class="text-muted fw-bold">${idx + 1}</td>
            <td class="fw-bold">${roll}</td>
            <td class="text-start ps-3 fw-bold text-dark">${name}</td>
            <td class="text-start ps-3 text-muted">${fname}</td>
            <td>${statusBadge}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function filterDrillDownTable() {
    const q = (document.getElementById('drillDownSearchInput').value || '').trim().toLowerCase();
    if (!q) {
        renderDrillDownRows(currentDrillStudents);
        return;
    }
    const filtered = currentDrillStudents.filter(st => {
        return (st.name && st.name.toLowerCase().includes(q)) ||
               (st.roll && String(st.roll).toLowerCase().includes(q)) ||
               (st.father_name && st.father_name.toLowerCase().includes(q)) ||
               (st.status && st.status.toLowerCase().includes(q));
    });
    renderDrillDownRows(filtered);
}

function printDrillDownTable() {
    const title = document.getElementById('drillDownModalLabel').innerText;
    const count = document.getElementById('drillDownCount').innerText;
    const content = document.getElementById('drillDownTable').outerHTML;
    const printWin = window.open('', '', 'height=650,width=900');
    printWin.document.write('<html dir="rtl"><head><title>' + title + '</title>');
    printWin.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">');
    printWin.document.write('<style>body{font-family:Tahoma,Arial,sans-serif;padding:20px;} table{width:100%;font-size:12px;}</style></head><body>');
    printWin.document.write('<h4 class="text-center fw-bold mb-1">' + title + '</h4>');
    printWin.document.write('<p class="text-center text-muted mb-3">تعداد: ' + count + '</p>');
    printWin.document.write(content);
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    setTimeout(() => { printWin.print(); printWin.close(); }, 400);
}
</script>
</div>

<?php include 'footer.php'; ?>
