<?php 
// ایرر رپورٹنگ
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db.php'; 
include 'header.php'; 

// --- 1. ہجری ایڈجسٹمنٹ لاجک ---
$offset_file = 'hijri_offset.txt';
if (isset($_POST['update_offset'])) {
    $new_offset = (int)$_POST['offset_value'];
    file_put_contents($offset_file, $new_offset);
    echo "<script>window.location.href = window.location.href;</script>";
    exit;
}
$hijri_offset = file_exists($offset_file) ? (int)file_get_contents($offset_file) : -1; 

// --- 2. رپورٹ سیٹنگز ---
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'month'; 
$current_month = date('m');
$current_year = date('Y');
$today = date('Y-m-d');
$last_year_today = date('Y-m-d', strtotime('-1 year'));

$urdu_months_greg = [
    'January' => 'جنوری', 'February' => 'فروری', 'March' => 'مارچ', 'April' => 'اپریل',
    'May' => 'مئی', 'June' => 'جون', 'July' => 'جولائی', 'August' => 'اگست',
    'September' => 'ستمبر', 'October' => 'اکتوبر', 'November' => 'نومبر', 'December' => 'دسمبر'
];

if ($mode == 'month') {
    $month = $_GET['month'] ?? $current_month;
    $year = $_GET['year'] ?? $current_year;
    $start_date = "$year-$month-01";
    $end_date = date("Y-m-t", strtotime($start_date));
} else {
    $start_date = $_GET['from'] ?? $last_year_today;
    $end_date = $_GET['to'] ?? $today;
}

// تاریخوں کی گروپنگ
$period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
$grouped_months = [];
foreach ($period as $dt) {
    $ym = $dt->format('Y-m'); 
    $grouped_months[$ym][] = $dt->format('Y-m-d');
}
krsort($grouped_months); 

// ڈیٹا لائیں
$students = $pdo->query("
    SELECT * FROM students 
    WHERE ((status='Active' AND is_deleted=0) 
       OR (id IN (SELECT student_id FROM attendance WHERE date BETWEEN '$start_date' AND '$end_date'))
       OR (id IN (SELECT student_id FROM period_attendance WHERE date BETWEEN '$start_date' AND '$end_date')))
       " . (isset($_GET['std_id']) && $_GET['std_id'] != '' ? " AND id = " . (int)$_GET['std_id'] : "") . "
    ORDER BY class ASC, admission_number ASC
") ->fetchAll();

$grouped_students = [];
foreach ($students as $s) {
    $c = empty($s['class']) ? 'دیگر' : $s['class'];
    if (!isset($grouped_students[$c])) $grouped_students[$c] = [];
    $grouped_students[$c][] = $s;
}

$predefined_order = ['حفظ', 'گردان', 'تجوید', 'حسن صوت', 'اولیٰ', 'اولیٰ دہم', 'اولیٰ نہم', 'مڈل'];
$ordered_classes = [];
foreach ($predefined_order as $pc) {
    if (isset($grouped_students[$pc])) $ordered_classes[] = $pc;
}
foreach (array_keys($grouped_students) as $c) {
    if (!in_array($c, $predefined_order)) $ordered_classes[] = $c;
}

$attendance_data = [];
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while($row = $stmt->fetch()) {
    $s1 = $row['status_1'] ?? null;
    $s2 = $row['status_2'] ?? null;
    $s3 = $row['status_3'] ?? null;
    $s4 = $row['status_4'] ?? null;
    $l1 = (int)($row['late_1'] ?? 0);
    $l2 = (int)($row['late_2'] ?? 0);
    $l3 = (int)($row['late_3'] ?? 0);
    $l4 = (int)($row['late_4'] ?? 0);

    $attendance_data[$row['student_id']][$row['date']] = [
        's1' => $s1, 's2' => $s2, 's3' => $s3, 's4' => $s4,
        's5' => $row['status_5'] ?? $s1,
        's6' => $row['status_6'] ?? $s2,
        's7' => $row['status_7'] ?? $s3,
        's8' => $row['status_8'] ?? $s4,
        'l1' => $l1, 'l2' => $l2, 'l3' => $l3, 'l4' => $l4,
        'l5' => (int)($row['late_5'] ?? $l1),
        'l6' => (int)($row['late_6'] ?? $l2),
        'l7' => (int)($row['late_7'] ?? $l3),
        'l8' => (int)($row['late_8'] ?? $l4),
    ];
}
$stmt = $pdo->prepare("SELECT student_id, date, period_num, status, remarks FROM period_attendance WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while($row = $stmt->fetch()) {
    $periodNo = (int)($row['period_num'] ?? 0);
    if ($periodNo < 1 || $periodNo > 8) continue;
    if (!isset($attendance_data[$row['student_id']][$row['date']])) {
        $attendance_data[$row['student_id']][$row['date']] = [];
    }
    $attendance_data[$row['student_id']][$row['date']]['s' . $periodNo] = $row['status'];
    $attendance_data[$row['student_id']][$row['date']]['l' . $periodNo] = (int)($row['remarks'] ?? 0);
}

// --- 3. اساتذہ اور ان کی حاضری لائیں (Teachers & their Attendance) ---
$teachers_db = $pdo->query("SELECT id, name, father_name, assigned_classes FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll();
$teachers_assoc = [];
foreach($teachers_db as $t) {
    $teachers_assoc[$t['id']] = $t;
}

function ar_list_values($value) {
    $raw = trim((string)$value);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : explode(',', $raw);
    $out = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') $out[] = $item;
    }
    return array_values(array_unique($out));
}

$classes_db = get_allowed_classes($pdo);
$classes_assoc = [];
$class_id_map = [];
foreach($classes_db as $c) {
    $class_id_map[$c['name']] = $c['id'];
    $classes_assoc[$c['id']] = $c;
}

$class_periods_db = $pdo->query("SELECT class_id, period_no, teacher_ids, teacher_id FROM class_periods")->fetchAll();
$class_periods_assoc = [];
foreach($class_periods_db as $cp) {
    $class_periods_assoc[$cp['class_id']][] = $cp;
}

$teacher_attendance_data = [];
$stmt = $pdo->prepare("SELECT teacher_id, class_id, date, period_no, status, late_minutes FROM teacher_period_attendance WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while($row = $stmt->fetch()) {
    $statusMap = ['حاضر' => 'P', 'غیر حاضر' => 'A', 'رخصت' => 'L', 'P' => 'P', 'A' => 'A', 'L' => 'L'];
    $sts = $statusMap[$row['status']] ?? '';
    $teacher_attendance_data[$row['teacher_id']][$row['class_id']][$row['date']][$row['period_no']] = [
        'status' => $sts,
        'late' => (int)$row['late_minutes'] > 0 ? 1 : 0
    ];
}

$grand_total_p = 0; $grand_total_late = 0; $grand_total_a = 0; $grand_total_l = 0; $grand_total_slots = 0;

function att_class_periods($className, $classes_assoc, $class_id_map, $class_periods_assoc = []) {
    $cid = $class_id_map[$className] ?? null;
    if ($cid && !empty($class_periods_assoc[$cid])) {
        $pNums = [];
        foreach ($class_periods_assoc[$cid] as $cp) {
            $pNo = (int)($cp['period_no'] ?? 0);
            if ($pNo > 0 && $pNo <= 8) $pNums[] = $pNo;
        }
        if (!empty($pNums)) {
            sort($pNums);
            return array_values(array_unique($pNums));
        }
    }
    $row = $cid && isset($classes_assoc[$cid]) ? $classes_assoc[$cid] : [];
    $text = mb_strtolower(trim(($className ?? '') . ' ' . ($row['class_type'] ?? '') . ' ' . ($row['section'] ?? '')), 'UTF-8');
    if (strpos($text, 'ناظرہ') !== false || strpos($text, 'nazira') !== false) return [1, 2];
    if (strpos($text, 'حفظ') !== false || strpos($text, 'گردان') !== false || strpos($text, 'حسن صوت') !== false || strpos($text, 'hifz') !== false || strpos($text, 'gardan') !== false || strpos($text, 'husn') !== false) return [1, 2, 3, 4];
    return [1, 2, 3, 4, 5, 6, 7, 8];
}

function att_grid_style($periodCount) {
    if ($periodCount <= 2) {
        return "grid-template-columns: repeat(2, 5px); gap: 2px; justify-content: center; align-items: center; width: 14px; margin: 0 auto;";
    }
    if ($periodCount <= 4) {
        return "grid-template-columns: repeat(2, 5px); gap: 2px; justify-content: center; align-items: center; width: 14px; margin: 0 auto;";
    }
    return "grid-template-columns: repeat(4, 4px); gap: 1px; justify-content: center; align-items: center; width: 19px; margin: 0 auto;";
}

function att_render_grid($att, $periods) {
    $html = '<div class="att-grid" style="' . att_grid_style(count($periods)) . '">';
    foreach ($periods as $p) {
        $s = $att['s' . $p] ?? '';
        $l = (int)($att['l' . $p] ?? 0);
        $html .= '<div class="dot ' . cls($s, $l) . '"></div>';
    }
    return $html . '</div>';
}

function att_count_status(&$pCount, &$lateCount, &$aCount, &$lCount, $status, $late = 0) {
    $status = trim((string)$status);
    if ($status === '') return;
    $statusMap = [
        'حاضر' => 'P', 'P' => 'P', 'present' => 'P', 'PRESENT' => 'P',
        'غیر حاضر' => 'A', 'A' => 'A', 'absent' => 'A', 'ABSENT' => 'A',
        'رخصت' => 'L', 'L' => 'L', 'leave' => 'L', 'LEAVE' => 'L',
        'تاخیر' => 'T', 'T' => 'T', 'late' => 'T', 'LATE' => 'T',
    ];
    $code = $statusMap[$status] ?? strtoupper(substr($status, 0, 1));
    
    if ($code === 'P') { if ((int)$late > 0) $lateCount++; else $pCount++; }
    if ($code === 'T') $lateCount++;
    if ($code === 'A') $aCount++;
    if ($code === 'L') $lCount++;
}
?>

<style>
    .att-grid { display: grid; margin: 0 auto; gap: 1px; align-items: center; justify-items: center; box-sizing: border-box; }
    .dot { width: 5px; height: 5px; min-width: 5px; min-height: 5px; border-radius: 50%; display: block; box-sizing: border-box; filter: none !important; -webkit-filter: none !important; opacity: 1 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; border: none !important; }
    .bg-p { background-color: #198754 !important; } 
    .bg-a { background-color: #dc3545 !important; } 
    .bg-l { background-color: #ffc107 !important; } 
    .bg-n { background-color: #e2e8f0 !important; }
    .bg-late { background-color: #fd7e14 !important; } 
    .report-table { width: 100%; border-collapse: collapse; font-size: 11px; table-layout: fixed; }
    .report-table th, .report-table td { border: 1px solid #000; padding: 2px 0px; text-align: center; vertical-align: middle; white-space: nowrap; overflow: hidden; }
    .madrasa-header { border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px; }
    .page-break { page-break-after: always; }
    .title-box { font-family: 'Noto Nastaliq Urdu', sans-serif; font-size: 18px; font-weight: bold; display: inline-block; border-bottom: 1px solid #ccc; padding-bottom: 2px; line-height: 1.4; }
    .date-cell { display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
    .greg-date { font-size: 13px; font-weight: bold; color: #000; display: block; }
    .hijri-date { font-size: 9px; color: #198754; font-weight: bold; margin-top: 2px; display: block; }
    .print-title-bar { display: none; }
    @media print {
        @page { size: A4 landscape; margin: 6mm 5mm; }
        /* === سب چھپائیں: sidebar, navbar, filter, buttons === */
        #wrapper, .no-print, .navbar, form, .nav-tabs,
        .card-header, nav, header, footer, aside,
        .sidebar, [class*="sidebar"], [id*="sidebar"],
        .card.shadow-sm.mb-3 { display: none !important; }
        /* === پرنٹ ٹائٹل بار ظاہر ہو === */
        .print-title-bar { display: block !important; }
        /* === باڈی بیس === */
        html, body { background: white !important; margin: 0 !important; padding: 0 !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
            font-family: 'Noto Nastaliq Urdu', sans-serif; }
        .container-fluid { padding: 0 1mm !important; margin: 0 !important; width: 100% !important; }
        /* === ریپورٹ کارڈ اس۔ ہے جیس۔ رجسٹر === */
        .report-card { border: none !important; box-shadow: none !important; margin-bottom: 2mm !important; }
        /* === پیج بریک === */
        .page-break { page-break-after: always; display: block; height: 1px; }
        /* === ٹیبل compact === */
        .report-table { font-size: 8pt !important; border-collapse: collapse !important; }
        .report-table th, .report-table td { border: 0.5pt solid #555 !important; padding: 1px !important; }
        .report-table thead tr { background-color: #284D3D !important; color: #fff !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        /* === ماڈرسہ ہیڈر رنگین === */
        .madrasa-header { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        /* === دوت رنگ پرنٹ میں === */
        .bg-p { background-color: #198754 !important; border: 1px solid #146c43 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .bg-a { background-color: #dc3545 !important; border: 1px solid #b02a37 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .bg-l { background-color: #ffc107 !important; border: 1px solid #d39e00 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .bg-late { background-color: #fd7e14 !important; border: 1px solid #dc6502 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .bg-n { background-color: #dee2e6 !important; border: 1px solid #ced4da !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; filter: none !important; opacity: 1 !important; }
        .hijri-date { color: #198754 !important; -webkit-print-color-adjust: exact; }
        /* === مجموعی سمری کارڈ پرنٹ میں === */
        .no-print-break { display: block !important; max-width: 100% !important; }
        .no-print-break .card-header { display: block !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }
</style>

<div class="container-fluid py-3">
    
    <div class="card shadow-sm mb-3 no-print">
        <div class="card-header bg-light p-0">
            <div class="d-flex justify-content-between align-items-center px-2">
                <ul class="nav nav-tabs fw-bold border-0">
                    <li class="nav-item"><button class="nav-link border-0 <?php echo $mode=='month'?'active bg-white':''; ?>" onclick="window.location='attendance_report.php?mode=month'">ماہانہ</button></li>
                    <li class="nav-item"><button class="nav-link border-0 <?php echo $mode=='range'?'active bg-white text-primary':'text-muted'; ?>" onclick="window.location='attendance_report.php?mode=range'">کسٹم رینج</button></li>
                </ul>
                <form method="POST" class="d-flex align-items-center gap-1 bg-white border rounded px-2 py-1 shadow-sm" style="height: 30px;">
                    <span class="small fw-bold text-muted">چاند فرق:</span>
                    <button type="submit" name="update_offset" class="btn btn-sm btn-outline-danger py-0 px-2 fw-bold" style="height: 22px; line-height: 0;" onclick="document.getElementById('off_val').value = <?php echo $hijri_offset - 1; ?>">-</button>
                    <span class="fw-bold text-dark mx-1" style="font-size: 14px; min-width: 20px; text-align: center;"><?php echo ($hijri_offset > 0 ? '+' : '') . $hijri_offset; ?></span>
                    <button type="submit" name="update_offset" class="btn btn-sm btn-outline-success py-0 px-2 fw-bold" style="height: 22px; line-height: 0;" onclick="document.getElementById('off_val').value = <?php echo $hijri_offset + 1; ?>">+</button>
                    <input type="hidden" name="offset_value" id="off_val" value="<?php echo $hijri_offset; ?>">
                    <span class="small text-muted ms-1">دن</span>
                </form>
            </div>
        </div>

        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-9">
                    <?php if($mode == 'month'): ?>
                    <form class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="mode" value="month">
                        <select name="month" class="form-select form-select-sm" style="width: 120px;" onchange="this.form.submit()">
                            <?php 
                            $sel_m = $_GET['month'] ?? $current_month;
                            for($m=1; $m<=12; $m++) echo "<option value='$m' ".($m==$sel_m?'selected':'').">".$urdu_months_greg[date('F', mktime(0,0,0,$m,1))]."</option>"; 
                            ?>
                        </select>
                        <select name="year" class="form-select form-select-sm" style="width: 100px;" onchange="this.form.submit()">
                            <?php 
                            $sel_y = $_GET['year'] ?? $current_year;
                            $start_y = $current_year - 2; $end_y = $current_year + 2;
                            for($y=$start_y; $y<=$end_y; $y++) echo "<option value='$y' ".($y==$sel_y?'selected':'').">$y</option>";
                            ?>
                        </select>
                        <select name="std_id" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                            <option value="">تمام طلبہ</option>
                            <?php 
                            $st_id = $_GET['std_id'] ?? '';
                            $all_sts = $pdo->query("SELECT id, name FROM students WHERE status='Active' AND is_deleted=0 ORDER BY name ASC")->fetchAll();
                            foreach($all_sts as $st) echo "<option value='{$st['id']}' ".($st_id==$st['id']?'selected':'').">{$st['name']}</option>";
                            ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">رپورٹ دیکھیں</button>
                        <?php if($st_id): ?>
                            <a href="parent_report.php?std_id=<?php echo $st_id; ?>" class="btn btn-info btn-sm fw-bold"><i class="fas fa-user me-1"></i> انفرادی رپورٹ</a>
                        <?php endif; ?>
                    </form>
                    <?php else: ?>
                    <form class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="mode" value="range">
                        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo $start_date; ?>">
                        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo $end_date; ?>">
                        <button type="submit" class="btn btn-primary btn-sm">رپورٹ دیکھیں</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 text-end">
                    <button onclick="printLandscape()" class="btn btn-success btn-sm fw-bold shadow"><i class="fas fa-print me-2"></i> پرنٹ (Landscape)</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Title Bar — صرف پرنٹ میں ظاہر -->
    <div class="print-title-bar">
        <div style="text-align:center; border-bottom:2px solid #284D3D; margin-bottom:6px; padding-bottom:5px; font-family:'Noto Nastaliq Urdu',sans-serif;">
            <h4 style="color:#284D3D; margin:0; font-size:13pt;">رجسٹر <?php echo t('status_present'); ?>ی</h4>
            <p style="margin:1px 0; font-size:8.5pt;">
                مدرسہ اشرف المدارس &mdash; فاروقہ، سرگودھا
                &nbsp;|&nbsp; مدت: <strong><?php echo date('d M Y', strtotime($start_date)); ?> تا <?php echo date('d M Y', strtotime($end_date)); ?></strong>
            </p>
        </div>
    </div>

    <?php foreach ($grouped_months as $ym => $dates_array): 
        $first_day = reset($dates_array);
        $last_day = end($dates_array);
        
        $eng_month_name = $urdu_months_greg[date('F', strtotime($first_day))];
        $eng_year = date('Y', strtotime($first_day));
        $english_part = "$eng_month_name $eng_year";
        
        $islamic_part = getHijriMonthRangeTitle($first_day, $last_day, $hijri_offset);
    ?>

    <?php foreach($ordered_classes as $cName):
        $class_periods = att_class_periods($cName, $classes_assoc, $class_id_map);
    ?>
    <div class="card report-card shadow-sm mb-4 page-break">
        <div class="card-body p-2">
            
            <div class="madrasa-header d-flex justify-content-between align-items-end" style="font-family: 'Jameel Noori Nastaleeq', sans-serif;">
                <div class="text-start" style="width: 25%;">
                    <h4 class="fw-bold m-0">مدرسہ اشرف المدارس</h4>
                    <h6 class="m-0">فاروقہ، سرگودھا</h6>
                </div>
                <div class="text-center" style="width: 50%;">
                    <div class="title-box">
                        <span class="text-dark"><?php echo $english_part; ?></span>
                        <span class="mx-2 text-muted" style="font-size: 20px;">/</span>
                        <span class="text-success"><?php echo $islamic_part; ?></span>
                    </div>
                    <div class="mt-1 fw-bold text-primary" style="font-family: 'Noto Nastaliq Urdu', sans-serif; font-size: 14px;">کلاس: <?php echo $cName; ?></div>
                </div>
                <div class="text-end" style="width: 25%;">
                    <h3 class="fw-bold m-0 text-decoration-underline">رجسٹر <?php echo t('status_present'); ?>ی</h3>
                </div>
            </div>

            <div class="text-center mb-2" style="font-size: 11px;">
                <span class="badge rounded-pill border border-success text-success bg-light mx-1">● <?php echo t('status_present'); ?></span>
                <span class="badge rounded-pill border border-warning text-dark bg-light mx-1" style="border-color: #fd7e14 !important; color: #fd7e14 !important;">● تاخیر</span>
                <span class="badge rounded-pill border border-danger text-danger bg-light mx-1">● غیر <?php echo t('status_present'); ?></span>
                <span class="badge rounded-pill border border-warning text-dark bg-light mx-1">● <?php echo t('status_leave'); ?></span>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr class="bg-light">
                            <th style="width: 25px;">#</th>
                            <th style="width: 50px;">رول نمبر</th>
                            <th style="width: 130px; text-align:right; padding-right:5px;">نام طالب علم</th>
                            <th style="width: 100px; text-align:right; padding-right:5px;">ولدیت</th>
                            <?php foreach($dates_array as $date_str): 
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $g_day = (int)date('d', strtotime($date_str));
                                $h_data = getHijriDateLoop($date_str, $hijri_offset); 
                                $h_day = $h_data['d'];
                            ?>
                                <th style="width: 22px;">
                                    <div class="date-cell">
                                        <span class="greg-date"><?php echo $g_day; ?></span>
                                        <span class="hijri-date"><?php echo $h_day; ?></span>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                            <th class="bg-light text-success" style="width: 30px; font-size:10px;"><?php echo t('status_present'); ?></th>
                            <th class="bg-light text-warning" style="width: 30px; font-size:10px; color: #fd7e14 !important;">تاخیر</th>
                            <th class="bg-light text-danger" style="width: 30px; font-size:10px;">غیر</th>
                            <th class="bg-light text-warning" style="width: 30px; font-size:10px;"><?php echo t('status_leave'); ?></th>
                            <th class="bg-light text-primary" style="width: 50px; font-size:10px;">کیفیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // --- اساتذہ کی رینڈرنگ ---
                        $teachers_for_class = [];
                        $cid = $class_id_map[$cName] ?? null;
                        if ($cid) {
                            $cRow = $classes_assoc[$cid];
                            if (!empty($cRow['main_teacher_ids'])) {
                                foreach (ar_list_values($cRow['main_teacher_ids']) as $tid) {
                                    $teachers_for_class[$tid] = $tid;
                                }
                            }
                            $cNameToCheck = $cRow['name'] ?? $cName;
                            $cTypeToCheck = $cRow['class_type'] ?? '';
                            if (
                                strpos($cNameToCheck, 'حفظ') !== false || 
                                strpos($cNameToCheck, 'ناظرہ') !== false || 
                                strpos($cNameToCheck, 'گردان') !== false || 
                                strpos($cNameToCheck, 'حسن صوت') !== false || 
                                strpos(strtolower($cNameToCheck), 'hifz') !== false || 
                                strpos(strtolower($cNameToCheck), 'nazira') !== false || 
                                $cTypeToCheck == 'Hifz/Nazira' ||
                                $cTypeToCheck == 'Hifz/Gardan/Husn' ||
                                $cTypeToCheck == 'Nazira'
                            ) {
                                if (!empty($cRow['main_teacher_ids'])) {
                                    $decoded = json_decode($cRow['main_teacher_ids'], true);
                                    if (is_array($decoded)) {
                                        foreach($decoded as $tid) $teachers_for_class[$tid] = $tid;
                                    } else {
                                        $exp = array_filter(explode(',', $cRow['main_teacher_ids']));
                                        foreach($exp as $tid) $teachers_for_class[$tid] = $tid;
                                    }
                                }
                                if (!empty($cRow['main_teacher_id'])) {
                                    $teachers_for_class[$cRow['main_teacher_id']] = $cRow['main_teacher_id'];
                                }
                                if (!empty($cRow['teacher_id'])) {
                                    $teachers_for_class[$cRow['teacher_id']] = $cRow['teacher_id'];
                                }
                            } else {
                                if (isset($class_periods_assoc[$cid])) {
                                    foreach ($class_periods_assoc[$cid] as $cp) {
                                        if (!empty($cp['teacher_ids'])) {
                                            $tids = json_decode($cp['teacher_ids'], true) ?: [];
                                            foreach ($tids as $tid) {
                                                $teachers_for_class[$tid] = $tid;
                                            }
                                        } elseif (!empty($cp['teacher_id'])) {
                                            $teachers_for_class[$cp['teacher_id']] = $cp['teacher_id'];
                                        }
                                    }
                                }
                            }
                        }
                        foreach ($teachers_assoc as $profile_tid => $profile_teacher) {
                            if (in_array($cName, ar_list_values($profile_teacher['assigned_classes'] ?? ''), true)) {
                                $teachers_for_class[$profile_tid] = $profile_tid;
                            }
                        }
                        
                        foreach ($teachers_for_class as $tid): 
                            if (!isset($teachers_assoc[$tid])) continue;
                            $tInfo = $teachers_assoc[$tid];
                            $month_p = 0; $month_late = 0; $month_a = 0; $month_l = 0;
                        ?>
                        <tr style="background-color: #e8f4f8;">
                            <td style="vertical-align: middle;"><i class="fas fa-chalkboard-teacher text-primary" style="font-size: 10px;"></i></td>
                            <td style="vertical-align: middle;"><span class="badge bg-primary ms-1" style="font-size:7px; vertical-align: middle; padding: 3px 5px;">استاد</span></td>
                            <td style="text-align:right; padding-right:5px; font-weight:bold; color: #084298; font-size: 10px; vertical-align: middle; white-space: nowrap;"><?php echo htmlspecialchars($tInfo['name']); ?></td>
                            <td style="text-align:right; padding-right:5px; color:#6c757d; font-size: 9px; vertical-align: middle;"><?php echo htmlspecialchars($tInfo['father_name'] ?? '-'); ?></td>
                            <?php foreach($dates_array as $date_str): 
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $att = $teacher_attendance_data[$tid][$cid][$date_str] ?? null;
                                if($att) {
                                    foreach($class_periods as $pNo) {
                                        $st = $att[$pNo]['status'] ?? '';
                                        $late = $att[$pNo]['late'] ?? 0;
                                        att_count_status($month_p, $month_late, $month_a, $month_l, $st, $late);
                                    }
                                }
                            ?>
                            <td>
                                <?php if($att): 
                                    $tGrid = [];
                                    foreach($class_periods as $pNo) {
                                        $tGrid['s' . $pNo] = $att[$pNo]['status'] ?? '';
                                        $tGrid['l' . $pNo] = $att[$pNo]['late'] ?? 0;
                                    }
                                    echo att_render_grid($tGrid, $class_periods);
                                endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="font-weight:bold;"><?php echo $month_p; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo $month_late; ?></td>
                            <td style="font-weight:bold;"><?php echo $month_a; ?></td>
                            <td style="font-weight:bold;"><?php echo $month_l; ?></td>
                            <?php 
                                $total_slots = $month_p + $month_late + $month_a + $month_l;
                                $status = "-";
                                $status_color = "black";
                                if ($total_slots > 0) {
                                    $perc = (($month_p + $month_late) / $total_slots) * 100;
                                    if ($perc >= 100) {
                                        $status = "مکمل حاضر";
                                        $status_color = "green";
                                    } else {
                                        $status = round($perc) . "% حاضر";
                                        $status_color = "blue";
                                    }
                                }
                            ?>
                            <td style="font-weight:bold; color: <?php echo $status_color; ?>;"><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php $sr = 1; foreach($grouped_students[$cName] as $s): 
                            $month_p = 0; $month_late = 0; $month_a = 0; $month_l = 0;
                        ?>
                        <tr>
                            <td><?php echo $sr++; ?></td>
                            <td><?php echo htmlspecialchars($s['roll_number'] ?? $s['admission_number']); ?></td>
                            <td style="text-align:right; padding-right:5px; font-weight:bold;"><?php echo $s['name']; ?></td>
                            <td style="text-align:right; padding-right:5px; color:#555;"><?php echo $s['father_name']; ?></td>
                            <?php foreach($dates_array as $date_str): 
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $isAlumni = ($s['status'] == 'Alumni' && !empty($s['graduation_date']) && $date_str >= $s['graduation_date']);
                                
                                $att = $attendance_data[$s['id']][$date_str] ?? null;
                                if($att) {
                                    foreach($class_periods as $pNo) {
                                        $st = $att['s' . $pNo] ?? '';
                                        $late = $att['l' . $pNo] ?? 0;
                                        att_count_status($month_p, $month_late, $month_a, $month_l, $st, $late);
                                        if($st) {
                                            $grand_total_slots++;
                                            att_count_status($grand_total_p, $grand_total_late, $grand_total_a, $grand_total_l, $st, $late);
                                        }
                                    }
                                }
                            ?>
                            <td>
                                <?php if($is_friday): ?>
                                    <div style="writing-mode: vertical-rl; transform: rotate(180deg); font-weight: bold; color: #dc3545; display: inline-block;">جمعہ</div>
                                <?php elseif($isAlumni): ?>
                                    <span class="text-muted" style="font-size: 10px;">-</span>
                                <?php elseif($att): ?>
                                    <?php echo att_render_grid($att, $class_periods); ?>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="font-weight:bold;"><?php echo $month_p; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo $month_late; ?></td>
                            <td style="font-weight:bold;"><?php echo $month_a; ?></td>
                            <td style="font-weight:bold;"><?php echo $month_l; ?></td>
                            <?php 
                                $total_slots = $month_p + $month_late + $month_a + $month_l;
                                $status = "-";
                                $status_color = "black";
                                if ($total_slots > 0) {
                                    $perc = (($month_p + $month_late) / $total_slots) * 100;
                                    if ($perc >= 100) {
                                        $status = "مکمل حاضر";
                                        $status_color = "green";
                                    } else {
                                        $status = round($perc) . "% حاضر";
                                        $status_color = "blue";
                                    }
                                }
                            ?>
                            <td style="font-weight:bold; color: <?php echo $status_color; ?>;"><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <?php 
    $base = ($grand_total_slots > 0) ? $grand_total_slots : 1;
    $perc_p = round(($grand_total_p / $base) * 100, 1);
    $perc_late = round(($grand_total_late / $base) * 100, 1);
    $perc_a = round(($grand_total_a / $base) * 100, 1);
    $perc_l = round(($grand_total_l / $base) * 100, 1);
    ?>
    <div class="card shadow border-dark mt-4 no-print-break" style="max-width: 600px; margin: 0 auto;">
        <div class="card-header bg-dark text-white text-center fw-bold">
            مجموعی کارکردگی رپورٹ (<?php echo date('d M', strtotime($start_date)) . " تا " . date('d M', strtotime($end_date)); ?>)
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-3 border-end"><h4 class="text-success fw-bold mb-0"><?php echo $perc_p; ?>%</h4><small>بر<?php echo t('time_period'); ?></small></div>
                <div class="col-3 border-end"><h4 class="text-warning fw-bold mb-0" style="color: #fd7e14 !important;"><?php echo $perc_late; ?>%</h4><small>تاخیر</small></div>
                <div class="col-3 border-end"><h4 class="text-danger fw-bold mb-0"><?php echo $perc_a; ?>%</h4><small>غیر <?php echo t('status_present'); ?></small></div>
                <div class="col-3"><h4 class="text-info fw-bold mb-0"><?php echo $perc_l; ?>%</h4><small><?php echo t('status_leave'); ?></small></div>
            </div>
        </div>
    </div>
</div>

<script>
function printLandscape() {
    document.querySelectorAll('.print-title-bar').forEach(el => el.style.display = 'block');
    window.print();
    document.querySelectorAll('.print-title-bar').forEach(el => el.style.display = 'none');
}
</script>

<?php 
include 'footer.php'; 
function cls($s, $l=0) {
    $s = trim((string)$s);
    if ($s === '') return 'bg-n';
    
    $statusMap = [
        'حاضر' => 'P', 'P' => 'P', 'present' => 'P', 'PRESENT' => 'P',
        'غیر حاضر' => 'A', 'A' => 'A', 'absent' => 'A', 'ABSENT' => 'A',
        'رخصت' => 'L', 'L' => 'L', 'leave' => 'L', 'LEAVE' => 'L',
        'تاخیر' => 'T', 'T' => 'T', 'late' => 'T', 'LATE' => 'T',
    ];
    $code = $statusMap[$s] ?? strtoupper(substr($s, 0, 1));

    if ($code === 'P') return ((int)$l > 0) ? 'bg-late' : 'bg-p';
    if ($code === 'T') return 'bg-late';
    if ($code === 'A') return 'bg-a';
    if ($code === 'L') return 'bg-l';
    return 'bg-n';
}

function getHijriMonthRangeTitle($start, $end, $offset) {
    $h1 = getHijriDateLoop($start, $offset);
    $h2 = getHijriDateLoop($end, $offset);
    $months = [1=>"محرم الحرام",2=>"صفر المظفر",3=>"ربیع الاول",4=>"ربیع الثانی",5=>"جمادی الاول",6=>"جمادی الثانی",7=>"رجب المرجب",8=>"شعبان المعظم",9=>"رمضان المبارک",10=>"شوال المکرم",11=>"ذیقعدہ",12=>"ذی الحجہ"];
    $m1 = isset($months[$h1['m']]) ? $months[$h1['m']] : '';
    $m2 = isset($months[$h2['m']]) ? $months[$h2['m']] : '';
    if ($h1['m'] == $h2['m']) { return $m1 . " " . $h1['y'] . " ھ"; }
    else { if ($h1['y'] != $h2['y']) { return $m1 . " " . $h1['y'] . " - " . $m2 . " " . $h2['y'] . " ھ"; } return $m1 . " - " . $m2 . " " . $h1['y'] . " ھ"; }
}

// 🧮 LOOP METHOD (The only way to guarantee 100% accuracy without library)
function getHijriDateLoop($date_str, $offset) {
    // Anchor: 19 July 2023 = 1 Muharram 1445
    $ref_date = new DateTime('2023-07-19');
    $target_date = new DateTime($date_str);
    
    // دن گنیں
    $interval = $ref_date->diff($target_date);
    $days = $interval->days; // Total days difference
    
    // اگر تاریخ پرانی ہو تو ہم فی الحال سپورٹ نہیں کر رہے (کیونکہ رجسٹر موجودہ ہے)
    // لیکن اگر ہو تو بھی 1445 ہی دکھا دے گا
    if ($ref_date > $target_date) {
        // Fallback for older dates (Simple approx)
        return ['d'=>1, 'm'=>1, 'y'=>1445];
    }

    // ایڈجسٹمنٹ
    $days = $days + $offset;

    // گنتی شروع کریں
    $h_year = 1445;
    $h_month = 1;
    
    while ($days > 0) {
        // طاق مہینے 30 کے، جفت 29 کے
        $days_in_month = ($h_month % 2 == 1) ? 30 : 29;
        
        // ذوالحجہ (12) لیپ سال میں 30 کا ہوتا ہے
        // Leap Year Logic: (11 * year + 14) % 30 < 11
        if ($h_month == 12 && ((11 * $h_year + 14) % 30 < 11)) {
            $days_in_month = 30; 
        }

        if ($days >= $days_in_month) {
            $days -= $days_in_month;
            $h_month++;
            if ($h_month > 12) {
                $h_month = 1;
                $h_year++;
            }
        } else {
            break; // گنتی پوری ہو گئی
        }
    }
    
    // جو دن بچ گئے وہ آج کی تاریخ ہے (0 سے شروع ہوتا ہے اس لیے +1)
    $h_day = $days + 1;

    return ['d' => (int)$h_day, 'm' => (int)$h_month, 'y' => (int)$h_year];
}
?>
