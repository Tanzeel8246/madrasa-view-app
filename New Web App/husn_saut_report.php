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

$stmt = $pdo->prepare("SELECT mashq_periods FROM classes WHERE name = ?");
$stmt->execute([$selected_class]);
$c = $stmt->fetch();
$mashq_periods = (int)($c['mashq_periods'] ?? 2);
if ($mashq_periods < 1) $mashq_periods = 1;
if ($mashq_periods > 4) $mashq_periods = 4;

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
    $colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}

$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;

$students_stmt = $pdo->prepare("SELECT s.id, s.name, s.father_name, s.admission_number, $student_roll_select FROM students s WHERE s.class = ? AND (LOWER(s.status) IN ('active','present') OR s.status='حاضر' OR s.status IS NULL) AND (s.is_deleted = 0 OR s.is_deleted IS NULL) ORDER BY s.name ASC");
$students_stmt->execute([$selected_class]);
$filter_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($selected_student_id > 0) {
    $all_students = array_values(array_filter($filter_students, function($st) use ($selected_student_id) {
        return (int)$st['id'] === $selected_student_id;
    }));
} else {
    $all_students = $filter_students;
}

function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . '-' . date('Y', $timestamp) . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}

function translateStatusClean($status) {
    if (empty($status) || $status === '-' || $status === 'ناغہ') return '-';
    $map = ['Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور', 'ممتاز'=>'ممتاز', 'بہتر'=>'بہتر', 'معمولی'=>'معمولی', 'کمزور'=>'کمزور'];
    $color = ['Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger', 'ممتاز'=>'success', 'بہتر'=>'primary', 'معمولی'=>'warning', 'کمزور'=>'danger'];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}
?>

<style>
    @media (min-width: 769px) {
        #sidebar-wrapper { display: none !important; }
        #page-content-wrapper { margin: 0 !important; width: 100% !important; min-width: 100% !important; transition: all 0.3s ease; }
    }
    .report-table th, .report-table td { word-break: break-word !important; white-space: normal !important; padding: 5px 8px !important; font-size: clamp(10px, 1vw, 13px); vertical-align: middle; }
    @media print {
        @page { size: A4 landscape; margin: 5mm; }
        body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .d-print-none { display: none !important; }
        #printableArea { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .table { font-size: 9pt !important; table-layout: fixed !important; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="fw-bold text-success mb-0"><i class="fas fa-filter me-2"></i> حسن صوت رپورٹ فلٹرز & پرنٹ</h4>
        <a href="academic_view_dashboard.php?class=<?= urlencode($selected_class) ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1"><i class="fas fa-user-graduate me-1 text-success"></i> طالب علم (سرچ کریں):</label>
            <select name="student_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                <?php foreach($filter_students as $st): ?>
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
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-book-open me-2"></i> حسن صوت رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <?php
    $total_cols = 3 + 3 + ($mashq_periods * 2) + 2; 
    ?>
    <div class="table-responsive">
        <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="font-size: 13px;">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="width: 3%;">#</th>
                    <th rowspan="2" style="width: 5%;">رول نمبر</th>
                    <th rowspan="2" style="width: 15%;">طالب علم</th>
                    <th colspan="3" class="bg-primary text-white">منزل</th>
                    <?php for($i=1; $i<=$mashq_periods; $i++): ?>
                        <th colspan="2" class="bg-info text-dark">مشق <?= $i ?></th>
                    <?php endfor; ?>
                    <th colspan="2" class="bg-success text-white">تلاوت</th>
                </tr>
                <tr>
                    <th class="bg-light">پارہ</th><th class="bg-light">سننے والا</th><th class="bg-light">کیفیت</th>
                    <?php for($i=1; $i<=$mashq_periods; $i++): ?>
                        <th class="bg-light">آیات</th><th class="bg-light">وقت</th>
                    <?php endfor; ?>
                    <th class="bg-light">مقدار</th><th class="bg-light">پارہ نمبرز</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $q = "SELECT r.* FROM husn_saut_record r 
                      JOIN students s ON r.student_id = s.id 
                      WHERE r.date >= ? AND r.date <= ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.class = ?";
                $stmt = $pdo->prepare($q);
                $stmt->execute([$start_date, $end_date, $selected_class]);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $record_map = [];
                foreach ($records as $rec) {
                    $record_map[$rec['date']][$rec['student_id']] = $rec;
                }

                $report_dates = [];
                $start_time = strtotime($start_date);
                $end_time = strtotime($end_date);
                for ($current_time = $end_time; $current_time >= $start_time; $current_time -= 86400) {
                    $report_dates[] = date('Y-m-d', $current_time);
                }

                if (count($all_students) > 0) {
                    foreach ($report_dates as $date) {
                        $formatted_date = translateDateUrduFull($date);
                        echo "<tr class='table-secondary'><td colspan='{$total_cols}' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $sr_no = 1;
                        foreach ($all_students as $student) {
                            $student_id = $student['id'];
                            $rec = $record_map[$date][$student_id] ?? null;
                            
                            $is_friday = (date('N', strtotime($date)) == 5);
                            
                            if ($rec) {
                                $manzil_sabaq = htmlspecialchars($rec['manzil_sabaq'] ?? '-');
                                $manzil_listener = htmlspecialchars($rec['manzil_listener'] ?? '-');
                                $manzil_grade = translateStatusClean($rec['manzil_grade'] ?? '-');
                                
                                $mashqs_html = '';
                                for($i=1; $i<=$mashq_periods; $i++) {
                                    $m_sabaq = htmlspecialchars($rec['mashq_'.$i.'_sabaq'] ?? '-');
                                    $m_time = htmlspecialchars(substr($rec['mashq_'.$i.'_time'] ?? '', 0, 5));
                                    if(empty(trim($m_time))) $m_time = '-';
                                    $mashqs_html .= "<td>{$m_sabaq}</td><td dir='ltr'>{$m_time}</td>";
                                }
                                
                                $t_qty = htmlspecialchars($rec['tilawat_qty'] ?? '-');
                                $t_parahs = htmlspecialchars($rec['tilawat_parahs'] ?? '-');
                                
                                echo "<tr>
                                    <td class='fw-bold text-center'>{$sr_no}</td>
                                    <td>" . htmlspecialchars(($student['roll_number'] ?: $student['admission_number']) ?? '') . "</td>
                                    <td class='text-start ps-2 fw-bold'>" . htmlspecialchars($student['name'] ?? '') . "</td>
                                    <td>{$manzil_sabaq}</td><td>{$manzil_listener}</td><td>{$manzil_grade}</td>
                                    {$mashqs_html}
                                    <td>{$t_qty}</td><td>{$t_parahs}</td>
                                </tr>";
                                $sr_no++;
                            } elseif ($is_friday) {
                                // Don't show anything for missing Friday records unless specifically filtered
                            }
                        }
                    }
                } else {
                    echo "<tr><td colspan='{$total_cols}' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>

