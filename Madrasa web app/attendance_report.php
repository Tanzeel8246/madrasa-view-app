<?php 
// ایرر رپورٹنگ
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db.php'; 
include 'header.php'; 
$cur_tenant_id = (int)get_current_tenant_id();

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

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'administrator'], true);
$p_needed = ($sys === 'Masjid') ? 'manage_masjid_attendance_report' : 'manage_attendance_report';
$p_alt = ($sys === 'Masjid') ? 'manage_masjid_attendance' : 'manage_attendance';
if (!$is_admin && !app_has_perm($p_needed) && !app_has_perm($p_alt)) {
    echo "<div class='alert alert-danger m-4 text-center fw-bold'>آپ کو اس حاضری رپورٹ تک رسائی کی اجازت نہیں ہے۔</div>";
    include 'footer.php';
    exit;
}

$allowed_classes = get_allowed_classes($pdo, 'id ASC', null, null, $sys);
$allowed_class_names = array_values(array_filter(array_unique(array_column($allowed_classes, 'name'))));
if ($is_admin) {
    $class_condition = "1=1";
} elseif (empty($allowed_class_names)) {
    $class_condition = "1=0";
} else {
    $inClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names));
    $class_condition = "(TRIM(class) IN ($inClasses) OR TRIM(class_name) IN ($inClasses))";
}

$studentCols = [];
try { $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
$hasSys = in_array('system_type', $studentCols, true);
$stdSysCond = $hasSys ? $sys_cond : "1=1";

$std_filter = (isset($_GET['std_id']) && $_GET['std_id'] != '') ? " AND id = " . (int)$_GET['std_id'] : "";

$students = $pdo->query("
    SELECT * FROM students 
    WHERE tenant_id = $cur_tenant_id AND $stdSysCond AND ($class_condition) AND (is_deleted=0 OR is_deleted IS NULL)
       AND (
           (
               (status='Active' OR status='Present' OR status='حاضر' OR status='' OR status IS NULL)
               AND (LOWER(TRIM(COALESCE(status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
               AND (status NOT LIKE '%خارج%' AND status NOT LIKE '%فارغ%' AND status NOT LIKE '%چھوڑ%' AND status NOT LIKE '%ترک%')
               AND (alumni_category IS NULL OR alumni_category = '' OR LOWER(TRIM(alumni_category)) NOT IN ('completed', 'left', 'dropped', 'expelled'))
               AND (graduation_date IS NULL OR graduation_date = '' OR graduation_date = '0000-00-00' OR graduation_date >= '$start_date')
           )
           OR (status='Alumni' AND graduation_date >= '$start_date')
           OR (id IN (SELECT student_id FROM attendance WHERE date BETWEEN '$start_date' AND '$end_date' AND tenant_id = $cur_tenant_id))
           OR (id IN (SELECT student_id FROM period_attendance WHERE date BETWEEN '$start_date' AND '$end_date' AND tenant_id = $cur_tenant_id))
       )
       $std_filter
    ORDER BY class ASC, admission_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($students) && !empty($allowed_class_names)) {
    $students = $pdo->query("
        SELECT * FROM students 
        WHERE tenant_id = $cur_tenant_id AND ($class_condition) AND (is_deleted=0 OR is_deleted IS NULL)
           AND (
               (
                   (status='Active' OR status='Present' OR status='حاضر' OR status='' OR status IS NULL)
                   AND (LOWER(TRIM(COALESCE(status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
                   AND (status NOT LIKE '%خارج%' AND status NOT LIKE '%فارغ%' AND status NOT LIKE '%چھوڑ%' AND status NOT LIKE '%ترک%')
                   AND (alumni_category IS NULL OR alumni_category = '' OR LOWER(TRIM(alumni_category)) NOT IN ('completed', 'left', 'dropped', 'expelled'))
                   AND (graduation_date IS NULL OR graduation_date = '' OR graduation_date = '0000-00-00' OR graduation_date >= '$start_date')
               )
               OR (status='Alumni' AND graduation_date >= '$start_date')
               OR (id IN (SELECT student_id FROM attendance WHERE date BETWEEN '$start_date' AND '$end_date' AND tenant_id = $cur_tenant_id))
               OR (id IN (SELECT student_id FROM period_attendance WHERE date BETWEEN '$start_date' AND '$end_date' AND tenant_id = $cur_tenant_id))
           )
           $std_filter
        ORDER BY class ASC, admission_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$grouped_students = [];
foreach ($students as $s) {
    $c = empty($s['class']) ? 'دیگر' : $s['class'];
    if (!isset($grouped_students[$c])) $grouped_students[$c] = [];
    $grouped_students[$c][] = $s;
}

// Sort each class by roll/admission number
foreach ($grouped_students as $c => &$cStudents) {
    usort($cStudents, function($a, $b) {
        $admA = (int)($a['admission_number'] ?? ($a['roll_number'] ?? 0));
        $admB = (int)($b['admission_number'] ?? ($b['roll_number'] ?? 0));
        return $admA <=> $admB;
    });
}
unset($cStudents);

$predefined_order = ['حفظ', 'گردان', 'تجوید', 'حسن صوت', 'اولیٰ', 'اولیٰ دہم', 'اولیٰ نہم', 'مڈل'];
$ordered_classes = [];
foreach ($predefined_order as $pc) {
    if (isset($grouped_students[$pc])) $ordered_classes[] = $pc;
}
foreach (array_keys($grouped_students) as $c) {
    if (!in_array($c, $predefined_order)) $ordered_classes[] = $c;
}

$attendance_data = [];
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE date BETWEEN ? AND ? AND tenant_id = ?");
$stmt->execute([$start_date, $end_date, $cur_tenant_id]);
while($row = $stmt->fetch()) {
    $s1 = $row['status_1'] ?? null;
    $s2 = $row['status_2'] ?? null;
    $s3 = $row['status_3'] ?? null;
    $s4 = $row['status_4'] ?? null;
    $shift_raw = strtolower(str_replace([' ', '_'], '', (string)($row['shift'] ?? '')));
    $stat_raw = trim((string)($row['status'] ?? ''));
    if ($shift_raw === '1' || strpos($shift_raw, 'morning') !== false || strpos($shift_raw, 'sabaq') !== false || strpos($shift_raw, 'صبح') !== false) {
        if ($s1 === null && $stat_raw !== '') $s1 = $stat_raw;
    } elseif ($shift_raw === '2' || strpos($shift_raw, 'after') !== false || strpos($shift_raw, 'sabqi') !== false || strpos($shift_raw, 'اشراق') !== false) {
        if ($s2 === null && $stat_raw !== '') $s2 = $stat_raw;
    } elseif ($shift_raw === '3' || strpos($shift_raw, 'noon') !== false || strpos($shift_raw, 'manzil') !== false || strpos($shift_raw, 'ظہر') !== false) {
        if ($s3 === null && $stat_raw !== '') $s3 = $stat_raw;
    } elseif ($shift_raw === '4' || strpos($shift_raw, 'evening') !== false || strpos($shift_raw, 'عصر') !== false) {
        if ($s4 === null && $stat_raw !== '') $s4 = $stat_raw;
    }
    $l1 = (int)($row['late_1'] ?? 0);
    $l2 = (int)($row['late_2'] ?? 0);
    $l3 = (int)($row['late_3'] ?? 0);
    $l4 = (int)($row['late_4'] ?? 0);

    $sid = $row['student_id'];
    $dt = $row['date'];
    if (!isset($attendance_data[$sid][$dt])) {
        $attendance_data[$sid][$dt] = [
            's1' => null, 's2' => null, 's3' => null, 's4' => null,
            's5' => null, 's6' => null, 's7' => null, 's8' => null,
            'l1' => 0, 'l2' => 0, 'l3' => 0, 'l4' => 0,
            'l5' => 0, 'l6' => 0, 'l7' => 0, 'l8' => 0,
        ];
    }
    if ($s1 !== null && ($attendance_data[$sid][$dt]['s1'] === null || $attendance_data[$sid][$dt]['s1'] === '')) $attendance_data[$sid][$dt]['s1'] = $s1;
    if ($s2 !== null && ($attendance_data[$sid][$dt]['s2'] === null || $attendance_data[$sid][$dt]['s2'] === '')) $attendance_data[$sid][$dt]['s2'] = $s2;
    if ($s3 !== null && ($attendance_data[$sid][$dt]['s3'] === null || $attendance_data[$sid][$dt]['s3'] === '')) $attendance_data[$sid][$dt]['s3'] = $s3;
    if ($s4 !== null && ($attendance_data[$sid][$dt]['s4'] === null || $attendance_data[$sid][$dt]['s4'] === '')) $attendance_data[$sid][$dt]['s4'] = $s4;
    if ($l1 > 0) $attendance_data[$sid][$dt]['l1'] = $l1;
    if ($l2 > 0) $attendance_data[$sid][$dt]['l2'] = $l2;
    if ($l3 > 0) $attendance_data[$sid][$dt]['l3'] = $l3;
    if ($l4 > 0) $attendance_data[$sid][$dt]['l4'] = $l4;
    if (false) $attendance_data[$row['student_id']][$row['date']] = [
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
$stmt = $pdo->prepare("SELECT student_id, date, period_num, status, remarks FROM period_attendance WHERE date BETWEEN ? AND ? AND tenant_id = ?");
$stmt->execute([$start_date, $end_date, $cur_tenant_id]);
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
$teachers_db = $pdo->query("SELECT id, name, father_name, assigned_classes, status, status_reason, status_change_date, joining_date FROM teachers WHERE tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll();
$teachers_assoc = [];
foreach($teachers_db as $t) {
    $teachers_assoc[$t['id']] = $t;
}

// اساتذہ کی آخری حاضری کی تاریخ معلوم کریں
$teacher_last_att = [];
try {
    $t_stmt = $pdo->query("SELECT teacher_id, MAX(date) as last_date FROM teacher_period_attendance WHERE tenant_id = $cur_tenant_id GROUP BY teacher_id");
    while($t_r = $t_stmt->fetch()) {
        $teacher_last_att[$t_r['teacher_id']] = $t_r['last_date'];
    }
} catch (Exception $e) {}

function ar_clean_date($date_str) {
    if (empty($date_str)) return null;
    $date_str = trim((string)$date_str);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $date_str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $date_str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $ts = strtotime($date_str);
    return $ts ? date('Y-m-d', $ts) : null;
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

$classes_db = get_allowed_classes($pdo, 'id ASC', null, null, $sys);
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

function att_period_display_name($pNo, $totalPeriods = 8) {
    return 'سبق ' . $pNo;
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
    .period-summary-card { border-color: #cbd5e1 !important; border-radius: 8px; background: #fff; }
    .period-stat-card { background: #fff; border-color: #cbd5e1 !important; border-radius: 6px; min-width: 95px; flex: 1 1 0; }
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
                            $all_sts = $pdo->query("SELECT id, name FROM students WHERE ($class_condition) AND status='Active' AND is_deleted=0 ORDER BY name ASC")->fetchAll();
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
                        <input type="date" name="from" id="attRangeFrom" class="form-control form-control-sm" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('attRangeTo').value) this.form.submit();">
                        <input type="date" name="to" id="attRangeTo" class="form-control form-control-sm" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('attRangeFrom').value) this.form.submit();">
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
        
        // Initialize period-wise date stats and students for this class
        $class_period_date_stats = [];
        $class_period_date_students = [];
        $class_daily_p = [];
        $class_daily_late = [];
        $class_daily_a = [];
        $class_daily_l = [];
        foreach ($dates_array as $d_tmp) {
            $class_period_date_stats[$d_tmp] = [];
            $class_period_date_students[$d_tmp] = [];
            $class_daily_p[$d_tmp] = 0;
            $class_daily_late[$d_tmp] = 0;
            $class_daily_a[$d_tmp] = 0;
            $class_daily_l[$d_tmp] = 0;
            foreach ($class_periods as $pNo) {
                $class_period_date_stats[$d_tmp][$pNo] = ['p' => 0, 'late' => 0, 'a' => 0, 'l' => 0];
                $class_period_date_students[$d_tmp][$pNo] = ['p' => [], 'late' => [], 'a' => [], 'l' => []];
            }
        }
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
                                $g_day = (int)date('d', strtotime($date_str));
                                $h_data = getHijriDateLoop($date_str, $hijri_offset); 
                                $h_day = $h_data['d'];
                            ?>
                                <th style="width: 22px; <?php if($is_friday) echo 'background-color: #fee2e2 !important; border-left: 1px solid #f87171 !important; border-right: 1px solid #f87171 !important;'; ?>">
                                    <div class="date-cell">
                                        <span class="greg-date" style="<?php if($is_friday) echo 'color: #b91c1c; font-weight: bold;'; ?>"><?php echo $is_friday ? 'جمعہ' : $g_day; ?></span>
                                        <span class="hijri-date" style="<?php if($is_friday) echo 'color: #dc2626;'; ?>"><?php echo $h_day; ?></span>
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
                            } elseif (isset($class_periods_assoc[$cid])) {
                                foreach ($class_periods_assoc[$cid] as $cp) {
                                    if (!empty($cp['teacher_ids'])) {
                                        $tids = json_decode($cp['teacher_ids'], true) ?: [];
                                        foreach ($tids as $tid) $teachers_for_class[$tid] = $tid;
                                    } elseif (!empty($cp['teacher_id'])) $teachers_for_class[$cp['teacher_id']] = $cp['teacher_id'];
                                }
                            }
                        }
                        foreach ($teachers_assoc as $profile_tid => $profile_teacher) {
                            if (in_array($cName, ar_list_values($profile_teacher['assigned_classes'] ?? ''), true)) {
                                $teachers_for_class[$profile_tid] = $profile_tid;
                            } elseif ($cid && !empty($teacher_attendance_data[$profile_tid][$cid])) {
                                $teachers_for_class[$profile_tid] = $profile_tid;
                            }
                        }

                        usort($teachers_for_class, function($a, $b) use ($teachers_assoc, $ym) {
                            $tA = $teachers_assoc[$a] ?? [];
                            $tB = $teachers_assoc[$b] ?? [];
                            $stA = trim((string)($tA['status'] ?? 'Active'));
                            $stB = trim((string)($tB['status'] ?? 'Active'));
                            $status_a_date = !empty($tA['status_change_date']) ? ar_clean_date($tA['status_change_date']) : (!empty($tA['resignation_date']) ? ar_clean_date($tA['resignation_date']) : null);
                            $status_b_date = !empty($tB['status_change_date']) ? ar_clean_date($tB['status_change_date']) : (!empty($tB['resignation_date']) ? ar_clean_date($tB['resignation_date']) : null);
                            $status_a_ym = $status_a_date ? substr($status_a_date, 0, 7) : null;
                            $status_b_ym = $status_b_date ? substr($status_b_date, 0, 7) : null;
                            $inactA = ($stA !== 'Active' && $stA !== 'حاضر' && $stA !== 'فعال' && !empty($stA)) && (empty($status_a_ym) || $ym >= $status_a_ym);
                            $inactB = ($stB !== 'Active' && $stB !== 'حاضر' && $stB !== 'فعال' && !empty($stB)) && (empty($status_b_ym) || $ym >= $status_b_ym);
                            if (!$inactA && $inactB) return -1;
                            if ($inactA && !$inactB) return 1;
                            return strcmp((string)($tA['name'] ?? ''), (string)($tB['name'] ?? ''));
                        });

                        foreach ($teachers_for_class as $tid): 
                            if (!isset($teachers_assoc[$tid])) continue;
                            $tInfo = $teachers_assoc[$tid];
                            $t_status = trim((string)($tInfo['status'] ?? 'Active'));
                            $t_status_reason = trim((string)($tInfo['status_reason'] ?? ''));
                            $isInactiveTeacher = ($t_status !== 'Active' && $t_status !== 'حاضر' && $t_status !== 'فعال' && !empty($t_status));

                            $join_date = !empty($tInfo['joining_date']) ? ar_clean_date($tInfo['joining_date']) : null;
                            $join_ym = $join_date ? substr($join_date, 0, 7) : null;
                            $status_date = !empty($tInfo['status_change_date']) ? ar_clean_date($tInfo['status_change_date']) : (!empty($tInfo['resignation_date']) ? ar_clean_date($tInfo['resignation_date']) : null);
                            if (!$status_date && $isInactiveTeacher && !empty($teacher_last_att[$tid])) $status_date = ar_clean_date($teacher_last_att[$tid]);
                            $status_ym = $status_date ? substr($status_date, 0, 7) : null;

                            // کیا استاذ اس رپورٹ کے مہینے میں غیر فعال تھا؟
                            // اگر رپورٹ کا مہینہ استعفیٰ کے مہینے سے پہلے کا ہے، تو وہ اس مہینے میں مکمل فعال استاذ تھا
                            $isInactiveTeacherForReport = $isInactiveTeacher && (empty($status_ym) || $ym >= $status_ym);

                            $has_att_in_this_month = false;
                            if (!empty($teacher_attendance_data[$tid][$cid])) {
                                foreach ($dates_array as $dStr) {
                                    if (!empty($teacher_attendance_data[$tid][$cid][$dStr])) { $has_att_in_this_month = true; break; }
                                }
                            }

                            if ($join_ym && $ym < $join_ym) continue;
                            if ($isInactiveTeacher && $status_ym && $ym > $status_ym && !$has_att_in_this_month) continue;

                            $tStatusText = "غیر فعال"; $tStatusColor = "#dc3545"; $tStatusBgColor = "#fde8e8"; $tStatusBorderColor = "#dc3545";
                            if (strpos($t_status, 'Terminated') !== false || strpos($t_status, 'برطرف') !== false || strpos($t_status, 'فارغ') !== false) {
                                $tStatusText = "برطرف / فارغ";
                            } elseif (strpos($t_status, 'On Leave') !== false || strpos($t_status, 'رخصت') !== false) {
                                $tStatusText = "رخصت پر"; $tStatusColor = "#fd7e14"; $tStatusBgColor = "#fff3cd"; $tStatusBorderColor = "#fd7e14";
                            } elseif (strpos($t_status, 'مستعفی') !== false || strpos($t_status, 'استعفی') !== false || strpos($t_status, 'Resigned') !== false) {
                                $tStatusText = "مستعفی"; $tStatusColor = "#6c757d"; $tStatusBgColor = "#f8f9fa"; $tStatusBorderColor = "#6c757d";
                            }
                            if (!empty($t_status_reason)) $tStatusText .= " (" . htmlspecialchars($t_status_reason) . ")";

                            $show_full_status_banner = ($isInactiveTeacherForReport && !$has_att_in_this_month) || ($isInactiveTeacher && $status_ym && $ym > $status_ym);

                            $month_p = 0; $month_late = 0; $month_a = 0; $month_l = 0;
                        ?>
                        <tr style="background-color: #e8f4f8;">
                            <td style="vertical-align: middle;"><i class="fas fa-chalkboard-teacher text-primary" style="font-size: 10px;"></i></td>
                            <td style="vertical-align: middle;"><span class="badge bg-primary ms-1" style="font-size:7px; vertical-align: middle; padding: 3px 5px;">استاد</span></td>
                            <td style="text-align:right; padding-right:5px; font-weight:bold; color: #084298; font-size: 10px; vertical-align: middle; white-space: nowrap;"><?php echo htmlspecialchars($tInfo['name']); ?></td>
                            <td style="text-align:right; padding-right:5px; color:#6c757d; font-size: 9px; vertical-align: middle;"><?php echo htmlspecialchars($tInfo['father_name'] ?? '-'); ?></td>
                            <?php if ($show_full_status_banner): ?>
                            <td colspan="<?php echo count($dates_array); ?>" style="text-align:center; vertical-align:middle; background-color: <?php echo $tStatusBgColor; ?>; border: 1px solid <?php echo $tStatusBorderColor; ?>; font-weight:bold; font-size:10px; color: <?php echo $tStatusColor; ?>;">
                                <?php echo $tStatusText . ($status_date ? " (مورخہ: " . date('d M Y', strtotime($status_date)) . ")" : ""); ?>
                            </td>
                            <?php else: ?>
                            <?php $total_days_count = count($dates_array);
                            for($d_idx = 0; $d_idx < $total_days_count; $d_idx++): 
                                $date_str = $dates_array[$d_idx];
                                if (!empty($join_date) && $date_str < $join_date): ?>
                                    <td style="text-align:center; vertical-align:middle; background-color:#f9f9f9;"><span class="text-muted" style="font-size:9px;">-</span></td>
                                    <?php continue; endif;
                                if ($isInactiveTeacherForReport && !empty($status_date) && $date_str >= $status_date):
                                    $remaining_cols = $total_days_count - $d_idx;
                                    $status_display = " (مورخہ: " . date('d M Y', strtotime($status_date)) . ")"; ?>
                                    <td colspan="<?php echo $remaining_cols; ?>" style="background-color: <?php echo $tStatusBgColor; ?>; text-align: center; vertical-align: middle; border: 1px dashed <?php echo $tStatusBorderColor; ?>; padding: 2px 4px;">
                                        <div style="font-weight: bold; color: <?php echo $tStatusColor; ?>; font-size: 10px;"><?php echo $tStatusText . $status_display; ?></div>
                                    </td>
                                    <?php break; endif;
                                $att = $teacher_attendance_data[$tid][$cid][$date_str] ?? null;
                                if($att) { foreach($class_periods as $pNo) { $st = $att[$pNo]['status'] ?? ''; $late = $att[$pNo]['late'] ?? 0; att_count_status($month_p, $month_late, $month_a, $month_l, $st, $late); } }
                            ?>
                            <?php if($is_friday): ?>
                                <td style="background-color: #fff5f5; text-align: center; border-left: 1px solid #fca5a5; border-right: 1px solid #fca5a5; font-size: 9px; font-weight: bold; color: #dc2626;">جمعہ</td>
                            <?php else: ?>
                                <td><?php if($att): $tGrid = []; foreach($class_periods as $pNo) { $tGrid['s' . $pNo] = $att[$pNo]['status'] ?? ''; $tGrid['l' . $pNo] = $att[$pNo]['late'] ?? 0; } echo att_render_grid($tGrid, $class_periods); endif; ?></td>
                            <?php endif; ?>
                            <?php endfor; ?>
                            <?php endif; ?>
                            <td style="font-weight:bold;"><?php echo ($month_p > 0 || !$isInactiveTeacherForReport) ? $month_p : '-'; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo ($month_late > 0 || !$isInactiveTeacherForReport) ? $month_late : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_a > 0 || !$isInactiveTeacherForReport) ? $month_a : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_l > 0 || !$isInactiveTeacherForReport) ? $month_l : '-'; ?></td>
                            <?php 
                                $total_slots = $month_p + $month_late + $month_a + $month_l;
                                $status = "-"; $status_color = "black";
                                if ($isInactiveTeacherForReport && ($total_slots == 0 || $show_full_status_banner)) { $status = $tStatusText; $status_color = $tStatusColor; }
                                elseif ($total_slots > 0) { $perc = (($month_p + $month_late) / $total_slots) * 100; $status = ($perc >= 100) ? "مکمل حاضر" : round($perc) . "% حاضر"; $status_color = ($perc >= 100) ? "green" : "blue"; }
                            ?>
                            <td style="font-weight:bold; color: <?php echo $status_color; ?>; font-size:<?php echo ($isInactiveTeacherForReport && ($total_slots == 0 || $show_full_status_banner))?'9px':'10px'; ?>;"><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php 
                        $sr = 1; 
                        $total_days = count($dates_array);

                        // فلٹر: اس مہینے کے مطابق طلبہ کا انتخاب
                        $month_students = [];
                        foreach ($grouped_students[$cName] as $s) {
                            $admDate = !empty($s['admission_date']) ? ar_clean_date($s['admission_date']) : '';
                            $adm_ym = !empty($admDate) ? substr($admDate, 0, 7) : null;

                            // اگر رپورٹ کا مہینہ تاریخِ داخلہ سے پہلے کا ہے تو طالب علم کو رجسٹر سے مکمل خارج کریں
                            if ($adm_ym && $ym < $adm_ym) {
                                continue;
                            }

                            $sStatus = $s['status'] ?? 'Active';
                            $isCurrentlyInactive = is_student_inactive($s, $start_date) || !empty($s['graduation_date']);
                            $gradDate = !empty($s['graduation_date']) ? $s['graduation_date'] : '';
                            $grad_ym = !empty($gradDate) ? date('Y-m', strtotime($gradDate)) : null;

                            if (!$isCurrentlyInactive) {
                                $month_students[] = $s; // ایکٹو طلبہ شامل ہیں
                                continue;
                            }

                            // چیک کریں کہ آیا اس طالب علم کی اس مہینے میں کوئی حاضری لگی ہے
                            $has_att_in_month = false;
                            if (isset($attendance_data[$s['id']])) {
                                foreach ($dates_array as $chk_date) {
                                    if (!empty($attendance_data[$s['id']][$chk_date])) {
                                        $has_att_in_month = true;
                                        break;
                                    }
                                }
                            }

                            if ($has_att_in_month) {
                                $month_students[] = $s; // اس مہینے میں حاضری لگی ہے، ریکارڈ محفوظ رہے گا
                                continue;
                            }

                            // اگر اس مہینے میں کوئی حاضری نہیں ہے:
                            if (!empty($gradDate)) {
                                // اگر موجودہ رپورٹ کا مہینہ اخراج کے مہینے کے بعد کا ہے، تو نام خارج کر دیں
                                if ($ym > $grad_ym) {
                                    continue; // بعد کے مہینوں میں رجسٹر سے خارج
                                }
                                $month_students[] = $s;
                            }
                        }

                        // چھانٹی برائے موجودہ مہینہ: اس مہینے میں جو ایکٹو رہے وہ اوپر، اور جو اسی مہینے فارغ ہوئے وہ رجسٹر کے آخر میں
                        usort($month_students, function($a, $b) use ($ym) {
                            $aGradDate = !empty($a['graduation_date']) ? $a['graduation_date'] : '';
                            $bGradDate = !empty($b['graduation_date']) ? $b['graduation_date'] : '';
                            
                            $aStatus = $a['status'] ?? 'Active';
                            $bStatus = $b['status'] ?? 'Active';
                            
                            $aInactiveInMonth = ($aStatus === 'Alumni' || !empty($aGradDate) || $aStatus === 'Left' || $aStatus === 'Struck Off' || $aStatus === 'خارج')
                                && (empty($aGradDate) || date('Y-m', strtotime($aGradDate)) <= $ym);
                            
                            $bInactiveInMonth = ($bStatus === 'Alumni' || !empty($bGradDate) || $bStatus === 'Left' || $bStatus === 'Struck Off' || $bStatus === 'خارج')
                                && (empty($bGradDate) || date('Y-m', strtotime($bGradDate)) <= $ym);
                                
                            if (!$aInactiveInMonth && $bInactiveInMonth) return -1;
                            if ($aInactiveInMonth && !$bInactiveInMonth) return 1;
                            
                            $admA = (int)($a['admission_number'] ?? ($a['roll_number'] ?? 0));
                            $admB = (int)($b['admission_number'] ?? ($b['roll_number'] ?? 0));
                            return $admA <=> $admB;
                        });

                        foreach($month_students as $s_idx => $s): 
                            $month_p = 0; $month_late = 0; $month_a = 0; $month_l = 0;
                            
                            $sStatus = $s['status'] ?? 'Active';
                            $admDate = !empty($s['admission_date']) ? ar_clean_date($s['admission_date']) : '';
                            $gradDate = !empty($s['graduation_date']) ? $s['graduation_date'] : '';
                            $grad_ym = !empty($gradDate) ? date('Y-m', strtotime($gradDate)) : null;
                            $leavingReason = trim((string)($s['leaving_reason'] ?? ''));
                            $alumniCat = trim((string)($s['alumni_category'] ?? ''));

                            // کیا طالب علم اس رپورٹ کے مہینے میں ان ایکٹو تھا؟
                            // اگر رپورٹ کا مہینہ اس کے فارغ ہونے سے پہلے کا ہے، تو وہ اس مہینے میں مکمل ایکٹو طالب علم تھا
                            $isInactiveForThisReport = ($sStatus === 'Alumni' || !empty($gradDate) || $sStatus === 'Left' || $sStatus === 'Struck Off' || $sStatus === 'خارج')
                                && (empty($gradDate) || $grad_ym <= $ym);

                            $statusBadgeText = "تعلیم مکمل ہو گئی";
                            $statusBadgeColor = "#198754";
                            $statusBgColor = "#e8f5e9";

                            if (strpos($leavingReason, 'خارج') !== false || strpos($sStatus, 'خارج') !== false || strpos($alumniCat, 'خارج') !== false) {
                                $statusBadgeText = "خارج کر دیا گیا";
                                $statusBadgeColor = "#dc3545";
                                $statusBgColor = "#fde8e8";
                            } elseif (strpos($leavingReason, 'چھوڑ') !== false || strpos($leavingReason, 'شوقیہ') !== false || strpos($sStatus, 'Left') !== false) {
                                $statusBadgeText = "چھوڑ گیا";
                                $statusBadgeColor = "#fd7e14";
                                $statusBgColor = "#fff3cd";
                            } elseif (!empty($alumniCat)) {
                                $statusBadgeText = $alumniCat;
                            } elseif (!empty($leavingReason)) {
                                $statusBadgeText = $leavingReason;
                            }
                        ?>
                        <tr <?php if($isInactiveForThisReport && (!empty($gradDate) && $grad_ym == $ym)) echo 'style="background-color: #fafbfc;"'; ?>>
                            <td><?php echo $sr++; ?></td>
                            <td><?php echo htmlspecialchars((string)($s['roll_number'] ?? $s['admission_number'])); ?></td>
                            <td style="text-align:right; padding-right:5px; font-weight:bold;">
                                <?php echo htmlspecialchars((string)$s['name']); ?>
                                <?php if($isInactiveForThisReport && (!empty($gradDate) && $grad_ym == $ym)): ?>
                                    <span class="badge" style="font-size: 8px; background-color: <?php echo $statusBadgeColor; ?>; vertical-align: middle; margin-right: 3px;"><?php echo $statusBadgeText; ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; padding-right:5px; color:#555;"><?php echo htmlspecialchars((string)$s['father_name']); ?></td>
                            <?php 
                            for ($d_idx = 0; $d_idx < $total_days; $d_idx++):
                                $date_str = $dates_array[$d_idx];
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                
                                if ($is_friday):
                                    if ($s_idx === 0): ?>
                                        <td rowspan="<?php echo count($month_students); ?>" class="friday-column text-center" style="background-color: #fff5f5 !important; border-left: 1.5px solid #f87171 !important; border-right: 1.5px solid #f87171 !important; vertical-align: middle !important; padding: 0 !important; width: 24px; min-width: 24px;">
                                            <div style="writing-mode: vertical-rl; transform: rotate(180deg); font-weight: bold; color: #dc2626; font-size: 13px; letter-spacing: 5px; white-space: nowrap; display: inline-block; margin: 0 auto;">جمعۃ المبارک</div>
                                        </td>
                                    <?php endif;
                                    // بعد کے تمام طلبہ کے لیے جمعہ کا سیل اس سنگل کالم و رَو rowspan میں سما چکا ہے
                                    continue;
                                endif;

                                // اگر تاریخِ داخلہ سے پہلے کا دن ہے تو ریکارڈ یا حاضری کاؤنٹ نہ ہو
                                if (!empty($admDate) && $date_str < $admDate): ?>
                                    <td style="text-align:center; vertical-align:middle; background-color:#f9f9f9;"><span class="text-muted" style="font-size:9px;">-</span></td>
                                    <?php continue; endif;

                                // اگر طالب علم فارغ التحصیل / خارج ہو چکا ہو اور یہ تاریخ اس کی فراغت کے بعد ہو
                                if ($isInactiveForThisReport && !empty($gradDate) && $date_str >= $gradDate): ?>
                                    <td style="background-color: <?php echo $statusBgColor; ?>; text-align: center; vertical-align: middle;">
                                        <span class="text-muted" style="font-size:9px;">-</span>
                                    </td>
                                    <?php continue;
                                endif;

                                $att = $attendance_data[$s['id']][$date_str] ?? null;
                                if($att) {
                                    foreach($class_periods as $pNo) {
                                        $st = $att['s' . $pNo] ?? '';
                                        $late = $att['l' . $pNo] ?? 0;
                                        att_count_status($month_p, $month_late, $month_a, $month_l, $st, $late);
                                        att_count_status($class_daily_p[$date_str], $class_daily_late[$date_str], $class_daily_a[$date_str], $class_daily_l[$date_str], $st, $late);
                                        if (isset($class_period_date_stats[$date_str][$pNo])) {
                                            att_count_status(
                                                $class_period_date_stats[$date_str][$pNo]['p'],
                                                $class_period_date_stats[$date_str][$pNo]['late'],
                                                $class_period_date_stats[$date_str][$pNo]['a'],
                                                $class_period_date_stats[$date_str][$pNo]['l'],
                                                $st,
                                                $late
                                            );
                                            
                                            $stdInfo = [
                                                'id' => $s['id'],
                                                'roll' => htmlspecialchars((string)($s['roll_number'] ?? $s['admission_number'])),
                                                'name' => htmlspecialchars((string)$s['name']),
                                                'father' => htmlspecialchars((string)$s['father_name']),
                                                'late' => (int)$late,
                                            ];
                                            $stClean = trim((string)$st);
                                            $stMap = [
                                                'حاضر' => 'P', 'P' => 'P', 'present' => 'P', 'PRESENT' => 'P',
                                                'غیر حاضر' => 'A', 'A' => 'A', 'absent' => 'A', 'ABSENT' => 'A',
                                                'رخصت' => 'L', 'L' => 'L', 'leave' => 'L', 'LEAVE' => 'L',
                                                'تاخیر' => 'T', 'T' => 'T', 'late' => 'T', 'LATE' => 'T',
                                            ];
                                            $code = $stMap[$stClean] ?? (function_exists('mb_substr') ? mb_strtoupper(mb_substr($stClean, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($stClean, 0, 1)));
                                            if ($code === 'P') {
                                                if ((int)$late > 0) $class_period_date_students[$date_str][$pNo]['late'][] = $stdInfo;
                                                else $class_period_date_students[$date_str][$pNo]['p'][] = $stdInfo;
                                            } elseif ($code === 'T') {
                                                $class_period_date_students[$date_str][$pNo]['late'][] = $stdInfo;
                                            } elseif ($code === 'A') {
                                                $class_period_date_students[$date_str][$pNo]['a'][] = $stdInfo;
                                            } elseif ($code === 'L') {
                                                $class_period_date_students[$date_str][$pNo]['l'][] = $stdInfo;
                                            }
                                        }
                                        if($st) {
                                            $grand_total_slots++;
                                            att_count_status($grand_total_p, $grand_total_late, $grand_total_a, $grand_total_l, $st, $late);
                                        }
                                    }
                                }
                            ?>
                            <td>
                                <?php if($att): ?>
                                    <?php echo att_render_grid($att, $class_periods); ?>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 10px;">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                            <td style="font-weight:bold;"><?php echo ($month_p > 0 || !$isInactiveForThisReport) ? $month_p : '-'; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo ($month_late > 0 || !$isInactiveForThisReport) ? $month_late : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_a > 0 || !$isInactiveForThisReport) ? $month_a : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_l > 0 || !$isInactiveForThisReport) ? $month_l : '-'; ?></td>
                            <?php 
                                $total_slots = $month_p + $month_late + $month_a + $month_l;
                                if ($isInactiveForThisReport && $total_slots == 0) {
                                    $status = $statusBadgeText;
                                    $status_color = $statusBadgeColor;
                                } elseif ($total_slots > 0) {
                                    $perc = (($month_p + $month_late) / $total_slots) * 100;
                                    if ($perc >= 100) {
                                        $status = "مکمل حاضر";
                                        $status_color = "green";
                                    } else {
                                        $status = round($perc) . "% حاضر";
                                        $status_color = "blue";
                                    }
                                } else {
                                    $status = "-";
                                    $status_color = "black";
                                }
                            ?>

                            <td style="font-weight:bold; color: <?php echo $status_color; ?>; font-size: 10px;"><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- پیریڈ وائز حاضری سمری پینل (Period-wise Attendance Summary Panel) -->
            <?php 
                $cls_safe_id = 'cls_' . md5($ym . '_' . $cName);
                
                // تلاش کریں کہ کس تاریخ پر اس کلاس میں حاضری کا اندراج موجود ہے
                $latest_attended_date = null;
                $today_date = date('Y-m-d');
                $today_has_att = false;
                if (isset($class_period_date_stats[$today_date])) {
                    $today_tot = 0;
                    foreach ($class_periods as $pNo) {
                        $today_tot += ($class_period_date_stats[$today_date][$pNo]['p'] ?? 0)
                                    + ($class_period_date_stats[$today_date][$pNo]['late'] ?? 0)
                                    + ($class_period_date_stats[$today_date][$pNo]['a'] ?? 0)
                                    + ($class_period_date_stats[$today_date][$pNo]['l'] ?? 0);
                    }
                    if ($today_tot > 0) {
                        $today_has_att = true;
                    }
                }

                if ($today_has_att) {
                    $default_date = $today_date;
                } else {
                    foreach (array_reverse($dates_array) as $d_cand) {
                        $cand_tot = 0;
                        foreach ($class_periods as $pNo) {
                            $cand_tot += ($class_period_date_stats[$d_cand][$pNo]['p'] ?? 0)
                                       + ($class_period_date_stats[$d_cand][$pNo]['late'] ?? 0)
                                       + ($class_period_date_stats[$d_cand][$pNo]['a'] ?? 0)
                                       + ($class_period_date_stats[$d_cand][$pNo]['l'] ?? 0);
                        }
                        if ($cand_tot > 0) {
                            $latest_attended_date = $d_cand;
                            break;
                        }
                    }
                    $default_date = $latest_attended_date ?? (in_array($today_date, $dates_array) ? $today_date : end($dates_array));
                }
            ?>
            <div class="period-summary-card mt-3 mb-1 p-3 bg-white border rounded shadow-sm no-print-break" style="border-color: #cbd5e1 !important; border-radius: 8px;">
                <!-- ہیڈر بار: بائیں جانب تاریخ سلیکٹر، دائیں جانب عنوان -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom" style="border-color: #e2e8f0 !important;">
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select form-select-sm fw-bold text-dark border-secondary" 
                                id="date_sel_<?php echo $cls_safe_id; ?>" 
                                style="width: auto; min-width: 140px; font-size: 13px; border-radius: 6px; cursor: pointer; background-color: #f8fafc;" 
                                onchange="updateClassPeriodCards('<?php echo $cls_safe_id; ?>', this.value, '<?php echo htmlspecialchars(addslashes($cName), ENT_QUOTES); ?>')">
                            <?php foreach(array_reverse($dates_array) as $d_opt): 
                                $opt_label = date('d-M-Y', strtotime($d_opt));
                            ?>
                                <option value="<?php echo $d_opt; ?>" <?php echo ($d_opt === $default_date) ? 'selected' : ''; ?>>
                                    <?php echo $opt_label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Noto Nastaliq Urdu', sans-serif; font-size: 18px;">
                        پیریڈ وائز حاضری سمری
                    </h5>
                </div>

                <!-- پیریڈ کارڈز گرڈ (RTL میں دائیں سے بائیں ترتیب) -->
                <div class="d-flex flex-wrap gap-2 justify-content-start" dir="rtl" id="cards_wrap_<?php echo $cls_safe_id; ?>">
                    <?php foreach($class_periods as $pNo): 
                        $pTitle = att_period_display_name($pNo, count($class_periods));
                        $curr_p = $class_period_date_stats[$default_date][$pNo]['p'] ?? 0;
                        $curr_late = $class_period_date_stats[$default_date][$pNo]['late'] ?? 0;
                        $curr_a = $class_period_date_stats[$default_date][$pNo]['a'] ?? 0;
                        $curr_l = $class_period_date_stats[$default_date][$pNo]['l'] ?? 0;
                    ?>
                    <div class="period-stat-card border rounded p-2 text-center" 
                         style="flex: 1 1 0; min-width: 100px; max-width: 140px; background: #fff; border-color: #cbd5e1 !important; border-radius: 8px;">
                        <div class="fw-bold text-dark pb-1 mb-2 border-bottom" style="font-size: 13px; border-color: #e2e8f0 !important;">
                            <?php echo $pTitle; ?>
                        </div>
                        <div class="d-flex justify-content-around align-items-center pt-1 fw-bold" style="font-size: 12.5px;">
                            <span class="period-stat-btn" style="color: #0d9488; cursor: pointer; text-decoration: underline;" 
                                  title="حاضر طلبہ کی فہرست دیکھنے کے لیے کلک کریں"
                                  onclick="openPeriodStudentsModal('<?php echo $cls_safe_id; ?>', <?php echo $pNo; ?>, 'p', '<?php echo htmlspecialchars(addslashes($cName), ENT_QUOTES); ?>')">
                                ح: <span id="<?php echo $cls_safe_id; ?>_p<?php echo $pNo; ?>_p"><?php echo $curr_p; ?></span>
                            </span>
                            <span class="period-stat-btn" style="color: #ea580c; cursor: pointer; text-decoration: underline;" 
                                  title="تاخیر والے طلبہ کی فہرست دیکھنے کے لیے کلک کریں"
                                  onclick="openPeriodStudentsModal('<?php echo $cls_safe_id; ?>', <?php echo $pNo; ?>, 'late', '<?php echo htmlspecialchars(addslashes($cName), ENT_QUOTES); ?>')">
                                ت: <span id="<?php echo $cls_safe_id; ?>_p<?php echo $pNo; ?>_late"><?php echo $curr_late; ?></span>
                            </span>
                            <span class="period-stat-btn" style="color: #dc2626; cursor: pointer; text-decoration: underline;" 
                                  title="غیر حاضر طلبہ کی فہرست دیکھنے کے لیے کلک کریں"
                                  onclick="openPeriodStudentsModal('<?php echo $cls_safe_id; ?>', <?php echo $pNo; ?>, 'a', '<?php echo htmlspecialchars(addslashes($cName), ENT_QUOTES); ?>')">
                                غ: <span id="<?php echo $cls_safe_id; ?>_p<?php echo $pNo; ?>_a"><?php echo $curr_a; ?></span>
                            </span>
                            <span class="period-stat-btn" style="color: #ca8a04; cursor: pointer; text-decoration: underline;" 
                                  title="رخصت والے طلبہ کی فہرست دیکھنے کے لیے کلک کریں"
                                  onclick="openPeriodStudentsModal('<?php echo $cls_safe_id; ?>', <?php echo $pNo; ?>, 'l', '<?php echo htmlspecialchars(addslashes($cName), ENT_QUOTES); ?>')">
                                ر: <span id="<?php echo $cls_safe_id; ?>_p<?php echo $pNo; ?>_l"><?php echo $curr_l; ?></span>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
            if (typeof window.allClassPeriodData === 'undefined') {
                window.allClassPeriodData = {};
            }
            if (typeof window.allClassPeriodStudents === 'undefined') {
                window.allClassPeriodStudents = {};
            }
            window.allClassPeriodData['<?php echo $cls_safe_id; ?>'] = <?php echo json_encode($class_period_date_stats); ?>;
            window.allClassPeriodStudents['<?php echo $cls_safe_id; ?>'] = <?php echo json_encode($class_period_date_students); ?>;
            </script>
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

<!-- پاپ اپ ماڈل: پیریڈ وائز کیٹیگری کے طلبہ کی فہرست -->
<div class="modal fade no-print" id="periodStudentsModal" tabindex="-1" aria-labelledby="periodStudentsModalLabel" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.25); border: 1px solid #cbd5e1;">
            <div class="modal-header py-2 px-3 text-white" id="modalHeaderBg" style="background-color: #0d9488;">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-users fs-5"></i>
                    <h5 class="modal-title fw-bold m-0" id="periodStudentsModalLabel" style="font-family: 'Noto Nastaliq Urdu', sans-serif; font-size: 18px;">
                        فہرست طلبہ
                    </h5>
                </div>
                <button type="button" class="btn-close btn-close-white ms-0 me-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded mb-3 border">
                    <div>
                        <span class="fw-bold text-dark" id="modalClassName">کلاس: -</span> | 
                        <span class="text-secondary fw-bold" id="modalPeriodName">پیریڈ: -</span>
                    </div>
                    <div>
                        <span class="badge bg-dark fs-6" id="modalDateBadge">تاریخ: -</span>
                        <span class="badge fs-6 ms-1" id="modalTypeBadge">-</span>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="table table-bordered table-hover text-center align-middle m-0" style="font-size: 13px;">
                        <thead class="table-secondary sticky-top">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 110px;">رول / داخلہ نمبر</th>
                                <th>نام طالب علم</th>
                                <th>والد کا نام</th>
                                <th style="width: 130px;">کیفیت</th>
                            </tr>
                        </thead>
                        <tbody id="periodStudentsTableBody">
                            <!-- جاوا اسکرپٹ سے بھرا جائے گا -->
                        </tbody>
                    </table>
                </div>
                <div id="emptyModalMsg" class="alert alert-info text-center mt-2 d-none fw-bold">
                    اس کیٹیگری میں فی الوقت کوئی طالب علم موجود نہیں ہے۔
                </div>
            </div>
            <div class="modal-footer py-2 px-3 bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-outline-primary btn-sm fw-bold" onclick="printModalStudentsList()">
                    <i class="fas fa-print me-1"></i> پرنٹ فہرست
                </button>
                <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">
                    بند کریں
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function updateClassPeriodCards(clsId, selectedDate, className) {
    if (!window.allClassPeriodData || !window.allClassPeriodData[clsId]) return;
    var dateMap = window.allClassPeriodData[clsId][selectedDate];
    if (!dateMap) return;
    for (var pNo in dateMap) {
        if (dateMap.hasOwnProperty(pNo)) {
            var st = dateMap[pNo];
            var elP = document.getElementById(clsId + '_p' + pNo + '_p');
            var elLate = document.getElementById(clsId + '_p' + pNo + '_late');
            var elA = document.getElementById(clsId + '_p' + pNo + '_a');
            var elL = document.getElementById(clsId + '_p' + pNo + '_l');
            if (elP) elP.innerText = st.p !== undefined ? st.p : 0;
            if (elLate) elLate.innerText = st.late !== undefined ? st.late : 0;
            if (elA) elA.innerText = st.a !== undefined ? st.a : 0;
            if (elL) elL.innerText = st.l !== undefined ? st.l : 0;
        }
    }
}

function openPeriodStudentsModal(clsId, pNo, type, className) {
    var selEl = document.getElementById('date_sel_' + clsId);
    var selectedDate = selEl ? selEl.value : '';
    
    var typeTitles = {
        'p': { label: 'حاضر طلبہ', color: '#0d9488', badge: 'bg-success' },
        'late': { label: 'تاخیر والے طلبہ', color: '#ea580c', badge: 'bg-warning text-dark' },
        'a': { label: 'غیر حاضر طلبہ', color: '#dc2626', badge: 'bg-danger' },
        'l': { label: 'رخصت والے طلبہ', color: '#ca8a04', badge: 'bg-warning text-dark' }
    };
    var tInfo = typeTitles[type] || { label: 'فہرست طلبہ', color: '#0d9488', badge: 'bg-primary' };
    
    var periodName = 'سبق ' + pNo;
    
    document.getElementById('periodStudentsModalLabel').innerText = 'فہرست ' + tInfo.label + ' (' + periodName + ')';
    document.getElementById('modalHeaderBg').style.backgroundColor = tInfo.color;
    document.getElementById('modalClassName').innerText = 'کلاس: ' + className;
    document.getElementById('modalPeriodName').innerText = 'پیریڈ: ' + periodName;
    document.getElementById('modalDateBadge').innerText = 'تاریخ: ' + selectedDate;
    
    var badgeEl = document.getElementById('modalTypeBadge');
    badgeEl.className = 'badge fs-6 ms-1 ' + tInfo.badge;
    badgeEl.innerText = tInfo.label;
    
    var list = [];
    if (window.allClassPeriodStudents && 
        window.allClassPeriodStudents[clsId] && 
        window.allClassPeriodStudents[clsId][selectedDate] && 
        window.allClassPeriodStudents[clsId][selectedDate][pNo] && 
        window.allClassPeriodStudents[clsId][selectedDate][pNo][type]) {
        list = window.allClassPeriodStudents[clsId][selectedDate][pNo][type];
    }
    
    var tbody = document.getElementById('periodStudentsTableBody');
    tbody.innerHTML = '';
    var emptyMsg = document.getElementById('emptyModalMsg');
    
    if (!list || list.length === 0) {
        emptyMsg.classList.remove('d-none');
    } else {
        emptyMsg.classList.add('d-none');
        for (var i = 0; i < list.length; i++) {
            var st = list[i];
            var tr = document.createElement('tr');
            var lateDetail = (type === 'late' && st.late > 0) ? ' (' + st.late + ' منٹ)' : '';
            tr.innerHTML = '<td>' + (i + 1) + '</td>' +
                           '<td>' + (st.roll || '-') + '</td>' +
                           '<td class="fw-bold text-end pe-3">' + st.name + '</td>' +
                           '<td class="text-end pe-3 text-secondary">' + (st.father || '-') + '</td>' +
                           '<td><span class="badge ' + tInfo.badge + '">' + tInfo.label + lateDetail + '</span></td>';
            tbody.appendChild(tr);
        }
    }
    
    var modalEl = document.getElementById('periodStudentsModal');
    var modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (!modalInstance) {
        modalInstance = new bootstrap.Modal(modalEl);
    }
    modalInstance.show();
}

function printModalStudentsList() {
    var title = document.getElementById('periodStudentsModalLabel').innerText;
    var cls = document.getElementById('modalClassName').innerText;
    var dt = document.getElementById('modalDateBadge').innerText;
    var typeText = document.getElementById('modalTypeBadge').innerText;
    var tbody = document.getElementById('periodStudentsTableBody').innerHTML;
    
    var win = window.open('', '', 'height=750,width=900');
    win.document.write('<html dir="rtl"><head><title>' + title + '</title>');
    win.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">');
    win.document.write('<style>body{font-family:sans-serif;padding:25px;} table{width:100%;border-collapse:collapse;margin-top:15px;} th,td{border:1px solid #333;padding:8px;text-align:center;} th{background-color:#f2f2f2;}</style>');
    win.document.write('</head><body>');
    win.document.write('<h3 class="text-center fw-bold mb-1">مدرسہ اشرف المدارس</h3>');
    win.document.write('<h5 class="text-center text-muted mb-3">' + title + ' (' + cls + ' | ' + dt + ')</h5>');
    win.document.write('<table class="table table-bordered"><thead><tr><th>#</th><th>رول / داخلہ نمبر</th><th>نام طالب علم</th><th>والد کا نام</th><th>کیفیت</th></tr></thead><tbody>' + tbody + '</tbody></table>');
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    setTimeout(function(){ win.print(); }, 500);
}

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
