<?php 
require_once 'db.php'; 
include 'header.php';

$student_roll_select = "s.admission_number AS roll_number";
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}
 

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$week_start_day = $settings['week_start_day'] ?? 6;

// 1. فلٹرز
if (!isset($_GET['from'])) {
    $now = new DateTime();
    $current_day_num = (int)$now->format('w');
    $days_since_start = ($current_day_num - $week_start_day + 7) % 7;
    $start = clone $now;
    $start->modify("-$days_since_start days");
    $start_date = $start->format('Y-m-d');
    $end_date = $now->format('Y-m-d');
} else {
    $start_date = $_GET['from'];
    $end_date = $_GET['to'];
}

$selected_class = $_GET['class'] ?? '';

// Fetch all active Nazira students for lazy student detection
$all_nazira_students = $pdo->prepare("
    SELECT s.id, s.name, s.father_name, s.admission_number, $student_roll_select FROM students s
    WHERE (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present') 
      AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
      AND s.class = ?
");
$all_nazira_students->execute([$selected_class]);
$all_nazira_students = $all_nazira_students->fetchAll();

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
?>

<style>
    @media print {
        @page { size: A4 landscape; margin: 5mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .d-print-none { display: none !important; }
        #printableArea { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        
        /* Force table to fit */
        .table-responsive { overflow: visible !important; }
        .table { width: 100% !important; table-layout: fixed !important; max-width: 100% !important; }
        .table th, .table td { 
            white-space: normal !important; 
            word-wrap: break-word !important; 
            font-size: 10px !important; 
            padding: 2px !important; 
        }
    }
    .table th, .table td { white-space: nowrap; }
    .bg-light-purple { background-color: #f3e5f5; }
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
</style>

<div class="container-fluid mt-3">
    <div class="d-print-none mb-3">
        <a href="academic_view_dashboard.php?class=<?= urlencode($selected_class) ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end" dir="rtl">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="class" value="<?= htmlspecialchars($selected_class) ?>">
            <div class="col-md-3"><label class="fw-bold">تاریخ سے:</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>"></div>
            <div class="col-md-3"><label class="fw-bold">تاریخ تک:</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>"></div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100 fw-bold mb-1">تلاش کریں</button>
                <button type="button" onclick="window.print()" class="btn btn-dark w-100 fw-bold"><?php echo t('btn_print') ?? 'Print'; ?></button>
            </div>
        </form>
    </div>

    <div id="printableArea" class="bg-white p-3 text-end" dir="rtl">
        <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
            <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-book-open me-2"></i> ناظرہ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
            <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered border-dark text-center table-sm align-middle" style="font-size: 13px;">
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
                    // Fetch Nazira records
                    $q = "SELECT n.* FROM daily_nazira n 
                          JOIN students s ON n.student_id = s.id 
                          WHERE n.date >= ? AND n.date <= ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                    $stmt = $pdo->prepare($q);
                    $stmt->execute([$start_date, $end_date, $selected_class]);
                    $nazira_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $nazira_map = [];
                    foreach ($nazira_records as $rec) {
                        $nazira_map[$rec['date']][$rec['student_id']] = $rec;
                    }

                    // Fetch Attendance for date range
                    $att_sql = "SELECT a.student_id, a.date, a.status_1, a.status_2 FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date BETWEEN ? AND ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                    try {
                        $att_stmt = $pdo->prepare($att_sql);
                        $att_stmt->execute([$start_date, $end_date, $selected_class]);
                        $att_records = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) { $att_records = []; }
                    $att_map = [];
                    foreach ($att_records as $a) {
                        $s1 = strtoupper(trim($a['status_1'] ?? ''));
                        $s2 = strtoupper(trim($a['status_2'] ?? ''));
                        if ($s1 === 'A' || $s2 === 'A' || $s1 === 'غ' || $s2 === 'غ') {
                            $att_map[$a['date']][$a['student_id']] = 'A';
                        } elseif ($s1 === 'L' || $s2 === 'L' || $s1 === 'ر' || $s2 === 'ر') {
                            $att_map[$a['date']][$a['student_id']] = 'L';
                        } else {
                            $att_map[$a['date']][$a['student_id']] = 'P';
                        }
                    }

                    // Generate dates in range (descending)
                    $report_dates = [];
                    $start_time = strtotime($start_date);
                    $end_time = strtotime($end_date);
                    for ($current_time = $end_time; $current_time >= $start_time; $current_time -= 86400) {
                        $report_dates[] = date('Y-m-d', $current_time);
                    }

                    $totalMorning = 0; $totalEvening = 0; $n_count = 0;
                    $students_with_records = [];

                    if (count($all_nazira_students) > 0) {
                        foreach ($report_dates as $date) {
                            $formatted_date = translateDateUrduFull($date);
                            echo "<tr class='table-secondary'><td colspan='7' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                            
                            $sr_no = 1;
                            foreach ($all_nazira_students as $student) {
                                $student_id = $student['id'];
                                $rec = $nazira_map[$date][$student_id] ?? null;
                                $attStatus = $att_map[$date][$student_id] ?? 'P';
                                
                                if ($attStatus === 'A' || $attStatus === 'L') {
                                    $attLabel = ($attStatus === 'A') ? 'غیر حاضر' : 'رخصت پر';
                                    $attClass = ($attStatus === 'A') ? 'bg-danger text-white' : 'bg-warning text-dark';
                                    $badgeHtml = "<span class='badge {$attClass}'>{$attLabel}</span>";
                                    $morning_html = $badgeHtml;
                                    $evening_html = $badgeHtml;
                                } elseif ($rec) {
                                    $students_with_records[] = $student_id;
                                    $totalMorning += getGradePoints($rec['morning_status'], 50);
                                    $totalEvening += getGradePoints($rec['evening_status'], 50);
                                    $n_count++;
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
                                } else {
                                    $morning_html = '-';
                                    $evening_html = '-';
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
                    } else {
                        echo "<tr><td colspan='7' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                    }

                    $students_with_records = array_unique($students_with_records);
                    $academicScore = ($n_count > 0) ? round(($totalMorning + $totalEvening) / $n_count) : 0;
                    ?>
                </tbody>
            </table>
        </div>

        <?php
        $students_with_records = array_unique($students_with_records);
        $lazy_students = [];
        foreach($all_nazira_students as $ns) {
            if(!in_array($ns['id'], $students_with_records)) {
                $lazy_students[] = $ns;
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
                        <th style="width: 30%;">طالب علم</th>
                        <th style="width: 30%;">ولدیت</th>
                        <th style="width: 30%;">کیفیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($lazy_students as $ls): ?>
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
</div>

<?php include 'footer.php'; ?>
