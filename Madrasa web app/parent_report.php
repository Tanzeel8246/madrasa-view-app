<?php 
require_once 'db.php'; 
include 'header.php'; 

if (!isset($_SESSION['role'])) {
    echo "<script>window.location='login.php';</script>"; exit;
}

$user_role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$is_staff = in_array($user_role, ['admin', 'superadmin', 'nazim', 'teacher', 'mudarris'], true);

if ($is_staff) {
    $std_id = isset($_GET['std_id']) ? intval($_GET['std_id']) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0);
} else {
    $requested_id = isset($_GET['std_id']) ? intval($_GET['std_id']) : intval($_SESSION['user_id'] ?? 0);
    $logged_user_id = intval($_SESSION['user_id'] ?? 0);
    $is_authorized = false;

    if ($requested_id > 0 && $requested_id === $logged_user_id) {
        $is_authorized = true;
    } else if ($requested_id > 0) {
        // Check student_parents mapping
        $chkMap = $pdo->prepare("SELECT 1 FROM student_parents WHERE parent_id = ? AND student_id = ? LIMIT 1");
        $chkMap->execute([$logged_user_id, $requested_id]);
        if ($chkMap->fetch()) {
            $is_authorized = true;
        } else {
            // Check by parent phone
            $chkPhone = $pdo->prepare("SELECT 1 FROM students s WHERE s.id = ? AND (s.guardian_phone = (SELECT phone FROM parents WHERE id = ?) OR s.guardian_phone = (SELECT phone FROM students WHERE id = ?)) LIMIT 1");
            $chkPhone->execute([$requested_id, $logged_user_id, $logged_user_id]);
            if ($chkPhone->fetch()) {
                $is_authorized = true;
            }
        }
    }

    if (!$is_authorized) {
        echo "<div class='container my-5'><div class='alert alert-danger text-center fw-bold shadow-sm rounded-3 p-4'><i class='fas fa-shield-alt fa-2x mb-2 d-block'></i> معذرت! آپ کو اس طالب علم کا تعلیمی ریکارڈ یا رپورٹ دیکھنے کی اجازت نہیں ہے۔</div></div>";
        include 'footer.php';
        exit;
    }
    $std_id = $requested_id;
}
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$week_start_day = $settings['week_start_day'] ?? 6;

// Logic for default date: Current Week
$period = $_GET['period'] ?? 'weekly';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';

if (empty($start_date) || empty($end_date)) {
    $range = get_period_date_range($period, $start_date, $end_date);
    $start_date = $range['from'];
    $end_date = $range['to'];
}

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
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    $en_month = date('M', $timestamp);
    $en_day = date('D', $timestamp);
    $day_num = date('d', $timestamp);
    return $day_num . '-' . ($months[$en_month] ?? $en_month) . ' (' . ($days[$en_day] ?? $en_day) . ')';
}

function translateStatusClean($status) {
    if (empty($status) || $status === '-' || $status === 'ناغہ' || $status === 'Absent') return '-';
    $map = ['Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور', 'بہت اچھا'=>'بہت اچھا', 'اچھا'=>'اچھا', 'درمیانہ'=>'درمیانہ'];
    $color = ['Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger', 'بہت اچھا'=>'success', 'اچھا'=>'primary', 'درمیانہ'=>'warning', 'کمزور'=>'danger'];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}

function translateQtyClean($qty) {
    $map = ['Quarter'=> 'پاؤ', 'Half'=> 'آدھا', 'Three Quarter'=> 'تین پاؤ', 'Full'=> 'مکمل', '1.5 Parah'=> 'ڈیڑھ', '2 Parah'=> 'دو', '2.5 Parah'=> 'ڈھائی', '3 Parah'=> 'تین'];
    return $map[$qty] ?? $qty;
}

function parseManzilAmountToParas($amountStr) {
    if (empty($amountStr) || $amountStr === '-' || $amountStr === 'ناغہ') return 0;
    $s = trim($amountStr);
    $map = [
        'Quarter' => 0.25, 'پاؤ' => 0.25,
        'Half' => 0.5, 'آدھا' => 0.5,
        'Three Quarter' => 0.75, 'تین پاؤ' => 0.75,
        'Full' => 1.0, 'مکمل' => 1.0,
        '1 Parah' => 1.0, 'ایک پارہ' => 1.0, '1' => 1.0,
        '1.25 Parah' => 1.25, 'سوا پارہ' => 1.25,
        '1.5 Parah' => 1.5, 'ڈیڑھ' => 1.5, 'ڈیڑھ پارہ' => 1.5, '1.5' => 1.5,
        '2 Parah' => 2.0, 'دو پارے' => 2.0, 'دو' => 2.0, '2' => 2.0,
        '2.5 Parah' => 2.5, 'ڈھائی' => 2.5, 'ڈھائی پارے' => 2.5, '2.5' => 2.5,
        '3 Parah' => 3.0, 'تین' => 3.0, 'تین پارے' => 3.0, '3' => 3.0
    ];
    if (isset($map[$s])) return $map[$s];
    if (preg_match('/^([0-9\.]+)/', $s, $m)) {
        return floatval($m[1]);
    }
    return 0;
}

// AI Integration removed from here, now handled via AJAX for stability.

// Data Fetching & Calculation
$student_info = $pdo->prepare("SELECT s.*, c.class_type FROM students s LEFT JOIN classes c ON s.class = c.name WHERE s.id = ?");
$student_info->execute([$std_id]);
$student = $student_info->fetch();
$class_type = $student['class_type'] ?? '';
$is_qaida = ($class_type === 'Qaida' || $class_type === 'نورانی قاعدہ');
$is_nazira = ($class_type === 'Nazira');

if ($is_qaida) {
    $sql = "SELECT * FROM qaida_records WHERE student_id = ? AND date BETWEEN ? AND ? AND is_deleted = 0 ORDER BY date ASC";
} elseif ($is_nazira) {
    $sql = "SELECT * FROM daily_nazira WHERE student_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC";
} else {
    $sql = "SELECT * FROM daily_hifz WHERE student_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC";
}
$stmt = $pdo->prepare($sql);
$stmt->execute([$std_id, $start_date, $end_date]);
$hifz_rows = $stmt->fetchAll();

$totalSabaq = 0; $totalSabqi = 0; $totalManzil = 0; $h_count = count($hifz_rows);
foreach($hifz_rows as $row) {
    if ($is_qaida) {
        $totalSabaq += getGradePoints($row['grade_1']??'', 25);
        $totalSabaq += getGradePoints($row['grade_2']??'', 25);
        $totalSabaq += getGradePoints($row['grade_3']??'', 25);
        $totalSabaq += getGradePoints($row['grade_4']??'', 25);
    } elseif ($is_nazira) {
        $totalSabaq += getGradePoints($row['morning_status']??'', 50);
        $totalSabaq += getGradePoints($row['evening_status']??'', 50);
    } else {
        $totalSabaq += getGradePoints($row['sabaq_status']??'', 30);
        $totalSabqi += getGradePoints($row['sabqi_status']??'', 30);
        $totalManzil += getGradePoints($row['manzil_status']??'', 40);
    }
}

// Fetch Generic Academic Reports
$ar_sql = "SELECT * FROM academic_reports WHERE student_id = ? AND date BETWEEN ? AND ? AND is_deleted = 0 ORDER BY date ASC";
$ar_stmt = $pdo->prepare($ar_sql);
$ar_stmt->execute([$std_id, $start_date, $end_date]);
$ar_rows = $ar_stmt->fetchAll();

$ar_count = count($ar_rows);
$total_ar_points = 0;
foreach($ar_rows as $ar) {
    $total_ar_points += getGradePoints($ar['grade'], 100);
}

$effective_count = $h_count + $ar_count;
$academicScore = ($effective_count > 0) ? round(($totalSabaq + $totalSabqi + $totalManzil + $total_ar_points) / $effective_count) : 0;

$att_query = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND date BETWEEN ? AND ?");
$att_query->execute([$std_id, $start_date, $end_date]);
$atts = $att_query->fetchAll();
$p=0;$a=0;$l=0;$late=0;
foreach($atts as $at) {
    for($i=1;$i<=4;$i++) {
        if(($at["status_$i"] ?? '') == 'P') {
            if(($at["late_$i"] ?? 0) > 0) $late++;
            else $p++;
        }
        elseif(($at["status_$i"] ?? '') == 'A') $a++;
        elseif(($at["status_$i"] ?? '') == 'L') $l++;
    }
}
$total_att = $p+$late+$a+$l;
$attendancePerc = ($total_att > 0) ? round((($p + $late) / $total_att) * 100) : 0;

// Build attendance by date
$att_day_status = [];
foreach($atts as $at) {
    $d = $at['date'];
    $is_p = false; $is_l = false; $is_a = false;
    for($i=1;$i<=4;$i++) {
        $st = strtoupper($at["status_$i"] ?? '');
        if ($st === 'P' || ($at["late_$i"] ?? 0) > 0) $is_p = true;
        elseif ($st === 'L') $is_l = true;
        elseif ($st === 'A') $is_a = true;
    }
    if ($is_p) $att_day_status[$d] = 'P';
    elseif ($is_l) $att_day_status[$d] = 'L';
    elseif ($is_a) $att_day_status[$d] = 'A';
}

try {
    $p_stmt = $pdo->prepare("SELECT date, status FROM period_attendance WHERE student_id = ? AND date BETWEEN ? AND ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $p_stmt->execute([$std_id, $start_date, $end_date]);
    while($pa = $p_stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = $pa['date'];
        if (!isset($att_day_status[$d])) {
            $st = strtoupper($pa['status'] ?? '');
            if ($st === 'P' || $st === 'PRESENT' || $st === 'حاضر') $att_day_status[$d] = 'P';
            elseif ($st === 'L' || $st === 'LEAVE' || $st === 'رخصت') $att_day_status[$d] = 'L';
            elseif ($st === 'A' || $st === 'ABSENT' || $st === 'غیر حاضر') $att_day_status[$d] = 'A';
        }
    }
} catch(Exception $e) {}

$hifz_by_date = [];
foreach($hifz_rows as $row) {
    $hifz_by_date[$row['date']] = $row;
}

$all_dates_set = array_unique(array_merge(array_keys($att_day_status), array_keys($hifz_by_date)));
sort($all_dates_set);

$adm_date = $student['admission_date'] ?? '';
if (!empty($adm_date)) {
    $all_dates_set = array_values(array_filter($all_dates_set, function($d) use ($adm_date) {
        return $d >= $adm_date;
    }));
}

$total_sabaq_lines = 0;
$missed_sabaq = 0;
$sabqi_days_recited = 0;
$missed_sabqi = 0;
$manzil_paras_recited = 0;
$missed_manzil = 0;

$unified_report_rows = [];
$total_present_days = 0;
$total_absent_days = 0;
$total_leave_days = 0;

foreach($all_dates_set as $d) {
    $att = $att_day_status[$d] ?? 'P';
    $h = $hifz_by_date[$d] ?? null;

    if ($att === 'A') {
        $total_absent_days++;
        $unified_report_rows[] = [
            'date' => $d,
            'att_status' => 'A',
            'type' => 'absent'
        ];
    } elseif ($att === 'L') {
        $total_leave_days++;
        $unified_report_rows[] = [
            'date' => $d,
            'att_status' => 'L',
            'type' => 'leave'
        ];
    } else {
        $total_present_days++;
        if ($h) {
            if ($is_qaida) {
                $q_s1 = trim($h['sabaq_1'] ?? '');
                $q_g1 = trim($h['grade_1'] ?? '');
                $is_s_nagha = ($q_s1 === '' || $q_s1 === 'ناغہ' || $q_g1 === 'ناغہ' || $q_g1 === 'Absent');
                if ($is_s_nagha) $missed_sabaq++;
                else $total_sabaq_lines += 1;
                $unified_report_rows[] = [
                    'date' => $d,
                    'att_status' => 'P',
                    'type' => 'present_qaida',
                    'row' => $h
                ];
            } elseif ($is_nazira) {
                $m_s = trim($h['morning_sabaq'] ?? '');
                $m_stat = trim($h['morning_status'] ?? '');
                $is_s_nagha = ($m_s === '' || $m_s === 'ناغہ' || $m_stat === 'ناغہ' || $m_stat === 'Absent');
                if ($is_s_nagha) $missed_sabaq++;
                else $total_sabaq_lines += 1;
                $unified_report_rows[] = [
                    'date' => $d,
                    'att_status' => 'P',
                    'type' => 'present_nazira',
                    'row' => $h
                ];
            } else {
                $s_lines = intval($h['sabaq_lines'] ?? 0);
                $s_stat = trim($h['sabaq_status'] ?? $h['sabaq_grade'] ?? '');
                $s_parah = trim($h['sabaq_parah'] ?? '');
                $is_sabaq_nagha = ($s_stat === 'ناغہ' || $s_stat === 'Absent' || $s_stat === 'نہیں سنایا' || ($s_lines === 0 && empty($s_parah)));
                if ($is_sabaq_nagha) {
                    $missed_sabaq++;
                } else {
                    $total_sabaq_lines += $s_lines;
                }

                $sq_stat = trim($h['sabqi_status'] ?? $h['sabqi_grade'] ?? '');
                $sq_parah = trim($h['sabqi_parah'] ?? '');
                $sq_amt = trim($h['sabqi_amount'] ?? '');
                $is_sabqi_nagha = ($sq_stat === 'ناغہ' || $sq_stat === 'Absent' || $sq_stat === 'نہیں سنائی' || (empty($sq_parah) && empty($sq_amt)));
                if ($is_sabqi_nagha) {
                    $missed_sabqi++;
                } else {
                    $sabqi_days_recited++;
                }

                $m_stat = trim($h['manzil_status'] ?? $h['manzil_grade'] ?? '');
                $m_parah = trim($h['manzil_parah'] ?? '');
                $m_amt = trim($h['manzil_amount'] ?? '');
                $is_manzil_nagha = ($m_stat === 'ناغہ' || $m_stat === 'Absent' || $m_stat === 'نہیں سنائی' || (empty($m_parah) && empty($m_amt)));
                if ($is_manzil_nagha) {
                    $missed_manzil++;
                } else {
                    $paras = parseManzilAmountToParas($m_amt);
                    if ($paras == 0 && !empty($m_parah)) $paras = 1.0;
                    $manzil_paras_recited += $paras;
                }

                $unified_report_rows[] = [
                    'date' => $d,
                    'att_status' => 'P',
                    'type' => 'present_hifz',
                    'row' => $h,
                    'is_sabaq_nagha' => $is_sabaq_nagha,
                    'is_sabqi_nagha' => $is_sabqi_nagha,
                    'is_manzil_nagha' => $is_manzil_nagha,
                ];
            }
        } else {
            $missed_sabaq++;
            $missed_sabqi++;
            $missed_manzil++;
            $unified_report_rows[] = [
                'date' => $d,
                'att_status' => 'P',
                'type' => 'present_no_record',
                'is_sabaq_nagha' => true,
                'is_sabqi_nagha' => true,
                'is_manzil_nagha' => true,
            ];
        }
    }
}
$present_dates = array_flip(array_keys(array_filter($att_day_status, function($st) { return $st === 'P'; })));

$aiAdvice = "..."; // Placeholder

// Fetch Attendance for Register
$at_query = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND date BETWEEN ? AND ? ORDER BY date DESC");
$at_query->execute([$std_id, $start_date, $end_date]);
$all_atts = $at_query->fetchAll();

    // Fetch Complaints
    $c_params = [$std_id, $start_date, $end_date];
    $c_sql = "SELECT id, date, complaint as description, 'admin' as type FROM complaints WHERE student_id = ? AND date BETWEEN ? AND ?";
    
    $pc_sql = "SELECT id, date, CONCAT('[', category, '] ', description) as description, 'parent' as type FROM parent_complaints WHERE student_id = ? AND date BETWEEN ? AND ?";
    
    $all_c_sql = "$c_sql UNION ALL $pc_sql ORDER BY date DESC";
    $comp_query = $pdo->prepare($all_c_sql);
    $comp_query->execute(array_merge($c_params, $c_params));
    $list = $comp_query->fetchAll();

// Fetch Exam Results
$exam_query = $pdo->prepare("SELECT er.*, e.exam_name FROM exam_results er JOIN exams e ON er.exam_id = e.id WHERE er.student_id = ? ORDER BY e.exam_date DESC LIMIT 5");
$exam_query->execute([$std_id]);
$exam_results = $exam_query->fetchAll();

// Fetch Fee Status
$fee_status_str = '';
try {
    $fee_stmt = $pdo->prepare("SELECT * FROM fee_submissions WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $fee_stmt->execute([$std_id]);
    $last_fee = $fee_stmt->fetch();
    if ($last_fee) {
        $st = strtolower($last_fee['status'] ?? 'pending');
        $amt = $last_fee['amount'] ?? 0;
        if ($st === 'approved' || $st === 'paid' || $st === 'ادا شدہ') {
            $fee_status_str = 'ادا شدہ (' . $amt . ' روپے)';
        } else {
            $fee_status_str = 'بقایا (' . $amt . ' روپے)';
        }
    }
} catch (Exception $e) {}

// Build formatted WhatsApp message
$std_full_name = ($student['name'] ?? 'طالب علم') . (!empty($student['father_name']) ? (" ولد " . $student['father_name']) : "");
$complaints_cnt = count($list ?? []);
$wa_msg = "📋 *مجموعی رپورٹ مدرسہ اشرف المدارس فروکہ*\n";
$wa_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$wa_msg .= "👤 *برائے :* " . $std_full_name . "\n";
if (!empty($student['class'])) {
    $wa_msg .= "🏫 *جماعت :* " . $student['class'] . "\n";
}
$wa_msg .= "📅 *از :* " . $start_date . " *تا :* " . $end_date . "\n";
$wa_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$wa_msg .= "📊 *حاضری :* " . $attendancePerc . " فیصد\n";
$wa_msg .= "📖 *سبق :* " . $missed_sabaq . " ناغے\n";
if (!$is_qaida) {
    $wa_msg .= "📚 *سبقی :* " . $missed_sabqi . " ناغے\n";
    $wa_msg .= "🕋 *منزل :* " . $missed_manzil . " ناغے\n";
}
$wa_msg .= "⚠️ *شکایات :* " . $complaints_cnt . "\n";
if (!empty($fee_status_str)) {
    $wa_msg .= "💰 *فیس سٹیٹس :* " . $fee_status_str . "\n";
}
$wa_msg .= "━━━━━━━━━━━━━━━━━━━━\n";
$wa_msg .= "_منجانب: ادارہ اشرف المدارس فروکہ_";

// Fetch Monthly Attendance Data for Chart.js
$att_labels = [];
$att_values = [];
try {
    $monthly_att_query = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as ym,
            COUNT(*) as total_days,
            SUM(CASE WHEN (status_1='P' OR late_1 > 0 OR status_2='P' OR late_2 > 0) THEN 1 ELSE 0 END) as presents
        FROM attendance 
        WHERE student_id = ?
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY ym ASC
        LIMIT 6
    ");
    $monthly_att_query->execute([$std_id]);
    $monthly_att_data = $monthly_att_query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthly_att_data as $m) {
        $month_label = date('M Y', strtotime($m['ym'] . '-01'));
        $att_labels[] = $month_label;
        $perc = $m['total_days'] > 0 ? round(($m['presents'] / $m['total_days']) * 100) : 0;
        $att_values[] = $perc;
    }
} catch (Exception $e) {}

// Fetch Exam Results Data for Chart.js
$exam_labels = [];
$exam_scores = [];
if (!empty($exam_results)) {
    foreach (array_reverse($exam_results) as $ex) {
        $exam_labels[] = $ex['exam_name'] ?? 'امتحان';
        $t = (float)($ex['total_marks'] ?? 100);
        $o = (float)($ex['obtained_marks'] ?? 0);
        $exam_scores[] = $t > 0 ? round(($o / $t) * 100) : 0;
    }
}
?>

<style>
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
    .ai-card { background: var(--bg-gradient-green-val) !important; color: var(--stat-card-green-text) !important; border-radius: 12px; padding: 15px; margin-top: 10px; }
    @media print {
        @page { size: landscape; margin: 5mm; }
        body * { visibility: hidden; }
        #printableArea, #printableArea * { visibility: visible; }
        #printableArea { position: absolute; left: 0; top: 0; width: 100%; }
        .d-print-none { display: none !important; }
        table { width: 100% !important; border-collapse: collapse; }
        td, th { padding: 2px 4px !important; font-size: 10px !important; border: 1px solid #333 !important; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
<div class="d-print-none d-flex justify-content-end gap-2 mb-4">
    <button type="button" data-bs-toggle="modal" data-bs-target="#studentWhatsAppModal" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
        <i class="fab fa-whatsapp me-2"></i> WhatsApp مختصر رپورٹ
    </button>
    <button onclick="sendToParent('sms')" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">
        <i class="fas fa-sms me-2"></i> SMS رپورٹ بھیجیں
    </button>
</div>

<!-- Student WhatsApp Modal -->
<div class="modal fade" id="studentWhatsAppModal" tabindex="-1" aria-labelledby="studentWhatsAppModalLabel" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="studentWhatsAppModalLabel"><i class="fab fa-whatsapp me-2"></i> طالب علم کی مختصر WhatsApp رپورٹ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="copySuccessAlertStudent" class="alert alert-success d-none py-2 text-center small fw-bold">
                    <i class="fas fa-check-circle me-1"></i> خلاصہ کامیابی سے کاپی ہو گیا!
                </div>
                <label class="form-label fw-bold small text-muted">پیغام کا متن:</label>
                <textarea id="student_wa_text" class="form-control font-monospace" rows="10" readonly style="direction: rtl; font-size: 13px;"><?php echo htmlspecialchars($wa_msg); ?></textarea>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">بند کریں</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" onclick="copyStudentWhatsAppText()">
                        <i class="fas fa-copy me-1"></i> کاپی کریں
                    </button>
                    <a href="https://wa.me/?text=<?php echo urlencode($wa_msg); ?>" target="_blank" class="btn btn-success btn-sm fw-bold shadow-sm">
                        <i class="fab fa-whatsapp me-1"></i> WhatsApp پر کھولیں
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyStudentWhatsAppText() {
    var copyText = document.getElementById("student_wa_text");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var alertBox = document.getElementById('copySuccessAlertStudent');
        alertBox.classList.remove('d-none');
        setTimeout(function() {
            alertBox.classList.add('d-none');
        }, 2500);
    });
}
function sendToParent(type) {
    if(!confirm("کیا آپ والدین کو یہ رپورٹ بھیجنا چاہتے ہیں؟")) return;
    const sId = "<?php echo $std_id; ?>";
    const form = new FormData();
    form.append('student_id', sId);
    form.append('type', type);
    fetch('trigger_notify.php', { method: 'POST', body: form })
    .then(r => r.json()).then(data => { alert(data.message); }).catch(e => alert("Error: " + e));
}
</script>
<?php endif; ?>

<style>
    .table-responsive { width: 100% !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table th, .table td { word-break: break-word !important; white-space: normal !important; }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <form method="GET" class="row g-2 align-items-end">
        <?php if (isset($_GET['std_id'])): ?>
            <input type="hidden" name="std_id" value="<?php echo htmlspecialchars($_GET['std_id']); ?>">
        <?php endif; ?>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">دورانیہ (Period):</label>
            <select name="period" id="parentPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyParentPeriodDates(this.value)">
                <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (Weekly)</option>
                <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (Monthly)</option>
                <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (3 ماہی)</option>
                <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (6 ماہی)</option>
                <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (Annually)</option>
                <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">کب سے (From Date)</label>
            <input type="date" name="from" id="parentFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('parentToDate').value) this.form.submit();" required>
        </div>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">کب تک (To Date)</label>
            <input type="date" name="to" id="parentToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('parentFromDate').value) this.form.submit();" required>
        </div>
        <div class="col-6 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> تلاش کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-print me-1"></i> پرنٹ</button>
        </div>
    </form>
</div>

<script>
function applyParentPeriodDates(val) {
    const today = new Date();
    const formatDate = (d) => {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    let fromDate = new Date();
    let toDate = new Date();
    if (val === 'weekly') {
        fromDate.setDate(today.getDate() - 6);
        toDate = today;
    } else if (val === 'monthly') {
        fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
        toDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    } else if (val === 'quarterly') {
        fromDate = new Date();
        fromDate.setMonth(today.getMonth() - 3);
        toDate = today;
    } else if (val === 'semi_annually') {
        fromDate = new Date();
        fromDate.setMonth(today.getMonth() - 6);
        toDate = today;
    } else if (val === 'annually') {
        fromDate = new Date(today.getFullYear(), 0, 1);
        toDate = today;
    }
    if (val !== 'custom') {
        document.getElementById('parentFromDate').value = formatDate(fromDate);
        document.getElementById('parentToDate').value = formatDate(toDate);
        document.getElementById('parentPeriodSelect').form.submit();
    }
}
</script>

<div id="printableArea" class="bg-white p-2">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-2 pb-1">
        <div>
            <h3 class="m-0 fw-bold text-success"><?php echo t('app_name'); ?></h3>
            <h6 class="text-muted m-0">تعلیمی و اخلاقی رپورٹ (Performance Report)</h6>
        </div>
        <div class="text-end">
            <p class="mb-0 fw-bold small">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p>
            <?php
            // Student info already fetched at the top
            ?>
            <?php if($student): ?>
            <p class="mb-0 fw-bold"><?php echo $student['name']; ?> ولدیت: <?php echo $student['father_name']; ?> | درجہ: <?php echo $student['class']; ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Comprehensive Academic & Attendance Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-2 bg-light border-top border-4 border-success">
                <div class="small fw-bold text-success"><i class="fas fa-book-open me-1"></i> سبق کی سمری</div>
                <div class="d-flex justify-content-around align-items-center mt-2">
                    <div>
                        <div class="fw-bold fs-5 text-dark"><?= $total_sabaq_lines ?></div>
                        <div class="text-muted" style="font-size: 10px;">کل سنائی گئی لائنیں</div>
                    </div>
                    <div class="border-start ps-2">
                        <div class="fw-bold fs-5 <?= $missed_sabaq > 0 ? 'text-danger' : 'text-success' ?>"><?= $missed_sabaq ?></div>
                        <div class="text-muted" style="font-size: 10px;">سبق کے ناغے</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-2 bg-light border-top border-4 border-primary">
                <div class="small fw-bold text-primary"><i class="fas fa-book me-1"></i> سبقی کی سمری</div>
                <div class="d-flex justify-content-around align-items-center mt-2">
                    <div>
                        <div class="fw-bold fs-5 text-dark"><?= $sabqi_days_recited ?> دن</div>
                        <div class="text-muted" style="font-size: 10px;">سنائے گئے کل ایام</div>
                    </div>
                    <div class="border-start ps-2">
                        <div class="fw-bold fs-5 <?= $missed_sabqi > 0 ? 'text-danger' : 'text-success' ?>"><?= $missed_sabqi ?></div>
                        <div class="text-muted" style="font-size: 10px;">سبقی کے ناغے</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-2 bg-light border-top border-4 border-warning">
                <div class="small fw-bold text-warning text-dark"><i class="fas fa-quran me-1"></i> منزل کی سمری</div>
                <div class="d-flex justify-content-around align-items-center mt-2">
                    <div>
                        <div class="fw-bold fs-5 text-dark"><?= round($manzil_paras_recited, 1) ?> پارے</div>
                        <div class="text-muted" style="font-size: 10px;">سنائے گئے کل پارے</div>
                    </div>
                    <div class="border-start ps-2">
                        <div class="fw-bold fs-5 <?= $missed_manzil > 0 ? 'text-danger' : 'text-success' ?>"><?= $missed_manzil ?></div>
                        <div class="text-muted" style="font-size: 10px;">منزل کے ناغے</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-3 text-center p-2 bg-light border-top border-4 border-info">
                <div class="small fw-bold text-info"><i class="fas fa-user-check me-1"></i> حاضری کی سمری</div>
                <div class="d-flex justify-content-around align-items-center mt-2">
                    <div>
                        <div class="fw-bold fs-6 text-success"><?= $total_present_days ?> حاضر</div>
                        <div class="text-muted" style="font-size: 10px;">حاضری: <?= $attendancePerc ?>%</div>
                    </div>
                    <div class="border-start ps-1">
                        <div class="fw-bold fs-6 text-danger"><?= $total_absent_days ?> غیرحاضر</div>
                        <div class="fw-bold text-warning text-dark" style="font-size: 10px;"><?= $total_leave_days ?> رخصت</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Interactive Charts Section -->
    <div class="row g-3 mb-3 d-print-none">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-dark text-white fw-bold py-2 small" style="background: #284D3D !important;">
                    <i class="fas fa-chart-line me-1"></i> ماہانہ حاضری ٹرینڈ (%)
                </div>
                <div class="card-body p-2" style="height: 200px;">
                    <canvas id="attendanceLineChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-success text-white fw-bold py-2 small">
                    <i class="fas fa-chart-bar me-1"></i> امتحانی نتائج (%)
                </div>
                <div class="card-body p-2" style="height: 200px;">
                    <canvas id="examsBarChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Register-style Hifz Table -->
    <div class="table-responsive">
        <table class="table table-bordered border-dark text-center table-sm align-middle" style="font-size: 11px; width: 100%;">
            <?php if ($is_qaida): ?>
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width:15%;">تاریخ</th>
                    <th colspan="3" class="bg-success text-white" style="--bs-bg-opacity:.8;">سبق 1</th>
                    <th colspan="3" class="bg-primary text-white" style="--bs-bg-opacity:.8;">سبق 2</th>
                    <th colspan="3" class="bg-warning text-dark" style="--bs-bg-opacity:.8;">سبق 3</th>
                    <th colspan="3" class="bg-info text-white" style="--bs-bg-opacity:.8;">سبق 4</th>
                    <th rowspan="2" class="bg-secondary text-white" style="--bs-bg-opacity:.8;">رویہ</th>
                </tr>
                <tr style="font-size:10px;">
                    <th>تفصیل</th><th>مقدار</th><th>گریڈ</th>
                    <th>تفصیل</th><th>مقدار</th><th>گریڈ</th>
                    <th>تفصیل</th><th>مقدار</th><th>گریڈ</th>
                    <th>تفصیل</th><th>مقدار</th><th>گریڈ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(count($unified_report_rows) > 0) {
                    foreach($unified_report_rows as $u) {
                        $d_disp = translateDateUrduFull($u['date']);
                        if ($u['type'] === 'absent') {
                            echo "<tr class='table-danger'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='13' class='text-center py-2'><span class='badge bg-danger fs-6 px-3 py-1'>❌ غیر حاضر (Absent)</span></td></tr>";
                        } elseif ($u['type'] === 'leave') {
                            echo "<tr class='table-warning'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='13' class='text-center py-2'><span class='badge bg-warning text-dark fs-6 px-3 py-1'>⚠️ رخصت (On Leave)</span></td></tr>";
                        } elseif ($u['type'] === 'present_no_record') {
                            echo "<tr class='table-light'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='13'><span class='badge bg-danger'>ناغہ (سبق نہیں سنایا)</span></td></tr>";
                        } else {
                            $row = $u['row'];
                            $s1 = htmlspecialchars($row['sabaq_1'] ?? '-'); $q1 = htmlspecialchars($row['qty_1'] ?? '-'); $g1 = translateStatusClean($row['grade_1'] ?? '-');
                            $s2 = htmlspecialchars($row['sabaq_2'] ?? '-'); $q2 = htmlspecialchars($row['qty_2'] ?? '-'); $g2 = translateStatusClean($row['grade_2'] ?? '-');
                            $s3 = htmlspecialchars($row['sabaq_3'] ?? '-'); $q3 = htmlspecialchars($row['qty_3'] ?? '-'); $g3 = translateStatusClean($row['grade_3'] ?? '-');
                            $s4 = htmlspecialchars($row['sabaq_4'] ?? '-'); $q4 = htmlspecialchars($row['qty_4'] ?? '-'); $g4 = translateStatusClean($row['grade_4'] ?? '-');
                            $beh = htmlspecialchars($row['behavior'] ?? '-');
                            $ht = empty($row['hearing_time']) ? '' : "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['hearing_time']))."</small>";
                            echo "<tr>
                                <td class='fw-bold text-start ps-1'>{$d_disp}{$ht}</td>
                                <td>{$s1}</td><td>{$q1}</td><td>{$g1}</td>
                                <td>{$s2}</td><td>{$q2}</td><td>{$g2}</td>
                                <td>{$s3}</td><td>{$q3}</td><td>{$g3}</td>
                                <td>{$s4}</td><td>{$q4}</td><td>{$g4}</td>
                                <td class='small fw-bold'>{$beh}</td>
                            </tr>";
                        }
                    }
                } else {
                    echo "<tr><td colspan='14' class='text-danger p-3 fw-bold text-center'>اس عرصے میں کوئی ریکارڈ نہیں ملا</td></tr>";
                }
                ?>
            </tbody>
            <?php elseif ($is_nazira): ?>
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width:15%;">تاریخ</th>
                    <th colspan="3" class="bg-success text-white" style="--bs-bg-opacity:.8;">صبح کا سبق</th>
                    <th colspan="3" class="bg-primary text-white" style="--bs-bg-opacity:.8;">شام کا سبق</th>
                </tr>
                <tr style="font-size:10px;">
                    <th>پارہ</th><th>مقدار</th><th>گریڈ</th>
                    <th>پارہ</th><th>مقدار</th><th>گریڈ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(count($unified_report_rows) > 0) {
                    foreach($unified_report_rows as $u) {
                        $d_disp = translateDateUrduFull($u['date']);
                        if ($u['type'] === 'absent') {
                            echo "<tr class='table-danger'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='6' class='text-center py-2'><span class='badge bg-danger fs-6 px-3 py-1'>❌ غیر حاضر (Absent)</span></td></tr>";
                        } elseif ($u['type'] === 'leave') {
                            echo "<tr class='table-warning'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='6' class='text-center py-2'><span class='badge bg-warning text-dark fs-6 px-3 py-1'>⚠️ رخصت (On Leave)</span></td></tr>";
                        } elseif ($u['type'] === 'present_no_record') {
                            echo "<tr class='table-light'><td class='fw-bold text-start ps-1'>{$d_disp}</td><td colspan='6'><span class='badge bg-danger'>ناغہ (سبق نہیں سنایا)</span></td></tr>";
                        } else {
                            $row = $u['row'];
                            $m_time = !empty($row['morning_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['morning_time']))."</small>" : "";
                            $e_time = !empty($row['evening_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['evening_time']))."</small>" : "";
                            echo "<tr>
                                <td class='fw-bold text-start ps-1'>{$d_disp}</td>
                                <td class='fw-bold text-success'>{$row['morning_sabaq']}</td><td>{$row['morning_qty']}</td><td>".translateStatusClean($row['morning_status']).$m_time."</td>
                                <td class='fw-bold text-primary'>{$row['evening_sabaq']}</td><td>{$row['evening_qty']}</td><td>".translateStatusClean($row['evening_status']).$e_time."</td>
                            </tr>";
                        }
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-danger p-3 fw-bold text-center'>اس عرصے میں کوئی ریکارڈ نہیں ملا</td></tr>";
                }
                ?>
            </tbody>
            <?php else: ?>
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width:15%;">تاریخ</th>
                    <th colspan="3" class="bg-success text-white" style="--bs-bg-opacity:.8;">سبق</th>
                    <th colspan="4" class="bg-primary text-white" style="--bs-bg-opacity:.8;">سبقی</th>
                    <th colspan="4" class="bg-warning text-dark" style="--bs-bg-opacity:.8;">منزل</th>
                </tr>
                <tr style="font-size:10px;">
                    <th>پارہ</th><th>لائنز</th><th>گریڈ</th>
                    <th>پارہ</th><th>مقدار</th><th>گریڈ</th><th>سننے والا</th>
                    <th>تفصیل</th><th>مقدار</th><th>گریڈ</th><th>سننے والا</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(count($unified_report_rows) > 0) {
                    foreach($unified_report_rows as $u) {
                        $d_disp = translateDateUrduFull($u['date']);
                        if ($u['type'] === 'absent') {
                            echo "<tr class='table-danger'>
                                <td class='fw-bold text-start ps-1'>{$d_disp}</td>
                                <td colspan='11' class='text-center py-2'><span class='badge bg-danger fs-6 px-3 py-1'><i class='fas fa-times-circle me-1'></i> غیر حاضر (Absent)</span></td>
                            </tr>";
                        } elseif ($u['type'] === 'leave') {
                            echo "<tr class='table-warning'>
                                <td class='fw-bold text-start ps-1'>{$d_disp}</td>
                                <td colspan='11' class='text-center py-2'><span class='badge bg-warning text-dark fs-6 px-3 py-1'><i class='fas fa-clock me-1'></i> رخصت (On Leave)</span></td>
                            </tr>";
                        } elseif ($u['type'] === 'present_no_record') {
                            echo "<tr class='table-light'>
                                <td class='fw-bold text-start ps-1'>{$d_disp}</td>
                                <td>-</td><td>0</td><td><span class='badge bg-danger'>ناغہ</span></td>
                                <td>-</td><td>-</td><td><span class='badge bg-danger'>ناغہ</span></td><td>-</td>
                                <td>-</td><td>-</td><td><span class='badge bg-danger'>ناغہ</span></td><td>-</td>
                            </tr>";
                        } else {
                            $row = $u['row'];
                            $l_sabqi = isset($row['sabqi_listener']) ? (mb_strlen($row['sabqi_listener']) > 12 ? mb_substr($row['sabqi_listener'], 0, 12).'..' : $row['sabqi_listener']) : '-';
                            $l_manzil = isset($row['manzil_listener']) ? (mb_strlen($row['manzil_listener']) > 12 ? mb_substr($row['manzil_listener'], 0, 12).'..' : $row['manzil_listener']) : '-';
                            $manzil_qty_map = ['Quarter'=>'پاؤ','Half'=>'آدھا','Three Quarter'=>'تین پاؤ','Full'=>'مکمل','1.5 Parah'=>'ڈیڑھ','2 Parah'=>'دو','2.5 Parah'=>'ڈھائی','3 Parah'=>'تین'];
                            $manzil_qty = $manzil_qty_map[$row['manzil_amount'] ?? ''] ?? ($row['manzil_amount'] ?? '-');

                            $s_stat_disp = $u['is_sabaq_nagha'] ? "<span class='badge bg-danger'>ناغہ</span>" : translateStatusClean($row['sabaq_status'] ?? $row['sabaq_grade'] ?? '');
                            $sq_stat_disp = $u['is_sabqi_nagha'] ? "<span class='badge bg-danger'>ناغہ</span>" : translateStatusClean($row['sabqi_status'] ?? $row['sabqi_grade'] ?? '');
                            $m_stat_disp = $u['is_manzil_nagha'] ? "<span class='badge bg-danger'>ناغہ</span>" : translateStatusClean($row['manzil_status'] ?? $row['manzil_grade'] ?? '');

                            $s_para_disp = !empty($row['sabaq_parah']) ? htmlspecialchars($row['sabaq_parah']) : '-';
                            $s_lines_disp = ($u['is_sabaq_nagha'] && empty($row['sabaq_lines'])) ? '0' : htmlspecialchars($row['sabaq_lines'] ?? '0');

                            $sq_para_disp = !empty($row['sabqi_parah']) ? htmlspecialchars($row['sabqi_parah']) : '-';
                            $sq_amt_disp = !empty($row['sabqi_amount']) ? htmlspecialchars($row['sabqi_amount']) : '-';

                            $m_para_disp = !empty($row['manzil_parah']) ? htmlspecialchars($row['manzil_parah']) : '-';

                            $s_time = !empty($row['sabaq_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['sabaq_time']))."</small>" : "";
                            $sq_time = !empty($row['sabqi_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['sabqi_time']))."</small>" : "";
                            $m_time = !empty($row['manzil_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['manzil_time']))."</small>" : "";

                            echo "<tr>
                                <td class='fw-bold text-start ps-1'>{$d_disp}</td>
                                <td class='fw-bold'>{$s_para_disp}</td><td>{$s_lines_disp}</td><td>{$s_stat_disp}{$s_time}</td>
                                <td>{$sq_para_disp}</td><td>{$sq_amt_disp}</td><td>{$sq_stat_disp}{$sq_time}</td><td class='small text-muted'>$l_sabqi</td>
                                <td class='text-start ps-1 small'>{$m_para_disp}</td><td>$manzil_qty</td><td>{$m_stat_disp}{$m_time}</td><td class='small text-muted'>$l_manzil</td>
                            </tr>";
                        }
                    }
                } else {
                    echo "<tr><td colspan='12' class='text-danger p-3 fw-bold text-center'>اس عرصے میں کوئی ریکارڈ نہیں ملا</td></tr>";
                }
                ?>
            </tbody>
            <?php endif; ?>
        </table>
    </div>

    <!-- Generic Academic Reports (Non-Hifz Subjects) -->
    <?php if($ar_count > 0): ?>
    <div class="mt-3">
        <h6 class="fw-bold text-dark border-bottom border-2 border-dark pb-1 mb-2 small"><i class="fas fa-book me-2"></i> تعلیمی مضامین (Educational Subjects)</h6>
        <div class="table-responsive">
            <table class="table table-bordered border-dark text-center table-sm align-middle" style="font-size: 11px; width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;">تاریخ</th>
                        <th>کلاس</th>
                        <th>مضمون</th>
                        <th>سبق کی تفصیل</th>
                        <th style="width: 10%;">گریڈ</th>
                        <th>ریمارکس</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ar_rows as $ar): ?>
                    <tr>
                        <td class="fw-bold"><?php echo translateDateUrduFull($ar['date']); ?></td>
                        <td><?php echo $ar['class_name']; ?></td>
                        <td class="text-primary fw-bold"><?php echo $ar['subject']; ?></td>
                        <td class="text-start"><?php echo $ar['lesson_details']; ?></td>
                        <td><?php echo translateStatusClean($ar['grade']); ?></td>
                        <td class="small"><?php echo $ar['remarks']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top: Performance Gauges + AI Advice -->
    <div class="row g-2 mt-1">
        <div class="col-md-4">
            <!-- Performance Gauge -->
            <div class="p-2 border rounded border-dark h-100" style="background:#f8f9fa;">
                <h6 class="fw-bold border-bottom border-dark pb-1 mb-1 small text-center">ہفتہ وار کارکردگی</h6>
                <?php if($h_count == 0 && $ar_count == 0 && $total_att == 0): ?>
                    <p class="text-center text-muted small mt-3">کوئی ریکارڈ نہیں</p>
                <?php else: ?>
                <div class="row text-center mt-2 g-2">
                    <div class="col-6">
                        <div class="gauge-container mb-1">
                            <svg class="gauge-svg" viewBox="0 0 100 100">
                                <circle class="gauge-bg" cx="50" cy="50" r="45"/>
                                <circle class="gauge-fill" cx="50" cy="50" r="45" style="stroke:#0d6efd; stroke-dasharray:<?php echo ($attendancePerc * 2.83); ?>, 283;"/>
                            </svg>
                            <div class="gauge-text text-primary"><?php echo $attendancePerc; ?>%</div>
                        </div>
                        <small class="text-muted">حاضری</small>
                    </div>
                    <div class="col-6">
                        <div class="gauge-container mb-1">
                            <svg class="gauge-svg" viewBox="0 0 100 100">
                                <circle class="gauge-bg" cx="50" cy="50" r="45"/>
                                <circle class="gauge-fill" cx="50" cy="50" r="45" style="stroke-dasharray:<?php echo ($academicScore * 2.83); ?>, 283;"/>
                            </svg>
                            <div class="gauge-text text-success"><?php echo $academicScore; ?>%</div>
                        </div>
                        <small class="text-muted">تعلیمی</small>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <?php
                    $overall = round(($attendancePerc + $academicScore) / 2);
                    if($overall > 85) echo "<span class='text-success fw-bold small'>شاندار! ✨</span>";
                    elseif($overall > 65) echo "<span class='text-primary fw-bold small'>بہتر 👍</span>";
                    else echo "<span class='text-danger fw-bold small'>توجہ طلب! ⚠</span>";
                    ?>
                </div>
                <div class="text-center mt-1 border-top pt-1" style="font-size: 9px; color: #555;">
                    مجموعی حاضر: <?php echo ($p + $late); ?> (تاخیر: <?php echo $late; ?>) | غیر: <?php echo $a; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-8">
            <!-- AI Advice -->
            <div class="ai-card shadow-sm small h-100" id="ai-insight-box" style="display:none;">
                <h6 class="m-0 fw-bold"><i class="fas fa-university me-1 text-warning"></i> مشورہ</h6>
                <p class="m-0 mt-1" id="ai-insight-text">تجزیہ کیا جا رہا ہے...</p>
            </div>
        </div>
    </div>

    <!-- Weekly Attendance Register Grid -->
    <div class="mt-3 p-2 border rounded border-dark bg-white">
        <h6 class="fw-bold border-bottom border-dark pb-1 mb-2 small text-uppercase">
            <i class="fas fa-th me-1"></i> حاضری رجسٹر (Register View)
        </h6>
        <div class="table-responsive">
            <table class="table table-bordered table-sm text-center m-0" style="font-size: 10px; table-layout: fixed;">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">تاریخ</th>
                        <th>فجر</th><th>ظہر</th><th>عصر</th><th>عشاء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $begin = new DateTime($start_date);
                    $end = new DateTime($end_date);
                    $interval = new DateInterval('P1D');
                    $period = new DatePeriod($begin, $interval, $end->modify('+1 day'));

                    foreach ($period as $date):
                        $d = $date->format('Y-m-d');
                        $row = null;
                        foreach($all_atts as $a) if($a['date'] == $d) { $row = $a; break; }
                    ?>
                    <tr>
                        <td class="fw-bold bg-light"><?php echo date('d-M', strtotime($d)); ?></td>
                        <?php for($i=1;$i<=4;$i++): 
                            $s = $row["status_$i"] ?? '';
                            $l = $row["late_$i"] ?? 0;
                            $cls = '';
                            if($s=='P') $cls = ($l > 0 ? 'bg-warning text-dark' : 'bg-success text-white');
                            elseif($s=='A') $cls = 'bg-danger text-white';
                            elseif($s=='L') $cls = 'bg-info text-white';
                        ?>
                        <td class="<?php echo $cls; ?>" style="height: 25px;">
                            <?php if($s=='P') echo ($l > 0 ? 'ت' : 'ح'); elseif($s=='A') echo 'غ'; elseif($s=='L') echo 'ر'; ?>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-1 d-flex gap-2 justify-content-center" style="font-size: 8px;">
                <span><i class="fas fa-square text-success"></i> حاضر</span>
                <span><i class="fas fa-square text-warning"></i> تاخیر</span>
                <span><i class="fas fa-square text-danger"></i> غیر حاضر</span>
                <span><i class="fas fa-square text-info"></i> رخصت</span>
            </div>
        </div>
    </div>

    <!-- Attendance Details List -->
    <div class="mt-3 p-2 border rounded border-dark">
        <h6 class="fw-bold border-bottom border-dark pb-1 mb-1 small text-uppercase">
            <i class="fas fa-calendar-check me-1"></i> حاضری کی تفصیل (Attendance History)
        </h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered text-center align-middle m-0" style="font-size: 10px;">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle">تاریخ</th>
                        <th colspan="2">فجر</th>
                        <th colspan="2">ظہر</th>
                        <th colspan="2">عصر</th>
                        <th colspan="2">عشاء</th>
                    </tr>
                    <tr>
                        <th>حالت</th><th>تاخیر</th>
                        <th>حالت</th><th>تاخیر</th>
                        <th>حالت</th><th>تاخیر</th>
                        <th>حالت</th><th>تاخیر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if(count($all_atts) > 0):
                        foreach($all_atts as $row): 
                    ?>
                        <tr>
                            <td class="fw-bold bg-light"><?php echo translateDateUrduFull($row['date']); ?></td>
                            <?php for($i=1;$i<=4;$i++):
                                $s = $row["status_$i"] ?? '';
                                $l_min = $row["late_$i"] ?? 0;
                            ?>
                                <td>
                                    <?php if($s == 'P'): ?>
                                        <span class="text-success fw-bold">حاضر</span>
                                    <?php elseif($s == 'A'): ?>
                                        <span class="text-danger fw-bold">غیر حاضر</span>
                                    <?php elseif($s == 'L'): ?>
                                        <span class="text-info fw-bold">رخصت</span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ($l_min > 0 ? "<b class='text-warning'>$l_min منٹ</b>" : "-"); ?></td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="p-3 text-muted">کوئی ریکارڈ نہیں ملا</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Complaints Section -->
    <div class="mt-3 p-2 border rounded border-dark bg-white">
        <h6 class="fw-bold border-bottom border-dark pb-1 mb-1 small text-uppercase">
            <i class="fas fa-clipboard-list me-1"></i> اساتذہ کے نوٹ / شکایات (Teacher Remarks)
        </h6>
        <?php if(count($list) > 0): ?>
            <div style="text-align:justify; line-height:1.6; font-size:12px; padding: 5px;">
                <?php foreach($list as $idx => $c): ?>
                    <div class="mb-2">
                        <span class="fw-bold text-danger">[<?php echo translateDateUrduFull($c['date']); ?>]</span>
                        <?php if($c['type'] == 'parent'): ?>
                            <span class="badge bg-warning text-dark me-1">والدین</span>
                        <?php endif; ?>
                        <span class="text-dark"><?php echo nl2br(htmlspecialchars($c['description'])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-2"><span class="fw-bold text-success small">✔ الحمدللہ، کوئی شکایت درج نہیں کی گئی۔</span></div>
        <?php endif; ?>
    </div>

    <!-- Exam Results Section -->
    <?php if(count($exam_results) > 0): ?>
    <div class="mt-3 p-2 border rounded border-dark bg-white">
        <h6 class="fw-bold border-bottom border-dark pb-1 mb-1 small text-uppercase">
            <i class="fas fa-poll me-1"></i> امتحانی نتائج (Latest Exam Results)
        </h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered text-center align-middle m-0" style="font-size: 10px;">
                <thead class="table-light">
                    <tr>
                        <th>امتحان</th><th>حاصل نمبر</th><th>کل نمبر</th><th>گریڈ</th><th>کیفیت</th><th>ایکشن</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($exam_results as $ex): ?>
                    <tr>
                        <td class="fw-bold"><?php echo $ex['exam_name']; ?></td>
                        <td><?php echo $ex['marks_obtained']; ?></td>
                        <td><?php echo $ex['total_marks']; ?></td>
                        <td><span class="badge bg-primary"><?php echo $ex['grade']; ?></span></td>
                        <td>
                            <?php if($ex['status'] == 'Pass'): ?>
                                <span class="text-success fw-bold">پاس</span>
                            <?php elseif($ex['status'] == 'Grace Pass'): ?>
                                <span class="text-warning fw-bold">رعایتی</span>
                            <?php else: ?>
                                <span class="text-danger fw-bold">فیل</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="print_result_card.php?exam_id=<?php echo $ex['exam_id']; ?>&student_id=<?php echo $std_id; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="رزلٹ کارڈ دیکھیں">
                                <i class="fas fa-eye"></i> رزلٹ کارڈ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Attendance Line Chart
    const attLabels = <?php echo json_encode($att_labels); ?>;
    const attValues = <?php echo json_encode($att_values); ?>;
    const attCtx = document.getElementById('attendanceLineChart');
    if (attCtx && attLabels.length > 0) {
        new Chart(attCtx, {
            type: 'line',
            data: {
                labels: attLabels,
                datasets: [{
                    label: 'حاضری فیصد',
                    data: attValues,
                    borderColor: '#284D3D',
                    backgroundColor: 'rgba(40, 77, 61, 0.15)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#284D3D',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }

    // 2. Exams Bar Chart
    const examLabels = <?php echo json_encode($exam_labels); ?>;
    const examScores = <?php echo json_encode($exam_scores); ?>;
    const examCtx = document.getElementById('examsBarChart');
    if (examCtx && examLabels.length > 0) {
        new Chart(examCtx, {
            type: 'bar',
            data: {
                labels: examLabels,
                datasets: [{
                    label: 'حاصل کردہ نمبرات (%)',
                    data: examScores,
                    backgroundColor: ['#284D3D', '#4CAF50', '#2196F3', '#FF9800', '#9C27B0'],
                    borderRadius: 6,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });
    }

    const hifzCount = <?php echo $h_count; ?>;
    const arCount = <?php echo $ar_count; ?>;
    const complaintsCount = <?php echo count($list); ?>;
    if (hifzCount > 0 || arCount > 0) {
        document.getElementById('ai-insight-box').style.display = 'block';
        const formData = new FormData();
        formData.append('attendance', '<?php echo $attendancePerc; ?>');
        formData.append('academic', '<?php echo $academicScore; ?>');
        formData.append('hifz_count', hifzCount);
        formData.append('complaints', complaintsCount);
        formData.append('late_count', '<?php echo $late; ?>');

        fetch('ajax_parent_insight.php', { method: 'POST', body: formData })
        .then(response => response.text())
        .then(data => {
            if (data.trim() !== "") {
                document.getElementById('ai-insight-text').innerText = data;
            } else {
                document.getElementById('ai-insight-box').style.display = 'none';
            }
        })
        .catch(() => document.getElementById('ai-insight-box').style.display = 'none');
    }
});
</script>

<?php include 'footer.php'; ?>