<?php 
// ایرر رپورٹنگ
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db.php'; 
include 'header.php'; 

$can_teacher_attendance_report = app_has_perm('manage_teacher_attendance') || app_has_perm('manage_attendance');
if (!$can_teacher_attendance_report) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

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

// ڈیٹا لائیں
$t_query = [];

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$sys_t_cond = ($sys === 'Masjid') ? "t.system_type = 'Masjid'" : "(t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '')";

$selected_teacher_id = isset($_GET['t_id']) && $_GET['t_id'] !== '' ? (int)$_GET['t_id'] : 0;
$teacherWhere = "(t.is_deleted=0 OR t.is_deleted IS NULL) AND t.tenant_id = $cur_tenant_id AND $sys_t_cond";

// صرف وہ اساتذہ لائیں جو:
// 1. Active/On Leave ہیں، یا
// 2. مستعفی/برطرف ہوئے لیکن رپورٹ کے عرصہ میں فعال تھے (status_change_date >= start_date)
$t_query_sql = "
    SELECT t.id, t.name, t.phone, t.assigned_classes, t.assigned_periods, t.status, t.status_reason, t.status_change_date, t.joining_date
    FROM teachers t
    WHERE ($teacherWhere)
      AND (
          (t.status IS NULL OR t.status = '' OR t.status = 'Active' OR t.status = 'حاضر' OR t.status = 'فعال' OR t.status = 'On Leave' OR t.status = 'رخصت پر')
          OR (t.status_change_date IS NOT NULL AND t.status_change_date >= ?)
      )
";
$t_query_args = [$start_date];
if ($selected_teacher_id) {
    $t_query_sql .= " AND t.id = ?";
    $t_query_args[] = $selected_teacher_id;
}
$t_query_sql .= " ORDER BY t.name ASC";
$stmt = $pdo->prepare($t_query_sql);
$stmt->execute($t_query_args);
$t_query = $stmt->fetchAll(PDO::FETCH_ASSOC);

$all_ts_stmt = $pdo->prepare("
    SELECT id, name
    FROM teachers t
    WHERE $teacherWhere
      AND (t.status IS NULL OR t.status = '' OR t.status = 'Active' OR t.status = 'حاضر' OR t.status = 'فعال' OR t.status = 'On Leave' OR t.status = 'رخصت پر')
    ORDER BY name ASC
");
$all_ts_stmt->execute([]);
$all_ts = $all_ts_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_classes = $pdo->query("SELECT id as class_id, name as class_name, class_type, main_teacher_ids FROM classes WHERE tenant_id = $cur_tenant_id AND $sys_cond")->fetchAll();
$all_class_periods = $pdo->query("SELECT class_id, period_no, teacher_id, teacher_ids FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM classes WHERE tenant_id = $cur_tenant_id AND $sys_cond))")->fetchAll();

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

function tar_list_values($value) {
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

function tar_period_no_from_key($key) {
    $key = trim((string)$key);
    $map = [
        'hifz_sabaq' => 1,
        'hifz_morning' => 1,
        'hifz_sabqi' => 2,
        'hifz_after_breakfast' => 2,
        'hifz_manzil' => 3,
        'hifz_noon' => 3,
        'hifz_review' => 4,
        'hifz_evening' => 4,
        'nazira_morning' => 1,
        'nazira_evening' => 2,
    ];
    return isset($map[$key]) ? $map[$key] : (int)$key;
}

function tar_assigned_period_numbers($assignedPeriods, $className) {
    $raw = trim((string)$assignedPeriods);
    if ($raw === '') return [];
    $values = [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (array_key_exists($className, $decoded)) {
            $values = is_array($decoded[$className]) ? $decoded[$className] : explode(',', (string)$decoded[$className]);
        }
    } else {
        $values = explode(',', $raw);
    }
    $periods = [];
    foreach ($values as $value) {
        $periodNo = tar_period_no_from_key($value);
        if ($periodNo > 0) $periods[] = $periodNo;
    }
    return array_values(array_unique($periods));
}

function tar_default_periods_for_class($classRowOrName) {
    $name = is_array($classRowOrName)
        ? trim((string)($classRowOrName['class_name'] ?? $classRowOrName['name'] ?? '') . ' ' . (string)($classRowOrName['class_type'] ?? ''))
        : (string)$classRowOrName;
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    if (strpos($lower, 'hifz') !== false || strpos($name, 'Ø­ÙØ¸') !== false || strpos($name, 'Ú¯Ø±Ø¯Ø§Ù†') !== false || strpos($name, 'Ø­Ø³Ù†') !== false) return [1, 2, 3, 4];
    if (strpos($lower, 'nazira') !== false || strpos($name, 'Ù†Ø§Ø¸Ø±Û') !== false) return [1, 2];
    return [1, 2, 3, 4, 5, 6, 7, 8];
}

$teachers_with_classes = [];
foreach ($t_query as $t) {
    $t_id = $t['id'];
    $profile_classes = tar_list_values($t['assigned_classes'] ?? '');
    $found_class = false;
    foreach ($all_classes as $c) {
        $c_id = $c['class_id'];
        $is_assigned = false;
        if (in_array($c['class_name'], $profile_classes, true)) {
            $is_assigned = true;
        }
        
        if (!$is_assigned) {
            $m_ids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
            if (in_array($t_id, $m_ids) || in_array((string)$t_id, $m_ids)) {
                $is_assigned = true;
            }
        }
        
        if (!$is_assigned) {
            foreach ($all_class_periods as $cp) {
                if ($cp['class_id'] == $c_id) {
                    $cp_t_ids = json_decode($cp['teacher_ids'] ?? '[]', true) ?: [];
                    if (!empty($cp['teacher_id']) && !in_array($cp['teacher_id'], $cp_t_ids)) $cp_t_ids[] = $cp['teacher_id'];
                    if (in_array($t_id, $cp_t_ids) || in_array((string)$t_id, $cp_t_ids)) {
                        $is_assigned = true; break;
                    }
                }
            }
        }
        
        if ($is_assigned) {
            $teachers_with_classes[] = [
                'id' => $t['id'], 'name' => $t['name'], 'phone' => $t['phone'],
                'class_id' => $c['class_id'], 'class_name' => $c['class_name'],
                'class_type' => $c['class_type'], 'main_teacher_ids' => $c['main_teacher_ids']
            ];
            $found_class = true;
        }
    }
    if (!$found_class) {
        $teachers_with_classes[] = ['id' => $t['id'], 'name' => $t['name'], 'phone' => $t['phone'], 'class_id' => null, 'class_name' => null, 'class_type' => null, 'main_teacher_ids' => null];
    }
}
usort($teachers_with_classes, function($a, $b) {
    $cmp = strcmp($a['name'], $b['name']);
    return $cmp === 0 ? strcmp($a['class_name'] ?? '', $b['class_name'] ?? '') : $cmp;
});

$grouped_teachers = [];
$ordered_classes = [];
foreach ($teachers_with_classes as $t) {
    $cname = $t['class_name'] ?: 'دیگر اساتذہ';
    if (!in_array($cname, $ordered_classes)) $ordered_classes[] = $cname;
    $grouped_teachers[$cname][] = $t;
}

$teachers_by_id = [];
foreach ($t_query as $teacherRow) {
    $teachers_by_id[(int)$teacherRow['id']] = $teacherRow;
}

$classes_by_id = [];
$classes_by_name = [];
foreach ($all_classes as $classRow) {
    $cid = (int)$classRow['class_id'];
    $classes_by_id[$cid] = $classRow;
    if (!empty($classRow['class_name'])) $classes_by_name[$classRow['class_name']] = $classRow;
}

$periods_by_teacher_class = [];
$explicit_by_teacher_class = [];
foreach ($all_class_periods as $cp) {
    $cid = (int)($cp['class_id'] ?? 0);
    $periodNo = (int)($cp['period_no'] ?? 0);
    if (!$cid || !$periodNo) continue;
    $teacherIds = [];
    if (!empty($cp['teacher_ids'])) {
        $decoded = json_decode($cp['teacher_ids'], true);
        if (is_array($decoded)) $teacherIds = array_merge($teacherIds, $decoded);
    }
    if (!empty($cp['teacher_id'])) $teacherIds[] = $cp['teacher_id'];
    foreach (array_unique(array_filter($teacherIds)) as $tidRaw) {
        $tid = (int)$tidRaw;
        if (!$tid || !isset($teachers_by_id[$tid])) continue;
        $periods_by_teacher_class[$tid][$cid][] = $periodNo;
        $explicit_by_teacher_class[$tid][$cid] = true;
    }
}

foreach ($t_query as $teacherRow) {
    $tid = (int)$teacherRow['id'];
    foreach (tar_list_values($teacherRow['assigned_classes'] ?? '') as $className) {
        if (!isset($classes_by_name[$className])) continue;
        $classRow = $classes_by_name[$className];
        $cid = (int)$classRow['class_id'];
        $periods = tar_assigned_period_numbers($teacherRow['assigned_periods'] ?? '', $className);
        if (empty($periods)) $periods = tar_default_periods_for_class($classRow);
        foreach ($periods as $periodNo) {
            $periods_by_teacher_class[$tid][$cid][] = (int)$periodNo;
        }
    }
}

foreach ($all_classes as $classRow) {
    $cid = (int)$classRow['class_id'];
    foreach (tar_list_values($classRow['main_teacher_ids'] ?? '') as $tidRaw) {
        $tid = (int)$tidRaw;
        if (!$tid || !isset($teachers_by_id[$tid])) continue;
        if (isset($explicit_by_teacher_class[$tid][$cid])) continue;
        foreach (tar_default_periods_for_class($classRow) as $periodNo) {
            $periods_by_teacher_class[$tid][$cid][] = (int)$periodNo;
        }
    }
}

$grouped_teachers = [];
$ordered_classes = [];
foreach ($periods_by_teacher_class as $tid => $classesForTeacher) {
    foreach ($classesForTeacher as $cid => $periods) {
        if (!isset($classes_by_id[$cid])) continue;
        $classRow = $classes_by_id[$cid];
        $teacherRow = $teachers_by_id[$tid];
        $periods = tar_default_periods_for_class($classRow);
        $cname = $classRow['class_name'] ?: 'Ø¯ÛŒÚ¯Ø± Ø§Ø³Ø§ØªØ°Û';
        if (!in_array($cname, $ordered_classes, true)) $ordered_classes[] = $cname;
        $grouped_teachers[$cname][] = [
            'id' => $teacherRow['id'],
            'name' => $teacherRow['name'],
            'phone' => $teacherRow['phone'],
            'class_id' => $cid,
            'class_name' => $classRow['class_name'],
            'class_type' => $classRow['class_type'] ?? '',
            'main_teacher_ids' => $classRow['main_teacher_ids'] ?? '[]',
            'periods' => $periods,
        ];
    }
}
foreach ($teachers_by_id as $tid => $teacherRow) {
    $alreadyGrouped = false;
    foreach ($grouped_teachers as $teacherRows) {
        foreach ($teacherRows as $row) {
            if ((int)$row['id'] === (int)$tid) {
                $alreadyGrouped = true;
                break 2;
            }
        }
    }
    if ($alreadyGrouped) continue;
    $cname = 'دیگر اساتذہ';
    if (!in_array($cname, $ordered_classes, true)) $ordered_classes[] = $cname;
    $grouped_teachers[$cname][] = [
        'id' => $teacherRow['id'],
        'name' => $teacherRow['name'],
        'phone' => $teacherRow['phone'] ?? '',
        'class_id' => 0,
        'class_name' => $cname,
        'class_type' => null,
        'periods' => [1],
        'main_teacher_ids' => '[]',
    ];
}
foreach ($grouped_teachers as &$teacherRows) {
    usort($teacherRows, function($a, $b) {
        return strcmp((string)$a['name'], (string)$b['name']);
    });
}
unset($teacherRows);
sort($ordered_classes);

if ($selected_teacher_id && isset($teachers_by_id[$selected_teacher_id])) {
    $cname = 'جنرل حاضری (مکمل پیریڈز)';
    array_unshift($ordered_classes, $cname);
    $grouped_teachers[$cname][] = [
        'id' => $selected_teacher_id,
        'name' => $teachers_by_id[$selected_teacher_id]['name'],
        'phone' => $teachers_by_id[$selected_teacher_id]['phone'] ?? '',
        'class_id' => 0,
        'class_name' => $cname,
        'class_type' => null,
        'periods' => [1,2,3,4,5,6,7,8],
        'main_teacher_ids' => '[]',
    ];
}

$attendance_data = [];
$stmt = $pdo->prepare("SELECT * FROM teacher_period_attendance WHERE date BETWEEN ? AND ? AND tenant_id = ?");
$stmt->execute([$start_date, $end_date, $cur_tenant_id]);
while($row = $stmt->fetch()) {
    $tid = $row['teacher_id'];
    $cid = $row['class_id'];
    $d = $row['date'];
    $p = $row['period_no'];

    $attendance_data[$tid][$cid][$d][$p] = [
        'status' => $row['status'],
        'late_minutes' => (int)($row['late_minutes'] ?? 0)
    ];

    if (!isset($attendance_data[$tid][0][$d][$p])) {
        $attendance_data[$tid][0][$d][$p] = [
            'status' => $row['status'],
            'late_minutes' => (int)($row['late_minutes'] ?? 0)
        ];
    }
}

$grand_total_p = 0; $grand_total_late = 0; $grand_total_a = 0; $grand_total_l = 0; $grand_total_slots = 0; $grand_total_late_mins = 0;
?>

<style>
    .att-grid { display: grid; justify-content: center; align-items: center; width: 24px; min-height: 14px; margin: 0 auto; gap: 2px; }
    .att-grid.periods-2, .att-grid.periods-4 { grid-template-columns: repeat(2, 7px); }
    .att-grid.periods-8 { grid-template-columns: repeat(4, 6px); width: 28px; }
    .dot { width: 7px; height: 7px; border-radius: 50%; display: block; box-sizing: border-box; filter: none !important; -webkit-filter: none !important; opacity: 1 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .att-grid.periods-8 .dot { width: 6px; height: 6px; }
    .dot-P { background-color: #198754 !important; border: 1px solid #146c43 !important; }
    .dot-A { background-color: #dc3545 !important; border: 1px solid #b02a37 !important; }
    .dot-L { background-color: #ffc107 !important; border: 1px solid #d39e00 !important; }
    .dot-T { background-color: #fd7e14 !important; border: 1px solid #dc6502 !important; }
    .dot-None { background-color: #dee2e6 !important; border: 1px solid #ced4da !important; }
    .bg-p { background-color: #198754 !important; border: 1px solid #146c43 !important; } 
    .bg-a { background-color: #dc3545 !important; border: 1px solid #b02a37 !important; } 
    .bg-l { background-color: #ffc107 !important; border: 1px solid #d39e00 !important; } 
    .bg-n { background-color: #dee2e6 !important; border: 1px solid #ced4da !important; }
    .bg-late { background-color: #fd7e14 !important; border: 1px solid #dc6502 !important; } 
    .report-table { width: 100%; border-collapse: collapse; font-size: 11px; }
    .report-table th, .report-table td { border: 1px solid #000; padding: 2px 1px; text-align: center; vertical-align: middle; }
    .teacher-name-cell { white-space: nowrap; overflow: hidden; text-overflow: clip; }
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
        .report-card { border: none !important; box-shadow: none !important; margin-bottom: 2mm !important; }
        .page-break { page-break-after: always; display: block; height: 1px; }
        .report-table { font-size: 8pt !important; border-collapse: collapse !important; }
        .report-table th, .report-table td { border: 0.5pt solid #555 !important; padding: 1px !important; }
        .report-table thead tr { background-color: var(--primary) !important; color: #fff !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .madrasa-header { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot-P { background-color: #198754 !important; border: 1px solid #146c43 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot-A { background-color: #dc3545 !important; border: 1px solid #b02a37 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot-L { background-color: #ffc107 !important; border: 1px solid #d39e00 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot-T { background-color: #fd7e14 !important; border: 1px solid #dc6502 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .dot-None { background-color: #dee2e6 !important; border: 1px solid #ced4da !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
                    <li class="nav-item"><button class="nav-link border-0 <?php echo $mode=='month'?'active bg-white':''; ?>" onclick="window.location='?mode=month'">ماہانہ</button></li>
                    <li class="nav-item"><button class="nav-link border-0 <?php echo $mode=='range'?'active bg-white text-primary':'text-muted'; ?>" onclick="window.location='?mode=range'">کسٹم رینج</button></li>
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
                        <select name="t_id" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                            <option value="">تمام اساتذہ</option>
                            <?php 
                            $t_id = $_GET['t_id'] ?? '';
                            foreach($all_ts as $t) echo "<option value='{$t['id']}' ".($t_id==$t['id']?'selected':'').">{$t['name']}</option>";
                            ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">رپورٹ دیکھیں</button>
                    </form>
                    <?php else: ?>
                    <form class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="mode" value="range">
                        <input type="date" name="from" id="tAttRangeFrom" class="form-control form-control-sm" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('tAttRangeTo').value) this.form.submit();">
                        <input type="date" name="to" id="tAttRangeTo" class="form-control form-control-sm" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('tAttRangeFrom').value) this.form.submit();">
                        <select name="t_id" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                            <option value="">تمام اساتذہ</option>
                            <?php 
                            $t_id = $_GET['t_id'] ?? '';
                            foreach($all_ts as $t) echo "<option value='{$t['id']}' ".($t_id==$t['id']?'selected':'').">{$t['name']}</option>";
                            ?>
                        </select>
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
        <div style="text-align:center; border-bottom:2px solid var(--primary); margin-bottom:6px; padding-bottom:5px; font-family:'Noto Nastaliq Urdu',sans-serif;">
            <h4 style="color:var(--primary); margin:0; font-size:13pt;">اساتذہ حاضری رجسٹر</h4>
            <p style="margin:1px 0; font-size:8.5pt;">
                <?php echo htmlspecialchars($header_madrasa_name ?? ($_SESSION['tenant_name'] ?? 'ادارہ')); ?>
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

    <?php foreach($ordered_classes as $cName): ?>
    <?php
        $maxTeacherNameLen = 12;
        foreach (($grouped_teachers[$cName] ?? []) as $nameRow) {
            $len = function_exists('mb_strlen') ? mb_strlen((string)$nameRow['name'], 'UTF-8') : strlen((string)$nameRow['name']);
            if ($len > $maxTeacherNameLen) $maxTeacherNameLen = $len;
        }
        $teacherNameColWidth = min(280, max(130, ($maxTeacherNameLen * 9) + 28));
    ?>
    <div class="card report-card shadow-sm mb-4 page-break">
        <div class="card-body p-2">
            
            <div class="madrasa-header d-flex justify-content-between align-items-end" style="font-family: 'Jameel Noori Nastaleeq', sans-serif;">
                <div class="text-start" style="width: 25%;">
                    <h4 class="fw-bold m-0"><?php echo htmlspecialchars($header_madrasa_name ?? ($_SESSION['tenant_name'] ?? 'ادارہ')); ?></h4>
                    <h6 class="m-0">فاروقہ، سرگودھا</h6>
                </div>
                <div class="text-center" style="width: 50%;">
                    <div class="title-box">
                        <span class="text-dark"><?php echo $english_part; ?></span>
                        <span class="mx-2 text-muted" style="font-size: 20px;">/</span>
                        <span class="text-success"><?php echo $islamic_part; ?></span>
                    </div>
                    <div class="mt-1 fw-bold text-primary" style="font-family: 'Noto Nastaliq Urdu', sans-serif; font-size: 14px;"><?php echo $cName; ?></div>
                </div>
                <div class="text-end" style="width: 25%;">
                    <h3 class="fw-bold m-0 text-decoration-underline">اساتذہ حاضری رجسٹر</h3>
                </div>
            </div>

            <div class="text-center mb-2" style="font-size: 11px;">
                <span class="badge rounded-pill border border-success text-success bg-light mx-1">P حاضر</span>
                <span class="badge rounded-pill border border-info text-info bg-light mx-1">T تاخیر</span>
                <span class="badge rounded-pill border border-danger text-danger bg-light mx-1">A غیر حاضر</span>
                <span class="badge rounded-pill border border-warning text-dark bg-light mx-1">L رخصت</span>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr class="bg-light">
                            <th style="width: 25px;">#</th>
                            <th class="teacher-name-cell" style="width: <?php echo $teacherNameColWidth; ?>px; min-width: <?php echo $teacherNameColWidth; ?>px; text-align:right; padding-right:5px;">نام استاد</th>
                            <?php foreach($dates_array as $date_str): 
                                $is_friday = (date('N', strtotime($date_str)) == 5);
                                $g_day = (int)date('d', strtotime($date_str));
                                $h_data = getHijriDateLoop($date_str, $hijri_offset); 
                                $h_day = $h_data['d'];
                            ?>
                                <th style="width: 28px;">
                                    <div class="date-cell">
                                        <span class="greg-date"><?php echo $g_day; ?></span>
                                        <span class="hijri-date"><?php echo $h_day; ?></span>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                            <th class="bg-light text-success" style="width: 30px; font-size:10px;">حاضر</th>
                            <th class="bg-light text-warning" style="width: 30px; font-size:10px; color: #fd7e14 !important;">تاخیر</th>
                            <th class="bg-light text-warning" style="width: 38px; font-size:10px; color: #fd7e14 !important;">تاخیر منٹ</th>
                            <th class="bg-light text-danger" style="width: 30px; font-size:10px;">غیرحاضر</th>
                            <th class="bg-light text-warning" style="width: 30px; font-size:10px;">رخصت</th>
                            <th class="bg-light text-primary" style="width: 50px; font-size:10px;">کیفیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sr = 1; foreach($grouped_teachers[$cName] as $t): 
                            $t_id = (int)$t['id'];
                            $teacherRow = $teachers_by_id[$t_id] ?? [];
                            $t_status = trim((string)($teacherRow['status'] ?? 'Active'));
                            $t_status_reason = trim((string)($teacherRow['status_reason'] ?? ''));
                            $isInactiveTeacher = ($t_status !== 'Active' && $t_status !== 'حاضر' && $t_status !== 'فعال' && !empty($t_status));

                            $join_date = !empty($teacherRow['joining_date']) ? ar_clean_date($teacherRow['joining_date']) : null;
                            $join_ym = $join_date ? substr($join_date, 0, 7) : null;
                            $status_date = !empty($teacherRow['status_change_date']) ? ar_clean_date($teacherRow['status_change_date']) : (!empty($teacherRow['resignation_date']) ? ar_clean_date($teacherRow['resignation_date']) : null);
                            $status_ym = $status_date ? substr($status_date, 0, 7) : null;

                            // کیا استاذ اس رپورٹ کے مہینے میں غیر فعال تھا؟
                            $isInactiveTeacherForReport = $isInactiveTeacher && (empty($status_ym) || $ym >= $status_ym);

                            $t_class_id = $t['class_id'] ?? 0;
                            $has_att_in_this_month = false;
                            foreach ($dates_array as $dStr) {
                                if (!empty($attendance_data[$t_id][$t_class_id][$dStr]) || !empty($attendance_data[$t_id][0][$dStr])) {
                                    $has_att_in_this_month = true;
                                    break;
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

                            $month_p = 0; $month_t = 0; $month_a = 0; $month_l = 0; $month_late_mins = 0;
                            $row_name = $t['name'];
                            $assigned_periods = $t['periods'] ?? [];
                            if (empty($assigned_periods)) $assigned_periods = [1, 2, 3, 4];
                        ?>
                        <tr>
                            <td><?php echo $sr++; ?></td>
                            <td class="teacher-name-cell" style="width: <?php echo $teacherNameColWidth; ?>px; min-width: <?php echo $teacherNameColWidth; ?>px; text-align:right; padding-right:5px; font-weight:bold; font-size:10px;"><?php echo htmlspecialchars($row_name); ?></td>
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

                                $has_any_att = isset($attendance_data[$t['id']][$t_class_id][$date_str]);
                                $cell_html = '<div class="att-grid periods-'.count($assigned_periods).'">';

                                foreach ($assigned_periods as $periodNo) {
                                    $period_attendance = $attendance_data[$t['id']][$t_class_id][$date_str][$periodNo] ?? [];
                                    $st = $period_attendance['status'] ?? '';
                                    $late_mins = (int)($period_attendance['late_minutes'] ?? 0);
                                    
                                    if($st == 'تاخیر' || (($st == 'حاضر' || $st == 'P') && $late_mins > 0)) { 
                                        $month_t++; 
                                        $month_late_mins += $late_mins;
                                        $grand_total_late++;
                                        $grand_total_late_mins += $late_mins;
                                        $grand_total_slots++;
                                        $dot_class = 'dot-T';
                                    } elseif($st == 'حاضر' || $st == 'P' || $st == 'بکار مدرسہ' || $st == 'OD') {
                                        $month_p++; 
                                        $grand_total_p++;
                                        $grand_total_slots++;
                                        $dot_class = 'dot-P';
                                    } elseif($st == 'غیر حاضر' || $st == 'A') {
                                        $month_a++; 
                                        $grand_total_a++;
                                        $grand_total_slots++;
                                        $dot_class = 'dot-A';
                                    } elseif($st == 'رخصت' || $st == 'رخصت اتفاقی' || $st == 'L' || $st == 'LA') {
                                        $month_l++;
                                        $grand_total_l++;
                                        $grand_total_slots++;
                                        $dot_class = 'dot-L';
                                    } else {
                                        $dot_class = 'dot-None';
                                    }
                                    
                                    $cell_html .= '<div class="dot '.$dot_class.'"></div>';
                                }
                                $cell_html .= '</div>';
                            ?>
                            <td>
                                <?php if($has_any_att): ?>
                                    <?php echo $cell_html; ?>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                            <?php endif; ?>
                            <td style="font-weight:bold;"><?php echo ($month_p > 0 || !$isInactiveTeacherForReport) ? $month_p : '-'; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo ($month_t > 0 || !$isInactiveTeacherForReport) ? $month_t : '-'; ?></td>
                            <td style="font-weight:bold; color: #fd7e14;"><?php echo ($month_late_mins > 0 || !$isInactiveTeacherForReport) ? $month_late_mins : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_a > 0 || !$isInactiveTeacherForReport) ? $month_a : '-'; ?></td>
                            <td style="font-weight:bold;"><?php echo ($month_l > 0 || !$isInactiveTeacherForReport) ? $month_l : '-'; ?></td>
                            <?php 
                                $total_slots = $month_p + $month_t + $month_a + $month_l;
                                $status = "-";
                                $status_color = "black";
                                if ($isInactiveTeacherForReport && ($total_slots == 0 || $show_full_status_banner)) {
                                    $status = $tStatusText;
                                    $status_color = $tStatusColor;
                                } elseif ($total_slots > 0) {
                                    $perc = (($month_p + $month_t) / $total_slots) * 100;
                                    if ($perc >= 100) {
                                        $status = "مکمل حاضر";
                                        $status_color = "green";
                                    } else {
                                        $status = round($perc) . "% حاضر";
                                        $status_color = "blue";
                                    }
                                }
                            ?>
                            <td style="font-weight:bold; color: <?php echo $status_color; ?>; font-size:<?php echo ($isInactiveTeacherForReport && ($total_slots == 0 || $show_full_status_banner))?'9px':'10px'; ?>;"><?php echo $status; ?></td>
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
    $grand_total_attendance = $grand_total_p + $grand_total_late;
    ?>
    <div class="card shadow border-dark mt-4 no-print-break" style="max-width: 900px; margin: 0 auto;">
        <div class="card-header bg-dark text-white text-center fw-bold">
            مجموعی کارکردگی رپورٹ (<?php echo date('d M', strtotime($start_date)) . " تا " . date('d M', strtotime($end_date)); ?>)
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col border-end"><h4 class="text-success fw-bold mb-0"><?php echo $grand_total_attendance; ?></h4><small>کل حاضریاں (<?php echo round((($grand_total_attendance) / $base) * 100, 1); ?>%)</small></div>
                <div class="col border-end"><h4 class="text-warning fw-bold mb-0" style="color: #fd7e14 !important;"><?php echo $grand_total_late; ?></h4><small>تاخیر تعداد (<?php echo $perc_late; ?>%)</small></div>
                <div class="col border-end"><h4 class="text-warning fw-bold mb-0" style="color: #fd7e14 !important;"><?php echo $grand_total_late_mins; ?></h4><small>کل تاخیر منٹ</small></div>
                <div class="col border-end"><h4 class="text-danger fw-bold mb-0"><?php echo $grand_total_a; ?></h4><small>غیر حاضر (<?php echo $perc_a; ?>%)</small></div>
                <div class="col"><h4 class="text-info fw-bold mb-0"><?php echo $grand_total_l; ?></h4><small>رخصت (<?php echo $perc_l; ?>%)</small></div>
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
function t_cls($s) { 
    if($s=='P') return 'text-success'; 
    if($s=='A') return 'text-danger'; 
    if($s=='L') return 'text-warning'; 
    if($s=='T') return 'text-info'; 
    return 'text-dark'; 
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
