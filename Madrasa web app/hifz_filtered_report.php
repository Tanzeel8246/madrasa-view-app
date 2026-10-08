<?php
require_once 'db.php';
// Auth checks are handled in header.php

$selected_class = $_GET['class'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_date = $_GET['filter_date'] ?? ''; // If empty, means weekly period
$lesson_type = $_GET['lesson_type'] ?? ''; // sabaq, sabqi, manzil, absent, leave, sabaq_absent, sabaq_leave, sabqi_absent, sabqi_leave, manzil_absent, manzil_leave
$miss_count = $_GET['miss_count'] ?? ''; // 0, 1, 2, 3, 'more', or 'any'

// Per-period absent/leave types
$per_period_types = ['absent', 'leave', 'sabaq_absent', 'sabaq_leave', 'sabqi_absent', 'sabqi_leave', 'manzil_absent', 'manzil_leave'];
$needs_att_filter = in_array($lesson_type, $per_period_types);

// Validate: miss_count not needed for absent/leave types
if (empty($selected_class) || ($miss_count === '' && !$needs_att_filter)) {
    echo "Invalid parameters.";
    exit;
}

include 'header.php';

// Utility functions
function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . ' ' . ($months[date('M', $timestamp)] ?? '') . ' ' . date('Y', $timestamp) . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}
function cleanUrduStr($str) {
    if(!$str) return '-';
    $str = str_replace(['(3/4)','(1/2)','(1/4)','(1)','((', '))', 'پارہ '], ['', '', '', '', '(', ')', ''], $str);
    $str = preg_replace('/(\d|[\x{0600}-\x{06FF}])\(/u', '$1 (', $str);
    return trim($str);
}
function translateStatusClean($status) {
    $map = ['Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور'];
    $color = ['Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger'];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}
function translateQtyClean($str) {
    if(!$str) return '-';
    $map = ['Lines'=>'لائنیں', 'Pages'=>'صفحات', 'Ruku'=>'رکوع', 'Rubah'=>'پاؤ', 'Nisf'=>'نصف', 'Suls'=>'ثُلث', 'Full Para'=>'مکمل پارہ'];
    foreach($map as $e => $u) { $str = str_replace($e, $u, $str); }
    return trim($str);
}

$cur_tenant_id = (int)get_current_tenant_id();
$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "(system_type = 'Masjid' OR admission_number LIKE 'M-%' OR COALESCE(roll_number, '') LIKE 'M-%')" : "((system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND (admission_number NOT LIKE 'M-%' AND COALESCE(roll_number, '') NOT LIKE 'M-%'))";

// Fetch all students of this class
$sql_students = "SELECT id, name, father_name, roll_number, admission_number FROM students 
                 WHERE (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) 
                 AND (class = ? OR class_name = ?) 
                 AND (is_deleted = 0 OR is_deleted IS NULL) 
                 AND $sys_cond
                 AND (status = 'Active' OR status = 'active' OR status = 'حاضر' OR status IS NULL OR status = '')";
$stmt = $pdo->prepare($sql_students);
$stmt->execute([$cur_tenant_id, $selected_class, $selected_class]);
$all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_students)) {
    // Try without tenant restriction
    $sql_students_fb = "SELECT id, name, father_name, roll_number, admission_number FROM students 
                        WHERE (class = ? OR class_name = ?) 
                        AND (is_deleted = 0 OR is_deleted IS NULL) 
                        AND $sys_cond
                        AND (status = 'Active' OR status = 'active' OR status = 'حاضر' OR status IS NULL OR status = '')";
    $stmt_fb = $pdo->prepare($sql_students_fb);
    $stmt_fb->execute([$selected_class, $selected_class]);
    $all_students = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);
}

// Fallback to maktab_students ONLY if system_type is Masjid
if (empty($all_students) && $sys_type === 'Masjid') {
    try {
        $m_stmt = $pdo->prepare("SELECT id, name, father_name, roll_number, admission_number FROM maktab_students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (class = ? OR class_name = ?)");
        $m_stmt->execute([$selected_class, $selected_class]);
        $all_students = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $eM) {}
}
$all_student_ids = array_column($all_students, 'id');

// Fetch Hifz records
$hifz_records = [];
if (!empty($all_student_ids)) {
    $in_ids = implode(',', array_map('intval', $all_student_ids));
    $sql = "SELECT d.* FROM daily_hifz d 
            WHERE d.tenant_id = $cur_tenant_id AND d.student_id IN ($in_ids)";
    $stmt = $pdo->query($sql);
    $raw_hifz = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date . ' 23:59:59');
    foreach ($raw_hifz as $rh) {
        $norm_d = normalize_date($rh['date'] ?? '');
        $r_ts = strtotime($norm_d);
        if ($r_ts >= $start_ts && $r_ts <= $end_ts) {
            $rh['date'] = $norm_d;
            $hifz_records[] = $rh;
        }
    }
}

// Fetch Complaints
$c_sql = "SELECT student_id, date, complaint as description FROM complaints WHERE tenant_id = ? AND date BETWEEN ? AND ?";
$stmt = $pdo->prepare($c_sql);
$stmt->execute([$cur_tenant_id, $start_date, $end_date]);
$complaints_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$complaints_map = [];
foreach($complaints_data as $cd) {
    $complaints_map[$cd['student_id'] . '_' . $cd['date']][] = $cd['description'];
}

// Calculate missed frequencies
$freq_sabaq = [];
$freq_sabqi = [];
$freq_manzil = [];
$student_records = []; // student_id => [date => rec]

foreach ($hifz_records as $rec) {
    $sid = $rec['student_id'];
    $student_records[$sid][$rec['date']] = $rec;
    
    // Check misses
    $miss_sabaq = empty(trim($rec['sabaq_parah'])) || $rec['sabaq_status'] == 'ناغہ' || $rec['sabaq_status'] == 'Absent' || empty(trim($rec['sabaq_status']));
    $miss_sabqi = empty(trim($rec['sabqi_parah'])) || $rec['sabqi_status'] == 'ناغہ' || $rec['sabqi_status'] == 'Absent' || empty(trim($rec['sabqi_status']));
    $miss_manzil = empty(trim($rec['manzil_parah'])) || $rec['manzil_status'] == 'ناغہ' || $rec['manzil_status'] == 'Absent' || empty(trim($rec['manzil_status']));
    
    if($miss_sabaq) $freq_sabaq[$sid] = ($freq_sabaq[$sid] ?? 0) + 1;
    if($miss_sabqi) $freq_sabqi[$sid] = ($freq_sabqi[$sid] ?? 0) + 1;
    if($miss_manzil) $freq_manzil[$sid] = ($freq_manzil[$sid] ?? 0) + 1;
}

// Ensure students with no records get their misses counted
$report_dates = [];
for ($c = strtotime($end_date); $c >= strtotime($start_date); $c -= 86400) {
    $report_dates[] = date('Y-m-d', $c);
}

foreach($all_students as $s) {
    $sid = $s['id'];
    foreach($report_dates as $d) {
        if(!isset($student_records[$sid][$d])) {
            $freq_sabaq[$sid] = ($freq_sabaq[$sid] ?? 0) + 1;
            $freq_sabqi[$sid] = ($freq_sabqi[$sid] ?? 0) + 1;
            $freq_manzil[$sid] = ($freq_manzil[$sid] ?? 0) + 1;
        }
    }
}

// Fetch Attendance for absent/leave filter (handles both global and per-period)
$att_map_filter = []; // sid => ['s1'=>'A/L/P', 's2'=>, 's3'=>]
if ($needs_att_filter && !empty($filter_date)) {
    try {
        $norm_filter_date = normalize_date($filter_date);
        $att_stmt = $pdo->query("SELECT student_id, date, status, status_1, status_2, status_3 FROM attendance WHERE tenant_id = $cur_tenant_id");
        foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (normalize_date($a['date'] ?? '') !== $norm_filter_date) continue;
            $parseS = function($val, $fallback = '') {
                $s = strtoupper(trim($val ?? $fallback));
                if (strpos($s, 'ABSENT') !== false || strpos($s, 'غیر حاضر') !== false || strpos($s, 'غائب') !== false || $s === 'A') return 'A';
                if (strpos($s, 'LEAVE') !== false || strpos($s, 'رخصت') !== false || $s === 'L') return 'L';
                return $s !== '' ? 'P' : '';
            };
            $s1_raw = $a['status_1'] ?? '';
            $s2_raw = $a['status_2'] ?? '';
            $s3_raw = $a['status_3'] ?? '';
            $fallback = $a['status'] ?? '';
            $att_map_filter[$a['student_id']] = [
                's1' => $parseS($s1_raw ?: $fallback),
                's2' => $parseS($s2_raw ?: $fallback),
                's3' => $parseS($s3_raw ?: $fallback),
            ];
        }
        
        $pa_stmt = $pdo->prepare("SELECT student_id, period_num, status FROM period_attendance WHERE tenant_id = ? AND date LIKE ?");
        $pa_stmt->execute([$cur_tenant_id, $filter_date . '%']);
        foreach ($pa_stmt->fetchAll(PDO::FETCH_ASSOC) as $pa) {
            $sid = (int)$pa['student_id'];
            $pnum = (int)$pa['period_num'];
            $stat = strtoupper(trim($pa['status'] ?? ''));
            $normStat = '';
            if (strpos($stat, 'A') !== false || strpos($stat, 'غ') !== false) $normStat = 'A';
            elseif (strpos($stat, 'L') !== false || strpos($stat, 'ر') !== false) $normStat = 'L';
            elseif ($stat !== '') $normStat = 'P';
            
            if ($normStat !== '') {
                if (!isset($att_map_filter[$sid])) {
                    $att_map_filter[$sid] = ['s1'=>'', 's2'=>'', 's3'=>''];
                }
                if ($pnum == 1) $att_map_filter[$sid]['s1'] = $normStat;
                elseif ($pnum == 2) $att_map_filter[$sid]['s2'] = $normStat;
                elseif ($pnum == 3) $att_map_filter[$sid]['s3'] = $normStat;
            }
        }
    } catch (PDOException $e) {}
}

// Filter students
$filtered_student_ids = [];
foreach($all_students as $s) {
    $sid = $s['id'];
    
    if ($needs_att_filter && !empty($filter_date)) {
        $att = $att_map_filter[$sid] ?? ['s1' => '', 's2' => '', 's3' => ''];
        $s1 = $att['s1'] ?? '';
        $s2 = $att['s2'] ?? '';
        $s3 = $att['s3'] ?? '';
        $rec = $student_records[$sid][$filter_date] ?? null;

        // Override from hifz record if available
        if ($rec) {
            if (in_array($rec['sabaq_status'] ?? '', ['غیر حاضر', 'Absent'])) $s1 = 'A';
            elseif (in_array($rec['sabaq_status'] ?? '', ['رخصت', 'Leave'])) $s1 = 'L';
            if (in_array($rec['sabqi_status'] ?? '', ['غیر حاضر', 'Absent'])) $s2 = 'A';
            elseif (in_array($rec['sabqi_status'] ?? '', ['رخصت', 'Leave'])) $s2 = 'L';
            if (in_array($rec['manzil_status'] ?? '', ['غیر حاضر', 'Absent'])) $s3 = 'A';
            elseif (in_array($rec['manzil_status'] ?? '', ['رخصت', 'Leave'])) $s3 = 'L';
        }

        $match = false;
        switch ($lesson_type) {
            case 'absent':        $match = ($s1 === 'A' || $s2 === 'A' || $s3 === 'A'); break;
            case 'leave':         $match = ($s1 === 'L' || $s2 === 'L' || $s3 === 'L'); break;
            case 'sabaq_absent':  $match = ($s1 === 'A'); break;
            case 'sabaq_leave':   $match = ($s1 === 'L'); break;
            case 'sabqi_absent':  $match = ($s2 === 'A'); break;
            case 'sabqi_leave':   $match = ($s2 === 'L'); break;
            case 'manzil_absent': $match = ($s3 === 'A'); break;
            case 'manzil_leave':  $match = ($s3 === 'L'); break;
        }
        if ($match) $filtered_student_ids[] = $sid;

    } elseif (!empty($filter_date)) {
        // Daily Summary Filter (ناغہ / سنانے والے)
        $rec = $student_records[$sid][$filter_date] ?? null;
        if ($rec) {
            $miss = false;
            if ($lesson_type == 'sabaq') $miss = empty(trim($rec['sabaq_parah'] ?? '')) || in_array($rec['sabaq_status'] ?? '', ['ناغہ', 'Absent', '-', '']) || empty(trim($rec['sabaq_status'] ?? ''));
            if ($lesson_type == 'sabqi') $miss = empty(trim($rec['sabqi_parah'] ?? '')) || in_array($rec['sabqi_status'] ?? '', ['ناغہ', 'Absent', '-', '']) || empty(trim($rec['sabqi_status'] ?? ''));
            if ($lesson_type == 'manzil') $miss = empty(trim($rec['manzil_details'] ?? $rec['manzil_parah'] ?? '')) || in_array($rec['manzil_status'] ?? '', ['ناغہ', 'Absent', '-', '']) || empty(trim($rec['manzil_status'] ?? ''));
        } else {
            $miss = true; // No record = miss
        }
        
        if ($miss_count == '0' && !$miss) {
            $filtered_student_ids[] = $sid;
        } elseif ($miss_count == 'any' && $miss) {
            $filtered_student_ids[] = $sid;
        }
    } else {
        // Weekly Summary Filter
        $freq = 0;
        if ($lesson_type == 'sabaq') $freq = $freq_sabaq[$sid] ?? 0;
        if ($lesson_type == 'sabqi') $freq = $freq_sabqi[$sid] ?? 0;
        if ($lesson_type == 'manzil') $freq = $freq_manzil[$sid] ?? 0;
        
        if ($miss_count == 'more' && $freq > 3) $filtered_student_ids[] = $sid;
        elseif ($miss_count !== 'more' && $freq == (int)$miss_count) $filtered_student_ids[] = $sid;
    }
}

$title_label = "";
$type_map = [
    'sabaq' => 'سبق', 'sabqi' => 'سبقی', 'manzil' => 'منزل',
    'absent' => 'غیر حاضر', 'leave' => 'رخصت',
    'sabaq_absent' => 'سبق - غیر حاضر', 'sabaq_leave' => 'سبق - رخصت',
    'sabqi_absent' => 'سبقی - غیر حاضر', 'sabqi_leave' => 'سبقی - رخصت',
    'manzil_absent' => 'منزل - غیر حاضر', 'manzil_leave' => 'منزل - رخصت',
];
$type_label = $type_map[$lesson_type] ?? $lesson_type;
if (!empty($filter_date)) {
    if (str_contains($lesson_type, 'absent') || str_contains($lesson_type, 'leave')) {
        $title_label = "بتاریخ " . translateDateUrduFull($filter_date) . " " . $type_label . " طلبہ";
    } else {
        $status_label = ($miss_count == '0') ? "سنانے والے طلبہ" : "کا ناغہ کرنے والے طلبہ";
        $title_label = "بتاریخ " . translateDateUrduFull($filter_date) . " " . $type_label . " " . $status_label;
    }
} else {
    if ($miss_count == '0') $mc_lbl = "بِلا ناغہ (0 ناغے)";
    elseif ($miss_count == 'more') $mc_lbl = "3 سے زیادہ ناغے";
    else $mc_lbl = $miss_count . " ناغے";
    $title_label = "کل دورانیہ میں " . $type_label . " کے " . $mc_lbl . " والے طلبہ";
}
?>

<style>
    .table th, .table td { 
        white-space: normal !important; 
        line-height: 1.25 !important; 
        vertical-align: middle !important; 
        word-break: normal !important; 
        overflow-wrap: break-word !important; 
    }
    @media print {
        @page { size: A4 landscape; margin: 5mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .d-print-none { display: none !important; }
        .table-responsive { overflow: visible !important; }
        .table { width: 100% !important; table-layout: fixed !important; }
        .table th, .table td { white-space: normal !important; font-size: 10px !important; padding: 2px !important; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end" dir="rtl">
    <a href="hifz_report.php?class=<?= urlencode($selected_class) ?>&from=<?= $start_date ?>&to=<?= $end_date ?>" class="btn btn-outline-secondary btn-sm fw-bold float-start mt-2"><i class="fas fa-arrow-right me-2"></i> واپس</a>
    <div class="text-end">
        <button type="button" onclick="window.print()" class="btn btn-dark fw-bold"><?php echo t('btn_print'); ?></button>
    </div>
</div>

<div class="bg-white p-3 text-end" dir="rtl">
    <div class="border-bottom border-2 border-dark mb-4 pb-2">
        <h3 class="m-0 fw-bold text-primary"><?= $title_label ?> (<?= count($filtered_student_ids) ?> طلبہ)</h3>
        <p class="mb-0 mt-1 fw-bold text-muted">کلاس: <?= htmlspecialchars($selected_class) ?></p>
    </div>

    <div class="table-responsive" style="width: 100%; overflow-x: auto;">
        <table class="table table-bordered border-dark text-center table-sm align-middle" style="font-size: 10.5px; min-width: 1520px; table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width: 2.5%;">#</th>
                    <th rowspan="2" style="width: 3.5%;">رول نمبر</th>
                    <th rowspan="2" style="width: 12.5%;"><?php echo t('student'); ?></th>
                    <th rowspan="2" style="width: 8.5%;">تاریخ</th>
                    <th colspan="3" class="bg-success text-white">سبق</th>
                    <th colspan="4" class="bg-primary text-white">سبقی</th>
                    <th colspan="4" class="bg-warning text-dark">منزل</th>
                    <th rowspan="2" style="width: 8.5%;" class="bg-danger text-white">شکایات</th>
                </tr>
                <tr>
                    <th style="width: 3.5%;">پارہ</th><th style="width: 3.5%;">لائنیں</th><th style="width: 5.5%;">کیفیت</th>
                    <th style="width: 10.5%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 9.5%;">سننے والا</th>
                    <th style="width: 13%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 9.5%;">سننے والا</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (count($filtered_student_ids) == 0) {
                    echo "<tr><td colspan='15' class='text-danger fw-bold p-3'>کوئی طالب علم نہیں ملا۔</td></tr>";
                } else {
                    $sr_no = 1;
                    foreach ($all_students as $student) {
                        if (!in_array($student['id'], $filtered_student_ids)) continue;
                        
                        $dates_to_show = empty($filter_date) ? $report_dates : [$filter_date];
                        $sid = $student['id'];
                        
                        foreach($dates_to_show as $d) {
                            $rec = $student_records[$sid][$d] ?? null;
                            $formatted_date = date('d-M-y', strtotime($d));
                            
                            $att = $att_map_filter[$sid] ?? ['s1' => '', 's2' => '', 's3' => ''];
                            $s1 = $att['s1'] ?? '';
                            $s2 = $att['s2'] ?? '';
                            $s3 = $att['s3'] ?? '';

                            if ($rec) {
                                if (in_array($rec['sabaq_status'] ?? '', ['غیر حاضر', 'Absent'])) $s1 = 'A';
                                elseif (in_array($rec['sabaq_status'] ?? '', ['رخصت', 'Leave'])) $s1 = 'L';
                                if (in_array($rec['sabqi_status'] ?? '', ['غیر حاضر', 'Absent'])) $s2 = 'A';
                                elseif (in_array($rec['sabqi_status'] ?? '', ['رخصت', 'Leave'])) $s2 = 'L';
                                if (in_array($rec['manzil_status'] ?? '', ['غیر حاضر', 'Absent'])) $s3 = 'A';
                                elseif (in_array($rec['manzil_status'] ?? '', ['رخصت', 'Leave'])) $s3 = 'L';
                            }

                            // Initialize default empty values
                            $sabaq_parah = '-'; $sabaq_lines = '-'; $sabaq_status = '-';
                            $sabqi_parah = '-'; $sabqi_amount = '-'; $sabqi_status = '-'; $sabqi_listener = '-';
                            $manzil_parah = '-'; $manzil_amount = '-'; $manzil_status = '-'; $manzil_listener = '-';

                            // Sabaq
                            if ($s1 === 'A') {
                                $sabaq_parah = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                                $sabaq_status = $sabaq_parah;
                            } elseif ($s1 === 'L') {
                                $sabaq_parah = "<span class='text-success fw-bold'>رخصت</span>";
                                $sabaq_status = $sabaq_parah;
                            } elseif ($rec) {
                                $sabaq_parah = cleanUrduStr($rec['sabaq_parah'] ?? '');
                                $sabaq_lines = htmlspecialchars($rec['sabaq_lines'] ?? '');
                                $sabaq_status = translateStatusClean($rec['sabaq_status'] ?? '');
                            }

                            // Sabqi
                            if ($s2 === 'A') {
                                $sabqi_parah = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                                $sabqi_status = $sabqi_parah;
                            } elseif ($s2 === 'L') {
                                $sabqi_parah = "<span class='text-success fw-bold'>رخصت</span>";
                                $sabqi_status = $sabqi_parah;
                            } elseif ($rec) {
                                $sabqi_parah = cleanUrduStr($rec['sabqi_parah'] ?? '');
                                $sabqi_amount = translateQtyClean($rec['sabqi_amount'] ?? '');
                                $sabqi_status = translateStatusClean($rec['sabqi_status'] ?? '');
                                $sabqi_listener = htmlspecialchars($rec['sabqi_listener'] ?? '');
                            }

                            // Manzil
                            if ($s3 === 'A') {
                                $manzil_parah = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                                $manzil_status = $manzil_parah;
                            } elseif ($s3 === 'L') {
                                $manzil_parah = "<span class='text-success fw-bold'>رخصت</span>";
                                $manzil_status = $manzil_parah;
                            } elseif ($rec) {
                                $manzil_parah = cleanUrduStr($rec['manzil_parah'] ?? '');
                                $manzil_amount = htmlspecialchars($rec['manzil_amount'] ?? '');
                                $manzil_status = translateStatusClean($rec['manzil_status'] ?? '');
                                $manzil_listener = htmlspecialchars($rec['manzil_listener'] ?? '');
                            }
                            
                            $comp_key = $sid . '_' . $d;
                            $complaints_html = isset($complaints_map[$comp_key]) ? implode('<br><hr class="my-1 border-danger">', $complaints_map[$comp_key]) : '-';
                            
                            $f_disp = !empty($student['father_name']) ? "<br><small class='text-muted fw-normal'>ولد " . htmlspecialchars($student['father_name']) . "</small>" : "";
                            $st_roll_val = '';
                            foreach (['roll_number', 'admission_number', 'roll_no', 'admission_no', 'gr_number', 'serial_no'] as $rk) {
                                if (!empty($student[$rk]) && trim((string)$student[$rk]) !== '-' && trim((string)$student[$rk]) !== '0') {
                                    $st_roll_val = trim((string)$student[$rk]);
                                    break;
                                }
                            }
                            $st_roll = !empty($st_roll_val) ? htmlspecialchars($st_roll_val) : '-';
                            echo "<tr>
                                <td>$sr_no</td>
                                <td>{$st_roll}</td>
                                <td class='fw-bold text-start ps-2'>{$student['name']} {$f_disp}</td>
                                <td class='text-muted' style='font-size:11px;' dir='ltr'>{$formatted_date}</td>
                                <td>{$sabaq_parah}</td><td>{$sabaq_lines}</td><td>{$sabaq_status}</td>
                                <td style='font-size:10.5px;'>{$sabqi_parah}</td><td>{$sabqi_amount}</td><td>{$sabqi_status}</td><td class='small text-muted'>{$sabqi_listener}</td>
                                <td class='text-start ps-1' style='font-size:10.5px;'>{$manzil_parah}</td><td>{$manzil_amount}</td><td>{$manzil_status}</td><td class='small text-muted'>{$manzil_listener}</td>
                                <td class='small text-start text-danger' style='font-size:11px;'>{$complaints_html}</td>
                            </tr>";
                        }
                        $sr_no++;
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
