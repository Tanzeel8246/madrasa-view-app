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
 

$cur_tenant_id = (int)get_current_tenant_id();
try {
    $st_stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
    $st_stmt->execute([$cur_tenant_id]);
    $settings = $st_stmt->fetch();
    $week_start_day = $settings['week_start_day'] ?? 6;
} catch (Exception $e) {
    $week_start_day = 6;
}

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
    $all_nazira_students = $stmt_lazy->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if tenant_id was restrictive
    if (empty($all_nazira_students)) {
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
        $all_nazira_students = $stmt_lazy_fallback->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback to maktab_students if still empty
    if (empty($all_nazira_students) && $sys_type === 'Masjid') {
        try {
            $m_stmt = $pdo->prepare("
                SELECT s.*, $student_roll_select 
                FROM maktab_students s 
                WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
                  AND (s.class = ? OR TRIM(s.class) = ? OR s.class LIKE ?) 
                ORDER BY s.name ASC
            ");
            $m_stmt->execute([$selected_class, trim($selected_class), '%' . trim($selected_class) . '%']);
            $all_nazira_students = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eM) {}
    }

    $filtered_nazira = array_values(array_filter($all_nazira_students, function($st) use ($start_date) {
        return !is_student_inactive($st, $start_date);
    }));
    $all_nazira_students = !empty($filtered_nazira) ? $filtered_nazira : $all_nazira_students;
} catch (PDOException $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger'><strong>ڈیٹا بیس ایرر (Students):</strong> " . $e->getMessage() . "</div></div>";
    $all_nazira_students = [];
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
            <div class="col-md-3"><label class="fw-bold">تاریخ سے:</label><input type="date" name="from" id="nazFromDate" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" onchange="if(this.value && document.getElementById('nazToDate').value) this.form.submit();"></div>
            <div class="col-md-3"><label class="fw-bold">تاریخ تک:</label><input type="date" name="to" id="nazToDate" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" onchange="if(this.value && document.getElementById('nazFromDate').value) this.form.submit();"></div>
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
                    // Date normalization helper
                    $norm_date = function($dRaw) {
                        $dRaw = explode(' ', trim((string)$dRaw))[0];
                        if (empty($dRaw)) return '';
                        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                        elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                        $ts = strtotime($dRaw);
                        return $ts ? date('Y-m-d', $ts) : $dRaw;
                    };

                    // Fetch Nazira records
                    $q = "SELECT n.* FROM daily_nazira n 
                          JOIN students s ON n.student_id = s.id 
                          WHERE n.tenant_id = ? AND s.tenant_id = ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                    $stmt = $pdo->prepare($q);
                    $stmt->execute([$cur_tenant_id, $cur_tenant_id, $selected_class]);
                    $nazira_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $nazira_map = [];
                    foreach ($nazira_records as $rec) {
                        $dNorm = $norm_date($rec['date'] ?? '');
                        if ($dNorm >= $start_date && $dNorm <= $end_date) {
                            $nazira_map[$dNorm][$rec['student_id']] = $rec;
                        }
                    }

                    // Fetch Attendance for all class students
                    $all_n_sids = array_map(function($st) { return (int)$st['id']; }, $all_nazira_students);
                    $att_map = [];

                    if (!empty($all_n_sids)) {
                        $in_clause = implode(',', $all_n_sids);
                        
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

                    $totalMorning = 0; $totalEvening = 0; $n_count = 0;
                    $students_with_records = [];

                    if (count($all_nazira_students) > 0) {
                        foreach ($report_dates as $date) {
                            $formatted_date = translateDateUrduFull($date);
                            echo "<tr class='table-secondary'><td colspan='7' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                            
                            $day_of_week = date('N', strtotime($date));
                            $is_friday = ($day_of_week == 5);

                            $sr_no = 1;
                            foreach ($all_nazira_students as $student) {
                                $student_id = $student['id'];
                                $rec = $nazira_map[$date][$student_id] ?? null;
                                $attStatus = $att_map[$date][$student_id] ?? '';

                                $sAdm = !empty($student['admission_date']) ? $norm_date($student['admission_date']) : '';
                                $sGrad = !empty($student['graduation_date']) ? $norm_date($student['graduation_date']) : '';
                                $hasRecOnDay = !empty($rec) || !empty($attStatus);
                                if (!$hasRecOnDay && is_student_inactive($student, $date)) continue;






                                if ($is_friday) {
                                    $morning_html = "<span class='text-primary fw-bold'>جمعہ</span>";
                                    $evening_html = "<span class='text-primary fw-bold'>جمعہ</span>";
                                } else {
                                    if ($rec) {
                                        $chk_stat = function($st) {
                                            $st = mb_strtoupper(trim((string)$st), 'UTF-8');
                                            if (strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || strpos($st, 'ABSENT') !== false || $st === 'A') return 'A';
                                            if (strpos($st, 'رخصت') !== false || strpos($st, 'LEAVE') !== false || $st === 'L') return 'L';
                                            return '';
                                        };
                                        $ms = $chk_stat($rec['morning_status'] ?? '');
                                        $es = $chk_stat($rec['evening_status'] ?? '');
                                        if ($ms === 'A' || $es === 'A') $attStatus = 'A';
                                        elseif (($ms === 'L' || $es === 'L') && $attStatus !== 'A') $attStatus = 'L';
                                    }
                                    
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
                                            $morning_html = "<span class='badge bg-danger'>ناغہ</span>";
                                        }

                                        $e_sabaq = htmlspecialchars($rec['evening_sabaq'] ?? '');
                                        $e_qty = htmlspecialchars($rec['evening_qty'] ?? '-');
                                        $e_time = htmlspecialchars(substr($rec['evening_time'] ?? '', 0, 5));
                                        if(empty(trim($e_time))) $e_time = '-';
                                        $e_status = translateStatusClean($rec['evening_status'] ?? '');
                                        
                                        if ($e_sabaq || !empty($rec['evening_status'])) {
                                            $evening_html = "<strong>{$e_sabaq}</strong><br><small class='text-muted'>مقدار: {$e_qty} | وقت: {$e_time}</small><br>{$e_status}";
                                        } else {
                                            $evening_html = "<span class='badge bg-danger'>ناغہ</span>";
                                        }
                                    } else {
                                        $morning_html = "<span class='badge bg-danger'>ناغہ</span>";
                                        $evening_html = "<span class='badge bg-danger'>ناغہ</span>";
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
