<?php 
require_once 'db.php'; 
include 'header.php'; 

if (!isset($_SESSION['role'])) {
    echo "<script>window.location='login.php';</script>"; exit;
}

if ($_SESSION['role'] == 'admin') {
    $std_id = isset($_GET['std_id']) ? $_GET['std_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
} else {
    $std_id = $_SESSION['user_id'];
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
    }
}
$total_att = $p+$late+$a;
$attendancePerc = ($total_att > 0) ? round((($p + $late) / $total_att) * 100) : 0;

$present_dates = [];
foreach($atts as $at) {
    $is_present = false;
    for($i=1;$i<=4;$i++) {
        if(($at["status_$i"] ?? '') == 'P' || ($at["late_$i"] ?? 0) > 0) {
            $is_present = true;
            break;
        }
    }
    if($is_present) {
        $present_dates[$at['date']] = true;
    }
}

$missed_sabaq = 0; $missed_sabqi = 0; $missed_manzil = 0;
foreach($present_dates as $date => $val) {
    $has_record = false;
    foreach($hifz_rows as $row) {
        if ($row['date'] == $date) {
            $has_record = true;
            if ($is_qaida) {
                if (empty(trim($row['sabaq_1']??'')) || ($row['status']??'') == 'ناغہ' || ($row['status']??'') == 'Absent' || empty($row['status']??'')) $missed_sabaq++;
            } else {
                if (empty(trim($row['sabaq_parah']??'')) || ($row['sabaq_status']??'') == 'ناغہ' || ($row['sabaq_status']??'') == 'Absent' || empty($row['sabaq_status']??'')) $missed_sabaq++;
                if (empty(trim($row['sabqi_parah']??'')) || ($row['sabqi_status']??'') == 'ناغہ' || ($row['sabqi_status']??'') == 'Absent' || empty($row['sabqi_status']??'')) $missed_sabqi++;
                if (empty(trim($row['manzil_parah']??'')) || ($row['manzil_status']??'') == 'ناغہ' || ($row['manzil_status']??'') == 'Absent' || empty($row['manzil_status']??'')) $missed_manzil++;
            }
            break;
        }
    }
    if (!$has_record) {
        $missed_sabaq++;
        if (!$is_qaida) {
            $missed_sabqi++;
            $missed_manzil++;
        }
    }
}

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
    <button onclick="sendToParent('whatsapp')" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
        <i class="fab fa-whatsapp me-2"></i> WhatsApp رپورٹ بھیجیں
    </button>
    <button onclick="sendToParent('sms')" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">
        <i class="fas fa-sms me-2"></i> SMS رپورٹ بھیجیں
    </button>
</div>
<script>
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
            <input type="date" name="from" id="parentFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1">کب تک (To Date)</label>
            <input type="date" name="to" id="parentToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" required>
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

    <?php if ($h_count > 0): ?>
    <!-- Missed Lessons Summary -->
    <div class="row mb-3">
        <div class="col-12">
            <table class="table table-bordered text-center table-sm mb-0" style="font-size: 11px;">
                <thead class="table-dark">
                    <tr>
                        <th>کل حاضری کے دن</th>
                        <th class="text-success">سبق کا ناغہ</th>
                        <?php if (!$is_qaida): ?>
                        <th class="text-primary">سبقی کا ناغہ</th>
                        <th class="text-warning text-dark">منزل کا ناغہ</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold fs-6"><?php echo count($present_dates); ?> دن</td>
                        <td class="fw-bold fs-6 text-danger"><?php echo $missed_sabaq; ?> دن</td>
                        <?php if (!$is_qaida): ?>
                        <td class="fw-bold fs-6 text-danger"><?php echo $missed_sabqi; ?> دن</td>
                        <td class="fw-bold fs-6 text-danger"><?php echo $missed_manzil; ?> دن</td>
                        <?php endif; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
                if($h_count > 0) {
                    foreach($hifz_rows as $row) {
                        $s1 = htmlspecialchars($row['sabaq_1'] ?? '-'); $q1 = htmlspecialchars($row['qty_1'] ?? '-'); $g1 = translateStatusClean($row['grade_1'] ?? '-');
                        $s2 = htmlspecialchars($row['sabaq_2'] ?? '-'); $q2 = htmlspecialchars($row['qty_2'] ?? '-'); $g2 = translateStatusClean($row['grade_2'] ?? '-');
                        $s3 = htmlspecialchars($row['sabaq_3'] ?? '-'); $q3 = htmlspecialchars($row['qty_3'] ?? '-'); $g3 = translateStatusClean($row['grade_3'] ?? '-');
                        $s4 = htmlspecialchars($row['sabaq_4'] ?? '-'); $q4 = htmlspecialchars($row['qty_4'] ?? '-'); $g4 = translateStatusClean($row['grade_4'] ?? '-');
                        $beh = htmlspecialchars($row['behavior'] ?? '-');
                        $ht = empty($row['hearing_time']) ? '' : "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['hearing_time']))."</small>";
                        
                        echo "<tr>
                            <td class='fw-bold text-start ps-1'>".translateDateUrduFull($row['date']).$ht."</td>
                            <td>{$s1}</td><td>{$q1}</td><td>{$g1}</td>
                            <td>{$s2}</td><td>{$q2}</td><td>{$g2}</td>
                            <td>{$s3}</td><td>{$q3}</td><td>{$g3}</td>
                            <td>{$s4}</td><td>{$q4}</td><td>{$g4}</td>
                            <td class='small fw-bold'>{$beh}</td>
                        </tr>";
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
                if($h_count > 0) {
                    foreach($hifz_rows as $row) {
                        $m_time = !empty($row['morning_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['morning_time']))."</small>" : "";
                        $e_time = !empty($row['evening_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['evening_time']))."</small>" : "";
                        echo "<tr>
                            <td class='fw-bold text-start ps-1'>".translateDateUrduFull($row['date'])."</td>
                            <td class='fw-bold text-success'>{$row['morning_sabaq']}</td><td>{$row['morning_qty']}</td><td>".translateStatusClean($row['morning_status']).$m_time."</td>
                            <td class='fw-bold text-primary'>{$row['evening_sabaq']}</td><td>{$row['evening_qty']}</td><td>".translateStatusClean($row['evening_status']).$e_time."</td>
                        </tr>";
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
                if($h_count > 0) {
                    foreach($hifz_rows as $row) {
                        $l_sabqi = isset($row['sabqi_listener']) ? (mb_strlen($row['sabqi_listener']) > 12 ? mb_substr($row['sabqi_listener'], 0, 12).'..' : $row['sabqi_listener']) : '-';
                        $l_manzil = isset($row['manzil_listener']) ? (mb_strlen($row['manzil_listener']) > 12 ? mb_substr($row['manzil_listener'], 0, 12).'..' : $row['manzil_listener']) : '-';
                        $manzil_qty_map = ['Quarter'=>'پاؤ','Half'=>'آدھا','Three Quarter'=>'تین پاؤ','Full'=>'مکمل','1.5 Parah'=>'ڈیڑھ','2 Parah'=>'دو','2.5 Parah'=>'ڈھائی','3 Parah'=>'تین'];
                        $manzil_qty = $manzil_qty_map[$row['manzil_amount'] ?? ''] ?? ($row['manzil_amount'] ?? '-');
                        echo "<tr>
                            <td class='fw-bold text-start ps-1'>".translateDateUrduFull($row['date'])."</td>
                            <td class='fw-bold'>{$row['sabaq_parah']}</td><td>{$row['sabaq_lines']}</td><td>".translateStatusClean($row['sabaq_status']).(!empty($row['sabaq_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['sabaq_time']))."</small>" : "")."</td>
                            <td>{$row['sabqi_parah']}</td><td>{$row['sabqi_amount']}</td><td>".translateStatusClean($row['sabqi_status']).(!empty($row['sabqi_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['sabqi_time']))."</small>" : "")."</td><td class='small text-muted'>$l_sabqi</td>
                            <td class='text-start ps-1 small'>{$row['manzil_parah']}</td><td>$manzil_qty</td><td>".translateStatusClean($row['manzil_status']).(!empty($row['manzil_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($row['manzil_time']))."</small>" : "")."</td><td class='small text-muted'>$l_manzil</td>
                        </tr>";
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
                            if($s=='P') $cls = ($l > 0 ? 'bg-warning' : 'bg-success');
                            elseif($s=='A') $cls = 'bg-danger';
                            elseif($s=='L') $cls = 'bg-info';
                        ?>
                        <td class="<?php echo $cls; ?> text-white" style="height: 25px;">
                            <?php if($s=='P') echo ($l > 0 ? 'L' : 'P'); elseif($s=='A') echo 'A'; elseif($s=='L') echo 'R'; ?>
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
                        <th>تاریخ</th><th>شفٹ</th><th>حالت</th><th>تاخیر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if(count($all_atts) > 0):
                        foreach($all_atts as $row): 
                            for($i=1;$i<=4;$i++):
                                $s = $row["status_$i"] ?? '';
                                if(!$s) continue;
                                $l_min = $row["late_$i"] ?? 0;
                    ?>
                        <tr>
                            <td><?php echo translateDateUrduFull($row['date']); ?></td>
                            <td><?php echo ($i==1?'فجر':($i==2?'ظہر':($i==3?'عصر':'عشاء'))); ?></td>
                            <td>
                                <?php if($s == 'P'): ?>
                                    <span class="text-success fw-bold">حاضر</span>
                                <?php elseif($s == 'A'): ?>
                                    <span class="text-danger fw-bold">غیر حاضر</span>
                                <?php else: ?>
                                    <span class="text-info fw-bold">رخصت</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($l_min > 0 ? "<b class='text-warning'>$l_min منٹ</b>" : "-"); ?></td>
                        </tr>
                    <?php endfor; endforeach; else: ?>
                        <tr><td colspan="4" class="p-3 text-muted">کوئی ریکارڈ نہیں ملا</td></tr>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
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