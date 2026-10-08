<?php 
require_once 'db.php'; 
include 'header.php'; 

$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

$period = $_GET['period'] ?? 'current_week';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';

$cur_tenant_id = (int)get_current_tenant_id();
$week_start_day = 6;
try {
    $settings_stmt = $pdo->prepare("SELECT week_start_day FROM settings WHERE tenant_id = ? LIMIT 1");
    $settings_stmt->execute([$cur_tenant_id]);
    if ($settings_row = $settings_stmt->fetch()) {
        $week_start_day = isset($settings_row['week_start_day']) ? (int)$settings_row['week_start_day'] : 6;
    }
} catch (Exception $e) {}

if (empty($start_date) || empty($end_date)) {
    $range = get_period_date_range($period, $start_date, $end_date, $week_start_day);
    $start_date = $range['from'];
    $end_date = $range['to'];
}

$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;
$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

if (!function_exists('academic_norm_class')) {
    function academic_norm_class($val) {
        $val = trim((string)$val);
        $val = str_replace(['ٰ', 'ى', 'ي', 'ك', 'آ', 'آ'], ['', 'ی', 'ی', 'ک', 'ا', 'ا'], $val);
        $val = preg_replace('/\s+/u', '', $val);
        return function_exists('mb_strtolower') ? mb_strtolower($val, 'UTF-8') : strtolower($val);
    }
}
$target_norm_class = academic_norm_class($selected_class);

// Fetch all student candidates for class matching and search dropdown
$all_std_candidates = [];
try {
    $st_stmt = $pdo->prepare("SELECT id, name, father_name, admission_number, class, class_name, status, alumni_category, leaving_reason, is_deleted, admission_date, graduation_date FROM students WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $st_stmt->execute([$cur_tenant_id]);
    $all_std_candidates = array_merge($all_std_candidates, $st_stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

try {
    $mst_stmt = $pdo->prepare("SELECT id, name, father_name, admission_number, class, class_name, status, alumni_category, leaving_reason, is_deleted, admission_date, graduation_date FROM maktab_students WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $mst_stmt->execute([$cur_tenant_id]);
    $all_std_candidates = array_merge($all_std_candidates, $mst_stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

$filter_students = [];
$seen_stds = [];
foreach ($all_std_candidates as $std) {
    $c1 = academic_norm_class($std['class'] ?? '');
    $c2 = academic_norm_class($std['class_name'] ?? '');
    if ($c1 === $target_norm_class || $c2 === $target_norm_class) {
        $sid = (int)$std['id'];
        if (!isset($seen_stds[$sid])) {
            $seen_stds[$sid] = true;
            $filter_students[] = $std;
        }
    }
}

// Ensure any student with academic reports or attendance in this class is also in the filter dropdown
try {
    $cleanClass = str_replace(' ', '', $selected_class);
    $ar_stds = $pdo->prepare("SELECT DISTINCT student_id FROM academic_reports WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (class_name = ? OR REPLACE(class_name, ' ', '') = ?)");
    $ar_stds->execute([$cur_tenant_id, $selected_class, $cleanClass]);
    $ar_sids = $ar_stds->fetchAll(PDO::FETCH_COLUMN);

    $att_stds = $pdo->prepare("SELECT DISTINCT student_id FROM attendance WHERE tenant_id = ? AND (date BETWEEN ? AND ?)");
    $att_stds->execute([$cur_tenant_id, $start_date, $end_date]);
    $att_sids = $att_stds->fetchAll(PDO::FETCH_COLUMN);
    $rel_sids = array_unique(array_merge($ar_sids, $att_sids));

    foreach ($all_std_candidates as $std) {
        $sid = (int)$std['id'];
        $c1 = academic_norm_class($std['class'] ?? '');
        $c2 = academic_norm_class($std['class_name'] ?? '');
        $isClass = ($c1 === $target_norm_class || $c2 === $target_norm_class || in_array($sid, $ar_sids));
        if (!isset($seen_stds[$sid]) && in_array($sid, $rel_sids) && $isClass) {
            $seen_stds[$sid] = true;
            $filter_students[] = $std;
        }
    }
} catch (Exception $e) {}

usort($filter_students, function($a, $b) {
    return strcmp((string)$a['name'], (string)$b['name']);
});

function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'تمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}
?>
<style>
/* Full Responsiveness with smooth horizontal scrolling */
.report-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: visible !important;
}

.table-responsive-no-scroll, .table-responsive {
    width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
    margin-bottom: 1rem;
}

.report-table {
    width: 100% !important;
    min-width: 680px !important;
    table-layout: auto !important;
    font-size: clamp(12px, 1.2vw, 14px);
}

.report-table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background-color: #212529 !important;
    color: #ffffff !important;
}

.report-table th, .report-table td {
    word-break: normal !important;
    white-space: nowrap !important;
    padding: 8px 10px !important;
    vertical-align: middle;
}

.report-table td.cell-wrap, .report-table th.cell-wrap {
    white-space: normal !important;
    min-width: 180px;
    max-width: 320px;
}

@media (max-width: 768px) {
    .report-table {
        font-size: 12px !important;
    }
    .report-table th, .report-table td {
        padding: 6px 8px !important;
    }
}

@media print {
    @page {
        size: A4 portrait;
        margin: 8mm;
    }
    body {
        background: #fff !important;
        color: #000 !important;
        font-size: 11pt !important;
    }
    .d-print-none, header, footer, nav, .navbar, .sidebar {
        display: none !important;
    }
    #printableArea {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        border: none !important;
    }
    .report-table {
        width: 100% !important;
        table-layout: fixed !important;
    }
}
</style>

<div class="report-wrapper container-fluid px-2 px-md-4 py-3" dir="rtl">
    <!-- Filter Panel -->
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i> تعلیمی رپورٹ فلٹرز & پرنٹ</h4>
            <a href="academic_view_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
        </div>
        <form method="GET" id="reportFilterForm" class="row g-2 align-items-end">
            <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1"><i class="fas fa-calendar-alt me-1"></i> دورانیہ (Period):</label>
                <select name="period" id="periodSelect" class="form-select fw-bold form-select-sm" onchange="applyPeriodDates(this.value)">
                    <option value="all" <?php echo $period==='all'?'selected':''; ?>>تمام تاریخیں / تمام ریکارڈز (All)</option>
                    <option value="this_month" <?php echo $period==='this_month'?'selected':''; ?>>رواں مہینہ (This Month)</option>
                    <option value="current_week" <?php echo $period==='current_week'?'selected':''; ?>>رواں ہفتہ (Current Week)</option>
                    <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (آخری 7 دن)</option>
                    <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (آخری 30 دن)</option>
                    <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (آخری 90 دن)</option>
                    <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (آخری 180 دن)</option>
                    <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (پورا سال)</option>
                    <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
                </select>
            </div>
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1">تاریخ سے:</label>
                <input type="date" name="from" id="fromDateInput" class="form-control form-select-sm fw-bold" value="<?php echo htmlspecialchars($start_date); ?>" onchange="if(this.value && document.getElementById('toDateInput').value) this.form.submit();" required>
            </div>
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1">تاریخ تک:</label>
                <input type="date" name="to" id="toDateInput" class="form-control form-select-sm fw-bold" value="<?php echo htmlspecialchars($end_date); ?>" onchange="if(this.value && document.getElementById('fromDateInput').value) this.form.submit();" required>
            </div>

            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1"><i class="fas fa-user-graduate me-1 text-primary"></i> طالب علم (سرچ کریں):</label>
                <select name="student_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                    <?php foreach($filter_students as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $selected_student_id == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['admission_number'] . ' - ' . $st['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill fw-bold"><i class="fas fa-search me-1"></i> رپورٹ دکھائیں</button>
                <button type="button" onclick="window.print()" class="btn btn-dark btn-sm flex-fill fw-bold"><i class="fas fa-print me-1"></i> <?php echo t('btn_print'); ?></button>
            </div>
        </form>
    </div>

    <!-- Printable Report View Area -->
    <div id="printableArea" class="bg-white p-3 p-md-4 border rounded-3 shadow-sm text-end">
        <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
            <div>
                <h3 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i> عمومی تعلیمی رپورٹ - <?= htmlspecialchars($selected_class) ?></h3>
                <small class="text-muted fw-bold">مدرسہ اشرف المدارس</small>
            </div>
            <div class="text-start">
                <p class="mb-0 fw-bold fs-6">دورانیہ: <span dir="ltr"><?php echo date('d-M-Y', strtotime($start_date)); ?></span> سے <span dir="ltr"><?php echo date('d-M-Y', strtotime($end_date)); ?></span></p>
                <span class="badge bg-secondary"><?php 
                    $period_names = ['weekly'=>'ہفتہ وار', 'monthly'=>'ماہانہ', 'quarterly'=>'سہ ماہی (3 ماہ)', 'semi_annually'=>'شش ماہی (6 ماہ)', 'annually'=>'سالانہ', 'custom'=>'کسٹم ڈیٹس'];
                    echo $period_names[$period] ?? 'ماہانہ';
                ?></span>
            </div>
        </div>

        <?php
        // 1. Fetch all students for the class (Normalized & Resilient)
        $student_map = []; // sid => student array

        // Normalize date helper
        $normalize_date = function($dStr) {
            $dStr = trim(explode(' ', (string)$dStr)[0]);
            if (empty($dStr)) return '';
            if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dStr, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dStr, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            $ts = strtotime($dStr);
            return $ts ? date('Y-m-d', $ts) : $dStr;
        };

        // Populate initial student map from candidate pool (using normalization)
        foreach ($all_std_candidates as $st) {
            $c1 = academic_norm_class($st['class'] ?? '');
            $c2 = academic_norm_class($st['class_name'] ?? '');
            if ($c1 === $target_norm_class || $c2 === $target_norm_class || isset($seen_stds[(int)$st['id']])) {
                $sid = (int)$st['id'];
                if ($selected_student_id && $sid !== $selected_student_id) continue;
                if (!$selected_student_id && is_student_inactive($st, $start_date)) continue;
                if (!isset($student_map[$sid])) {
                    $student_map[$sid] = [
                        'id' => $sid,
                        'student_name' => $st['name'] ?? '',
                        'father_name' => $st['father_name'] ?? '',
                        'admission_number' => $st['admission_number'] ?? '',
                        'class' => !empty($st['class']) ? $st['class'] : ($st['class_name'] ?? $selected_class),
                        'admission_date' => $st['admission_date'] ?? '',
                        'graduation_date' => $st['graduation_date'] ?? '',
                        'status' => $st['status'] ?? '',
                        'alumni_category' => $st['alumni_category'] ?? '',
                        'leaving_reason' => $st['leaving_reason'] ?? ''
                    ];
                }
            }
        }

        // 2. Fetch academic reports comprehensively for this date range and class
        $ar_rows = [];
        $raw_reports_found = [];
        try {
            $ar_stmt = $pdo->prepare("SELECT ar.*, s.name as s_name, s.father_name as s_father_name, s.admission_number as s_adm, s.class as s_class 
                                      FROM academic_reports ar 
                                      LEFT JOIN students s ON ar.student_id = s.id 
                                      WHERE ar.tenant_id = ? AND (ar.is_deleted = 0 OR ar.is_deleted IS NULL) 
                                      AND (ar.date BETWEEN ? AND ? OR ar.date IS NULL)");
            $ar_stmt->execute([$cur_tenant_id, $start_date, $end_date]);
            $raw_reports_found = array_merge($raw_reports_found, $ar_stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}

        try {
            $mar_stmt = $pdo->prepare("SELECT ar.*, s.name as s_name, s.father_name as s_father_name, s.admission_number as s_adm, s.class as s_class 
                                       FROM maktab_academic_reports ar 
                                       LEFT JOIN maktab_students s ON ar.student_id = s.id 
                                       WHERE ar.tenant_id = ? AND (ar.is_deleted = 0 OR ar.is_deleted IS NULL) 
                                       AND (ar.date BETWEEN ? AND ? OR ar.date IS NULL)");
            $mar_stmt->execute([$cur_tenant_id, $start_date, $end_date]);
            $raw_reports_found = array_merge($raw_reports_found, $mar_stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}

        // Filter and Auto-Recover Students whose reports were entered from mobile
        foreach ($raw_reports_found as $row) {
            $c_ar = academic_norm_class($row['class_name'] ?? '');
            $c_std = academic_norm_class($row['s_class'] ?? '');
            $sid = (int)($row['student_id'] ?? 0);
            if ($selected_student_id && $sid !== $selected_student_id) continue;

            $matches_class = ($c_ar === $target_norm_class || $c_std === $target_norm_class || isset($student_map[$sid]));
            if ($matches_class) {
                $ar_rows[] = $row;
                // AUTO-RECOVERY: If student not present in student_map, recover them immediately
                if ($sid > 0 && !isset($student_map[$sid])) {
                    // Try to find in candidate pool
                    $found_cand = null;
                    foreach ($all_std_candidates as $cand) {
                        if ((int)$cand['id'] === $sid) {
                            $found_cand = $cand;
                            break;
                        }
                    }
                    $student_map[$sid] = [
                        'id' => $sid,
                        'student_name' => $found_cand['name'] ?? (!empty($row['s_name']) ? $row['s_name'] : ($row['student_name'] ?? 'طالب علم #'.$sid)),
                        'father_name' => $found_cand['father_name'] ?? ($row['s_father_name'] ?? ''),
                        'admission_number' => $found_cand['admission_number'] ?? ($row['s_adm'] ?? ''),
                        'class' => !empty($row['class_name']) ? $row['class_name'] : $selected_class
                    ];
                }
            }
        }

        $class_students = array_values($student_map);
        usort($class_students, function($a, $b) {
            $admA = (int)preg_replace('/[^0-9]/', '', (string)$a['admission_number']);
            $admB = (int)preg_replace('/[^0-9]/', '', (string)$b['admission_number']);
            if ($admA !== $admB && $admA > 0 && $admB > 0) return $admA <=> $admB;
            return strcmp((string)$a['student_name'], (string)$b['student_name']);
        });
        $class_student_ids = array_map(function($st) { return (int)$st['id']; }, $class_students);

        $ar_map = [];
        $recorded_dates = [];
        foreach ($ar_rows as $r) {
            $d = $normalize_date($r['date'] ?? '');
            $sid = (int)($r['student_id'] ?? 0);
            if ($d && $sid && $d >= $start_date && $d <= $end_date) {
                $ar_map[$d][$sid][] = $r;
                $recorded_dates[$d] = true;
            }
        }

        // 4. Fetch attendance map for status links
        $att_map = [];
        if (!empty($class_student_ids)) {
            $in_sids = implode(',', $class_student_ids);
            $att_rows = [];
            try {
                $stmt1 = $pdo->query("SELECT * FROM attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_sids)");
                $att_rows = array_merge($att_rows, $stmt1->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {}
            try {
                $stmt2 = $pdo->query("SELECT student_id, date, status FROM period_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_sids)");
                $att_rows = array_merge($att_rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {}
            try {
                $stmt3 = $pdo->query("SELECT student_id, date, status FROM maktab_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_sids)");
                $att_rows = array_merge($att_rows, $stmt3->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {}
            foreach ($att_rows as $a) {
                $dt = $normalize_date($a['date'] ?? '');
                if ($dt && $dt >= $start_date && $dt <= $end_date) {
                    $sid = (int)($a['student_id'] ?? 0);
                    $st = strtoupper(trim(($a['status'] ?? '') . ' ' . ($a['status_1'] ?? '') . ' ' . ($a['status_2'] ?? '') . ' ' . ($a['status_3'] ?? '') . ' ' . ($a['status_4'] ?? '') . ' ' . ($a['status_5'] ?? '') . ' ' . ($a['status_6'] ?? '') . ' ' . ($a['status_7'] ?? '') . ' ' . ($a['status_8'] ?? '')));
                    
                    $tokens = preg_split('/\s+/', $st);
                    $val = 'P';
                    if (strpos($st, 'ABSENT') !== false || strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) {
                        $val = 'A';
                    } elseif (strpos($st, 'LEAVE') !== false || strpos($st, 'رخصت') !== false || strpos($st, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) {
                        $val = 'L';
                    }
                    
                    $cur = $att_map["{$dt}_{$sid}"] ?? '';
                    if ($val === 'A') {
                        $att_map["{$dt}_{$sid}"] = 'A';
                    } elseif ($val === 'L' && $cur !== 'A') {
                        $att_map["{$dt}_{$sid}"] = 'L';
                    } elseif ($cur === '') {
                        $att_map["{$dt}_{$sid}"] = 'P';
                    }
                    $recorded_dates[$dt] = true;
                }
            }
        }

        // Sort dates descending
        $date_list = array_keys($recorded_dates);
        rsort($date_list);
        if (empty($date_list)) {
            $date_list = [$end_date];
        }

        // Build report display rows & accurate summary counts
        $report_display_rows = [];
        $recited_rows = [];
        $not_recited_rows = [];
        $naagha_rows = [];
        $absent_rows = [];
        $leave_rows = [];
        $recited_cnt = 0;
        $not_recited_cnt = 0;
        $naagha_cnt = 0;
        $absent_cnt = 0;
        $leave_cnt = 0;

        $student_naagha_count_map = [];
        $student_naagha_dates_map = [];

        foreach ($date_list as $dt) {
            $is_friday = (date('N', strtotime($dt)) == 5);

            foreach ($class_students as $st) {
                $sid = (int)$st['id'];
                $has_explicit_att = isset($att_map["{$dt}_{$sid}"]);
                $att_val = $has_explicit_att ? $att_map["{$dt}_{$sid}"] : null;
                $student_reports = $ar_map[$dt][$sid] ?? [];

                $sAdm = !empty($st['admission_date']) ? substr($normalize_date($st['admission_date']), 0, 10) : '';
                $sGrad = !empty($st['graduation_date']) ? substr($normalize_date($st['graduation_date']), 0, 10) : '';
                if (!empty($sAdm) && $dt < $sAdm) continue;
                if (!empty($sGrad) && $dt > $sGrad) continue;
                if (!$selected_student_id && is_student_inactive($st, $dt)) continue;
                $hasRecordOnDay = !empty($student_reports) || $has_explicit_att;

                if ($is_friday) {
                    $report_display_rows[] = [
                        'date' => $dt,
                        'student_name' => $st['student_name'],
                        'father_name' => $st['father_name'] ?? '',
                        'admission_number' => $st['admission_number'],
                        'class_name' => $selected_class,
                        'subject' => '-',
                        'att_badge' => "<span class='badge bg-primary'>جمعہ</span>",
                        'lesson' => "<span class='text-primary fw-bold'>جمعہ</span>",
                        'grade' => "<span class='text-primary fw-bold'>جمعہ</span>",
                        'color' => 'primary'
                    ];
                } elseif (!empty($student_reports)) {
                    foreach ($student_reports as $rep) {
                        $grade = trim($rep['grade'] ?? '');
                        $lesson = trim($rep['lesson_details'] ?? '');
                        $is_empty_lesson = ($lesson === '' || $lesson === '-' || strpos($lesson, 'ناغہ') !== false);
                        $is_empty_grade = ($grade === '' || $grade === '-' || strpos($grade, 'ناغہ') !== false);
                        
                        $is_absent = ($grade === 'غیر حاضر' || $lesson === 'غیر حاضر' || $grade === 'Absent' || $att_val === 'A');
                        $is_leave = ($grade === 'رخصت' || $lesson === 'رخصت' || $grade === 'Leave' || $att_val === 'L');
                        $is_explicit_naagha = (strpos($grade, 'ناغہ') !== false || strpos($lesson, 'ناغہ') !== false || $grade === 'سبق نہیں سنایا' || $grade === 'Naagha');
                        $is_not_recited = ($att_val === 'P' && ($is_empty_lesson || $is_empty_grade || $is_explicit_naagha));
                        
                        $has_recitation = (!$is_empty_lesson || !$is_empty_grade) && !$is_absent && !$is_leave && !$is_explicit_naagha && !$is_not_recited;

                        if ($has_recitation) {
                            $recited_cnt++;
                            $c = ($grade == 'کمزور' || $grade == 'Weak' || $grade == 'کچا') ? 'danger' : (($grade == 'بہترین' || $grade == 'Excellent' || $grade == 'ممتاز') ? 'success' : 'primary');
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => !empty($rep['class_name']) ? $rep['class_name'] : $selected_class,
                                'subject' => !empty($rep['subject']) ? $rep['subject'] : '-',
                                'att_badge' => "<span class='badge bg-success'>حاضر</span>",
                                'lesson' => htmlspecialchars($lesson ?: '-'),
                                'grade' => htmlspecialchars($grade ?: '-'),
                                'color' => $c
                            ];
                            $report_display_rows[] = $row_data;
                            $recited_rows[] = $row_data;
                        } elseif ($is_absent) {
                            $absent_cnt++;
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => !empty($rep['class_name']) ? $rep['class_name'] : $selected_class,
                                'subject' => !empty($rep['subject']) ? $rep['subject'] : '-',
                                'att_badge' => "<span class='badge bg-danger'>غیر حاضر</span>",
                                'lesson' => "<span class='badge bg-danger'>غیر حاضر</span>",
                                'grade' => "<span class='badge bg-danger'>غیر حاضر</span>",
                                'color' => 'danger'
                            ];
                            $report_display_rows[] = $row_data;
                            $absent_rows[] = $row_data;
                        } elseif ($is_leave) {
                            $leave_cnt++;
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => !empty($rep['class_name']) ? $rep['class_name'] : $selected_class,
                                'subject' => !empty($rep['subject']) ? $rep['subject'] : '-',
                                'att_badge' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                                'lesson' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                                'grade' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                                'color' => 'warning'
                            ];
                            $report_display_rows[] = $row_data;
                            $leave_rows[] = $row_data;
                        } elseif ($is_not_recited) {
                            $not_recited_cnt++;
                            $student_naagha_count_map[$sid] = ($student_naagha_count_map[$sid] ?? 0) + 1;
                            $student_naagha_dates_map[$sid][] = $dt;
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => !empty($rep['class_name']) ? $rep['class_name'] : $selected_class,
                                'subject' => !empty($rep['subject']) ? $rep['subject'] : '-',
                                'att_badge' => "<span class='badge bg-success'>حاضر</span>",
                                'lesson' => "<span class='badge bg-warning text-dark'>نہ سنایا</span>",
                                'grade' => "<span class='badge bg-warning text-dark'>نہ سنایا</span>",
                                'color' => 'warning'
                            ];
                            $report_display_rows[] = $row_data;
                            $not_recited_rows[] = $row_data;
                        } else {
                            $naagha_cnt++;
                            $student_naagha_count_map[$sid] = ($student_naagha_count_map[$sid] ?? 0) + 1;
                            $student_naagha_dates_map[$sid][] = $dt;
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => !empty($rep['class_name']) ? $rep['class_name'] : $selected_class,
                                'subject' => !empty($rep['subject']) ? $rep['subject'] : '-',
                                'att_badge' => "<span class='badge bg-success'>حاضر</span>",
                                'lesson' => "<span class='badge bg-danger'>ناغہ</span>",
                                'grade' => "<span class='badge bg-danger'>ناغہ</span>",
                                'color' => 'danger'
                            ];
                            $report_display_rows[] = $row_data;
                            $naagha_rows[] = $row_data;
                        }
                    }
                } else {
                    // Student has no academic entry on this recorded date
                    if ($att_val === 'A') {
                        $absent_cnt++;
                        $row_data = [
                            'date' => $dt,
                            'student_name' => $st['student_name'],
                            'father_name' => $st['father_name'] ?? '',
                            'admission_number' => $st['admission_number'],
                            'class_name' => $selected_class,
                            'subject' => '-',
                            'att_badge' => "<span class='badge bg-danger'>غیر حاضر</span>",
                            'lesson' => "<span class='badge bg-danger'>غیر حاضر</span>",
                            'grade' => "<span class='badge bg-danger'>غیر حاضر</span>",
                            'color' => 'danger'
                        ];
                        $report_display_rows[] = $row_data;
                        $absent_rows[] = $row_data;
                    } elseif ($att_val === 'L') {
                        $leave_cnt++;
                        $row_data = [
                            'date' => $dt,
                            'student_name' => $st['student_name'],
                            'father_name' => $st['father_name'] ?? '',
                            'admission_number' => $st['admission_number'],
                            'class_name' => $selected_class,
                            'subject' => '-',
                            'att_badge' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                            'lesson' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                            'grade' => "<span class='badge bg-warning text-dark'>رخصت</span>",
                            'color' => 'warning'
                        ];
                        $report_display_rows[] = $row_data;
                        $leave_rows[] = $row_data;
                    } elseif ($att_val === 'P') {
                        if (is_student_inactive($st, $dt)) {
                            continue;
                        }
                        // Explicitly marked PRESENT on attendance sheet, but no recitation record -> Not Recited
                        $not_recited_cnt++;
                        $student_naagha_count_map[$sid] = ($student_naagha_count_map[$sid] ?? 0) + 1;
                        $student_naagha_dates_map[$sid][] = $dt;
                        $row_data = [
                            'date' => $dt,
                            'student_name' => $st['student_name'],
                            'father_name' => $st['father_name'] ?? '',
                            'admission_number' => $st['admission_number'],
                            'class_name' => $selected_class,
                            'subject' => '-',
                            'att_badge' => "<span class='badge bg-success'>حاضر</span>",
                            'lesson' => "<span class='badge bg-warning text-dark'>نہ سنایا</span>",
                            'grade' => "<span class='badge bg-warning text-dark'>نہ سنایا</span>",
                            'color' => 'warning'
                        ];
                        $report_display_rows[] = $row_data;
                        $not_recited_rows[] = $row_data;
                    } else {
                        // Neither explicit attendance nor academic record exists for this student on this date
                        if (!empty($ar_map[$dt])) {
                            $row_data = [
                                'date' => $dt,
                                'student_name' => $st['student_name'],
                                'father_name' => $st['father_name'] ?? '',
                                'admission_number' => $st['admission_number'],
                                'class_name' => $selected_class,
                                'subject' => '-',
                                'att_badge' => "<span class='badge bg-secondary'>-</span>",
                                'lesson' => "-",
                                'grade' => "-",
                                'color' => 'secondary'
                            ];
                            $report_display_rows[] = $row_data;
                        }
                    }
                }
            }
        }

        // Aggregate Period Naagha Student Buckets
        $naagha_0_students = [];
        $naagha_1_students = [];
        $naagha_2_students = [];
        $naagha_3_students = [];
        $naagha_more_students = [];

        foreach ($class_students as $st) {
            $sid = (int)$st['id'];
            $cnt = $student_naagha_count_map[$sid] ?? 0;
            $st_item = [
                'id' => $sid,
                'name' => $st['student_name'],
                'father_name' => $st['father_name'] ?? '',
                'admission_number' => $st['admission_number'] ?? '',
                'class' => $st['class'] ?? $selected_class,
                'count' => $cnt,
                'dates' => $student_naagha_dates_map[$sid] ?? []
            ];
            if ($cnt === 0) {
                $naagha_0_students[] = $st_item;
            } elseif ($cnt === 1) {
                $naagha_1_students[] = $st_item;
            } elseif ($cnt === 2) {
                $naagha_2_students[] = $st_item;
            } elseif ($cnt === 3) {
                $naagha_3_students[] = $st_item;
            } else {
                $naagha_more_students[] = $st_item;
            }
        }

        if (count($report_display_rows) > 0):
        ?>
        <!-- Summary Cards (Clickable) -->
        <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
            <a href="#" data-bs-toggle="modal" data-bs-target="#recitedModal" class="badge bg-success text-decoration-none p-2 fs-6 shadow-sm" title="کلک کر کے سنانے والے طلبہ کی فہرست دیکھیں">
                سنانے والے: <?= $recited_cnt ?> <i class="fas fa-list-ul ms-1"></i>
            </a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#notRecitedModal" class="badge text-white text-decoration-none p-2 fs-6 shadow-sm" style="background-color: #d97706;" title="کلک کر کے نہ سنانے والے طلبہ کی فہرست دیکھیں">
                نہ سنانے والے: <?= $not_recited_cnt ?> <i class="fas fa-list-ul ms-1"></i>
            </a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#naaghaModal" class="badge bg-danger text-decoration-none p-2 fs-6 shadow-sm" title="کلک کر کے ناغہ کرنے والے طلبہ کی فہرست دیکھیں">
                ناغہ: <?= $naagha_cnt ?> <i class="fas fa-list-ul ms-1"></i>
            </a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#absentModal" class="badge text-white text-decoration-none p-2 fs-6 shadow-sm" style="background-color: #881337;" title="کلک کر کے غیر حاضر طلبہ کی فہرست دیکھیں">
                غیر حاضر: <?= $absent_cnt ?> <i class="fas fa-list-ul ms-1"></i>
            </a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#leaveModal" class="badge text-dark text-decoration-none p-2 fs-6 shadow-sm" style="background-color: #f59e0b;" title="کلک کر کے رخصت والے طلبہ کی فہرست دیکھیں">
                رخصت: <?= $leave_cnt ?> <i class="fas fa-list-ul ms-1"></i>
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered border-dark text-center align-middle report-table">
                <thead class="table-dark">
                    <tr>
                        <th style="min-width: 170px;"><?php echo t('student'); ?></th>
                        <th style="min-width: 95px;">حاضری</th>
                        <th style="min-width: 100px;">کلاس</th>
                        <th style="min-width: 130px;"><?php echo t('subject'); ?> / کتاب</th>
                        <th style="min-width: 170px;">تفصیل <?php echo t('sabaq'); ?></th>
                        <th style="min-width: 100px;">کیفیت / درجہ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $current_date = null;
                    foreach($report_display_rows as $row) {
                        if ($current_date !== $row['date']) {
                            $current_date = $row['date'];
                            echo "<tr class='table-secondary'><td colspan='6' class='fw-bold py-2 text-center border-dark'>تاریخ: ".translateDateUrduFull($current_date)."</td></tr>";
                        }
                        $f_disp = !empty($row['father_name']) ? "<small class='text-muted'> ولد " . htmlspecialchars($row['father_name']) . "</small>" : "";
                        $c = ($row['grade'] == 'ممتاز' || $row['grade'] == 'Excellent') ? 'success' : (($row['grade'] == 'بہتر' || $row['grade'] == 'Good') ? 'primary' : (($row['grade'] == 'مناسب' || $row['grade'] == 'Average') ? 'warning' : 'danger'));
                        echo "<tr>
                            <td class='fw-bold text-start ps-2'>{$row['student_name']} {$f_disp} <small class='text-muted'>({$row['admission_number']})</small></td>
                            <td>{$row['att_badge']}</td>
                            <td>{$row['class_name']}</td>
                            <td class='fw-bold'>{$row['subject']}</td>
                            <td class='text-start ps-2 cell-wrap'>{$row['lesson']}</td>
                            <td class='fw-bold text-$c'>{$row['grade']}</td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Aggregate Naagha Summary Section (پیریڈ وار مجموعی ناغہ جات خلاصہ) -->
        <div class="mt-4 mb-4 border rounded-3 p-3 bg-light shadow-sm">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h5 class="fw-bold text-success m-0"><i class="fas fa-chart-pie me-2"></i> پیریڈ وار مجموعی ناغہ جات خلاصہ (Aggregate Naagha Summary)</h5>
                <span class="badge bg-secondary">کل طلبہ: <?= count($class_students) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered border-dark text-center align-middle mb-0 bg-white">
                    <thead class="table-secondary">
                        <tr>
                            <th style="width: 50%;">ناغہ تفصیل</th>
                            <th style="width: 50%;">طلبہ تعداد (کلک برائے پرنٹ ایبل فہرست)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-success">
                            <td class="fw-bold text-success">بغیر ناغہ (0 ناغہ)</td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#naagha0Modal" class="fw-bold text-success text-decoration-underline fs-6">
                                    <?= count($naagha_0_students) ?> طلبہ <i class="fas fa-external-link-alt ms-1 small"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td class="fw-bold">1 ناغہ</td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#naagha1Modal" class="fw-bold text-dark text-decoration-underline fs-6">
                                    <?= count($naagha_1_students) ?> طلبہ <i class="fas fa-external-link-alt ms-1 small"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td class="fw-bold">2 ناغے</td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#naagha2Modal" class="fw-bold text-dark text-decoration-underline fs-6">
                                    <?= count($naagha_2_students) ?> طلبہ <i class="fas fa-external-link-alt ms-1 small"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td class="fw-bold">3 ناغے</td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#naagha3Modal" class="fw-bold text-dark text-decoration-underline fs-6">
                                    <?= count($naagha_3_students) ?> طلبہ <i class="fas fa-external-link-alt ms-1 small"></i>
                                </a>
                            </td>
                        </tr>
                        <tr class="table-danger">
                            <td class="fw-bold text-danger">3 سے زائد ناغے</td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#naaghaMoreModal" class="fw-bold text-danger text-decoration-underline fs-6">
                                    <?= count($naagha_more_students) ?> طلبہ <i class="fas fa-external-link-alt ms-1 small"></i>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div class="alert alert-info text-center fw-bold py-4 my-3">منتخب کردہ دورانیہ میں کوئی جنرل تعلیمی رپورٹ یا حاضری موجود نہیں ہے۔</div>
        <?php endif; ?>

        <!-- Complaints Section -->
        <?php
        $c_params = [$cur_tenant_id, $start_date, $end_date, $selected_class];
        $c_sql = "SELECT c.id, c.date, c.complaint as description, s.name as student_name, 'admin' as type 
                  FROM complaints c JOIN students s ON c.student_id = s.id 
                  WHERE c.tenant_id = ? AND c.date BETWEEN ? AND ? AND s.class = ?";
        if ($selected_student_id) {
            $c_sql .= " AND c.student_id = ?";
            $c_params[] = $selected_student_id;
        }
        
        $pc_params = [$cur_tenant_id, $start_date, $end_date, $selected_class];
        $pc_sql = "SELECT p.id, p.date, CONCAT('[', p.category, '] ', p.description) as description, s.name as student_name, 'parent' as type 
                   FROM parent_complaints p JOIN students s ON p.student_id = s.id 
                   WHERE p.tenant_id = ? AND p.date BETWEEN ? AND ? AND s.class = ?";
        if ($selected_student_id) {
            $pc_sql .= " AND p.student_id = ?";
            $pc_params[] = $selected_student_id;
        }
        
        $all_c_sql = "$c_sql UNION ALL $pc_sql ORDER BY date DESC, student_name ASC";
        $c_stmt = $pdo->prepare($all_c_sql);
        $all_params = array_merge($c_params, $pc_params);
        $c_stmt->execute($all_params);
        $complaints = $c_stmt->fetchAll();

        if (count($complaints) > 0):
        ?>
        <div class="mt-4">
            <h4 class="fw-bold text-danger border-bottom border-2 border-dark pb-2 mb-3"><i class="fas fa-exclamation-triangle me-2"></i> شکایات و نوٹس ریکارڈ</h4>
            <div class="table-responsive">
                <table class="table table-bordered border-dark text-center align-middle report-table">
                    <thead class="table-secondary">
                        <tr>
                            <th style="min-width: 110px;">تاریخ</th>
                            <th style="min-width: 170px;"><?php echo t('student'); ?></th>
                            <th style="min-width: 220px;">شکایت کی تفصیل</th>
                            <th style="min-width: 90px;">ذریعہ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($complaints as $c): ?>
                        <tr>
                            <td class="fw-bold"><?php echo translateDateUrduFull($c['date']); ?></td>
                            <td class="fw-bold text-start ps-2"><?php echo htmlspecialchars($c['student_name']); ?></td>
                            <td class="text-start ps-2"><?php echo nl2br(htmlspecialchars($c['description'])); ?></td>
                            <td>
                                <?php if($c['type'] == 'admin'): ?>
                                    <span class="badge bg-danger">مدرسہ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">والدین</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Category Modals -->
        <?php
        $modal_configs = [
            'recitedModal' => ['title' => 'سنانے والے طلبہ کی فہرست', 'color' => 'success', 'icon' => 'fa-check-circle', 'rows' => $recited_rows, 'badge' => 'bg-success', 'label' => 'حاضر و سنایا'],
            'notRecitedModal' => ['title' => 'نہ سنانے والے طلبہ کی فہرست', 'color' => 'warning text-dark', 'icon' => 'fa-exclamation-circle', 'rows' => $not_recited_rows, 'badge' => 'bg-warning text-dark', 'label' => 'حاضر مگر نہیں سنایا'],
            'naaghaModal' => ['title' => 'ناغہ کرنے والے طلبہ کی فہرست', 'color' => 'danger', 'icon' => 'fa-times-circle', 'rows' => $naagha_rows, 'badge' => 'bg-danger', 'label' => 'ناغہ'],
            'absentModal' => ['title' => 'غیر حاضر طلبہ کی فہرست', 'color' => 'danger', 'icon' => 'fa-user-slash', 'rows' => $absent_rows, 'badge' => 'bg-danger', 'label' => 'غیر حاضر'],
            'leaveModal' => ['title' => 'رخصت والے طلبہ کی فہرست', 'color' => 'warning', 'icon' => 'fa-calendar-minus', 'rows' => $leave_rows, 'badge' => 'bg-warning text-dark', 'label' => 'رخصت']
        ];

        foreach ($modal_configs as $m_id => $m_cfg):
        ?>
        <div class="modal fade" id="<?= $m_id ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content text-end">
                    <div class="modal-header bg-<?= $m_cfg['color'] ?> text-white d-flex justify-content-between align-items-center">
                        <h5 class="modal-title fw-bold m-0"><i class="fas <?= $m_cfg['icon'] ?> me-2"></i> <?= $m_cfg['title'] ?> (تعداد: <?= count($m_cfg['rows']) ?>)</h5>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-light btn-sm fw-bold" onclick="printModalTable('table_<?= $m_id ?>', '<?= addslashes($m_cfg['title']) ?>')">
                                <i class="fas fa-print me-1"></i> پرنٹ فہرست
                            </button>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0">
                        <?php if (empty($m_cfg['rows'])): ?>
                            <div class="p-4 text-center text-muted fw-bold">کوئی ریکارڈ موجود نہیں ہے۔</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="table_<?= $m_id ?>" class="table table-bordered table-striped table-hover mb-0 text-center align-middle" style="font-size: 13px; min-width: 600px;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 8%;">شمار</th>
                                            <th style="width: 15%;">تاریخ</th>
                                            <th style="width: 12%;">داخلہ نمبر</th>
                                            <th style="width: 25%;">طالب علم مع ولدیت</th>
                                            <th style="width: 20%;">مضمون / کتاب</th>
                                            <th style="width: 20%;">تفصیل / اسٹیٹس</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $m_sr = 1; foreach ($m_cfg['rows'] as $mr): ?>
                                        <tr>
                                            <td class="fw-bold"><?= $m_sr++ ?></td>
                                            <td><?= htmlspecialchars($mr['date']) ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($mr['admission_number']) ?></td>
                                            <td class="text-start ps-2 fw-bold">
                                                <?= htmlspecialchars($mr['student_name']) ?>
                                                <?= !empty($mr['father_name']) ? '<span class="text-muted fw-normal"> ولد ' . htmlspecialchars($mr['father_name']) . '</span>' : '' ?>
                                            </td>
                                            <td><?= htmlspecialchars($mr['subject']) ?></td>
                                            <td><?= $mr['lesson'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">بند کریں</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Aggregate Naagha Modals (پیریڈ وار مجموعی ناغہ فہرست ماڈلز) -->
        <?php
        $agg_modal_configs = [
            'naagha0Modal' => ['title' => 'بلا ناغہ طلبہ کی فہرست (0 ناغہ)', 'color' => 'success', 'students' => $naagha_0_students, 'is_zero' => true],
            'naagha1Modal' => ['title' => '1 ناغہ والے طلبہ کی فہرست', 'color' => 'secondary', 'students' => $naagha_1_students, 'is_zero' => false],
            'naagha2Modal' => ['title' => '2 ناغے والے طلبہ کی فہرست', 'color' => 'secondary', 'students' => $naagha_2_students, 'is_zero' => false],
            'naagha3Modal' => ['title' => '3 ناغے والے طلبہ کی فہرست', 'color' => 'danger', 'students' => $naagha_3_students, 'is_zero' => false],
            'naaghaMoreModal' => ['title' => '3 سے زائد ناغے والے طلبہ کی فہرست', 'color' => 'danger', 'students' => $naagha_more_students, 'is_zero' => false]
        ];

        foreach ($agg_modal_configs as $a_id => $a_cfg):
        ?>
        <div class="modal fade" id="<?= $a_id ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content text-end">
                    <div class="modal-header bg-<?= $a_cfg['color'] ?> text-white d-flex justify-content-between align-items-center">
                        <h5 class="modal-title fw-bold m-0"><i class="fas fa-users me-2"></i> <?= $a_cfg['title'] ?> (تعداد: <?= count($a_cfg['students']) ?>)</h5>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-light btn-sm fw-bold" onclick="printModalTable('table_<?= $a_id ?>', '<?= addslashes($a_cfg['title']) ?>')">
                                <i class="fas fa-print me-1"></i> پرنٹ فہرست
                            </button>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0">
                        <?php if (empty($a_cfg['students'])): ?>
                            <div class="p-4 text-center text-muted fw-bold">کوئی طالب علم موجود نہیں ہے۔</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="table_<?= $a_id ?>" class="table table-bordered table-striped table-hover mb-0 text-center align-middle" style="font-size: 13px; min-width: 600px;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 8%;">شمار</th>
                                            <th style="width: 15%;">داخلہ / رول نمبر</th>
                                            <th style="width: 30%;">طالب علم مع ولدیت</th>
                                            <th style="width: 17%;">کلاس</th>
                                            <th style="width: 12%;">کل ناغے</th>
                                            <th style="width: 18%;">ناغہ کی تاریخیں</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $a_sr = 1; foreach ($a_cfg['students'] as $ast): ?>
                                        <tr>
                                            <td class="fw-bold"><?= $a_sr++ ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($ast['admission_number']) ?></td>
                                            <td class="text-start ps-2 fw-bold">
                                                <?= htmlspecialchars($ast['name']) ?>
                                                <?= !empty($ast['father_name']) ? '<span class="text-muted fw-normal"> ولد ' . htmlspecialchars($ast['father_name']) . '</span>' : '' ?>
                                            </td>
                                            <td><?= htmlspecialchars($ast['class']) ?></td>
                                            <td class="fw-bold <?= $ast['count'] > 0 ? 'text-danger' : 'text-success' ?>">
                                                <?= $ast['count'] ?> ناغے
                                            </td>
                                            <td class="small">
                                                <?= !empty($ast['dates']) ? implode(', ', $ast['dates']) : ($a_cfg['is_zero'] ? '<span class="badge bg-success">بلا ناغہ</span>' : '-') ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">بند کریں</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function printModalTable(tableId, title) {
    var tbl = document.getElementById(tableId);
    if (!tbl) return;
    var newWin = window.open('', '_blank');
    newWin.document.open();
    newWin.document.write('<!DOCTYPE html><html dir="rtl" lang="ur"><head><meta charset="utf-8"><title>' + title + '</title>');
    newWin.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">');
    newWin.document.write('<style>body{direction:rtl;text-align:right;padding:25px;font-family:Arial,sans-serif;} table{width:100%;border-collapse:collapse;margin-top:15px;} th,td{border:1px solid #333 !important;padding:8px;text-align:center;} th{background-color:#eee !important;}</style>');
    newWin.document.write('</head><body>');
    newWin.document.write('<div class="text-center mb-4 pb-2 border-bottom"><h3>' + title + '</h3><p class="mb-0 fw-bold">کلاس: <?= htmlspecialchars($selected_class) ?> | دورانیہ: <?= htmlspecialchars($start_date) ?> تا <?= htmlspecialchars($end_date) ?></p></div>');
    newWin.document.write(tbl.outerHTML);
    newWin.document.write('<script>window.onload = function() { window.print(); }<\/script>');
    newWin.document.write('</body></html>');
    newWin.document.close();
}
function applyPeriodDates(val) {
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
        document.getElementById('fromDateInput').value = formatDate(fromDate);
        document.getElementById('toDateInput').value = formatDate(toDate);
        document.getElementById('reportFilterForm').submit();
    }
}
</script>

<?php include 'footer.php'; ?>


