<?php 
require_once 'db.php'; 
error_reporting(E_ALL);
ini_set('display_errors', 1);

$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

$week_start_day = 6;
try {
    $settings_stmt = $pdo->query("SELECT week_start_day FROM settings WHERE id=1");
    if ($settings_row = $settings_stmt->fetch()) {
        $week_start_day = isset($settings_row['week_start_day']) ? (int)$settings_row['week_start_day'] : 6;
    }
} catch (Exception $e) {}

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
    $colStmt = $pdo->query("SHOW COLUMNS FROM maktab_students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}

$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;

$maktab_students_stmt = $pdo->prepare("SELECT s.id, s.name, s.father_name, s.admission_number, $student_roll_select FROM maktab_students s WHERE s.class = ? AND (LOWER(s.status) IN ('active','present') OR s.status='حاضر' OR s.status IS NULL) AND (s.is_deleted = 0 OR s.is_deleted IS NULL) ORDER BY s.name ASC");
$maktab_students_stmt->execute([$selected_class]);
$filter_maktab_students = $maktab_students_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($selected_student_id > 0) {
    $all_maktab_maktab_students = array_values(array_filter($filter_maktab_students, function($st) use ($selected_student_id) {
        return (int)$st['id'] === $selected_student_id;
    }));
} else {
    $all_maktab_maktab_students = $filter_maktab_students;
}

// Fetch daily attendance status for the selected maktab_students
$attendance_map = [];
if (!empty($all_maktab_maktab_students)) {
    $student_ids = array_column($all_maktab_maktab_students, 'id');
    $inClause = implode(',', $student_ids);
    try {
        $stmtAtt = $pdo->prepare("SELECT student_id, date, period_num, status FROM period_attendance WHERE date >= ? AND date <= ? AND student_id IN ($inClause)");
        $stmtAtt->execute([$start_date, $end_date]);
        while ($row = $stmtAtt->fetch()) {
            $attendance_map[$row['student_id']][$row['date']][$row['period_num']] = $row['status'];
        }
    } catch (Exception $e) {}
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
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . '-' . date('Y', $timestamp) . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}

function translateStatusClean($status) {
    $map = ['Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور', 'بہترین'=>'بہترین', 'بہتر'=>'بہتر', 'کمزور'=>'کمزور'];
    $color = ['Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger', 'بہترین'=>'success', 'بہتر'=>'primary', 'کمزور'=>'danger'];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}
?>

<style>
    /* Desktop Sidebar Push Effect */
    @media (min-width: 769px) {
        #sidebar-wrapper { display: none !important; }
        #page-content-wrapper { margin: 0 !important; width: 100% !important; min-width: 100% !important; transition: all 0.3s ease; }
        .navbar #menu-toggle { display: inline-block !important; }
        
        body.active-nav #sidebar-wrapper { display: block !important; position: fixed; right: 0; left: auto; z-index: 1000; height: 100vh; overflow-y: auto; }
        html[dir="rtl"] body.active-nav #sidebar-wrapper { right: 0; left: auto; }
        html[dir="ltr"] body.active-nav #sidebar-wrapper { left: 0; right: auto; }
        
        /* Push the content instead of overlay */
        html[dir="rtl"] body.active-nav #page-content-wrapper { margin-right: 260px !important; width: calc(100% - 260px) !important; min-width: auto !important; }
        html[dir="ltr"] body.active-nav #page-content-wrapper { margin-left: 260px !important; width: calc(100% - 260px) !important; min-width: auto !important; }
        
        body.active-nav .sidebar-overlay { display: none !important; }
    }
    
    /* Mobile Overlay */
    @media (max-width: 768px) {
        body.active-nav #sidebar-wrapper { display: block !important; position: fixed; right: 0; z-index: 1050; height: 100vh; }
        html[dir="ltr"] body.active-nav #sidebar-wrapper { left: 0; right: auto; }
        body.active-nav .sidebar-overlay { display: block !important; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040; }
    }

    .report-wrapper { width: 100% !important; max-width: 100% !important; overflow-x: auto !important; padding-bottom: 15px; }
    .table-responsive { width: 100% !important; max-width: 100% !important; overflow-x: auto !important; -webkit-overflow-scrolling: touch; margin-bottom: 15px; padding-bottom: 10px; }
    
    /* VERY IMPORTANT FOR SCROLLING: Allow table to exceed container */
    .table.report-table { width: 100% !important; max-width: 100% !important; table-layout: auto !important; }
    
    .table th, .table td { word-break: break-word !important; white-space: normal !important; padding: 2px 3px !important; font-size: clamp(9px, 0.9vw, 12px) !important; vertical-align: middle; line-height: 1.2 !important; }
    .bg-light-purple { background-color: #f3e5f5; }
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
    
    @media print {
        @page { size: A4 landscape; margin: 2mm 3mm; }
        body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; background: #fff !important; margin: 0 !important; padding: 0 !important; zoom: 0.65; transform: scale(0.9); transform-origin: top left; }
        .d-print-none, header, footer, nav, .navbar, .sidebar { display: none !important; }
        #printableArea { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; border: none !important; }
        
        .report-wrapper, .table-responsive { overflow: visible !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; border: none !important; display: block !important; }
        .table.report-table { width: 100% !important; max-width: 100% !important; table-layout: auto !important; }
        .table th, .table td { 
            white-space: normal !important; word-break: break-word !important; padding: 2px 2px !important; 
            line-height: 1.1 !important;
            border: 1px solid #000 !important;
            font-size: 7.5pt !important;
        }
        .small { font-size: 6.5pt !important; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="fw-bold text-success mb-0"><i class="fas fa-filter me-2"></i> ناظرہ رپورٹ فلٹرز & پرنٹ</h4>
        <a href="academic_view_dashboard.php?class=<?= urlencode($selected_class) ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1"><i class="fas fa-user-graduate me-1 text-success"></i> طالب علم (سرچ کریں):</label>
            <select name="student_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                <?php foreach($filter_maktab_students as $st): ?>
                    <option value="<?php echo $st['id']; ?>" <?php echo $selected_student_id == $st['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($st['admission_number'] . ' - ' . $st['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">دورانیہ (Period):</label>
            <select name="period" id="academicPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyAcademicPeriodDates(this.value)">
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
            <input type="date" name="from" id="academicFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ تک:</label>
            <input type="date" name="to" id="academicToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-success btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> فلٹر کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-print me-1"></i> پرنٹ</button>
        </div>
    </form>
</div>

<script>
function applyAcademicPeriodDates(val) {
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
        document.getElementById('academicFromDate').value = formatDate(fromDate);
        document.getElementById('academicToDate').value = formatDate(toDate);
        document.getElementById('academicPeriodSelect').form.submit();
    }
}
</script>

<div id="printableArea" class="bg-white p-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-book-open me-2"></i> ناظرہ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="font-size: 13px;">
            <thead class="table-light">
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 10%;">رول نمبر</th>
                    <th style="width: 25%;">طالب علم</th>
                    <th style="width: 30%;" class="bg-success text-white">صبح کا سبق</th>
                    <th style="width: 30%;" class="bg-primary text-white">شام کا سبق</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch Maktab records
                $q = "SELECT n.* FROM daily_maktab n 
                      JOIN maktab_students s ON n.student_id = s.id 
                      WHERE n.date >= ? AND n.date <= ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                $stmt = $pdo->prepare($q);
                $stmt->execute([$start_date, $end_date, $selected_class]);
                $maktab_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $maktab_map = [];
                foreach ($maktab_records as $rec) {
                    $maktab_map[$rec['date']][$rec['student_id']] = $rec;
                }

                // Generate dates in range (descending)
                $report_dates = [];
                $start_time = strtotime($start_date);
                $end_time = strtotime($end_date);
                for ($current_time = $end_time; $current_time >= $start_time; $current_time -= 86400) {
                    $report_dates[] = date('Y-m-d', $current_time);
                }

                $totalMorning = 0; $totalEvening = 0; $n_count = 0;
                $maktab_students_with_records = [];

                if (count($all_maktab_maktab_students) > 0) {
                    foreach ($report_dates as $date) {
                        $formatted_date = translateDateUrduFull($date);
                        echo "<tr class='table-secondary'><td colspan='7' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $morning_absent = 0; $morning_leave = 0;
                        $evening_absent = 0; $evening_leave = 0;
                        $summary_missed_morning = 0;
                        $summary_missed_evening = 0;
                        
                        $sr_no = 1;
                        foreach ($all_maktab_maktab_students as $student) {
                            $student_id = $student['id'];
                            $rec = $maktab_map[$date][$student_id] ?? null;
                            
                            $att_p1 = $attendance_map[$student_id][$date][1] ?? '';
                            $att_p2 = $attendance_map[$student_id][$date][2] ?? '';
                            
                            $is_absent_p1 = (strtolower(trim($att_p1)) === 'a' || strpos($att_p1, 'غیر حاضر') !== false || stripos($att_p1, 'absent') !== false);
                            $is_leave_p1 = (strtolower(trim($att_p1)) === 'l' || strpos($att_p1, 'رخصت') !== false || stripos($att_p1, 'leave') !== false);
                            $is_absent_p2 = (strtolower(trim($att_p2)) === 'a' || strpos($att_p2, 'غیر حاضر') !== false || stripos($att_p2, 'absent') !== false);
                            $is_leave_p2 = (strtolower(trim($att_p2)) === 'l' || strpos($att_p2, 'رخصت') !== false || stripos($att_p2, 'leave') !== false);
                            
                            $is_friday = (date('N', strtotime($date)) == 5);
                            
                            if ($rec || $is_absent_p1 || $is_leave_p1 || $is_absent_p2 || $is_leave_p2) {
                                if ($rec) {
                                    $maktab_students_with_records[] = $student_id;
                                    $totalMorning += getGradePoints($rec['morning_status'], 50);
                                    $totalEvening += getGradePoints($rec['evening_status'], 50);
                                    $n_count++;
                                }
                                
                                $morning_empty = empty(trim($rec['morning_sabaq'] ?? '')) && empty(trim($rec['morning_status'] ?? ''));
                                if ($is_friday) {
                                    $morning_html = "<span class='text-success fw-bold'>تعطیل</span>";
                                } elseif ($morning_empty && ($is_absent_p1 || $is_leave_p1)) {
                                    $morning_html = $is_absent_p1 ? "<span class='text-danger fw-bold'>غیر حاضر</span>" : "<span class='text-info fw-bold'>رخصت</span>";
                                    if ($is_absent_p1) $morning_absent++; else $morning_leave++;
                                } else {
                                    if ($morning_empty && !$is_friday) $summary_missed_morning++;
                                    $m_sabaq = htmlspecialchars($rec['morning_sabaq'] ?? '');
                                    $m_qty = htmlspecialchars($rec['morning_qty'] ?? '-');
                                    $m_time = htmlspecialchars(substr($rec['morning_time'] ?? '', 0, 5));
                                    if(empty(trim($m_time))) $m_time = '-';
                                    $m_status = translateStatusClean($rec['morning_status'] ?? '');
                                    
                                    if ($m_sabaq || !empty($rec['morning_status'])) {
                                        $morning_html = "<strong>{$m_sabaq}</strong><br><small class='text-muted'>مقدار: {$m_qty} | وقت: {$m_time}</small><br>{$m_status}";
                                    } else {
                                        $morning_html = '-';
                                    }
                                }
                                
                                $evening_empty = empty(trim($rec['evening_sabaq'] ?? '')) && empty(trim($rec['evening_status'] ?? ''));
                                if ($is_friday) {
                                    $evening_html = "<span class='text-success fw-bold'>تعطیل</span>";
                                } elseif ($evening_empty && ($is_absent_p2 || $is_leave_p2)) {
                                    $evening_html = $is_absent_p2 ? "<span class='text-danger fw-bold'>غیر حاضر</span>" : "<span class='text-info fw-bold'>رخصت</span>";
                                    if ($is_absent_p2) $evening_absent++; else $evening_leave++;
                                } else {
                                    if ($evening_empty && !$is_friday) $summary_missed_evening++;
                                    $e_sabaq = htmlspecialchars($rec['evening_sabaq'] ?? '');
                                    $e_qty = htmlspecialchars($rec['evening_qty'] ?? '-');
                                    $e_time = htmlspecialchars(substr($rec['evening_time'] ?? '', 0, 5));
                                    if(empty(trim($e_time))) $e_time = '-';
                                    $e_status = translateStatusClean($rec['evening_status'] ?? '');
                                    
                                    if ($e_sabaq || !empty($rec['evening_status'])) {
                                        $evening_html = "<strong>{$e_sabaq}</strong><br><small class='text-muted'>مقدار: {$e_qty} | وقت: {$e_time}</small><br>{$e_status}";
                                    } else {
                                        $evening_html = '-';
                                    }
                                }
                                
                                echo "<tr>
                                    <td class='fw-bold text-center'>{$sr_no}</td>
                                    <td>" . htmlspecialchars(($student['roll_number'] ?: $student['admission_number']) ?? '') . "</td>
                                    <td class='text-start ps-2 fw-bold'>" . htmlspecialchars($student['name'] ?? '') . " - " . htmlspecialchars($student['father_name'] ?? '') . "</td>
                                    <td>{$morning_html}</td>
                                    <td>{$evening_html}</td>
                                </tr>";
                                
                                $sr_no++;
                            }
                        }
                        
                        if ($selected_student_id == 0) {
                            $total_st = count($all_maktab_maktab_students);
                            $morning_0 = $total_st - $summary_missed_morning - $morning_absent - $morning_leave;
                            $evening_0 = $total_st - $summary_missed_evening - $evening_absent - $evening_leave;
                            
                            $max_absent = max($morning_absent, $evening_absent);
                            $max_leave = max($morning_leave, $evening_leave);
                            
                            echo "<tr class='bg-light'>
                                    <td colspan='3' class='fw-bold text-end'>یومیہ سمری ($formatted_date):</td>
                                    <td class='fw-bold' style='font-size:11px;'>
                                        <span class='text-danger'>ناغہ: {$summary_missed_morning}</span><br>
                                        <span class='text-success'>سنانے والے: {$morning_0}</span>
                                    </td>
                                    <td class='fw-bold' style='font-size:11px;'>
                                        <span class='text-danger'>ناغہ: {$summary_missed_evening}</span><br>
                                        <span class='text-success'>سنانے والے: {$evening_0}</span><br>
                                        <span class='text-secondary'>غیرحاضر (زیادہ سے زیادہ): {$max_absent} | رخصت: {$max_leave}</span>
                                    </td>
                                  </tr>";
                        }
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                }

                $maktab_students_with_records = array_unique($maktab_students_with_records);
                $academicScore = ($n_count > 0) ? round(($totalMorning + $totalEvening) / $n_count) : 0;
                ?>
            </tbody>
        </table>
    </div>

    <?php
    $maktab_students_with_records = array_unique($maktab_students_with_records);
    $lazy_maktab_students = [];
    foreach($all_maktab_maktab_students as $ns) {
        if(!in_array($ns['id'], $maktab_students_with_records)) {
            $lazy_maktab_students[] = $ns;
        }
    }
    usort($lazy_maktab_students, function($a, $b) {
        return $a['admission_number'] <=> $b['admission_number'];
    });
    ?>
    
    <?php if(count($lazy_maktab_students) > 0): ?>
    <div class="mt-5">
        <h4 class="text-danger border-bottom border-danger pb-2 fw-bold"><i class="fas fa-exclamation-circle me-2"></i> سست طلبہ (جن کی کوئی رپورٹ درج نہیں ہوئی)</h4>
        <table class="table table-bordered border-danger text-center table-sm mt-3 report-table" style="font-size: 13px;">
            <thead class="bg-danger text-white">
                <tr>
                    <th style="width: 10%;">رول نمبر</th>
                    <th style="width: 30%;">طالب علم</th>
                    <th style="width: 30%;">ولدیت</th>
                    <th style="width: 30%;">کیفیت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lazy_maktab_students as $ls): ?>
                <tr>
                    <td class="fw-bold bg-light h6 m-0"><?php echo htmlspecialchars($ls['admission_number']); ?></td>
                    <td class="text-start ps-3 fw-bold"><?php echo htmlspecialchars($ls['name']); ?></td>
                    <td class="text-start ps-3 text-muted"><?php echo htmlspecialchars($ls['father_name']); ?></td>
                    <td class="text-danger fw-bold">کوئی سبق نہیں سنایا</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if($n_count > 0): ?>
    <div class="row mt-4">
        <div class="col-md-4 text-center">
            <div class="card p-3 shadow-sm border-0 bg-light">
                <h6 class="fw-bold mb-3">ناظرہ کی مجموعی کارکردگی</h6>
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

